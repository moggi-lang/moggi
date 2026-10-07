#!/usr/bin/env php
<?php declare(strict_types=1);

// `moggi takeover` files a public takeover request over the signed write
// transport, and — as an admin — decides one. These checks drive the real CLI
// against a throwaway endpoint that verifies the same schnorr signature the
// registry does, so the request a real registry would accept is the one the
// client actually sent: the route, the body, and the identity. Without the
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
    echo "takeover command tests skipped (no schnorr CLI; set MOGGI_SCHNORR)\n";
    exit(0);
}

$work = createTempDir('moggi-takeover-command');
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

    $help = $cli(['takeover', '--help']);
    $assert($help['exitCode'] === 0 && \str_contains($help['stdout'], 'moggi takeover'), 'takeover --help prints its usage: ' . $help['stderr']);

    $noVerb = $cli(['takeover']);
    $assert($noVerb['exitCode'] === 1 && \str_contains($noVerb['stderr'], 'request, approve, reject'), 'a missing verb is refused: ' . $noVerb['stderr']);

    $noName = $cli(['takeover', 'request']);
    $assert($noName['exitCode'] === 1 && \str_contains($noName['stderr'], 'needs one package name'), 'a request needs a package name: ' . $noName['stderr']);

    $noReason = $cli(['takeover', 'request', 'demo', '--registry', $base, '--yes']);
    $assert($noReason['exitCode'] === 1 && \str_contains($noReason['stderr'], '--reason'), 'a request needs a reason: ' . $noReason['stderr']);

    $badAs = $cli(['takeover', 'request', 'demo', '--reason', 'x', '--as', 'nope', '--registry', $base, '--yes']);
    $assert($badAs['exitCode'] === 1 && \str_contains($badAs['stderr'], 'not an npub'), '--as must be an npub: ' . $badAs['stderr']);

    $emptyDir = $work . '/empty';
    \mkdir($emptyDir, 0777, true);
    $noSigner = $cli(['takeover', 'request', 'demo', '--reason', 'x', '--registry', $base, '--yes'], $emptyDir);
    $assert($noSigner['exitCode'] === 1 && \str_contains($noSigner['stderr'], '--as'), 'without a descriptor, --as is required: ' . $noSigner['stderr']);

    $clear();
    $file = $cli(['takeover', 'request', 'demo', '--reason', 'no releases in two years', '--repo', 'https://example.test/moggi-demo', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($file['exitCode'] === 0, 'filing a request must succeed: ' . $file['stderr']);
    $assert(\str_contains($file['stdout'], 'takeover request filed'), 'the report says it was filed: ' . $file['stdout']);
    $assert(\str_contains($file['stdout'], 'requests/takeover/demo.json'), 'the report names the public record: ' . $file['stdout']);
    $request = $last();
    $assert(($request['ok'] ?? false) === true, 'the endpoint verified the signature: ' . \json_encode($request));
    $assert(($request['method'] ?? '') === 'POST' && ($request['path'] ?? '') === '/takeover-request', 'a request goes to POST /takeover-request: ' . \json_encode($request));
    $assert(($request['npub'] ?? '') === $npub, 'the request is signed as the chosen npub');
    $body = \json_decode((string) ($request['body'] ?? ''), true);
    $assert(($body['name'] ?? null) === 'demo', 'the body names the package');
    $assert(($body['reason'] ?? null) === 'no releases in two years', 'the body carries the reason: ' . \json_encode($body));
    $assert(($body['repo'] ?? null) === 'https://example.test/moggi-demo', 'the body carries the repo: ' . \json_encode($body));

    $clear();
    $approve = $cli(['takeover', 'approve', 'demo', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($approve['exitCode'] === 0, 'an approval must succeed: ' . $approve['stderr']);
    $assert(\str_contains($approve['stdout'], 'handed over'), 'the report says the package is handed over: ' . $approve['stdout']);
    $assert(($last()['path'] ?? '') === '/admin/takeover-requests/demo', 'an approval goes to POST /admin/takeover-requests/<name>: ' . \json_encode($last()));
    $body = \json_decode((string) ($last()['body'] ?? ''), true);
    $assert(($body['decision'] ?? null) === 'approve', 'the body carries the decision: ' . \json_encode($body));

    $clear();
    $reject = $cli(['takeover', 'reject', 'demo', '--note', 'the owner answered', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes']);
    $assert($reject['exitCode'] === 0, 'a rejection must succeed: ' . $reject['stderr']);
    $body = \json_decode((string) ($last()['body'] ?? ''), true);
    $assert(($body['decision'] ?? null) === 'reject', 'the body carries the decision: ' . \json_encode($body));
    $assert(($body['note'] ?? null) === 'the owner answered', 'the body carries the note: ' . \json_encode($body));

    $clear();
    $json = $cli(['takeover', 'request', 'demo', '--reason', 'x', '--as', $npub, '--nsec-file', $keyFile, '--registry', $base, '--yes', '--json']);
    $assert($json['exitCode'] === 0, '--json must succeed: ' . $json['stderr']);
    $decoded = \json_decode($json['stdout'], true);
    $assert(\is_array($decoded) && ($decoded['name'] ?? null) === 'demo' && ($decoded['reason'] ?? null) === 'x', 'the json envelope names the request: ' . $json['stdout']);

    echo "takeover command tests passed ({$checks} checks)\n";
} finally {
    if ($server !== null) {
        stopFakeRegistry($server);
    }
    removeDirectory($work);
}
