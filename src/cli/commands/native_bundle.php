<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\runProcess;

/**
 * A native executable beside the shared libraries it needs.
 *
 * A fully static build is not possible on every platform: macOS has no static
 * libSystem, and Windows PE binaries always import system DLLs. `--package` is
 * the portable fallback. It copies the executable and every *non-system*
 * shared library into one directory, so the recipient does not need the build
 * machine's package manager or a host runtime. System libraries (libc, libSystem,
 * kernel32, ...) are left to the operating system, which supplies them.
 *
 * Everything sits in one directory and the executable is relinked to look
 * there: Linux gets a `$ORIGIN` rpath, macOS gets `@loader_path` install names,
 * and Windows already searches the executable's directory. There is no
 * launcher to run and no environment variable to set.
 *
 *   app.bundle/
 *     app
 *     libstdc++.so.6, libicu*.so, ...   (only when needed)
 *     README.txt
 *     .moggi-bundle                    (marker, so a rerun may replace it)
 */
function bundleNativeExecutable(string $binary, ?string $sourceBinary = null): ?string
{
    if (! \is_file($binary)) {
        \fwrite(STDERR, "error: cannot package missing executable {$binary}\n");

        return null;
    }

    $bundle = $binary . '.bundle';
    $marker = $bundle . DIRECTORY_SEPARATOR . '.moggi-bundle';
    if (\is_link($bundle)) {
        \fwrite(STDERR, "error: refusing to replace {$bundle}: it is a symbolic link\n");

        return null;
    }
    if (\file_exists($bundle)) {
        if (! \is_file($marker)) {
            \fwrite(STDERR, "error: refusing to replace {$bundle}: it was not created by --package\n");

            return null;
        }
        removeBundleTree($bundle);
    }

    if (! \mkdir($bundle, 0777, true) && ! \is_dir($bundle)) {
        \fwrite(STDERR, "error: cannot create {$bundle}\n");

        return null;
    }

    $name = \basename($binary);
    $bundledBinary = $bundle . DIRECTORY_SEPARATOR . $name;
    if (! \copy($binary, $bundledBinary)) {
        \fwrite(STDERR, "error: cannot copy {$binary} into the bundle\n");
        removeBundleTree($bundle);

        return null;
    }
    if (\PHP_OS_FAMILY !== 'Windows') {
        @\chmod($bundledBinary, 0755);
    }

    try {
        $libraries = bundleNativeLibraries($sourceBinary ?? $binary, $bundledBinary, $bundle);
        $files = [
            $bundle . DIRECTORY_SEPARATOR . 'README.txt' => bundleReadme($name, $libraries),
            $marker => "moggi native bundle\n",
        ];
        foreach ($files as $path => $contents) {
            if (\file_put_contents($path, $contents) === false) {
                throw new \RuntimeException("cannot write {$path}");
            }
        }
    } catch (\RuntimeException $e) {
        removeBundleTree($bundle);

        \fwrite(STDERR, 'error: cannot package native executable: ' . $e->getMessage() . "\n");

        return null;
    }

    return $bundle;
}

/**
 * Copy the executable's non-system shared libraries, transitively, into the
 * bundle directory, then make every copied ELF/Mach-O file load its siblings
 * from that directory.
 *
 * Dependencies are read from the *source* file, not the copy. A library with
 * an `$ORIGIN` or `@loader_path` rpath resolves against its own directory, and
 * after copying it would look in the incomplete bundle before its siblings
 * have been copied. Reading the source keeps discovery independent of copy
 * order.
 *
 * @param string $sourceBinary the executable before it was copied
 * @param string $binary the executable inside the bundle
 * @return list<string>
 */
function bundleNativeLibraries(string $sourceBinary, string $binary, string $bundle): array
{
    if (\PHP_OS_FAMILY === 'Windows') {
        return bundleWindowsLibraries($sourceBinary, $binary, $bundle);
    }

    $queue = \PHP_OS_FAMILY === 'Darwin'
        ? darwinBundleLibraries($sourceBinary, \dirname($sourceBinary))
        : linuxBundleLibraries($sourceBinary);
    $copied = [];
    while ($queue !== []) {
        $path = \array_shift($queue);
        $name = \basename($path);
        $real = \realpath($path);
        if (isSystemBundleLibrary($name) || ($real !== false && isset($copied[$name]) && $copied[$name] === $real)) {
            continue;
        }
        if ($real === false || ! \is_file($real)) {
            throw new \RuntimeException("cannot find shared library {$path}");
        }
        if (isset($copied[$name])) {
            throw new \RuntimeException("two different libraries are both named {$name}");
        }

        $target = $bundle . DIRECTORY_SEPARATOR . $name;
        if (! \copy($real, $target)) {
            throw new \RuntimeException("cannot copy {$real}");
        }
        $copied[$name] = $real;

        foreach (\PHP_OS_FAMILY === 'Darwin'
            ? darwinBundleLibraries($real, \dirname($sourceBinary))
            : linuxBundleLibraries($real) as $dependency) {
            $queue[] = $dependency;
        }
    }

    if ($copied === []) {
        return [];
    }

    if (\PHP_OS_FAMILY === 'Darwin') {
        relinkDarwinBundle($binary, $bundle, $copied);
    } else {
        relinkLinuxBundle($binary, $bundle, $copied);
    }

    return \array_keys($copied);
}

/** @return list<string> */
function linuxBundleLibraries(string $binary): array
{
    $ldd = findExecutable('ldd');
    if ($ldd === null) {
        $readelf = findExecutable('readelf');
        if ($readelf !== null) {
            $dynamic = runProcess([$readelf, '-d', $binary]);
            if ($dynamic['exitCode'] !== 0 || \preg_match('/\(NEEDED\)/', $dynamic['stdout']) !== 1) {
                return [];
            }
        }

        throw new \RuntimeException('Linux needs `ldd` on PATH to list the executable\'s shared libraries');
    }

    $result = runProcess([$ldd, $binary]);
    if ($result['exitCode'] !== 0) {
        $output = $result['stdout'] . $result['stderr'];
        if (\preg_match('/not a dynamic executable|statically linked/i', $output) === 1) {
            return [];
        }

        throw new \RuntimeException('ldd failed: ' . \trim($output));
    }

    $paths = [];
    foreach (\explode("\n", $result['stdout']) as $line) {
        $path = null;
        if (\preg_match('/=>\s+(\S+)/', $line, $match) === 1) {
            $path = $match[1];
        } elseif (\preg_match('/^\s*(\/\S+)\s+\(0x[0-9a-f]+\)/i', $line, $match) === 1) {
            $path = $match[1];
        }
        if ($path === null || $path === '' || \str_contains($line, 'not found')) {
            if (\str_contains($line, 'not found')) {
                throw new \RuntimeException('a shared library is missing: ' . \trim($line));
            }

            continue;
        }
        $name = \basename($path);
        if (\str_starts_with($name, 'linux-vdso') || \str_starts_with($name, 'ld-linux') || \str_starts_with($name, 'ld64.')) {
            continue;
        }
        $paths[] = $path;
    }

    return $paths;
}

/** @return list<string> */
function darwinBundleLibraries(string $binary, ?string $executableDir = null): array
{
    $paths = [];
    $directory = \dirname($binary);
    $executableDir ??= $directory;
    foreach (darwinInstallNames($binary) as $path) {
        if (\str_starts_with($path, '/usr/lib/') || \str_starts_with($path, '/System/')) {
            continue;
        }
        if (\str_starts_with($path, '@loader_path/')) {
            $path = $directory . '/' . \substr($path, \strlen('@loader_path/'));
        } elseif (\str_starts_with($path, '@executable_path/')) {
            $candidate = $executableDir . '/' . \substr($path, \strlen('@executable_path/'));
            $path = \is_file($candidate) ? $candidate : $directory . '/' . \substr($path, \strlen('@executable_path/'));
        } elseif (\str_starts_with($path, '@rpath/')) {
            $candidate = $directory . '/' . \substr($path, \strlen('@rpath/'));
            $path = \is_file($candidate) ? $candidate : $directory . '/' . \basename($path);
        }
        $paths[] = $path;
    }

    return $paths;
}

/**
 * The install names exactly as recorded in the Mach-O load commands.
 *
 * `install_name_tool -change` matches its old name against these strings, not
 * against a resolved path: changing a dependency recorded as `@rpath/libx`
 * from `/build/libx` fails. Library discovery can resolve paths, but relinking
 * must retain the recorded spelling.
 *
 * @return list<string>
 */
function darwinInstallNames(string $binary): array
{
    $otool = findExecutable('otool');
    if ($otool === null) {
        throw new \RuntimeException('macOS needs `otool` (Xcode command line tools) to list the executable\'s libraries');
    }

    $result = runProcess([$otool, '-L', $binary]);
    if ($result['exitCode'] !== 0) {
        throw new \RuntimeException('otool failed: ' . \trim($result['stdout'] . $result['stderr']));
    }

    $names = [];
    $lines = \explode("\n", $result['stdout']);
    \array_shift($lines);
    foreach ($lines as $line) {
        $trimmed = \trim($line);
        if ($trimmed === '') {
            continue;
        }
        $path = \preg_split('/\s+\(/', $trimmed)[0] ?? '';
        if ($path !== '') {
            $names[] = $path;
        }
    }

    return $names;
}

/** @return list<string> */
function bundleWindowsLibraries(string $sourceBinary, string $binary, string $bundle): array
{
    $objdump = findExecutable('objdump') ?? findExecutable('llvm-objdump');
    $dumpbin = $objdump === null ? findExecutable('dumpbin') : null;
    if ($objdump === null && $dumpbin === null) {
        throw new \RuntimeException('Windows needs `objdump`, `llvm-objdump` or `dumpbin` on PATH to list DLL dependencies');
    }

    $queue = [$binary];
    $copied = [];
    $seen = [];
    $searchDirs = [\dirname($sourceBinary), $bundle];
    while ($queue !== []) {
        $file = \array_shift($queue);
        foreach (peDllImports($objdump, $dumpbin, $file) as $dll) {
            $key = \strtolower($dll);
            if (isSystemWindowsDll($dll) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $source = findWindowsDll($dll, $searchDirs);
            if ($source !== null && isSystemWindowsPath($source)) {
                continue;
            }
            if ($source === null) {
                if (isOptionalWindowsDll($dll) || isWindowsSystemDllOnDisk($dll)) {
                    continue;
                }

                throw new \RuntimeException("cannot find {$dll} beside the executable or on PATH");
            }
            $directory = \dirname($source);
            if (! \in_array($directory, $searchDirs, true)) {
                $searchDirs[] = $directory;
            }
            $target = $bundle . DIRECTORY_SEPARATOR . \basename($source);
            if (! \copy($source, $target)) {
                throw new \RuntimeException("cannot copy {$source}");
            }
            $copied[\strtolower(\basename($source))] = true;
            $queue[] = $target;
        }
    }

    return \array_keys($copied);
}

/**
 * Resolve a Windows import the way the loader does for this build: the
 * executable's directory first, then directories of DLLs already seen, then
 * PATH. Without the directory search a DLL sitting beside the executable —
 * the layout a native toolchain commonly produces — is missed.
 *
 * @param list<string> $directories
 */
function findWindowsDll(string $dll, array $directories): ?string
{
    foreach ($directories as $directory) {
        if ($directory === '') {
            continue;
        }
        $candidate = \rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $dll;
        if (\is_file($candidate)) {
            return $candidate;
        }
    }

    return findExecutable($dll);
}

/**
 * Whether an import is a Windows system DLL that was not on PATH.
 *
 * The allowlist above catches the common names, but Windows adds system DLLs
 * over time (`d3d11.dll`, `winspool.drv`, …). A file in System32/SysWOW64 is
 * supplied by the OS even when it is not listed or not on PATH, and throwing
 * for it would make a normal packaged application unbuildable.
 */
function isWindowsSystemDllOnDisk(string $dll): bool
{
    $root = \getenv('SystemRoot');
    if (! \is_string($root) || $root === '') {
        return false;
    }
    $root = \rtrim(\str_replace('/', '\\', $root), '\\');
    foreach (['System32', 'SysWOW64'] as $directory) {
        if (\is_file($root . '\\' . $directory . '\\' . $dll)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function peDllImports(?string $objdump, ?string $dumpbin, string $file): array
{
    if ($objdump !== null) {
        $result = runProcess([$objdump, '-p', $file]);
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException('objdump failed: ' . \trim($result['stdout'] . $result['stderr']));
        }
        \preg_match_all('/DLL Name:\s*(\S+)/i', $result['stdout'], $matches);

        return \array_values(\array_unique($matches[1]));
    }

    $result = runProcess([(string) $dumpbin, '/dependents', $file]);
    if ($result['exitCode'] !== 0) {
        throw new \RuntimeException('dumpbin failed: ' . \trim($result['stdout'] . $result['stderr']));
    }
    $dlls = [];
    foreach (\explode("\n", $result['stdout']) as $line) {
        if (\preg_match('/^\s+([\w.-]+\.dll)\s*$/i', $line, $match) === 1) {
            $dlls[] = $match[1];
        }
    }

    return \array_values(\array_unique($dlls));
}

function isSystemBundleLibrary(string $name): bool
{
    return \preg_match(
        '/^(libc|libm|libmvec|libdl|librt|libpthread|libutil|libresolv|libnsl|libcrypt|ld-linux|ld64|ld-musl|linux-vdso|libSystem|libc\+\+|libobjc)([.-]|$)/',
        $name,
    ) === 1;
}

function isSystemWindowsDll(string $name): bool
{
    $name = \strtolower($name);
    if (\str_starts_with($name, 'api-ms-win-') || \str_starts_with($name, 'ext-ms-win-')) {
        return true;
    }

    return \in_array($name, [
        'advapi32.dll', 'authz.dll', 'bcrypt.dll', 'bcryptprimitives.dll', 'combase.dll',
        'comdlg32.dll', 'crypt32.dll', 'dbghelp.dll', 'dnsapi.dll', 'dwmapi.dll',
        'gdi32.dll', 'gdiplus.dll', 'imm32.dll', 'iphlpapi.dll', 'kernel32.dll',
        'kernelbase.dll', 'msimg32.dll', 'mswsock.dll', 'msvcrt.dll', 'netapi32.dll',
        'normaliz.dll', 'ntdll.dll', 'ole32.dll', 'oleacc.dll', 'oleaut32.dll',
        'pdh.dll', 'powrprof.dll', 'propsys.dll', 'psapi.dll', 'rpcrt4.dll',
        'rstrtmgr.dll', 'secur32.dll', 'setupapi.dll', 'shcore.dll', 'shell32.dll',
        'shlwapi.dll', 'twinapi.appcore.dll', 'ucrtbase.dll', 'user32.dll',
        'userenv.dll', 'uxtheme.dll', 'version.dll', 'winhttp.dll', 'wininet.dll',
        'winmm.dll', 'wintrust.dll', 'wow64.dll', 'ws2_32.dll', 'wtsapi32.dll',
    ], true);
}

function isOptionalWindowsDll(string $name): bool
{
    return \preg_match('/^(dbghelp|version|wintrust|pdh|uxtheme|dwmapi)\.dll$/i', $name) === 1;
}

function isSystemWindowsPath(string $path): bool
{
    $windows = \getenv('SystemRoot');
    if (! \is_string($windows) || $windows === '') {
        return false;
    }
    $root = \rtrim(\str_replace('/', '\\', $windows), '\\');
    $path = \str_replace('/', '\\', $path);

    return \stripos($path, $root . '\\') === 0;
}

/**
 * Set `$ORIGIN` on the executable and every copied library, so each resolves
 * its siblings from the bundle directory regardless of where it is unpacked.
 *
 * @param array<string, string> $copied library name => source path
 */
function relinkLinuxBundle(string $binary, string $bundle, array $copied): void
{
    $patchelf = findExecutable('patchelf');
    if ($patchelf === null) {
        throw new \RuntimeException('Linux needs `patchelf` on PATH to make the bundled libraries load from the executable\'s directory');
    }

    $targets = [$binary];
    foreach (\array_keys($copied) as $name) {
        $targets[] = $bundle . DIRECTORY_SEPARATOR . $name;
    }
    foreach ($targets as $target) {
        $result = runProcess([$patchelf, '--set-rpath', '$ORIGIN', $target]);
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException('patchelf failed: ' . \trim($result['stdout'] . $result['stderr']));
        }
    }
}

/** @param array<string, string> $copied library name => source path */
function relinkDarwinBundle(string $binary, string $bundle, array $copied): void
{
    $tool = findExecutable('install_name_tool');
    if ($tool === null) {
        throw new \RuntimeException('macOS needs `install_name_tool` (Xcode command line tools) to relink the bundled libraries');
    }

    foreach ($copied as $name => $source) {
        $copy = $bundle . DIRECTORY_SEPARATOR . $name;
        foreach (darwinInstallNames($copy) as $dependency) {
            $base = \basename($dependency);
            if (! isset($copied[$base]) || $dependency === '@loader_path/' . $base) {
                continue;
            }
            $result = runProcess([$tool, '-change', $dependency, '@loader_path/' . $base, $copy]);
            if ($result['exitCode'] !== 0) {
                throw new \RuntimeException('install_name_tool failed: ' . \trim($result['stderr']));
            }
        }
        $result = runProcess([$tool, '-id', '@loader_path/' . $name, $copy]);
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException('install_name_tool failed: ' . \trim($result['stderr']));
        }
    }

    foreach (darwinInstallNames($binary) as $source) {
        $name = \basename($source);
        if (! isset($copied[$name]) || $source === '@loader_path/' . $name) {
            continue;
        }
        $result = runProcess([$tool, '-change', $source, '@loader_path/' . $name, $binary]);
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException('install_name_tool failed: ' . \trim($result['stderr']));
        }
    }

    $codesign = findExecutable('codesign');
    if ($codesign !== null) {
        $targets = [$binary];
        foreach (\array_keys($copied) as $name) {
            $targets[] = $bundle . DIRECTORY_SEPARATOR . $name;
        }
        foreach ($targets as $target) {
            $result = runProcess([$codesign, '--force', '--sign', '-', $target]);
            if ($result['exitCode'] !== 0) {
                throw new \RuntimeException('codesign failed: ' . \trim($result['stderr']));
            }
        }
    }
}

/** @param list<string> $libraries */
function bundleReadme(string $name, array $libraries): string
{
    $librariesText = $libraries === []
        ? "No non-system shared libraries were needed.\n"
        : "Bundled shared libraries (non-system):\n  " . \implode("\n  ", $libraries) . "\n";

    return "Moggi native bundle\n"
        . "===================\n\n"
        . "Run this directory's program with:\n\n"
        . "  ./{$name} [arguments]\n"
        . "  (on Windows: {$name} [arguments])\n\n"
        . "The executable loads the shared libraries in this directory. System\n"
        . "libraries are supplied by the operating system.\n\n"
        . $librariesText;
}

function removeBundleTree(string $dir): void
{
    if (! \is_dir($dir)) {
        @\unlink($dir);

        return;
    }

    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
    }
    @\rmdir($dir);
}
