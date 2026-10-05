<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/../src/registry/descriptor.php';
require_once __DIR__ . '/../src/registry/extensions.php';

/**
 * The PHP extension contract of a distribution.
 *
 * A distribution ships its packages as sources, so the extensions a bundled PHP
 * must carry are *derived* from the descriptors it ships rather than kept in a
 * list beside the runtime's version; `dist/runtimes.json` records what the
 * runtime was built with, and `assertRuntimeExtensionsDeclared` refuses a build
 * where the two have drifted. A prepared runtime writes the set it actually
 * loads into `extensions.json`, so a client can check a package's requirements
 * against the runtime instead of guessing.
 */

/**
 * The file a prepared runtime records its extension set in, beside its binary.
 *
 * A distribution is the only place that knows what a runtime was built with, so
 * it writes it down: the client then checks a package's `[php] extension`
 * requirements against the *actual* set rather than assuming it. The name is
 * shared with `Moggi\Registry\EXTENSION_MANIFEST`.
 */
const EXTENSION_MANIFEST = 'extensions.json';

/** The directories a distribution bundles as installable packages. */
const DISTRIBUTION_PACKAGE_DIRS = ['lib'];

/**
 * The requirement sections of every package descriptor a distribution bundles.
 *
 * A distribution ships its packages as sources, so each `<name>.moggi` travels
 * with the tree it describes and its `[requires.<role>.php]` sections are read
 * from there. What a runtime is built with is therefore derived from what the
 * distribution actually carries, not from a list kept beside the runtime's
 * version.
 *
 * @return array<string, array<string, list<string>>> label => section => entries
 */
function distributionPackageRequirements(): array
{
    $repo = \dirname(distRoot());
    $packages = [];
    foreach (DISTRIBUTION_PACKAGE_DIRS as $relative) {
        foreach (packageDescriptorFiles($repo . '/' . $relative) as $file) {
            $ini = @\parse_ini_string((string) \file_get_contents($file), true, \INI_SCANNER_RAW);
            if (!\is_array($ini)) {
                throw new \RuntimeException("cannot read the package descriptor {$file}");
            }
            $name = \trim((string) ($ini['package']['name'] ?? ''));
            $packages[$name === '' ? \basename($file, '.moggi') : $name] = \Moggi\Registry\requirementSections($ini);
        }
    }
    \ksort($packages, \SORT_STRING);

    return $packages;
}

/**
 * Every `<name>.moggi` a bundled package root carries, at any depth.
 *
 * @return list<string>
 */
function packageDescriptorFiles(string $dir): array
{
    if (!\is_dir($dir)) {
        return [];
    }
    $files = [];
    $walk = static function (string $current) use (&$walk, &$files): void {
        foreach (\scandir($current) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $current . '/' . $entry;
            if (\is_dir($path)) {
                $walk($path);
            } elseif (\str_ends_with($entry, '.moggi')) {
                $files[] = $path;
            }
        }
    };
    $walk($dir);
    \sort($files, \SORT_STRING);

    return $files;
}

/**
 * The PHP extensions a distribution's packages require, by role.
 *
 * The roles are different contracts — `program` is what a compiled program
 * calls at run time, `compiler` is what running the compiler needs — and they
 * are collected apart so a name one role asks for cannot answer the other by
 * accident. One PHP serves both, so the runtime is built with their union
 * (`requiredPhpExtensions`); the roles themselves stay separate here.
 *
 * @return array{program: list<string>, compiler: list<string>}
 */
function distributionExtensionRoles(): array
{
    $roles = ['program' => [], 'compiler' => []];
    foreach (distributionPackageRequirements() as $sections) {
        foreach (\array_keys($roles) as $role) {
            foreach ($sections["requires.{$role}.php"] ?? [] as $entry) {
                $name = \Moggi\Registry\parsePhpExtensionEntry((string) $entry)['name'];
                if ($name !== '') {
                    $roles[$role][\Moggi\Registry\normalizeExtensionName($name)] = $name;
                }
            }
        }
    }

    return [
        'program' => extensionNamesSorted($roles['program']),
        'compiler' => extensionNamesSorted($roles['compiler']),
    ];
}

/**
 * The extension set the bundled PHP is built with: both roles as one list.
 *
 * One PHP answers both contracts — a compiled program and the compiler that
 * produced it — so a requirement only one role names still has to be there.
 *
 * @return list<string>
 */
function requiredPhpExtensions(): array
{
    $roles = distributionExtensionRoles();
    $names = [];
    foreach ([...$roles['program'], ...$roles['compiler']] as $name) {
        $names[\Moggi\Registry\normalizeExtensionName($name)] = $name;
    }

    return extensionNamesSorted($names);
}

/**
 * Extension names, keyed case-folded by the caller, in comparison order.
 *
 * @param array<string, string> $names folded name => the spelling to display
 * @return list<string>
 */
function extensionNamesSorted(array $names): array
{
    $values = \array_values($names);
    \usort($values, static fn (string $a, string $b): int => \strcasecmp($a, $b));

    return $values;
}

/**
 * The required extensions a runtime's loaded-module list does not provide.
 *
 * PHP compares module names case-insensitively, so the check folds case too.
 *
 * @param list<string> $required
 * @param list<string> $loaded
 * @return list<string>
 */
function missingPhpExtensions(array $required, array $loaded): array
{
    $present = [];
    foreach ($loaded as $name) {
        $present[\Moggi\Registry\normalizeExtensionName((string) $name)] = true;
    }
    $missing = [];
    foreach ($required as $name) {
        if (!isset($present[\Moggi\Registry\normalizeExtensionName((string) $name)])) {
            $missing[] = (string) $name;
        }
    }

    return $missing;
}

/**
 * Refuse a prepared runtime that does not carry every required extension.
 *
 * @param list<string> $required
 * @param list<string> $loaded
 * @param list<string> $compiledIn
 */
function assertPhpRuntimeExtensions(array $required, array $loaded, array $compiledIn, string $target): void
{
    $missing = missingPhpExtensions($required, $loaded);
    if ($missing === []) {
        return;
    }

    throw new \RuntimeException(
        'the PHP runtime for ' . $target . ' has no ' . \implode(', ', $missing)
        . ' (compiled in: ' . \implode(' ', $compiledIn) . ')',
    );
}

/**
 * Refuse a distribution whose pinned extension list has drifted from the derived
 * set.
 *
 * `dist/runtimes.json` records what the bundled PHP is built with; the set itself
 * comes from the packages the distribution ships, so the list there is verified
 * output. A list that no longer names exactly what the packages ask for is a hard
 * assembly failure rather than a runtime built to a stale set.
 */
function assertRuntimeExtensionsDeclared(array $config): void
{
    $declared = [];
    foreach ((array) ($config['runtimes']['php']['extensions'] ?? []) as $name) {
        $declared[\Moggi\Registry\normalizeExtensionName((string) $name)] = (string) $name;
    }
    $declaredList = extensionNamesSorted($declared);
    $derived = requiredPhpExtensions();
    if (extensionSetsEqual($declaredList, $derived)) {
        return;
    }

    throw new \RuntimeException(
        'the bundled PHP\'s extension list has drifted from the distribution\'s requirements: '
        . 'dist/runtimes.json lists [' . \implode(', ', $declaredList) . '], '
        . 'the packages require [' . \implode(', ', $derived) . ']',
    );
}

/**
 * Whether two extension lists name the same set, case-insensitively.
 *
 * @param list<string> $a
 * @param list<string> $b
 */
function extensionSetsEqual(array $a, array $b): bool
{
    $fold = static function (array $names): array {
        $set = \array_map(\Moggi\Registry\normalizeExtensionName(...), $names);
        \sort($set, \SORT_STRING);

        return $set;
    };

    return $fold($a) === $fold($b);
}

/**
 * The configure flags a from-source PHP build is driven by.
 *
 * The name-to-recipe seam: the extension set above is derived, and this is the
 * one place a name becomes build input. The flags are still written by hand in
 * `dist/runtimes.json`; when the recipe catalog replaces them, this body is the
 * only thing that changes.
 *
 * @param list<string> $extensions the derived set, in comparison order
 * @return list<string>
 */
function extensionBuildFlags(array $extensions, array $config): array
{
    $flags = \array_values(\array_map('strval', (array) ($config['runtimes']['php']['configureFlags'] ?? [])));
    if ($extensions !== [] && $flags === []) {
        throw new \RuntimeException(
            'the bundled PHP needs ' . \implode(', ', $extensions) . ' but no configure flags are declared for it',
        );
    }

    return $flags;
}

/**
 * Record what a prepared runtime's extension set is, so a client can check a
 * package's `[php] extension` requirements against it instead of guessing.
 *
 * @param list<string> $extensions
 */
function writeRuntimeExtensionManifest(string $dir, string $runtime, array $extensions): void
{
    \file_put_contents(
        $dir . '/' . EXTENSION_MANIFEST,
        \json_encode([
            'runtime' => $runtime,
            'extensions' => \array_values($extensions),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
    );
}

/**
 * The extensions a prepared PHP runtime actually loads, writing its `php.ini` if
 * a bundled extension has to be enabled. Returns them so the caller can record
 * them beside the runtime.
 *
 * @return list<string> the loaded modules
 */
function assertPhpExtensions(string $binary, string $dir, string $target, array $config): array
{
    assertRuntimeExtensionsDeclared($config);
    $required = requiredPhpExtensions();
    $compiledIn = phpModuleList($binary, $dir, ['-n']);

    $enable = [];
    foreach ($required as $extension) {
        if (phpHasModule($compiledIn, $extension)) {
            continue;
        }
        $dll = $dir . '/ext/php_' . $extension . '.dll';
        if (\is_file($dll)) {
            $enable[] = $extension;
        }
    }

    writeBundledPhpIni($dir, $compiledIn, $enable);

    $loaded = phpModuleList($binary, $dir, ['-c', $dir . '/php.ini', '-d', 'extension_dir=' . $dir . '/ext']);
    assertPhpRuntimeExtensions($required, $loaded, $compiledIn, $target);

    return $loaded;
}

/** @param list<string> $modules */
function phpHasModule(array $modules, string $extension): bool
{
    foreach ($modules as $module) {
        if (\strcasecmp($module, $extension) === 0) {
            return true;
        }
    }

    return false;
}

/** Modules a PHP binary reports, run without touching the host's configuration. */
function phpModuleList(string $binary, string $dir, array $options): array
{
    if (\PHP_OS_FAMILY === 'Windows') {
        $output = runtimeProcess([$binary, ...$options, '-m']);
    } else {
        $output = runWithLibraryPath([$binary, ...$options, '-m'], $dir . '/lib');
    }

    $modules = [];
    foreach (\explode("\n", $output) as $line) {
        $line = \trim($line);
        if ($line === '' || ($line[0] === '[' && \str_ends_with($line, ']'))) {
            continue;
        }
        $modules[] = $line;
    }

    return $modules;
}

/**
 * @param list<string> $compiledIn
 * @param list<string> $enable
 */
function writeBundledPhpIni(string $dir, array $compiledIn, array $enable): void
{
    $lines = [
        '; Written by packaging/extensions.php.',
        '; Configuration of the PHP runtime bundled with Moggi; the compiler sets PHPRC to this',
        '; directory, so no other php.ini takes part.',
        ';',
        '; Compiled in: ' . \implode(' ', $compiledIn),
    ];
    foreach ($enable as $extension) {
        $lines[] = 'extension=php_' . $extension . '.dll';
    }
    $lines[] = '';

    \file_put_contents($dir . '/php.ini', \implode("\n", $lines));
}
