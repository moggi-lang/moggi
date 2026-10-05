<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Backend\Php\resolveMicroSfx;

/**
 * PHP extension requirements: `[requires.<role>.<backend>]` in a descriptor.
 *
 * An extension is compiled into, or loadable by, a particular PHP, so this
 * module gathers the requirement across a whole closure and answers which
 * runtime provides it. A requirement is declared for one of two roles — `program`
 * (what a compiled program needs at run time) or `compiler` (what running the
 * compiler needs) — and one backend, because the two roles are different
 * contracts answered by different runtimes:
 *
 *   [requires.program.php]
 *   extension = intl, bcmath
 *
 *   [requires.compiler.php]
 *   extension = phar
 *
 * The legacy `[php] extension` key is the same declaration under
 * `[requires.program.php]`, and stays accepted: it always meant the program-side,
 * php-backend requirement. Entries use the descriptor's own syntax:
 *
 *   foo              required
 *   ?foo             optional
 *   foo^1.2 / foo<3  a version constraint (the name is what gets compared)
 *   foo=path/to/lib  built against a path
 *
 * Two runtimes answer separately: the PHP running this process, and the micro
 * PHP runtime a `--native` build appends the PHAR to. The first reports its set
 * through `get_loaded_extensions()`, the second through the `extensions.json` a
 * packaging run records beside `micro.sfx`.
 */

/** The section spelling the program-side, php-backend requirement set has. */
const PROGRAM_PHP_REQUIREMENTS = 'requires.program.php';

/** The file a prepared runtime records its extension set in, beside its binary. */
const EXTENSION_MANIFEST = 'extensions.json';

/**
 * One `[php] extension` entry, split into the name a runtime is checked for, its
 * optional marker, and a path it names.
 *
 * @return array{entry: string, name: string, optional: bool, path: ?string}
 */
function parsePhpExtensionEntry(string $entry): array
{
    $text = \trim($entry);
    $optional = \str_starts_with($text, '?');
    $name = \ltrim($text, '?');
    $path = null;
    if (\str_contains($name, '=')) {
        [$name, $path] = \explode('=', $name, 2);
        $path = \trim($path);
    }
    $name = \trim(\preg_split('/[\^<>]/', $name)[0] ?? $name);

    return [
        'entry' => $text,
        'name' => $name,
        'optional' => $optional,
        'path' => $path === '' ? null : $path,
    ];
}

/**
 * The requirement entries one role and backend declares, in declaration order.
 *
 * A descriptor read by `readDescriptor` carries a `requirements` map; a synthetic
 * descriptor built by a caller may carry only the legacy `[php] extension` shape,
 * which reads as the program-side, php-backend set.
 *
 * @param array<string, mixed> $descriptor
 * @return list<string>
 */
function descriptorRequirementEntries(array $descriptor, string $role, string $backend): array
{
    $key = "requires.{$role}.{$backend}";
    $sections = $descriptor['requirements'] ?? null;
    if (\is_array($sections) && isset($sections[$key])) {
        return \array_values(\array_map('strval', (array) $sections[$key]));
    }
    if ($role === 'program' && $backend === 'php') {
        return descriptorPhpExtensions($descriptor);
    }

    return [];
}

/**
 * The program-side, php-backend requirement entries a descriptor declares.
 *
 * @param array<string, mixed> $descriptor
 * @return list<string>
 */
function descriptorPhpExtensions(array $descriptor): array
{
    return \array_values(\array_map('strval', (array) ($descriptor['php']['extensions'] ?? [])));
}

/**
 * The requirement entries an installed package declares, read from the
 * `<name>.moggi` beside its sources.
 *
 * @return ?list<string> the entries, or null when there is no descriptor to read
 */
function installedRequirementEntries(string $installDir, string $name, string $role, string $backend): ?array
{
    $file = \rtrim($installDir, '/\\') . '/' . $name . '.moggi';
    if (!\is_file($file)) {
        return null;
    }
    $ini = @\parse_ini_string((string) \file_get_contents($file), true, \INI_SCANNER_RAW);
    if (!\is_array($ini)) {
        return null;
    }
    $sections = requirementSections($ini);

    return \array_values(\array_map('strval', $sections["requires.{$role}.{$backend}"] ?? []));
}

/**
 * The program-side, php-backend requirement entries an installed package
 * declares, read from the `<name>.moggi` beside its sources.
 *
 * @return ?list<string> the entries, or null when there is no descriptor to read
 */
function installedPhpExtensions(string $installDir, string $name): ?array
{
    return installedRequirementEntries($installDir, $name, 'program', 'php');
}

/**
 * The descriptors whose requirements a build of this project depends on: the
 * project's own under the label `root`, and every installed package the lock
 * names under its own name.
 *
 * Each carries a `requirements` map — the canonical shape — so the program and
 * compiler roles both travel through.
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
 * A descriptor's `requirements` map, whether it was read by `readDescriptor` or
 * built by a caller in the legacy `[php] extension` shape.
 *
 * @param array<string, mixed> $descriptor
 * @return array<string, list<string>>
 */
function requirementSectionsFromDescriptor(array $descriptor): array
{
    $sections = $descriptor['requirements'] ?? null;
    if (\is_array($sections) && $sections !== []) {
        $out = [];
        foreach ($sections as $section => $entries) {
            $out[(string) $section] = \array_values(\array_map('strval', (array) $entries));
        }

        return $out;
    }

    $legacy = descriptorPhpExtensions($descriptor);

    return $legacy === [] ? [] : [PROGRAM_PHP_REQUIREMENTS => $legacy];
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
    if (!\is_file($file)) {
        return null;
    }
    $ini = @\parse_ini_string((string) \file_get_contents($file), true, \INI_SCANNER_RAW);

    return \is_array($ini) ? requirementSections($ini) : null;
}

/**
 * Merge the requirements of several descriptors into one set, keyed by the
 * lowercased extension name, with the labels that asked for each.
 *
 * A name is required when any source requires it, and optional when every source
 * marks it optional.
 *
 * @param array<string, array<string, mixed>> $descriptors label => descriptor
 * @return array<string, array{name: string, optional: bool, sources: list<string>, paths: list<string>}>
 */
function collectRequirementEntries(array $descriptors, string $role, string $backend): array
{
    $collected = [];
    foreach ($descriptors as $label => $descriptor) {
        $label = (string) $label;
        foreach (descriptorRequirementEntries($descriptor, $role, $backend) as $raw) {
            $entry = parsePhpExtensionEntry($raw);
            if ($entry['name'] === '') {
                continue;
            }
            $key = normalizeExtensionName($entry['name']);
            $existing = $collected[$key] ?? [
                'name' => $entry['name'],
                'optional' => true,
                'sources' => [],
                'paths' => [],
            ];
            $existing['optional'] = $existing['optional'] && $entry['optional'];
            if (!\in_array($label, $existing['sources'], true)) {
                $existing['sources'][] = $label;
            }
            if ($entry['path'] !== null && !\in_array($entry['path'], $existing['paths'], true)) {
                $existing['paths'][] = $entry['path'];
            }
            $collected[$key] = $existing;
        }
    }

    \ksort($collected, \SORT_STRING);

    return $collected;
}

/**
 * Two collected requirement sets as one, folding a name both roles ask for into a
 * single entry that names both sources.
 *
 * @param array<string, array{name: string, optional: bool, sources: list<string>, paths: list<string>}> $a
 * @param array<string, array{name: string, optional: bool, sources: list<string>, paths: list<string>}> $b
 * @return array<string, array{name: string, optional: bool, sources: list<string>, paths: list<string>}>
 */
function mergeRequirementSets(array $a, array $b): array
{
    $merged = $a;
    foreach ($b as $key => $entry) {
        $existing = $merged[$key] ?? null;
        if ($existing === null) {
            $merged[$key] = $entry;

            continue;
        }
        $sources = $existing['sources'];
        foreach ($entry['sources'] as $source) {
            if (!\in_array($source, $sources, true)) {
                $sources[] = $source;
            }
        }
        $paths = $existing['paths'];
        foreach ($entry['paths'] as $path) {
            if (!\in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }
        $merged[$key] = [
            'name' => $existing['name'],
            'optional' => $existing['optional'] && $entry['optional'],
            'sources' => $sources,
            'paths' => $paths,
        ];
    }

    \ksort($merged, \SORT_STRING);

    return $merged;
}

/**
 * The program-side, php-backend requirement set of several descriptors, merged.
 *
 * @param array<string, array<string, mixed>> $descriptors label => descriptor
 * @return array<string, array{name: string, optional: bool, sources: list<string>, paths: list<string>}>
 */
function collectPhpExtensions(array $descriptors): array
{
    return collectRequirementEntries($descriptors, 'program', 'php');
}

/** Extension names are case-insensitive, so comparison folds case. */
function normalizeExtensionName(string $name): string
{
    return \strtolower(\trim($name));
}

/** The extensions the PHP running this reports, folded for comparison. */
function phpRuntimeExtensions(): array
{
    return \array_map(normalizeExtensionName(...), \get_loaded_extensions());
}

/**
 * The required names a runtime's extension set does not provide, as the display
 * spelling from `$requirements`. Optional entries are compared and skipped.
 *
 * @param array<string, array{name: string, optional: bool, sources: list<string>, paths: list<string>}> $requirements
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
        if ($requirement['optional'] || isset($present[$key])) {
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
    if (!\is_file($file)) {
        return null;
    }
    $recorded = \json_decode((string) \file_get_contents($file), true);
    if (!\is_array($recorded) || !\is_array($recorded['extensions'] ?? null)) {
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
 * The program role and the compiler role are gathered separately and reported
 * separately, because they are answered by different runtimes: a program's needs
 * are what a built executable must be able to call, a compiler's needs are what
 * this process and the runtime a `--native` build uses must already carry.
 *
 * @param array<string, array<string, mixed>> $descriptors label => descriptor
 * @return array{problems: list<string>, blocking: list<string>} the human lines,
 *   and the subset the micro runtime lacks
 */
function phpExtensionProblems(array $descriptors): array
{
    $program = collectRequirementEntries($descriptors, 'program', 'php');
    $compiler = collectRequirementEntries($descriptors, 'compiler', 'php');
    $requirements = mergeRequirementSets($program, $compiler);
    $problems = [];
    $blocking = [];

    foreach (unmetPhpExtensions($requirements, phpRuntimeExtensions()) as $name) {
        $problems[] = extensionRequirementProblem($name, $requirements, 'the PHP running this (' . \PHP_VERSION . ')');
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
        $problems[] = extensionRequirementProblem($name, $requirements, 'the runtime a --native build uses');
        $blocking[] = $name;
    }

    return ['problems' => $problems, 'blocking' => $blocking];
}

/**
 * One finding line, naming the labels that asked for the extension.
 *
 * @param array<string, array{name: string, optional: bool, sources: list<string>, paths: list<string>}> $requirements
 */
function extensionRequirementProblem(string $name, array $requirements, string $runtime): string
{
    $key = normalizeExtensionName($name);
    $requirement = $requirements[$key] ?? null;
    $display = $requirement['name'] ?? $name;
    $sources = \array_map(
        static fn (string $label): string => $label === 'root' ? 'this package' : $label,
        $requirement['sources'] ?? [],
    );
    $by = $sources === [] || $sources === ['this package'] ? '' : ' (required by ' . \implode(', ', $sources) . ')';

    return "php extension `{$display}` is not in {$runtime}{$by}";
}
