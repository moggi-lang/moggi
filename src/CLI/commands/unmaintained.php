<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Registry\isPackageName;
use function Moggi\Registry\postSignedWrite;
use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readNsecKey;
use function Moggi\Registry\registryError;
use function Moggi\Registry\shortNpub;

/**
 * `moggi unmaintained` — declare a package unmaintained, or clear the marker.
 *
 * `unmaintained` is a package-level field in the signed catalog shard, beside
 * `bad`, and is a self-declaration the owner (or an admin) makes. Unlike `bad` it
 * does not block: the package still works, so clients warn about it — `install`
 * notes it, and `show` / `search` display it. The note is optional, but a note is
 * the difference between a user knowing why and only knowing that.
 */
function unmaintainedUsage(): string
{
    $default = DEFAULT_REGISTRY;

    return <<<HELP
    usage:
      moggi unmaintained <name> [--note TEXT] [options]
      moggi unmaintained <name> --clear [options]

    Declare a package unmaintained — a package-level signal in the signed catalog —
    or clear an existing marker. It does not block a build: `install` warns, and
    `show` / `search` display it, because the package still works.

    There is no login: the request is signed with your npub, and the registry
    accepts it only from the package owner or an admin.

    options:
      --note TEXT          why it is unmaintained (optional; the reason a user reads)
      --clear              remove the marker instead of setting one
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
function runUnmaintainedCommand(array $argv): int
{
    $spec = new CommandSpec('unmaintained', unmaintainedUsage(), [
        ['name' => 'clear'],
        ['name' => 'yes'],
        ['name' => 'json'],
        ['name' => 'note', 'value' => true],
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
        \fwrite(\STDERR, "error: `moggi unmaintained` needs one package name\n\n" . unmaintainedUsage() . "\n");

        return 1;
    }

    try {
        return unmaintainedPackage($name, $options);
    } catch (\RuntimeException $error) {
        \fwrite(\STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * @param array<string, mixed> $options
 */
function unmaintainedPackage(string $name, array $options): int
{
    $clear = (bool) $options['clear'];
    $note = \trim((string) ($options['note'] ?? ''));
    if ($clear && $note !== '') {
        throw new \RuntimeException('--clear and --note contradict each other — clear the marker, or set one with a note');
    }

    $signer = governanceSigner($options['as']);
    $nsec = readNsecKey($options['nsec-file']);
    $base = \rtrim((string) $options['registry'], '/');
    $route = '/unmaintained';

    if (!$options['yes']) {
        confirmGovernanceAction($clear
            ? "clear the unmaintained marker on `{$name}` at {$base} as " . shortNpub($signer) . '?'
            : "mark `{$name}` unmaintained at {$base} as " . shortNpub($signer) . '?'
                . ($note === '' ? '' : "\n  note: {$note}"));
    }

    $marker = $note === '' ? true : ['note' => $note];
    $body = \json_encode(
        ['name' => $name, 'unmaintained' => $clear ? null : $marker],
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
            'unmaintained' => $clear ? null : $marker,
            'response' => \json_decode($response['body'], true),
        ]) . "\n";

        return 0;
    }

    \printf("%s — %s\n", $name, $clear ? 'unmaintained marker cleared' : 'marked unmaintained');
    if (!$clear && $note !== '') {
        \printf("note       %s\n", $note);
    }
    \printf("registry   %s\n", $base);
    \printf("signer     %s\n", shortNpub($signer));

    return 0;
}
