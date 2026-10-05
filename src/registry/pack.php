<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * Deterministic content: one directory digest, and one canonical archive.
 *
 * A package's identity is a hash, so the same bytes have to hash the same on
 * every machine. Nothing the filesystem is free to vary may enter: no
 * timestamps, no owners, no modes, no directory order, no locale's collation.
 * Content and paths only.
 *
 * **The digest of a directory** is the sha256 of these records, one per entry,
 * in ascending order of the path's UTF-8 bytes, each record ending in a line
 * feed:
 *
 *     type NUL size NUL hash NUL path NUL extra NUL LF
 *
 * `type` is `file` or `link`; `size` is the byte length; `hash` is the sha256 of
 * the content — a file's bytes, or a link's target string; `path` is relative,
 * `/`-separated and NFC; `extra` is empty for a file and the target for a link.
 * A target is relative and stays under the root, so the archive always unpacks
 * where it was packed. Directories are not records, so an empty one is not
 * content.
 *
 * **The canonical archive** is an uncompressed tar of the same entries in the
 * same order with every metadata field fixed: `mtime` 0, `uid`/`gid` 0, empty
 * user and group names, `644` for a file and `777` for a link, ustar with pax
 * records for names and targets that do not fit. Uncompressed on purpose —
 * zlib's output is not guaranteed identical across versions, so compressing
 * would move the digest to the machine's zlib. Compression is transport
 * (`registry-spec.md` §4.2).
 *
 * Modes are fixed because they do not travel: Windows derives a file's `x` bits
 * from its extension, so `bin/tool` is executable there and `script.bat` is not.
 * A digest covering them would give one tree two digests, which is the thing
 * this file exists to prevent.
 */

/**
 * The patterns in a project's optional `.moggiignore`, in file order.
 *
 * A small, documented subset of the `.gitignore` grammar, because a package's
 * selection should be readable at a glance and should not be a second language:
 * blank lines and `#` comments are skipped; a trailing `/` means a directory and
 * everything under it; a leading `/` anchors to the package root; `*` and `?` are
 * globs that do not cross `/`; and a pattern with no `/` matches at any depth.
 * The file itself is never packed.
 *
 * @return list<string>
 */
function moggiIgnorePatterns(string $directory): array
{
    $path = \rtrim($directory, '/') . '/.moggiignore';
    if (!\is_file($path)) {
        return [];
    }
    $patterns = [];
    foreach (\preg_split('/\R/', (string) \file_get_contents($path)) ?: [] as $line) {
        $line = \trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $patterns[] = $line;
    }

    return $patterns;
}

/** Whether a relative path is selected out by a `.moggiignore` pattern. */
function isIgnoredPath(string $path, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        $pattern = \trim($pattern);
        if ($pattern === '' || $pattern[0] === '#') {
            continue;
        }
        $directoryOnly = \str_ends_with($pattern, '/');
        $pattern = \ltrim(\rtrim($pattern, '/'), '/');
        if ($pattern === '') {
            continue;
        }

        if ($directoryOnly || \str_contains($pattern, '/')) {
            if ($path === $pattern || \str_starts_with($path, $pattern . '/')
                || \fnmatch($pattern, $path, \FNM_PATHNAME)) {
                return true;
            }
            continue;
        }
        if (\fnmatch($pattern, \basename($path), \FNM_PATHNAME) || \fnmatch($pattern, $path, \FNM_PATHNAME)) {
            return true;
        }
    }

    return false;
}

/**
 * The files the digest covers, in digest order.
 *
 * @param list<string> $exclude path prefixes to skip, on top of the toolchain's own
 * @param list<string> $ignore `.moggiignore` patterns, from `moggiIgnorePatterns`
 * @return list<array{type: string, size: int, hash: string, path: string, extra: string, absolute: string}>
 */
function directoryEntries(string $directory, array $exclude = [], array $ignore = []): array
{
    $root = \rtrim($directory, '/');
    $defaults = ['.git', '.moggi', 'moggi.lock', '.moggiignore'];
    $entries = [];

    $walk = static function (string $current, string $relative) use (&$walk, &$entries, $root, $defaults, $exclude, $ignore): void {
        $names = \scandir($current);
        if ($names === false) {
            throw new \RuntimeException("cannot read {$current}");
        }
        \sort($names, \SORT_STRING);

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $relative === '' ? $name : $relative . '/' . $name;
            if (\in_array($name, $defaults, true) || \in_array($path, $exclude, true)
                || isIgnoredPath($path, $ignore)) {
                continue;
            }

            $absolute = $current . '/' . $name;
            if (\is_link($absolute)) {
                $target = (string) \readlink($absolute);
                $problem = linkTargetProblem($path, $target);
                if ($problem !== null) {
                    throw new \RuntimeException($problem . ': ' . \var_export($path, true) . ' -> ' . \var_export($target, true));
                }
                $entries[] = [
                    'type' => 'link',
                    'size' => 0,
                    'hash' => \hash('sha256', $target),
                    'path' => $path,
                    'extra' => $target,
                    'absolute' => $absolute,
                ];
            } elseif (\is_dir($absolute)) {
                $walk($absolute, $path);
            } elseif (\is_file($absolute)) {
                $hash = \hash_file('sha256', $absolute);
                $size = \filesize($absolute);
                if ($hash === false || $size === false) {
                    throw new \RuntimeException("cannot read {$absolute}");
                }
                $entries[] = [
                    'type' => 'file',
                    'size' => $size,
                    'hash' => $hash,
                    'path' => $path,
                    'extra' => '',
                    'absolute' => $absolute,
                ];
            }
        }
    };

    $walk($root, '');

    \usort($entries, static fn (array $a, array $b): int => \strcmp($a['path'], $b['path']));

    foreach ($entries as $entry) {
        assertPortablePath($entry['path']);
    }

    return $entries;
}

/**
 * The digest of a directory, as `sha256:<hex>` — what a `dir` source names and
 * what a `docs` tree is checked against.
 *
 * @param list<string> $exclude
 * @param list<string> $ignore
 */
function directoryDigest(string $directory, array $exclude = [], array $ignore = []): string
{
    return 'sha256:' . \hash('sha256', directoryRecordBytes($directory, $exclude, $ignore));
}

/**
 * The exact bytes the digest is taken over, so `moggi pack --list` can show them
 * and a second implementation has something to compare against byte for byte.
 *
 * @param list<string> $exclude
 * @param list<string> $ignore
 */
function directoryRecordBytes(string $directory, array $exclude = [], array $ignore = []): string
{
    return entriesRecordBytes(directoryEntries($directory, $exclude, $ignore));
}

/**
 * The record bytes of an already-listed tree.
 *
 * The listing is the expensive half — one `scandir`, one hash per file — so a
 * caller that wants both digests *and* the archive from one tree lists it once
 * and derives the rest from the list, rather than walking it three times.
 *
 * @param list<array{type: string, size: int, hash: string, path: string, extra: string}> $entries
 */
function entriesRecordBytes(array $entries): string
{
    $bytes = '';
    foreach ($entries as $entry) {
        $bytes .= recordLine($entry);
    }

    return $bytes;
}

/**
 * The content digest of an already-listed tree, `sha256:<hex>` — the same value
 * `directoryDigest` gives for the tree it was listed from.
 *
 * @param list<array{type: string, size: int, hash: string, path: string, extra: string}> $entries
 */
function entriesDigest(array $entries): string
{
    return 'sha256:' . \hash('sha256', entriesRecordBytes($entries));
}

/**
 * One §3.6 record: `type NUL size NUL hash NUL path NUL extra NUL LF`.
 *
 * One place, so a record read back out of an archive and one read off a
 * directory cannot be spelled two different ways.
 *
 * @param array{type: string, size: int, hash: string, path: string, extra: string} $entry
 */
function recordLine(array $entry): string
{
    return \implode("\0", [
        $entry['type'],
        (string) $entry['size'],
        $entry['hash'],
        $entry['path'],
        $entry['extra'],
    ]) . "\0\n";
}

/**
 * The record bytes a canonical archive encodes — the same bytes
 * `directoryRecordBytes` gives for the tree it was packed from.
 *
 * `publish` re-derives a docs tree's digest *from the archive it is about to
 * upload*, rather than trusting a digest computed before the archive existed, so
 * the `docs` value it signs and the bytes on the wire cannot disagree. This is
 * the PHP half of the rule the worker's `readCanonicalArchive` implements, and it
 * is deliberately strict: an entry type the packer never writes is refused rather
 * than skipped, because an archive that is not the packer's output is not one to
 * take a digest over hopefully.
 */
function archiveRecordBytes(string $archive): string
{
    $entries = [];
    $offset = 0;
    $length = \strlen($archive);
    $pending = [];

    while ($offset + 512 <= $length) {
        $header = \substr($archive, $offset, 512);
        $offset += 512;
        if (\trim($header, "\0") === '') {
            break;
        }

        $name = \rtrim(\substr($header, 0, 100), "\0");
        $size = (int) \octdec(\rtrim(\substr($header, 124, 12), "\0 "));
        $type = \substr($header, 156, 1);
        $linkTarget = \rtrim(\substr($header, 157, 100), "\0");
        $prefix = \rtrim(\substr($header, 345, 155), "\0");
        if ($prefix !== '') {
            $name = $prefix . '/' . $name;
        }

        $content = '';
        if ($size > 0) {
            $content = \substr($archive, $offset, $size);
            $offset += (int) (\ceil($size / 512) * 512);
        }

        if ($type === 'x' || $type === 'g') {
            foreach (\explode("\n", $content) as $record) {
                if (\preg_match('/^(\d+) ([^=]+)=(.*)$/', $record, $match) === 1) {
                    $pending[$match[2]] = $match[3];
                }
            }
            continue;
        }
        if ($type === 'L') {
            $pending['path'] = $content;

            continue;
        }
        if (isset($pending['path'])) {
            $name = $pending['path'];
        }
        if (isset($pending['linkpath'])) {
            $linkTarget = $pending['linkpath'];
        }
        $pending = [];

        while (\str_starts_with($name, './')) {
            $name = \substr($name, 2);
        }
        if ($name === '' || $name === '.') {
            continue;
        }
        if ($type === '5') {
            continue;
        }
        if ($type === '2') {
            $entries[] = ['type' => 'link', 'size' => 0, 'hash' => \hash('sha256', $linkTarget), 'path' => $name, 'extra' => $linkTarget];
            continue;
        }
        if ($type !== '0' && $type !== '' && $type !== "\0") {
            throw new \RuntimeException("the canonical archive contains an unsupported entry type ({$type})");
        }
        $entries[] = ['type' => 'file', 'size' => $size, 'hash' => \hash('sha256', $content), 'path' => $name, 'extra' => ''];
    }

    \usort($entries, static fn (array $a, array $b): int => \strcmp($a['path'], $b['path']));

    $bytes = '';
    foreach ($entries as $entry) {
        $bytes .= recordLine($entry);
    }

    return $bytes;
}

/**
 * The content digest of an archive's tree, `sha256:<hex>` — the value a release's
 * `docs` field names. The server computes the same thing from the same archive
 * when the docs are uploaded (§7), so this is the check that says the digest being
 * signed is the digest of the bytes being sent.
 */
function archiveTreeDigest(string $archive): string
{
    return 'sha256:' . \hash('sha256', archiveRecordBytes($archive));
}

/**
 * Why a link target cannot live in a package, or null when it can.
 *
 * A target has to mean the same thing on every system that unpacks it: `/` is a
 * separator everywhere, a backslash only on Windows, and a target that climbs
 * above the package root writes outside the directory it is unpacked into. Both
 * `packDirectory` and `extractArchive` come through here, so what we write is
 * exactly what we accept.
 */
function linkTargetProblem(string $path, string $target): ?string
{
    if ($target === '') {
        return 'a link target may not be empty';
    }
    if (\str_contains($target, "\0") || \str_contains($target, "\n") || \str_contains($target, "\r")) {
        return 'a link target may not contain NUL, LF or CR';
    }
    if (\str_contains($target, '\\')) {
        return 'a link target must use forward slashes, not backslashes';
    }
    if (\str_starts_with($target, '/') || \preg_match('#^[A-Za-z]:#', $target) === 1) {
        return 'a link target must be relative';
    }

    $directory = \dirname($path);
    $parts = $directory === '.' || $directory === '' ? [] : \explode('/', $directory);
    foreach (\explode('/', $target) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts === []) {
                return 'a link target must stay inside the package';
            }
            \array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return null;
}

/**
 * A path that survives the trip between systems.
 *
 * NUL cannot appear in a path at all; a newline or carriage return would make a
 * record ambiguous; and non-NFC text is one name spelled two ways — macOS hands
 * back decomposed names for a file Linux stores composed, so without this the
 * same tree would hash differently on the two.
 *
 * ASCII is a subset of NFC, so only a non-ASCII path can be the second spelling,
 * and only it needs `intl`. A tree that has one and a packer without the
 * extension cannot promise cross-system digests, so it says so rather than
 * guessing.
 */
function assertPortablePath(string $path): void
{
    if (\str_contains($path, "\0") || \str_contains($path, "\n") || \str_contains($path, "\r")) {
        throw new \RuntimeException("a path may not contain NUL, LF or CR: " . \var_export($path, true));
    }
    if (!\mb_check_encoding($path, 'UTF-8')) {
        throw new \RuntimeException("a path must be valid UTF-8: " . \var_export($path, true));
    }
    if (\preg_match('/[\x80-\xff]/', $path) !== 1) {
        return;
    }
    if (!\class_exists(\Normalizer::class)) {
        throw new \RuntimeException(
            'a non-ASCII path needs the intl extension to check that it is NFC-normalized: '
            . \var_export($path, true) . ' — without it the same tree could hash differently elsewhere',
        );
    }
    if (!\Normalizer::isNormalized($path, \Normalizer::FORM_C)) {
        throw new \RuntimeException(
            "a path must be NFC-normalized: " . \var_export($path, true) . ' — rename it, or the same tree hashes differently elsewhere',
        );
    }
}

/**
 * The canonical archive: an uncompressed tar of the same entries, metadata fixed.
 *
 * @param list<string> $exclude
 * @param list<string> $ignore
 */
function packDirectory(string $directory, array $exclude = [], array $ignore = []): string
{
    return packEntries(directoryEntries($directory, $exclude, $ignore));
}

/**
 * The canonical archive of an already-listed tree — the same bytes
 * `packDirectory` writes for the tree it was listed from.
 *
 * @param list<array{type: string, size: int, hash: string, path: string, extra: string, absolute: string}> $entries
 */
function packEntries(array $entries): string
{
    $tar = '';
    foreach ($entries as $entry) {
        $link = $entry['type'] === 'link';
        $content = $link ? '' : (string) \file_get_contents($entry['absolute']);

        $metadata = '';
        if (\strlen($entry['path']) > 100) {
            $metadata .= paxRecord('path', $entry['path']);
        }
        if (\strlen($entry['extra']) > 100) {
            $metadata .= paxRecord('linkpath', $entry['extra']);
        }
        if ($metadata !== '') {
            $tar .= tarHeader('PaxHeaders/' . \substr(\basename($entry['path']), 0, 88), 'x', 0o644, \strlen($metadata), '');
            $tar .= padTo512($metadata);
        }

        $tar .= tarHeader($entry['path'], $link ? '2' : '0', $link ? 0o777 : 0o644, $link ? 0 : $entry['size'], $entry['extra']);
        $tar .= $link ? '' : padTo512($content);
    }

    return $tar . \str_repeat("\0", 1024);
}

/** The blob digest: sha256 of the canonical archive's bytes. */
function archiveDigest(string $archive): string
{
    return 'sha256:' . \hash('sha256', $archive);
}

/**
 * One pax extended-header record, `"%d %s=%s\n"` where the leading length covers
 * the whole record including its own digits and the newline (POSIX 1003.1).
 *
 * The length is self-referential, so it is settled by iteration: a guess of one
 * digit more or fewer changes the record's length, and the loop walks to the value
 * that agrees with itself. Without the length no reader can find the record's end,
 * so a name longer than 100 bytes would travel as its ustar truncation.
 */
function paxRecord(string $key, string $value): string
{
    $rest = ' ' . $key . '=' . $value . "\n";
    $length = \strlen($rest) + 1;
    while (true) {
        $next = \strlen($rest) + \strlen((string) $length);
        if ($next === $length) {
            break;
        }
        $length = $next;
    }

    return $length . $rest;
}

/**
 * One ustar header. Every field that could vary between machines is fixed: no
 * timestamps, no owners, no device numbers, and one of two modes — a file `644`,
 * a link `777` — because the executable bit is not portable.
 */
function tarHeader(string $name, string $type, int $mode, int $size, string $linkTarget): string
{
    $header = \str_pad(\substr($name, 0, 100), 100, "\0")
        . \sprintf("%07o\0", $mode)
        . \sprintf("%07o\0", 0)
        . \sprintf("%07o\0", 0)
        . \sprintf("%011o\0", $size)
        . \sprintf("%011o\0", 0)
        . '        '
        . $type
        . \str_pad(\substr($linkTarget, 0, 100), 100, "\0")
        . 'ustar' . "\0" . '00'
        . \str_pad('', 32, "\0")
        . \str_pad('', 32, "\0")
        . \sprintf("%07o\0", 0)
        . \sprintf("%07o\0", 0)
        . \str_pad('', 155, "\0")
        . \str_pad('', 12, "\0");

    $checksum = 0;
    for ($i = 0, $n = \strlen($header); $i < $n; $i++) {
        $checksum += \ord($header[$i]);
    }

    return \substr_replace($header, \sprintf("%06o\0 ", $checksum), 148, 8);
}

function padTo512(string $content): string
{
    $remainder = \strlen($content) % 512;

    return $content . ($remainder === 0 ? '' : \str_repeat("\0", 512 - $remainder));
}

/**
 * Read a canonical archive back: what `install` does with a blob.
 *
 * Refuses a path or a link that would escape the destination, and refuses an
 * entry type it does not understand rather than silently skipping it — a package
 * carrying a device node or a hard link is not something to extract hopefully.
 *
 * @return ?string a problem, or null when everything was written
 */
function extractArchive(string $archive, string $destination): ?string
{
    $offset = 0;
    $length = \strlen($archive);
    $pending = [];

    while ($offset + 512 <= $length) {
        $header = \substr($archive, $offset, 512);
        $offset += 512;
        if (\trim($header, "\0") === '') {
            break;
        }

        $name = \rtrim(\substr($header, 0, 100), "\0");
        $mode = (int) \octdec(\rtrim(\substr($header, 100, 8), "\0 "));
        $size = (int) \octdec(\rtrim(\substr($header, 124, 12), "\0 "));
        $type = \substr($header, 156, 1);
        $linkTarget = \rtrim(\substr($header, 157, 100), "\0");
        $prefix = \rtrim(\substr($header, 345, 155), "\0");
        if ($prefix !== '') {
            $name = $prefix . '/' . $name;
        }

        $content = '';
        if ($size > 0) {
            $content = \substr($archive, $offset, $size);
            $offset += (int) (\ceil($size / 512) * 512);
        }

        if ($type === 'x' || $type === 'g') {
            foreach (\explode("\n", $content) as $record) {
                if (\preg_match('/^(\d+) ([^=]+)=(.*)$/', $record, $match) === 1) {
                    $pending[$match[2]] = $match[3];
                }
            }
            continue;
        }
        if ($type === 'L') {
            $pending['path'] = $content;

            continue;
        }
        if (isset($pending['path'])) {
            $name = $pending['path'];
        }
        if (isset($pending['linkpath'])) {
            $linkTarget = $pending['linkpath'];
        }
        $pending = [];

        while (\str_starts_with($name, './')) {
            $name = \substr($name, 2);
        }
        if ($name === '' || $name === '.') {
            continue;
        }
        if (\str_starts_with($name, '/') || \preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            return "the archive contains an unsafe path ({$name})";
        }

        $target = \rtrim($destination, '/') . '/' . $name;
        if ($type === '5') {
            if (!\is_dir($target) && !@\mkdir($target, 0o777, true) && !\is_dir($target)) {
                return "cannot create directory {$target}";
            }
            continue;
        }
        if (!\is_dir(\dirname($target)) && !@\mkdir(\dirname($target), 0o777, true) && !\is_dir(\dirname($target))) {
            return "cannot create directory " . \dirname($target);
        }
        if ($type === '2') {
            $problem = linkTargetProblem($name, $linkTarget);
            if ($problem !== null) {
                return "the archive has an unsafe link ({$name} -> {$linkTarget}): {$problem}";
            }
            @\unlink($target);
            if (!@\symlink($linkTarget, $target)) {
                return "cannot create link {$target}";
            }
            continue;
        }
        if ($type !== '0' && $type !== '' && $type !== "\0") {
            return "the archive contains an unsupported entry type ({$type})";
        }
        if (@\file_put_contents($target, $content) === false) {
            return "cannot write {$target}";
        }
        @\chmod($target, $mode & 0o777);
    }

    return null;
}
