<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Foreign;

use function Moggi\Backend\DotNet\Dependencies\dotNetTypeIsValueType;

use function Moggi\Backend\DotNet\Naming\bclAssemblyFor;
use function Moggi\Backend\DotNet\Verify\dotNetConstNeedsGetterCall;
use function Moggi\Backend\DotNet\Verify\sameClrTypeIgnoringScope;
use function Moggi\Backend\DotNet\Verify\splitClrSignature;
use function Moggi\Backend\backendById;
use function Moggi\Foreign\splitForeignPathMember;
use function Moggi\Semantics\IoBoundary\isIoTypeConName;
use function Moggi\Semantics\Kernel\boolTypeName;
use function Moggi\Semantics\Kernel\boundaryEitherTypeName;
use function Moggi\Semantics\Kernel\boundaryHandleTypeName;
use function Moggi\Semantics\Kernel\boundaryMaybeTypeName;
use function Moggi\Semantics\Types\primitiveTypeIdOf;

use Moggi\Foreign\ForeignTypeTable;
use Moggi\Syntax\Ast;

/**
 * The dotnet host types checked modules declare — one table, owned by the target.
 *
 * A `foreign dotnet type Path "…"` is one module's statement about one of its own names, and the
 * module is half the key ({@see ForeignTypeTable}): two modules declare `Path` against different
 * host types, and a bare-name map would let whichever was checked last answer for both.
 */
function registerDotNetForeignType(string $module, string $moggiName, string $hostType): void
{
    dotNetForeignTypes()->register($module, $moggiName, $hostType);
}

/** Every module that declares `$name`, with the host type it declares. */
function dotNetForeignTypeDeclarations(string $name): array
{
    return dotNetForeignTypes()->declarationsOf($name);
}

/**
 * The host type `$name` stands for, read from the module that declared it.
 *
 * With a module, that module's declaration and nothing else; without one, every declaring module
 * must agree, and a real disagreement is refused rather than answered by whichever ran last.
 */
function lookupDotNetForeignType(string $name, ?string $module = null): ?string
{
    return dotNetForeignTypes()->lookup($name, $module, 'dotnet');
}

/** Every host type any module declares, for set membership. */
function dotNetDeclaredForeignHostTypes(): array
{
    return dotNetForeignTypes()->hostTypes();
}

function dotNetForeignTypes(): ForeignTypeTable
{
    return backendById('dotnet')->foreignTypes();
}

/**
 * DotNet foreign paths:
 *   System.Char:ToLower          — static method
 *   System.Console:Out           — static field / property (kind=const)
 *   System.String.Substring      — instance method
 *   System.Text.StringBuilder:__con — constructor
 *   Some.Type:__cast             — reference cast to Some.Type (emit only)
 *   Some.Type:__default          — initobj default for valuetype (emit only)
 *   Some.Type:__null             — ldnull typed as Some.Type (emit only)
 */
function resolveDotNetForeignPath(string $path): array
{
    if (! \str_contains($path, ':') && ! \str_contains($path, '.')) {
        throw new \RuntimeException(
            "dotnet foreign path `{$path}` must be Type:member or Type.member; bare host names are not supported",
        );
    }

    [$classPath, $member] = splitForeignPathMember($path);

    if (\str_contains($path, ':')) {
        $dispatch = match ($member) {
            '__con' => 'constructor',
            '__cast', '__default', '__null' => 'intrinsic',
            default => 'static',
        };

        return [
            'dispatch' => $dispatch,
            'class' => $classPath,
            'hostMember' => $member === '__con' ? '.ctor' : $member,
        ];
    }

    return [
        'dispatch' => 'instance',
        'class' => $classPath,
        'hostMember' => $member,
    ];
}

/**
 * Infer a CLR method/field signature string for emit (ILASM-oriented).
 *
 * Format examples:
 *   method: "(int64,string)object" or "()void"
 *   field:  "string" / "object"
 *
 * A sidecar never overrides the declaration silently: where one applies, the declared parameter
 * list must match the entry's shared prefix typewise, and anything beyond it must sit inside the
 * entry's optional tail. The emitter then calls the sidecar's full list, padding what the
 * declaration does not pass.
 *
 * @param 'static'|'instance'|'constructor'|'intrinsic' $dispatch
 * @param 'function'|'const' $kind
 * @param ?array{params: list<string>, declared: list<string>, optionalTail: int} $sidecar
 */
function clrSigFromMoggiType(
    Ast\TypeNode $type,
    string $dispatch,
    string $kind,
    ?string $classPath,
    ?string $member = null,
    ?array $sidecar = null,
    string $name = '',
    ?string $module = null,
): string {
    if ($kind === 'const') {
        return clrFieldTypeName(clrForeignIoInnerType($type), $classPath, $member, $module);
    }

    if ($dispatch === 'intrinsic') {
        return clrIntrinsicSig($type, $classPath, $member, $module);
    }

    $argTypes = [];
    $cursor = $type;
    while ($cursor instanceof Ast\TypeArrow) {
        $argTypes[] = $cursor->from;
        $cursor = $cursor->to;
    }
    $result = clrForeignIoInnerType($type);

    if ($dispatch === 'instance') {
        if ($argTypes === []) {
            throw new \RuntimeException('dotnet instance foreign requires a receiver argument');
        }
        \array_shift($argTypes);
    }

    $params = [];
    foreach ($argTypes as $arg) {
        $params[] = clrTypeName($arg, $classPath, $member, false, $dispatch, $module);
    }

    if ($sidecar !== null) {
        $params = applyDotNetSidecarParams($name, $classPath, $member, $params, $sidecar);
    }

    if ($dispatch === 'constructor') {
        return '(' . \implode(',', $params) . ')';
    }

    $ret = $result instanceof Ast\TypeUnit
        ? 'void'
        : clrTypeName($result, $classPath, $member, true, $dispatch, $module);

    return '(' . \implode(',', $params) . ')' . $ret;
}

/**
 * Where the declaration and the sidecar disagree, and how the compile answers.
 *
 * The sidecar *describes* the host member; the declaration is what the program passes. The two
 * meet positionally: the declaration's inferred parameter list must equal the entry's `declared`
 * list over the shared prefix (they differ only where the entry blesses an interface with
 * `from`), and every sidecar parameter past the declaration must sit inside `optionalTail`.
 * Anything else is a compile error naming both sides — the sidecar cannot invent arguments, drop
 * them, or retype them.
 *
 * @param list<string> $declaredParams IL types inferred from the Moggi declaration
 * @param array{params: list<string>, declared: list<string>, optionalTail: int} $sidecar
 * @return list<string> the emit parameter list (the sidecar's full `params`)
 */
function applyDotNetSidecarParams(
    string $name,
    ?string $classPath,
    ?string $member,
    array $declaredParams,
    array $sidecar,
): array {
    $path = ($classPath ?? '') . ':' . ($member ?? '');
    $error = dotNetSidecarMismatch($name, $path, $declaredParams, $sidecar);
    if ($error !== null) {
        throw new \RuntimeException($error);
    }

    return $sidecar['params'];
}

/**
 * @param list<string> $declaredParams
 * @param array{params: list<string>, declared: list<string>, optionalTail: int} $sidecar
 */
function dotNetSidecarMismatch(
    string $name,
    string $path,
    array $declaredParams,
    array $sidecar,
): ?string {
    $nDeclared = \count($declaredParams);
    $nSidecar = \count($sidecar['params']);
    if ($nDeclared > $nSidecar) {
        return "foreign dotnet `{$name}` ({$path}) declares {$nDeclared} parameter(s)"
            . " but its sidecar entry lists {$nSidecar} — the sidecar cannot drop declared arguments";
    }
    $nExtra = $nSidecar - $nDeclared;
    if ($nExtra > $sidecar['optionalTail']) {
        return "foreign dotnet `{$name}` ({$path}) passes {$nDeclared} argument(s)"
            . " but its sidecar entry lists {$nSidecar} with optionalTail {$sidecar['optionalTail']}"
            . ' — the entry cannot invent required arguments';
    }
    foreach ($declaredParams as $i => $inferred) {
        $blessed = $sidecar['declared'][$i] ?? null;
        if ($blessed !== $inferred) {
            return "foreign dotnet `{$name}` ({$path}) declares parameter {$i} as `{$inferred}`"
                . " but its sidecar entry says `{$blessed}` — the sidecar cannot retype the declaration";
        }
    }

    return null;
}

/**
 * Signatures for emit-only intrinsics (__cast / __default / __null).
 */
function clrIntrinsicSig(Ast\TypeNode $type, ?string $classPath, ?string $member, ?string $module = null): string
{
    $result = clrForeignIoInnerType($type);
    $ret = $result instanceof Ast\TypeUnit
        ? 'void'
        : clrTypeName($result, $classPath, $member, true, 'intrinsic', $module);

    $argTypes = [];
    $cursor = $type;
    while ($cursor instanceof Ast\TypeArrow) {
        $argTypes[] = $cursor->from;
        $cursor = $cursor->to;
    }

    if ($member === '__cast') {
        if (\count($argTypes) !== 1) {
            throw new \RuntimeException('dotnet __cast requires exactly one argument');
        }
        $param = clrTypeName($argTypes[0], $classPath, $member, false, 'intrinsic', $module);

        return '(' . $param . ')' . $ret;
    }

    if ($member === '__default' || $member === '__null') {
        if ($argTypes !== []) {
            throw new \RuntimeException("dotnet {$member} takes no arguments");
        }

        return '()' . $ret;
    }

    throw new \RuntimeException("dotnet unknown foreign intrinsic `{$member}`");
}

/**
 * Static-field / property descriptors whose real CLR type differs from the
 * generic Moggi-type mapping (e.g. `System.Console:Out` is a TextWriter).
 */
function clrFieldTypeName(
    Ast\TypeNode $type,
    ?string $classPath,
    ?string $member,
    ?string $module = null,
): string
{
    $key = ($classPath ?? '') . ':' . ($member ?? '');
    static $known = null;
    if ($known === null) {
        $known = [
            'System.Console:Out' => 'class [System.Runtime]System.IO.TextWriter',
            'System.Console:Error' => 'class [System.Runtime]System.IO.TextWriter',
            'System.Console:In' => 'class [System.Runtime]System.IO.TextReader',
            'System.Int64:MinValue' => 'int64',
            'System.Int64:MaxValue' => 'int64',
        ];
    }
    if (isset($known[$key])) {
        return $known[$key];
    }

    return clrTypeName($type, $classPath, $member, true, 'static', $module);
}

/**
 * True BCL "static readonly value" surfaces are often auto-properties, not
 * fields (e.g. `Console.Out` is a `get_Out()` getter). `const`-kind foreign
 * imports for a property are emitted as a getter `call` instead of `ldsfld`.
 *
 * The named three are answered without touching the host; anything else asks
 * the inspector, which already read the type for verification. An unanswerable
 * host keeps the field load — the pre-inspector behavior, not a new guess.
 */
function isDotNetConstProperty(?string $classPath, ?string $member): bool
{
    static $known = [
        'System.Console:Out' => true,
        'System.Console:Error' => true,
        'System.Console:In' => true,
    ];

    if (isset($known[($classPath ?? '') . ':' . ($member ?? '')])) {
        return true;
    }

    return dotNetConstNeedsGetterCall($classPath, $member);
}

function clrTypeName(
    Ast\TypeNode $type,
    ?string $classPath,
    ?string $member,
    bool $isReturn,
    string $dispatch = 'static',
    ?string $module = null,
): string {
    if ($type instanceof Ast\TypeUnit) {
        return 'void';
    }

    if ($type instanceof Ast\TypeApp && $type->con instanceof Ast\TypeCon) {
        $container = $type->con->name;
        if ($container === boundaryMaybeTypeName()) {
            $inner = $type->args[0] ?? null;
            if ($inner instanceof Ast\AstNode) {
                return clrTypeName($inner, $classPath, $member, $isReturn, $dispatch, $module);
            }
        }
        if ($container === boundaryEitherTypeName()) {
            $inner = $type->args[1] ?? $type->args[0] ?? null;
            if ($inner instanceof Ast\AstNode) {
                return clrTypeName($inner, $classPath, $member, $isReturn, $dispatch, $module);
            }
        }
    }

    $con = clrForeignTypeConName($type);

    if ($classPath === 'System.Object' && $member === 'ReferenceEquals' && ! $isReturn) {
        return 'object';
    }

    if ($classPath === 'System.Convert' && $member === 'ToString' && ! $isReturn) {
        return 'object';
    }

    if ($classPath === 'System.Convert' && $member === 'ToInt32' && ! $isReturn) {
        return 'object';
    }

    $kernel = clrKernelTypeName(primitiveTypeIdOf((string) $con));
    if ($kernel !== null) {
        return $kernel;
    }

    return match ($con) {
        boolTypeName() => 'bool',
        boundaryHandleTypeName() => clrOpaqueObjectMapping($classPath, $isReturn, $dispatch),
        'List' => 'class Moggi.Rt.MList',
        default => clrForeignLeafToIl((string) $con, $module),
    };
}

/**
 * The CLR type of a kernel type, or null when the CLR has no direct one for it.
 *
 * Keyed by the kernel's own ids, not by the names a signature writes them with: the
 * kernel's vocabulary is the machine's, and a library name reaching this table would
 * make the mapping a statement about `lib/` rather than about the CLR.
 */
function clrKernelTypeName(?string $kernelId): ?string
{
    return match ($kernelId) {
        'Int#', 'Int64#' => 'int64',
        'Int8#' => 'int8',
        'Int16#' => 'int16',
        'Int32#' => 'int32',
        'Char#' => 'char',
        'Integer#' => 'valuetype [System.Runtime.Numerics]System.Numerics.BigInteger',
        'Double#' => 'float64',
        'String#', 'Bytes#' => 'string',
        default => null,
    };
}

/**
 * Map a foreign nominal leaf to its IL type descriptor. Fail closed — never
 * silently fall back to `object` for an unresolved foreign type.
 */
function clrForeignLeafToIl(string $con, ?string $module = null): string
{
    $host = lookupDotNetForeignType($con, $module);
    if ($host === null) {
        throw new \RuntimeException(
            "dotnet foreign type `{$con}` is not registered (no hostType); refusing object fallback",
        );
    }

    return clrHostTypeToIl($host);
}

/**
 * Convert a foreign `hostType` string to an ILASM type descriptor.
 *
 * Conventions:
 *   - `System.Foo.Bar`           → `class [Asm]System.Foo.Bar`
 *   - `valuetype System.Foo.Bar` → `valuetype [Asm]System.Foo.Bar`
 *   - already-qualified forms (`class [Asm]…` / `valuetype [Asm]…` / `object`) are kept
 */
function clrHostTypeToIl(string $host): string
{
    $host = \trim($host);
    if ($host === '') {
        throw new \RuntimeException('dotnet foreign hostType must be non-empty');
    }
    if ($host === 'object' || $host === 'System.Object') {
        return 'object';
    }
    if (\str_starts_with($host, 'class ') || \str_starts_with($host, 'valuetype ') || \str_ends_with($host, '[]')) {
        if (\str_starts_with($host, 'class [') || \str_starts_with($host, 'valuetype [') || $host === 'object[]') {
            return $host;
        }
        if (\str_starts_with($host, 'valuetype ')) {
            $rest = \trim(\substr($host, \strlen('valuetype ')));
            if (\str_starts_with($rest, '[')) {
                return 'valuetype ' . $rest;
            }

            return 'valuetype ' . qualifyTypeForOpaque($rest);
        }
        if (\str_starts_with($host, 'class ')) {
            $rest = \trim(\substr($host, \strlen('class ')));
            if (\str_starts_with($rest, '[')) {
                return 'class ' . $rest;
            }

            return 'class ' . qualifyTypeForOpaque($rest);
        }

        return $host;
    }

    $kind = dotNetTypeIsValueType($host) === true ? 'valuetype ' : 'class ';

    return $kind . qualifyTypeForOpaque($host);
}

/** Opaque host refs: instance returns use the declaring type for fluent APIs. */
function clrOpaqueObjectMapping(?string $classPath, bool $isReturn, string $dispatch): string
{
    if ($isReturn && $dispatch === 'instance' && $classPath !== null && $classPath !== '') {
        return 'class ' . qualifyTypeForOpaque($classPath);
    }

    return 'object';
}

function qualifyTypeForOpaque(string $dotted): string
{
    if (\str_starts_with($dotted, 'Moggi.')) {
        return $dotted;
    }

    return '[' . bclAssemblyFor($dotted) . ']' . $dotted;
}

/**
 * True when a registered foreign hostType is a valuetype, or the CLR type is
 * declared one in library metadata.
 */
function dotNetHostIsValueType(string $classPath): bool
{
    $declared = dotNetTypeIsValueType($classPath);
    if ($declared !== null) {
        return $declared;
    }

    foreach (dotNetDeclaredForeignHostTypes() as $host) {
        $host = \trim((string) $host);
        if (! \str_starts_with($host, 'valuetype ')) {
            continue;
        }
        $rest = \trim(\substr($host, \strlen('valuetype ')));
        if (\str_starts_with($rest, '[')) {
            $close = \strpos($rest, ']');
            if ($close !== false) {
                $rest = \substr($rest, $close + 1);
            }
        }
        if ($rest === $classPath || \str_ends_with($rest, '/' . $classPath)) {
            return true;
        }
        $normHost = \str_replace('+', '/', $rest);
        $normClass = \str_replace('+', '/', $classPath);
        if ($normHost === $normClass || \str_ends_with($normHost, '/' . $normClass)) {
            return true;
        }
    }

    return false;
}

function clrForeignIoInnerType(Ast\TypeNode $type): Ast\TypeNode
{
    $result = $type;
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    if (
        $result instanceof Ast\TypeApp
        && $result->con instanceof Ast\TypeCon
        && isIoTypeConName($result->con->name)
    ) {
        return $result->args[0] ?? new Ast\TypeUnit();
    }

    return $result;
}

function clrForeignTypeConName(Ast\TypeNode $type): ?string
{
    return match ($type::class) {
        Ast\TypeCon::class => $type->name,
        Ast\TypeApp::class => $type->con instanceof Ast\TypeCon ? $type->con->name : null,
        default => null,
    };
}

/**
 * Resolve a foreign call against one member's inspector overloads — the CLR
 * mirror of the JVM's `resolveJvmForeignOverload`.
 *
 * Exact-signature matching stays the default: when any candidate carries the
 * inferred signature, the answer is `exact` and nothing below runs. Otherwise
 * a trailing host array may complete the call — but only as written here,
 * never inferred from the array shape alone:
 *
 * - omission: the declaration is one parameter short and the host proves the
 *   last parameter a `params` array (the inspector's `paramsArray` index,
 *   read from `ParamArrayAttribute`) — the emitter synthesizes an empty
 *   array of the component type;
 * - supply: the declaration's last parameter is a Moggi list and the host's
 *   last parameter is a `params` array with a compatible element — the
 *   emitter packs the list.
 *
 * Exactly one qualifying overload may exist; two is `ambiguous`, never a
 * guess. A trailing Moggi list against a non-array host, or an unmappable
 * element type, is `none` with a `detail` saying so. A missing `paramsArray`
 * proof (older inspector output, unanswerable host) simply never qualifies —
 * the declaration falls through to the exact-only verdict, the way a proven
 * non-variadic tail does on the JVM side.
 *
 * The emitter trusts this resolution the way the JVM emitter trusts its own:
 * a value in a proven `params` slot at emit time is packed as a Moggi list,
 * because no other Moggi value inhabits an array-typed slot by construction.
 *
 * @param list<array{params?: list<string>, ret?: string, static?: bool, public?: bool, paramsArray?: int}> $candidates inspector overloads under one member name
 * @param ?Ast\TypeNode $moggiType the declared Moggi type, or null to skip element checks (emit trusts verify)
 * @return array{status: 'exact'|'omit'|'pack'|'none'|'ambiguous', signature?: string, candidates?: list<string>, detail?: string}
 */
function resolveDotNetForeignOverload(
    array $candidates,
    string $expected,
    ?Ast\TypeNode $moggiType = null,
    ?string $classPath = null,
    ?string $member = null,
    string $dispatch = 'static',
): array {
    $want = splitClrSignature($expected);
    foreach ($candidates as $candidate) {
        if (! ($candidate['public'] ?? false)) {
            continue;
        }
        if (! dotNetParamsMatch($candidate['params'] ?? [], $want['params'])) {
            continue;
        }
        if (! sameClrTypeIgnoringScope((string) ($candidate['ret'] ?? ''), $want['ret'])) {
            continue;
        }

        return ['status' => 'exact'];
    }

    $declEndsList = $want['params'] !== [] && \end($want['params']) === 'class Moggi.Rt.MList';

    $omit = [];
    $pack = [];
    foreach ($candidates as $candidate) {
        if (! ($candidate['public'] ?? false)) {
            continue;
        }
        $hostParams = $candidate['params'] ?? [];
        if (! sameClrTypeIgnoringScope((string) ($candidate['ret'] ?? ''), $want['ret'])) {
            continue;
        }
        $last = \end($hostParams);
        if (! \is_string($last) || ! \str_ends_with($last, '[]') || \str_ends_with($last, '[...]')) {
            continue;
        }
        if (($candidate['paramsArray'] ?? -1) !== \count($hostParams) - 1) {
            continue;
        }
        if (\count($hostParams) === \count($want['params']) + 1
            && dotNetOmitPrefixMatches($hostParams, $want['params'])
            && ! $declEndsList
        ) {
            $omit[] = $candidate;

            continue;
        }
        if (\count($hostParams) === \count($want['params'])
            && $declEndsList
            && dotNetOmitPrefixMatches(
                \array_slice($hostParams, 0, -1),
                \array_slice($want['params'], 0, -1),
            )
        ) {
            $pack[] = ['candidate' => $candidate, 'component' => \substr($last, 0, -2)];
        }
    }

    if ($omit !== [] || $pack !== []) {
        if (\count($omit) + \count($pack) > 1) {
            $descs = [];
            foreach ($omit as $entry) {
                $descs[] = dotNetCandidateSig($entry);
            }
            foreach ($pack as $entry) {
                $descs[] = dotNetCandidateSig($entry['candidate']);
            }
            \sort($descs);

            return ['status' => 'ambiguous', 'candidates' => $descs];
        }
        if ($omit !== []) {
            return ['status' => 'omit', 'signature' => dotNetCandidateSig($omit[0])];
        }
        $compat = dotNetVarargsElementCompat(
            $moggiType,
            $pack[0]['component'],
            $classPath,
            $member,
            $dispatch,
        );
        if ($compat !== null) {
            return ['status' => 'none', 'detail' => $compat];
        }

        return ['status' => 'pack', 'signature' => dotNetCandidateSig($pack[0]['candidate'])];
    }

    if ($declEndsList) {
        return ['status' => 'none', 'detail' => 'a trailing Moggi list needs a host array parameter to pack into'];
    }

    return ['status' => 'none'];
}

/** Rebuild `(params)ret` from an inspector candidate. */
function dotNetCandidateSig(array $candidate): string
{
    return '(' . \implode(',', $candidate['params'] ?? []) . ')' . ($candidate['ret'] ?? 'void');
}

/** Parameters equal ignoring assembly scopes. */
function dotNetParamsMatch(array $a, array $b): bool
{
    if (\count($a) !== \count($b)) {
        return false;
    }
    foreach ($a as $i => $param) {
        if (! sameClrTypeIgnoringScope($param, $b[$i])) {
            return false;
        }
    }

    return true;
}

/**
 * Whether the host's leading parameters accept the declaration's: equal
 * ignoring scopes, or a declared reference in a host `object` slot — the CLR
 * widening rule, which needs no check and emits no cast. Value types never
 * widen: a boxed Moggi number in an `object` slot would need a box the
 * declaration did not ask for.
 *
 * @param list<string> $hostParams
 * @param list<string> $wantParams
 */
function dotNetOmitPrefixMatches(array $hostParams, array $wantParams): bool
{
    foreach ($wantParams as $i => $want) {
        $host = $hostParams[$i] ?? null;
        if (\is_string($host) && sameClrTypeIgnoringScope($host, $want)) {
            continue;
        }
        if ($host === 'object' && dotNetIsReferenceIlType($want)) {
            continue;
        }

        return false;
    }

    return true;
}

/** A CLR type a reference conversion carries without a check: classes, `string`, `object`, arrays. */
function dotNetIsReferenceIlType(string $il): bool
{
    if ($il === 'object' || $il === 'string') {
        return true;
    }
    if (\str_starts_with($il, 'class ') || \str_ends_with($il, '[]') || \str_ends_with($il, '[...]')) {
        return true;
    }

    return false;
}

/**
 * Whether a Moggi list element may pack into a host array component.
 *
 * Reference components take an equal element type, or anything for `object`
 * (the host checks); primitive components take the Moggi numbers that box
 * compatibly. Null (no detail) means compatible.
 */
function dotNetVarargsElementCompat(
    ?Ast\TypeNode $moggiType,
    string $component,
    ?string $classPath,
    ?string $member,
    string $dispatch,
): ?string {
    if ($moggiType === null) {
        return null;
    }
    $cursor = $moggiType;
    $element = null;
    while ($cursor instanceof Ast\TypeArrow) {
        $element = $cursor->from;
        $cursor = $cursor->to;
    }
    if ($element instanceof Ast\TypeApp
        && $element->con instanceof Ast\TypeCon
        && $element->con->name === 'List'
        && \count($element->args) === 1
        && $element->args[0] instanceof Ast\TypeNode
    ) {
        $element = $element->args[0];
    }
    if (! ($element instanceof Ast\TypeNode)) {
        return 'cannot read the supplied list element type';
    }
    try {
        $elementDesc = clrTypeName($element, $classPath, $member, false, $dispatch);
    } catch (\Throwable $e) {
        return 'cannot map the supplied list element type: ' . $e->getMessage();
    }
    if ($component === 'object') {
        if (! dotNetIsReferenceIlType($elementDesc)) {
            return "list elements are `{$elementDesc}` but the host takes `{$component}`";
        }

        return null;
    }
    if (sameClrTypeIgnoringScope($elementDesc, $component)) {
        return null;
    }
    $intish = ['int64', 'int32', 'int16', 'char', 'int8'];
    if ($elementDesc === 'int64' && \in_array($component, $intish, true)) {
        return null;
    }
    if (($elementDesc === 'float64' && $component === 'float64')
        || ($elementDesc === 'bool' && $component === 'bool')
    ) {
        return null;
    }

    return "list elements are `{$elementDesc}` but the host takes `{$component}`";
}
