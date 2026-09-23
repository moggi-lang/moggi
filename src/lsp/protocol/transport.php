<?php declare(strict_types=1);

namespace Moggi\LSP\Protocol;

function pathToUri(string $path): string
{
    $real = realpath($path);
    if ($real !== false) {
        $path = $real;
    }
    $path = str_replace('\\', '/', $path);
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    $parts = explode('/', $path);
    $encoded = [];
    foreach ($parts as $i => $part) {
        // Keep empty segments (leading slash → first empty part).
        $encoded[] = $part === '' && $i === 0 ? '' : rawurlencode($part);
    }

    return 'file://' . implode('/', $encoded);
}

/**
 * Convert a `file://` (or any) URI to a filesystem path.
 */
function uriToPath(string $uri): string
{
    $uri = trim($uri);
    if (!str_starts_with($uri, 'file://')) {
        // Not a file URI (e.g. untitled:); return as-is so callers can decide.
        return $uri;
    }
    $path = substr($uri, strlen('file://'));
    // Windows file:///C:/... sometimes arrives as /C:/...
    $path = rawurldecode($path);

    return $path;
}

/**
 * Detect whether a URI designates a `.mog` source file.
 */
function isMoguri(string $uri): bool
{
    return str_ends_with(strtolower($uri), '.mog');
}

/**
 * Read one JSON-RPC message from a stream handle.
 *
 * @param resource $stream The PHP stream to read from (defaults to STDIN).
 * @param bool $malformed Set to true when the frame was unusable (missing
 *   Content-Length or invalid JSON body). The caller must answer with the
 *   appropriate JSON-RPC error and may continue reading; the stream stays in
 *   sync for length-prefixed frames with a bad body.
 * @return mixed[]|null|false The decoded message body, null at real EOF, or
 *   false when the frame was malformed.
 */
function readMessage($stream = null, ?bool &$malformed = null): array|null|false
{
    $stream ??= \STDIN;
    $malformed = false;

    $contentLength = null;
    $sawHeaderLine = false;
    while (true) {
        $line = fgets($stream);
        if ($line === false) {
            // EOF before any header byte of this frame → clean shutdown.
            return $sawHeaderLine ? false : null;
        }
        if ($line === "\r\n" || $line === "\n") {
            break;
        }
        $sawHeaderLine = true;
        if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m)) {
            $contentLength = (int) $m[1];
        }
    }

    if ($contentLength === null || $contentLength <= 0) {
        // Frame without a (valid) Content-Length header. The caller answers
        // InvalidRequest upstream and resynchronizes on the next frame.
        $malformed = true;
        return false;
    }

    $body = '';
    $remaining = $contentLength;
    while ($remaining > 0) {
        $chunk = fread($stream, $remaining);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $body .= $chunk;
        $remaining -= strlen($chunk);
    }

    $decoded = json_decode($body, true);
    if (!\is_array($decoded)) {
        // Known body length → stream stays in sync; answer ParseError and go on.
        $malformed = true;
        return false;
    }

    return $decoded;
}

/**
 * Wait up to $timeoutSec for a message. Returns:
 * - array: decoded message
 * - null: EOF
 * - false: timeout (no message yet)
 *
 * Uses stream_select so the LSP loop can flush debounced analyzes without threads.
 * Non-selectable streams (e.g. php://memory in tests) treat timeout as idle.
 *
 * @param resource|null $stream
 * @return array|null|false
 */
function readMessageOrTimeout($stream = null, ?float $timeoutSec = null, ?bool &$malformed = null): array|null|false
{
    $stream ??= \STDIN;
    $malformed = false;
    if ($timeoutSec === null || $timeoutSec < 0) {
        return readMessage($stream, $malformed);
    }
    $sec = (int) floor($timeoutSec);
    $usec = (int) max(0, ($timeoutSec - $sec) * 1_000_000);
    $read = [$stream];
    $write = null;
    $except = null;
    try {
        // PHP reports non-selectable wrappers (notably php://memory in tests)
        // as a warning rather than an exception; false is the intended idle result.
        $n = @stream_select($read, $write, $except, $sec, $usec);
    } catch (\Throwable) {
        // php://memory and other non-selectable streams
        return false;
    }
    if ($n === false || $n === 0 || $read === []) {
        return false;
    }
    return readMessage($stream, $malformed);
}

/**
 * Write a JSON-RPC message to a stream handle.
 *
 * @param mixed[] $message The message (jsonrpc/id/method/params/result/error).
 * @param resource $stream The PHP stream to write to (defaults to STDOUT).
 */
function writeMessage(array $message, $stream = null): void
{
    $stream ??= \STDOUT;
    $body = json_encode(
        $message,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    if ($body === false) {
        $body = '{}';
    }
    fwrite($stream, "Content-Length: " . strlen($body) . "\r\n");
    fwrite($stream, "Content-Type: application/vscode-jsonrpc; charset=utf-8\r\n");
    fwrite($stream, "\r\n");
    fwrite($stream, $body);
    fflush($stream);
}
