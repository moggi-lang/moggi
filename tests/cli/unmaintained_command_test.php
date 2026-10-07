#!/usr/bin/env php
<?php declare(strict_types=1);

// `moggi unmaintained` declares or clears a package-level marker over the signed
// write transport. These checks drive the real CLI against a throwaway endpoint
// that verifies the same schnorr signature the registry does, so the route, the
// body and the identity are the ones a real registry would accept. Without the
// `schnorr` binary the signing half cannot run and the checks are reported as
// skipped rather than faked.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/registry-write-server.php';

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
    echo "unmaintained command tests skipped (no schnorr CLI; set MOGGI_SCHNORR)\n";
    exit(0);
}

$work = createTempDir('moggi-unmaintained-command');
$project = $work . '/project';
\mkdir($project, 0777, true);

$generated = runCompiledProcess([$binary, 'generate'], 30);
[$nsec, $npub] = \preg_split('/\s+/', \trim($generated['stdout'])) ?: ['', ''];
$assert(\str_starts_with($nsec, 'nsec') && \str_starts_with($npub, 'npub'), 'the schnorr CLI generated a keypair: ' . $generated['stderr']);
$keyFile = $work . '/key.nsec';
\file_put_contents($keyFile, $nsec . "\n");
\chmod($keyFile, 0600);

$env = \getenv();
$env['MOGGI_SCHNORR'] = $binary;
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
$env['MOGGI_USER_CACHE'] = $work . '/user-cache';
unset($env['MOGGI_REGISTRY_NPUB'], $env['MOGGI_ALLOW_STALE_REGISTRY'], $env['MOGGI_NSEC_FILE']);

$router = $work . '/router.php';
\file_put_contents($router, fakeRegistryRouterSource($root));
$log = $work . '/requests.log';

$server = null;
try {
    $server = startFakeRegistry($router, $log, $env);
    $base = 'http://127.0.0.1:' . $server['port'];

    $cli = static fn (array $arguments, ?string $cwd = null): array => runCompiledProcess(
        [PHP_BINARY, $root . '/moggi.php', ...$arguments],
        120,
        $env,
        $cwd ?? $project,
    );
    $clear = static function () use ($log): void {
        @\unlink($log);
    };
    $last = static function () use ($log): array {
        $requests = fakeRegistryRequests($log);

        return $requests === [] ? [] : $requests[\count($requests) - 1];
    };

    $help = $cli(['unmaintained', '--help']);
    $assert($help['exitCode'] === 0 && \str_contains($help['stdout'], 'moggi unmaintained'), 'unmaintained --help prints its usage: ' . $help['stderr']);

    $noName = $cli(['unmaintained']);
    $assert($noName['exitCode'] === 1 && \str_contains($noName['stderr'], 'needs one package name'), 'unmaintained needs a package name: ' . $noName['stderr']);

    $both = $cli(['unmaintained', 'demo', '--clear', '--note', 'x', '--registry', $base, '--yes']);
    $assert($both['exitCode'] === 1 && \str_contains($both['stderr'], 'contradict'), '--clear and --note are refused together: ' . $both['stderr']);

    $emptyDir = $work . '/empty';
    \mkdir($emptyDir, 0777, true);
    $noSigner = $cli(['unmaintained', 'demo', '--registry', $base, '--yes'], $emptyDir);
    $assert($noSigner['exitCode'] === 1 && \str_contains($noSigner['stderr'], '--as'), 'without a descriptor, --as is required: ' . $noSigner['stderr']);

    $clear();
    $withNote = $cli(['unmaintained', 'demo', '--note', 'no releases since 0.9', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($withNote['exitCode'] === 0, 'declaring unmaintained must succeed: ' . $withNote['stderr']);
    $assert(\str_contains($withNote['stdout'], 'marked unmaintained'), 'the report says it was marked: ' . $withNote['stdout']);
    $request = $last();
    $assert(($request['ok'] ?? false) === true, 'the endpoint verified the signature: ' . \json_encode($request));
    $assert(($request['path'] ?? '') === '/unmaintained', 'the write goes to POST /unmaintained: ' . \json_encode($request));
    $assert(($request['npub'] ?? '') === $npub, 'the request is signed as the chosen npub');
    $body = \json_decode((string) ($request['body'] ?? ''), true);
    $assert(($body['name'] ?? null) === 'demo', 'the body names the package');
    $assert(($body['unmaintained']['note'] ?? null) === 'no releases since 0.9', 'the body carries the note: ' . \json_encode($body));

    $clear();
    $bare = $cli(['unmaintained', 'demo', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($bare['exitCode'] === 0, 'a bare declaration must succeed: ' . $bare['stderr']);
    $body = \json_decode((string) ($last()['body'] ?? ''), true);
    $assert(($body['unmaintained'] ?? null) === true, 'a declaration with no note sends `true`: ' . \json_encode($body));

    $clear();
    $cleared = $cli(['unmaintained', 'demo', '--clear', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($cleared['exitCode'] === 0, 'clearing must succeed: ' . $cleared['stderr']);
    $assert(\str_contains($cleared['stdout'], 'cleared'), 'the report says it was cleared: ' . $cleared['stdout']);
    $body = \json_decode((string) ($last()['body'] ?? ''), true);
    $assert(\array_key_exists('unmaintained', $body) && $body['unmaintained'] === null, 'a clear sends `unmaintained: null`: ' . \json_encode($body));

    echo "unmaintained command tests passed ({$checks} checks)\n";
} finally {
    if ($server !== null) {
        stopFakeRegistry($server);
    }
    removeDirectory($work);
}
