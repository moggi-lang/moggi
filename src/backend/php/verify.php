<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Verify;

use function Moggi\Backend\Php\Dependencies\composerVendorMaps;
use function Moggi\Backend\Php\Dependencies\composerVendorRoots;
use function Moggi\Backend\Php\Dependencies\vendorPsrClassFile;
use function Moggi\Backend\Php\Host\phpHost;
use function Moggi\Foreign\phpClassFqn;

use Moggi\Backend\Php\Host\PhpHost;
use Moggi\Syntax\Ast;

/**
 * Verify a `foreign php` declaration against the PHP that will run the program.
 *
 * A `foreign import` is an axiom — the compiler emits the call it is told to emit — so a mistyped
 * host name or a wrong arity is a runtime fatal (`Error: Call to undefined function strlen2()`,
 * `ArgumentCountError`) rather than a diagnostic. PHP can be asked before the program runs, but
 * only by the process that would run it: the bundled `runtime/php` in a distribution, or the
 * compiler's own process when that process is a real CLI PHP. {@see phpHost()} picks one and says
 * when there is none, and a micro SAPI compiler is never its own host — it has neither the CLI nor
 * the extension set a project installs into.
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
 * host has not loaded is looked up in that tree's own maps instead of being waved through: the
 * classmap, the files-declared symbols, and the PSR-0/PSR-4 prefixes whose computed path exists —
 * the same answer the artifact's `require vendor/autoload.php` would give. A name no carried tree
 * provides is reported, exactly like a name the host lacks. Members of a vendor-provided class
 * cannot be asked without executing the tree, so existence is the verdict there; members that
 * resolve in the host are still verified — arity and static-ness exactly.
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

    $host = phpHost();

    if ($dispatch === 'global') {
        return verifyForeignGlobalFunction($host, (string) ($pathInfo['path'] ?? ''), $type);
    }

    $classPath = (string) ($pathInfo['classPath'] ?? '');
    if ($classPath === '') {
        return null;
    }

    $className = phpClassFqn($classPath);
    $class = $host->classShape($className);

    if ($class === null) {
        return vendorProvidesClass($className) ? null : "php has no class or interface `{$className}`";
    }

    $member = (string) ($pathInfo['hostMember'] ?? '');

    return match ($dispatch) {
        'constructor' => verifyForeignConstructor($host, $class, $className, $type),
        'static' => verifyForeignStaticMethod($host, $className, $member, $type),
        'instance' => verifyForeignInstanceMethod($host, $className, $member, $type),
        default => null,
    };
}

/**
 * Whether any carried Composer tree provides a class, interface or enum the host has not loaded.
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
 * Whether any carried Composer tree declares a global function the host has not loaded.
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

/**
 * Whether the runtime this backend emits beside a program declares a function.
 *
 * The emitted tree always carries the runtime, so a `Moggi\…` path names the
 * compiler's own PHP library rather than the host's. The names are read from
 * the runtime source, because the host does not load it.
 */
function runtimeProvidesFunction(string $function): bool
{
    $path = \ltrim($function, '\\');
    if (! \str_starts_with($path, 'Moggi\\')) {
        return false;
    }

    $names = runtimeFunctionNames();

    return isset($names[\substr($path, \strlen('Moggi\\'))]);
}

/** @return array<string, true> */
function runtimeFunctionNames(): array
{
    static $names = null;

    if ($names !== null) {
        return $names;
    }

    $names = [];
    $source = @\file_get_contents(__DIR__ . '/runtime.php');
    if ($source === false) {
        return $names;
    }

    if (\preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches) > 0) {
        foreach ($matches[1] as $name) {
            $names[$name] = true;
        }
    }

    return $names;
}

/** @param array{dispatch?: string, classPath?: ?string, hostMember?: ?string} $pathInfo */
function verifyForeignConst(array $pathInfo): ?string
{
    if (($pathInfo['dispatch'] ?? '') !== 'static') {
        return null;
    }

    $host = phpHost();
    $member = (string) ($pathInfo['hostMember'] ?? '');
    $classPath = (string) ($pathInfo['classPath'] ?? '');

    if ($classPath === 'PHP') {
        return $host->hasConstant(null, $member) ? null : "php has no global constant `{$member}`";
    }

    $className = phpClassFqn($classPath);
    if ($host->classShape($className) === null) {
        return vendorProvidesClass($className) ? null : "php has no class or interface `{$className}`";
    }

    return $host->hasConstant($className, $member)
        ? null
        : "php class `{$className}` has no constant `{$member}`";
}

function verifyForeignGlobalFunction(PhpHost $host, string $function, ?Ast\TypeNode $type): ?string
{
    if ($function === '') {
        return null;
    }

    if (runtimeProvidesFunction($function)) {
        return null;
    }

    $shape = $host->functionShape($function);
    if ($shape === null) {
        return vendorProvidesFunction($function) ? null : "php has no function `{$function}`";
    }

    return foreignArityProblem(
        'php function',
        $function,
        foreignArity($type, 'global'),
        $shape,
    );
}

function verifyForeignStaticMethod(
    PhpHost $host,
    string $className,
    string $member,
    ?Ast\TypeNode $type,
): ?string {
    if ($member === '') {
        return null;
    }

    $shape = $host->methodShape($className, $member);
    if ($shape === null) {
        return $host->magic($className)['callStatic']
            ? null
            : "php class `{$className}` has no method `{$member}`";
    }
    if (! $shape['static']) {
        return "php method `{$className}::{$member}` is not static; use `{$className}.{$member}`"
            . ' and pass the receiver first';
    }

    return foreignArityProblem(
        'php method',
        "{$className}::{$member}",
        foreignArity($type, 'static'),
        $shape,
    );
}

function verifyForeignInstanceMethod(
    PhpHost $host,
    string $className,
    string $member,
    ?Ast\TypeNode $type,
): ?string {
    if ($member === '') {
        return null;
    }

    $shape = $host->methodShape($className, $member);
    if ($shape === null) {
        return $host->magic($className)['call']
            ? null
            : "php class `{$className}` has no method `{$member}`";
    }

    return foreignArityProblem(
        'php method',
        "{$className}.{$member}",
        foreignArity($type, 'instance'),
        $shape,
    );
}

/** @param array{instantiable: bool, constructor: ?array{required: int, total: int, variadic: bool}} $class */
function verifyForeignConstructor(PhpHost $host, array $class, string $className, ?Ast\TypeNode $type): ?string
{
    if (! $class['instantiable']) {
        return "php class `{$className}` cannot be instantiated";
    }

    if ($class['constructor'] === null) {
        return null;
    }

    return foreignArityProblem(
        'php constructor',
        "{$className}::__construct",
        foreignArity($type, 'constructor'),
        $class['constructor'],
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

/** @param array{required: int, total: int, variadic: bool} $shape */
function foreignArityProblem(string $subject, string $label, int $declared, array $shape): ?string
{
    if ($declared < $shape['required']) {
        return "{$subject} `{$label}` needs " . argumentCount($shape['required'])
            . ', but this foreign import declares ' . argumentCount($declared);
    }

    if (! $shape['variadic'] && $declared > $shape['total']) {
        return "{$subject} `{$label}` takes at most " . argumentCount($shape['total'])
            . ', but this foreign import declares ' . argumentCount($declared);
    }

    return null;
}

function argumentCount(int $count): string
{
    return $count === 1 ? '1 argument' : "{$count} arguments";
}
