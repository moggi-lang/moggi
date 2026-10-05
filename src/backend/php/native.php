<?php declare(strict_types=1);

namespace Moggi\Backend\Php;

/**
 * Native PHP executables.
 *
 * The PHP counterpart of GraalVM's `native-image` for the JVM backend and Native
 * AOT for .NET: one standalone executable with no PHP installation behind it.
 * The mechanism is the micro SAPI's — a statically linked PHP runtime runs
 * whatever is appended to it, so a compiled PHAR plus an optional INI block *is*
 * the program:
 *
 *     [ micro.sfx ][ \xfd\xf6\x69\xe6 length INI ][ payload: .php or .phar ]
 *
 * The runtime is static-php-cli's micro SAPI, and a packaging run compiles it for
 * the target with that project's `spc` build tool (`packaging/runtimes.php`),
 * so it carries the same extensions as the bundled `php` runtime. A distribution
 * carries one under `runtime/php-native/`; `MOGGI_MICRO_SFX` names one anywhere
 * else, which is how a source checkout and the test suite reach it.
 *
 * One property of that runtime shapes the code here and in the CLI: it has no CLI
 * of its own — arguments go to the program, `PHP_BINARY` is empty, and
 * `phar.readonly` cannot be passed on a command line, only in the INI block.
 */

/** Names a micro runtime, or a directory holding one, for `resolveMicroSfx`. */
const MICRO_SFX_ENV = 'MOGGI_MICRO_SFX';

/** The runtime's file name, as static-php-cli's build writes it. */
const MICRO_SFX_NAME = 'micro.sfx';

/** Where a distribution puts the micro runtime, relative to the installation root. */
const MICRO_SFX_RUNTIME_DIR = 'php-native';

/** The INI injection object's leading magic. */
const MICRO_INI_MAGIC = "\xfd\xf6\x69\xe6";

/**
 * The micro runtime to build with: `$MOGGI_MICRO_SFX` (a file, or a directory
 * holding one) when set, else the one this installation bundles.
 */
function resolveMicroSfx(): ?string
{
    $named = \getenv(MICRO_SFX_ENV);
    if (\is_string($named) && $named !== '') {
        return microSfxAt($named);
    }

    foreach (microSfxInstallationRoots() as $root) {
        $bundled = microSfxAt($root . '/' . 'runtime' . '/' . MICRO_SFX_RUNTIME_DIR);
        if ($bundled !== null) {
            return $bundled;
        }
    }

    return null;
}

/** The micro runtime at a path that is either the runtime itself or a directory holding one. */
function microSfxAt(string $path): ?string
{
    if (\is_file($path)) {
        return $path;
    }

    $candidate = \rtrim($path, '/\\') . \DIRECTORY_SEPARATOR . MICRO_SFX_NAME;

    return \is_file($candidate) ? $candidate : null;
}

/**
 * The installations a bundled micro runtime can sit in: an explicit
 * `$MOGGI_ROOT`, then the installation this archive lives in
 * (`<installation>/bin/moggi.phar` → `<installation>/runtime/php-native`).
 *
 * @return list<string>
 */
function microSfxInstallationRoots(): array
{
    $roots = [];
    $envRoot = \getenv('MOGGI_ROOT');
    if (\is_string($envRoot) && $envRoot !== '') {
        $roots[] = \rtrim($envRoot, '/\\');
    }
    $installRoot = \Moggi\Install\installationRoot();
    if ($installRoot !== null) {
        $roots[] = $installRoot;
    }

    return $roots;
}

/**
 * The micro SAPI's INI injection object, or `''` for no directives.
 *
 * The SAPI reads its configuration from between the runtime and the program,
 * which is the only way to set a directive that cannot be changed once a process
 * runs — `phar.readonly`, the one the compiler archive needs, is such a
 * directive, and the runtime has no command line to pass it on.
 *
 * @param array<string, int|string> $directives
 */
function microIniObject(array $directives): string
{
    if ($directives === []) {
        return '';
    }

    $lines = [];
    foreach ($directives as $name => $value) {
        $lines[] = \is_int($value) ? "{$name}={$value}" : "{$name}=\"{$value}\"";
    }
    $text = \implode("\n", $lines);

    return MICRO_INI_MAGIC . \pack('N', \strlen($text)) . $text;
}

/**
 * Write a micro runtime with a program appended to it, as an executable.
 *
 * The three parts are copied into the output one after another rather than
 * concatenated in memory: a statically linked micro runtime is tens of
 * megabytes, and appending it to the program needs a second copy of it, which
 * the default `memory_limit` refuses.
 *
 * @param array<string, int|string> $directives
 */
function combineMicroPhar(string $sfxPath, string $payloadPath, string $outPath, array $directives = []): void
{
    if (!\is_file($sfxPath)) {
        throw new \RuntimeException("missing micro runtime {$sfxPath}");
    }
    if (!\is_file($payloadPath)) {
        throw new \RuntimeException("missing program {$payloadPath}");
    }

    $out = \fopen($outPath, 'wb');
    if ($out === false) {
        throw new \RuntimeException("cannot write {$outPath}");
    }

    try {
        appendFile($sfxPath, $out, $outPath);
        if (\fwrite($out, microIniObject($directives)) === false) {
            throw new \RuntimeException("cannot write {$outPath}");
        }
        appendFile($payloadPath, $out, $outPath);
    } finally {
        \fclose($out);
    }

    @\chmod($outPath, 0755);
}

/**
 * Copy a file into an open output stream without holding it in memory.
 *
 * @param resource $out
 */
function appendFile(string $source, $out, string $outPath): void
{
    $in = \fopen($source, 'rb');
    if ($in === false) {
        throw new \RuntimeException("cannot read {$source}");
    }

    try {
        if (\stream_copy_to_stream($in, $out) === false) {
            throw new \RuntimeException("cannot write {$outPath}");
        }
    } finally {
        \fclose($in);
    }
}

/**
 * `--native` on the PHP backend: append the packaged PHAR to a micro runtime.
 *
 * Mirrors `buildJvmNativeExecutable` and `buildDotNetNativeExecutable`: the
 * managed artifact is built first and this turns it into the executable beside
 * it, so `--native` never replaces a PHAR a caller may still want.
 */
function buildPhpNativeExecutable(string $outputRoot, string $pharName, string $binaryName): bool
{
    $sfx = resolveMicroSfx();
    if ($sfx === null) {
        \fwrite(
            STDERR,
            'error: micro PHP runtime not found (use a distribution that bundles PHP, or set ' . MICRO_SFX_ENV . ")\n",
        );

        return false;
    }

    $pharPath = $outputRoot . \DIRECTORY_SEPARATOR . $pharName;
    if (!\is_file($pharPath)) {
        \fwrite(STDERR, "error: missing {$pharPath}\n");

        return false;
    }

    try {
        combineMicroPhar($sfx, $pharPath, $outputRoot . \DIRECTORY_SEPARATOR . $binaryName);
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return false;
    }

    return true;
}
