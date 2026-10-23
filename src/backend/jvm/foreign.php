<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Foreign;

use function Moggi\Foreign\splitForeignPathMember;

/**
 * JVM foreign paths are opaque to the language. This resolver interprets them.
 *
 * Grammar (no descriptors in source — inferred from Moggi types):
 *   java.lang.Character:toLowerCase   — static method (invokestatic)
 *   java.lang.System:out              — static field when kind=const (getstatic)
 *   java.lang.String.lines            — instance method (invokevirtual)
 *   java.lang.String:__con      — constructor
 *   java.lang.CharSequence:__cast     — reference cast to that type (emit only)
 *
 * Foreign type hostType may be prefixed with {@code interface } (or
 * {@code interface:}) so invokeinterface is selected without a per-member table.
 */

use function Moggi\Semantics\IoBoundary\isIoTypeConName;
use function Moggi\Semantics\Kernel\boolTypeName;
use function Moggi\Semantics\Kernel\boundaryEitherTypeName;
use function Moggi\Semantics\Kernel\boundaryHandleTypeName;
use function Moggi\Semantics\Kernel\boundaryMaybeTypeName;
use function Moggi\Semantics\Types\primitiveTypeIdOf;

use Moggi\Syntax\Ast;

function registerJvmForeignType(string $moggiName, string $hostType): void
{
    $GLOBALS['moggi_jvm_foreign_types'][$moggiName] = $hostType;
}

function lookupJvmForeignType(string $name): ?string
{
    return $GLOBALS['moggi_jvm_foreign_types'][$name] ?? null;
}

/**
 * Strip an optional {@code interface } / {@code interface:} prefix from a host type.
 *
 * @return array{0: string, 1: bool} [dotted host class/iface, isInterface]
 */
function jvmParseForeignHostType(string $hostType): array
{
    $trimmed = \trim($hostType);
    if (\str_starts_with($trimmed, 'interface:')) {
        return [\trim(\substr($trimmed, \strlen('interface:'))), true];
    }
    if (\str_starts_with($trimmed, 'interface ')) {
        return [\trim(\substr($trimmed, \strlen('interface '))), true];
    }

    return [$trimmed, false];
}

function jvmForeignHostInternal(string $hostType): string
{
    [$dotted] = jvmParseForeignHostType($hostType);

    return dottedToInternal($dotted);
}

/** @return array{dispatch: 'static'|'instance'|'constructor'|'intrinsic', class: string, hostMember: string} */
function resolveJvmForeignPath(string $path): array
{
    if (! \str_contains($path, ':') && ! \str_contains($path, '.')) {
        throw new \RuntimeException(
            "JVM foreign path `{$path}` must be Class:member or Class.member; bare host names are not supported",
        );
    }

    [$classPath, $member] = splitForeignPathMember($path);

    if (\str_contains($path, ':')) {
        $dispatch = match ($member) {
            '__con' => 'constructor',
            '__cast' => 'intrinsic',
            default => 'static',
        };

        return [
            'dispatch' => $dispatch,
            'class' => dottedToInternal($classPath),
            'hostMember' => $member === '__con' ? '<init>' : $member,
        ];
    }

    return [
        'dispatch' => 'instance',
        'class' => dottedToInternal($classPath),
        'hostMember' => $member,
    ];
}

function dottedToInternal(string $dotted): string
{
    return \str_replace('.', '/', $dotted);
}

/**
 * The interface owners a compilation's `foreign jvm type` declarations name, by internal name.
 *
 * The kind is a fact of the declaration (`interface java.io.Closeable`), so it is read from the
 * parsed programs the compilation is made of — not from {@see registerJvmForeignType}'s registry,
 * which only a *typecheck* fills. A project compile emits modules it did not re-check in this
 * process (shallow library units, cached products), and those are exactly the modules that carry
 * the declarations; reading the source facts keeps the answer attached to the compilation instead
 * of to which modules happened to run through the checker first.
 *
 * @param iterable<Ast\Program> $programs
 * @return array<string, true>
 */
function jvmInterfaceNamesFromPrograms(iterable $programs): array
{
    $names = [];
    foreach ($programs as $program) {
        foreach ($program->items as $item) {
            if (! $item instanceof Ast\ForeignTypeDecl || $item->backend !== 'jvm') {
                continue;
            }
            [$dotted, $isInterface] = jvmParseForeignHostType($item->hostType);
            if ($isInterface) {
                $names[dottedToInternal($dotted)] = true;
            }
        }
    }

    return $names;
}

/**
 * Moggi interface types that require InterfaceMethodref + invokeinterface.
 * (checkcast to an interface is fine; invokevirtual is not.)
 *
 * The answer comes from declarations, not from a hardcoded list: every
 * {@code foreign jvm type Foo "interface …"} registers, and the generated
 * binder emits the prefix for every interface `javap` reports. An interface
 * reached with no such declaration is a missing declaration, not a missing
 * table entry.
 *
 * This reads the process registry, which only a check fills. An emitter should
 * consult the set its compilation published instead
 * ({@see jvmInterfaceNamesFromPrograms}); this remains for callers that have no
 * compilation to ask.
 */
function jvmIsInterface(string $internalName): bool
{
    foreach ($GLOBALS['moggi_jvm_foreign_types'] ?? [] as $hostType) {
        [$dotted, $isIface] = jvmParseForeignHostType((string) $hostType);
        if ($isIface && dottedToInternal($dotted) === $internalName) {
            return true;
        }
    }

    return false;
}

/**
 * Infer a JVM method/field descriptor from the Moggi foreign type.
 * Paths never carry descriptors — only Class:member / Class.member.
 *
 * @param 'static'|'instance'|'constructor' $dispatch
 * @param 'function'|'const' $kind
 */
function jvmDescriptorFromMoggiType(
    Ast\TypeNode $type,
    string $dispatch,
    string $kind,
    ?string $classPath,
    ?string $member = null,
): string {
    if ($kind === 'const') {
        return jvmFieldDescriptorFromMoggiType(jvmForeignIoInnerType($type), $classPath, $member);
    }

    $argTypes = [];
    $cursor = $type;
    while ($cursor instanceof Ast\TypeArrow) {
        $argTypes[] = $cursor->from;
        $cursor = $cursor->to;
    }
    $result = jvmForeignIoInnerType($type);

    if ($dispatch === 'instance') {
        if ($argTypes === []) {
            throw new \RuntimeException('JVM instance foreign requires a receiver argument');
        }
        \array_shift($argTypes);
    }

    $params = '';
    $argIndex = 0;
    foreach ($argTypes as $arg) {
        $params .= jvmTypeDescriptorFromMoggiType($arg, $classPath, false, $member, $dispatch, $argIndex++);
    }

    if ($dispatch === 'constructor') {
        return '(' . $params . ')V';
    }

    if ($result instanceof Ast\TypeUnit) {
        return '(' . $params . ')V';
    }

    return '(' . $params . ')' . jvmTypeDescriptorFromMoggiType($result, $classPath, true, $member, $dispatch);
}

function jvmFieldDescriptorFromMoggiType(Ast\TypeNode $type, ?string $classPath, ?string $member): string
{
    $key = ($classPath ?? '') . ':' . ($member ?? '');
    $known = [
        'java.lang.Long:MIN_VALUE' => 'J',
        'java.lang.Long:MAX_VALUE' => 'J',
        'java.lang.System:out' => 'Ljava/io/PrintStream;',
        'java.lang.System:err' => 'Ljava/io/PrintStream;',
        'java.lang.System:in' => 'Ljava/io/InputStream;',
    ];
    if (isset($known[$key])) {
        return $known[$key];
    }

    return jvmTypeDescriptorFromMoggiType($type, $classPath, true, $member, 'static');
}

function jvmTypeDescriptorFromMoggiType(
    Ast\TypeNode $type,
    ?string $classPath,
    bool $isReturn,
    ?string $member = null,
    string $dispatch = 'static',
    int $argIndex = 0,
): string {
    if ($type instanceof Ast\TypeUnit) {
        return 'V';
    }

    if ($type instanceof Ast\TypeApp && $type->con instanceof Ast\TypeCon) {
        $container = $type->con->name;
        if ($container === boundaryMaybeTypeName()) {
            $inner = $type->args[0] ?? null;
            if ($inner instanceof Ast\AstNode) {
                return jvmTypeDescriptorFromMoggiType(
                    $inner,
                    $classPath,
                    $isReturn,
                    $member,
                    $dispatch,
                    $argIndex,
                );
            }
        }
        if ($container === boundaryEitherTypeName()) {
            $inner = $type->args[1] ?? $type->args[0] ?? null;
            if ($inner instanceof Ast\AstNode) {
                return jvmTypeDescriptorFromMoggiType(
                    $inner,
                    $classPath,
                    $isReturn,
                    $member,
                    $dispatch,
                    $argIndex,
                );
            }
        }
    }

    $con = jvmForeignTypeConName($type);

    if ($classPath === 'java.util.Objects') {
        if ($member === 'isNull' || $member === 'nonNull') {
            if (! $isReturn) {
                return 'Ljava/lang/Object;';
            }
        }
        if ($member === 'requireNonNull') {
            return 'Ljava/lang/Object;';
        }
    }

    if (
        ! $isReturn
        && $classPath === 'java.nio.file.Files'
        && $member === 'writeString'
        && primitiveTypeIdOf((string) $con) === 'String#'
    ) {
        return 'Ljava/lang/CharSequence;';
    }

    $argOverride = jvmArgDescriptorOverride($classPath, $member, $dispatch, $isReturn, $argIndex);
    if ($argOverride !== null) {
        return $argOverride;
    }

    $kernel = jvmKernelDescriptor(primitiveTypeIdOf((string) $con));
    if ($kernel !== null) {
        return $kernel;
    }

    return match ($con) {
        boolTypeName() => 'Z',
        boundaryHandleTypeName() => jvmOpaqueObjectDescriptor($classPath, $isReturn, $dispatch),
        'List' => jvmListDescriptor($classPath, $member),
        default => jvmForeignTypeDescriptorOrFail($con),
    };
}

/**
 * The JVM descriptor of a kernel type, or null when the JVM has no direct one for it.
 *
 * Keyed by the kernel's own ids, not by the names a signature writes them with: the
 * kernel's vocabulary is the machine's, and a library name reaching this table would
 * make the mapping a statement about `lib/` rather than about the JVM.
 */
function jvmKernelDescriptor(?string $kernelId): ?string
{
    return match ($kernelId) {
        'Int#', 'Int64#' => 'J',
        'Int8#' => 'B',
        'Int16#' => 'S',
        'Int32#', 'Char#' => 'I',
        'Integer#' => 'Ljava/math/BigInteger;',
        'Double#' => 'D',
        'String#', 'Bytes#' => 'Ljava/lang/String;',
        default => null,
    };
}

/**
 * Fail closed: declared foreign types lower to their hostType; unknown Moggi
 * constructors are not silently mapped to Ljava/lang/Object;.
 */
function jvmForeignTypeDescriptorOrFail(?string $con): string
{
    if ($con === null || $con === '') {
        throw new \RuntimeException(
            'JVM foreign signature contains a non-nominal type that cannot be lowered to a host descriptor',
        );
    }

    $host = lookupJvmForeignType($con);
    if ($host === null) {
        throw new \RuntimeException(
            "unknown type `{$con}` in JVM foreign signature; declare"
            . " `foreign jvm type {$con} \"…\"` or use a supported primitive/Handle/List",
        );
    }

    return 'L' . jvmForeignHostInternal($host) . ';';
}

/**
 * Opaque host refs: instance-method returns use the declaring class so the
 * invokevirtual descriptor matches (e.g. StringBuilder.append → StringBuilder).
 */
function jvmOpaqueObjectDescriptor(?string $classPath, bool $isReturn, string $dispatch): string
{
    if ($isReturn && $dispatch === 'instance' && $classPath !== null && $classPath !== '') {
        return 'L' . dottedToInternal($classPath) . ';';
    }

    return 'Ljava/lang/Object;';
}

/** Moggi Int is the platform word (i64, J). Fixed widths come from Data.Int. */

function jvmListDescriptor(?string $classPath, ?string $member = null): string
{
    $key = ($classPath ?? '') . '.' . ($member ?? '');
    $streamReturns = [
        'java.lang.String.lines' => true,
    ];
    if (isset($streamReturns[$key])) {
        return 'Ljava/util/stream/Stream;';
    }

    return 'Lmoggi/rt/MList;';
}

function jvmForeignIoInnerType(Ast\TypeNode $type): Ast\TypeNode
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

function jvmForeignTypeConName(Ast\TypeNode $type): ?string
{
    return match ($type::class) {
        Ast\TypeCon::class => $type->name,
        Ast\TypeApp::class => $type->con instanceof Ast\TypeCon ? $type->con->name : null,
        default => null,
    };
}

/** @return list<string> each param type descriptor (I, Z, Ljava/lang/String;, …) */
function parseJvmParamKinds(string $desc): array
{
    if ($desc === '' || $desc[0] !== '(') {
        return [];
    }
    $i = 1;
    $kinds = [];
    $n = \strlen($desc);
    while ($i < $n && $desc[$i] !== ')') {
        $start = $i;
        $c = $desc[$i];
        if ($c === 'L') {
            $semi = \strpos($desc, ';', $i);
            if ($semi === false) {
                throw new \RuntimeException("bad JVM descriptor: {$desc}");
            }
            $i = $semi + 1;
        } elseif ($c === '[') {
            $i++;
            while ($i < $n && $desc[$i] === '[') {
                $i++;
            }
            if ($i < $n && $desc[$i] === 'L') {
                $semi = \strpos($desc, ';', $i);
                $i = $semi === false ? $n : $semi + 1;
            } else {
                $i++;
            }
        } else {
            $i++;
        }
        $kinds[] = \substr($desc, $start, $i - $start);
    }

    return $kinds;
}

function jvmReturnDescriptor(string $methodDesc): string
{
    $pos = \strrpos($methodDesc, ')');
    if ($pos === false) {
        return $methodDesc;
    }

    return \substr($methodDesc, $pos + 1);
}

/**
 * Resolve a foreign call against one member's host overloads.
 *
 * Exact-descriptor matching stays the default: when any candidate carries the
 * inferred descriptor, the answer is `exact` and nothing below runs. Otherwise
 * a trailing host array may complete the call — but only as written here, never
 * inferred from the array shape alone:
 *
 * - omission: the declaration is one parameter short and backend metadata
 *   proves the host's last parameter variadic — the emitter synthesizes an
 *   empty array of the component type. The shared prefix compares with
 *   Object-widening: a declared reference in a host `Object` slot is the
 *   Java assignment rule, checked nowhere else, so it is allowed here;
 * - supply: the declaration's last parameter is a Moggi list and the host's
 *   last parameter is an array (variadic or not) with a compatible element —
 *   the emitter packs the list. No metadata call: nothing is omitted.
 *
 * Exactly one qualifying overload may exist; two is `ambiguous`, never a guess.
 * A trailing Moggi list against a non-array host, or an unmappable element
 * type, is `none` with a `detail` saying so.
 *
 * @param list<array{descriptor: string, public: bool}> $candidates host overloads under one member name
 * @param callable(string): ?bool $isVarargs backend metadata: true when (class, member, descriptor)
 *   declares its trailing array parameter variadic, false when it does not, null when unanswerable
 * @param ?Ast\TypeNode $moggiType the declared Moggi type, or null to skip element checks (emit trusts verify)
 * @return array{status: 'exact'|'omit'|'pack'|'none'|'ambiguous', descriptor?: string, pad?: string, candidates?: list<string>, detail?: string}
 */
function resolveJvmForeignOverload(
    array $candidates,
    string $expected,
    callable $isVarargs,
    ?Ast\TypeNode $moggiType = null,
): array {
    foreach ($candidates as $candidate) {
        if (($candidate['descriptor'] ?? '') === $expected) {
            return ['status' => 'exact'];
        }
    }

    $wantParams = parseJvmParamKinds($expected);
    $wantRet = jvmReturnDescriptor($expected);
    $declEndsList = $wantParams !== [] && \end($wantParams) === 'Lmoggi/rt/MList;';

    $omit = [];
    $pack = [];
    foreach ($candidates as $candidate) {
        if (! ($candidate['public'] ?? false)) {
            continue;
        }
        $hostParams = parseJvmParamKinds((string) ($candidate['descriptor'] ?? ''));
        if (jvmReturnDescriptor((string) ($candidate['descriptor'] ?? '')) !== $wantRet) {
            continue;
        }
        $last = \end($hostParams);
        if ($last === false || ! \str_starts_with($last, '[')) {
            continue;
        }
        if (\count($hostParams) === \count($wantParams) + 1
            && jvmOmitPrefixMatches($hostParams, $wantParams)
            && ! $declEndsList
        ) {
            $omit[] = $candidate['descriptor'];

            continue;
        }
        if (\count($hostParams) === \count($wantParams) && $declEndsList) {
            $pack[] = ['descriptor' => $candidate['descriptor'], 'component' => \substr($last, 1)];
        }
    }

    if ($omit !== [] || $pack !== []) {
        if (\count($omit) + \count($pack) > 1) {
            $descs = [...$omit];
            foreach ($pack as $entry) {
                $descs[] = $entry['descriptor'];
            }
            \sort($descs);

            return ['status' => 'ambiguous', 'candidates' => $descs];
        }
        if ($omit !== []) {
            $hostParams = parseJvmParamKinds($omit[0]);
            $component = \substr((string) \end($hostParams), 1);
            $variadic = $isVarargs((string) $omit[0]);
            if ($variadic === true) {
                return ['status' => 'omit', 'descriptor' => $omit[0], 'pad' => $component];
            }
            if ($variadic === null) {
                return [
                    'status' => 'none',
                    'detail' => 'variadic metadata is unavailable, so the omitted parameter cannot be proven variadic',
                ];
            }

            return ['status' => 'none'];
        }
        $component = $pack[0]['component'];
        $compat = jvmVarargsElementCompat($moggiType, $component);
        if ($compat !== null) {
            return ['status' => 'none', 'detail' => $compat];
        }

        return ['status' => 'pack', 'descriptor' => $pack[0]['descriptor'], 'pad' => $component];
    }

    if ($declEndsList) {
        return ['status' => 'none', 'detail' => 'a trailing Moggi list needs a host array parameter to pack into'];
    }

    return ['status' => 'none'];
}

/**
 * Whether the host's leading parameters accept the declaration's: equal, or a
 * declared reference (object or array) in a host `Object` slot — the Java
 * widening rule, which needs no check and emits no cast. Primitives never
 * widen: a boxed Moggi number in an `Object` slot would need a box the
 * declaration did not ask for.
 *
 * @param list<string> $hostParams
 * @param list<string> $wantParams
 */
function jvmOmitPrefixMatches(array $hostParams, array $wantParams): bool
{
    foreach ($wantParams as $i => $want) {
        $host = $hostParams[$i] ?? null;
        if ($host === $want) {
            continue;
        }
        if ($host === 'Ljava/lang/Object;'
            && (\str_starts_with($want, 'L') || \str_starts_with($want, '['))
        ) {
            continue;
        }

        return false;
    }

    return true;
}

/**
 * Whether a Moggi list element may pack into a host array component.
 *
 * Reference components take an equal element type, or anything for `Object`
 * (the host checks); primitive components take the Moggi numbers that box
 * compatibly. Null (no detail) means compatible.
 */
function jvmVarargsElementCompat(?Ast\TypeNode $moggiType, string $component): ?string
{
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
        $elementDesc = jvmTypeDescriptorFromMoggiType($element, null, false);
    } catch (\Throwable $e) {
        return 'cannot map the supplied list element type: ' . $e->getMessage();
    }
    if (\str_starts_with($component, 'L') || $component === 'Ljava/lang/Object;') {
        if ($component === 'Ljava/lang/Object;' || $elementDesc === $component) {
            return null;
        }
        if (\str_starts_with($elementDesc, 'L')) {
            return "list elements are `{$elementDesc}` but the host takes `{$component}`";
        }

        return "list elements are `{$elementDesc}` but the host takes `{$component}`";
    }
    $numeric = ['J' => ['J', 'I', 'S', 'B'], 'I' => ['J', 'I', 'S', 'B']];
    if (isset($numeric[$elementDesc]) && \in_array($component, $numeric[$elementDesc], true)) {
        return null;
    }
    if (($elementDesc === 'D' && $component === 'D')
        || ($elementDesc === 'Z' && $component === 'Z')
        || ($elementDesc === 'I' && $component === 'C')
    ) {
        return null;
    }

    return "list elements are `{$elementDesc}` but the host takes `{$component}`";
}

/**
 * Per-arg descriptor override for specific constructors/methods.
 *
 * @param 'static'|'instance'|'constructor' $dispatch
 * @param int $argIndex 0-based into the *Moggi* argument list
 */
function jvmArgDescriptorOverride(
    ?string $classPath,
    ?string $member,
    string $dispatch,
    bool $isReturn,
    int $argIndex = 0,
): ?string {
    if (
        $classPath === 'java.io.Console'
        && $member === 'reader'
        && $isReturn
        && $dispatch === 'instance'
    ) {
        return 'Ljava/io/BufferedReader;';
    }
    if (
        $classPath === 'java.lang.System'
        && $member === 'console'
        && $isReturn
        && $dispatch === 'static'
    ) {
        return 'Ljava/io/Console;';
    }
    if (
        $classPath === 'java.nio.charset.StandardCharsets'
        && $member === 'UTF_8'
        && $isReturn
        && $dispatch === 'static'
    ) {
        return 'Ljava/nio/charset/Charset;';
    }
    if (
        $classPath === 'java.io.Reader'
        && $member === 'transferTo'
        && ! $isReturn
        && $dispatch === 'instance'
        && $argIndex === 0
    ) {
        return 'Ljava/io/Writer;';
    }

    if ($dispatch !== 'constructor') {
        return null;
    }

    $ctorKey = ($classPath ?? '') . ':__con';
    $overridden = [
        'java.io.InputStreamReader:__con' => [
            0 => 'Ljava/io/InputStream;',
            1 => 'Ljava/nio/charset/Charset;',
        ],
        'java.io.BufferedReader:__con' => [
            0 => 'Ljava/io/Reader;',
        ],
        'java.io.PrintStream:__con' => [
            0 => 'Ljava/io/OutputStream;',
            2 => 'Ljava/nio/charset/Charset;',
        ],
    ];

    if (! isset($overridden[$ctorKey])) {
        return null;
    }

    return $overridden[$ctorKey][$argIndex] ?? null;
}
