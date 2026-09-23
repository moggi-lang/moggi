<?php declare(strict_types=1);

namespace Moggi\Semantics\Foreign;

use Moggi\Backend;
use Moggi\IR\IoWrap;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Backend\backendById;
use function Moggi\Backend\compileBackend;
use function Moggi\Backend\implementedBackendIds;
use function Moggi\Backend\isBackendImplemented;
use function Moggi\Errors\formatKnownAlternatives;
use function Moggi\Foreign\parseForeignPath;
use function Moggi\Semantics\IntrinsicRegistry\isMagicHashName;
use function Moggi\Semantics\IoBoundary\isIoType;
use function Moggi\Semantics\IoBoundary\isIoTypeConName;
use function Moggi\Semantics\IoBoundary\typeMentionsIo;
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

    // Declared-backend rules run before compile-backend match (preserve
    // diagnostics like PHP handle-receiver on a JVM compile). Skip unknown ids.
    if (isBackendImplemented($item->backend)) {
        $receiverIsHandle = false;
        if ($pathInfo['dispatch'] === 'instance' && $item->type instanceof Ast\TypeArrow) {
            $receiverIsHandle = foreignHandleType($item->type->from);
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

    $compileBackend = compileBackend();
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

    $params = foreignParamNames($item->type);
    $body = new Ast\ForeignCall(
        $item->backend,
        $item->kind,
        $item->name,
        $item->path,
        $pathInfo['dispatch'],
        args: \array_map(static fn (string $param): Ast\Variable => new Ast\Variable($param), $params),
        classPath: $pathInfo['classPath'],
        member: $pathInfo['member'],
        ioWrap: foreignIoWrap($item->type, $item->path),
        phpValueBox: foreignPhpValueBox($item->type),
        handleBox: foreignHandleBox($item->type),
        handleUnboxArgs: foreignHandleUnboxArgIndices($item->type),
        nativeSig: backendById($item->backend)->foreignNativeSig($item->type, $item->kind, $pathInfo, $item->name),
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
    if (!$type instanceof Ast\TypeArrow) {
        throw typeFail($state, 'instance foreign import requires a receiver as first argument', $item);
    }

    $receiver = $type->from;
    $receiverName = foreignTypeConName($receiver);
    if ($receiverName === 'PHPValue') {
        throw typeFail(
            $state,
            'PHPValue cannot be an instance-method receiver; use a specific foreign type or Handle',
            $item,
        );
    }
    if ($receiverName === 'Handle' || $receiverName === 'Resource') {
        return;
    }
    // Magichash spellings are not FFI surface; use public aliases (Integer, …).
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
    // Public nullary alias of a Magichash prim (Integer = Integer#, String = String#, …).
    // Any `*#` RHS counts — no host-ref allowlist to grow when new prim aliases appear.
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
    if ($name === null || !isset($state->typeSynonyms[$name])) {
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

function foreignHandleType(Ast\TypeNode $type): bool
{
    if ($type instanceof Ast\TypeVar) {
        return true;
    }

    if ($type instanceof Ast\TypeUnit) {
        return false;
    }

    if (!$type instanceof Ast\TypeCon) {
        return false;
    }

    $primitive = ['String', 'ByteString', 'Int', 'Int8', 'Int16', 'Int32', 'Int64', 'Integer', 'Double', 'Bool', 'Char', 'Word8', 'Word16', 'Word32', 'Word64', 'Ordering'];

    return !\in_array($type->name, $primitive, true);
}

function foreignHandleUnboxArgIndices(Ast\TypeNode $type): array
{
    $indices = [];
    $cursor = $type;
    $index = 0;

    while ($cursor instanceof Ast\TypeArrow) {
        if (foreignTypeConName($cursor->from) === 'Handle') {
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
    if (foreignTypeConName($inner) !== 'Either') {
        return false;
    }

    $left = $inner instanceof Ast\TypeApp ? ($inner->args[0] ?? null) : null;

    return $left instanceof Ast\AstNode && foreignTypeConName($left) === 'String';
}

function foreignReturnsPhpValue(Ast\TypeNode $type): bool
{
    $inner = foreignIoInnerType($type);
    if (foreignTypeConName($inner) === 'Either') {
        $right = $inner instanceof Ast\TypeApp ? ($inner->args[1] ?? null) : null;

        return $right instanceof Ast\AstNode && foreignTypeConName($right) === 'PHPValue';
    }

    return foreignTypeConName($inner) === 'PHPValue';
}

function foreignReturnsIo(Ast\TypeNode $type): bool
{
    $result = $type;
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    return $result instanceof Ast\TypeNode && isIoType($result);
}

/** Which IO wrapping the host call needs (see `IR\IoWrap`). */
function foreignIoWrap(Ast\TypeNode $type, string $path = ''): IoWrap
{
    if (!foreignReturnsIo($type)) {
        return IoWrap::None;
    }

    if (foreignReturnsEitherString($type)) {
        return IoWrap::Either;
    }

    if (foreignReturnsMaybeString($type)) {
        return foreignMaybeStringUsesFgetsLine($path)
            ? IoWrap::FgetsLine
            : IoWrap::MaybeString;
    }

    return IoWrap::None;
}

function foreignMaybeStringUsesFgetsLine(string $path): bool
{
    $info = parseForeignPath($path);
    $member = strtolower((string) ($info['member'] ?? $path));

    return str_contains($member, 'fgets');
}

function foreignReturnsMaybeString(Ast\TypeNode $type): bool
{
    $inner = foreignIoInnerType($type);
    if (foreignTypeConName($inner) !== 'Maybe') {
        return false;
    }

    $arg = $inner instanceof Ast\TypeApp ? ($inner->args[0] ?? null) : null;

    return $arg instanceof Ast\AstNode && foreignTypeConName($arg) === 'String';
}

function foreignPhpValueBox(Ast\TypeNode $type): bool
{
    if (foreignReturnsIo($type)) {
        return foreignReturnsPhpValue($type);
    }

    return foreignTypeConName($type) === 'PHPValue'
        || foreignReturnsPhpValue($type);
}

function foreignReturnsHandle(Ast\TypeNode $type): bool
{
    $inner = foreignIoInnerType($type);
    if (foreignTypeConName($inner) === 'Either') {
        $right = $inner instanceof Ast\TypeApp ? ($inner->args[1] ?? null) : null;

        return $right instanceof Ast\AstNode && foreignTypeConName($right) === 'Handle';
    }

    return foreignTypeConName($inner) === 'Handle';
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

    return foreignTypeConName($result) === 'Handle';
}

function validateForeignTypeDecl(TypeCheckState $state, Ast\ForeignTypeDecl $decl): void
{
    // Type decls may name any implemented backend: they are nominal metadata.
    // Compile-backend matching applies to foreign *functions/consts* and to
    // using a foreign type inside another backend's foreign signature.
    if (!isBackendImplemented($decl->backend)) {
        throw typeFail(
            $state,
            formatKnownAlternatives(
                "unknown foreign type backend `{$decl->backend}`",
                implementedBackendIds(),
            ),
            $decl,
        );
    }

    if (trim($decl->hostType) === '') {
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
): void {
    if ($type instanceof Ast\TypeVar) {
        throw typeFail($state, 'foreign import cannot use type variables', $context);
    }

    if ($type instanceof Ast\TypeCon) {
        validateForeignTypeCon($state, $type, $backend, $context, $argument);
        return;
    }

    if ($type instanceof Ast\TypeApp) {
        validateForeignSignatureTypes($state, $type->con, $backend, $context, $argument);
        foreach ($type->args ?? [] as $arg) {
            validateForeignSignatureTypes($state, $arg, $backend, $context, $argument);
        }
        return;
    }

    if ($type instanceof Ast\TypeArrow) {
        validateForeignSignatureTypes($state, $type->from, $backend, $context, true);
        validateForeignSignatureTypes($state, $type->to, $backend, $context, false);
        return;
    }
}

function validateForeignTypeCon(
    TypeCheckState $state,
    Ast\TypeCon $con,
    string $backend,
    Ast\AstNode $context,
    bool $argument = false,
): void {
    $name = $con->name;

    if ($name === 'JObject' || $name === 'ClrObject' || $name === 'PhpRef') {
        throw typeFail(
            $state,
            "opaque host type `{$name}` has been deleted; use a specific foreign type declaration instead",
            $context,
        );
    }

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

    // PHPValue is an ordinary Moggi ADT used for deliberate mixed → inspectable
    // value reification at the FFI boundary (e.g., Composer interop).
    // It is NOT a foreign type / NOT a host handle, and only the php backend
    // boxes a host value into it.
    if ($name === 'PHPValue') {
        if ($backend !== 'php') {
            throw typeFail(
                $state,
                "`PHPValue` reification is only available on the php backend, but it is used with `{$backend}`",
                $context,
            );
        }
        return;
    }

    if ($name === 'Handle' || $name === 'Resource') {
        return;
    }

    $primitives = ['String', 'ByteString', 'Int', 'Int8', 'Int16', 'Int32', 'Int64', 'Integer', 'Double', 'Bool', 'Char', 'Word', 'Word8', 'Word16', 'Word32', 'Word64', 'Ordering', 'Unit', '()'];
    if (in_array($name, $primitives, true)) {
        return;
    }

    // Container types. A host result is converted into these; an argument has
    // no marshalling, so the host would receive Moggi's own representation.
    if (in_array($name, ['Maybe', 'Either', 'Tuple'], true)) {
        if ($argument) {
            throw typeFail(
                $state,
                "`{$name}` cannot be an argument of a foreign call — only a result is converted; pass the payload instead",
                $context,
            );
        }
        return;
    }

    if ($name === 'List') {
        // A Moggi list *is* a php array, which is why a list argument works
        // there; on jvm/.NET it is the runtime's own list, never the host's.
        if ($argument && $backend !== 'php') {
            throw typeFail(
                $state,
                "`[a]` cannot be an argument of a {$backend} foreign call — only php's array is the same value as a Moggi list; call it element by element or declare the host's own type",
                $context,
            );
        }
        return;
    }

    // IO is allowed in result position (outermost only, checked elsewhere)
    if ($name === 'IO') {
        return;
    }

    // IOMode is a primitive enum for foreign I/O functions
    if ($name === 'IOMode') {
        return;
    }

    // Everything else is an error
    throw typeFail(
        $state,
        "type `{$name}` is not legal in a foreign signature; use a specific foreign type, a primitive, or PHPValue for reification",
        $context,
    );
}
