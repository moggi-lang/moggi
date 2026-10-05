<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/../src/executables.php';

/**
 * Platform facts about the native binaries a distribution ships.
 *
 * A Linux artifact has to run on the oldest libc its release promises (the
 * glibc floor), and a PHP built from source links libraries the host is not
 * required to have, so they travel beside it and the loader is pointed at them.
 * Both are properties of the binary, not of the runtime, which is why they are
 * read here from the file itself rather than declared in `dist/runtimes.json`.
 */

/** The glibc floor the Linux distributions are built and checked against. */
function glibcFloor(array $config): string
{
    return (string) ($config['linuxGlibcFloor'] ?? '2.35');
}

function requiredGlibc(string $binary): ?string
{
    $objdump = \Moggi\Compiler\findExecutable('objdump');
    if ($objdump === null || !\is_file($binary)) {
        return null;
    }

    $result = runProcess([$objdump, '-T', $binary]);
    if ($result[0] !== 0) {
        return null;
    }

    $newest = null;
    if (\preg_match_all('/GLIBC_(\d+)\.(\d+)/', $result[1], $matches, \PREG_SET_ORDER) === false) {
        return null;
    }
    foreach ($matches as $match) {
        $version = $match[1] . '.' . $match[2];
        if ($newest === null || \version_compare($version, $newest, '>')) {
            $newest = $version;
        }
    }

    return $newest;
}

/**
 * Whether a file is a binary the platform's dynamic linker loads.
 *
 * The glibc floor is a property of what the loader resolves; a phar or a shell
 * script has no glibc requirement at all, so a runtime that is not a native
 * executable is not floor-checked.
 */
function isNativeBinary(string $path): bool
{
    if (!\is_file($path)) {
        return false;
    }
    $magic = (string) @\file_get_contents($path, false, null, 0, 4);

    return \str_starts_with($magic, "\x7fELF")
        || \in_array($magic, ["\xcf\xfa\xed\xfe", "\xce\xfa\xed\xfe", "\xfe\xed\xfa\xcf", "\xfe\xed\xfa\xce"], true);
}

function assertGlibcFloor(string $binary, string $floor, string $label): void
{
    $required = requiredGlibc($binary);
    if ($required === null) {
        throw new \RuntimeException("cannot read the glibc requirements of {$label} ({$binary}); is `objdump` installed?");
    }
    if (\version_compare($required, $floor, '>')) {
        throw new \RuntimeException(
            "{$label} requires glibc {$required}, above the {$floor} floor\n"
            . "  build it in a glibc {$floor} image (see .github/workflows/package.yml)",
        );
    }
}

function stripBinary(string $binary): void
{
    $strip = findExecutable('strip');
    if ($strip === null) {
        return;
    }
    runProcess([$strip, $binary]);
}

function copyNonBaselineLibraries(string $binary, string $libDir): void
{
    if (\PHP_OS_FAMILY === 'Windows') {
        return;
    }

    $baseline = '/^(libc|libm|libdl|librt|libpthread|libgcc_s|libstdc\+\+|libutil|libresolv|libnsl|ld-linux|linux-vdso|libSystem|libc\+\+|libobjc)([.-]|$)/';
    $darwin = \PHP_OS_FAMILY === 'Darwin';

    $queue = [];
    foreach ($darwin ? darwinLinkedLibraries($binary) : linuxLinkedLibraries($binary) as $path) {
        $queue[] = [$path, \dirname($binary)];
    }

    $copied = [];
    while ($queue !== []) {
        [$path, $referrer] = \array_shift($queue);
        $name = \basename($path);
        if (\preg_match($baseline, $name) === 1 || isset($copied[$name])) {
            continue;
        }
        [$resolved, $relative] = resolveLibraryReference($path, $referrer);
        $path = $resolved;
        if (!\is_file($path)) {
            if ($darwin && !$relative) {
                throw new \RuntimeException(
                    "cannot make the bundled PHP portable: {$name} is needed by {$referrer} and is not a file",
                );
            }
            continue;
        }
        if (!\is_dir($libDir) && !\mkdir($libDir, 0777, true) && !\is_dir($libDir)) {
            throw new \RuntimeException("cannot create {$libDir}");
        }
        if (!\copy($path, $libDir . '/' . $name)) {
            throw new \RuntimeException("cannot copy {$path}");
        }
        $real = \realpath($path);
        if (\is_string($real) && \basename($real) !== $name && \is_file($real)) {
            @\copy($real, $libDir . '/' . \basename($real));
        }
        $copied[$name] = $path;
        foreach ($darwin ? darwinLinkedLibraries($path) : linuxLinkedLibraries($path) as $dependency) {
            $queue[] = [$dependency, \dirname($path)];
        }
    }

    if ($copied === []) {
        return;
    }

    \fwrite(STDOUT, '    bundled libraries: ' . \implode(', ', \array_keys($copied)) . "\n");

    if (\PHP_OS_FAMILY === 'Darwin') {
        relinkDarwinLibraries($binary, $libDir, $copied);
    }
}

/** @param array<string, string> $copied library name => source path */
function relinkDarwinLibraries(string $binary, string $libDir, array $copied): void
{
    $tool = findExecutable('install_name_tool');
    if ($tool === null) {
        throw new \RuntimeException('macOS needs install_name_tool (part of the Xcode command line tools) to make the bundled PHP portable');
    }

    foreach ($copied as $name => $source) {
        $copy = $libDir . '/' . $name;
        foreach (darwinLinkedLibraries($copy) as $dependency) {
            $base = \basename($dependency);
            if (!isset($copied[$base]) || $dependency === '@loader_path/' . $base) {
                continue;
            }
            runProcess([$tool, '-change', $dependency, '@loader_path/' . $base, $copy]);
        }
        runProcess([$tool, '-id', '@loader_path/' . $name, $copy]);
        unset($source);
    }

    foreach ($copied as $name => $source) {
        runProcess([$tool, '-change', $source, '@loader_path/../lib/' . $name, $binary]);
    }
}

/**
 * Resolve a library reference to the file it means.
 *
 * macOS references a dylib by its `install_name`, which may be relative: `@loader_path` is the
 * directory of the file that makes the reference (not of the binary being fixed up), and Homebrew's
 * ICU uses it for the data library every ICU dylib needs. The second value says the reference was
 * relative, since one that an `rpath` resolves somewhere we cannot see is left alone rather than
 * reported as missing.
 *
 * @return array{string, bool} resolved path, whether the reference was relative
 */
function resolveLibraryReference(string $reference, string $referrer): array
{
    if (\str_starts_with($reference, '@loader_path/') || \str_starts_with($reference, '@rpath/')) {
        return [$referrer . '/' . \basename($reference), true];
    }

    return [$reference, false];
}

/** @return list<string> */
function linuxLinkedLibraries(string $binary): array
{
    [$code, $stdout] = runProcess(['ldd', $binary]);
    if ($code !== 0) {
        return [];
    }
    $paths = [];
    foreach (\explode("\n", $stdout) as $line) {
        if (\preg_match('/=>\s+(\S+)/', $line, $m) === 1) {
            $paths[] = $m[1];
        }
    }

    return $paths;
}

/** @return list<string> */
function darwinLinkedLibraries(string $binary): array
{
    [$code, $stdout] = runProcess(['otool', '-L', $binary]);
    if ($code !== 0) {
        return [];
    }
    $paths = [];
    foreach (\explode("\n", $stdout) as $line) {
        $trimmed = \trim($line);
        if ($trimmed === '' || \str_ends_with($trimmed, ':')) {
            continue;
        }
        $path = \preg_split('/\s+\(/', $trimmed)[0] ?? '';
        if (\str_starts_with($path, '/usr/lib/') || \str_starts_with($path, '/System/')) {
            continue;
        }
        $paths[] = $path;
    }

    return $paths;
}
