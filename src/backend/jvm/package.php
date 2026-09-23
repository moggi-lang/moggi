<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm;

use function Moggi\Debug\sourceMapFrames;

use function Moggi\Backend\Jvm\Dependencies\mergeDemandClasspathJars;
use function Moggi\Backend\Jvm\Naming\moduleInternalName;

/**
 * JVM build finalization: assemble the collected classfiles into a runnable JAR.
 *
 * Mirrors the PHP `packagePhpOutput` and .NET `packageDotNetOutput`: the backend
 * class only collects build inputs, and this module turns the output directory
 * into the deployable artifact (with `--unpacked` leaving the class tree on
 * disk instead).
 */

/**
 * @param array<string, mixed> $options unpacked?, jarName?, entryModule?
 */
function packageJvmOutput(string $outputRoot, array $options = []): void
{
    // Language ABI (Platform / remaining RT for string_* and platform_* intrinsics).
    $classes = languageRuntime() + platformRuntime();
    $resources = [];
    $frameRows = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($outputRoot, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $full = $file->getPathname();
        $rel = substr($full, strlen(rtrim($outputRoot, DIRECTORY_SEPARATOR)) + 1);
        $rel = \str_replace('\\', '/', $rel);
        // Ignore packaging intermediates / prior jars sitting in the output tree.
        if (str_ends_with($rel, '.jar') || str_contains(basename($rel), '.tmp.')) {
            continue;
        }
        if ($file->getExtension() === 'class') {
            $internal = preg_replace('/\.class$/', '', $rel) ?? $rel;
            $classes[$internal] = file_get_contents($full);
            continue;
        }
        if (str_ends_with($rel, '.moggi.map')) {
            // Bake the frames; the map itself stays in the build tree, since
            // nothing reads it out of the jar.
            $raw = (string) file_get_contents($full);
            foreach (sourceMapFrames($raw) as $frame) {
                $key = (string) ($frame['class'] ?? '')
                    . '#' . (string) ($frame['method'] ?? '')
                    . '#' . (int) ($frame['line'] ?? 0);
                $text = (string) ($frame['text'] ?? '');
                if ($text !== '') {
                    $frameRows[] = ['key' => $key, 'text' => $text];
                }
            }
        }
    }

    // Host stack traces name the generated class/method/line; the baked
    // table translates them to `.mog` frames (path and column included).
    $classes['moggi/rt/Frames'] = buildFrames($frameRows);

    $entry = $options['entryModule'] ?? null;
    $mainClass = null;
    if (\is_string($entry) && $entry !== '') {
        $mainClass = \str_replace('/', '.', moduleInternalName($entry));
    } elseif (isset($classes['moggi/Main'])) {
        $mainClass = 'moggi.Main';
    }

    // Demand-driven: merge vendored jars (any lib's `jvm/` dir) when
    // emitted bytecode references packages from those jars.
    mergeDemandClasspathJars($classes, $resources);

    if (!empty($options['unpacked'])) {
        // Explicit unpacked/development build: leave the generated classes
        // and the runtime on disk (runnable with `java -cp <root> ...`);
        // jar packaging is skipped, like the PHAR in the PHP backend.
        foreach ($classes as $internal => $bytes) {
            if (!\is_string($bytes) || $bytes === '') {
                continue;
            }
            $dst = $outputRoot . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $internal) . '.class';
            $dstDir = \dirname($dst);
            if (!\is_dir($dstDir) && !mkdir($dstDir, 0777, true) && !\is_dir($dstDir)) {
                throw new \RuntimeException("cannot create {$dstDir}");
            }
            \file_put_contents($dst, $bytes);
        }

        return;
    }

    $jarName = $options['jarName'] ?? 'moggi-app.jar';
    $jarPath = $outputRoot . DIRECTORY_SEPARATOR . $jarName;
    writeJar($jarPath, $classes, $mainClass, $resources);
}

/**
 * Build a runnable JAR (ZIP) from classfile bytes (+ optional resource files for native embed).
 *
 * @param array<string, string> $classes internalName => bytes (e.g. moggi/rt/RT)
 * @param array<string, string> $resources jarEntryPath => bytes
 * @param ?string $mainClass dotted Main-Class (e.g. moggi.App)
 */
function writeJar(string $jarPath, array $classes, ?string $mainClass = null, array $resources = []): void
{
    $dir = dirname($jarPath);
    if ($dir !== '' && $dir !== '.' && !\is_dir($dir) && !@mkdir($dir, 0777, true) && !\is_dir($dir)) {
        throw new \RuntimeException("cannot create jar directory {$dir}");
    }

    // Write to a sibling temp file, then rename. ZipArchive::OVERWRITE can fail
    // on close() with "Renaming temporary file failed" when replacing an
    // existing jar in-place (seen under test packageOutput paths).
    $tmpPath = $jarPath . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (\is_file($tmpPath)) {
        @unlink($tmpPath);
    }

    $zip = new \ZipArchive();
    $opened = $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new \RuntimeException("cannot create jar {$tmpPath} (ZipArchive status {$opened})");
    }

    try {
        $manifest = "Manifest-Version: 1.0\r\nCreated-By: Moggi\r\n";
        if ($mainClass !== null && $mainClass !== '') {
            $manifest .= "Main-Class: {$mainClass}\r\n";
        }
        $manifest .= "\r\n";
        if (!$zip->addFromString('META-INF/MANIFEST.MF', $manifest)) {
            throw new \RuntimeException("cannot write jar manifest into {$tmpPath}");
        }

        foreach ($classes as $internal => $bytes) {
            if (!$zip->addFromString($internal . '.class', $bytes)) {
                throw new \RuntimeException("cannot add class {$internal} to {$tmpPath}");
            }
        }
        foreach ($resources as $entry => $bytes) {
            $name = ltrim(\str_replace('\\', '/', $entry), '/');
            if (!$zip->addFromString($name, $bytes)) {
                throw new \RuntimeException("cannot add resource {$name} to {$tmpPath}");
            }
        }

        if (!$zip->close()) {
            throw new \RuntimeException("cannot finalize jar {$tmpPath}");
        }
    } catch (\Throwable $e) {
        // close() may already have run; ignore secondary failures while cleaning up.
        try {
            @$zip->unchangeAll();
            @$zip->close();
        } catch (\Throwable) {
        }
        @unlink($tmpPath);
        throw $e;
    }

    if (\is_file($jarPath) && !@unlink($jarPath)) {
        @unlink($tmpPath);
        throw new \RuntimeException("cannot replace existing jar {$jarPath}");
    }
    if (!@rename($tmpPath, $jarPath)) {
        @unlink($tmpPath);
        throw new \RuntimeException("cannot move jar into place at {$jarPath}");
    }
}
