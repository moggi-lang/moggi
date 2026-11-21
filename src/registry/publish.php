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
 * A file has to be private: mode 0600, or on Windows an ACL that grants nobody
 * but its owner, `SYSTEM` and `Administrators` — a secret key that group or
 * other can read is not secret, and failing here costs a `chmod` rather than the
 * key. A run with no terminal and no file fails
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
    } else {
        assertWindowsKeyFileIsPrivate($path);
    }

    return assertNsec(\trim((string) \file_get_contents($path)), $path);
}

/**
 * Whether an SDDL access control list leaves a key file private.
 *
 * SDDL is the form `Get-Acl` prints in one line and in SIDs: `O:` owner, `G:`
 * group, `D:` DACL, and `(A;;FA;;;S-1-5-21-…)` one allow rule for that
 * principal. The localized `Access` list this check used to read had two
 * problems a machine nobody is sitting at made expensive: the names are
 * translated per install, and a listing that never arrived was the same empty
 * string as a file with no rules at all. One line of SDDL has neither.
 *
 * The owner is allowed to read its own key; every other principal has to be
 * `SYSTEM` or `Administrators`, which is what a POSIX mode calls the root
 * account, plus `OWNER RIGHTS` — the right the owner already holds. Anything
 * else — `Everyone`, `Users`, `Authenticated Users`, a named second account — is
 * the `other` or `group` bit in disguise.
 */
function windowsKeyAclIsPrivate(string $sddl): bool
{
    return windowsKeyAclRefusalReason($sddl) === '';
}

/**
 * Why an SDDL list is not private, or `''` when it is.
 *
 * Three cases a mode cannot spell out are worth separating for whoever reads the
 * refusal: a `null` DACL, which Windows reads as `Everyone` with full control; a
 * rule for somebody else; and a list this client could not read at all. An empty
 * DACL is none of them — it grants nobody anything, which is private.
 */
function windowsKeyAclRefusalReason(string $sddl): string
{
    $acl = windowsAclSddl($sddl);
    if ($acl === null) {
        return 'its ACL could not be read';
    }
    if ($acl['nullDacl']) {
        return 'its DACL grants everyone full control';
    }

    $allowed = ['S-1-5-18', 'S-1-5-32-544', 'S-1-3-4', 'S-1-3-0', $acl['owner']];
    foreach ($acl['allow'] as $sid) {
        if (! \in_array($sid, $allowed, true)) {
            return 'its ACL grants access to someone besides you, SYSTEM and Administrators';
        }
    }

    return '';
}

/**
 * The owner and the allow principals of an SDDL string, or `null` when it cannot
 * be read.
 *
 * `O:` names the owner, `D:` starts the DACL, and each `(…)` is one rule: its
 * type first (`A` for allow, `D` for deny, `OA` for an object allow), its flags
 * and rights, and the SID last. Deny and audit rules are skipped — a deny takes
 * access away, which is the direction this check wants.
 *
 * @return array{owner: string, allow: list<string>, nullDacl: bool}|null
 */
function windowsAclSddl(string $sddl): ?array
{
    $sddl = \trim($sddl);
    if (\preg_match('/^O:([A-Z]{2}|S-\d+(?:-\d+)+)/', $sddl, $owner) !== 1) {
        return null;
    }
    $ownerSid = windowsAclSid($owner[1]);
    if ($ownerSid === null) {
        return null;
    }

    $daclAt = \strpos($sddl, 'D:');
    if ($daclAt === false) {
        return null;
    }
    $dacl = \substr($sddl, $daclAt + 2);
    if (\str_starts_with($dacl, 'NO_ACCESS_CONTROL')) {
        return ['owner' => $ownerSid, 'allow' => [], 'nullDacl' => true];
    }

    $allow = [];
    \preg_match_all('/\(([^)]*)\)/', $dacl, $rules);
    foreach ($rules[1] as $rule) {
        $fields = \explode(';', $rule);
        $type = $fields[0] ?? '';
        if ($type !== 'A' && $type !== 'OA') {
            continue;
        }
        $sid = windowsAclSid($fields[\count($fields) - 1] ?? '');
        if ($sid === null) {
            return null;
        }
        $allow[] = $sid;
    }

    return ['owner' => $ownerSid, 'allow' => $allow, 'nullDacl' => false];
}

/**
 * The SID an SDDL principal stands for, or `null` when it is not one this client knows.
 *
 * SDDL writes a well-known principal as a two-letter code — `SY` for SYSTEM, `BA` for
 * Administrators, `WD` for `Everyone`, `OW` for `OWNER RIGHTS` — and everything else as the SID
 * itself. Both spellings have to become the same value, or a rule for `WD` would read as a
 * principal this check has never heard of and `Everyone` would pass as private.
 */
function windowsAclSid(string $principal): ?string
{
    if (\preg_match('/^S-\d+(?:-\d+)+$/', $principal) === 1) {
        return $principal;
    }

    return match ($principal) {
        'SY' => 'S-1-5-18',
        'BA' => 'S-1-5-32-544',
        'BU' => 'S-1-5-32-545',
        'WD' => 'S-1-1-0',
        'AU' => 'S-1-5-11',
        'OW' => 'S-1-3-4',
        'CO' => 'S-1-3-0',
        default => null,
    };
}

/**
 * Refuse a Windows key file whose ACL is not private.
 *
 * PHP cannot see an ACL, so PowerShell prints it as SDDL: one line, SIDs only,
 * so nothing depends on the install's language and nothing can be lost between
 * several writes to a redirected pipe. A missing PowerShell or an unreadable ACL
 * fails the check rather than passing it: the whole point is that a key is read
 * only from a file a `chmod 600` would have left alone.
 */
function assertWindowsKeyFileIsPrivate(string $path): void
{
    $refusal = static fn (string $why): \RuntimeException => new \RuntimeException(
        "{$path} is not private: {$why} — a secret key file must be readable only by you (fix its ACL, or use the interactive prompt)",
    );

    $powershell = findExecutable('powershell') ?? findExecutable('pwsh');
    if ($powershell === null) {
        throw $refusal('there is no PowerShell to read its ACL');
    }

    $literal = "'" . \str_replace("'", "''", $path) . "'";
    $script = 'try { (Get-Acl -LiteralPath ' . $literal . ').Sddl }'
        . ' catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }';

    $process = \proc_open(
        [$powershell, '-NoProfile', '-NonInteractive', '-Command', $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (! \is_resource($process) || ! isset($pipes[1], $pipes[2])) {
        throw $refusal('PowerShell could not be started to read it');
    }
    \fclose($pipes[0]);
    $sddl = (string) \stream_get_contents($pipes[1]);
    $error = \trim((string) \stream_get_contents($pipes[2]));
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $code = \proc_close($process);

    if ($code !== 0) {
        throw $refusal($error !== '' ? $error : 'PowerShell could not read its ACL');
    }
    if (! windowsKeyAclIsPrivate($sddl)) {
        throw $refusal(windowsKeyAclRefusalReason($sddl));
    }
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
