<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Compiler\findExecutable;

/**
 * The write half of a registry client: signing, and the HTTP that carries it.
 *
 * There is no login. Every write is authenticated by a Nostr Web Token: a
 * signed kind-27519 event whose claims bind the audience, method, path, body
 * digest and a nonce. The token travels as `Authorization: Nostr <base64url>`.
 *
 * The event id makes the token self-identifying, and the nonce makes each one
 * unique; the expiry bounds how long it is worth trying. The worker verifies
 * the event and the request claims with the same rules. Signing goes through
 * the project's own `schnorr` CLI, so there is one BIP-340 implementation in
 * the tree, not two. The nsec is written to that process's stdin, never to an
 * argument: argv is world-readable.
 */

/** The key must be an nsec of the shape an nsec has. */
function isNsec(string $nsec): bool
{
    return \strlen($nsec) === 63
        && \str_starts_with($nsec, 'nsec1')
        && \preg_match('/^nsec1[023456789acdefghjklmnpqrstuvwxyz]+$/', $nsec) === 1;
}

/**
 * BIP-340 sign one 32-byte digest, with the schnorr CLI.
 *
 * @param string $messageHex the digest, as 64 hex characters
 * @return string the signature, as 128 hex characters
 */
function signMessage(string $messageHex, string $nsec): string
{
    $binary = schnorrBinary();
    if ($binary === null) {
        throw new \RuntimeException(schnorrMissingNote());
    }

    $process = \proc_open(
        [$binary, 'sign', $messageHex],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (! \is_resource($process)) {
        throw new \RuntimeException("cannot run {$binary}");
    }

    \fwrite($pipes[0], $nsec . "\n");
    \fclose($pipes[0]);
    $stdout = \trim((string) \stream_get_contents($pipes[1]));
    $stderr = \trim((string) \stream_get_contents($pipes[2]));
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $code = \proc_close($process);

    if ($code !== 0 || $stdout === '') {
        throw new \RuntimeException($stderr !== '' ? $stderr : 'the schnorr CLI could not sign');
    }

    return $stdout;
}

/**
 * The host a URL is written to, spelled the way a URL parser spells it: the
 * hostname lowercased, and the port only when it is not the scheme's default.
 *
 * The worker builds the NWT's `aud` claim from `new URL(request.url).host`, so
 * the two have to agree character for character or every write fails. This is
 * that same rule, in PHP.
 */
function requestHost(string $url): string
{
    $host = \strtolower((string) (\parse_url($url, \PHP_URL_HOST) ?? ''));
    $port = \parse_url($url, \PHP_URL_PORT);
    $scheme = \strtolower((string) \parse_url($url, \PHP_URL_SCHEME));
    if (\is_int($port)
        && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
        return $host . ':' . $port;
    }

    return $host;
}

/**
 * The headers that authenticate one request.
 *
 * The NWT's audience is the host, so a token made for one registry cannot be
 * replayed against a mirror or a look-alike origin. The method, path and body
 * digest are claims too; the registry checks all of them.
 *
 * @return list<string>
 */
function writeAuthHeaders(string $host, string $method, string $path, string $body, string $nsec, string $npub): array
{
    $event = nwtWriteEvent($host, $method, $path, $body, $nsec, $npub);

    return ['Authorization: ' . NWT_AUTH_SCHEME . ' ' . nwtToken($event)];
}

/**
 * One request, returning the status and body rather than throwing on a non-2xx:
 * the registry's errors are structured (`{error, message}`) and the caller prints
 * them verbatim.
 *
 * @param list<string> $headers
 * @return array{status: int, reason: string, body: string}
 */
function httpSend(string $url, string $method, array $headers, string $body = '', bool $followRedirects = true): array
{
    $headerLines = [...$headers, 'Accept-Encoding: identity', 'Content-Length: ' . \strlen($body)];
    $context = \stream_context_create(['http' => [
        'method' => $method,
        'header' => \implode("\r\n", $headerLines),
        'content' => $body,
        'timeout' => 60,
        'ignore_errors' => true,
        'follow_location' => $followRedirects ? 1 : 0,
        'max_redirects' => $followRedirects ? 20 : 0,
    ]]);

    $bytes = @\file_get_contents($url, false, $context);
    $status = 0;
    $reason = '';
    foreach ($http_response_header ?? [] as $header) {
        if (\preg_match('#^HTTP/\S+\s+(\d{3})\s*(.*)$#', $header, $match) === 1) {
            $status = (int) $match[1];
            $reason = \trim($match[2]);
        }
    }

    return ['status' => $status, 'reason' => $reason, 'body' => $bytes === false ? '' : $bytes];
}

/**
 * Whether this filesystem reports POSIX permission bits at all
 */
function reportsPosixModeBits(): bool
{
    return \PHP_OS_FAMILY !== 'Windows';
}

/** Whether standard input is a terminal this client can prompt on. */
function stdinIsTerminal(): bool
{
    if (\function_exists('stream_isatty')) {
        return \stream_isatty(\STDIN);
    }
    if (\function_exists('posix_isatty')) {
        return \posix_isatty(\STDIN);
    }

    return false;
}

/**
 * Read one line from a Windows console without echoing it.
 *
 * PHP has no hidden-input primitive, so PowerShell reads the keys itself and
 * writes only the completed line to its standard output. `Read-Host` is not used
 * because its prompt can share that stream with the secret.
 */
function readHiddenLineWindows(): string
{
    $powershell = findExecutable('powershell') ?? findExecutable('pwsh');
    if ($powershell === null) {
        throw new \RuntimeException('no PowerShell to hide the key prompt on Windows');
    }

    $script = '$s = New-Object System.Text.StringBuilder; '
        . 'while ($true) { $k = [Console]::ReadKey($true); '
        . 'if ($k.Key -eq "Enter") { break } '
        . 'if ($k.Key -eq "Backspace") { if ($s.Length -gt 0) { [void]$s.Remove($s.Length - 1, 1) } continue } '
        . 'if ($k.Key -eq "Escape" -or ($k.Key -eq "C" -and $k.Modifiers -eq "Control")) { exit 1 } '
        . 'if (-not [char]::IsControl($k.KeyChar)) { [void]$s.Append($k.KeyChar) } } '
        . '[Console]::Out.Write($s.ToString())';

    $process = \proc_open(
        [$powershell, '-NoProfile', '-Command', $script],
        [0 => \STDIN, 1 => ['pipe', 'w'], 2 => \STDERR],
        $pipes,
    );
    if (! \is_resource($process) || ! isset($pipes[1])) {
        throw new \RuntimeException('cannot start PowerShell to read the key');
    }
    $line = \trim((string) \stream_get_contents($pipes[1]));
    \fclose($pipes[1]);
    $code = \proc_close($process);
    if ($code !== 0 || $line === '') {
        throw new \RuntimeException('the interactive key prompt was cancelled or returned nothing');
    }

    return $line;
}

/**
 * The nsec to sign with, from `--nsec-file`, `MOGGI_NSEC_FILE`, or a no-echo
 * prompt on a terminal.
 *
 * A file has to be private: mode 0600 — a secret key that group or other can read
 * is not secret, and failing here costs a `chmod` rather than the key. Windows has
 * no mode bits PHP can read and no ACL API, so there is nothing to check there. A
 * run with no terminal and no file fails
 * loudly instead of hanging on a read — a CI job that silently waits for a
 * prompt is worse than one that stops.
 */
function readNsecKey(?string $file): string
{
    $path = $file ?? ((\getenv('MOGGI_NSEC_FILE') ?: null) ?: null);
    if ($path === null) {
        if (! stdinIsTerminal()) {
            throw new \RuntimeException(
                'no key: pass --nsec-file FILE (or set MOGGI_NSEC_FILE), or run on a terminal to be prompted',
            );
        }

        return promptNsec();
    }

    if (! \is_file($path)) {
        throw new \RuntimeException("no such key file: {$path}");
    }
    if (reportsPosixModeBits()) {
        \clearstatcache(true, $path);
        $mode = \fileperms($path);
        if ($mode === false) {
            throw new \RuntimeException("cannot read the permissions of {$path} — refusing a key whose mode is unknown");
        }
        if (($mode & 0o077) !== 0) {
            throw new \RuntimeException(
                \sprintf('%s is mode %04o — a secret key file must not be readable by group or other (chmod 600)', $path, $mode & 0o777),
            );
        }
    }

    return assertNsec(\trim((string) \file_get_contents($path)), $path);
}

/** Prompt for the key without echoing it. */
function promptNsec(): string
{
    \fwrite(\STDERR, 'nsec (input hidden): ');
    if (\PHP_OS_FAMILY === 'Windows') {
        $line = readHiddenLineWindows();
        \fwrite(\STDERR, "\n");

        return assertNsec($line, 'stdin');
    }

    @\shell_exec('stty -echo 2>/dev/null');
    $line = \fgets(\STDIN);
    @\shell_exec('stty echo 2>/dev/null');
    \fwrite(\STDERR, "\n");

    return assertNsec(\trim((string) $line), 'stdin');
}

function assertNsec(string $nsec, string $where): string
{
    if (! isNsec($nsec)) {
        throw new \RuntimeException("{$where} does not hold an nsec (63 characters, `nsec1` then bech32)");
    }

    return $nsec;
}

/**
 * POST one signed JSON body to a registry route, and return the response.
 *
 * The bytes are sent unchanged, and the NWT binds the route and body digest, so
 * a body cannot be moved to another route. A non-2xx is returned rather than
 * thrown: the registry's errors are structured, and the caller decides how to
 * report them.
 *
 * @return array{status: int, reason: string, body: string}
 */
function postSignedWrite(string $base, string $route, string $json, string $nsec, string $npub): array
{
    $url = \rtrim($base, '/') . $route;

    return httpSend(
        $url,
        'POST',
        [...writeAuthHeaders(requestHost($url), 'POST', $route, $json, $nsec, $npub), 'Content-Type: application/json'],
        $json,
        false,
    );
}

/**
 * A registry error body (`{error, message}`) as one line, or the raw text.
 *
 * Shared by every write verb, so a refused `publish`, `bad` or `unmaintained`
 * reports the registry's machine code and message the same way.
 */
function registryError(string $body): string
{
    $decoded = \json_decode($body, true);
    if (\is_array($decoded) && isset($decoded['error'])) {
        return $decoded['error'] . ': ' . ($decoded['message'] ?? '');
    }

    return \trim($body) === '' ? 'no response body' : \trim($body);
}
