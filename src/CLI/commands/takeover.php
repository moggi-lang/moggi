<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Registry\isPackageName;
use function Moggi\Registry\postSignedWrite;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readNsecKey;
use function Moggi\Registry\registryError;
use function Moggi\Registry\shortNpub;

/**
 * `moggi takeover` — file a request to take over an abandoned package, or decide
 * one as an admin.
 *
 * A takeover is not a silent ownership change: it is a public request, a waiting
 * period, and an admin's recorded decision (`registry-governance-plan.md` §3).
 * The record is written into the registry at `requests/takeover/<name>.json`, and
 * the registry's git history is the audit log, so the whole process is visible.
 * The client's part is to file the request — signed with the requester's npub —
 * and to say plainly what happens next: the owner has the waiting period to
 * object, and the admin decides after it. `approve` and `reject` are the admin's
 * side of the same flow, over the admin-only routes.
 */
function takeoverUsage(): string
{
    $default = DEFAULT_REGISTRY;

    return <<<HELP
    usage:
      moggi takeover request <name> --reason TEXT [--repo URL] [options]
      moggi takeover approve <name> [--note TEXT] [options]
      moggi takeover reject  <name> [--note TEXT] [options]

    Ask to take over an abandoned package, Hackage-style, or decide such a request
    as an admin. A request is public and signed: it is recorded at
    requests/takeover/<name>.json, the owner has a waiting period to object, and an
    admin decides after it. Approving hands the package over — owner, allowed users
    and the unmaintained marker move together, in one signed change.

    There is no login: the request is signed with your npub. Filing one changes
    nothing about the package, so any npub may ask; deciding one is admin-only.

    options:
      --reason TEXT        why you should take the package over (required to request)
      --repo URL           where you intend to maintain it (optional)
      --note TEXT          the note an admin records with a decision
      --registry URL|DIR   registry to write to (default: MOGGI_REGISTRY, else {$default})
      --as NPUB            the identity that signs (default: the descriptor here, when there is one)
      --nsec-file FILE     the signing key, mode 0600 (default: MOGGI_NSEC_FILE,
                           else a no-echo prompt on a terminal)
      --yes                do not prompt for confirmation
      --json               machine-readable output
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runTakeoverCommand(array $argv): int
{
    $spec = new CommandSpec('takeover', takeoverUsage(), [
        ['name' => 'yes'],
        ['name' => 'json'],
        ['name' => 'reason', 'value' => true],
        ['name' => 'repo', 'value' => true],
        ['name' => 'note', 'value' => true],
        ['name' => 'as', 'value' => true],
        ['name' => 'nsec-file', 'value' => true],
    ], verb: true);

    $verb = $argv[2] ?? '';
    if (!\in_array($verb, ['request', 'approve', 'reject'], true)) {
        if (wantsHelp($argv)) {
            echo commandHelp($spec);

            return 0;
        }
        \fwrite(\STDERR, "error: `moggi takeover` takes one of request, approve, reject\n\n" . takeoverUsage() . "\n");

        return 1;
    }

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec, 3);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $name = \trim((string) $options['path']);
    if ($name === '' || $name === '.' || !isPackageName($name)) {
        \fwrite(\STDERR, "error: `moggi takeover {$verb}` needs one package name\n\n" . takeoverUsage() . "\n");

        return 1;
    }

    try {
        return $verb === 'request'
            ? takeoverRequest($name, $options)
            : takeoverDecision($verb, $name, $options);
    } catch (\RuntimeException $error) {
        \fwrite(\STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * File the request, and print the steps the process expects next.
 *
 * @param array<string, mixed> $options
 */
function takeoverRequest(string $name, array $options): int
{
    $reason = \trim((string) ($options['reason'] ?? ''));
    if ($reason === '') {
        throw new \RuntimeException('a takeover request needs --reason TEXT (why you should take the package over)');
    }
    $repo = \trim((string) ($options['repo'] ?? ''));

    $signer = governanceSigner($options['as']);
    $nsec = readNsecKey($options['nsec-file']);
    $base = \rtrim((string) $options['registry'], '/');

    if (!$options['yes']) {
        confirmGovernanceAction(
            "file a public takeover request for `{$name}` at {$base} as " . shortNpub($signer) . '?'
            . "\n  reason: {$reason}" . ($repo === '' ? '' : "\n  repo: {$repo}"),
        );
    }

    $body = \json_encode(
        ['name' => $name, 'reason' => $reason] + ($repo === '' ? [] : ['repo' => $repo]),
        \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
    );
    $response = postSignedWrite($base, '/takeover-request', $body, $nsec, $signer);
    if ($response['status'] !== 200) {
        throw new \RuntimeException('the registry refused the request (' . $response['status'] . '): ' . registryError($response['body']));
    }

    $decoded = \json_decode($response['body'], true);
    $waitUntil = \is_array($decoded) ? \trim((string) ($decoded['wait_until'] ?? '')) : '';

    if ($options['json']) {
        $payload = [
            'name' => $name,
            'registry' => $base,
            'requester' => $signer,
            'reason' => $reason,
        ];
        if ($repo !== '') {
            $payload['repo'] = $repo;
        }
        if ($waitUntil !== '') {
            $payload['wait_until'] = $waitUntil;
        }
        $payload['response'] = \is_array($decoded) ? $decoded : null;
        echo prettyJson($payload) . "\n";

        return 0;
    }

    \printf("%s — takeover request filed\n", $name);
    \printf("requester  %s\n", shortNpub($signer));
    if ($waitUntil !== '') {
        \printf("wait until %s\n", $waitUntil);
    }
    \printf("registry   %s\n", $base);
    echo "\n";
    \printf("the request is public at requests/takeover/%s.json; the registry's git history is the log\n", $name);
    echo "next: email the registry admin (its contact is published with the registry) that the request is open\n";
    echo "      the owner has the waiting period to object, and the admin decides after it\n";

    return 0;
}

/**
 * Approve or reject a pending request, over the admin-only route.
 *
 * @param array<string, mixed> $options
 */
function takeoverDecision(string $verb, string $name, array $options): int
{
    $approve = $verb === 'approve';
    $note = \trim((string) ($options['note'] ?? ''));

    $signer = governanceSigner($options['as']);
    $nsec = readNsecKey($options['nsec-file']);
    $base = \rtrim((string) $options['registry'], '/');
    $route = '/admin/takeover-requests/' . $name;

    if (!$options['yes']) {
        confirmGovernanceAction(
            ($approve
                ? "approve the takeover of `{$name}` — the package is handed to the requester"
                : "reject the takeover request for `{$name}`")
            . " at {$base} as " . shortNpub($signer) . '?'
            . ($note === '' ? '' : "\n  note: {$note}"),
        );
    }

    $body = \json_encode(
        ['decision' => $approve ? 'approve' : 'reject'] + ($note === '' ? [] : ['note' => $note]),
        \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
    );
    $response = postSignedWrite($base, $route, $body, $nsec, $signer);
    if ($response['status'] !== 200) {
        throw new \RuntimeException('the registry refused the decision (' . $response['status'] . '): ' . registryError($response['body']));
    }

    if ($options['json']) {
        $payload = [
            'name' => $name,
            'registry' => $base,
            'signer' => $signer,
            'decision' => $approve ? 'approve' : 'reject',
        ];
        if ($note !== '') {
            $payload['note'] = $note;
        }
        $payload['response'] = \json_decode($response['body'], true);
        echo prettyJson($payload) . "\n";

        return 0;
    }

    \printf("%s — takeover %s\n", $name, $approve ? 'approved: the package is handed over' : 'request rejected');
    if (!$approve && $note !== '') {
        \printf("note       %s\n", $note);
    }
    \printf("registry   %s\n", $base);
    \printf("signer     %s\n", shortNpub($signer));

    return 0;
}
