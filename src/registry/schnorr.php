<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Install\installationRoot;

/**
 * The `schnorr` CLI — the one BIP-340 implementation the client uses, resolved
 * beside the moggi executable and never from `PATH`.
 *
 * Every signature the client checks goes through this binary: the registry root,
 * every release, and the client's own pre-send check. It is therefore the trust
 * root of the whole model, and a `schnorr` picked up from `PATH` — a project
 * `bin/`, a writable directory, a `PATH` edit a dependency made — that simply
 * exits 0 would verify a forged catalog. So the search is a fixed set of known
 * locations, and a missing binary is an error at the point of use, never a
 * fallback to a shell.
 *
 * Locations, in order:
 *
 *   - `<installation>/bin/schnorr`, where the installation is the phar's parent
 *     (`<install>/bin/moggi.phar` → `<install>`);
 *   - a source checkout's `<root>/bin/schnorr` and the binary `make` writes
 *     beside the C sources, `<root>/schnorr/schnorr` — the checkout *is* the
 *     installation when the compiler runs from source.
 *
 * There is no override, and deliberately so: the verifier is part of the
 * compiler that uses it, so "which binary" has exactly one answer and no
 * accident of environment can change it.
 */
function schnorrBinary(): ?string
{
    foreach (schnorrCandidatePaths() as $candidate) {
        if (\is_file($candidate) && \is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The known locations of the bundled binary, most authoritative first.
 *
 * Kept separate from the lookup so a caller that wants to explain the failure
 * can name where it looked.
 *
 * @return list<string>
 */
function schnorrCandidatePaths(): array
{
    $name = \PHP_OS_FAMILY === 'Windows' ? 'schnorr.exe' : 'schnorr';
    $separator = \DIRECTORY_SEPARATOR;
    $candidates = [];

    $installation = installationRoot();
    if ($installation !== null) {
        $candidates[] = $installation . $separator . 'bin' . $separator . $name;
    }

    $checkout = \dirname(__DIR__, 2);
    $candidates[] = $checkout . $separator . 'bin' . $separator . $name;
    $candidates[] = $checkout . $separator . 'schnorr' . $separator . $name;

    return $candidates;
}

/** A one-line explanation of where the bundled binary was expected, for errors. */
function schnorrMissingNote(): string
{
    $tried = schnorrCandidatePaths();
    $list = $tried === [] ? '' : ' (looked in: ' . \implode(', ', $tried) . ')';

    return 'no bundled `schnorr` binary found' . $list
        . ' — install a distribution that ships it, or build it with `make -C schnorr` in a source checkout';
}
