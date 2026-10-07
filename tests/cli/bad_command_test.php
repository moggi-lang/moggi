#!/usr/bin/env php
<?php declare(strict_types=1);

// `moggi bad` marks or lifts a package-level marker over the signed write
// transport. These checks drive the real CLI against a throwaway endpoint that
// verifies the same schnorr signature the registry does, so the request a real
// registry would accept is the one the client actually sent — the route, the
// body, and the identity. Without the `schnorr` binary the signing half cannot
// run and the checks are reported as skipped rather than faked.

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
    echo "bad command tests skipped (no schnorr CLI; set MOGGI_SCHNORR)\n";
    exit(0);
}

$work = createTempDir('moggi-bad-command');
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

    $help = $cli(['bad', '--help']);
    $assert($help['exitCode'] === 0 && \str_contains($help['stdout'], 'moggi bad'), 'bad --help prints its usage: ' . $help['stderr']);

    $noName = $cli(['bad']);
    $assert($noName['exitCode'] === 1 && \str_contains($noName['stderr'], 'needs one package name'), 'bad needs a package name: ' . $noName['stderr']);

    $noReason = $cli(['bad', 'demo', '--registry', $base, '--yes']);
    $assert($noReason['exitCode'] === 1 && \str_contains($noReason['stderr'], '--reason'), 'marking needs a reason: ' . $noReason['stderr']);

    $both = $cli(['bad', 'demo', '--lift', '--reason', 'x', '--registry', $base, '--yes']);
    $assert($both['exitCode'] === 1 && \str_contains($both['stderr'], 'contradict'), '--lift and --reason are refused together: ' . $both['stderr']);

    $badAs = $cli(['bad', 'demo', '--reason', 'x', '--as', 'nope', '--registry', $base, '--yes']);
    $assert($badAs['exitCode'] === 1 && \str_contains($badAs['stderr'], 'not an npub'), '--as must be an npub: ' . $badAs['stderr']);

    $emptyDir = $work . '/empty';
    \mkdir($emptyDir, 0777, true);
    $noSigner = $cli(['bad', 'demo', '--reason', 'x', '--registry', $base, '--yes'], $emptyDir);
    $assert($noSigner['exitCode'] === 1 && \str_contains($noSigner['stderr'], '--as'), 'without a descriptor, --as is required: ' . $noSigner['stderr']);

    $clear();
    $mark = $cli(['bad', 'demo', '--reason', 'RCE in the decoder', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($mark['exitCode'] === 0, 'a mark must succeed: ' . $mark['stderr']);
    $assert(\str_contains($mark['stdout'], 'marked bad'), 'the report says it was marked: ' . $mark['stdout']);
    $request = $last();
    $assert(($request['ok'] ?? false) === true, 'the endpoint verified the signature: ' . \json_encode($request));
    $assert(($request['method'] ?? '') === 'POST' && ($request['path'] ?? '') === '/bad', 'a mark goes to POST /bad: ' . \json_encode($request));
    $assert(($request['npub'] ?? '') === $npub, 'the request is signed as the chosen npub');
    $body = \json_decode((string) ($request['body'] ?? ''), true);
    $assert(($body['name'] ?? null) === 'demo', 'the body names the package');
    $assert(($body['bad']['reason'] ?? null) === 'RCE in the decoder', 'the body carries the reason: ' . \json_encode($body));

    $clear();
    $lift = $cli(['bad', 'demo', '--lift', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($lift['exitCode'] === 0, 'a lift must succeed: ' . $lift['stderr']);
    $assert(\str_contains($lift['stdout'], 'lifted'), 'the report says it was lifted: ' . $lift['stdout']);
    $body = \json_decode((string) ($last()['body'] ?? ''), true);
    $assert(\array_key_exists('bad', $body) && $body['bad'] === null, 'a lift sends `bad: null`: ' . \json_encode($body));

    $clear();
    $admin = $cli(['bad', 'demo', '--reason', 'compromised', '--admin', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($admin['exitCode'] === 0, 'the admin route must succeed: ' . $admin['stderr']);
    $assert(($last()['path'] ?? '') === '/admin/bad', '--admin uses POST /admin/bad: ' . \json_encode($last()));

    $clear();
    $json = $cli(['bad', 'demo', '--reason', 'x', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes', '--json']);
    $assert($json['exitCode'] === 0, '--json must succeed: ' . $json['stderr']);
    $decoded = \json_decode($json['stdout'], true);
    $assert(\is_array($decoded) && ($decoded['name'] ?? null) === 'demo', 'the json envelope names the package: ' . $json['stdout']);

    echo "bad command tests passed ({$checks} checks)\n";
} finally {
    if ($server !== null) {
        stopFakeRegistry($server);
    }
    removeDirectory($work);
}
