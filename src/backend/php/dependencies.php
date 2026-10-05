<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Dependencies;

use function Moggi\Modules\libraryScanRoots;

/**
 * Library-owned PHP dependency directories, and the one external kind: a
 * Composer project.
 *
 * A library may bundle PHP sources its backend module needs; those live in the
 * module's sibling `php/` directory, and the codegen inlines them. External
 * packages are Composer's job — `moggi build` writes the `composer.json` and
 * runs it — and what arrives is a `vendor/` tree, which is *not* inlined: it is
 * copied into the artifact and required at startup, because it is code the
 * program calls into rather than helper functions it embeds.
 */

/** Where a Composer project's autoloader sits inside a resolved tree. */
const COMPOSER_AUTOLOAD = 'vendor/autoload.php';

/**
 * Library roots that hold a resolved Composer project.
 *
 * A resolved tree is a library root like any other (`moggi build` hands it to
 * the compile as one), so discovery is the same generic walk the JVM and .NET
 * scanners do. An entry is the root, not the autoloader, because the whole tree
 * travels with the artifact.
 *
 * @return list<string>
 */
function composerVendorRoots(): array
{
    $roots = [];
    foreach (libraryScanRoots() as $root) {
        if (\is_file(\rtrim($root, '/') . '/' . COMPOSER_AUTOLOAD)) {
            $roots[] = $root;
        }
    }

    return $roots;
}

/**
 * Whether this build has a Composer tree to carry.
 *
 * The single answer the emit and the artifact copy both ask, so a require is
 * never emitted for a tree that is not also copied.
 */
function composerIsBundled(): bool
{
    return composerVendorRoots() !== [];
}

/**
 * The `php/` dependency directory owned by one backend module, if any.
 *
 * Ownership is by directory: `lib/<path>/PHP.mog` owns `lib/<path>/php/`. Only
 * the PHP-backend module pulls in PHP helpers, so a sibling `.mog` in the same
 * directory (a JVM or .NET variant) never does.
 */
function phpCompanionDirForModule(string $sourcePath): ?string
{
    $stem = \pathinfo($sourcePath, \PATHINFO_FILENAME);
    if (\strcasecmp($stem, 'php') !== 0) {
        return null;
    }
    $dir = \dirname($sourcePath) . \DIRECTORY_SEPARATOR . 'php';

    return \is_dir($dir) ? $dir : null;
}

/**
 * Sorted absolute paths of the PHP sources a backend module bundles.
 *
 * @return list<string>
 */
function phpCompanionFilesForModule(string $sourcePath): array
{
    $dir = phpCompanionDirForModule($sourcePath);
    if ($dir === null) {
        return [];
    }
    $files = \glob($dir . \DIRECTORY_SEPARATOR . '*.php') ?: [];
    \sort($files);

    return $files;
}
