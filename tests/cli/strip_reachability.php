<?php declare(strict_types=1);

/**
 * What a whole-program strip removes, judged on the artifacts rather than on a program's output.
 *
 * The reachability rules themselves are pinned on hand-built IR in `tests/optimize/tree_shake.php`;
 * this case compiles a real project through `moggi compile`, so what is judged is the thing that
 * ships: the dead declarations are gone from the stripped build and still there under `--no-strip`,
 * the declarations the program does reach survive both, a module the shake has nothing to remove
 * from is byte-identical between the two builds, and the stripped build is smaller.
 *
 * The jvm units are compiled classes, so symbol absence is not readable from its artifact; that
 * backend's proof is the stripped program itself (`backend/runtime/exec-strip-reachability`), which
 * runs on all three targets.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require_once $root . '/src/compiler.php';
require_once $root . '/tests/suite/support/process.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$scratch = \sys_get_temp_dir() . '/moggi-strip-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
\mkdir($scratch, 0777, true);

$remove = static function (string $dir) use (&$remove): void {
    foreach (\scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        \is_dir($path) && ! \is_link($path) ? $remove($path) : @\unlink($path);
    }
    @\rmdir($dir);
};

$project = 'tests/backend/runtime/exec-strip-reachability';
$compile = static function (string $backend, bool $strip, string $out) use ($root, $project): void {
    $run = runCompiledProcess(
        [
            PHP_BINARY,
            $root . '/moggi.php',
            'compile',
            $project . '/Main.mog',
            '--unpacked',
            '--backend',
            $backend,
            ...($strip ? [] : ['--no-strip']),
            '-o',
            $out,
        ],
        180,
        null,
        $root,
    );
    if (($run['exitCode'] ?? 1) !== 0) {
        throw new \RuntimeException(
            "the {$backend} " . ($strip ? 'stripped' : 'unstripped') . " build failed:\n"
            . \trim(($run['stdout'] ?? '') . "\n" . ($run['stderr'] ?? '')),
        );
    }
};

$read = static function (string $dir, string $rel): string {
    $path = $dir . '/' . $rel;
    if (! \is_file($path)) {
        throw new \RuntimeException("the build wrote no {$rel}");
    }

    return (string) \file_get_contents($path);
};

$countFiles = static function (string $dir): int {
    $count = 0;
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            ++$count;
        }
    }

    return $count;
};

/** The unit each backend writes for a module, the dead name it must drop, and the live one it must keep. */
$units = [
    'php' => [
        'main' => [$project . '/Main.php', 'deadWeight', 'bump'],
        'helper' => [$project . '/Helper.php', 'spare', 'bump'],
        'untouched' => $project . '/Util.php',
    ],
    'dotnet' => [
        'main' => ['Moggi/Main.il', 'deadWeight', 'bump'],
        'helper' => ['Moggi/Helper.il', 'spare', 'bump'],
        'untouched' => 'Moggi/Util.il',
    ],
];

try {
    foreach ($units as $backend => $files) {
        $stripped = $scratch . '/' . $backend . '-stripped';
        $unstripped = $scratch . '/' . $backend . '-unstripped';
        // No `--strip`: the claim is that `moggi compile` strips unless it is told not to.
        $compile($backend, true, $stripped);
        $compile($backend, false, $unstripped);

        [$mainFile, $dead, $live] = $files['main'];
        $strippedMain = $read($stripped, $mainFile);
        $assert(! \str_contains($strippedMain, $dead), "{$backend}: the stripped entry still declares {$dead}");
        $assert(\str_contains($strippedMain, $live), "{$backend}: the stripped entry lost {$live}, which the program reaches");
        $assert(
            \str_contains($read($unstripped, $mainFile), $dead),
            "{$backend}: --no-strip did not keep {$dead}",
        );

        [$helperFile, $helperDead, $helperLive] = $files['helper'];
        $strippedHelper = $read($stripped, $helperFile);
        $assert(! \str_contains($strippedHelper, $helperDead), "{$backend}: the stripped module still declares {$helperDead}");
        $assert(\str_contains($strippedHelper, $helperLive), "{$backend}: the stripped module lost {$helperLive}, which another module calls");
        $assert(
            \str_contains($read($unstripped, $helperFile), $helperDead),
            "{$backend}: --no-strip did not keep {$helperDead}",
        );

        $assert(
            $read($stripped, $files['untouched']) === $read($unstripped, $files['untouched']),
            "{$backend}: a module with nothing to remove was rewritten by the shake",
        );
        $assert(
            $countFiles($stripped) < $countFiles($unstripped),
            "{$backend}: the stripped build wrote as many units as the unstripped one",
        );
    }
} finally {
    $remove($scratch);
}

echo "ok ({$checks} checks)\n";
