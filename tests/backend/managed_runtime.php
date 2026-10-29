<?php declare(strict_types=1);

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use function Moggi\Backend\DotNet\collectIlSources;
use function Moggi\Backend\DotNet\packageDotNetOutput;
use function Moggi\Backend\DotNet\resolveIlasmExecutable;
use function Moggi\Backend\Inspect\findJavapBinary;
use function Moggi\Compiler\runProcess;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/**
 * The runtime a managed backend ships is not source in a language a compiler checks: the JVM
 * runtime is classfile bytes assembled by the backend's own builder, and the .NET runtime is
 * ILASM text. This case is what holds both to a real toolchain.
 *
 * - .NET: the emitted project must declare the header first (the fast path already bundles it
 *   first; enumerating every IL file with a glob sorts `_header.il` after `Moggi/`, `examples/`,
 *   `libraries/` and `rt/`, so ilasm sees `[System.Runtime]System.Object` before the extern
 *   exists and warns "Reference to undeclared extern assembly … Attempting autodetect"), and the
 *   bundled runtime must assemble with no warnings at all.
 * - JVM: every runtime class must parse as a classfile and pass the verifier. `javap` reads the
 *   same bytes the verifier does but does not type-check the bytecode, so each class is launched
 *   under `-Xverify:all` and must stop at the missing `main` rather than at a `VerifyError`.
 */
$ilasm = resolveIlasmExecutable();
$javap = findJavapBinary();
if ($ilasm === null && $javap === null) {
    \fwrite(STDERR, "needs `ilasm` (the .NET backend's assembler) or `javap`, and this machine has neither\n");
    exit(2);
}

$work = createTempDir('moggi-managed-runtime');

if ($ilasm !== null) {
    $out = $work . '/dotnet';
    \mkdir($out, 0777, true);
    packageDotNetOutput($out, ['unpacked' => true, 'entryModule' => 'Main']);

    $project = (string) \file_get_contents($out . '/moggi-app.ilproj');
    $assert($project !== '', 'the .NET project must be written');

    $order = [];
    if (\preg_match_all('/<Compile\\s+Include="([^"]+)"/', $project, $matches) > 0) {
        $order = $matches[1];
    }
    $assert(
        ($order[0] ?? null) === '_header.il',
        'the .NET project must declare `_header.il` before any glob, or ilasm meets a reference '
        . 'before the extern that declares it; got ' . \var_export($order, true),
    );
    $assert(
        \count($order) > 1 && \preg_match('/<Compile\s+Include="[^"]*\*[^"]*"[^>]*Exclude="[^"]*_header\.il/', $project) === 1,
        'the project glob must exclude `_header.il`, so the header is declared once and first; got '
        . \var_export($order, true),
    );

    // The assembler is driven exactly as the backend's fast path drives it: its own source order,
    // without `-QUIET`, so any diagnostic is visible.
    $sources = collectIlSources($out);
    $assert(\count($sources) >= 3, 'the runtime and its header must be among the emitted IL sources');
    $bundle = $work . '/runtime.il';
    $text = '';
    foreach ($sources as $source) {
        $text .= (string) \file_get_contents($source);
        if (! \str_ends_with($text, "\n")) {
            $text .= "\n";
        }
    }
    \file_put_contents($bundle, $text);

    $dll = $work . '/Moggi.Rt.dll';
    $result = runProcess([$ilasm, '-NOLOGO', '-DLL', '-OUTPUT=' . $dll, $bundle], $work);
    $output = $result['stdout'] . "\n" . $result['stderr'];
    $assert(
        $result['exitCode'] === 0 && \is_file($dll),
        "ilasm must assemble the bundled runtime, exit {$result['exitCode']}:\n{$output}",
    );
    $assert(
        ! \str_contains(\strtolower($output), 'warning'),
        "the runtime must assemble without a single warning, got:\n{$output}",
    );
}

if ($javap !== null) {
    $classes = \Moggi\Backend\Jvm\languageRuntime() + \Moggi\Backend\Jvm\platformRuntime();
    $assert($classes !== [], 'the JVM runtime must emit classes');
    $dir = $work . '/jvm';
    $checked = 0;
    foreach ($classes as $internal => $bytes) {
        $path = $dir . '/' . $internal . '.class';
        if (! \is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0777, true);
        }
        \file_put_contents($path, $bytes);
        $result = runProcess([$javap, '-p', '-c', $path], $work);
        $assert(
            $result['exitCode'] === 0,
            "`{$internal}` must parse as a classfile:\n" . $result['stdout'] . "\n" . $result['stderr'],
        );
        ++$checked;
    }
    $assert($checked >= 20, "the JVM runtime should be the whole support library, parsed {$checked}");
    echo "jvm runtime classes parsed: {$checked}\n";

    // `javap` reads the classfile; the verifier is what type-checks the bytecode inside it. Launching
    // each class with `-Xverify:all` runs the verifier on it and stops at the missing `main`, which is
    // the cheapest way to verify bytecode there is no source for. The neighbouring `java` is the one
    // from the same JDK as `javap`.
    $java = \dirname($javap) . '/java';
    if (\is_file($java)) {
        $verified = 0;
        foreach ($classes as $internal => $bytes) {
            $binary = \str_replace('/', '.', $internal);
            $result = runProcess([$java, '-Xverify:all', '-cp', $dir, $binary], $work, null, false, 20);
            $diagnostics = $result['stdout'] . "\n" . $result['stderr'];
            $assert(
                ! \preg_match('/VerifyError|ClassFormatError|IncompatibleClassChangeError/', $diagnostics),
                "`{$internal}` must pass the JVM verifier:\n{$diagnostics}",
            );
            $assert(
                \str_contains($diagnostics, 'Main method not found') || $result['exitCode'] === 0,
                "`{$internal}` did not reach the missing-`main` stop, so it was never verified:\n{$diagnostics}",
            );
            ++$verified;
        }
        echo "jvm runtime classes verified: {$verified}\n";
    }
}

echo "managed runtime: " . ($ilasm !== null ? 'ilasm assembled it warning-free' : 'ilasm not available')
    . ', ' . ($javap !== null ? 'javap parsed it' : 'javap not available') . "\n";
