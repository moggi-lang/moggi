#!/usr/bin/env php
<?php declare(strict_types=1);

// A package the registry marks bad is refused through the real CLI, not only in
// the helper: `install` and `build` stop with the reason the registry published,
// `--allow-bad` is the one way past them and says what it let through, and
// `show` / `search` surface the marker for the packages a user is looking at.
// The registry is signed with the checkout's own `schnorr`; without that binary
// the signing half cannot run and the checks are reported as skipped rather than
// faked.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$binary = \getenv('MOGGI_SCHNORR');
if (!\is_string($binary) || $binary === '') {
    $binary = $root . '/schnorr/schnorr';
}
if (!\is_file($binary) || !\is_executable($binary)) {
    $binary = $root . '/schnorr/build/schnorr';
}
if (!\is_file($binary) || !\is_executable($binary)) {
    echo "bad package CLI tests skipped (no schnorr CLI; set MOGGI_SCHNORR)\n";
    exit(0);
}

$schnorr = static function (array $arguments, ?string $stdin = null) use ($binary): string {
    $process = \proc_open(
        \array_merge([$binary], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!\is_resource($process)) {
        throw new \RuntimeException("cannot run {$binary}");
    }
    if ($stdin !== null) {
        \fwrite($pipes[0], $stdin . "\n");
    }
    \fclose($pipes[0]);
    $stdout = (string) \stream_get_contents($pipes[1]);
    $stderr = (string) \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $code = \proc_close($process);
    if ($code !== 0) {
        throw new \RuntimeException('schnorr: ' . (\trim($stderr) !== '' ? \trim($stderr) : "exited {$code}"));
    }

    return \trim($stdout);
};

$work = createTempDir('moggi-bad-cli');
$env = \getenv();
$env['MOGGI_SCHNORR'] = $binary;
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
$env['MOGGI_USER_CACHE'] = $work . '/user-cache';
unset($env['MOGGI_REGISTRY_NPUB'], $env['MOGGI_ALLOW_STALE_REGISTRY']);

$cli = static function (array $arguments, string $cwd) use ($root, $env): array {
    return runCompiledProcess([PHP_BINARY, $root . '/moggi.php', ...$arguments], 120, $env, $cwd);
};

try {
    [$nsec, $npub] = \preg_split('/\s+/', $schnorr(['generate'])) ?: ['', ''];
    $assert(\str_starts_with($nsec, 'nsec') && \str_starts_with($npub, 'npub'), 'the schnorr CLI generated a keypair');

    $writeRegistry = static function (string $dir, string $npub, string $nsec, array $packages) use ($schnorr): void {
        if (\is_dir($dir)) {
            removeDirectory($dir);
        }
        \mkdir($dir . '/catalog', 0777, true);

        $shards = [];
        $grouped = [];
        foreach ($packages as $name => $entry) {
            $prefix = \substr((string) $name, 0, 2);
            $grouped[$prefix] ??= ['format' => 1, 'prefix' => $prefix, 'packages' => []];
            $grouped[$prefix]['packages'][$name] = $entry;
        }
        foreach ($grouped as $prefix => $shard) {
            $bytes = \json_encode($shard, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
            \file_put_contents($dir . '/catalog/' . $prefix . '.json', $bytes);
            $shards[$prefix] = 'sha256:' . \hash('sha256', $bytes);
        }

        $root = [
            'format' => 1,
            'generated' => \gmdate('Y-m-d\TH:i:s\Z'),
            'version' => 1,
            'expires' => \gmdate('Y-m-d\TH:i:s\Z', \time() + 3600),
            'registry_npub' => $npub,
            'admins' => [$npub],
            'maintainers' => [$npub],
            'shards' => $shards,
        ];
        $bytes = \json_encode($root, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
        \file_put_contents($dir . '/index.json', $bytes);
        \file_put_contents($dir . '/index.json.sig', $schnorr(['sign', \hash('sha256', $bytes)], $nsec) . "\n");
    };

    $registry = $work . '/registry';
    $project = $work . '/project';
    \mkdir($project . '/src', 0777, true);
    \file_put_contents(
        $project . '/app.moggi',
        "[package]\nname = app\nversion = 1.0.0\n\n[author]\nname = Test\nemail = t@example.com\nnpub = {$npub}\n\n"
        . "[executable]\nmain = Main\nsource-dirs = src\nbackend = php\n\n"
        . "[dependencies]\nevil = >=1.0\nstale = >=1.0\nok = >=1.0\n",
    );
    \file_put_contents($project . '/src/Main.mog', "module Main\n\nmain = 0\n");

    $writeRegistry($registry, $npub, $nsec, [
        'evil' => [
            'owner' => $npub,
            'authors' => [$npub],
            'latest' => '1.0.0',
            'description' => 'a package pulled from the registry',
            'digest' => 'sha256:' . \str_repeat('e', 64),
            'bad' => ['reason' => 'RCE in the decoder', 'at' => '2026-10-05', 'by' => $npub],
            'versions' => ['1.0.0' => ['deps' => []]],
        ],
        'ok' => [
            'owner' => $npub,
            'authors' => [$npub],
            'latest' => '1.0.0',
            'description' => 'an ordinary package',
            'digest' => 'sha256:' . \str_repeat('o', 64),
            'versions' => ['1.0.0' => ['deps' => []]],
        ],
        'stale' => [
            'owner' => $npub,
            'authors' => [$npub],
            'latest' => '1.0.0',
            'description' => 'a package nobody maintains',
            'digest' => 'sha256:' . \str_repeat('s', 64),
            'unmaintained' => ['at' => '2026-09-01', 'note' => 'no releases since 0.9'],
            'versions' => ['1.0.0' => ['deps' => []]],
        ],
    ]);

    // `install` refuses the marked package, names the reason and the way past.
    $refused = $cli(['install', '--dry-run', '--registry', $registry, '--no-cache'], $project);
    $assert($refused['exitCode'] === 1, 'install of a marked package must fail: ' . $refused['stdout']);
    $assert(\str_contains($refused['stderr'], 'marked bad'), 'the refusal names the package as bad: ' . $refused['stderr']);
    $assert(\str_contains($refused['stderr'], 'RCE in the decoder'), 'the refusal carries the reason the registry published: ' . $refused['stderr']);
    $assert(\str_contains($refused['stderr'], '--allow-bad'), 'the refusal names the way past: ' . $refused['stderr']);

    // `--allow-bad` is that way past, and it warns rather than staying silent.
    $allowed = $cli(['install', '--dry-run', '--allow-bad', '--registry', $registry, '--no-cache'], $project);
    $assert($allowed['exitCode'] === 0, '--allow-bad must let the install through: ' . $allowed['stderr']);
    $assert(\str_contains($allowed['stdout'], 'would install'), 'an allowed install proceeds: ' . $allowed['stdout']);
    $assert(\str_contains($allowed['stderr'], 'warning:'), 'an allowed install still warns: ' . $allowed['stderr']);
    $assert(!\str_contains($allowed['stderr'], 'pass --allow-bad'), 'an allowed install is not refused: ' . $allowed['stderr']);
    $assert(\str_contains($allowed['stderr'], 'marked unmaintained'), 'an allowed install warns about an unmaintained package: ' . $allowed['stderr']);

    // `update` refuses to write a lock that names a marked package, and leaves no
    // file behind; `--allow-bad` is the way past it here too.
    $updateRefused = $cli(['update', '--registry', $registry, '--no-cache'], $project);
    $assert($updateRefused['exitCode'] === 1, 'update of a marked package must fail: ' . $updateRefused['stderr']);
    $assert(\str_contains($updateRefused['stderr'], 'marked bad'), 'the update refusal names the package as bad: ' . $updateRefused['stderr']);
    $assert(!\is_file($project . '/moggi.lock'), 'a refused update must write no lock: ' . $updateRefused['stderr']);

    $lock = $cli(['update', '--allow-bad', '--registry', $registry, '--no-cache'], $project);
    $assert($lock['exitCode'] === 0 && \is_file($project . '/moggi.lock'), 'update --allow-bad must write a lock: ' . $lock['stderr']);
    $assert(\str_contains($lock['stderr'], 'warning:'), 'an allowed update still warns: ' . $lock['stderr']);

    $buildRefused = $cli(['build', '--registry', $registry, '--no-cache'], $project);
    $assert($buildRefused['exitCode'] === 1, 'build linking a marked package must fail: ' . $buildRefused['stdout']);
    $assert(\str_contains($buildRefused['stderr'], 'links as bad'), 'the build refusal says it links the package: ' . $buildRefused['stderr']);
    $assert(\str_contains($buildRefused['stderr'], 'pass --allow-bad'), 'the build refusal names the way past: ' . $buildRefused['stderr']);

    $buildAllowed = $cli(['build', '--allow-bad', '--registry', $registry, '--no-cache'], $project);
    $assert(\str_contains($buildAllowed['stderr'], 'warning:'), 'an allowed build warns about the marked package: ' . $buildAllowed['stderr']);
    $assert(!\str_contains($buildAllowed['stderr'], 'links as bad'), 'an allowed build is not refused for linking it: ' . $buildAllowed['stderr']);

    // `show` prints the marker first; `search` flags the row.
    $show = $cli(['show', 'evil', '--registry', $registry, '--no-cache'], $project);
    $assert($show['exitCode'] === 0, 'show of a known package succeeds: ' . $show['stderr']);
    $assert(\str_contains($show['stdout'], 'BAD'), 'show surfaces the bad marker: ' . $show['stdout']);
    $assert(\str_contains($show['stdout'], 'RCE in the decoder'), 'show carries the reason: ' . $show['stdout']);

    $search = $cli(['search', 'ordinary', '--registry', $registry, '--no-cache'], $project);
    $assert($search['exitCode'] === 0 && \str_contains($search['stdout'], 'ok'), 'search finds the unmarked package: ' . $search['stdout']);
    $assert(!\str_contains($search['stdout'], 'BAD'), 'an unmarked search row is not flagged: ' . $search['stdout']);

    $searchBad = $cli(['search', 'pulled', '--registry', $registry, '--no-cache'], $project);
    $assert(\str_contains($searchBad['stdout'], 'evil') && \str_contains($searchBad['stdout'], 'BAD'), 'search flags the marked package: ' . $searchBad['stdout']);

    // The unmaintained marker travels the same read path, but warns rather than
    // refusing: `show` and `search` display it, and the lock commands note it.
    $showStale = $cli(['show', 'stale', '--registry', $registry, '--no-cache'], $project);
    $assert($showStale['exitCode'] === 0, 'show of an unmaintained package succeeds: ' . $showStale['stderr']);
    $assert(\str_contains($showStale['stdout'], 'UNMAINTAINED'), 'show surfaces the unmaintained marker: ' . $showStale['stdout']);
    $assert(\str_contains($showStale['stdout'], 'no releases since 0.9'), 'show carries the unmaintained note: ' . $showStale['stdout']);

    $searchStale = $cli(['search', 'nobody', '--registry', $registry, '--no-cache'], $project);
    $assert(\str_contains($searchStale['stdout'], 'UNMAINTAINED'), 'search flags the unmaintained package: ' . $searchStale['stdout']);

    $outdated = $cli(['outdated', '--registry', $registry, '--no-cache'], $project);
    $assert($outdated['exitCode'] === 0, 'outdated succeeds: ' . $outdated['stderr']);
    $assert(\str_contains($outdated['stdout'], 'MARKED UNMAINTAINED'), 'outdated marks the unmaintained package: ' . $outdated['stdout']);

    $why = $cli(['why', '--registry', $registry, '--no-cache'], $project);
    $assert($why['exitCode'] === 0, 'why succeeds: ' . $why['stderr']);
    $assert(\str_contains($why['stdout'], 'marked unmaintained'), 'why notes the unmaintained package: ' . $why['stdout']);

    // `verify` reports both markers beside the signature verdict it is about.
    $verify = $cli(['verify', '--registry', $registry, '--no-cache'], $project);
    $assert(\str_contains($verify['stdout'], 'marked bad'), 'verify reports the bad package: ' . $verify['stdout']);
    $assert(\str_contains($verify['stdout'], 'marked unmaintained'), 'verify reports the unmaintained package: ' . $verify['stdout']);

    echo "bad package CLI tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
