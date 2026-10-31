<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Dependencies;

use function Moggi\Modules\hostLibraryRoots;
use function Moggi\Modules\hostLibraryShapeSatisfied;
use function Moggi\Modules\libraryScanRoots;
use function Moggi\Paths\globPaths;

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
 * Roots that hold a resolved Composer project: library roots that vendor one, and the host trees
 * named with `--lib-composer`.
 *
 * An entry is the project root, not the autoloader, because the whole `vendor/` tree travels with
 * the artifact.
 *
 * @return list<string>
 */
function composerVendorRoots(): array
{
    $roots = [];
    foreach ([...libraryScanRoots(), ...hostLibraryRoots()] as $root) {
        if (hostLibraryShapeSatisfied('php', $root)) {
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
 * What a carried Composer tree can provide, parsed as data — never required.
 *
 * Requiring a downloaded `vendor/` tree at compile time would execute third-party code during
 * verification, so the autoloader maps are read as text instead: the classmap keys, the
 * PSR-0/PSR-4 prefixes with their directories, and — because `autoload_files.php` maps hashes to
 * files rather than naming what those files declare — the top-level functions and classes of each
 * listed file, read with `token_get_all` and no execution.
 *
 * The verdict this supports mirrors the runtime autoloader's own answer: a name the maps provide
 * (or a prefix computes to an existing file for) is a name the artifact's `require vendor/autoload.php`
 * would load, and anything else is unknown. Cached per root for the life of the process, since the
 * verification loop asks per declaration.
 *
 * @return array{
 *   classes: array<string, true>, functions: array<string, true>,
 *   psr4: list<array{prefix: string, dirs: list<string>}>, psr0: list<array{prefix: string, dirs: list<string>}>
 * } class/function keys are lowercase FQNs without a leading backslash
 */
function composerVendorMaps(string $root): array
{
    static $maps = [];
    if (isset($maps[$root])) {
        return $maps[$root];
    }

    $composer = \rtrim($root, '/') . '/vendor/composer';
    $classes = [];
    $functions = [];
    $psr4 = readPsrMaps($composer . '/autoload_psr4.php', $root);
    $psr0 = readPsrMaps($composer . '/autoload_namespaces.php', $root);

    $classmap = readAutoloadMap($composer . '/autoload_classmap.php');
    if ($classmap !== null) {
        foreach ($classmap['keys'] as $class) {
            $classes[normalizeVendorName($class)] = true;
        }
    }

    $files = readAutoloadMap($composer . '/autoload_files.php');
    if ($files !== null) {
        foreach ($files['strings'] as $literal) {
            if (! \str_ends_with($literal, '.php') || \preg_match('/^[0-9a-f]{32}$/i', $literal) === 1) {
                continue;
            }
            $path = resolveVendorLiteral($root, $literal);
            if ($path === null) {
                continue;
            }
            foreach (phpDeclaredSymbols($path) as $kind => $names) {
                foreach ($names as $name) {
                    if ($kind === 'function') {
                        $functions[normalizeVendorName($name)] = true;
                    } else {
                        $classes[normalizeVendorName($name)] = true;
                    }
                }
            }
        }
    }

    return $maps[$root] = ['classes' => $classes, 'functions' => $functions, 'psr4' => $psr4, 'psr0' => $psr0];
}

function normalizeVendorName(string $name): string
{
    return \strtolower(\ltrim($name, '\\'));
}

/**
 * The keys and string literals of a Composer `return [...]` map file, or null when the file is
 * absent or not the shape Composer generates. Keys name what the map provides (classes, prefixes);
 * string literals in value position name where it lives.
 *
 * @return ?array{keys: list<string>, strings: list<string>}
 */
function readAutoloadMap(string $file): ?array
{
    $src = @\file_get_contents($file);
    if ($src === false) {
        return null;
    }
    $tokens = @\token_get_all($src);
    if (! \is_array($tokens)) {
        return null;
    }

    $keys = [];
    $strings = [];
    $depth = 0;
    $count = \count($tokens);
    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (\is_string($token)) {
            if ($token === '(' || $token === '[') {
                ++$depth;
            } elseif (($token === ')' || $token === ']') && $depth > 0) {
                --$depth;
            }

            continue;
        }
        if ($token[0] !== \T_CONSTANT_ENCAPSED_STRING || $depth !== 1) {
            continue;
        }
        $literal = \stripcslashes(\substr($token[1], 1, -1));
        $strings[] = $literal;
        if (isMapKey($tokens, $i, $count)) {
            $keys[] = $literal;
        }
    }

    return $depth === 0 ? ['keys' => $keys, 'strings' => $strings] : null;
}

/**
 * A PSR-0/PSR-4 map file as prefix/dir entries, with directories resolved against the tree.
 *
 * Each prefix owns exactly the dir literals inside its own value array, so this walks the tokens
 * once keeping the current key rather than attributing every literal to every prefix.
 *
 * @return list<array{prefix: string, dirs: list<string>}>
 */
function readPsrMaps(string $file, string $root): array
{
    $src = @\file_get_contents($file);
    if ($src === false) {
        return [];
    }
    $tokens = @\token_get_all($src);
    if (! \is_array($tokens)) {
        return [];
    }

    $literalsByPrefix = [];
    $current = null;
    $depth = 0;
    $count = \count($tokens);
    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (\is_string($token)) {
            if ($token === '(' || $token === '[') {
                ++$depth;
            } elseif (($token === ')' || $token === ']') && $depth > 0) {
                --$depth;
                if ($depth === 0) {
                    $current = null;
                }
            }

            continue;
        }
        if ($token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $literal = \stripcslashes(\substr($token[1], 1, -1));
        if ($depth === 1 && isMapKey($tokens, $i, $count)) {
            $current = $literal;
            $literalsByPrefix[$current] ??= [];
        } elseif ($current !== null && $depth >= 1) {
            $literalsByPrefix[$current][] = $literal;
        }
    }

    $entries = [];
    foreach ($literalsByPrefix as $prefix => $literals) {
        $dirs = [];
        foreach ($literals as $literal) {
            $path = resolveVendorLiteral($root, $literal);
            if ($path !== null && \is_dir($path)) {
                $dirs[] = $path;
            }
        }
        $entries[] = ['prefix' => $prefix, 'dirs' => $dirs];
    }

    return $entries;
}

/** Whether the string at `$i` is a map key: the next meaningful token is `=>`. */
function isMapKey(array $tokens, int $i, int $count): bool
{
    for ($j = $i + 1; $j < $count; ++$j) {
        $next = $tokens[$j];
        if (\is_array($next)
            && ($next[0] === \T_WHITESPACE || $next[0] === \T_COMMENT || $next[0] === \T_DOC_COMMENT)) {
            continue;
        }

        return \is_array($next) && $next[0] === \T_DOUBLE_ARROW;
    }

    return false;
}

/**
 * A path literal from an autoloader map to a file or directory that exists.
 *
 * Composer writes `$baseDir . '/...'` (tree-relative) or absolute paths; a resolved tree may have
 * moved since, so the tree-relative candidate wins and the literal follows.
 */
function resolveVendorLiteral(string $root, string $literal): ?string
{
    $candidates = \str_starts_with($literal, '/')
        ? [\rtrim($root, '/') . $literal, $literal]
        : [\rtrim($root, '/') . '/vendor/composer/' . $literal];
    foreach ($candidates as $candidate) {
        if (\is_file($candidate) || \is_dir($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The file a PSR-4/PSR-0 prefix computes for a class, when that file exists.
 *
 * The longest matching prefix wins, exactly like the autoloader; a computed path that does not
 * exist is not a verdict, since the runtime would fail to load it too.
 *
 * @param list<array{prefix: string, dirs: list<string>}> $entries
 */
function vendorPsrClassFile(array $entries, string $class, bool $psr0): ?string
{
    $class = \ltrim($class, '\\');
    $best = null;
    $bestLength = -1;
    foreach ($entries as $entry) {
        $prefix = \ltrim((string) $entry['prefix'], '\\');
        if ($prefix === '') {
            continue;
        }
        $match = $psr0
            ? \str_starts_with(normalizePsr0($class), normalizePsr0($prefix))
            : \str_starts_with(\strtolower($class), \strtolower($prefix));
        if ($match && \strlen($prefix) > $bestLength) {
            $best = $entry;
            $bestLength = \strlen($prefix);
        }
    }
    if ($best === null) {
        return null;
    }
    $relative = $psr0
        ? \str_replace(['\\', '_'], '/', $class) . '.php'
        : \str_replace('\\', '/', \substr($class, \strlen(\ltrim((string) $best['prefix'], '\\')))) . '.php';
    foreach ((array) $best['dirs'] as $dir) {
        $candidate = \rtrim($dir, '/') . '/' . $relative;
        if (\is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function normalizePsr0(string $name): string
{
    return \strtolower(\str_replace(['\\', '_'], '/', $name));
}

/**
 * Top-level functions and classes a PHP file declares, without executing it.
 *
 * Closures (`function (`), arrow functions and methods (inside a class body) are not declarations;
 * conditionally declared functions are — the autoloader requires the file, so they exist at run
 * time. Names carry the file's namespace.
 *
 * @return array{function: list<string>, class: list<string>}
 */
function phpDeclaredSymbols(string $file): array
{
    $src = @\file_get_contents($file);
    if ($src === false) {
        return ['function' => [], 'class' => []];
    }
    $tokens = @\token_get_all($src);
    if (! \is_array($tokens)) {
        return ['function' => [], 'class' => []];
    }

    $functions = [];
    $classes = [];
    $namespace = '';
    $stack = [];
    $pendingClass = false;
    $count = \count($tokens);
    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (\is_string($token)) {
            if ($token === '{') {
                $stack[] = $pendingClass;
                $pendingClass = false;
            } elseif ($token === '}') {
                \array_pop($stack);
            }

            continue;
        }
        [$id, $text] = $token;
        if ($id === \T_NAMESPACE) {
            $namespace = readPhpNamespace($tokens, $i);
        } elseif ($id === \T_CLASS || $id === \T_INTERFACE || $id === \T_TRAIT || (\defined('T_ENUM') && $id === \T_ENUM)) {
            [$name, $next] = readPhpDeclaredName($tokens, $i);
            $i = $next;
            if ($name !== null && ! \in_array(true, $stack, true)) {
                $classes[] = $namespace === '' ? $name : $namespace . '\\' . $name;
            }
            $pendingClass = true;
        } elseif ($id === \T_FUNCTION) {
            [$name, $next] = readPhpDeclaredName($tokens, $i, true);
            $i = $next;
            if ($name !== null && ! \in_array(true, $stack, true)) {
                $functions[] = $namespace === '' ? $name : $namespace . '\\' . $name;
            }
        }
    }

    return ['function' => $functions, 'class' => $classes];
}

/** The namespace a `T_NAMESPACE` token opens (`Foo\Bar`, or '' for global code). */
function readPhpNamespace(array $tokens, int &$i): string
{
    $name = '';
    $count = \count($tokens);
    for ($j = $i + 1; $j < $count; ++$j) {
        $token = $tokens[$j];
        if (\is_string($token)) {
            break;
        }
        if ($token[0] === \T_STRING || $token[0] === \T_NAME_QUALIFIED
            || (\defined('T_NAME_FULLY_QUALIFIED') && $token[0] === \T_NAME_FULLY_QUALIFIED)) {
            $name .= $token[1];
        } elseif ($token[0] !== \T_WHITESPACE && $token[0] !== \T_COMMENT && $token[0] !== \T_DOC_COMMENT) {
            break;
        }
    }

    return \ltrim($name, '\\');
}

/**
 * The name a `T_CLASS`/`T_FUNCTION` token declares, or null for anonymous ones.
 *
 * @return array{?string, int} the name and the index to continue from
 */
function readPhpDeclaredName(array $tokens, int $i, bool $allowRef = false): array
{
    $count = \count($tokens);
    for ($j = $i + 1; $j < $count; ++$j) {
        $token = $tokens[$j];
        if (\is_string($token)) {
            if ($allowRef && $token === '&') {
                continue;
            }

            return [null, $j];
        }
        if ($token[0] === \T_STRING) {
            return [$token[1], $j];
        }
        if ($token[0] === \T_WHITESPACE || $token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
            continue;
        }

        return [null, $j];
    }

    return [null, $count];
}

/**
 * The `php/` dependency directory owned by one backend module, if any.
 *
 * Ownership is by directory: `<dir>/PHP.mog` owns `<dir>/php/`. Only
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
    $files = globPaths($dir . '/*.php');
    \sort($files);

    return $files;
}
