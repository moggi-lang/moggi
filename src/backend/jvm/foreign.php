<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Foreign;

use Moggi\Syntax\Ast;

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

use function Moggi\Foreign\splitForeignPathMember;
use function Moggi\Semantics\IoBoundary\isIoTypeConName;

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
    $trimmed = trim($hostType);
    if (str_starts_with($trimmed, 'interface:')) {
        return [trim(substr($trimmed, strlen('interface:'))), true];
    }
    if (str_starts_with($trimmed, 'interface ')) {
        return [trim(substr($trimmed, strlen('interface '))), true];
    }

    return [$trimmed, false];
}

function jvmForeignHostInternal(string $hostType): string
{
    [$dotted, ] = jvmParseForeignHostType($hostType);

    return dottedToInternal($dotted);
}

/** @return array{dispatch: 'static'|'instance'|'constructor'|'intrinsic', class: string, member: string} */
function resolveJvmForeignPath(string $path): array
{
    if (!str_contains($path, ':') && !str_contains($path, '.')) {
        throw new \RuntimeException(
            "JVM foreign path `{$path}` must be Class:member or Class.member; bare host names are not supported",
        );
    }

    [$classPath, $member] = splitForeignPathMember($path);

    if (str_contains($path, ':')) {
        $dispatch = match ($member) {
            '__con' => 'constructor',
            // A cast is not a member of the host class: it is a checkcast the
            // emitter writes, exactly as .NET treats __cast/__default/__null.
            '__cast' => 'intrinsic',
            default => 'static',
        };

        return [
            'dispatch' => $dispatch,
            'class' => dottedToInternal($classPath),
            'member' => $member === '__con' ? '<init>' : $member,
        ];
    }

    return [
        'dispatch' => 'instance',
        'class' => dottedToInternal($classPath),
        'member' => $member,
    ];
}

function dottedToInternal(string $dotted): string
{
    return \str_replace('.', '/', $dotted);
}

/**
 * JDK / Moggi interface types that require InterfaceMethodref + invokeinterface.
 * (checkcast to an interface is fine; invokevirtual is not.)
 *
 * Prefer declaring {@code foreign jvm type Foo "interface java.util.List"} for
 * interfaces; the scan below honors every such declaration. An entry here is
 * therefore only needed for paths the stdlib reaches without one —
 * `java.util.List`/`Map`, whose Moggi types are builtins with nothing to attach
 * the prefix to, plus Iterable/Path/Closeable, which were bound before the
 * prefix existed — and for a handful of well-known JDK interfaces kept for
 * convenience when hand-writing user `foreign` imports.
 */
function jvmIsInterface(string $internalName): bool
{
    static $ifaces = [
        'java/util/List' => true,
        'java/util/Map' => true,
        'java/util/Map$Entry' => true,
        'java/lang/Iterable' => true,
        'java/lang/Comparable' => true,
        'java/lang/CharSequence' => true,
        'java/nio/file/Path' => true,
        'java/nio/file/DirectoryStream' => true,
        'java/util/concurrent/ConcurrentMap' => true,
        'moggi/rt/Fn' => true,
        // Handle-level close() dispatches via interface.
        'java/io/Closeable' => true,
    ];

    if (isset($ifaces[$internalName])) {
        return true;
    }

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

    // Instance methods take an explicit receiver as the first Moggi argument.
    // Constructors do not — every arrow argument is a <init> parameter; the
    // constructed object is the Moggi result (descriptor returns void).
    if ($dispatch === 'instance') {
        if ($argTypes === []) {
            throw new \RuntimeException('JVM instance foreign requires a receiver argument');
        }
        array_shift($argTypes);
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
    // JDK static fields whose binary type differs from the Moggi Int mapping.
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

    // Maybe a / Either e a are ordinary Moggi ADTs. Host APIs return the value
    // payload (nullable); emit applies IoWrap to build Just/Nothing or Left/Right.
    if ($type instanceof Ast\TypeApp && $type->con instanceof Ast\TypeCon) {
        $container = $type->con->name;
        if ($container === 'Maybe') {
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
        if ($container === 'Either') {
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

    // Objects.* APIs erase reference args (and requireNonNull's return) to Object.
    if ($classPath === 'java.util.Objects') {
        if ($member === 'isNull' || $member === 'nonNull') {
            if (!$isReturn) {
                return 'Ljava/lang/Object;';
            }
        }
        if ($member === 'requireNonNull') {
            return 'Ljava/lang/Object;';
        }
    }

    // writeString(Path, CharSequence[, OpenOption...]) — String is a CharSequence.
    if (!$isReturn && $classPath === 'java.nio.file.Files' && $member === 'writeString' && $con === 'String') {
        return 'Ljava/lang/CharSequence;';
    }

    // Handle-level overrides for specific constructors/methods (arg indices
    // are 0-based into the *Moggi* argument list; for instance dispatch the
    // receiver is already shifted out, so index 0 = first caller arg).
    $argOverride = jvmArgDescriptorOverride($classPath, $member, $dispatch, $isReturn, $argIndex);
    if ($argOverride !== null) {
        return $argOverride;
    }

    return match ($con) {
        // Platform word Int is i64 (J). Fixed-width Ints map to exact JVM
        // primitives; Char is a Unicode codepoint (JVM char APIs take int).
        'Int' => 'J',
        'Int8' => 'B',
        'Int16' => 'S',
        'Int32' => 'I',
        'Int64' => 'J',
        'Char' => 'I',
        'Integer' => 'Ljava/math/BigInteger;',
        'Bool' => 'Z',
        'Double' => 'D',
        'String', 'ByteString' => 'Ljava/lang/String;',
        'Handle' => jvmOpaqueObjectDescriptor($classPath, $isReturn, $dispatch),
        'List' => jvmListDescriptor($classPath, $member),
        default => jvmForeignTypeDescriptorOrFail($con),
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
    // Only known JDK APIs that return Stream; do not map every java.* List.
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
    // java.io.Console.reader — instance method returning BufferedReader.
    if (
        $classPath === 'java.io.Console'
        && $member === 'reader'
        && $isReturn
        && $dispatch === 'instance'
    ) {
        return 'Ljava/io/BufferedReader;';
    }
    // java.lang.System:console — static field returning Console.
    if (
        $classPath === 'java.lang.System'
        && $member === 'console'
        && $isReturn
        && $dispatch === 'static'
    ) {
        return 'Ljava/io/Console;';
    }
    // java.nio.charset.StandardCharsets:UTF_8 — static field Charset.
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
        && !$isReturn
        && $dispatch === 'instance'
        && $argIndex === 0
    ) {
        return 'Ljava/io/Writer;';
    }

    // Constructor argument narrowing:
    if ($dispatch !== 'constructor') {
        return null;
    }

    $ctorKey = ($classPath ?? '') . ':__con';
    $overridden = [
        // InputStreamReader(InputStream, Charset) — both args are narrower than Object.
        'java.io.InputStreamReader:__con' => [
            0 => 'Ljava/io/InputStream;',
            1 => 'Ljava/nio/charset/Charset;',
        ],
        // BufferedReader(Reader) — narrower than Object.
        'java.io.BufferedReader:__con' => [
            0 => 'Ljava/io/Reader;',
        ],
        // PrintStream(OutputStream, boolean, Charset) — narrowed.
        'java.io.PrintStream:__con' => [
            0 => 'Ljava/io/OutputStream;',
            2 => 'Ljava/nio/charset/Charset;',
        ],
    ];

    if (!isset($overridden[$ctorKey])) {
        return null;
    }

    return $overridden[$ctorKey][$argIndex] ?? null;
}
