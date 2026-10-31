<?php declare(strict_types=1);

namespace Moggi\Backend\Inspect;

use Moggi\Backend\Backend;

use function Moggi\Backend\backendById;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\findToolchainExecutable;
use function Moggi\Compiler\runProcess;

require_once __DIR__ . '/Backend.php';
require_once __DIR__ . '/../executables.php';

/**
 * Shared emit-inspection helpers behind {@see Backend::describeEmit}.
 *
 * Each backend knows the shape of its own `emit()` result, so `:emit` in the
 * REPL never has to sniff file extensions: it asks the backend for a
 * human-readable dump. Everything here is pure rendering plus the small amount
 * of process plumbing (javap) and temp-file housekeeping that needs.
 */

/**
 * Emit entries whose relative path ends in $extension, in artifact order.
 *
 * `.moggi.map` sidecars (and any other companion) are never display artifacts.
 *
 * @param array<string, string> $emit
 * @return array<string, string>
 */
function selectArtifacts(array $emit, string $extension): array
{
    $out = [];
    foreach ($emit as $rel => $bytes) {
        if (! \is_string($rel) || ! \is_string($bytes)) {
            continue;
        }
        if (\str_ends_with($rel, '.moggi.map')) {
            continue;
        }
        if (\str_ends_with($rel, $extension)) {
            $out[$rel] = $bytes;
        }
    }

    return $out;
}

/**
 * Last-resort listing when no artifact matches the backend's display shape:
 * names and sizes, never the source map.
 *
 * @param array<string, string> $emit
 */
function describeArtifactList(array $emit): string
{
    $lines = [];
    foreach ($emit as $rel => $bytes) {
        if (! \is_string($rel) || ! \is_string($bytes) || \str_ends_with($rel, '.moggi.map')) {
            continue;
        }
        $lines[] = $rel . ' (' . \strlen($bytes) . ' bytes)';
    }

    return $lines === [] ? '' : \implode("\n", $lines) . "\n";
}

/**
 * PHP emits source text; the primary artifact is the module file itself
 * (host-owned companion `.php` files follow it in the map).
 *
 * @param array<string, string> $emit
 * @param list<string> $focusNames
 */
function describePhpEmit(array $emit, array $focusNames = []): string
{
    $artifacts = selectArtifacts($emit, '.php');
    if ($artifacts === []) {
        return describeArtifactList($emit);
    }

    return displayPhpEmit((string) \reset($artifacts), $focusNames);
}

/**
 * JVM emits `.class` bytes; disassemble them with `javap -c -p`, like the PHP
 * backend shows source.
 *
 * @param array<string, string> $emit
 * @param list<string> $focusNames
 */
function describeJvmEmit(array $emit, array $focusNames = []): string
{
    $classFiles = selectArtifacts($emit, '.class');
    if ($classFiles === []) {
        return describeArtifactList($emit);
    }

    return formatJavapDump($classFiles, $focusNames);
}

/**
 * .NET emits ILASM text (`.il`), the analogue of the JVM javap dump.
 *
 * @param array<string, string> $emit
 * @param list<string> $focusNames
 */
function describeDotNetEmit(array $emit, array $focusNames = []): string
{
    $ilFiles = selectArtifacts($emit, '.il');
    if ($ilFiles === []) {
        return describeArtifactList($emit);
    }

    return formatIlDump($ilFiles, $focusNames);
}

/**
 * Disassemble JVM class bytes with `javap -c -p`.
 *
 * @param array<string, string> $classFiles relative `.class` path => bytes
 * @param list<string> $focusNames Moggi binding names to keep (empty = all methods)
 */
function formatJavapDump(array $classFiles, array $focusNames = []): string
{
    $javap = findJavapBinary();
    if ($javap === null) {
        $lines = [];
        foreach ($classFiles as $rel => $bytes) {
            $lines[] = $rel . ' (' . \strlen($bytes) . ' bytes; install javap for bytecode dump)';
        }

        return \implode("\n", $lines) . "\n";
    }

    $dir = \sys_get_temp_dir() . '/moggi-javap-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
    if (! \mkdir($dir, 0777, true) && ! \is_dir($dir)) {
        throw new \RuntimeException("cannot create {$dir}");
    }

    try {
        $binaryNames = [];
        foreach ($classFiles as $rel => $bytes) {
            $path = $dir . '/' . $rel;
            $parent = \dirname($path);
            if (! \is_dir($parent) && ! \mkdir($parent, 0777, true) && ! \is_dir($parent)) {
                throw new \RuntimeException("cannot create {$parent}");
            }
            if (\file_put_contents($path, $bytes) === false) {
                throw new \RuntimeException("cannot write {$path}");
            }
            $binaryNames[] = \str_replace('/', '.', \preg_replace('/\.class$/', '', $rel) ?? $rel);
        }

        $cmd = [$javap, '-c', '-p', '-classpath', $dir, ...$binaryNames];
        $captured = runProcess($cmd, $dir);
        if ($captured['exitCode'] !== 0) {
            $err = \trim($captured['stderr'] !== '' ? $captured['stderr'] : $captured['stdout']);

            return ($err !== '' ? $err : 'javap failed') . "\n";
        }

        $text = stripJavapNoise($captured['stdout']);
        if ($focusNames === []) {
            return $text === '' || \str_ends_with($text, "\n") ? $text : $text . "\n";
        }

        $syms = [];
        foreach ($focusNames as $name) {
            $syms[] = backendById('jvm')->symbolName($name);
        }
        $filtered = filterJavapMethods($text, \array_values(\array_unique($syms)));

        return $filtered === '' || \str_ends_with($filtered, "\n") ? $filtered : $filtered . "\n";
    } finally {
        removeTree($dir);
    }
}

/**
 * Drop javap's per-class `Compiled from "…"` banner: the disassembly names the
 * class itself, so the source filename is noise in `:emit` output. The block
 * layout is made host-independent too: {@see canonicalizeJavapCodeBlocks}.
 */
function stripJavapNoise(string $javapOut): string
{
    $text = \preg_replace('/^Compiled from .*\R/m', '', $javapOut) ?? $javapOut;

    return canonicalizeJavapCodeBlocks($text);
}

/**
 * Fix javap's block layout so the same classfile dumps identically on every JDK.
 *
 * `javap -c` prints a method's bytecode under `Code:` with the instruction
 * offsets right-aligned in a fixed-width field, and the exception table beneath
 * them indented to match. JDK 24 widened that field from 8 columns to 10, so the
 * same `.class` prints two columns wider on a newer JDK. That is the host's
 * cosmetic layout, not the bytecode — but left alone it makes every
 * `*.jvm.emit.expected` golden depend on which JDK the suite ran on (CI pins
 * 21, a developer machine may carry anything newer).
 *
 * The canonical form is the 10-column field: a `Code:` block is shifted as a
 * whole by the difference its first instruction reveals, so the operand columns
 * inside an instruction, which javap already keeps stable, are untouched.
 */
function canonicalizeJavapCodeBlocks(string $text): string
{
    $lines = \preg_split('/\R/', $text) ?: [];
    $out = [];
    $inCode = false;
    $shift = null;

    foreach ($lines as $line) {
        if (\ltrim($line) === 'Code:') {
            $inCode = true;
            $shift = null;
            $out[] = $line;

            continue;
        }

        // A blank line, the closing brace, or the next member signature ends the block.
        $endsBlock = \trim($line) === ''
            || \preg_match('/^\s*}/', $line) === 1
            || \preg_match('/^  \S/', $line) === 1;
        if (! $inCode || $endsBlock) {
            $inCode = false;
            $out[] = $line;

            continue;
        }

        if ($shift === null && \preg_match('/^(\s*)(\d+):/', $line, $m) === 1) {
            $shift = 10 - \strlen($m[1]) - \strlen($m[2]);
        }
        if ($shift === null) {
            $out[] = $line;

            continue;
        }

        if ($shift >= 0) {
            $out[] = \str_repeat(' ', $shift) . $line;

            continue;
        }

        $leading = \strlen($line) - \strlen(\ltrim($line));
        $out[] = \substr($line, \min($leading, -$shift));
    }

    return \implode("\n", $out);
}

/**
 * Show emitted .NET IL — one `.il` type source per artifact; focus names keep
 * only matching members.
 *
 * @param array<string, string> $ilFiles relative `.il` path => IL source
 * @param list<string> $focusNames Moggi binding names to keep (empty = all)
 */
function formatIlDump(array $ilFiles, array $focusNames = []): string
{
    $parts = [];
    foreach ($ilFiles as $il) {
        $parts[] = \rtrim($il, "\n");
    }
    $text = \implode("\n\n", $parts) . "\n";

    if ($focusNames === []) {
        return $text;
    }

    $syms = [];
    foreach ($focusNames as $name) {
        $syms[] = backendById('dotnet')->symbolName($name);
    }
    $filtered = filterIlMethods($text, \array_values(\array_unique($syms)));

    return $filtered === '' || \str_ends_with($filtered, "\n") ? $filtered : $filtered . "\n";
}

/** `javap` from the configured JDK, else from `PATH`. */
function findJavapBinary(): ?string
{
    return findJavapBinaryWithSource()['binary'];
}

/**
 * `javap` and where it came from, so a diagnostic can say which binary answered.
 *
 * The build compiles against the JDK behind `JAVA_HOME` (or its aliases), so a
 * `javap` from anywhere else is not the build's toolchain — verification still
 * uses it, but says so.
 *
 * @return array{binary: ?string, source: ?string} the env var, `'PATH'`, or null
 */
function findJavapBinaryWithSource(): array
{
    foreach (['JAVA_HOME', 'GRAALVM_HOME', 'JDK_HOME'] as $env) {
        $candidate = findToolchainExecutable($env, 'javap');
        if ($candidate !== null) {
            return ['binary' => $candidate, 'source' => $env];
        }
    }

    $path = findExecutable('javap');

    return ['binary' => $path, 'source' => $path === null ? null : 'PATH'];
}

/**
 * Keep class headers + methods whose names appear in $methodNames.
 *
 * @param list<string> $methodNames
 */
function filterJavapMethods(string $javapOut, array $methodNames): string
{
    if ($methodNames === []) {
        return $javapOut;
    }

    $wanted = [];
    foreach ($methodNames as $m) {
        $wanted[$m] = true;
    }

    $lines = \preg_split('/\R/', $javapOut) ?: [];
    $out = [];
    $i = 0;
    $n = \count($lines);
    $keptAny = false;

    while ($i < $n) {
        while ($i < $n) {
            $out[] = $lines[$i];
            if (\str_contains($lines[$i], '{')) {
                $i++;

                break;
            }
            $i++;
        }

        while ($i < $n) {
            $line = $lines[$i];
            if (\preg_match('/^\s*\}/', $line) === 1) {
                $out[] = $line;
                $i++;
                if ($i < $n && \trim($lines[$i]) === '') {
                    $out[] = $lines[$i];
                    $i++;
                }

                break;
            }

            if (\preg_match('/^  \S/', $line) === 1 && \str_contains($line, '(')) {
                $block = [$line];
                $i++;
                while ($i < $n) {
                    $next = $lines[$i];
                    if (\preg_match('/^\s*\}/', $next) === 1) {
                        break;
                    }
                    if (\preg_match('/^  \S/', $next) === 1 && \str_contains($next, '(')) {
                        break;
                    }
                    $block[] = $next;
                    $i++;
                }
                $sig = $block[0];
                $match = false;
                foreach ($wanted as $method => $_) {
                    if (\preg_match('/\b' . \preg_quote($method, '/') . '\s*\(/', $sig) === 1) {
                        $match = true;

                        break;
                    }
                }
                if ($match) {
                    foreach ($block as $b) {
                        $out[] = $b;
                    }
                    $keptAny = true;
                }

                continue;
            }

            $i++;
        }
    }

    if (! $keptAny) {
        return $javapOut;
    }

    return \implode("\n", $out);
}

/**
 * Keep `.class` headers + `.method` blocks whose names appear in $methodNames.
 *
 * @param list<string> $methodNames ILASM identifiers (e.g. `'foo'`)
 */
function filterIlMethods(string $il, array $methodNames): string
{
    if ($methodNames === []) {
        return $il;
    }

    $wanted = [];
    foreach ($methodNames as $m) {
        $wanted[$m] = true;
    }

    $lines = \preg_split('/\R/', $il) ?: [];
    $out = [];
    $i = 0;
    $n = \count($lines);
    $keptAny = false;

    while ($i < $n) {
        $line = $lines[$i];
        if (\preg_match('/^\s*\.method\b/', $line) === 1) {
            $block = [$line];
            $i++;
            while ($i < $n && \preg_match('/^\s*\}/', $lines[$i]) !== 1) {
                $block[] = $lines[$i];
                $i++;
            }
            if ($i < $n) {
                $block[] = $lines[$i];
                $i++;
            }
            while ($i < $n && \trim($lines[$i]) === '') {
                $block[] = $lines[$i];
                $i++;
            }

            $match = false;
            foreach ($wanted as $method => $_) {
                if (\preg_match('/' . \preg_quote($method, '/') . '\s*\(/', $line) === 1) {
                    $match = true;

                    break;
                }
            }
            if ($match) {
                foreach ($block as $b) {
                    $out[] = $b;
                }
                $keptAny = true;
            }

            continue;
        }

        if (\trim($line) !== '') {
            $out[] = $line;
        }
        $i++;
    }

    if (! $keptAny) {
        return $il;
    }

    return \implode("\n", $out);
}

/**
 * PHP emit for display: the named bindings, or the whole module with its
 * boilerplate reduced (see {@see presentPhpModule}).
 *
 * @param list<string> $focusNames
 */
function displayPhpEmit(string $emit, array $focusNames): string
{
    if (! \str_starts_with(\ltrim($emit), '<?php')) {
        return $emit;
    }
    if ($focusNames === []) {
        return presentPhpModule($emit);
    }

    $phpNames = [];
    foreach ($focusNames as $name) {
        $phpNames[] = backendById('php')->symbolName($name);
    }
    $phpNames = \array_values(\array_unique($phpNames));
    $chunks = [];
    foreach ($phpNames as $phpName) {
        $extracted = extractPhpFunction($emit, $phpName);
        if ($extracted !== null) {
            $chunks[] = $extracted;
        }
    }
    if ($chunks === []) {
        return $emit;
    }

    return \implode("\n\n", $chunks) . "\n";
}

/**
 * Reduce a PHP module to what is worth reading: its namespace and declarations.
 *
 * A module opens with `<?php declare(strict_types=1);`, then a `require_once`
 * per imported module plus a `use function` alias for every imported binding —
 * hundreds of lines for anything that imports Prelude. None of that is the code
 * being read, and the javap / .NET IL dumps have no equivalent, so the preamble
 * goes (with the blank line that opens the file after it), and the import
 * block goes with it. Everything else keeps the layout the emitter gave it.
 */
function presentPhpModule(string $emit): string
{
    $lines = \preg_split('/\R/', $emit) ?: [];

    $first = null;
    $last = null;
    foreach ($lines as $i => $line) {
        if (! isPhpImportLine($line)) {
            continue;
        }
        $first ??= $i;
        $last = $i;
    }

    $kept = [];
    $afterPreamble = false;
    foreach ($lines as $i => $line) {
        if (\preg_match('/^\s*<\?php/', $line) === 1) {
            $afterPreamble = true;

            continue;
        }
        if ($afterPreamble) {
            $afterPreamble = false;
            if ($line === '') {
                continue;
            }
        }
        if ($first !== null && $i >= $first && $i <= $last) {
            if ($line === '' || isPhpImportLine($line)) {
                continue;
            }
        }
        $kept[] = $line;
    }

    return \implode("\n", $kept);
}

/** A generated module's import line: `require_once …` or `use function …`. */
function isPhpImportLine(string $line): bool
{
    return \preg_match('/^\s*(?:require_once|use function)\s/', $line) === 1;
}

function extractPhpFunction(string $php, string $functionName): ?string
{
    if (! \preg_match(
        '/^function\s+' . \preg_quote($functionName, '/') . '\s*\(/m',
        $php,
        $m,
        PREG_OFFSET_CAPTURE,
    )) {
        return null;
    }
    $start = (int) $m[0][1];
    $brace = \strpos($php, '{', $start);
    if ($brace === false) {
        return null;
    }
    $depth = 0;
    $len = \strlen($php);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $php[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return \rtrim(\substr($php, $start, $i - $start + 1));
            }
        }
    }

    return null;
}

/** Recursively delete a directory tree. */
function removeTree(string $dir): void
{
    if (! \is_dir($dir)) {
        return;
    }
    $items = \scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (\is_dir($path)) {
            removeTree($path);
        } else {
            @\unlink($path);
        }
    }
    @\rmdir($dir);
}
