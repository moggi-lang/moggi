<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Backend\Php\resolveMicroSfx;

/**
 * PHP extension requirements: the `[php.extensions]` table of a descriptor.
 *
 * An extension is compiled into, or loadable by, a particular PHP, so this
 * module gathers the requirement across a whole closure and answers which
 * runtime provides it:
 *
 *   [php.extensions]
 *   bcmath  = *
 *   intl    = ^8.0
 *   libcurl = /path/to/lib
 *
 * The key names the extension and the value is its requirement: `*` for any, a
 * version constraint (the name is what gets compared), or a path it is built
 * against.
 *
 * Two runtimes answer separately: the PHP host this project installs into, and
 * the micro PHP runtime a `--native` build appends the PHAR to. The first is
 * asked over the probe in `Backend\Php\Host` — the bundled `runtime/php`, or
 * the compiler's own process when that process is a real CLI — and the second
 * through the `extensions.json` a packaging run records beside `micro.sfx`.
 *
 * A missing extension is a requirement, not a warning: the compiler cannot
 * introspect a symbol it cannot load, and a `--native` runtime cannot be made to
 * carry it after the fact. `phpExtensionHostProblems()` is the list a compile
 * refuses on, and every line ends in the command that fixes it.
 */

use function Moggi\Backend\Php\Host\phpHostExtensions;
use function Moggi\Backend\Php\Host\phpHostLabel;

/** The file a prepared runtime records its extension set in, beside its binary. */
const EXTENSION_MANIFEST = 'extensions.json';

/**
 * One `[php.extensions]` entry, split into the name a runtime is checked for and
 * a path it names.
 *
 * @return array{entry: string, name: string, path: ?string}
 */
function parsePhpExtensionEntry(string $entry): array
{
    $text = \trim($entry);
    $name = $text;
    $path = null;
    $beforeEquals = \strstr($name, '=', true);
    if (\is_string($beforeEquals) && ! \str_ends_with($beforeEquals, '>') && ! \str_ends_with($beforeEquals, '<')) {
        [$name, $path] = \explode('=', $name, 2);
        $path = \trim($path);
    }
    $name = \trim(\preg_split('/[\^<>]/', $name)[0] ?? $name);

    return [
        'entry' => $text,
        'name' => $name,
        'path' => $path === '' ? null : $path,
    ];
}

/**
 * The descriptors whose requirements a build of this project depends on: the
 * project's own under the label `root`, and every installed package the lock
 * names under its own name.
 *
 * Each carries a `requirements` map — the canonical shape — so a requirement
 * declared by an installed package is read the same way as the root's.
 *
 * @param array<string, mixed> $descriptor
 * @param array<string, mixed> $lock
 * @return array<string, array<string, mixed>> label => a descriptor-shaped array
 */
function phpExtensionDescriptors(array $descriptor, array $lock): array
{
    $descriptors = ['root' => ['requirements' => requirementSectionsFromDescriptor($descriptor)]];
    foreach ((array) ($lock['packages'] ?? []) as $name => $entry) {
        $name = (string) $name;
        $digest = \is_array($entry) ? ($entry['digest'] ?? null) : null;
        $requirements = installedRequirements(lockedInstallDir($name, \is_string($digest) ? $digest : null), $name);
        if ($requirements !== null && $requirements !== []) {
            $descriptors[$name] = ['requirements' => $requirements];
        }
    }

    return $descriptors;
}

/**
 * A descriptor's `requirements` map, as read by `readDescriptor`.
 *
 * @param array<string, mixed> $descriptor
 * @return array<string, list<string>>
 */
function requirementSectionsFromDescriptor(array $descriptor): array
{
    $sections = $descriptor['requirements'] ?? null;
    if (! \is_array($sections)) {
        return [];
    }
    $out = [];
    foreach ($sections as $section => $entries) {
        $out[(string) $section] = \array_values(\array_map('strval', (array) $entries));
    }

    return $out;
}

/**
 * The requirement map an installed package declares, read from the `<name>.moggi`
 * beside its sources.
 *
 * @return ?array<string, list<string>> the sections, or null when there is no
 *   descriptor to read
 */
function installedRequirements(string $installDir, string $name): ?array
{
    $file = \rtrim($installDir, '/\\') . '/' . $name . '.moggi';
    if (! \is_file($file)) {
        return null;
    }
    $ini = @\parse_ini_string((string) \file_get_contents($file), true, \INI_SCANNER_RAW);

    return \is_array($ini) ? requirementSections($ini) : null;
}

/**
 * Merge the `[php.extensions]` requirements of several descriptors into one set,
 * keyed by the lowercased extension name, with the labels that asked for each.
 *
 * @param array<string, array<string, mixed>> $descriptors label => descriptor
 * @return array<string, array{name: string, sources: list<string>, paths: list<string>}>
 */
function collectPhpExtensions(array $descriptors): array
{
    $collected = [];
    foreach ($descriptors as $label => $descriptor) {
        $label = (string) $label;
        foreach (requirementSectionsFromDescriptor($descriptor)['php.extensions'] ?? [] as $raw) {
            $entry = parsePhpExtensionEntry($raw);
            if ($entry['name'] === '') {
                continue;
            }
            $key = normalizeExtensionName($entry['name']);
            $existing = $collected[$key] ?? [
                'name' => $entry['name'],
                'sources' => [],
                'paths' => [],
            ];
            if (! \in_array($label, $existing['sources'], true)) {
                $existing['sources'][] = $label;
            }
            if ($entry['path'] !== null && ! \in_array($entry['path'], $existing['paths'], true)) {
                $existing['paths'][] = $entry['path'];
            }
            $collected[$key] = $existing;
        }
    }

    \ksort($collected, \SORT_STRING);

    return $collected;
}

/** Extension names are case-insensitive, so comparison folds case. */
function normalizeExtensionName(string $name): string
{
    return \strtolower(\trim($name));
}

/**
 * The extensions the project's PHP host reports, folded for comparison.
 *
 * A compiler that has no host to ask answers with an empty set, which makes
 * every declared extension unmet — the honest answer, and the one a PHP-less
 * variant should give rather than passing a project it can never run.
 */
function phpRuntimeExtensions(): array
{
    $extensions = phpHostExtensions();
    if ($extensions === null) {
        return [];
    }

    return \array_map(normalizeExtensionName(...), $extensions);
}

/**
 * The names a runtime's extension set does not provide, as the display spelling
 * from `$requirements`.
 *
 * @param array<string, array{name: string, sources: list<string>, paths: list<string>}> $requirements
 * @param list<string> $available
 * @return list<string> display names
 */
function unmetPhpExtensions(array $requirements, array $available): array
{
    $present = [];
    foreach ($available as $name) {
        $present[normalizeExtensionName((string) $name)] = true;
    }

    $unmet = [];
    foreach ($requirements as $key => $requirement) {
        if (isset($present[$key])) {
            continue;
        }
        $unmet[] = $requirement['name'];
    }

    return $unmet;
}

/**
 * The extension set a prepared runtime recorded beside its binary.
 *
 * @return ?list<string> the extensions, or null when nothing is recorded
 */
function runtimeExtensionsAt(string $runtimeDir): ?array
{
    $file = \rtrim($runtimeDir, '/\\') . '/' . EXTENSION_MANIFEST;
    if (! \is_file($file)) {
        return null;
    }
    $recorded = \json_decode((string) \file_get_contents($file), true);
    if (! \is_array($recorded) || ! \is_array($recorded['extensions'] ?? null)) {
        return null;
    }

    return \array_values(\array_map('strval', $recorded['extensions']));
}

/**
 * The micro PHP runtime's recorded extensions, read from the manifest beside the
 * `micro.sfx` a `--native` build appends the PHAR to.
 *
 * @return ?list<string> the extensions, or null when no runtime or no record
 */
function microRuntimeExtensions(?string $sfxPath = null): ?array
{
    $sfx = $sfxPath ?? resolveMicroSfx();
    if ($sfx === null) {
        return null;
    }

    return runtimeExtensionsAt(\dirname($sfx));
}

/**
 * The unmet requirement set of a closure, checked against both runtimes a build
 * can use.
 *
 * A package declares one set — what its program calls — and it is answered by
 * two runtimes: the PHP host this project installs into, and the micro PHP runtime a
 * `--native` build appends the PHAR to.
 *
 * @param array<string, array<string, mixed>> $descriptors label => descriptor
 * @return array{problems: list<string>, blocking: list<string>} the human lines,
 *   and the subset the micro runtime lacks
 */
function phpExtensionProblems(array $descriptors): array
{
    $requirements = collectPhpExtensions($descriptors);
    $problems = [];
    $blocking = [];

    if (phpHostExtensions() === null && $requirements !== []) {
        $problems[] = 'there is no PHP host to check php extensions against (this installation bundles no PHP;'
            . ' use a distribution that bundles one, or set MOGGI_PHP_HOST)';
    }

    foreach (unmetPhpExtensions($requirements, phpRuntimeExtensions()) as $name) {
        $problems[] = extensionRequirementProblem($name, $requirements, phpHostLabel(), 'moggi runtime php install-ext ' . $name);
    }

    $sfx = resolveMicroSfx();
    if ($sfx === null) {
        return ['problems' => $problems, 'blocking' => $blocking];
    }
    $manifest = microRuntimeExtensions($sfx);
    if ($manifest === null) {
        if ($requirements !== []) {
            $problems[] = 'the runtime a --native build uses records no extension set, so a --native build cannot be verified';
        }

        return ['problems' => $problems, 'blocking' => $blocking];
    }

    foreach (unmetPhpExtensions($requirements, $manifest) as $name) {
        $problems[] = extensionRequirementProblem($name, $requirements, 'the runtime a --native build uses', 'moggi runtime php native');
        $blocking[] = $name;
    }

    return ['problems' => $problems, 'blocking' => $blocking];
}

/**
 * The findings a compile refuses on: an extension a declared program calls that
 * its PHP host does not provide.
 *
 * The `--native` half is not a compile refusal — a non-native build never runs
 * the micro runtime — so it is reported by `phpExtensionProblems()` for callers
 * that ask about both, and this list stays the host requirement.
 *
 * @param array<string, array<string, mixed>> $descriptors label => descriptor
 * @return list<string>
 */
function phpExtensionHostProblems(array $descriptors): array
{
    $requirements = collectPhpExtensions($descriptors);
    if ($requirements === []) {
        return [];
    }

    $available = phpHostExtensions();
    if ($available === null) {
        return [
            'this installation has no PHP host to check php extensions against'
            . ' — use a distribution that bundles PHP, or set MOGGI_PHP_HOST to one',
        ];
    }

    $problems = [];
    foreach (unmetPhpExtensions($requirements, $available) as $name) {
        $problems[] = extensionRequirementProblem($name, $requirements, phpHostLabel(), 'moggi runtime php install-ext ' . $name);
    }

    return $problems;
}

/**
 * One finding line, naming the labels that asked for the extension.
 *
 * @param array<string, array{name: string, sources: list<string>, paths: list<string>}> $requirements
 */
function extensionRequirementProblem(string $name, array $requirements, string $runtime, ?string $remedy = null): string
{
    $key = normalizeExtensionName($name);
    $requirement = $requirements[$key] ?? null;
    $display = $requirement['name'] ?? $name;
    $sources = \array_map(
        static fn (string $label): string => $label === 'root' ? 'this package' : $label,
        $requirement['sources'] ?? [],
    );
    $by = $sources === [] || $sources === ['this package'] ? '' : ' (required by ' . \implode(', ', $sources) . ')';

    $fix = $remedy === null ? '' : " — run `{$remedy}`";

    return "php extension `{$display}` is not in {$runtime}{$by}{$fix}";
}
