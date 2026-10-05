#!/usr/bin/env php
<?php declare(strict_types=1);

// `moggi outdated` and `moggi why`, through the real CLI, against a real signed
// registry directory and a real lock: a lock that is behind, a version the
// descriptor refuses, a version the catalog no longer publishes, and a package
// pulled in transitively. The registry is signed with the checkout's own
// `schnorr`; without that binary the signing half cannot run and the checks are
// reported as skipped rather than faked.

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
    echo "outdated/why tests skipped (no schnorr CLI; set MOGGI_SCHNORR)\n";
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

$work = createTempDir('moggi-outdated-why');
$env = \getenv();
$env['MOGGI_SCHNORR'] = $binary;
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
$env['MOGGI_USER_CACHE'] = $work . '/user-cache';
unset($env['MOGGI_REGISTRY_NPUB'], $env['MOGGI_ALLOW_STALE_REGISTRY']);

$cli = static function (array $arguments, string $cwd) use ($root, $env): array {
    $result = runCompiledProcess([PHP_BINARY, $root . '/moggi.php', ...$arguments], 120, $env, $cwd);
    if ($result['exitCode'] !== 0) {
        throw new \RuntimeException(
            \implode(' ', $arguments) . " failed ({$result['exitCode']}):\n" . $result['stdout'] . $result['stderr'],
        );
    }

    return $result;
};
$cliFail = static function (array $arguments, string $cwd) use ($root, $env): array {
    $result = runCompiledProcess([PHP_BINARY, $root . '/moggi.php', ...$arguments], 120, $env, $cwd);
    if ($result['exitCode'] === 0) {
        throw new \RuntimeException(\implode(' ', $arguments) . " was expected to be refused, but it succeeded:\n" . $result['stdout']);
    }

    return $result;
};

try {
    [$nsec, $npub] = \preg_split('/\s+/', $schnorr(['generate'])) ?: ['', ''];
    $assert(\str_starts_with($nsec, 'nsec') && \str_starts_with($npub, 'npub'), 'the schnorr CLI generated a keypair');

    $packageEntry = static function (string $npub, array $versions, string $seed): array {
        $names = \array_keys($versions);
        \usort($names, '\\version_compare');

        return [
            'owner' => $npub,
            'authors' => [$npub],
            'latest' => (string) \end($names),
            'description' => 'test package',
            'digest' => 'sha256:' . \str_repeat($seed, 64),
            'versions' => $versions,
        ];
    };

    $writeRegistry = static function (string $dir, string $npub, string $nsec, int $version, array $packages) use ($schnorr): void {
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
            'version' => $version,
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
    \mkdir($project, 0777, true);
    \file_put_contents(
        $project . '/app.moggi',
        "[package]\nname = app\nversion = 1.0.0\n\n[author]\nname = Test\nemail = t@example.com\nnpub = {$npub}\n\n"
        . "[dependencies]\nmid = >=1.0\nblocked = =1.0.0\n",
    );

    $writeRegistry($registry, $npub, $nsec, 1, [
        'mid' => $packageEntry($npub, ['1.0.0' => ['deps' => ['leaf' => '>=1.0']]], 'a'),
        'leaf' => $packageEntry($npub, ['1.0.0' => ['deps' => []]], 'b'),
        'blocked' => $packageEntry($npub, ['1.0.0' => ['deps' => []]], 'c'),
    ]);

    $cli(['update', '--registry', $registry, '--no-cache'], $project);
    $assert(\is_file($project . '/moggi.lock'), '`update` must write a lock');

    $synced = $cli(['outdated', '--registry', $registry, '--no-cache'], $project);
    $assert(
        \str_contains($synced['stdout'], 'everything is at the newest version the descriptor allows'),
        'a lock at the newest allowed versions reports nothing to move: ' . $synced['stdout'],
    );

    $writeRegistry($registry, $npub, $nsec, 2, [
        'mid' => $packageEntry($npub, [
            '1.0.0' => ['deps' => ['leaf' => '>=1.0']],
            '1.1.0' => ['deps' => ['leaf' => '>=1.0']],
        ], 'a'),
        'leaf' => $packageEntry($npub, ['1.0.0' => ['deps' => []], '1.1.0' => ['deps' => []]], 'b'),
        'blocked' => $packageEntry($npub, ['1.0.0' => ['deps' => []], '2.0.0' => ['deps' => []]], 'c'),
    ]);

    $stale = $cli(['outdated', '--registry', $registry, '--no-cache'], $project);
    $assert(\str_contains($stale['stdout'], 'mid'), 'a stale lock lists the package that moved: ' . $stale['stdout']);
    $assert(\str_contains($stale['stdout'], '1.1.0'), 'a stale lock names what it would move to: ' . $stale['stdout']);
    $assert(\str_contains($stale['stdout'], '->'), 'a stale lock is marked as moving: ' . $stale['stdout']);
    $assert(
        \str_contains($stale['stdout'], 'held by `=1.0.0`'),
        'a version the descriptor refuses is held, with the constraint named: ' . $stale['stdout'],
    );

    $json = $cli(['outdated', '--json', '--registry', $registry, '--no-cache'], $project);
    $document = \json_decode($json['stdout'], true);
    $assert(\is_array($document) && \is_array($document['packages'] ?? null), 'outdated --json emits a packages list: ' . $json['stdout']);
    $rows = [];
    foreach ($document['packages'] as $row) {
        $rows[(string) $row['name']] = $row;
    }
    $assert(isset($rows['mid'], $rows['blocked']), 'every locked package has a row');
    $assert($rows['mid']['current'] === '1.0.0' && $rows['mid']['wanted'] === '1.1.0' && $rows['mid']['latest'] === '1.1.0', 'the wanted and latest columns are exact: ' . \json_encode($rows['mid']));
    $assert($rows['blocked']['wanted'] === '1.0.0' && $rows['blocked']['latest'] === '2.0.0' && $rows['blocked']['constraint'] === '=1.0.0', 'a refused candidate names its constraint: ' . \json_encode($rows['blocked']));
    $assert($rows['mid']['available'] === true, 'a published version is available');

    $transitive = $cli(['why', 'leaf', '--registry', $registry, '--no-cache'], $project);
    $assert(
        \str_contains($transitive['stdout'], 'root -> mid -> leaf'),
        'a transitive package names the chain that pulled it in: ' . $transitive['stdout'],
    );

    $held = $cli(['why', 'blocked', '--registry', $registry, '--no-cache'], $project);
    $assert(\str_contains($held['stdout'], 'root -> blocked'), 'a direct dependency names its chain: ' . $held['stdout']);
    $assert(\str_contains($held['stdout'], 'held at 1.0.0 by'), 'a refused newer version is explained: ' . $held['stdout']);
    $assert(\str_contains($held['stdout'], '=1.0.0'), 'the refusing constraint is named: ' . $held['stdout']);
    $assert(\str_contains($held['stdout'], 'available: 2.0.0, 1.0.0'), 'the published versions are listed newest first: ' . $held['stdout']);

    $whole = $cli(['why', '--registry', $registry, '--no-cache'], $project);
    foreach (['mid', 'leaf', 'blocked'] as $name) {
        $assert(\str_contains($whole['stdout'], $name), "the whole install explains {$name}: " . $whole['stdout']);
    }

    $whyJson = $cli(['why', 'leaf', '--json', '--registry', $registry, '--no-cache'], $project);
    $whyDocument = \json_decode($whyJson['stdout'], true);
    $assert($whyDocument['why']['leaf']['version'] === '1.0.0', 'why --json reports the locked version');
    $paths = \array_column($whyDocument['why']['leaf']['requiredBy'], 'path');
    $assert($paths === ['root -> mid -> leaf'], 'why --json reports the transitive chain: ' . \json_encode($paths));

    $writeRegistry($registry, $npub, $nsec, 3, [
        'mid' => $packageEntry($npub, ['1.1.0' => ['deps' => ['leaf' => '>=1.0']]], 'a'),
        'leaf' => $packageEntry($npub, ['1.0.0' => ['deps' => []], '1.1.0' => ['deps' => []]], 'b'),
        'blocked' => $packageEntry($npub, ['1.0.0' => ['deps' => []], '2.0.0' => ['deps' => []]], 'c'),
    ]);

    $gone = $cli(['outdated', '--json', '--registry', $registry, '--no-cache'], $project);
    $goneDocument = \json_decode($gone['stdout'], true);
    $goneRows = [];
    foreach ($goneDocument['packages'] as $row) {
        $goneRows[(string) $row['name']] = $row;
    }
    $assert(
        $goneRows['mid']['available'] === false && $goneRows['mid']['current'] === '1.0.0' && $goneRows['mid']['wanted'] === '1.1.0',
        'a locked version the catalog no longer publishes is reported, not dropped: ' . \json_encode($goneRows['mid']),
    );

    $goneText = $cli(['outdated', '--registry', $registry, '--no-cache'], $project);
    $assert(
        \str_contains($goneText['stdout'], 'is no longer published'),
        'the unavailability is stated in the text report: ' . $goneText['stdout'],
    );

    [$otherNsec, $otherNpub] = \preg_split('/\s+/', $schnorr(['generate'])) ?: ['', ''];
    $writeRegistry($work . '/other-registry', $otherNpub, $otherNsec, 1, [
        'mid' => $packageEntry($otherNpub, ['1.1.0' => ['deps' => ['leaf' => '>=1.0']]], 'a'),
        'leaf' => $packageEntry($otherNpub, ['1.0.0' => ['deps' => []], '1.1.0' => ['deps' => []]], 'b'),
        'blocked' => $packageEntry($otherNpub, ['1.0.0' => ['deps' => []], '2.0.0' => ['deps' => []]], 'c'),
    ]);
    $mismatch = $cliFail(['outdated', '--registry', $work . '/other-registry', '--no-cache'], $project);
    $assert(
        \str_contains($mismatch['stderr'], 'resolved against'),
        'a lock resolved elsewhere is refused rather than diffed against a different registry: ' . $mismatch['stderr'],
    );

    echo "outdated/why tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
