<?php declare(strict_types=1);

// Step-6A pin for the .NET foreign story.
//
// `foreign dotnet` is checked against the CLR itself: a reflection inspector built from shipped
// source answers over the compile's reference set, so (a) a member that does not exist is a
// compile error naming it. The sidecar still cannot override the declaration silently: (b) a flat
// entry that lists more parameters than the declaration passes is a compile error pointing at
// the object form, and (c) an object-form entry whose extras sit inside `optionalTail` is
// accepted, with the emitter padding the blessed extras in `ldnull`. The declaration still owns
// its types: a sidecar that retypes a declared parameter is refused — and (c) declares `Int32`
// because that is what `Parse` returns; the old `Int` spelling would fail the verdict, correctly.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

// The CLI's `--lib` shape: the bundled standard library as the primary, the
// probe root as an extra. The primary is what makes the scan see the extra at
// all (`libraryScanRoots` only walks extras beside a real library root).
\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');

$work = createTempDir('moggi-dotnet-foreign');

// The sidecar root is registered before any compile: `dotNetLibMetadata()`
// memoizes per process, so a root added between two compiles would be
// invisible to the second one.
$sidecarRoot = $work . '/sidecar-root';
\mkdir($sidecarRoot . '/dotnet', 0777, true);
\file_put_contents(
    $sidecarRoot . '/dotnet/probe.json',
    '{"assemblies": {"System.Runtime": {"signatures": {"parse": ["string", "class [System.Runtime]System.IFormatProvider"]}}}}',
);
\Moggi\Modules\addLibraryRoot($sidecarRoot);

try {
    // --- (a) a member the CLR does not have is a compile error -------------
    $probeA = $work . '/ProbeA.mog';
    \file_put_contents($probeA, <<<'MOGGI'
        module ProbeA

        foreign dotnet function noSuch "System.Math:NoSuchMethodAtAll" :: Int -> IO Int

        main :: IO ()
        main = do
          noSuch 1 >> putStrLn "done"
        MOGGI);

    $errorA = null;

    try {
        \Moggi\Compiler\compileFile($probeA, 'php', true, null, 'dotnet');
    } catch (\Moggi\Semantics\Types\TypeError $e) {
        $errorA = $e->getMessage();
    }
    $assert($errorA !== null, 'a member the CLR does not have must refuse the import');
    $assert(
        \str_contains($errorA, 'System.Math')
        && \str_contains($errorA, 'NoSuchMethodAtAll'),
        'the refusal names the host member: ' . $errorA,
    );

    // --- (b) a flat entry cannot bless extras: compile error, not `ldnull` ---
    $probeB = $work . '/ProbeB.mog';
    \file_put_contents($probeB, <<<'MOGGI'
        module ProbeB

        foreign dotnet function parse "System.Int32:Parse" :: String -> IO Int

        main :: IO ()
        main = do
          parse "123" >> putStrLn "done"
        MOGGI);

    $errorB = null;

    try {
        \Moggi\Compiler\compileFile($probeB, 'php', true, null, 'dotnet');
    } catch (\Moggi\Semantics\Types\TypeError $e) {
        $errorB = $e->getMessage();
    }
    $assert($errorB !== null, 'a flat sidecar entry with extras must refuse the import');
    $assert(
        \str_contains($errorB, 'a flat entry cannot bless extras')
        && \str_contains($errorB, '"optionalTail": N'),
        'the refusal points at the object form: ' . $errorB,
    );

    // --- (c) an object-form entry blesses its optional tail ------------------
    \file_put_contents(
        $sidecarRoot . '/dotnet/probe-blessed.json',
        '{"format": 1, "assemblies": {"System.Runtime": {"signatures":'
        . ' {"parseBlessed": {"optionalTail": 1, "params": ["string",'
        . ' "class [System.Runtime]System.IFormatProvider"]}}}}}',
    );
    $probeC = $work . '/ProbeC.mog';
    \file_put_contents($probeC, <<<'MOGGI'
        module ProbeC

        import Data.Int (Int32)

        foreign dotnet function parseBlessed "System.Int32:Parse" :: String -> IO Int32

        main :: IO ()
        main = do
          parseBlessed "123" >> putStrLn "done"
        MOGGI);

    // `dotNetLibMetadata()` memoizes per process, and the blessed file landed after the first
    // compile in this process — this is the same staleness the CLI never sees (one compile per
    // process), worked around here by running the probe in a fresh process.
    $probeScript = $work . '/probe-c.php';
    \file_put_contents($probeScript, <<<'PHP'
        <?php declare(strict_types=1);
        $root = $argv[1];
        require $root . '/src/compiler.php';
        require $root . '/tests/suite/support/workspace.php';
        \Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');
        \Moggi\Modules\addLibraryRoot($argv[3]);
        $il = '';
        foreach (\Moggi\Compiler\compileFile($argv[2], 'php', true, null, 'dotnet') as $relative => $bytes) {
            if (\is_string($relative) && \is_string($bytes) && \str_ends_with($relative, '.il')) {
                $il .= "\n" . $bytes;
            }
        }
        echo $il;
        PHP);
    $out = [];
    $code = 0;
    // `2>/dev/null` is POSIX; cmd.exe wants `2>nul` — and says so loudly
    // ("The system cannot find the path specified.") when it gets the former.
    $null = \PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
    \exec(
        \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($probeScript)
        . ' ' . \escapeshellarg($root) . ' ' . \escapeshellarg($probeC)
        . ' ' . \escapeshellarg($sidecarRoot) . ' 2>' . $null,
        $out,
        $code,
    );
    $ilC = \implode("\n", $out);
    $assert($code === 0, 'an object-form entry inside its optional tail must compile: ' . $ilC);
    $assert(
        \str_contains($ilC, 'System.Int32::Parse(string, class [System.Runtime]System.IFormatProvider)'),
        'the blessed tail is emitted on the call: ' . $ilC,
    );
    $assert(
        \str_contains($ilC, 'ldnull'),
        'the blessed-but-unpassed argument is padded with `ldnull`: ' . $ilC,
    );
} finally {
    removeDirectory($work);
}

echo "dotnet foreign behaviour tests passed\n";
