<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Verify;

use Moggi\Syntax\Ast;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;

use function Moggi\Backend\Php\Dependencies\composerVendorMaps;
use function Moggi\Backend\Php\Dependencies\composerVendorRoots;
use function Moggi\Backend\Php\Dependencies\vendorPsrClassFile;
use function Moggi\Foreign\phpClassFqn;

/**
 * Verify a `foreign php` declaration against the PHP the compiler is running on.
 *
 * A `foreign import` is an axiom — the compiler emits the call it is told to emit — so a mistyped
 * host name or a wrong arity is a runtime fatal (`Error: Call to undefined function strlen2()`,
 * `ArgumentCountError`) rather than a diagnostic. PHP is the one backend where the host *is* the
 * compiler's own process, so the declaration can be checked here and now against
 * `function_exists`/`Reflection`, with nothing to guess. The check is exact for the PHP the emitted
 * program runs under: a distribution compiles with the same bundled runtime that runs the artifact.
 *
 * What is checked: that the host member exists, that it is the kind of member the path claims
 * (`Class:member` needs a static method, `Class.member` an instance method, `Class:__con` an
 * instantiable class), and that the declared arity can be satisfied.
 *
 * What is deliberately **not** checked:
 *
 * - Types. `Int`/`Int32`/`Char`/`ByteString`/`Integer` all have PHP representations, so comparing a
 *   declared type against a host `string`/`int` would report differences the emitted code does not
 *   have.
 * - A `foreign php type` declaration. On PHP its path is a label that never reaches the emitted code
 *   (`libraries/base/src/Platform/PHP.mog` names one `"mixed"`, `libraries/base/src/System/IO/PHP.mog` one `"array"`), so there is
 *   nothing to compare it with.
 *
 * When the compile carries a third-party host tree (a Composer `vendor/` root), a symbol the
 * compiler process has not loaded is looked up in that tree's own maps instead of being waved
 * through: the classmap, the files-declared symbols, and the PSR-0/PSR-4 prefixes whose computed
 * path exists — the same answer the artifact's `require vendor/autoload.php` would give. A name no
 * carried tree provides is reported, exactly like a name the process lacks. Members of a
 * vendor-provided class cannot be asked without executing the tree, so existence is the verdict
 * there; members that resolve in-process are still verified — arity and static-ness exactly.
 *
 * @param 'function'|'const' $kind
 * @param array{dispatch?: string, classPath?: ?string, hostMember?: ?string, path?: string} $pathInfo
 */
function verifyForeignImportAgainstHost(string $kind, array $pathInfo, ?Ast\TypeNode $type, string $name): ?string
{
    $dispatch = (string) ($pathInfo['dispatch'] ?? 'global');

    if ($kind === 'const') {
        return verifyForeignConst($pathInfo);
    }

    if ($dispatch === 'global') {
        return verifyForeignGlobalFunction((string) ($pathInfo['path'] ?? ''), $type);
    }

    $classPath = (string) ($pathInfo['classPath'] ?? '');
    if ($classPath === '') {
        return null;
    }

    $className = phpClassFqn($classPath);
    $class = phpClassReflection($className);

    if ($class === null) {
        return vendorProvidesClass($className) ? null : "php has no class or interface `{$className}`";
    }

    $member = (string) ($pathInfo['hostMember'] ?? '');

    return match ($dispatch) {
        'constructor' => verifyForeignConstructor($class, $className, $type),
        'static' => verifyForeignStaticMethod($class, $className, $member, $type),
        'instance' => verifyForeignInstanceMethod($class, $className, $member, $type),
        default => null,
    };
}

/**
 * Whether any carried Composer tree provides a class, interface or enum the process has not loaded.
 *
 * The classmap and the files-declared symbols answer by name; the PSR prefixes answer by computing
 * the path and checking it exists, since a name matching no prefix is still an error even with
 * `optimize-autoloader` off — the runtime would fail to load it too.
 */
function vendorProvidesClass(string $className): bool
{
    $roots = composerVendorRoots();
    if ($roots === []) {
        return false;
    }
    $wanted = \strtolower(\ltrim($className, '\\'));
    foreach ($roots as $root) {
        $maps = composerVendorMaps($root);
        if (isset($maps['classes'][$wanted])) {
            return true;
        }
        if (vendorPsrClassFile($maps['psr4'], $className, false) !== null
            || vendorPsrClassFile($maps['psr0'], $className, true) !== null) {
            return true;
        }
    }

    return false;
}

/**
 * Whether any carried Composer tree declares a global function the process has not loaded.
 *
 * `autoload_files.php` maps hashes to files rather than naming functions, so the files it lists
 * are parsed for their top-level declarations — statically, never required.
 */
function vendorProvidesFunction(string $function): bool
{
    $roots = composerVendorRoots();
    if ($roots === []) {
        return false;
    }
    $wanted = \strtolower(\ltrim($function, '\\'));
    foreach ($roots as $root) {
        if (isset(composerVendorMaps($root)['functions'][$wanted])) {
            return true;
        }
    }

    return false;
}

/** @param array{dispatch?: string, classPath?: ?string, hostMember?: ?string} $pathInfo */
function verifyForeignConst(array $pathInfo): ?string
{
    if (($pathInfo['dispatch'] ?? '') !== 'static') {
        return null;
    }

    $member = (string) ($pathInfo['hostMember'] ?? '');
    $classPath = (string) ($pathInfo['classPath'] ?? '');

    if ($classPath === 'PHP') {
        return \defined($member) ? null : "php has no global constant `{$member}`";
    }

    $className = phpClassFqn($classPath);
    if (phpClassReflection($className) === null) {
        return vendorProvidesClass($className) ? null : "php has no class or interface `{$className}`";
    }

    return \defined($className . '::' . $member)
        ? null
        : "php class `{$className}` has no constant `{$member}`";
}

function verifyForeignGlobalFunction(string $function, ?Ast\TypeNode $type): ?string
{
    if ($function === '') {
        return null;
    }

    if (!\function_exists($function)) {
        return vendorProvidesFunction($function) ? null : "php has no function `{$function}`";
    }

    return foreignArityProblem(
        'php function',
        $function,
        foreignArity($type, 'global'),
        new ReflectionFunction($function),
    );
}

function verifyForeignStaticMethod(
    ReflectionClass $class,
    string $className,
    string $member,
    ?Ast\TypeNode $type,
): ?string {
    if ($member === '') {
        return null;
    }

    if (!$class->hasMethod($member)) {
        return $class->hasMethod('__callStatic') ? null : "php class `{$className}` has no method `{$member}`";
    }

    $method = $class->getMethod($member);
    if (!$method->isStatic()) {
        return "php method `{$className}::{$member}` is not static; use `{$className}.{$member}`"
            . ' and pass the receiver first';
    }

    return foreignArityProblem(
        'php method',
        "{$className}::{$member}",
        foreignArity($type, 'static'),
        $method,
    );
}

function verifyForeignInstanceMethod(
    ReflectionClass $class,
    string $className,
    string $member,
    ?Ast\TypeNode $type,
): ?string {
    if ($member === '') {
        return null;
    }

    if (!$class->hasMethod($member)) {
        return $class->hasMethod('__call') ? null : "php class `{$className}` has no method `{$member}`";
    }

    return foreignArityProblem(
        'php method',
        "{$className}.{$member}",
        foreignArity($type, 'instance'),
        $class->getMethod($member),
    );
}

function verifyForeignConstructor(ReflectionClass $class, string $className, ?Ast\TypeNode $type): ?string
{
    if (!$class->isInstantiable()) {
        return "php class `{$className}` cannot be instantiated";
    }

    if (!$class->hasMethod('__construct')) {
        return null;
    }

    return foreignArityProblem(
        'php constructor',
        "{$className}::__construct",
        foreignArity($type, 'constructor'),
        $class->getMethod('__construct'),
    );
}

/**
 * The number of host arguments a foreign signature declares: one per arrow, less the receiver for an
 * instance call (the receiver is the host object, not an argument).
 */
function foreignArity(?Ast\TypeNode $type, string $dispatch): int
{
    $count = 0;
    $cursor = $type;
    while ($cursor instanceof Ast\TypeArrow) {
        ++$count;
        $cursor = $cursor->to;
    }

    return $dispatch === 'instance' ? \max(0, $count - 1) : $count;
}

function foreignArityProblem(
    string $subject,
    string $label,
    int $declared,
    ReflectionFunctionAbstract $callable,
): ?string {
    $required = $callable->getNumberOfRequiredParameters();
    $total = $callable->getNumberOfParameters();

    if ($declared < $required) {
        return "{$subject} `{$label}` needs " . argumentCount($required)
            . ', but this foreign import declares ' . argumentCount($declared);
    }

    if (!$callable->isVariadic() && $declared > $total) {
        return "{$subject} `{$label}` takes at most " . argumentCount($total)
            . ', but this foreign import declares ' . argumentCount($declared);
    }

    return null;
}

function argumentCount(int $count): string
{
    return $count === 1 ? '1 argument' : "{$count} arguments";
}

/** The host class, interface or enum `$className` names, or null when the host has no such name. */
function phpClassReflection(string $className): ?ReflectionClass
{
    if (\class_exists($className) || \interface_exists($className) || (\function_exists('enum_exists') && \enum_exists($className))) {
        return new ReflectionClass($className);
    }

    return null;
}
