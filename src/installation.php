<?php declare(strict_types=1);

namespace Moggi\Install;

/**
 * Where an installed compiler lives, and the runtimes it carries.
 *
 * A distribution used to be a C launcher beside `moggi.phar`: the launcher found
 * the archive, put the bundled runtimes in front of `PATH`, and handed the
 * process to PHP. Now the compiler *is* one executable — a micro PHP runtime
 * with the archive appended (see `Moggi\Backend\Php`) — so there is no separate
 * program to do that, and the bytes between the runtime and the archive (the INI
 * object) are the only place a directive can be set.
 *
 * The installation root is therefore found without `Phar::running`, which is
 * empty in a native binary: the archive stub records the directory it was
 * started from as `MOGGI_INSTALL_ROOT`, and that is `<installation>` whether the
 * payload is appended to a runtime or run as `php bin/moggi.phar`.
 */

/** The constant the archive stub defines: the installation root, or nothing in a checkout. */
const INSTALL_ROOT_CONSTANT = 'MOGGI_INSTALL_ROOT';

/**
 * The installation this process is running from, or null in a source checkout.
 *
 * The stub's constant is authoritative — it is the directory the executable was
 * started from, so a compiler copied elsewhere finds the `lib/` and `runtime/`
 * beside *it*, never beside wherever it was built. The `Phar::running` fallback
 * covers an archive run without the stub, which nothing in this tree produces,
 * but which costs one line to keep working.
 */
function installationRoot(): ?string
{
    if (\defined(INSTALL_ROOT_CONSTANT)) {
        $root = (string) \constant(INSTALL_ROOT_CONSTANT);

        return $root === '' ? null : \rtrim($root, '/\\');
    }

    $archive = \Phar::running(false);
    if ($archive !== '') {
        return \dirname($archive, 2);
    }

    return null;
}

/**
 * Put the bundled runtimes in front of `PATH`, and hand the roots the compiler
 * looks for to the environment it passes to its children.
 *
 * A distribution that bundles a runtime wants the compiler's child processes —
 * `javac`, `java`, `native-image`, `dotnet`, `php`, `composer`, `mvn` — to
 * resolve to the bundled copy before anything the host has, which is the one
 * thing the retired C launcher did that the compiler cannot do for itself by
 * reading a path: the child has no idea where the installation is.
 *
 * Nothing here is fatal on its own: a variant that bundles no runtime makes the
 * whole function a no-op, and a runtime directory that is missing is skipped
 * rather than reported, because that is what "not in this variant" means.
 */
function activateBundledRuntimes(): void
{
    $root = installationRoot();
    if ($root === null) {
        return;
    }

    $runtime = $root . '/runtime';

    $directories = [];

    $jvm = $runtime . '/jvm';
    if (\is_dir($jvm . '/bin')) {
        $directories[] = $jvm . '/bin';
        setEnvironment('JAVA_HOME', platformPath($jvm));
    }

    $graalvm = $runtime . '/graalvm';
    if (\is_dir($graalvm . '/bin')) {
        $directories[] = $graalvm . '/bin';
    }

    $dotnet = $runtime . '/dotnet';
    if (\is_dir($dotnet)) {
        $directories[] = $dotnet;
        setEnvironment('DOTNET_ROOT', platformPath($dotnet));
    }

    $phpHome = $runtime . '/php';
    $phpBin = bundledPhpBinDirectory($phpHome);
    if (\is_dir($phpBin)) {
        $directories[] = $phpBin;
        if (\is_file($phpHome . '/php.ini')) {
            setEnvironment('PHPRC', platformPath($phpHome));
            setEnvironment('PHP_INI_SCAN_DIR', '');
        }
        prependLoaderPath($phpHome . '/lib');
    }

    $maven = $runtime . '/maven';
    if (\is_dir($maven . '/bin')) {
        $directories[] = $maven . '/bin';
    }

    $composer = $runtime . '/composer';
    if (\is_dir($composer . '/bin')) {
        $directories[] = $composer . '/bin';
    }

    if ($directories !== []) {
        prependPath($directories);
    }
}

/**
 * Set a variable in the running process and in the copies a spawned child sees.
 *
 * `putenv` is what `proc_open`/`exec` inherit; `$_ENV`/`$_SERVER` are what PHP
 * code reads with `getenv`'s fallbacks and what the language server snapshots.
 */
function setEnvironment(string $name, string $value): void
{
    \putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

/**
 * Prepend directories to `PATH`, in the order given, keeping what was there.
 *
 * @param list<string> $directories
 */
function prependPath(array $directories): void
{
    $current = \getenv('PATH');
    $prefix = \implode(\PATH_SEPARATOR, $directories);
    setEnvironment('PATH', $prefix . \PATH_SEPARATOR . ($current === false ? '' : $current));
}

/**
 * Prepend a directory to the platform's dynamic-loader search path, when it
 * exists: the variable is the one that platform's linker reads.
 */
function prependLoaderPath(string $directory): void
{
    $name = loaderVariable();
    if ($name === null || !\is_dir($directory)) {
        return;
    }

    $current = \getenv($name);
    setEnvironment($name, $directory . \PATH_SEPARATOR . ($current === false ? '' : $current));
}

/**
 * The variable a platform's dynamic loader reads, or null when it has none.
 *
 * The name is the platform's own: macOS's `dyld` reads
 * `DYLD_FALLBACK_LIBRARY_PATH`, which is a *fallback* — it never overrides a
 * library a host already provides, which is what a bundled runtime wants. Linux
 * reads `LD_LIBRARY_PATH`. Windows resolves DLLs through `PATH` and has no
 * separate loader variable, so there is nothing to add.
 */
function loaderVariable(?string $osFamily = null): ?string
{
    return match ($osFamily ?? \PHP_OS_FAMILY) {
        'Windows' => null,
        'Darwin' => 'DYLD_FALLBACK_LIBRARY_PATH',
        default => 'LD_LIBRARY_PATH',
    };
}

/**
 * Where a bundled PHP's executable sits inside its runtime directory.
 *
 * The official Windows build is a flat zip with `php.exe` at the top, while the
 * source builds shipped for Linux and macOS put the binary under `bin/`.
 */
function bundledPhpBinDirectory(string $phpHome, ?string $osFamily = null): string
{
    return ($osFamily ?? \PHP_OS_FAMILY) === 'Windows' ? $phpHome : $phpHome . '/bin';
}

/**
 * A path as the platform spells it when handed to a child process.
 *
 * Windows accepts forward slashes in most APIs, but a tool that builds a path by
 * concatenation — `%JAVA_HOME%\bin\java`, a `.cmd` launcher — expects
 * backslashes, so the values the compiler exports are spelled the host's way.
 * Every other platform already reads `/`.
 */
function platformPath(string $path, ?string $osFamily = null): string
{
    return ($osFamily ?? \PHP_OS_FAMILY) === 'Windows'
        ? \str_replace('/', '\\', $path)
        : $path;
}
