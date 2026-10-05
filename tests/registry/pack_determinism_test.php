#!/usr/bin/env php
<?php declare(strict_types=1);

// A package's identity is a digest, so packing one tree twice has to give the same bytes — on this
// machine and on the next one — and `extractArchive` has to be the same contract read backwards.
// The records are asserted byte for byte so a field creeping back in is caught here rather than by a
// registry refusing a release packed on someone else's laptop; `$golden` states the same claim as
// one value that every platform has to agree with.

$root = __DIR__;
while (!is_file($root . '/src/registry/pack.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/registry/pack.php';
require $root . '/tests/suite/support/workspace.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$jsonMoggi = "name = json\nversion = 1.0.0\n";
$jsonMog = "module Json where\n";

/** One record, spelled out here rather than by the implementation: `type NUL size NUL hash NUL path NUL extra NUL LF`. */
$record = static fn (string $content, string $relative): string => \implode("\0", [
    'file',
    (string) \strlen($content),
    \hash('sha256', $content),
    $relative,
    '',
]) . "\0\n";

// Byte order, never creation order and never a locale's: `json.moggi` before `src/Json.mog`.
$expected = $record($jsonMoggi, 'json.moggi') . $record($jsonMog, 'src/Json.mog');

$work = createTempDir('moggi-pack');
$tree = $work . '/json';

/** The fixture, with `$mode` on `src/Json.mog` and the files created in the order given. */
$lay = static function (string $directory, int $mode, bool $reverse = false) use ($jsonMoggi, $jsonMog): void {
    if (!\is_dir($directory)) {
        \mkdir($directory . '/src', 0777, true);
    }
    $writes = [
        $directory . '/json.moggi' => $jsonMoggi,
        $directory . '/src/Json.mog' => $jsonMog,
    ];
    foreach (($reverse ? \array_reverse($writes, true) : $writes) as $path => $content) {
        \file_put_contents($path, $content);
    }
    @\chmod($directory . '/src/Json.mog', $mode);
};

try {
    $lay($tree, 0644);

    $records = \Moggi\Registry\directoryRecordBytes($tree);
    $assert($records === $expected, "the records are not the documented shape:\n" . \var_export($records, true));
    $digest = \Moggi\Registry\directoryDigest($tree);
    $assert(
        $digest === 'sha256:' . \hash('sha256', $expected),
        "the digest is not the sha256 of the records: {$digest}",
    );

    // The same claim as one value. A change here means the record format moved — deliberately or
    // not — and every digest in every registry has to be re-signed to match.
    $golden = 'sha256:036a42560ea5fe6fba4640315751763a35443da65980322bfdbd0718ed7893ea';
    $assert($digest === $golden, "a fixed tree no longer digests to {$golden}: {$digest}");

    // The permissions of a file and of a directory it sits in are both outside the digest.
    $archive = \Moggi\Registry\packDirectory($tree);
    \chmod($tree . '/src/Json.mog', 0755);
    \chmod($tree . '/src', 0700);
    $assert(
        \Moggi\Registry\directoryRecordBytes($tree) === $expected,
        'the executable bit reached the records — it is not portable and must not be in them',
    );
    $assert(\Moggi\Registry\directoryDigest($tree) === $digest, 'chmod changed the content digest');
    $assert(
        \Moggi\Registry\packDirectory($tree) === $archive,
        'chmod changed the archive — the archive is content, not permissions',
    );
    $lay($tree, 0644);

    // An empty directory is not content, so it is not a record either.
    \mkdir($tree . '/empty');
    $assert(\Moggi\Registry\directoryDigest($tree) === $digest, 'an empty directory changed the digest');
    \rmdir($tree . '/empty');

    // The walk sorts; the order the files were written in is not an input.
    $other = $work . '/other';
    $lay($other, 0644, true);
    $assert(\Moggi\Registry\directoryDigest($other) === $digest, 'creation order changed the digest');

    // A link is a record of its own: the target is the content that gets hashed, and it is the file's
    // own name that differs — nothing about permissions is involved on either side.
    $targets = $work . '/with-link';
    $lay($targets, 0644);
    if (@\symlink('src/Json.mog', $targets . '/link.mog')) {
        $linked = \Moggi\Registry\directoryEntries($targets);
        $link = $linked[1] ?? null;
        $assert(($link['type'] ?? null) === 'link', 'a symlink must be a link record');
        $assert(($link['path'] ?? null) === 'link.mog', 'a link record must carry its own path');
        $assert(($link['extra'] ?? null) === 'src/Json.mog', 'a link record must carry its target');
        $assert(
            ($link['hash'] ?? null) === \hash('sha256', 'src/Json.mog'),
            'a link record must hash the target string',
        );
    }

    // What install does with a blob: the tree comes back byte for byte, and the mode the archive
    // carries is the constant one. Windows cannot report that, so it is asserted where it is real.
    $destination = $work . '/unpacked';
    \mkdir($destination, 0777, true);
    $problem = \Moggi\Registry\extractArchive($archive, $destination);
    $assert($problem === null, "extracting the canonical archive failed: {$problem}");
    $assert((string) \file_get_contents($destination . '/json.moggi') === $jsonMoggi, 'a file was not restored');
    $assert((string) \file_get_contents($destination . '/src/Json.mog') === $jsonMog, 'a nested file was not restored');
    if (\PHP_OS_FAMILY !== 'Windows') {
        $assert(
            (\fileperms($destination . '/src/Json.mog') & 0o777) === 0o644,
            'an installed file is 644: ' . \decoct(\fileperms($destination . '/src/Json.mog') & 0o777),
        );
    }

    // The archive is read back by a registry too, so a path that escapes the destination is refused
    // rather than unpacked hopefully.
    $hostile = \Moggi\Registry\tarHeader('../escape.mog', '0', 0644, 3, '')
        . \Moggi\Registry\padTo512('boo') . \str_repeat("\0", 1024);
    $escape = $work . '/escape';
    \mkdir($escape, 0777, true);
    $refusal = \Moggi\Registry\extractArchive($hostile, $escape);
    $assert($refusal !== null && \str_contains($refusal, 'unsafe path'), 'a `../` entry must be refused: ' . \var_export($refusal, true));
    $assert(!\file_exists($work . '/escape.mog'), 'a `../` entry must not be written');

    // A link is the other way out of the destination, and the blob is hostile input: a target that
    // climbs above the root, or names an absolute path, must be refused on the way in as well as on
    // the way out.
    foreach (['../../outside.mog', '/etc/passwd', 'src\\Json.mog'] as $target) {
        $blob = \Moggi\Registry\tarHeader('link.mog', '2', 0777, 0, $target) . \str_repeat("\0", 1024);
        $verdict = \Moggi\Registry\extractArchive($blob, $escape);
        $assert(
            $verdict !== null && \str_contains($verdict, 'unsafe link'),
            "a link to {$target} must be refused: " . \var_export($verdict, true),
        );
        $assert(!\file_exists($escape . '/link.mog'), "a link to {$target} must not be created");
    }

    // And the same rule when packing, so we never write an archive we would not unpack.
    $unsafeTree = $work . '/unsafe-link';
    \mkdir($unsafeTree, 0777, true);
    \file_put_contents($unsafeTree . '/json.moggi', $jsonMoggi);
    foreach (['../../outside.mog', '/etc/passwd', 'src\\Json.mog'] as $target) {
        @\unlink($unsafeTree . '/link.mog');
        if (!@\symlink($target, $unsafeTree . '/link.mog')) {
            continue;
        }
        $refused = false;
        try {
            \Moggi\Registry\directoryEntries($unsafeTree);
        } catch (\RuntimeException) {
            $refused = true;
        }
        $assert($refused, "packing a link to {$target} must be refused");
    }

    // A link inside the tree, on the other hand, is ordinary content.
    @\unlink($unsafeTree . '/link.mog');
    @\symlink('src/Json.mog', $unsafeTree . '/link.mog');
    $accepted = true;
    try {
        \Moggi\Registry\directoryEntries($unsafeTree);
    } catch (\RuntimeException) {
        $accepted = false;
    }
    $assert($accepted, 'a link that stays inside the tree must be accepted');

    // A path a record could not survive: it would make the format ambiguous.
    foreach (["a\nb.mog", "a\rb.mog"] as $impossible) {
        $refused = false;
        try {
            \Moggi\Registry\assertPortablePath($impossible);
        } catch (\RuntimeException) {
            $refused = true;
        }
        $assert($refused, 'a path with a line break must be refused');
    }

    // macOS hands back decomposed names for a file Linux stores composed, which is the same name
    // twice; with `intl` present that is asserted, and without it the check cannot be made at all.
    if (\class_exists(\Normalizer::class)) {
        $decomposed = "cafe\u{0301}.mog";
        $refused = false;
        try {
            \Moggi\Registry\assertPortablePath($decomposed);
        } catch (\RuntimeException) {
            $refused = true;
        }
        $assert($refused, 'a decomposed path must be refused, not silently hashed differently');

        $accepted = true;
        try {
            \Moggi\Registry\assertPortablePath('Docs/' . "\u{00DC}" . 'nicode.mog');
        } catch (\RuntimeException) {
            $accepted = false;
        }
        $assert($accepted, 'a composed non-ASCII path must be accepted as it stands');
    }
} finally {
    removeDirectory($work);
}

\fwrite(STDOUT, "registry: {$checks} checks passed\n");
