<?php declare(strict_types=1);

/**
 * Packages shipped with the compiler resolve and install locally without
 * network access when their bundled versions satisfy the requested
 * constraints. Otherwise, resolution falls back to the registry.
 *
 * Tests point the compiler at a fake `libraries/` tree and use a `$fetch` that
 * fails on any network access, proving bundled resolution stays offline.
 * `base` is included as a provided package to exercise transitive closures.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Install\ConfiguredInstallation;

use function Moggi\CLI\Commands\writeResolution;

use function Moggi\Registry\archiveDigest;
use function Moggi\Registry\bundledArchiveDigest;
use function Moggi\Registry\bundledConstraintFor;
use function Moggi\Registry\bundledEntry;
use function Moggi\Registry\bundledPackages;
use function Moggi\Registry\checkBundledInstalled;
use function Moggi\Registry\demandsCloseLocally;

use Moggi\Registry\FetchLog;

use function Moggi\Registry\installBundledPackage;
use function Moggi\Registry\installLockedPackages;
use function Moggi\Registry\isInstalled;
use function Moggi\Registry\lockDisagreements;
use function Moggi\Registry\lockDocument;
use function Moggi\Registry\lockIsLocal;
use function Moggi\Registry\packDirectory;
use function Moggi\Registry\pinBundledDemands;
use function Moggi\Registry\providedPackages;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\resolveWith;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$work = createTempDir('moggi-bundled');
$author = "[author]\nname = Test\nemail = test@example.com\n"
    . "npub = npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm\n";
$manifest = static function (string $name, string $version, string $extra = '') use ($author): string {
    return "[package]\nname = {$name}\nversion = {$version}\nbackends = php\n\n"
        . $author . "\n[lib]\nsource-dirs = src\n"
        . ($extra === '' ? '' : "\n{$extra}\n");
};
$writeLib = static function (string $name, string $version, string $extra = '') use ($work, $manifest): string {
    $dir = $work . '/libraries/' . $name;
    \mkdir($dir . '/src', 0777, true);
    \file_put_contents($dir . '/' . $name . '.moggi', $manifest($name, $version, $extra));
    \file_put_contents($dir . '/src/Dummy.mog', "-- {$name}\n");

    return $dir;
};

$oldRoot = ConfiguredInstallation::root();
$oldCache = \getenv('MOGGI_CACHE_DIR');
$oldUserCache = \getenv('MOGGI_USER_CACHE');
ConfiguredInstallation::set($work);
\putenv('MOGGI_CACHE_DIR=' . $work . '/cache');
\putenv('MOGGI_USER_CACHE=' . $work . '/ucache');

$remove = static function (string $path) use (&$remove): void {
    if (! \file_exists($path) && ! \is_link($path)) {
        return;
    }
    if (\is_dir($path) && ! \is_link($path)) {
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        @\rmdir($path);

        return;
    }
    @\unlink($path);
};

try {
    $writeLib('base', '9.9.9');
    $alphaDir = $writeLib('alpha', '1.2.0', "[dependencies]\nbase = 9\n");
    $betaDir = $writeLib('beta', '0.3.1', "[dependencies]\nalpha = 1.2\n");

    // --- the scan -----------------------------------------------------------
    $bundled = bundledPackages();
    $assert(isset($bundled['alpha'], $bundled['beta']), 'the shipped libraries are found: ' . \json_encode(\array_keys($bundled)));
    $assert(! isset($bundled['base']), '`base` stays provided, never bundled');
    $assert($bundled['alpha']['version'] === '1.2.0' && $bundled['alpha']['dir'] === $alphaDir, 'a bundled entry names its version and dir');
    $assert(bundledConstraintFor('1.2.0') === '1.2', 'the recorded line is the bundled major line');
    $assert(bundledConstraintFor('2') === '2', 'a version without a minor records as-is');

    // --- pinning -------------------------------------------------------------
    [$demands, $pins] = pinBundledDemands(['alpha' => '', 'beta' => '0.3', 'gamma' => ''], $bundled);
    $assert($pins === ['alpha' => '1.2.0', 'beta' => '0.3.1'], 'satisfied demands pin to the carried versions: ' . \json_encode($pins));
    $assert($demands['alpha'] === '=1.2.0' && $demands['beta'] === '=0.3.1' && $demands['gamma'] === '', 'pins are exact, the rest passes through');
    [$demands, $pins] = pinBundledDemands(['alpha' => '=9.9', 'beta' => '>=0.1'], $bundled);
    $assert($pins === ['beta' => '0.3.1'] && $demands['alpha'] === '=9.9', 'a demand the carried version cannot meet stays for the registry');

    // --- the local gate -------------------------------------------------------
    $provided = providedPackages();
    $assert($provided === ['base' => '9.9.9'], 'the fixture base is provided: ' . \json_encode($provided));
    $assert(demandsCloseLocally(['beta' => ''], $bundled, $provided), 'a closure through bundled and provided needs no catalog');
    $assert(demandsCloseLocally(['alpha' => '1.2', 'beta' => '0.3'], $bundled, $provided), 'satisfied lines close locally');
    $assert(! demandsCloseLocally(['gamma' => ''], $bundled, $provided), 'an unshipped name needs the catalog');
    $assert(! demandsCloseLocally(['alpha' => '=9.9'], $bundled, $provided), 'an unsatisfiable demand needs the catalog');
    $assert(! demandsCloseLocally(['base' => '=1.0'], $bundled, $provided), 'an unsatisfied toolchain floor needs the catalog');
    $assert(demandsCloseLocally([], $bundled, $provided), 'no demands close trivially');

    // --- offline resolution ----------------------------------------------------
    $noNetwork = static function (string $base, string $path): ?string {
        throw new \RuntimeException("network fetch attempted: {$base}{$path}");
    };
    $delegated = [];
    $spy = static function (string $name) use (&$delegated): ?array {
        $delegated[] = $name;

        return null;
    };
    $resolution = resolveWith(bundledEntry($spy, $bundled), ['beta' => ''], 'root', $provided);
    $assert($resolution['ok'], 'the bundled closure resolves offline: ' . ($resolution['error'] ?? ''));
    $assert($resolution['chosen']['beta'] === '0.3.1' && $resolution['chosen']['alpha'] === '1.2.0', 'it chooses the carried versions');
    $assert($resolution['chosen']['base'] === '9.9.9', 'the provided base answers at its carried version (the lock writer drops it later)');
    $assert($delegated === [], 'no name reached the catalog entry behind the wrapper: ' . \json_encode($delegated));
    $missing = resolveWith(bundledEntry($spy, $bundled), ['gamma' => ''], 'root', $provided);
    $assert(! $missing['ok'] && \str_contains((string) $missing['error'], 'unknown package `gamma`'), 'off the closure the error names the package');
    $assert($delegated !== [] && \array_unique($delegated) === ['gamma'], 'only the unknown name fell through to the catalog: ' . \json_encode($delegated));

    // --- digests ----------------------------------------------------------------
    $first = bundledArchiveDigest($alphaDir);
    $assert($first === archiveDigest(packDirectory($alphaDir)), 'the digest is the pack digest, recomputed');
    $assert($first === bundledArchiveDigest($alphaDir), 'packing is deterministic');
    \file_put_contents($alphaDir . '/src/Dummy.mog', "-- alpha, moved\n");
    $assert(bundledArchiveDigest($alphaDir) !== $first, 'a moved tree packs differently');
    \file_put_contents($alphaDir . '/src/Dummy.mog', "-- alpha\n");

    // --- the lock ---------------------------------------------------------------
    $lock = lockDocument([
        'root' => ['name' => 'app', 'version' => '0.1.0'],
        'registry' => ['base' => 'https://registry.invalid', 'npub' => null, 'verified' => false],
        'packages' => ['alpha' => ['version' => '1.2.0', 'digest' => $first, 'bundled' => true]],
    ]);
    $assert(($lock['packages']['alpha']['bundled'] ?? false) === true, 'the lock carries the bundled flag');
    $assert(lockIsLocal($lock), 'an all-bundled lock is local');
    $assert(! lockIsLocal(lockDocument([
        'root' => ['name' => 'app', 'version' => '0.1.0'],
        'registry' => ['base' => 'https://registry.invalid', 'npub' => null, 'verified' => false],
        'packages' => [
            'alpha' => ['version' => '1.2.0', 'digest' => $first, 'bundled' => true],
            'gamma' => ['version' => '2.0.0', 'digest' => 'sha256:0'],
        ],
    ])), 'one registry package makes the lock non-local');
    $assert(lockDisagreements($lock, []) === [], 'a bundled entry is never compared to a catalog');
    $assert(lockDisagreements($lock, ['alpha' => ['versions' => ['9.9.9' => []]]]) === [], 'not even against a catalog that moved on');

    // --- install from the shipped tree, still offline -----------------------------
    $log = new FetchLog();
    $installed = installLockedPackages('https://registry.invalid', $lock, [], true, $noNetwork, $log);
    $assert($installed['ok'], 'a bundled lock installs with no registry: ' . \json_encode($installed['results']));
    $assert($log->requests === [], 'and nothing was fetched');
    $assert(isInstalled('alpha', $first), 'the install marker lands in the digest dir');
    $assert(checkBundledInstalled('alpha', '1.2.0') === null, 'the build-time check passes on the installed tree');

    // --- refusals ------------------------------------------------------------------
    \file_put_contents($alphaDir . '/src/Dummy.mog', "-- alpha, moved\n");
    $moved = installBundledPackage('alpha', '1.2.0', $first, $bundled['alpha']);
    $assert(! $moved['ok'] && \str_contains($moved['detail'], 'packs to') && \str_contains($moved['detail'], 'run `moggi update`'), 'a moved tree is refused with the re-resolve: ' . $moved['detail']);
    \file_put_contents($alphaDir . '/src/Dummy.mog', "-- alpha\n");
    $drifted = installBundledPackage('alpha', '9.9.9', $first, $bundled['alpha']);
    $assert(! $drifted['ok'] && \str_contains($drifted['detail'], 'now ships'), 'a version drift is refused: ' . $drifted['detail']);
    $gone = installBundledPackage('gamma', '1.0.0', 'sha256:0', ['version' => '1.0.0', 'dir' => $work . '/nope', 'manifest' => $work . '/nope/gamma.moggi']);
    $assert(! $gone['ok'] && \str_contains($gone['detail'], 'no longer ships'), 'a removed package is refused: ' . $gone['detail']);
    $assert(checkBundledInstalled('gamma', '1.0.0') !== null, 'the build-time check fails for what is not shipped');

    // --- writeResolution end to end, catalog null ------------------------------------
    \mkdir($work . '/app/src', 0777, true);
    $descriptorPath = $work . '/app/app.moggi';
    \file_put_contents($descriptorPath, $manifest('app', '0.1.0', "[executable]\nmain = Main\nsource-dirs = src\nbackend = php\n\n[dependencies]\nbeta = 0.3\n"));
    \file_put_contents($work . '/app/src/Main.mog', "-- app\n");
    $descriptor = readDescriptor($descriptorPath);
    $written = writeResolution($descriptor, $descriptorPath, 'https://registry.invalid', null, null, true, false);
    $document = $written['document'];
    $assert(($document['packages']['beta']['bundled'] ?? false) === true, 'the lock a null catalog produced still marks bundled');
    $assert($document['packages']['beta']['version'] === '0.3.1', 'at the carried version');
    $assert($document['registry']['verified'] === false && $document['registry']['npub'] === null, 'with no root to record');
    $assert(lockIsLocal($document), 'so the next command can skip the catalog too');

    echo "bundled package tests passed ({$checks} checks)\n";
} finally {
    ConfiguredInstallation::set($oldRoot);
    if ($oldCache === false) {
        \putenv('MOGGI_CACHE_DIR');
    } else {
        \putenv('MOGGI_CACHE_DIR=' . $oldCache);
    }
    if ($oldUserCache === false) {
        \putenv('MOGGI_USER_CACHE');
    } else {
        \putenv('MOGGI_USER_CACHE=' . $oldUserCache);
    }
    $remove($work);
}
