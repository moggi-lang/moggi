<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Registry\isPackageName;
use function Moggi\Registry\postSignedWrite;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readNsecKey;
use function Moggi\Registry\registryError;
use function Moggi\Registry\shortNpub;

/**
 * `moggi bad` — mark a package bad, or lift the marker, over the signed write
 * transport.
 *
 * `bad` is a package-level advisory in the signed catalog shard, so setting or
 * removing it is a registry write like `publish`: there is no login, and the
 * request is signed with the caller's npub. The registry decides who may act —
 * the package owner, or an admin on any package — and the client's job is to sign
 * as the identity it claims and to say plainly what it is about to do.
 *
 * A reason is required to mark: it is the text a user reads, so a marker without
 * one would be a refusal with no explanation. `--lift` removes the marker instead.
 * Both are one route (`POST /bad`); `--admin` uses the admin-only route
 * (`POST /admin/bad`) an admin may point at any package.
 */
function badUsage(): string
{
    $default = DEFAULT_REGISTRY;

    return <<<HELP
    usage:
      moggi bad <name> --reason TEXT [options]
      moggi bad <name> --lift [options]

    Mark a package bad — a package-level marker in the signed catalog, so it
    covers every release — or lift an existing marker. The marker is a refusal:
    `install`, `build` and `update` stop unless the operator passes `--allow-bad`,
    and `show` / `search` print it. The reason is what a user reads, so it is
    required to mark.

    There is no login: the request is signed with your npub, and the registry
    accepts it only from the package owner or an admin.

    options:
      --reason TEXT        why the package is bad (required to mark)
      --lift               remove the marker instead of setting one
      --registry URL|DIR   registry to write to (default: MOGGI_REGISTRY, else {$default})
      --as NPUB            the identity that signs (default: the descriptor here, when there is one)
      --nsec-file FILE     the signing key, mode 0600 (default: MOGGI_NSEC_FILE,
                           else a no-echo prompt on a terminal)
      --admin              sign through the admin-only route (`POST /admin/bad`)
      --yes                do not prompt for confirmation
      --json               machine-readable output
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runBadCommand(array $argv): int
{
    $spec = new CommandSpec('bad', badUsage(), [
        ['name' => 'lift'],
        ['name' => 'admin'],
        ['name' => 'yes'],
        ['name' => 'json'],
        ['name' => 'reason', 'value' => true],
        ['name' => 'as', 'value' => true],
        ['name' => 'nsec-file', 'value' => true],
    ]);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec);
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $name = \trim((string) $options['path']);
    if ($name === '' || $name === '.' || !isPackageName($name)) {
        \fwrite(\STDERR, "error: `moggi bad` needs one package name\n\n" . badUsage() . "\n");

        return 1;
    }

    try {
        return badPackage($name, $options);
    } catch (\RuntimeException $error) {
        \fwrite(\STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * @param array<string, mixed> $options
 */
function badPackage(string $name, array $options): int
{
    $lift = (bool) $options['lift'];
    $reason = \trim((string) ($options['reason'] ?? ''));
    if ($lift && $reason !== '') {
        throw new \RuntimeException('--lift and --reason contradict each other — lift the marker, or set one with a reason');
    }
    if (!$lift && $reason === '') {
        throw new \RuntimeException(
            'marking a package bad needs --reason TEXT (what a user reads); pass --lift to remove the marker instead',
        );
    }

    $signer = governanceSigner($options['as']);
    $nsec = readNsecKey($options['nsec-file']);
    $base = \rtrim((string) $options['registry'], '/');
    $route = $options['admin'] ? '/admin/bad' : '/bad';

    if (!$options['yes']) {
        confirmGovernanceAction($lift
            ? "lift the bad marker on `{$name}` at {$base} as " . shortNpub($signer) . '?'
            : "mark `{$name}` bad at {$base} as " . shortNpub($signer)
                . ($options['admin'] ? ' (admin route)' : '') . "?\n  reason: {$reason}");
    }

    $body = \json_encode(
        ['name' => $name, 'bad' => $lift ? null : ['reason' => $reason]],
        \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
    );
    $response = postSignedWrite($base, $route, $body, $nsec, $signer);
    if ($response['status'] !== 200) {
        throw new \RuntimeException('the registry refused the change (' . $response['status'] . '): ' . registryError($response['body']));
    }

    if ($options['json']) {
        echo prettyJson([
            'name' => $name,
            'registry' => $base,
            'signer' => $signer,
            'bad' => $lift ? null : ['reason' => $reason],
            'response' => \json_decode($response['body'], true),
        ]) . "\n";

        return 0;
    }

    \printf("%s — %s\n", $name, $lift ? 'bad marker lifted' : 'marked bad');
    if (!$lift) {
        \printf("reason     %s\n", $reason);
    }
    \printf("registry   %s\n", $base);
    \printf("signer     %s\n", shortNpub($signer));

    return 0;
}
