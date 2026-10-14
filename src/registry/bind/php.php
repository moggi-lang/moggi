<?php declare(strict_types=1);

namespace Moggi\Registry\Bind;

use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionType;

use function Moggi\Backend\Php\Dependencies\phpDeclaredSymbols;
use function Moggi\Cache\runtimeCacheDir;

/**
 * The PHP side of `moggi bind`: read a Composer-style tree on disk and describe
 * what it can become as Moggi declarations.
 *
 * The tree is required into this process (reflection needs the code loaded), so
 * Phase 1 only binds trees the user already trusts — the same trust `moggi run`
 * places in `--lib` sources. Binding an untrusted tree in an isolated inspector
 * process is a later phase; the JSON below is already the boundary it would
 * speak across.
 *
 * Everything out of this file is deterministic: files are scanned in sorted
 * order, symbols are inspected in sorted order, and the descriptor records a
 * `skipped` entry (with a reason) for every symbol the writer cannot emit, so a
 * review can tell a deliberate omission from a missed one.
 */

/** Scalar host types Phase 1 maps, and the Moggi type each becomes. */
const PHP_BIND_SCALARS = [
    'string' => 'String',
    'int' => 'Int',
    'bool' => 'Bool',
    'float' => 'Double',
];

/**
 * Split a `vendor/package` coordinate the way `moggi add` spells a Composer
 * requirement.
 *
 * @return array{vendor: string, package: string}
 */
function parsePhpCoordinate(string $coordinate): array
{
    $parts = explode('/', $coordinate, 2);
    if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        throw new \RuntimeException(
            "cannot bind `{$coordinate}` — a Composer coordinate is `vendor/package`",
        );
    }

    return ['vendor' => $parts[0], 'package' => $parts[1]];
}

/** One coordinate segment as a module-path segment: `foo-bar_baz` is `FooBarBaz`. */
function phpSegmentToModule(string $segment): string
{
    return bindSegmentToModule($segment);
}

/**
 * The module a bound library lives in: `acme/hello` is `Composer.Acme.Hello`.
 * The ecosystem prefix keeps three backends' bindings for "the same" library
 * apart (R7), and one segment per component keeps the module/file law resolving
 * generated modules with no new lookup rule.
 */
function phpBindModuleName(string $vendor, string $package): string
{
    $segments = ['Composer', phpSegmentToModule($vendor)];
    foreach (explode('/', $package) as $part) {
        $segments[] = phpSegmentToModule($part);
    }

    return \implode('.', $segments);
}

/** The namespace prefix only this coordinate's symbols live under: `Acme\Hello`. */
function phpNamespacePrefix(string $vendor, string $package): string
{
    $prefix = phpSegmentToModule($vendor);
    foreach (explode('/', $package) as $part) {
        $prefix .= '\\' . phpSegmentToModule($part);
    }

    return $prefix;
}

/**
 * Where the generated binding is cached: under the runtime tree beside the
 * fetched host trees, with the backend's own emit discriminator folded in, so
 * a build carrying a host tree never reuses a binding emitted without one
 * (and back).
 *
 * The .NET arm counts hand-written sidecars only, never generated ones:
 * a generated sidecar is derived from the tree and the generator the bind
 * marker versions, so keying the root on it would move the root every
 * generation and the marker would never hit (see `dotNetSidecarDiscriminator`).
 */
function bindCacheKey(string $backend, string $coordinate): string
{
    $discriminator = match ($backend) {
        'jvm' => (new \Moggi\Backend\Jvm\JvmBackend())->emitCacheDiscriminator(),
        'dotnet' => \Moggi\Backend\DotNet\Dependencies\dotNetSidecarDiscriminator(false),
        default => (new \Moggi\Backend\Php\PhpBackend())->emitCacheDiscriminator(),
    };
    $slug = \strtolower((string) \preg_replace('/[^a-z0-9]+/i', '-', $coordinate));
    $slug = \trim($slug, '-');

    return runtimeCacheDir() . '/' . $backend . '/bind-' . $slug . '-' . ($discriminator !== '' ? $discriminator : 'plain');
}

/**
 * Describe the bindable symbols of a tree: `*.php` files under it except
 * `vendor/` — plus the bound package's own `vendor/<vendor>/<package>/`
 * subdir, which is where a resolved tree keeps installed sources — required in
 * sorted order, keeping the classes and functions that appear and live under
 * the coordinate's namespace.
 *
 * @return array{backend: string, coordinate: string, module: string, types: list<array<string, mixed>>, functions: list<array<string, mixed>>, consts: list<array<string, mixed>>, skipped: list<array{symbol: string, reason: string}>}
 */
function inspectPhpTree(string $treeRoot, string $vendor, string $package): array
{
    $root = \rtrim($treeRoot, '/');
    if (!\is_dir($root)) {
        throw new \RuntimeException("cannot bind `{$treeRoot}` — no such directory");
    }
    $prefix = phpNamespacePrefix($vendor, $package);

    $scanRoots = [$root];
    foreach (["{$vendor}/{$package}", \strtolower("{$vendor}/{$package}")] as $packageDir) {
        $candidate = $root . '/vendor/' . $packageDir;
        if (\is_dir($candidate)) {
            $scanRoots[] = $candidate;
        }
    }
    $files = [];
    foreach ($scanRoots as $scanRoot) {
        $scan = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scanRoot, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($scan as $file) {
            if (!$file->isFile() || \strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            if ($scanRoot === $root) {
                $relative = \str_replace('\\', '/', (string) \substr($file->getPathname(), \strlen($root) + 1));
                if ($relative === 'vendor' || \str_starts_with($relative, 'vendor/')) {
                    continue;
                }
            }
            $files[] = $file->getPathname();
        }
    }
    $files = \array_values(\array_unique($files));
    \sort($files);

    $wanted = [];
    foreach ($files as $file) {
        $wanted[\realpath($file) ?: $file] = true;
    }
    foreach ($files as $file) {
        require_once $file;
    }
    $newClasses = [];
    foreach (\get_declared_classes() as $class) {
        $defining = (new \ReflectionClass($class))->getFileName();
        if ($defining === false) {
            continue;
        }
        if (isset($wanted[\realpath($defining) ?: $defining])) {
            $newClasses[] = $class;
        }
    }
    $newFunctions = [];
    foreach (\get_defined_functions()['user'] as $function) {
        $defining = (new \ReflectionFunction($function))->getFileName();
        if ($defining === false) {
            continue;
        }
        if (isset($wanted[\realpath($defining) ?: $defining])) {
            $newFunctions[] = $function;
        }
    }
    \sort($newClasses);
    \sort($newFunctions);

    $module = phpBindModuleName($vendor, $package);
    $used = [];
    $types = [];
    $functions = [];
    $consts = [];
    $skipped = [];
    $skip = static function (string $symbol, string $reason) use (&$skipped): void {
        $skipped[] = ['symbol' => $symbol, 'reason' => $reason];
    };
    $claim = static function (string $name) use (&$used): bool {
        if (isset($used[$name])) {
            return false;
        }
        $used[$name] = true;

        return true;
    };

    foreach ($newClasses as $class) {
        if (!\str_starts_with($class, $prefix . '\\') && $class !== $prefix) {
            $skip($class, 'outside namespace ' . $prefix);

            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isInternal()) {
            $skip($class, 'internal to PHP itself');

            continue;
        }
        $short = $reflection->getShortName();
        if (!$claim($short)) {
            $skip($class, "name collision on `{$short}`");

            continue;
        }
        $types[] = [
            'moggi' => $short,
            'fqn' => $class,
            'path' => \str_replace('\\', '.', $class),
        ];
        foreach ($reflection->getMethods() as $method) {
            inspectPhpMethod($reflection, $method, $skip, $claim, $functions);
        }
        foreach ($reflection->getConstants() as $name => $value) {
            inspectPhpClassConst($reflection, (string) $name, $value, $skip, $claim, $consts);
        }
    }

    $canonical = [];
    foreach ($files as $file) {
        foreach (phpDeclaredSymbols($file)['function'] as $name) {
            $canonical[\strtolower($name)] = $name;
        }
    }

    foreach ($newFunctions as $function) {
        if (!\str_starts_with(\strtolower($function), \strtolower($prefix) . '\\')) {
            $skip($function, 'outside namespace ' . $prefix);

            continue;
        }
        inspectPhpFunction(
            new ReflectionFunction($function),
            $canonical[\strtolower($function)] ?? $function,
            $skip,
            $claim,
            $functions,
        );
    }

    $byName = static fn (array $entry): string => (string) $entry['moggi'];
    foreach (['types' => &$types, 'functions' => &$functions, 'consts' => &$consts] as &$list) {
        \usort($list, static fn (array $a, array $b): int => $byName($a) <=> $byName($b));
    }
    unset($list);
    \usort($skipped, static fn (array $a, array $b): int => $a['symbol'] <=> $b['symbol']);

    return [
        'backend' => 'php',
        'coordinate' => $vendor . '/' . $package,
        'module' => $module,
        'types' => $types,
        'functions' => $functions,
        'consts' => $consts,
        'skipped' => $skipped,
    ];
}

/** @param list<array<string, mixed>> $functions */
function inspectPhpMethod(
    ReflectionClass $class,
    \ReflectionMethod $method,
    callable $skip,
    callable $claim,
    array &$functions,
): void {
    $label = $class->getName() . '::' . $method->getName();
    if (!$method->isPublic()) {
        $skip($label, 'not public');

        return;
    }
    if ($method->isConstructor() || $method->isDestructor()) {
        $skip($label, 'constructors need a handle-typed binding');

        return;
    }
    if (!$method->isStatic()) {
        $skip($label, 'instance methods need a handle-typed binding');

        return;
    }
    $moggi = \lcfirst($class->getShortName()) . \ucfirst($method->getName());
    if (!$claim($moggi)) {
        $skip($label, "name collision on `{$moggi}`");

        return;
    }
    $signature = phpCallableSignature($method);
    if ($signature === null) {
        $skip($label, 'unsupported signature (' . phpSignatureReason($method) . ')');

        return;
    }
    $functions[] = [
        'moggi' => $moggi,
        'path' => \str_replace('\\', '.', $class->getName()) . ':' . $method->getName(),
        'dispatch' => 'static',
        'sig' => $signature,
    ];
}

/** @param list<array<string, mixed>> $functions */
function inspectPhpFunction(
    ReflectionFunction $function,
    string $display,
    callable $skip,
    callable $claim,
    array &$functions,
): void {
    $name = $display;
    $short = (string) \substr($name, (int) \strrpos($name, '\\') + 1);
    if (!$claim($short)) {
        $skip($name, "name collision on `{$short}`");

        return;
    }
    $signature = phpCallableSignature($function);
    if ($signature === null) {
        $skip($name, 'unsupported signature (' . phpSignatureReason($function) . ')');

        return;
    }
    $functions[] = [
        'moggi' => $short,
        'path' => $name,
        'dispatch' => 'global',
        'sig' => $signature,
    ];
}

/** @param list<array<string, mixed>> $consts */
function inspectPhpClassConst(
    ReflectionClass $class,
    string $name,
    mixed $value,
    callable $skip,
    callable $claim,
    array &$consts,
): void {
    $label = $class->getName() . '::' . $name;
    $reflection = $class->getReflectionConstant($name);
    if ($reflection === false || !$reflection->isPublic()) {
        $skip($label, 'not public');

        return;
    }
    $type = match (\gettype($value)) {
        'string' => 'String',
        'integer' => 'Int',
        'boolean' => 'Bool',
        'double' => 'Double',
        default => null,
    };
    if ($type === null) {
        $skip($label, 'only scalar constants bind');

        return;
    }
    $moggi = \lcfirst($class->getShortName()) . \ucfirst(\strtolower($name));
    if (!$claim($moggi)) {
        $skip($label, "name collision on `{$moggi}`");

        return;
    }
    $consts[] = [
        'moggi' => $moggi,
        'path' => \str_replace('\\', '.', $class->getName()) . ':' . $name,
        'type' => $type,
    ];
}

/**
 * The Moggi signature for a host callable: every parameter in, `IO` out, and
 * `Either String` around the result — a host call can always throw, and PHP
 * reflection cannot prove otherwise, so the boundary tax (R4) is uniform rather
 * than guessed per callable. Null when the callable needs more than Phase 1 maps.
 */
function phpCallableSignature(ReflectionFunctionAbstract $callable): ?string
{
    $params = [];
    foreach ($callable->getParameters() as $param) {
        if ($param->isPassedByReference() || $param->isVariadic()) {
            return null;
        }
        $type = phpScalarMoggiType($param->getType());
        if ($type === null) {
            return null;
        }
        $params[] = $type;
    }
    $tail = phpMoggiIoTail($callable->getReturnType());
    if ($tail === null) {
        return null;
    }
    $params[] = $tail;

    return \implode(' -> ', $params);
}

/**
 * The IO tail for a host return type: `void` is `IO ()`, a *nullable* scalar
 * (`?T`, `T|null`) is `IO (Maybe …)` — the emitter converts a false/null
 * result to Nothing — and anything else mapped is `IO (Either String …)`,
 * since a host call can always throw. A nested `Either String (Maybe …)`
 * would be a lie: the classifier reads the outer con only, so the Either
 * emitter would never convert the null. Anything unmappable (untyped, wider
 * unions, classes, nullable classes) is still omit + report, and nullable
 * *parameters* stay refused with them: a Maybe argument has no conversion,
 * only a result does.
 */
function phpMoggiIoTail(?ReflectionType $return): ?string
{
    if ($return === null) {
        return null;
    }
    if ($return instanceof ReflectionNamedType && $return->getName() === 'void' && !$return->allowsNull()) {
        return 'IO ()';
    }
    $named = null;
    $nullable = false;
    if ($return instanceof ReflectionNamedType) {
        $named = $return;
        $nullable = $return->allowsNull();
    } elseif ($return instanceof ReflectionUnionType) {
        $named = phpNullableUnionMember($return);
        $nullable = $named !== null;
    }
    if ($named === null || !$named->isBuiltin()) {
        return null;
    }
    $inner = PHP_BIND_SCALARS[$named->getName()] ?? null;
    if ($inner === null) {
        return null;
    }

    return $nullable ? 'IO (Maybe ' . $inner . ')' : 'IO (Either String ' . $inner . ')';
}

/** The non-null member of a `T|null` union, else null. */
function phpNullableUnionMember(ReflectionUnionType $union): ?ReflectionNamedType
{
    if (\count($union->getTypes()) !== 2) {
        return null;
    }
    $named = null;
    foreach ($union->getTypes() as $member) {
        if (!($member instanceof ReflectionNamedType)) {
            return null;
        }
        if ($member->getName() === 'null') {
            continue;
        }
        if ($named !== null) {
            return null;
        }
        $named = $member;
    }

    return $named;
}

/** Why `phpCallableSignature` refused a callable, for the `skipped` record. */
function phpSignatureReason(ReflectionFunctionAbstract $callable): string
{
    foreach ($callable->getParameters() as $param) {
        if ($param->isPassedByReference()) {
            return 'by-reference parameter';
        }
        if ($param->isVariadic()) {
            return 'variadic parameter';
        }
        if (phpScalarMoggiType($param->getType()) === null) {
            return 'parameter type ' . phpTypeSpelling($param->getType());
        }
    }
    $return = $callable->getReturnType();
    if ($return === null) {
        return 'no declared return type';
    }
    if (phpMoggiIoTail($return) !== null) {
        return '';
    }

    return 'return type ' . phpTypeSpelling($return);
}

function phpScalarMoggiType(?ReflectionType $type): ?string
{
    if (!($type instanceof ReflectionNamedType) || $type->allowsNull()) {
        return null;
    }
    if (!$type->isBuiltin()) {
        return null;
    }

    return PHP_BIND_SCALARS[$type->getName()] ?? null;
}

function phpTypeSpelling(?ReflectionType $type): string
{
    if ($type === null) {
        return 'untyped';
    }

    return (string) $type;
}
