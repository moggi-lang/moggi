<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Portable;

use const Moggi\Backend\Php\Store\EXTENSION_MANIFEST;

use function Moggi\Backend\Php\Store\hostAbi;
use function Moggi\Backend\Php\Store\manifest;
use function Moggi\Backend\Php\Store\phpBinary;
use function Moggi\Backend\Php\Store\removeSourceTree;
use function Moggi\Backend\Php\Store\runtimeKey;
use function Moggi\Backend\Php\Store\writeManifest;
use function Moggi\Cache\runtimeCacheDir;
use function Moggi\Compiler\runProcess;
use function Moggi\Registry\normalizeExtensionName;

/**
 * A copy of a PHP runtime that runs where it is put.
 *
 * The bundled runtime is built for one machine, and says so: its `bin/php`
 * carries the runpath of the build directory and of the C libraries it was
 * linked against, so copying the tree produces something that starts only where
 * it was built, under the PHP it was built beside. This turns that tree into one
 * that finds its own libraries and its own configuration:
 *
 *   - every shared library `ldd` names is copied into `lib/`. The runtime
 *     already ships ICU and oniguruma; the C++ runtime, libgcc, the C library
 *     and the program loader are what a copy is missing;
 *   - `DT_RUNPATH` is rewritten to `$ORIGIN/../lib` **in place**, with no
 *     external tool. A distribution has no `patchelf`, and requiring one would
 *     make "prepare a runtime" need the very toolchain it exists to avoid;
 *   - `bin/php` becomes a small launcher. PHP looks for `php.ini` only at the
 *     prefix it was compiled with, so a copied runtime has to say where its own
 *     is, and it runs the loader beside libc explicitly so the glibc PHP was
 *     linked against is the one that loads it, whatever the host provides;
 *   - the whole thing sits in the shared runtime cache under a key of version,
 *     platform and extension set, so the same set is built once and reused.
 *
 * What this does not do is rebuild PHP: a runtime is prepared from one that
 * exists, and the extension set is whatever that runtime carried. Building a
 * runtime with a *different* set is the static `php-native` path, whose
 * extensions have to be compiled in.
 *
 * Windows needs none of that rewriting: the loader searches the directory of
 * the executable before anywhere else, and PHP looks for `php.ini` there too, so
 * a copy of the tree is a working PHP. What a copy does need is an
 * `extension_dir` that survives being run from another directory — PHP resolves
 * a relative one against the current directory, never against `php.exe` — and
 * an ini that enables what the copy carries. macOS is refused: its libraries are
 * named by `install_name` and have to be rewritten with `install_name_tool`,
 * then signed ad-hoc, which this command does not do.
 */

/**
 * The cache key of a portable runtime: what it is, on what platform, carrying what.
 *
 * The same shape `nativeRuntimeKey()` uses, because it is the same question —
 * "has this runtime already been prepared?" — asked about a different artifact.
 *
 * @param list<string> $extensions
 */
function portableRuntimeKey(string $version, array $extensions): string
{
    return runtimeKey('php', $extensions, $version);
}

/** Where a portable runtime for this set lives, whether or not it has been built. */
function portableRuntimeDir(string $version, array $extensions): string
{
    return runtimeCacheDir() . '/php/' . \substr(portableRuntimeKey($version, $extensions), 7);
}

/**
 * The runtime home a `--php` option names: a runtime directory, or the PHP
 * binary inside one.
 *
 * `--php` mirrors `runtime php verify --php`, where it names the PHP to ask;
 * here it names the runtime to copy, so both the directory and its binary are
 * accepted rather than making a person know which one the command wants.
 */
function portableRuntimeHome(string $path): string
{
    if (\trim($path) === '') {
        throw new \RuntimeException('--php needs a runtime directory or the PHP binary inside one');
    }

    $resolved = \realpath($path);
    if ($resolved === false) {
        throw new \RuntimeException("--php names nothing on disk: {$path}");
    }

    if (\is_dir($resolved)) {
        if (\is_file($resolved . '/bin/php') || \is_file($resolved . '/php.exe')) {
            return $resolved;
        }

        throw new \RuntimeException(
            "--php needs a runtime directory or the PHP inside one, and {$path} has no bin/php or php.exe\n"
            . '  a runtime directory is the one holding php.ini, extensions.json and ext/',
        );
    }

    $dir = \dirname($resolved);

    return \basename($dir) === 'bin' ? \dirname($dir) : $dir;
}

/**
 * Build (or reuse) the portable runtime for the set `$home` carries.
 *
 * The ABI comes from the binary, not the record: a packaged runtime records only
 * its extension set, and the binary is the authority on what it is. The tree is
 * built beside its destination and moved in only when it is whole, so a
 * half-copied runtime is never reused as a cache hit.
 *
 * @param callable(string): void $say
 * @return array{path: string, reused: bool, out: ?string, size: int, php: string, zts: bool, extensions: list<string>, unavailable: list<string>}
 */
function buildPortableRuntime(string $home, ?string $out, bool $force, callable $say): array
{
    if (\PHP_OS_FAMILY === 'Darwin') {
        throw new \RuntimeException(
            "preparing a portable PHP runtime is not supported on macOS yet\n"
            . '  its libraries are named by install_name and have to be rewritten with install_name_tool,'
            . ' then the copy has to be signed ad-hoc; this command does neither',
        );
    }
    $windows = \PHP_OS_FAMILY === 'Windows';

    $source = phpBinary($home);
    if (! \is_file($source)) {
        throw new \RuntimeException("no PHP binary at {$source}");
    }

    $record = manifest($home);
    if ($record['extensions'] === []) {
        throw new \RuntimeException(
            "the runtime at {$home} records no extension set, so a portable copy could not say what it carries:\n"
            . "  {$home}/" . EXTENSION_MANIFEST . " is missing or empty\n"
            . '  use the runtime a Moggi distribution installed, or pass --php to one',
        );
    }

    $abi = hostAbi($home);
    $version = $abi['version'] !== '' ? $abi['version'] : $record['abi']['version'];
    $zts = $abi['zts'] || $record['abi']['zts'];
    $extensions = \array_values(\array_unique(\array_map(normalizeExtensionName(...), $record['extensions'])));
    $dir = portableRuntimeDir($version, $extensions);

    $entrypoint = $windows ? $dir . '/php.exe' : $dir . '/bin/php';
    if (! $force && \is_file($entrypoint) && \is_file($dir . '/php.ini')) {
        $say('reusing the runtime already prepared for this set');
        $copied = $out === null ? null : mirrorPortableRuntime($dir, $out, $force);
        $prepared = manifest($dir);

        return [
            'path' => $dir,
            'reused' => true,
            'out' => $copied,
            'size' => directorySize($dir),
            'php' => $version,
            'zts' => $zts,
            'extensions' => $extensions,
            'unavailable' => $prepared['unavailable'],
        ];
    }

    $parent = \dirname($dir);
    if (! \is_dir($parent) && ! \mkdir($parent, 0777, true) && ! \is_dir($parent)) {
        throw new \RuntimeException("cannot create {$parent}");
    }

    $staging = $parent . '/.build-' . \bin2hex(\random_bytes(4));
    removeSourceTree($staging);

    $unavailable = [];
    try {
        if ($windows) {
            $unavailable = makePortableWindowsTree($home, $staging, $version, $zts);
        } else {
            makePortablePosixTree($home, $staging, $version, $zts);
        }
        verifyPortableRuntime($staging, $version, $extensions);
    } catch (\Throwable $error) {
        removeSourceTree($staging);

        throw $error instanceof \RuntimeException
            ? $error
            : new \RuntimeException('cannot prepare the runtime: ' . $error->getMessage(), 0, $error);
    }

    removeSourceTree($dir);
    if (! @\rename($staging, $dir)) {
        removeSourceTree($staging);

        throw new \RuntimeException("cannot move the prepared runtime into {$dir}");
    }

    $say("built {$dir}");
    $copied = $out === null ? null : mirrorPortableRuntime($dir, $out, $force);

    return [
        'path' => $dir,
        'reused' => false,
        'out' => $copied,
        'size' => directorySize($dir),
        'php' => $version,
        'zts' => $zts,
        'extensions' => $extensions,
        'unavailable' => $unavailable,
    ];
}

/**
 * Lay out a Unix portable tree: binary, libraries, extensions, configuration,
 * launcher.
 *
 * Every object that directs the loader is pointed at the copy's own `lib/`, and
 * one whose runpath cannot be rewritten in place is an error rather than a path
 * left naming the machine this was prepared on. The copy records the ABI it was
 * built from, so `build --php` on it answers the same questions.
 */
function makePortablePosixTree(string $home, string $staging, string $version, bool $zts): void
{
    foreach (['bin', 'libexec', 'lib', 'ext'] as $directory) {
        $path = $staging . '/' . $directory;
        if (! \mkdir($path, 0777, true) && ! \is_dir($path)) {
            throw new \RuntimeException("cannot create {$path}");
        }
    }

    $binary = $staging . '/libexec/php-' . $version;
    if (! \copy(phpBinary($home), $binary) || ! \chmod($binary, 0755)) {
        throw new \RuntimeException('cannot copy the PHP binary out of ' . $home);
    }

    foreach (['php.ini' => 'php.ini', 'LICENSE' => 'LICENSE'] as $from => $to) {
        if (\is_file($home . '/' . $from) && ! \copy($home . '/' . $from, $staging . '/' . $to)) {
            throw new \RuntimeException("cannot copy {$home}/{$from}");
        }
    }
    requirePhpIni($staging, $home);

    $record = manifest($home);
    writeManifest($staging, [
        'php' => $version,
        'abi' => ['version' => $version, 'zts' => $zts],
        'extensions' => $record['extensions'],
        'installed' => $record['installed'],
    ]);

    copyFlat($home . '/ext', $staging . '/ext');
    copyFlat($home . '/lib', $staging . '/lib');

    bundleSharedLibraries($binary, $staging . '/lib');

    rewriteElfRunpath($binary, '$ORIGIN/../lib');
    foreach (\glob($staging . '/ext/*.so') ?: [] as $extension) {
        bundleSharedLibraries($extension, $staging . '/lib', false);
        rewriteElfRunpath($extension, '$ORIGIN/../lib');
    }

    $launcher = $staging . '/bin/php';
    if (\file_put_contents($launcher, portableLauncher($version)) === false || ! \chmod($launcher, 0755)) {
        throw new \RuntimeException("cannot write {$launcher}");
    }
}

/**
 * Lay out a portable tree for a Windows runtime.
 *
 * A Windows PHP is already a directory that can be copied: `php.exe` beside the
 * DLLs it imports, `ext/` beside that, and the loader searches the directory of
 * the executable first. Nothing in a PE binary names a library by path, so there
 * is no runpath to rewrite and no library to bundle — the copy is the tree,
 * plus the two things php.net's zip does not ship:
 *
 *   - a `php.ini` that enables every extension DLL this PHP can actually load.
 *     The packaged ini names only the distribution's set; a copy that is going
 *     to be run on its own should carry what it has. A DLL that needs software
 *     the tree does not ship (an Oracle client, an SNMP stack built elsewhere)
 *     is left out rather than left to fail on every start, which is what the
 *     probe decides;
 *   - `bin/php.cmd`, because PHP resolves a relative `extension_dir` against the
 *     current directory rather than against `php.exe`: a bare `php.exe` finds
 *     its extensions only when it is started from this directory, and the
 *     launcher names them from wherever it is started.
 *
 * @return list<string> the extension DLLs the runtime cannot load, and so does not enable
 */
function makePortableWindowsTree(string $home, string $staging, string $version, bool $zts): array
{
    copyPortableTree($home, $staging);

    if (! \is_file($staging . '/php.exe')) {
        throw new \RuntimeException("the runtime at {$home} has no php.exe");
    }
    requirePhpIni($staging, $home);

    $record = manifest($home);
    $probe = enableWindowsExtensions($staging);
    writeManifest($staging, [
        'php' => $version,
        'abi' => ['version' => $version, 'zts' => $zts],
        'extensions' => $record['extensions'],
        'installed' => $record['installed'],
        'unavailable' => $probe['unavailable'],
    ]);
    writeWindowsIni($staging, $probe['enabled']);
    writeWindowsLauncher($staging);

    return $probe['unavailable'];
}

/**
 * Every extension DLL in the copy that this PHP can load, and the ones it cannot.
 *
 * The question is asked of the runtime itself, one DLL at a time and with no ini
 * at all: a module that is compiled in cannot be added a second time, and one
 * whose own native dependencies are not in the tree fails to load. Neither
 * belongs in the copy's ini — the first would warn on every start, the second
 * would break it.
 *
 * @return array{enabled: list<string>, unavailable: list<string>}
 */
function enableWindowsExtensions(string $directory): array
{
    $php = $directory . '/php.exe';
    $extensions = $directory . '/ext';
    $dlls = \glob($extensions . '/php_*.dll') ?: [];
    \sort($dlls, \SORT_STRING);
    $env = windowsRuntimeEnv();

    $compiledIn = windowsModules($php, ['-n'], $directory, $env);
    if ($compiledIn === null) {
        throw new \RuntimeException(
            "cannot ask {$php} which modules it has compiled in, so the copy\n"
            . '  could not say which extension DLLs it may enable',
        );
    }

    $enabled = [];
    $unavailable = [];
    foreach ($dlls as $dll) {
        $modules = windowsModules(
            $php,
            ['-n', '-d', 'extension_dir=' . $extensions, '-d', 'extension=' . \basename($dll)],
            $directory,
            $env,
        );
        if ($modules !== null && $modules !== $compiledIn) {
            $enabled[] = \basename($dll);
        } else {
            $unavailable[] = \basename($dll);
        }
    }

    return ['enabled' => $enabled, 'unavailable' => $unavailable];
}

/**
 * The modules a PHP reports, or null when it did not start.
 *
 * The snippet contains no `"`: on Windows a `.cmd` is started through `cmd /c`,
 * so a quote would have to survive that layer as well as PHP's own quoting.
 * `chr()` says the same thing without one.
 */
function windowsModules(string $php, array $options, string $cwd, array $env): ?string
{
    $result = runProcess(
        [$php, ...$options, '-r', 'echo implode(chr(44), get_loaded_extensions());'],
        $cwd,
        $env,
    );

    return $result['exitCode'] === 0 ? \trim($result['stdout']) : null;
}

/**
 * The copy's `php.ini`: every loadable extension, and an `extension_dir` that
 * means something.
 *
 * The runtime's own lines are kept — a `zend_extension`, a `memory_limit`, a
 * per-path section are not this function's to change — and only the two kinds of
 * line a copy has to restate are replaced.
 *
 * @param list<string> $enabled
 */
function writeWindowsIni(string $directory, array $enabled): void
{
    $ini = $directory . '/php.ini';
    $lines = \is_file($ini) ? \explode("\n", (string) \file_get_contents($ini)) : [];
    $kept = [];
    foreach ($lines as $line) {
        $line = \rtrim($line, "\r");
        if (\preg_match('/^\s*(?:extension|extension_dir)\s*=/i', $line) === 1) {
            continue;
        }
        $kept[] = $line;
    }
    while ($kept !== [] && \trim((string) \end($kept)) === '') {
        \array_pop($kept);
    }

    $kept[] = '';
    $kept[] = '; Written by `moggi runtime php build`.';
    $kept[] = '; extension_dir is relative to the current directory, so a bare php.exe finds';
    $kept[] = '; ext/ only when it starts here; bin/php.cmd names its own ext/, from whatever';
    $kept[] = '; directory it is started in.';
    $kept[] = 'extension_dir="ext"';
    foreach ($enabled as $dll) {
        $kept[] = 'extension=' . $dll;
    }
    $kept[] = '';

    if (\file_put_contents($ini, \implode("\n", $kept)) === false) {
        throw new \RuntimeException("cannot write {$ini}");
    }
}

/**
 * `bin/php.cmd`, the entry point a Windows copy is used through.
 *
 * Windows PHP needs no help finding its ini or its DLLs: both are searched for
 * beside `php.exe`. The exception is `extension_dir`, which PHP resolves against
 * the current directory even when it is relative, so the only way to use the
 * copy from another directory is to say where `ext/` is. That is all this does —
 * and no `cd`, because the argument a caller passes is resolved against *their*
 * directory, not this one.
 */
function windowsLauncher(): string
{
    return <<<'BAT'
    @echo off
    rem Portable PHP runtime, prepared by `moggi runtime php build`.
    rem
    rem php.exe finds php.ini and its DLLs beside itself. PHP resolves a relative
    rem extension_dir against the current directory instead, so the copy's own
    rem ext/ is passed explicitly; nothing has to be installed or set first.
    rem
    rem Every path is quoted, the copy is canonicalised with for/%%~f so no `..`
    rem reaches PHP, and delayed expansion is off so a `!` in the path is a
    rem character rather than an expansion. `%*` hands the caller's arguments
    rem through as they were typed, which is why the copy is never chdir'd to.
    setlocal DisableDelayedExpansion
    for %%i in ("%~dp0..") do set "php_home=%%~fi"
    if "%php_home:~-1%"=="\" set "php_home=%php_home:~0,-1%"
    set "PHPRC=%php_home%"
    set "PHP_INI_SCAN_DIR="
    "%php_home%\php.exe" -d "extension_dir=%php_home%\ext" %*
    exit /b %ERRORLEVEL%

    BAT;
}

/** Write the copy's `bin/php.cmd`, with the CRLF a batch file expects. */
function writeWindowsLauncher(string $directory): void
{
    $bin = $directory . '/bin';
    if (! \is_dir($bin) && ! \mkdir($bin, 0777, true) && ! \is_dir($bin)) {
        throw new \RuntimeException("cannot create {$bin}");
    }
    $path = $bin . '/php.cmd';
    if (\file_put_contents($path, \str_replace("\n", "\r\n", windowsLauncher())) === false) {
        throw new \RuntimeException("cannot write {$path}");
    }
}

/**
 * The environment a prepared Windows runtime is tested in.
 *
 * Not an empty one: Windows needs `SystemRoot` and the variables `cmd` reads,
 * and a runtime is not required to invent them. What is removed is everything
 * that could make the copy work for the wrong reason — `PHPRC`,
 * `PHP_INI_SCAN_DIR`, and every `PATH` entry that could name another PHP — so
 * an extension that loads can only have come from this directory.
 *
 * @return array<string, string>
 */
function windowsRuntimeEnv(): array
{
    $system = \getenv('SystemRoot');
    $system = \is_string($system) && $system !== '' ? $system : 'C:\\Windows';

    $env = ['SystemRoot' => $system, 'PATH' => $system . '\\system32;' . $system];
    foreach (['WINDIR', 'SystemDrive', 'ComSpec', 'PATHEXT', 'TEMP', 'TMP', 'NUMBER_OF_PROCESSORS'] as $name) {
        $value = \getenv($name);
        if (\is_string($value) && $value !== '') {
            $env[$name] = $value;
        }
    }

    return $env;
}

/**
 * A copy without a `php.ini` would load no extension, on any platform.
 *
 * The message is the same wherever the copy is made, so it is written once: a
 * runtime without one is a directory of files, not a runtime.
 */
function requirePhpIni(string $copy, string $home): void
{
    if (\is_file($copy . '/php.ini')) {
        return;
    }

    throw new \RuntimeException(
        "the runtime at {$home} has no php.ini, so a copy of it would load no extension\n"
        . '  a runtime a Moggi distribution installed always has one',
    );
}

/**
 * The `bin/php` a prepared runtime is entered through.
 *
 * A launcher and not the binary itself, because two things a copied PHP cannot
 * work out on its own have to be said: where its `php.ini` is (PHP only looks at
 * the prefix it was compiled with) and which C library to load (the one in
 * `lib/`, not whatever the host has). Everything it uses is a shell builtin, so
 * it works with an empty `PATH` — which is exactly how a portable runtime is
 * tested, and often how it is deployed.
 */
function portableLauncher(string $version): string
{
    return \str_replace('@version@', $version, <<<'SH'
    #!/bin/sh
    # Portable PHP runtime, prepared by `moggi runtime php build`.
    #
    # PHP looks for php.ini only at the prefix it was compiled with, so this
    # runtime points PHPRC at itself. The loader beside libc is run explicitly:
    # the glibc PHP was linked against is then the one that loads it, whatever
    # the host provides. Everything used here is a shell builtin, so it works
    # with an empty PATH.
    case "$0" in
        */*) dir=${0%/*} ;;
        *) dir=. ;;
    esac
    root=$(CDPATH= cd "$dir/.." && pwd) || exit 1
    PHPRC="$root"
    PHP_INI_SCAN_DIR=
    export PHPRC PHP_INI_SCAN_DIR

    for candidate in "$root"/lib/ld-linux*.so* "$root"/lib/ld-musl-*.so.1; do
        if [ -f "$candidate" ]; then
            exec "$candidate" --library-path "$root/lib" "$root/libexec/php-@version@" "$@"
        fi
    done

    exec "$root/libexec/php-@version@" "$@"

    SH);
}

/**
 * Copy every shared library an object needs that the runtime does not ship.
 *
 * `ldd` and not `readelf`: the question is the whole closure — ICU pulls in
 * `libicudata`, which no `NEEDED` entry of PHP names — and `ldd` is also what
 * says a library was not found, which is an error for the binary: a runtime with
 * a hole in it is not portable, it is a runtime that fails on the other machine.
 *
 * The program loader is the one library that has to match the C library beside
 * it, so the copy in libc's own directory wins over what the host resolves it
 * to.
 */
function bundleSharedLibraries(string $object, string $libDir, bool $strict = true): void
{
    $libraries = sharedLibraries($object, $strict);
    $loader = null;

    foreach ($libraries as $name => $path) {
        if ($strict && \preg_match('/^ld-(?:linux|musl)/', $name) === 1) {
            $loader = [$name, $path];

            continue;
        }

        $target = $libDir . '/' . $name;
        if (\is_file($target)) {
            continue;
        }
        $real = \realpath($path);
        if ($real === false || ! \is_file($real) || ! \copy($real, $target)) {
            throw new \RuntimeException("cannot bundle the shared library {$name} ({$path})");
        }
    }

    if ($loader !== null) {
        [$name, $path] = $loader;
        $libc = isset($libraries['libc.so.6']) ? \realpath($libraries['libc.so.6']) : false;
        $candidates = [];
        if ($libc !== false) {
            $candidates[] = \dirname($libc) . '/' . $name;
        }
        $candidates[] = (string) \realpath($path);

        foreach ($candidates as $candidate) {
            if (\is_file($candidate) && \copy($candidate, $libDir . '/' . $name) && \chmod($libDir . '/' . $name, 0755)) {
                return;
            }
        }

        throw new \RuntimeException("cannot bundle the program loader {$name} ({$path})");
    }
}

/**
 * Every shared library `ldd` resolves for an object, as soname => path.
 *
 * Not strict is for an extension: a dependency this machine lacks means that one
 * extension will not load, which the runtime can carry on without, while the
 * same answer for the binary means there is nothing portable to copy.
 *
 * @return array<string, string>
 */
function sharedLibraries(string $object, bool $strict = true): array
{
    $result = runProcess(['ldd', $object]);
    if ($result['exitCode'] !== 0) {
        if (! $strict) {
            return [];
        }

        throw new \RuntimeException(
            "cannot read what {$object} links against: ldd failed\n" . \trim($result['stderr']),
        );
    }

    $libraries = [];
    foreach (\explode("\n", $result['stdout']) as $line) {
        $line = \trim($line);
        if ($line === '' || \str_contains($line, 'linux-vdso') || \str_contains($line, 'statically linked')) {
            continue;
        }
        if (\str_contains($line, 'not found')) {
            if (! $strict) {
                continue;
            }

            throw new \RuntimeException(
                "{$object} needs a shared library this machine does not have: {$line}\n"
                . '  the runtime it comes from is incomplete, so there is nothing portable to copy',
            );
        }
        if (\preg_match('/^(?<name>\S+)\s+=>\s+(?<path>\S+)/', $line, $match) !== 1) {
            continue;
        }
        $libraries[\basename($match['name'])] = $match['path'];
    }

    return $libraries;
}

/**
 * Point an ELF object's `DT_RUNPATH` (and `DT_RPATH`, when it has one) at
 * `$runpath`, in place.
 *
 * In place is the point: the string table already has room, the loader reads the
 * string it finds there, and no `patchelf` is needed on a machine whose whole
 * promise is that nothing has to be installed. The old string is overwritten
 * with the new one and NUL-padded, so nothing else in the table moves.
 *
 * A Mach-O object is refused here: macOS needs `install_name_tool` and an
 * ad-hoc signature, which is a different job, and pretending otherwise would
 * produce a runtime that does not load there. An object with no dynamic section
 * names no libraries, so it has none to point anywhere and is left alone.
 */
function rewriteElfRunpath(string $file, string $runpath): void
{
    $bytes = \file_get_contents($file);
    if ($bytes === false) {
        throw new \RuntimeException("cannot read {$file}");
    }
    if (\strlen($bytes) < 64 || \substr($bytes, 0, 4) !== "\x7fELF") {
        throw new \RuntimeException(
            "{$file} is not an ELF object, so its runpath cannot be rewritten\n"
            . '  preparing a portable runtime on macOS needs install_name_tool and an ad-hoc codesign,'
            . ' which this command does not do yet',
        );
    }

    $class = \ord($bytes[4]);            // 1 = 32-bit, 2 = 64-bit
    $little = \ord($bytes[5]) !== 2;     // 2 = big endian
    if ($class !== 1 && $class !== 2) {
        throw new \RuntimeException("{$file} has an unknown ELF class");
    }
    $wide = $class === 2;

    $read = static function (int $offset, int $size) use ($bytes, $little): int {
        $chunk = \substr($bytes, $offset, $size);
        if (\strlen($chunk) !== $size) {
            throw new \RuntimeException('truncated ELF object');
        }
        $format = match ($size) {
            8 => $little ? 'P' : 'J',
            4 => $little ? 'V' : 'N',
            default => $little ? 'v' : 'n',
        };

        return (int) \unpack($format, $chunk)[1];
    };

    $sectionHeaders = $read($wide ? 0x28 : 0x20, $wide ? 8 : 4);
    $sectionSize = $read(0x3A, 2);
    $sectionCount = $read(0x3C, 2);
    if ($sectionHeaders === 0 || $sectionCount === 0 || $sectionSize === 0) {
        throw new \RuntimeException("{$file} has no section table, so its runpath cannot be found");
    }

    $dynamic = null;
    $sections = [];
    for ($index = 0; $index < $sectionCount; $index++) {
        $base = $sectionHeaders + $index * $sectionSize;
        $sections[$index] = $read($base + ($wide ? 24 : 16), $wide ? 8 : 4);   // sh_offset
        if ($read($base + 4, 4) !== 6) {                                        // SHT_DYNAMIC
            continue;
        }
        $dynamic = [
            'offset' => $read($base + ($wide ? 24 : 16), $wide ? 8 : 4),
            'size' => $read($base + ($wide ? 32 : 20), $wide ? 8 : 4),
            'link' => $read($base + ($wide ? 40 : 24), 4),
            'entry' => $read($base + ($wide ? 56 : 36), $wide ? 8 : 4),
        ];
    }

    if ($dynamic === null || ! isset($sections[$dynamic['link']])) {
        return;
    }

    $entrySize = $dynamic['entry'] !== 0 ? $dynamic['entry'] : ($wide ? 16 : 8);
    $tagSize = $wide ? 8 : 4;
    $strings = $sections[$dynamic['link']];

    $slots = [];
    for ($offset = $dynamic['offset']; $offset + $entrySize <= $dynamic['offset'] + $dynamic['size']; $offset += $entrySize) {
        $tag = $read($offset, $tagSize);
        if ($tag !== 29 && $tag !== 15) {   // DT_RUNPATH, DT_RPATH
            continue;
        }
        $start = $strings + $read($offset + $tagSize, $tagSize);
        $end = \strpos($bytes, "\0", $start);
        if ($end === false) {
            throw new \RuntimeException("{$file} has a runpath that is not terminated");
        }
        $slots[] = [$start, $end - $start];
    }

    if ($slots === []) {
        return;
    }

    foreach ($slots as [$start, $length]) {
        if (\strlen($runpath) > $length) {
            throw new \RuntimeException(
                "{$file}'s runpath field ({$length} bytes) has no room for `{$runpath}`",
            );
        }
        $bytes = \substr_replace($bytes, \str_pad($runpath, $length, "\0"), $start, $length);
    }

    if (\file_put_contents($file, $bytes) === false) {
        throw new \RuntimeException("cannot rewrite the runpath of {$file}");
    }
}

/**
 * Run the prepared runtime the way a stranger would: no environment to lean on.
 *
 * This is the claim the command makes, checked before it is made. A runtime that
 * loads its extensions only with the preparing shell's environment is exactly
 * the failure this whole command exists to prevent, so it is also the check that
 * stops it from being written to the cache.
 *
 * Windows is checked from a directory that is not the copy's, because that is
 * the case its launcher exists for. The ini PHP says it loaded is part of the
 * claim on both platforms: a machine with PHP installed can offer the registry's
 * ini instead, and a launcher that loses its own path loads none at all.
 *
 * @param list<string> $extensions
 */
function verifyPortableRuntime(string $directory, string $version, array $extensions): void
{
    $windows = \PHP_OS_FAMILY === 'Windows';
    $php = $directory . '/bin/' . ($windows ? 'php.cmd' : 'php');
    $result = runProcess(
        [
            $php,
            '-r',
            'echo PHP_VERSION, chr(10), implode(chr(44), get_loaded_extensions()), chr(10), (string) php_ini_loaded_file();',
        ],
        $windows ? \sys_get_temp_dir() : null,
        $windows ? windowsRuntimeEnv() : [],
    );
    if ($result['exitCode'] !== 0) {
        throw new \RuntimeException(
            "the prepared runtime does not start: {$php} exited {$result['exitCode']}\n"
            . \trim($result['stdout'] . "\n" . $result['stderr']),
        );
    }

    $lines = \explode("\n", \trim($result['stdout']), 3);
    $reported = (string) ($lines[0] ?? '');
    if ($reported !== $version) {
        throw new \RuntimeException(
            "the prepared runtime starts as PHP {$reported}, not {$version}"
            . ' — the binary and the record beside it disagree',
        );
    }

    $ini = (string) ($lines[2] ?? '');
    $loadedOwnIni = $windows
        ? samePath($ini, $directory . '/php.ini')
        : \realpath($ini) === \realpath($directory . '/php.ini');
    if (! $loadedOwnIni) {
        throw new \RuntimeException(
            'the prepared runtime loaded `' . \trim($ini) . '` instead of its own php.ini'
            . "\n  " . ($windows
                ? 'a php.ini registered for another PHP on this machine takes precedence over the copy'
                : 'its launcher did not point PHP at the copy'),
        );
    }

    $loaded = \array_map(normalizeExtensionName(...), \explode(',', (string) ($lines[1] ?? '')));
    $missing = \array_values(\array_filter(
        $extensions,
        static fn (string $name): bool => ! \in_array($name, $loaded, true),
    ));
    if ($missing !== []) {
        throw new \RuntimeException(
            'the prepared runtime does not load ' . \implode(', ', $missing)
            . ' with an empty environment — its php.ini or its lib/ would not work on another machine',
        );
    }
}

/**
 * Whether two Windows paths name the same file.
 *
 * PHP reports the path it opened, which can differ from the one asked for in
 * case and in separators alone.
 */
function samePath(string $left, string $right): bool
{
    $normalize = static fn (string $path): string => \rtrim(
        \strtolower(\str_replace('\\', '/', \trim($path))),
        '/',
    );

    return $left !== '' && $normalize($left) === $normalize($right);
}

/**
 * Copy the prepared runtime to a directory the caller named.
 *
 * `--out` is a destructive target: whatever is there is replaced. A path that
 * names something larger than the copy — the directory the command was run in,
 * the runtime being copied, the cache itself, a filesystem root — is therefore
 * refused, and so is an empty one.
 */
function mirrorPortableRuntime(string $directory, string $out, bool $force): string
{
    $target = \rtrim($out, '/\\');
    if ($target === '' || $target === '.' || $target === '..') {
        throw new \RuntimeException('--out needs a directory to copy the runtime into, such as --out dist/php');
    }
    if (\is_file($target)) {
        throw new \RuntimeException("--out names a file ({$target}); it needs a directory to copy the runtime into");
    }

    $absolute = canonicalPath($target);
    if ($absolute === '/' || \preg_match('#^[a-z]:$#', $absolute) === 1) {
        throw new \RuntimeException("--out names a filesystem root ({$target}), which this will not replace");
    }
    if (pathIsWithin((string) \getcwd(), $absolute)) {
        throw new \RuntimeException(
            "--out names {$target}, which holds the directory this ran in; pick a directory of its own",
        );
    }
    if (pathIsWithin($absolute, $directory) || pathIsWithin($directory, $absolute)) {
        throw new \RuntimeException(
            "--out overlaps the runtime it would copy ({$directory}); pick a directory outside it",
        );
    }
    if (pathIsWithin($absolute, runtimeCacheDir())) {
        throw new \RuntimeException(
            '--out names a directory inside the runtime cache (' . runtimeCacheDir() . '); pick a directory of its own',
        );
    }

    if (\is_dir($target) && ! $force && \count(\scandir($target) ?: []) > 2) {
        throw new \RuntimeException(
            "--out names a directory that is not empty ({$target}); --force replaces it, and everything in it, with the runtime",
        );
    }

    removeSourceTree($target);
    copyPortableTree($directory, $target);

    return $target;
}

/**
 * A path with `.` and `..` folded, an absolute base, and no trailing separator.
 *
 * Comparison only: the string the caller typed is what gets created, so a
 * `--out` written as `dist/php` still lands under the project. A relative path is
 * resolved against the current directory, because that is what the copy does.
 */
function canonicalPath(string $path): string
{
    $path = \str_replace('\\', '/', $path);
    $drive = '';
    if (\preg_match('#^([A-Za-z]):#', $path, $match) === 1) {
        $drive = \strtoupper($match[1]) . ':';
        $path = \substr($path, 2);
    }
    if (! \str_starts_with($path, '/')) {
        $path = $drive === ''
            ? \str_replace('\\', '/', (string) \getcwd()) . '/' . $path
            : '/' . $path;
    }

    $parts = [];
    foreach (\explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            \array_pop($parts);

            continue;
        }
        $parts[] = $part;
    }

    $canonical = $drive . '/' . \implode('/', $parts);

    return \PHP_OS_FAMILY === 'Windows' ? \strtolower($canonical) : $canonical;
}

/** Whether a path names a directory or something below it. */
function pathIsWithin(string $path, string $directory): bool
{
    $path = canonicalPath($path);
    $directory = \rtrim(canonicalPath($directory), '/');
    if ($directory === '' || \preg_match('#^[a-z]:$#', $directory) === 1) {
        return true;
    }

    return $path === $directory || \str_starts_with($path, $directory . '/');
}

/** Copy the files of one directory into another, without its subdirectories. */
function copyFlat(string $from, string $to): void
{
    if (! \is_dir($from)) {
        return;
    }
    foreach (\scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        if (! \is_file($source) || \is_dir($source)) {
            continue;
        }
        if (! \copy($source, $to . '/' . $entry)) {
            throw new \RuntimeException("cannot copy {$source} into {$to}");
        }
    }
}

/** Recursively copy a runtime tree, following symlinks so a copy is self-contained. */
function copyPortableTree(string $from, string $to): void
{
    if (! \is_dir($to) && ! \mkdir($to, 0777, true) && ! \is_dir($to)) {
        throw new \RuntimeException("cannot create {$to}");
    }
    foreach (\scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;
        if (\is_dir($source) && ! \is_link($source)) {
            copyPortableTree($source, $target);

            continue;
        }
        if (! \copy($source, $target)) {
            throw new \RuntimeException("cannot copy {$source} to {$target}");
        }
        @\chmod($target, \fileperms($source) & 0777);
    }
}

/** The size of a directory tree, in bytes. */
function directorySize(string $directory): int
{
    $total = 0;
    $entries = @\scandir($directory) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . '/' . $entry;
        if (\is_dir($path) && ! \is_link($path)) {
            $total += directorySize($path);

            continue;
        }
        $size = @\filesize($path);
        if ($size !== false) {
            $total += $size;
        }
    }

    return $total;
}
