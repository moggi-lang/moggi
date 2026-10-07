<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\Registry\findDescriptor;
use function Moggi\Registry\isNpub;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\selectAuthor;

/**
 * What the governance write verbs share: choosing the identity that signs, and
 * the confirmation a public, signed change deserves.
 *
 * `moggi bad`, `moggi unmaintained` and a takeover request are not tied to a
 * release, so unlike `publish` they cannot always read the signer out of a local
 * descriptor: an admin acting on an abandoned package has none, and names the
 * identity with `--as`.
 */

/**
 * The identity that signs: `--as`, or the single author of a descriptor in the
 * current directory.
 */
function governanceSigner(?string $as): string
{
    if ($as !== null) {
        if (!isNpub($as)) {
            throw new \RuntimeException("`--as {$as}` is not an npub (63 characters, `npub1` then bech32)");
        }

        return $as;
    }

    try {
        $descriptor = readDescriptor(findDescriptor('.'));
    } catch (\RuntimeException $error) {
        throw new \RuntimeException(
            'no descriptor here to take the signing identity from — pass --as NPUB (' . $error->getMessage() . ')',
        );
    }

    return selectAuthor($descriptor['authors'], null);
}

/**
 * State what is about to happen and ask before doing it, on a terminal.
 *
 * The prompt goes to stderr so `--json` output stays clean, and a run without a
 * terminal proceeds as `publish` does — `--yes` is what makes that explicit.
 */
function confirmGovernanceAction(string $summary): void
{
    if (!\function_exists('posix_isatty') || !\posix_isatty(\STDIN)) {
        return;
    }

    \fwrite(\STDERR, $summary . "\nthis is signed and public. [y/N] ");
    $answer = \trim((string) \fgets(\STDIN));
    if ($answer !== 'y' && $answer !== 'Y' && $answer !== 'yes') {
        throw new \RuntimeException('cancelled');
    }
}
