<?php declare(strict_types=1);

/**
 * A throwaway registry write endpoint for CLI tests.
 *
 * A `php -S` server with a router that verifies the same schnorr write signature
 * the real worker checks, records every request it saw, and answers `{ok:true}`
 * for a good signature and `403 bad-signature` otherwise. Starting and stopping
 * it here is what lets a client write verb (`bad`, `unmaintained`, a takeover
 * request) be driven end to end without a deployed registry or a network.
 */

/**
 * The router script's source.
 *
 * The repo root is baked in so the router can `require` the compiler and use the
 * project's own `writeMessageHex` and `verifySignature` — the same code the
 * client signs with, so the test cannot drift from the wire format.
 */
function fakeRegistryRouterSource(string $root): string
{
    $source = <<<'PHP'
    <?php declare(strict_types=1);

    require __COMPILER__;

    $body = (string) \file_get_contents('php://input');
    $host = \strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    $path = \parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH) ?: '/';
    $npub = (string) ($_SERVER['HTTP_X_MOGGI_NPUB'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_MOGGI_SIG'] ?? '');
    $timestamp = (int) ($_SERVER['HTTP_X_MOGGI_DATE'] ?? 0);
    $nonce = (string) ($_SERVER['HTTP_X_MOGGI_NONCE'] ?? '');

    $verdict = \Moggi\Registry\verifySignature(
        \Moggi\Registry\writeMessageHex($host, $method, $path, $timestamp, $nonce, $body),
        $signature,
        $npub,
    );

    \file_put_contents(
        (string) \getenv('MOGGI_WRITE_LOG'),
        \json_encode([
            'method' => $method,
            'path' => $path,
            'host' => $host,
            'npub' => $npub,
            'body' => $body,
            'ok' => $verdict['ok'],
        ]) . "\n",
        \FILE_APPEND,
    );

    \header('Content-Type: application/json');
    if (!$verdict['ok']) {
        \http_response_code(403);
        echo \json_encode(['error' => 'bad-signature', 'message' => $verdict['note']]);

        return;
    }

    $decoded = \json_decode($body, true);
    echo \json_encode(['ok' => true, 'name' => \is_array($decoded) ? ($decoded['name'] ?? null) : null]);
    PHP;

    return \str_replace(
        '__COMPILER__',
        \var_export(\rtrim($root, '/') . '/src/compiler.php', true),
        $source,
    );
}

/** An unused local TCP port, chosen by binding one and letting it go. */
function freeTcpPort(): int
{
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        throw new \RuntimeException('cannot find a free port: ' . $error);
    }
    $name = (string) \stream_socket_get_name($server, false);
    \fclose($server);

    return (int) \substr($name, \strrpos($name, ':') + 1);
}

/**
 * Start the fake registry, wait until it answers, and return its process, port
 * and pipes. Stop it with {@see stopFakeRegistry}.
 *
 * @param array<string, string> $env the environment the server and its router run with
 * @return array{process: resource, pipes: array<int, resource>, port: int}
 */
function startFakeRegistry(string $routerPath, string $logPath, array $env = []): array
{
    $port = freeTcpPort();
    $env['MOGGI_WRITE_LOG'] = $logPath;
    $process = \proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, $routerPath],
        [
            0 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        $env,
    );
    if (!\is_resource($process)) {
        throw new \RuntimeException('cannot start the fake registry');
    }

    $deadline = \microtime(true) + 15;
    while (\microtime(true) < $deadline) {
        $socket = @\fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($socket !== false) {
            \fclose($socket);

            return ['process' => $process, 'pipes' => $pipes, 'port' => $port];
        }
        \usleep(50_000);
    }

    foreach ($pipes as $pipe) {
        \fclose($pipe);
    }
    \proc_terminate($process, 9);
    \proc_close($process);

    throw new \RuntimeException('the fake registry did not start');
}

/**
 * @param array{process: resource, pipes: array<int, resource>, port: int} $server
 */
function stopFakeRegistry(array $server): void
{
    foreach ($server['pipes'] as $pipe) {
        if (\is_resource($pipe)) {
            \fclose($pipe);
        }
    }
    if (\is_resource($server['process'])) {
        \proc_terminate($server['process'], 9);
        \proc_close($server['process']);
    }
}

/**
 * The requests the fake registry recorded, oldest first.
 *
 * @return list<array{method: string, path: string, host: string, npub: string, body: string, ok: bool}>
 */
function fakeRegistryRequests(string $logPath): array
{
    if (!\is_file($logPath)) {
        return [];
    }
    $requests = [];
    foreach (\explode("\n", \trim((string) \file_get_contents($logPath))) as $line) {
        if ($line === '') {
            continue;
        }
        $decoded = \json_decode($line, true);
        if (\is_array($decoded)) {
            $requests[] = $decoded;
        }
    }

    return $requests;
}
