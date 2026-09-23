<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Dependencies;

use function Moggi\Modules\configuredStdlibLibPath;

/**
 * Library-owned JVM dependency directories: `jvm/` next to a module's
 * `.mog` sources, mirroring the `dotnet` (CLR metadata) and `php`
 * (companion sources) dependency directories.
 *
 * A library vendors its jars (and optional companion `.java`) there; the
 * backend discovers every such directory and demand-merges its classes
 * when emitted bytecode references the jar's packages. External
 * dependencies are still the job of Maven/Gradle — this only packages what
 * a library ships itself.
 */

/**
 * Merge third-party .class entries from stdlib vendor jvm dirs (any
 * lib/.../jvm/*.jar plus sibling *.java) when emitted bytecode references
 * packages from those jars. Demand-driven by ordinary FFI — no domain path
 * hardcodes.
 *
 * @param array<string, string> $classes
 * @param array<string, string> $resources
 */
function mergeDemandClasspathJars(array &$classes, array &$resources): void
{
    foreach (discoverVendorJvmDirs() as $jarDir) {
        $jars = glob($jarDir . DIRECTORY_SEPARATOR . '*.jar') ?: [];
        if ($jars === []) {
            continue;
        }
        $needles = vendorPackageNeedles($jars, $jarDir);
        if ($needles === [] || !bytecodeReferencesAnyNeedle($classes, $needles)) {
            continue;
        }
        foreach ($jars as $jarPath) {
            mergeJarClassEntries($jarPath, $classes, $resources);
        }
        compileAndMergeVendorJava($jarDir, $jars, $classes);
    }
}

/**
 * Absolute paths of lib/.../jvm dirs that contain jars or companion Java.
 *
 * @return list<string>
 */
function discoverVendorJvmDirs(): array
{
    $lib = configuredStdlibLibPath();
    if ($lib === null || $lib === '') {
        $fallback = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lib';
        $lib = \is_dir($fallback) ? $fallback : null;
    }
    if ($lib === null) {
        return [];
    }
    $dirs = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $name = $file->getFilename();
        if (!str_ends_with($name, '.jar') && !str_ends_with($name, '.java')) {
            continue;
        }
        $dir = $file->getPath();
        if (basename($dir) !== 'jvm') {
            continue;
        }
        $dirs[$dir] = true;
    }

    return \array_keys($dirs);
}

/**
 * Package / class path substrings that, if present in emitted bytecode, mean
 * this vendor dir must be merged (from jar entry names + sibling .java packages).
 *
 * @param list<string> $jars
 * @return list<string>
 */
function vendorPackageNeedles(array $jars, string $jarDir): array
{
    $needles = [];
    foreach ($jars as $jarPath) {
        $zip = new \ZipArchive();
        if ($zip->open($jarPath) !== true) {
            continue;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($name === '' || !str_ends_with($name, '.class') || str_contains($name, 'META-INF/')) {
                    continue;
                }
                $slash = strrpos($name, '/');
                if ($slash === false) {
                    continue;
                }
                // Use the top two package segments when present (stable demand gate).
                $parts = explode('/', substr($name, 0, $slash));
                if (count($parts) >= 2) {
                    $needles[$parts[0] . '/' . $parts[1] . '/'] = true;
                } elseif (count($parts) === 1) {
                    $needles[$parts[0] . '/'] = true;
                }
            }
        } finally {
            $zip->close();
        }
    }
    foreach (glob($jarDir . DIRECTORY_SEPARATOR . '*.java') ?: [] as $javaFile) {
        $src = (string) file_get_contents($javaFile);
        if (preg_match('/^\s*package\s+([\w.]+)\s*;/m', $src, $m) === 1) {
            $needles[str_replace('.', '/', $m[1]) . '/'] = true;
        }
    }

    return \array_keys($needles);
}

/**
 * @param array<string, string> $classes
 * @param list<string> $needles
 */
function bytecodeReferencesAnyNeedle(array $classes, array $needles): bool
{
    foreach ($classes as $name => $bytes) {
        if (\is_string($name)) {
            foreach ($needles as $needle) {
                if (str_starts_with($name, rtrim($needle, '/'))) {
                    return true;
                }
            }
        }
        if (!\is_string($bytes)) {
            continue;
        }
        foreach ($needles as $needle) {
            if (str_contains($bytes, $needle)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Compile library-owned `*.java` next to vendor jars into the app jar.
 * Same demand gate as the jars — not language RT.
 *
 * @param list<string> $jars
 * @param array<string, string> $classes
 */
function compileAndMergeVendorJava(string $jarDir, array $jars, array &$classes): void
{
    $javaFiles = glob($jarDir . DIRECTORY_SEPARATOR . '*.java') ?: [];
    if ($javaFiles === []) {
        return;
    }
    sort($javaFiles);
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moggi-jvm-vendor-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!mkdir($tmp, 0777, true) && !\is_dir($tmp)) {
        throw new \RuntimeException("cannot create vendor javac dir {$tmp}");
    }
    try {
        $cp = implode(PATH_SEPARATOR, $jars);
        $cmd = 'javac --release 21 -encoding UTF-8 -cp ' . escapeshellarg($cp)
            . ' -d ' . escapeshellarg($tmp);
        foreach ($javaFiles as $javaFile) {
            $cmd .= ' ' . escapeshellarg($javaFile);
        }
        $cmd .= ' 2>&1';
        $out = [];
        $code = 0;
        \exec($cmd, $out, $code);
        $outText = \implode("\n", $out);
        if ($code !== 0) {
            throw new \RuntimeException(
                "vendor javac failed (exit {$code}) under {$jarDir}: {$outText}",
            );
        }
        $built = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.class')) {
                continue;
            }
            $full = $file->getPathname();
            $rel = substr($full, strlen($tmp) + 1);
            $rel = str_replace('\\', '/', (string) $rel);
            $internal = substr($rel, 0, -strlen('.class'));
            if ($internal === false || $internal === '') {
                continue;
            }
            $bytes = \file_get_contents($full);
            if ($bytes === false) {
                throw new \RuntimeException("cannot read compiled {$full}");
            }
            if (mergeVendorClassEntry($classes, $internal, $bytes, "compiled {$jarDir}")) {
                $built[] = $internal;
            }
        }
        if ($built === []) {
            throw new \RuntimeException(
                "vendor javac produced no classes under {$jarDir}: {$outText}",
            );
        }
    } finally {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($tmp);
    }
}

function mergeJarClassEntries(string $jarPath, array &$classes, array &$resources): void
{
    $zip = new \ZipArchive();
    if ($zip->open($jarPath) !== true) {
        throw new \RuntimeException("cannot open classpath jar {$jarPath}");
    }
    try {
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (!str_ends_with($name, '.class')) {
                if (str_starts_with($name, 'META-INF/services/')) {
                    mergeVendorServiceFile($resources, $name, (string) $zip->getFromIndex($i));
                }
                continue;
            }
            $internal = substr($name, 0, -strlen('.class'));
            if ($internal === false || $internal === '') {
                continue;
            }
            mergeVendorClassEntry($classes, $internal, (string) $zip->getFromIndex($i), $jarPath);
        }
    } finally {
        $zip->close();
    }
}

/**
 * Merge a `META-INF/services/*` provider file. ServiceLoader reads every listed
 * provider, so overlapping jars contribute a union rather than one winning.
 *
 * @param array<string, string> $resources
 */
function mergeVendorServiceFile(array &$resources, string $name, string $incoming): void
{
    $existing = $resources[$name] ?? '';
    if ($existing === '') {
        $resources[$name] = $incoming;

        return;
    }
    $providers = [];
    foreach (\preg_split('/\r\n|\r|\n/', $existing . "\n" . $incoming) ?: [] as $line) {
        $line = \trim($line);
        if ($line !== '') {
            $providers[$line] = true;
        }
    }
    $resources[$name] = \implode("\n", \array_keys($providers)) . "\n";
}

/**
 * Merge one vendor-provided class entry. Identical duplicates are deduplicated;
 * a class provided by two sources with different bytecode is a hard error,
 * never a silent first-wins/last-wins pick.
 *
 * @param array<string, string> $classes
 * @return bool true when the entry was newly added
 */
function mergeVendorClassEntry(array &$classes, string $internal, string $bytes, string $source): bool
{
    if (isset($classes[$internal])) {
        if ($classes[$internal] !== $bytes) {
            throw new \RuntimeException(
                "conflicting JVM class `{$internal}` from {$source}; another source provides"
                . ' different bytecode for the same class. Resolve the dependency overlap',
            );
        }

        return false;
    }
    $classes[$internal] = $bytes;

    return true;
}
