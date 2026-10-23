<?php declare(strict_types=1);

namespace Moggi\Semantics\Foreign;

use Moggi\Backend;
use Moggi\IR\IoWrap;
use Moggi\Modules;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Backend\backendById;
use function Moggi\Backend\implementedBackendIds;
use function Moggi\Backend\isBackendImplemented;
use function Moggi\Errors\formatKnownAlternatives;
use function Moggi\Foreign\parseForeignPath;
use function Moggi\Semantics\IntrinsicRegistry\isMagicHashName;
use function Moggi\Semantics\IoBoundary\isIoType;
use function Moggi\Semantics\IoBoundary\isIoTypeConName;
use function Moggi\Semantics\IoBoundary\typeMentionsIo;
use function Moggi\Semantics\Kernel\boundaryEitherTypeName;
use function Moggi\Semantics\Kernel\boundaryHandleTypeName;
use function Moggi\Semantics\Kernel\boundaryMaybeTypeName;
use function Moggi\Semantics\Kernel\boundaryReifiedTypeName;
use function Moggi\Semantics\Kernel\boundaryStringTypeName;
use function Moggi\Semantics\Kernel\isBoundaryContainerType;
use function Moggi\Semantics\Kernel\isBoundaryHandleType;
use function Moggi\Semantics\Types\typeFail;

function checkForeignImportItem(TypeCheckState $state, Ast\ForeignImportDecl $item): Ast\FunctionDecl
{
    $pathInfo = parseForeignPath($item->path);
    if ($item->kind === 'const' && $pathInfo['dispatch'] !== 'static') {
        throw typeFail($state, 'foreign const import requires static class path (`Class:NAME`)', $item);
    }

    validateForeignImportType($state, $item);
    checkForeignSignatureLegality($state, $item);

    if ($pathInfo['dispatch'] === 'instance') {
        validateInstanceForeignType($state, $item);
    }

    if (isBackendImplemented($item->backend)) {
        $receiverIsHandle = false;
        if ($pathInfo['dispatch'] === 'instance' && $item->type instanceof Ast\TypeArrow) {
            $receiverIsHandle = foreignHandleType($state, $item->type->from);
        }
        $backendError = backendById($item->backend)->validateForeignImport(
            $item->kind,
            $pathInfo,
            $receiverIsHandle,
        );
        if ($backendError !== null) {
            throw typeFail($state, $backendError, $item);
        }
    }

    $compileBackend = $state->compileBackend;
    if ($item->backend !== $compileBackend) {
        throw typeFail(
            $state,
            formatKnownAlternatives(
                "foreign import backend `{$item->backend}` does not match compile backend `{$compileBackend}`",
                isBackendImplemented($compileBackend) ? [$compileBackend] : Backend\implementedBackendIds(),
            ),
            $item,
        );
    }

    $pathInfo['declaringRoot'] = Modules\declaringLibraryRoot($state->filename);

    try {
        $nativeSig = backendById($item->backend)->foreignNativeSig($item->type, $item->kind, $pathInfo, $item->name);
    } catch (\Throwable $e) {
        throw typeFail($state, $e->getMessage(), $item);
    }

    try {
        $hostError = foreignHostMismatch($item, $pathInfo, $nativeSig);
    } catch (Backend\VerificationUnavailable $e) {
        throw typeFail($state, $e->getMessage(), $item);
    }
    if ($hostError !== null) {
        throw typeFail($state, $hostError, $item);
    }

    $params = foreignParamNames($item->type);
    $body = new Ast\ForeignCall(
        $item->backend,
        $item->kind,
        $item->name,
        $item->path,
        $pathInfo['dispatch'],
        args: \array_map(static fn (string $param): Ast\Variable => new Ast\Variable($param), $params),
        classPath: $pathInfo['classPath'],
        member: $pathInfo['hostMember'],
        ioWrap: foreignIoWrap($item->type),
        phpValueBox: foreignPhpValueBox($item->type),
        handleBox: foreignHandleBox($item->type),
        handleUnboxArgs: foreignHandleUnboxArgIndices($item->type),
        nativeSig: $nativeSig,
    );

    return new Ast\FunctionDecl(
        $item->name,
        $item->type,
        \array_map(
            static fn (string $param): Ast\PatVar => new Ast\PatVar($param),
            $params,
        ),
        $body,
        false,
        true
    );
}

/**
 * Ask the backend whether the host agrees with a declaration.
 *
 * A `foreign import` is an axiom — the compiler emits the call it is told to emit, so a host name
 * the host does not have, or a signature it does not match, waits for run time to fail. This is the
 * check the compiler *can* make, and the backend owns it because only the backend knows its host:
 * PHP reflects its own process, the JVM runs `javap`, and a backend with nothing to ask returns
 * null. There is deliberately no switch that turns it off: a check is either the check, or a
 * promise the build does not mean.
 *
 * A check that *breaks* still must not break the compile — it warns on stderr and the declaration
 * is treated as unverified — but a missing tool ({@see Backend\VerificationUnavailable}) is not
 * a broken check and is re-thrown for the caller to refuse the declaration with.
 *
 * @param array{dispatch: string, classPath: ?string, hostMember: ?string, path?: string} $pathInfo
 * @param ?string $nativeSig the signature the emitter will use
 */
function foreignHostMismatch(Ast\ForeignImportDecl $item, array $pathInfo, ?string $nativeSig): ?string
{
    try {
        return backendById($item->backend)->verifyForeignImport(
            $item->kind,
            $pathInfo,
            $item->type,
            $nativeSig,
            $item->name,
        );
    } catch (Backend\VerificationUnavailable $e) {
        throw $e;
    } catch (\Throwable $e) {
        \fwrite(STDERR, "warning: foreign verification of `{$item->name}` failed: {$e->getMessage()}\n");

        return null;
    }
}

function validateForeignImportType(TypeCheckState $state, Ast\ForeignImportDecl $item): void
{
    $cursor = $item->type;
    while ($cursor instanceof Ast\TypeArrow) {
        if (typeMentionsIo($cursor->from)) {
            throw typeFail($state, 'foreign import cannot use IO in argument types', $item);
        }
        $cursor = $cursor->to;
    }

    if ($item->kind === 'const' && foreignReturnsIo($item->type)) {
        throw typeFail($state, 'foreign const import cannot return IO', $item);
    }
}

function validateInstanceForeignType(TypeCheckState $state, Ast\ForeignImportDecl $item): void
{
    $type = $item->type;
    if (! $type instanceof Ast\TypeArrow) {
        throw typeFail($state, 'instance foreign import requires a receiver as first argument', $item);
    }

    $receiver = $type->from;
    $receiverName = foreignTypeConName($receiver);
    if ($receiverName === boundaryReifiedTypeName()) {
        throw typeFail(
            $state,
            'PHPValue cannot be an instance-method receiver; use a specific foreign type or Handle',
            $item,
        );
    }
    if ($receiverName !== null && isBoundaryHandleType($receiverName)) {
        return;
    }
    if ($receiverName !== null && isMagicHashName($receiverName)) {
        throw typeFail(
            $state,
            "instance foreign import receiver must use a public type alias, not Magichash `{$receiverName}`",
            $item,
        );
    }
    if ($receiver instanceof Ast\TypeCon && isset($state->data[$receiver->name]['foreign'])) {
        $foreignInfo = $state->data[$receiver->name]['foreign'];
        if (($foreignInfo['backend'] ?? '') !== $item->backend) {
            throw typeFail(
                $state,
                "foreign type `{$receiver->name}` is declared for backend `{$foreignInfo['backend']}` but used with `{$item->backend}`",
                $item,
            );
        }

        return;
    }
    if (foreignIsMagicHashAlias($state, $receiver)) {
        return;
    }

    throw typeFail(
        $state,
        'instance foreign import receiver must be a same-backend foreign type or Handle',
        $item,
    );
}

/** True when `$type` is a public nullary synonym of a Magichash primitive (`Foo = Foo#`). */
function foreignIsMagicHashAlias(TypeCheckState $state, Ast\TypeNode $type): bool
{
    $name = foreignTypeConName($type);
    if ($name === null || ! isset($state->typeSynonyms[$name])) {
        return false;
    }

    $syn = $state->typeSynonyms[$name];
    if ($syn['params'] !== []) {
        return false;
    }

    $rhsName = foreignTypeConName($syn['rhs']);

    return $rhsName !== null
        && isMagicHashName($rhsName);
}

function foreignHandleType(TypeCheckState $state, Ast\TypeNode $type): bool
{
    if ($type instanceof Ast\TypeVar) {
        return true;
    }

    if ($type instanceof Ast\TypeUnit) {
        return false;
    }

    if (! $type instanceof Ast\TypeCon) {
        return false;
    }

    return ! foreignIsHostValueType($state, $type);
}

/**
 * Whether the host passes a value of this type directly, rather than through a boxing handle.
 *
 * The set is the library's, read from its declarations rather than spelled out here:
 *
 *   * a primitive is a library alias of a MagicHash type (`type Int = Int#` in
 *     `Moggi.Internal.Base`), which {@see foreignIsMagicHashAlias} recognises;
 *   * an enumeration — a library data type whose constructors all take fields none — is the host's
 *     own datatype, which is what makes the truth values and the ordering comparable across the
 *     boundary.
 *
 * Nothing else is passed directly: a type the library declares with payload, or one it does not
 * declare at all, is handed over as a boxing handle.
 */
function foreignIsHostValueType(TypeCheckState $state, Ast\TypeNode $type): bool
{
    if (foreignIsMagicHashAlias($state, $type)) {
        return true;
    }

    $name = foreignTypeConName($type);
    if ($name === null) {
        return false;
    }

    if (($state->data[$name]['primitive'] ?? false) === true) {
        return true;
    }

    $constructors = $state->data[$name]['constructors'] ?? null;
    if (! \is_array($constructors) || $constructors === []) {
        return false;
    }

    foreach ($constructors as $constructor) {
        if (($constructor['fields'] ?? []) !== []) {
            return false;
        }
    }

    return true;
}

function foreignHandleUnboxArgIndices(Ast\TypeNode $type): array
{
    $indices = [];
    $cursor = $type;
    $index = 0;

    while ($cursor instanceof Ast\TypeArrow) {
        if (foreignTypeConName($cursor->from) === boundaryHandleTypeName()) {
            $indices[] = $index;
        }

        ++$index;
        $cursor = $cursor->to;
    }

    return $indices;
}

function foreignParamNames(Ast\TypeNode $type): array
{
    $params = [];
    $cursor = $type;
    $index = 1;

    while ($cursor instanceof Ast\TypeArrow) {
        $params[] = 'a' . $index;
        ++$index;
        $cursor = $cursor->to;
    }

    return $params;
}

function foreignIoInnerType(Ast\TypeNode $type): Ast\TypeNode
{
    $result = $type;
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    if ($result instanceof Ast\TypeApp && $result->con instanceof Ast\TypeCon && isIoTypeConName($result->con->name)) {
        return $result->args[0] ?? new Ast\TypeUnit();
    }

    return $result;
}

function foreignTypeConName(Ast\TypeNode $type): ?string
{
    return match ($type::class) {
        Ast\TypeCon::class => $type->name,
        Ast\TypeApp::class => $type->con instanceof Ast\TypeCon ? $type->con->name : null,
        default => null,
    };
}

function foreignReturnsEitherString(Ast\TypeNode $type): bool
{
    $inner = foreignIoInnerType($type);
    if (foreignTypeConName($inner) !== boundaryEitherTypeName()) {
        return false;
    }

    $left = $inner instanceof Ast\TypeApp ? ($inner->args[0] ?? null) : null;

    return $left instanceof Ast\AstNode && foreignTypeConName($left) === boundaryStringTypeName();
}

function foreignReturnsPhpValue(Ast\TypeNode $type): bool
{
    $inner = foreignIoInnerType($type);
    if (foreignTypeConName($inner) === boundaryEitherTypeName()) {
        $right = $inner instanceof Ast\TypeApp ? ($inner->args[1] ?? null) : null;

        return $right instanceof Ast\AstNode && foreignTypeConName($right) === boundaryReifiedTypeName();
    }

    return foreignTypeConName($inner) === boundaryReifiedTypeName();
}

function foreignReturnsIo(Ast\TypeNode $type): bool
{
    $result = $type;
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    return $result instanceof Ast\TypeNode && isIoType($result);
}

/**
 * Which IO wrapping the host call needs (see `IR\IoWrap`).
 *
 * The answer is the signature's, never the host function's: `IO (Maybe String)`
 * means the same thing wherever it is imported from, so nothing here may look at
 * the path the declaration named.
 */
function foreignIoWrap(Ast\TypeNode $type): IoWrap
{
    if (! foreignReturnsIo($type)) {
        return IoWrap::None;
    }

    if (foreignReturnsEitherString($type)) {
        return IoWrap::Either;
    }

    if (foreignReturnsMaybeString($type)) {
        return IoWrap::MaybeString;
    }

    return IoWrap::None;
}

function foreignReturnsMaybeString(Ast\TypeNode $type): bool
{
    $inner = foreignIoInnerType($type);
    if (foreignTypeConName($inner) !== boundaryMaybeTypeName()) {
        return false;
    }

    $arg = $inner instanceof Ast\TypeApp ? ($inner->args[0] ?? null) : null;

    return $arg instanceof Ast\AstNode && foreignTypeConName($arg) === boundaryStringTypeName();
}

function foreignPhpValueBox(Ast\TypeNode $type): bool
{
    if (foreignReturnsIo($type)) {
        return foreignReturnsPhpValue($type);
    }

    return foreignTypeConName($type) === boundaryReifiedTypeName()
        || foreignReturnsPhpValue($type);
}

function foreignReturnsHandle(Ast\TypeNode $type): bool
{
    $inner = foreignIoInnerType($type);
    if (foreignTypeConName($inner) === boundaryEitherTypeName()) {
        $right = $inner instanceof Ast\TypeApp ? ($inner->args[1] ?? null) : null;

        return $right instanceof Ast\AstNode && foreignTypeConName($right) === boundaryHandleTypeName();
    }

    return foreignTypeConName($inner) === boundaryHandleTypeName();
}

function foreignHandleBox(Ast\TypeNode $type): bool
{
    if (foreignReturnsIo($type)) {
        return foreignReturnsHandle($type);
    }

    $result = $type;
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    return foreignTypeConName($result) === boundaryHandleTypeName();
}

function validateForeignTypeDecl(TypeCheckState $state, Ast\ForeignTypeDecl $decl): void
{
    if (! isBackendImplemented($decl->backend)) {
        throw typeFail(
            $state,
            formatKnownAlternatives(
                "unknown foreign type backend `{$decl->backend}`",
                implementedBackendIds(),
            ),
            $decl,
        );
    }

    if (\trim($decl->hostType) === '') {
        throw typeFail($state, 'foreign type host string must be non-empty', $decl);
    }
}

function checkForeignSignatureLegality(TypeCheckState $state, Ast\ForeignImportDecl $item): void
{
    validateForeignSignatureTypes($state, $item->type, $item->backend, $item);
}

function validateForeignSignatureTypes(
    TypeCheckState $state,
    Ast\TypeNode $type,
    string $backend,
    Ast\AstNode $context,
    bool $argument = false,
    bool $isTrailing = false,
): void {
    if ($type instanceof Ast\TypeVar) {
        throw typeFail($state, 'foreign import cannot use type variables', $context);
    }

    if ($type instanceof Ast\TypeCon) {
        validateForeignTypeCon($state, $type, $backend, $context, $argument, $isTrailing);

        return;
    }

    if ($type instanceof Ast\TypeApp) {
        validateForeignSignatureTypes($state, $type->con, $backend, $context, $argument, $isTrailing);
        foreach ($type->args ?? [] as $arg) {
            validateForeignSignatureTypes($state, $arg, $backend, $context, $argument, false);
        }

        return;
    }

    if ($type instanceof Ast\TypeArrow) {
        validateForeignSignatureTypes($state, $type->from, $backend, $context, true, !($type->to instanceof Ast\TypeArrow));
        validateForeignSignatureTypes($state, $type->to, $backend, $context, false, false);

        return;
    }
}

function validateForeignTypeCon(
    TypeCheckState $state,
    Ast\TypeCon $con,
    string $backend,
    Ast\AstNode $context,
    bool $argument = false,
    bool $isTrailing = false,
): void {
    $name = $con->name;

    if (isset($state->data[$name]['foreign'])) {
        $foreignInfo = $state->data[$name]['foreign'];
        if ($foreignInfo['backend'] !== $backend) {
            throw typeFail(
                $state,
                "foreign type `{$name}` is declared for backend `{$foreignInfo['backend']}` but used with `{$backend}`",
                $context,
            );
        }

        return;
    }

    if ($name === boundaryReifiedTypeName()) {
        if ($backend !== 'php') {
            throw typeFail(
                $state,
                "`PHPValue` reification is only available on the php backend, but it is used with `{$backend}`",
                $context,
            );
        }

        return;
    }

    if (isBoundaryHandleType($name)) {
        return;
    }

    if (isBoundaryContainerType($name)) {
        if ($argument) {
            throw typeFail(
                $state,
                "`{$name}` cannot be an argument of a foreign call — only a result is converted; pass the payload instead",
                $context,
            );
        }

        return;
    }

    if (foreignIsHostValueType($state, $con)) {
        return;
    }

    if ($name === 'List') {
        if ($argument && $backend !== 'php' && !$isTrailing) {
            throw typeFail(
                $state,
                "`[a]` cannot be an argument of a {$backend} foreign call — only php's array is the same value as a Moggi list; call it element by element or declare the host's own type",
                $context,
            );
        }

        return;
    }

    if (isIoTypeConName($name)) {
        return;
    }

    throw typeFail(
        $state,
        "type `{$name}` is not legal in a foreign signature; use a specific foreign type, a primitive, or PHPValue for reification",
        $context,
    );
}
