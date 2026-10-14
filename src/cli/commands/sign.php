<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Registry\prettyJson;
use function Moggi\Registry\readNsecKey;
use function Moggi\Registry\readReleaseRecord;
use function Moggi\Registry\signRelease;
use function Moggi\Registry\writeReleaseRecord;

/**
 * `moggi sign` — sign an unsigned release record, offline.
 *
 * The record is what `pack --record` prints: provenance, artifact, version and
 * declaration, but no signature. Signing attaches one, made with the author's
 * nsec over the canonical manifest, and verifies it straight back before the
 * record is written — so a wrong key fails here, next to the key. The signed
 * record then goes to `publish --signed`, which checks it against the tree and
 * uploads it. No network happens here; a machine without the key never sees it.
 */
function signUsage(): string
{
    return <<<HELP
    usage:
      moggi sign RECORD-FILE [-o FILE] [--as NPUB] [--nsec-file FILE]

    Sign an unsigned release record (as `pack --record` prints) with an
    author's nsec, and write the signed record. The signature is verified
    straight back before writing, so a key that is not the author's fails here
    rather than at the registry.

    options:
      -o, --output FILE    write the signed record here (default: print to stdout)
      --as NPUB            the signing author — required, since a bare record
                           names no authors to choose from
      --nsec-file FILE     the signing key, mode 0600 (default: MOGGI_NSEC_FILE,
                           else a no-echo prompt on a terminal)
      -h, --help           show this help
    HELP;
}

/** @param list<string> $argv */
function runSignCommand(array $argv): int
{
    $spec = new CommandSpec('sign', signUsage(), [
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

    try {
        $signed = signRecordFile($options['path'], $options['as'], $options['nsec-file']);

        if ($options['output'] !== null) {
            writeReleaseRecord($options['output'], $signed);
            \printf("signed %s %s\n", $signed['name'], $signed['version']);

            return 0;
        }

        echo prettyJson($signed) . "\n";

        return 0;
    } catch (\RuntimeException $error) {
        \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * Read an unsigned record, sign it, and return the signed record.
 *
 * @return array<string, mixed>
 */
function signRecordFile(string $path, ?string $as, ?string $nsecFile): array
{
    $record = readReleaseRecord($path);
    if ($as === null) {
        throw new \RuntimeException('--as NPUB names the signing author — a bare record carries no authors to choose from');
    }
    $nsec = readNsecKey($nsecFile);

    return signRelease((string) $record['name'], (string) $record['version'], $record, $as, $nsec);
}
