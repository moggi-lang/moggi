#!/usr/bin/env php
<?php declare(strict_types=1);

// Adversarial checks for the registry client's trust boundaries. Each one is a
// thing a hostile registry, a hostile lock, or a misplaced binary could try, and
// each asserts the *refusal* rather than a happy path. They run without a
// registry, a network, or a real `schnorr`: where a binary is needed, a fake one
// is written to a temp directory and named through `MOGGI_SCHNORR`.

$root = __DIR__;
while (!is_file($root . '/src/registry/names.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
// The whole compiler, not just the registry files: a descriptor names backends,
// and `implementedBackendIds()` reaches the backend registry. Loading it once is also how
// the suite loads it, so this test sees the same code the CLI does.
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Registry\Catalog;
use Moggi\Registry\FetchLog;

use function Moggi\Registry\assertPackageName;
use function Moggi\Registry\descriptorProblems;
use function Moggi\Registry\installDir;
use function Moggi\Registry\isPackageName;
use function Moggi\Registry\lockDisagreements;
use function Moggi\Registry\registryIdentityProblem;
use function Moggi\Registry\rememberedRegistryNpub;
use function Moggi\Registry\requestHost;
use function Moggi\Registry\schnorrBinary;
use function Moggi\Registry\verifySignature;
use function Moggi\Registry\writeMessageHex;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};
$refuses = static function (callable $call, string $needle, string $message) use (&$checks): void {
    ++$checks;
    try {
        $call();
    } catch (\RuntimeException $error) {
        if (!\str_contains($error->getMessage(), $needle)) {
            throw new \RuntimeException("{$message}: refused with an unexpected message: {$error->getMessage()}");
        }

        return;
    }
    throw new \RuntimeException("{$message}: was not refused");
};
/** @param list<string> $problems */
$mentions = static function (array $problems, string $needle): bool {
    foreach ($problems as $problem) {
        if (\str_contains($problem, $needle)) {
            return true;
        }
    }

    return false;
};

$seedNpub = 'npub1wfvq9dhta9cnht06atsndhyqz3k649fm047ur0x54yy7f5ufvetsqlf0pz';
$otherNpub = \substr($seedNpub, 0, -1) . 'q';
$hex = \str_repeat('a', 64);
$hexC = \str_repeat('c', 64);

// A registry, a lock and a name are all untrusted input that becomes a path, so
// the whole run is kept off the real caches.
$work = createTempDir('moggi-hardening');
\putenv('MOGGI_CACHE_DIR=' . $work . '/cache');
\putenv('MOGGI_USER_CACHE=' . $work . '/user-cache');
\putenv('MOGGI_REGISTRY_NPUB');
\putenv('MOGGI_SCHNORR');

try {
    // --- package names -------------------------------------------------------
    foreach (['json', 'a', 'a1', 'data.json', 'my-pkg', 'x_y_z', '0', 'a..b', 'n2'] as $good) {
        $assert(isPackageName($good), "`{$good}` must be a package name");
    }
    foreach (['', '.', '..', 'A', 'Json', '-a', 'a-', '_a', 'a_', 'a/', '/a', 'a b', 'a/b', "a\u{00e9}", '.a', 'a.', 'a\\b'] as $bad) {
        $assert(!isPackageName($bad), \var_export($bad, true) . ' must not be a package name');
    }
    $refuses(static fn () => assertPackageName('../../etc', 'test'), 'not a package name', 'a climbing name');

    // --- install paths -------------------------------------------------------
    $refuses(
        static fn () => installDir('../../evil', 'sha256:' . \str_repeat('b', 64)),
        'not a package name',
        'an install directory for a climbing name',
    );
    $assert(
        \str_ends_with(installDir('json', 'sha256:' . $hex), '/json/' . $hex),
        'a good install path must end in <name>/<hex>',
    );
    $assert(
        \str_ends_with(installDir('json', 'sha256:../../etc'), '/json/unknown'),
        'a digest that is not hex must not reach the path',
    );

    // --- lock disagreements --------------------------------------------------
    $lock = static fn (array $packages): array => ['packages' => $packages];
    $catalog = static fn (array $versions, ?string $digest): array => ['versions' => $versions, 'digest' => $digest];
    $one = static function (array $problems, string $needle, string $what) use ($assert): void {
        $assert(
            \count($problems) === 1 && \str_contains($problems[0], $needle),
            "{$what}: " . \var_export($problems, true),
        );
    };

    $assert(
        lockDisagreements($lock(['json' => ['version' => '1.0.0', 'digest' => 'sha256:' . $hex]]), ['json' => $catalog(['1.0.0' => []], 'sha256:' . $hex)]) === [],
        'a lock that agrees with the catalog must be clean',
    );
    $one(lockDisagreements($lock(['../evil' => ['version' => '1.0.0', 'digest' => 'sha256:' . $hex]]), []), 'not a name', 'a lock naming a climbing package must be a disagreement');
    $one(lockDisagreements($lock(['json' => ['version' => '1.0.0', 'digest' => null]]), ['json' => $catalog(['1.0.0' => []], 'sha256:' . $hex)]), 'names no release digest', 'a missing lock digest must be a disagreement');
    $one(lockDisagreements($lock(['json' => ['version' => '1.0.0', 'digest' => 'sha256:' . $hex]]), ['json' => $catalog(['1.0.0' => []], null)]), 'no release digest in the catalog', 'a missing catalog digest must be a disagreement');
    $one(lockDisagreements($lock(['json' => ['version' => '1.0.0', 'digest' => 'sha256:' . $hex]]), ['json' => $catalog(['1.0.0' => []], 'sha256:' . $hexC)]), 'changed since', 'a moved digest must be a disagreement');
    $one(lockDisagreements($lock(['json' => ['version' => '9.9.9', 'digest' => 'sha256:' . $hex]]), ['json' => $catalog(['1.0.0' => []], 'sha256:' . $hex)]), 'not published any more', 'a vanished version must be a disagreement');

    // --- a lock belongs to the registry it was resolved from ----------------
    $recorded = ['packages' => [], 'registry' => ['npub' => $seedNpub]];
    $assert(\Moggi\Registry\lockRegistryProblem($recorded, $seedNpub) === null, 'a lock from this registry is fine');
    $movedRegistry = \Moggi\Registry\lockRegistryProblem($recorded, $otherNpub);
    $assert(\str_contains($movedRegistry, 'resolved against'), 'a lock from another registry must be refused: ' . $movedRegistry);
    $assert(\Moggi\Registry\lockRegistryProblem(['packages' => []], $seedNpub) === null, 'a lock with no recorded key is not a mismatch');

    // --- a lock carries no timestamp: same content, same bytes ---------------
    $lockInput = [
        'root' => ['name' => 'pkg', 'version' => '1.0.0'],
        'registry' => ['base' => 'https://reg.example.org', 'npub' => $seedNpub, 'verified' => true],
        'packages' => ['json' => ['version' => '1.0.0', 'digest' => 'sha256:' . $hex]],
    ];
    $firstLock = \Moggi\Registry\renderLock(\Moggi\Registry\lockDocument($lockInput));
    $secondLock = \Moggi\Registry\renderLock(\Moggi\Registry\lockDocument($lockInput));
    $assert($firstLock === $secondLock, 'two locks with the same content must be byte-identical');
    $assert(!\str_contains($firstLock, 'generated'), 'the lock must not carry a timestamp: ' . $firstLock);

    // --- a written file is whole or absent ----------------------------------
    $atomicPath = $work . '/atomic.txt';
    $assert(\Moggi\Cache\atomicWrite($atomicPath, 'whole'), 'an atomic write must succeed');
    $assert((string) \file_get_contents($atomicPath) === 'whole', 'an atomic write must land its bytes');
    $assert((\glob($work . '/atomic.txt.tmp.*') ?: []) === [], 'an atomic write must leave no staging file');

    // --- a registry response is capped, not buffered without bound -----------
    $assert(\Moggi\Registry\maxResponseBytes() > 0, 'the response cap must be a positive number of bytes');
    \putenv('MOGGI_MAX_RESPONSE_BYTES=4096');
    $assert(\Moggi\Registry\maxResponseBytes() === 4096, 'the cap must honour MOGGI_MAX_RESPONSE_BYTES');
    \Moggi\Registry\assertResponseWithinCap(4096, 'a body at the cap');
    $refuses(
        static fn () => \Moggi\Registry\assertResponseWithinCap(4097, 'catalog/js.json'),
        'response cap',
        'a body one byte over the cap must be refused',
    );

    // The same `fetchBytes` a mirror or a checkout is read with is capped too, so
    // the refusal does not need a network to be exercised.
    $mirror = $work . '/mirror';
    \mkdir($mirror, 0777, true);
    \file_put_contents($mirror . '/big.json', \str_repeat('a', 5000));
    \file_put_contents($mirror . '/small.json', 'ok');
    $refuses(
        static fn () => \Moggi\Registry\fetchBytes($mirror, 'big.json'),
        'response cap',
        'an oversized registry file must be refused',
    );
    $assert(\Moggi\Registry\fetchBytes($mirror, 'small.json') === 'ok', 'a file inside the cap must read');

    // A caller-supplied reader is held to the cap too: the same bytes reach
    // install through it, so it must not be a way around the limit.
    $fatReader = static fn (string $base, string $path): string => \str_repeat('x', 9000);
    $refuses(
        static fn () => \Moggi\Registry\fetchRegistryBytes('base', 'blobs/' . $hex, $fatReader),
        'response cap',
        'a reader returning an oversized body must be refused',
    );

    \putenv('MOGGI_MAX_RESPONSE_BYTES=not-a-number');
    $refuses(
        static fn () => \Moggi\Registry\maxResponseBytes(),
        'MOGGI_MAX_RESPONSE_BYTES',
        'a malformed cap must be refused',
    );
    \putenv('MOGGI_MAX_RESPONSE_BYTES');

    // --- the catalog validates a name before it becomes a path ---------------
    $empty = new Catalog('base', ['shards' => []], ['ok' => true, 'note' => ''], null, new FetchLog(), false);
    $refuses(static fn () => $empty->entry('../../etc'), 'not a package name', 'a catalog lookup with a climbing name');
    $assert($empty->entry('json') === null, 'an unknown but valid name is simply absent');

    // --- the descriptor validates names it will later join -------------------
    $descriptor = static function (string $name, string $dependency, string $npub): string {
        $text = "[package]\nname = {$name}\nversion = 1.0.0\n[author]\nnpub = {$npub}\n";
        if ($dependency !== '') {
            $text .= "[dependencies]\n{$dependency} = 1.0.0\n";
        }

        return $text;
    };
    $file = $work . '/Bad.moggi';
    \file_put_contents($file, $descriptor('Bad', '', $seedNpub));
    $assert(
        $mentions(descriptorProblems($file), 'not a package name'),
        'a descriptor naming `Bad` must be refused: ' . \var_export(descriptorProblems($file), true),
    );
    $file = $work . '/root.moggi';
    \file_put_contents($file, $descriptor('root', 'Bad', $seedNpub));
    $assert(
        $mentions(descriptorProblems($file), 'not a package name'),
        'a dependency named `Bad` must be refused: ' . \var_export(descriptorProblems($file), true),
    );

    // --- the verifier needs the success token, not just exit 0 ---------------
    if (\PHP_OS_FAMILY !== 'Windows') {
        $fake = static function (string $path, string $body) use ($work): string {
            \file_put_contents($work . '/' . $path, "#!/bin/sh\n{$body}\n");
            \chmod($work . '/' . $path, 0755);

            return $work . '/' . $path;
        };

        \putenv('MOGGI_SCHNORR=' . $fake('valid', 'echo valid; exit 0'));
        $verdict = verifySignature('aa', 'bb', $seedNpub);
        $assert($verdict['ok'] === true, 'a verifier that prints `valid` must verify: ' . $verdict['note']);

        \putenv('MOGGI_SCHNORR=' . $fake('says-ok', 'echo ok; exit 0'));
        $assert(verifySignature('aa', 'bb', $seedNpub)['ok'] === false, 'a verifier that exits 0 without saying `valid` must not verify');

        \putenv('MOGGI_SCHNORR=' . $fake('silent', 'exit 0'));
        $assert(verifySignature('aa', 'bb', $seedNpub)['ok'] === false, 'a silent success must not verify');

        \putenv('MOGGI_SCHNORR=' . $fake('invalid', 'echo invalid; exit 1'));
        $assert(verifySignature('aa', 'bb', $seedNpub)['ok'] === false, '`invalid` must not verify');

        // A `schnorr` on `PATH` is never the verifier.
        $pathDir = $work . '/path-dir';
        \mkdir($pathDir, 0777, true);
        \file_put_contents($pathDir . '/schnorr', "#!/bin/sh\necho valid\n");
        \chmod($pathDir . '/schnorr', 0755);
        \putenv('MOGGI_SCHNORR');
        $savedPath = (string) \getenv('PATH');
        \putenv('PATH=' . $pathDir);
        $found = schnorrBinary();
        $assert(
            $found === null || !\str_starts_with((string) $found, $pathDir),
            'a `schnorr` on PATH must not be used: ' . \var_export($found, true),
        );
        \putenv('PATH=' . $savedPath);
    }

    // A set-but-missing `MOGGI_SCHNORR` is how a caller says "no binary": the
    // override wins and does not fall through to the bundled checkout copy.
    \putenv('MOGGI_SCHNORR=' . $work . '/no-such-schnorr');
    $verdict = verifySignature('aa', 'bb', $seedNpub);
    $assert(
        $verdict['ok'] === false && \str_contains($verdict['note'], 'no bundled'),
        'with no binary, verification must fail with a clear note',
    );

    // --- the write signature is bound to the host ----------------------------
    $assert(requestHost('https://reg.example.org/publish') === 'reg.example.org', 'a default https port is not shown');
    $assert(requestHost('http://reg.example.org:80/publish') === 'reg.example.org', 'a default http port is not shown');
    $assert(requestHost('https://reg.example.org:443/publish') === 'reg.example.org', 'a default https port is not shown');
    $assert(requestHost('http://reg.example.org:8080/publish') === 'reg.example.org:8080', 'a non-default port is shown');
    $assert(requestHost('https://REG.Example.ORG/publish') === 'reg.example.org', 'a host is lowercased');

    $golden = '5130f2677bd716694704132fa0c52a7364e8a96af5a4eb459ba407304d4a21c7';
    $message = writeMessageHex('reg.example.org', 'POST', '/publish', 1700000000, 'nonce', 'body');
    $assert($message === $golden, "the write message changed shape: {$message}");
    $assert(
        writeMessageHex('other.example.org', 'POST', '/publish', 1700000000, 'nonce', 'body') !== $message,
        'a different host must change the message',
    );
    $assert(
        writeMessageHex('reg.example.org', 'PUT', '/publish', 1700000000, 'nonce', 'body') !== $message,
        'a different method must change the message',
    );

    // --- .moggiignore selects what is packed --------------------------------
    $ignored = $work . '/ignored';
    \mkdir($ignored . '/src', 0777, true);
    \mkdir($ignored . '/build', 0777, true);
    \file_put_contents($ignored . '/.moggiignore', "# comment\n*.tmp\nbuild/\nsrc/generated\n\n");
    \file_put_contents($ignored . '/keep.mog', 'keep');
    \file_put_contents($ignored . '/drop.tmp', 'drop');
    \file_put_contents($ignored . '/build/out.o', 'out');
    \file_put_contents($ignored . '/src/keep.mog', 'keep');
    \file_put_contents($ignored . '/src/generated', 'gen');

    $patterns = \Moggi\Registry\moggiIgnorePatterns($ignored);
    $assert($patterns === ['*.tmp', 'build/', 'src/generated'], 'the patterns are not read as documented: ' . \var_export($patterns, true));
    $paths = \array_map(static fn (array $entry): string => $entry['path'], \Moggi\Registry\directoryEntries($ignored, [], $patterns));
    $assert(\in_array('keep.mog', $paths, true), 'an unignored file must be packed');
    $assert(\in_array('src/keep.mog', $paths, true), 'an unignored nested file must be packed');
    $assert(!\in_array('drop.tmp', $paths, true), 'a `*.tmp` file must be ignored');
    $assert(!\in_array('build/out.o', $paths, true), 'a directory pattern must ignore its contents');
    $assert(!\in_array('src/generated', $paths, true), 'a path pattern must ignore that path');
    $assert(!\in_array('.moggiignore', $paths, true), '`.moggiignore` itself must not be packed');
    $assert(\Moggi\Registry\isIgnoredPath('a/b.tmp', ['*.tmp']), 'a bare glob matches a basename at any depth');
    $assert(!\Moggi\Registry\isIgnoredPath('a/b.mog', ['*.tmp']), 'a non-matching path is kept');
    $assert(\Moggi\Registry\isIgnoredPath('build/x/y', ['build/']), 'a directory pattern matches its descendants');

    // --- install is atomic, and a leftover is replaced, not merged -----------
    $tree = $work . '/tree';
    \mkdir($tree . '/src', 0777, true);
    \file_put_contents($tree . '/pkg.moggi', "name = pkg\nversion = 1.0.0\n");
    \file_put_contents($tree . '/src/Pkg.mog', "module Pkg where\n");
    $archive = $work . '/pkg.tar';
    \file_put_contents($archive, \Moggi\Registry\packDirectory($tree));

    // --- the digest and the archive come from one listing --------------------
    $listing = \Moggi\Registry\directoryEntries($tree);
    $assert(
        \Moggi\Registry\entriesDigest($listing) === \Moggi\Registry\directoryDigest($tree),
        'the digest of a listing must match the digest of the tree',
    );
    $assert(
        \Moggi\Registry\packEntries($listing) === \Moggi\Registry\packDirectory($tree),
        'the archive of a listing must match the archive of the tree',
    );

    $destination = $work . '/install';
    $assert(\Moggi\Registry\unpackArchive($archive, $destination) === null, 'a fresh unpack must succeed');
    $assert(\is_file($destination . '/.unpacked'), 'a finished unpack must carry the marker');
    $assert((string) \file_get_contents($destination . '/src/Pkg.mog') === "module Pkg where\n", 'the staged tree must land intact');

    // Running it again is a no-op, not a failure: an already-installed tree stays.
    $before = (string) \file_get_contents($destination . '/src/Pkg.mog');
    $assert(\Moggi\Registry\unpackArchive($archive, $destination) === null, 'a repeat unpack must be a no-op');
    $assert((string) \file_get_contents($destination . '/src/Pkg.mog') === $before, 'a repeat unpack must not rewrite the tree');

    // A destination without the marker is a leftover from an interrupted run; it
    // must be replaced, not merged into, or a stale file would survive an install.
    $leftover = $work . '/leftover';
    \mkdir($leftover, 0777, true);
    \file_put_contents($leftover . '/stale.mog', 'stale');
    $assert(\Moggi\Registry\unpackArchive($archive, $leftover) === null, 'a leftover destination must be replaced');
    $assert(!\file_exists($leftover . '/stale.mog'), 'a leftover file must not survive the install');
    $assert(\is_file($leftover . '/.unpacked'), 'the replacement must be marked complete');

    // No staging directory is left behind after a successful rename.
    $strays = \glob($work . '/*.unpack-*') ?: [];
    $assert($strays === [], 'a staging directory was left behind: ' . \implode(', ', $strays));

    // --- registry identity: a pin, or trust on first use ---------------------
    $assert(registryIdentityProblem('https://reg.example.org', $seedNpub, $seedNpub) === '', 'a matching pin is fine');
    $pinProblem = registryIdentityProblem('https://reg.example.org', $seedNpub, $otherNpub);
    $assert(\str_contains($pinProblem, 'not the pinned key'), 'a mismatched pin must be refused: ' . $pinProblem);
    $assert(\str_contains(registryIdentityProblem('https://reg.example.org', $seedNpub, 'not-a-key'), 'not an npub'), 'a malformed pin must be refused');

    // With no pin, the first key is recorded and a later change is refused.
    $base = 'https://first.example.org';
    $assert(registryIdentityProblem($base, $seedNpub, null) === '', 'the first use of a key is trusted');
    $assert(rememberedRegistryNpub($base) === $seedNpub, 'the first key must be remembered');
    $assert(registryIdentityProblem($base, $seedNpub, null) === '', 'the remembered key must keep verifying');
    $changed = registryIdentityProblem($base, $otherNpub, null);
    $assert(\str_contains($changed, 'different registry key'), 'a changed key must be refused: ' . $changed);

    // --- library roots stay inside the package they came from ---------------
    $install = $work . '/installed';
    \mkdir($install . '/src', 0777, true);
    $roots = \Moggi\Registry\libraryRoots($install, "[lib]\nsource-dirs = src\n");
    $assert(
        $roots === [$install . '/src'],
        'a source-dirs inside the install must resolve to it: ' . \var_export($roots, true),
    );
    $refuses(
        static fn () => \Moggi\Registry\libraryRoots($install, "[lib]\nsource-dirs = ../../etc\n"),
        'climbs out',
        'a source-dirs that climbs out must be refused',
    );
    $refuses(
        static fn () => \Moggi\Registry\libraryRoots($install, "[lib]\nsource-dirs = /etc\n"),
        'climbs out',
        'an absolute source-dirs must be refused',
    );
    $assert(\Moggi\Registry\containedPath('/base', 'a/b') === '/base/a/b', 'a contained path joins');
    $assert(\Moggi\Registry\containedPath('/base', 'a/../b') === '/base/b', 'interior .. is folded');
    $assert(\Moggi\Registry\containedPath('/base', '../x') === null, 'a climbing path is refused');
    $assert(\Moggi\Registry\containedPath('/base', '/x') === null, 'an absolute path is refused');

    // --- third-party artifacts are held to the signed map -------------------
    $toolDir = $work . '/tool-tree';
    \mkdir($toolDir . '/vendor/acme/thing/src', 0777, true);
    \mkdir($toolDir . '/vendor/composer', 0777, true);
    \file_put_contents($toolDir . '/vendor/autoload.php', '<?php // generated');
    \file_put_contents($toolDir . '/vendor/composer/autoload_real.php', '<?php // generated');
    \file_put_contents($toolDir . '/vendor/acme/thing/src/Thing.php', '<?php class Thing {}');
    \mkdir($toolDir . '/jvm', 0777, true);
    \file_put_contents($toolDir . '/jvm/thing-1.0.jar', 'jar-bytes');
    \mkdir($toolDir . '/dotnet', 0777, true);
    \file_put_contents($toolDir . '/dotnet/Thing.dll', 'dll-bytes');

    $phpMap = \Moggi\Registry\thirdPartyMap('php', $toolDir);
    $assert(!isset($phpMap['vendor/autoload.php']), 'the generated autoloader must not be pinned');
    $assert(!isset($phpMap['vendor/composer/autoload_real.php']), 'the generated autoloader must not be pinned');
    $assert(
        isset($phpMap['vendor/acme/thing/src/Thing.php']),
        'an installed package file must be pinned: ' . \var_export(\array_keys($phpMap), true),
    );
    $assert(\Moggi\Registry\thirdPartyProblems('php', $toolDir, $phpMap) === [], 'a matching tree must verify');

    \file_put_contents($toolDir . '/vendor/acme/thing/src/Thing.php', '<?php class Evil {}');
    $tampered = \Moggi\Registry\thirdPartyProblems('php', $toolDir, $phpMap);
    $assert(
        \count($tampered) === 1 && \str_contains($tampered[0], 'does not match the signed third_party'),
        'a substituted package file must fail: ' . \var_export($tampered, true),
    );
    \file_put_contents($toolDir . '/vendor/acme/thing/src/Thing.php', '<?php class Thing {}');

    // An artifact no release declares is refused only when the local package
    // contributes no coordinates of its own for the backend, because those are
    // the project's own unsigned artifacts.
    \file_put_contents($toolDir . '/jvm/evil.jar', 'evil');
    $jvmMap = ['jvm/thing-1.0.jar' => \Moggi\Registry\sha256Digest('jar-bytes')];
    $assert(\Moggi\Registry\thirdPartyProblems('jvm', $toolDir, $jvmMap) === [], 'a non-strict check ignores an undeclared extra');
    $strict = \Moggi\Registry\thirdPartyProblems('jvm', $toolDir, $jvmMap, true);
    $assert(
        \count($strict) === 1 && \str_contains($strict[0], 'no signed release declares'),
        'a strict check must refuse an injected artifact: ' . \var_export($strict, true),
    );
    \unlink($toolDir . '/jvm/evil.jar');

    // A substituted jar is caught by bytes, not by name.
    \file_put_contents($toolDir . '/jvm/thing-1.0.jar', 'evil-jar');
    $jarProblem = \Moggi\Registry\thirdPartyProblems('jvm', $toolDir, $jvmMap);
    $assert(
        \count($jarProblem) === 1 && \str_contains($jarProblem[0], 'does not match the signed third_party'),
        'a substituted jar must fail: ' . \var_export($jarProblem, true),
    );
    \file_put_contents($toolDir . '/jvm/thing-1.0.jar', 'jar-bytes');

    // The map is a map even where it is empty, and is covered by the signature.
    $release = [
        'blob' => 'sha256:' . $hex,
        'source' => ['kind' => 'dir', 'sha256' => 'sha256:' . $hexC],
        'third_party' => ['jvm' => ['jvm/a.jar' => 'sha256:aaa']],
    ];
    $otherRelease = $release;
    $otherRelease['third_party'] = ['jvm' => ['jvm/a.jar' => 'sha256:bbb']];
    $assert(
        \Moggi\Registry\releaseMessage('json', '1.0.0', $release) !== \Moggi\Registry\releaseMessage('json', '1.0.0', $otherRelease),
        'the third_party map must be covered by the release signature',
    );

    // Two releases pinning different bytes for one path is refused, not resolved.
    $merged = \Moggi\Registry\mergeThirdParty([
        ['jvm' => ['jvm/a.jar' => 'sha256:aaa']],
        ['jvm' => ['jvm/a.jar' => 'sha256:bbb']],
    ]);
    $assert($merged['problems'] !== [], 'two releases disagreeing must be refused');
    $assert($merged['map']['jvm']['jvm/a.jar'] === 'sha256:aaa', 'the first pin is kept for the report');
} finally {
    removeDirectory($work);
}

\fwrite(STDOUT, "registry-hardening: {$checks} checks passed\n");
