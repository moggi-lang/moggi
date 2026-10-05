#!/usr/bin/env php
<?php declare(strict_types=1);

// `moggi compile --native` writes two artifacts — the packaged archive and the
// executable beside it — and they must never resolve to the same path, or the
// second move silently overwrites the first. This exercises the naming rule the
// CLI calls, including the extensionless `-o` that used to collide.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require_once $root . '/src/compiler.php';
require_once $root . '/tests/suite/support/process.php';

use function Moggi\CLI\Commands\artifactPrimaryRel;
use function Moggi\CLI\Commands\movePackagedArtifacts;
use function Moggi\Compiler\executableName;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$scratch = \sys_get_temp_dir() . '/moggi-compile-out-' . getmypid() . '-' . bin2hex(random_bytes(4));
\mkdir($scratch, 0777, true);

$remove = static function (string $dir) use (&$remove): void {
    foreach (\scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        \is_dir($path) && !\is_link($path) ? $remove($path) : @\unlink($path);
    }
    @\rmdir($dir);
};

$stage = static function (int $n, array $files) use ($scratch): string {
    $dir = $scratch . '/stage' . $n;
    \mkdir($dir, 0777, true);
    foreach ($files as $rel) {
        \file_put_contents($dir . '/' . $rel, 'x');
    }

    return $dir;
};

try {
    // A native php build: the archive and the executable, from both spellings of
    // the destination. Extensionless `-o Plain` used to put both at `Plain`.
    $withExtension = movePackagedArtifacts(
        $stage(1, ['moggi-app.phar', executableName('moggi-app')]),
        $scratch . '/App.phar',
        'php',
        true,
    );
    $assert(($withExtension['moggi-app.phar'] ?? null) === $scratch . '/App.phar', 'a `.phar` destination keeps the archive there');
    $assert(
        ($withExtension[executableName('moggi-app')] ?? null) === executableName($scratch . '/App'),
        'the executable sits beside the archive, extension stripped',
    );
    $assert(
        ($withExtension['moggi-app.phar'] ?? null) !== ($withExtension[executableName('moggi-app')] ?? null),
        'a `.phar` destination must not put the two artifacts on one path',
    );

    $extensionless = movePackagedArtifacts(
        $stage(2, ['moggi-app.phar', executableName('moggi-app')]),
        $scratch . '/Plain',
        'php',
        true,
    );
    $assert(
        ($extensionless['moggi-app.phar'] ?? null) === $scratch . '/Plain.phar',
        'an extensionless destination gives the archive the backend extension',
    );
    $assert(
        ($extensionless[executableName('moggi-app')] ?? null) === executableName($scratch . '/Plain'),
        'an extensionless destination names the executable',
    );
    $assert(
        ($extensionless['moggi-app.phar'] ?? null) !== ($extensionless[executableName('moggi-app')] ?? null),
        'an extensionless destination must not put the two artifacts on one path',
    );

    // Without --native there is one artifact, and `-o` still means exactly that path.
    $plain = movePackagedArtifacts($stage(3, ['moggi-app.phar']), $scratch . '/Solo', 'php', false);
    $assert(
        $plain === ['moggi-app.phar' => $scratch . '/Solo'],
        'a non-native build writes the archive to exactly `-o`, unchanged',
    );

    // The jvm and dotnet backends follow the same rule.
    $jar = movePackagedArtifacts($stage(4, ['moggi-app.jar', executableName('moggi-app')]), $scratch . '/J', 'jvm', true);
    $assert(($jar['moggi-app.jar'] ?? null) === $scratch . '/J.jar', 'an extensionless jvm destination gives the jar its extension');
    $assert(
        ($jar[executableName('moggi-app')] ?? null) === executableName($scratch . '/J'),
        'an extensionless jvm destination names the executable',
    );
    $assert(
        ($jar['moggi-app.jar'] ?? null) !== ($jar[executableName('moggi-app')] ?? null),
        'jvm artifacts must not share a path',
    );

    $dll = movePackagedArtifacts(
        $stage(5, ['moggi-app.dll', 'moggi-app.runtimeconfig.json', 'moggi-app.deps.json', 'Vendor.dll', executableName('moggi-app')]),
        $scratch . '/D',
        'dotnet',
        true,
    );
    $assert(($dll['Vendor.dll'] ?? null) === $scratch . '/Vendor.dll', 'a vendored .NET assembly travels out beside the app');
    $assert(($dll['moggi-app.dll'] ?? null) === $scratch . '/D.dll', 'an extensionless dotnet destination gives the library its extension');
    $assert(($dll['moggi-app.runtimeconfig.json'] ?? null) === $scratch . '/D.runtimeconfig.json', 'the dotnet sidecars follow the archive base');
    $assert(($dll['moggi-app.deps.json'] ?? null) === $scratch . '/D.deps.json', 'the deps sidecar follows the archive base');
    $assert(
        ($dll[executableName('moggi-app')] ?? null) === executableName($scratch . '/D'),
        'an extensionless dotnet destination names the executable',
    );
    $assert(
        ($dll['moggi-app.dll'] ?? null) !== ($dll[executableName('moggi-app')] ?? null),
        'dotnet artifacts must not share a path',
    );

    // A vendored assembly whose name is the archive's chosen destination is a
    // same-run clash: refused before anything moves, so nothing is clobbered.
    $threw = false;
    try {
        movePackagedArtifacts(
            $stage(6, ['moggi-app.dll', 'moggi-app.runtimeconfig.json', 'moggi-app.deps.json', 'Clash.dll']),
            $scratch . '/Clash.dll',
            'dotnet',
            false,
        );
    } catch (\RuntimeException $e) {
        $threw = \str_contains($e->getMessage(), 'written twice');
    }
    $assert($threw, 'a vendored assembly colliding with the archive destination must be refused');
    $assert(!\is_file($scratch . '/Clash.dll'), 'a refused clash must write nothing');

    // A destination that cannot be written is a failure, not a skipped line.
    \file_put_contents($scratch . '/blocker', 'x');
    $writeThrew = false;
    try {
        movePackagedArtifacts($stage(7, ['moggi-app.phar']), $scratch . '/blocker/App', 'php', false);
    } catch (\RuntimeException $e) {
        $writeThrew = \str_contains($e->getMessage(), 'cannot create directory');
    }
    $assert($writeThrew, 'an unwritable destination must fail, not be skipped');
    $assert(!\is_file($scratch . '/blocker/App'), 'a refused write must produce nothing');

    // A failure part way through must leave nothing this run created behind: the
    // archive would otherwise survive as a half-built artifact. A directory at
    // the executable's destination makes that second rename fail.
    \mkdir($scratch . '/Roll', 0777, true);
    \mkdir($scratch . '/Roll/Plain', 0777, true);
    $rollbackThrew = false;
    try {
        movePackagedArtifacts(
            $stage(8, ['moggi-app.phar', executableName('moggi-app')]),
            $scratch . '/Roll/Plain',
            'php',
            true,
        );
    } catch (\RuntimeException $e) {
        $rollbackThrew = \str_contains($e->getMessage(), 'cannot write');
    }
    $assert($rollbackThrew, 'a failed commit must fail the move');
    $assert(!\is_file($scratch . '/Roll/Plain.phar'), 'a failed move must roll back the artifact it already committed');
    $assert(\glob($scratch . '/Roll/*.moggi-tmp-*') === [], 'a failed move must leave no staging file behind');

    // A pre-existing destination the run legitimately replaced is never deleted:
    // removing it would lose the file `-o` asked to overwrite.
    \mkdir($scratch . '/Keep', 0777, true);
    \mkdir($scratch . '/Keep/Plain', 0777, true);
    \file_put_contents($scratch . '/Keep/Plain.phar', 'old');
    $keepThrew = false;
    try {
        movePackagedArtifacts(
            $stage(9, ['moggi-app.phar', executableName('moggi-app')]),
            $scratch . '/Keep/Plain',
            'php',
            true,
        );
    } catch (\RuntimeException $e) {
        $keepThrew = true;
    }
    $assert($keepThrew, 'the second rename still fails with a pre-existing archive');
    $assert(\is_file($scratch . '/Keep/Plain.phar'), 'a pre-existing destination must not be deleted by a rollback');

    // A native build's deliverable is the executable, so it is the primary
    // artifact; a managed build's is the archive.
    $assert(
        artifactPrimaryRel('php', true) === [executableName('moggi-app'), 'moggi-app.phar'],
        'a native php build leads with the executable',
    );
    $assert(
        artifactPrimaryRel('php') === ['moggi-app.phar', executableName('moggi-app')],
        'a managed php build leads with the archive',
    );
    $assert(
        artifactPrimaryRel('jvm', true) === [executableName('moggi-app'), 'moggi-app.jar'],
        'a native jvm build leads with the executable',
    );
    $assert(
        artifactPrimaryRel('dotnet', true) === [executableName('moggi-app'), 'moggi-app.dll', 'moggi-app.runtimeconfig.json', 'moggi-app.deps.json'],
        'a native dotnet build leads with the executable, sidecars last',
    );

    // A native executable is built from the packaged archive, so `--unpacked`
    // (which keeps the generated tree) is refused up front rather than failing
    // later on a missing PHAR.
    $app = $scratch . '/app';
    \mkdir($app, 0777, true);
    \file_put_contents($app . '/Main.mog', "main :: IO ()\nmain = putStrLn \"hi\"\n");
    $refused = runCompiledProcess(
        [PHP_BINARY, $root . '/moggi.php', 'compile', $app, '--native', '--unpacked', '-o', $scratch . '/out'],
        60,
        \getenv(),
        $root,
    );
    $assert(($refused['exitCode'] ?? 0) === 1, '--native with --unpacked must be refused');
    $assert(
        \str_contains($refused['stderr'], '--native cannot be combined with --unpacked'),
        'the refusal names both flags: ' . $refused['stderr'],
    );

    echo "compile output tests passed ({$checks} checks)\n";
} finally {
    if (\is_dir($scratch)) {
        $remove($scratch);
    }
}
