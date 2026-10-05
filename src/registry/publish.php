<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * The write half of a registry client: signing, and the HTTP that carries it.
 *
 * There is no login. Every write is authenticated by a BIP-340 signature from the
 * author's npub, over a canonical string that binds the method, the path, a
 * timestamp, a nonce and the exact body bytes (`registry-spec.md` §7). The nonce
 * makes each signature single-use; the timestamp bounds how long it is worth
 * trying. The worker and the browser front end build the same bytes, so a
 * difference shows up as a signature that fails to verify rather than as a write
 * that lands under the wrong identity.
 *
 * Signing goes through the project's own `schnorr` CLI — the same binary `verify`
 * uses — so there is one implementation of BIP-340 in the tree, not two. The nsec
 * is written to that process's stdin, never to an argument: argv is world-readable.
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
        throw new \RuntimeException(
            'no `schnorr` binary on the PATH — install a distribution that ships it, or put it there',
        );
    }

    $process = \proc_open(
        [$binary, 'sign', $messageHex],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!\is_resource($process)) {
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
 * The worker builds the signed string from `new URL(request.url).host`, so the
 * two have to agree character for character or every write fails. This is that
 * same rule, in PHP.
 */
function requestHost(string $url): string
{
    $host = \strtolower((string) (\parse_url($url, \PHP_URL_HOST) ?? ''));
    $port = \parse_url($url, \PHP_URL_PORT);
    $scheme = \parse_url($url, \PHP_URL_SCHEME);
    if (\is_int($port)
        && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
        return $host . ':' . $port;
    }

    return $host;
}

/**
 * The canonical bytes a request signature covers, as the worker's `auth.ts`
 * builds them.
 *
 * The host is part of the message so a signature made for one registry cannot be
 * replayed against another origin that accepts the same routes — a mirror, a
 * look-alike host, a proxy — inside the timestamp window. The path alone does not
 * bind the destination; the host does.
 */
function writeMessageHex(string $host, string $method, string $path, int $timestamp, string $nonce, string $body): string
{
    return \hash('sha256', "moggi-write\n{$host}\n{$method}\n{$path}\n{$timestamp}\n{$nonce}\n" . \hash('sha256', $body));
}

/**
 * The headers that authenticate one request.
 *
 * The signature is checked against the declared npub before it is sent: signing
 * with a key that is not the author's would otherwise only fail at the registry,
 * after a round trip and a confusing error.
 *
 * @return list<string>
 */
function writeAuthHeaders(string $host, string $method, string $path, string $body, string $nsec, string $npub): array
{
    $timestamp = \time();
    $nonce = \bin2hex(\random_bytes(16));
    $message = writeMessageHex($host, $method, $path, $timestamp, $nonce, $body);
    $signature = signMessage($message, $nsec);

    $verdict = verifySignature($message, $signature, $npub);
    if (!$verdict['ok']) {
        throw new \RuntimeException("the key does not match author {$npub}: {$verdict['note']}");
    }

    return [
        'x-moggi-npub: ' . $npub,
        'x-moggi-sig: ' . $signature,
        'x-moggi-date: ' . $timestamp,
        'x-moggi-nonce: ' . $nonce,
    ];
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
 * The nsec to sign with, from `--nsec-file`, `MOGGI_NSEC_FILE`, or a no-echo
 * prompt on a terminal.
 *
 * A file has to be mode 0600: a secret key that group or other can read is not
 * secret, and failing here costs a `chmod` rather than the key. A run with no
 * terminal and no file fails loudly instead of hanging on a read — a CI job that
 * silently waits for a prompt is worse than one that stops.
 */
function readNsecKey(?string $file): string
{
    $path = $file ?? ((\getenv('MOGGI_NSEC_FILE') ?: null) ?: null);
    if ($path === null) {
        if (!\function_exists('posix_isatty') || !\posix_isatty(\STDIN)) {
            throw new \RuntimeException(
                'no key: pass --nsec-file FILE (or set MOGGI_NSEC_FILE), or run on a terminal to be prompted',
            );
        }

        return promptNsec();
    }

    if (!\is_file($path)) {
        throw new \RuntimeException("no such key file: {$path}");
    }
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

    return assertNsec(\trim((string) \file_get_contents($path)), $path);
}

/** Prompt for the key without echoing it. */
function promptNsec(): string
{
    \fwrite(\STDERR, 'nsec (input hidden): ');
    $hidden = \stripos(\PHP_OS_FAMILY, 'Windows') === false;
    if ($hidden) {
        @\shell_exec('stty -echo 2>/dev/null');
    }
    $line = \fgets(\STDIN);
    if ($hidden) {
        @\shell_exec('stty echo 2>/dev/null');
        \fwrite(\STDERR, "\n");
    }

    return assertNsec(\trim((string) $line), 'stdin');
}

function assertNsec(string $nsec, string $where): string
{
    if (!isNsec($nsec)) {
        throw new \RuntimeException("{$where} does not hold an nsec (63 characters, `nsec1` then bech32)");
    }

    return $nsec;
}

/**
 * The author whose npub signs the release.
 *
 * `--as` names one; without it a single-author descriptor is unambiguous, and
 * several authors without a choice is an error rather than a guess — signing as
 * the wrong identity is not a mistake that should be quiet.
 *
 * @param list<string> $authors
 */
function selectAuthor(array $authors, ?string $as): string
{
    if ($as !== null) {
        if (!\in_array($as, $authors, true)) {
            throw new \RuntimeException("`{$as}` is not an npub in this package's [author] blocks");
        }

        return $as;
    }
    if (\count($authors) === 1) {
        return $authors[0];
    }
    if ($authors === []) {
        throw new \RuntimeException('the descriptor names no author to sign as');
    }

    throw new \RuntimeException(
        'this package has several authors — choose one with --as NPUB: ' . \implode(', ', $authors),
    );
}
