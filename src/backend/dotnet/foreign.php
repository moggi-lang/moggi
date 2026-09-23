<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Foreign;

use Moggi\Syntax\Ast;

use function Moggi\Backend\DotNet\Dependencies\dotNetTypeIsValueType;
use function Moggi\Backend\DotNet\Naming\bclAssemblyFor;
use function Moggi\Foreign\splitForeignPathMember;
use function Moggi\Semantics\IoBoundary\isIoTypeConName;

function registerDotNetForeignType(string $moggiName, string $hostType): void
{
    $GLOBALS['moggi_dotnet_foreign_types'][$moggiName] = $hostType;
}

function lookupDotNetForeignType(string $name): ?string
{
    return $GLOBALS['moggi_dotnet_foreign_types'][$name] ?? null;
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
    if (!str_contains($path, ':') && !str_contains($path, '.')) {
        throw new \RuntimeException(
            "dotnet foreign path `{$path}` must be Type:member or Type.member; bare host names are not supported",
        );
    }

    [$classPath, $member] = splitForeignPathMember($path);

    if (str_contains($path, ':')) {
        $dispatch = match ($member) {
            '__con' => 'constructor',
            '__cast', '__default', '__null' => 'intrinsic',
            default => 'static',
        };

        return [
            'dispatch' => $dispatch,
            'class' => $classPath,
            'member' => $member === '__con' ? '.ctor' : $member,
        ];
    }

    return [
        'dispatch' => 'instance',
        'class' => $classPath,
        'member' => $member,
    ];
}

/**
 * Infer a CLR method/field signature string for emit (ILASM-oriented).
 *
 * Format examples:
 *   method: "(int64,string)object" or "()void"
 *   field:  "string" / "object"
 *
 * @param 'static'|'instance'|'constructor'|'intrinsic' $dispatch
 * @param 'function'|'const' $kind
 */
function clrSigFromMoggiType(
    Ast\TypeNode $type,
    string $dispatch,
    string $kind,
    ?string $classPath,
    ?string $member = null,
    ?array $explicitParams = null,
): string {
    if ($kind === 'const') {
        return clrFieldTypeName(clrForeignIoInnerType($type), $classPath, $member);
    }

    if ($dispatch === 'intrinsic') {
        return clrIntrinsicSig($type, $classPath, $member);
    }

    $argTypes = [];
    $cursor = $type;
    while ($cursor instanceof Ast\TypeArrow) {
        $argTypes[] = $cursor->from;
        $cursor = $cursor->to;
    }
    $result = clrForeignIoInnerType($type);

    // Instance methods take an explicit receiver as the first Moggi argument.
    // Constructors do not — every arrow argument is a .ctor parameter.
    if ($dispatch === 'instance') {
        if ($argTypes === []) {
            throw new \RuntimeException('dotnet instance foreign requires a receiver argument');
        }
        array_shift($argTypes);
    }

    $params = [];
    foreach ($argTypes as $arg) {
        $params[] = clrTypeName($arg, $classPath, $member, false, $dispatch);
    }

    // A library-declared host signature overrides the derived parameter list.
    // This is how libs express APIs whose real CLR signature differs from the
    // Moggi type (C# optional parameters, interface-typed parameters, ...).
    if ($explicitParams !== null) {
        $params = $explicitParams;
    }

    if ($dispatch === 'constructor') {
        return '(' . \implode(',', $params) . ')';
    }

    $ret = $result instanceof Ast\TypeUnit
        ? 'void'
        : clrTypeName($result, $classPath, $member, true, $dispatch);

    return '(' . \implode(',', $params) . ')' . $ret;
}

/**
 * Signatures for emit-only intrinsics (__cast / __default / __null).
 */
function clrIntrinsicSig(Ast\TypeNode $type, ?string $classPath, ?string $member): string
{
    $result = clrForeignIoInnerType($type);
    $ret = $result instanceof Ast\TypeUnit
        ? 'void'
        : clrTypeName($result, $classPath, $member, true, 'intrinsic');

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
        $param = clrTypeName($argTypes[0], $classPath, $member, false, 'intrinsic');

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
function clrFieldTypeName(Ast\TypeNode $type, ?string $classPath, ?string $member): string
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

    return clrTypeName($type, $classPath, $member, true, 'static');
}

/**
 * True BCL "static readonly value" surfaces are often auto-properties, not
 * fields (e.g. `Console.Out` is a `get_Out()` getter). `const`-kind foreign
 * imports listed here are emitted as a getter `call` instead of `ldsfld`.
 */
function isDotNetConstProperty(?string $classPath, ?string $member): bool
{
    static $known = [
        'System.Console:Out' => true,
        'System.Console:Error' => true,
        'System.Console:In' => true,
    ];

    return isset($known[($classPath ?? '') . ':' . ($member ?? '')]);
}

function clrTypeName(
    Ast\TypeNode $type,
    ?string $classPath,
    ?string $member,
    bool $isReturn,
    string $dispatch = 'static',
): string {
    if ($type instanceof Ast\TypeUnit) {
        return 'void';
    }

    // Maybe a / Either e a are ordinary Moggi ADTs. Host APIs return the value
    // payload (nullable); emit applies IoWrap to build Just/Nothing or Left/Right.
    // Never look them up as foreign types (fail-closed object fallback).
    if ($type instanceof Ast\TypeApp && $type->con instanceof Ast\TypeCon) {
        $container = $type->con->name;
        if ($container === 'Maybe') {
            $inner = $type->args[0] ?? null;
            if ($inner instanceof Ast\AstNode) {
                return clrTypeName($inner, $classPath, $member, $isReturn, $dispatch);
            }
        }
        if ($container === 'Either') {
            $inner = $type->args[1] ?? $type->args[0] ?? null;
            if ($inner instanceof Ast\AstNode) {
                return clrTypeName($inner, $classPath, $member, $isReturn, $dispatch);
            }
        }
    }

    $con = clrForeignTypeConName($type);

    // Object.ReferenceEquals(object, object) — never narrow to a more specific
    // class type; null must stay a plain object reference.
    if ($classPath === 'System.Object' && $member === 'ReferenceEquals' && !$isReturn) {
        return 'object';
    }

    // Convert.ToString(object) — do not narrow the arg to a declared host type.
    if ($classPath === 'System.Convert' && $member === 'ToString' && !$isReturn) {
        return 'object';
    }

    return match ($con) {
        // Platform word Int is i64. Fixed-width Ints map to exact CLR
        // primitives; Char maps to the UTF-16 unit System.Char APIs take.
        'Int' => 'int64',
        'Int8' => 'int8',
        'Int16' => 'int16',
        'Int32' => 'int32',
        'Int64' => 'int64',
        'Char' => 'char',
        'Integer' => 'valuetype [System.Runtime.Numerics]System.Numerics.BigInteger',
        'Bool' => 'bool',
        'Double' => 'float64',
        'String', 'ByteString' => 'string',
        'Handle' => clrOpaqueObjectMapping($classPath, $isReturn, $dispatch),
        'List' => 'class Moggi.Rt.MList',
        default => clrForeignLeafToIl((string) $con),
    };
}

/**
 * Map a foreign nominal leaf to its IL type descriptor. Fail closed — never
 * silently fall back to `object` for an unresolved foreign type.
 */
function clrForeignLeafToIl(string $con): string
{
    $host = lookupDotNetForeignType($con);
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
        // Allow fully-qualified IL snippets (e.g. Nullable`1<…> with assembly refs).
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

    // Plain CLR type name: value-type-ness is a host ABI fact a library may
    // declare alongside the type; otherwise assume a reference type.
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
 * declared one in library metadata (`lib/<module>/dotnet/*.json`).
 */
function dotNetHostIsValueType(string $classPath): bool
{
    // Library-declared CLR types carry an explicit value-type flag.
    $declared = dotNetTypeIsValueType($classPath);
    if ($declared !== null) {
        return $declared;
    }

    foreach ($GLOBALS['moggi_dotnet_foreign_types'] ?? [] as $host) {
        $host = \trim((string) $host);
        if (!\str_starts_with($host, 'valuetype ')) {
            continue;
        }
        $rest = \trim(\substr($host, \strlen('valuetype ')));
        // Strip optional [Assembly] qualifier for comparison.
        if (\str_starts_with($rest, '[')) {
            $close = \strpos($rest, ']');
            if ($close !== false) {
                $rest = \substr($rest, $close + 1);
            }
        }
        if ($rest === $classPath || \str_ends_with($rest, '/' . $classPath)) {
            return true;
        }
        // Nested types are spelled `Outer/Inner`; `+` is the C# spelling.
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
