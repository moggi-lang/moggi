<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * Reading `<name>.moggi` — the file a package declares itself with.
 *
 * Discovery is a `*.moggi` glob at the project root only, exactly one descriptor
 * per project, named after the package it declares: `json.moggi` says
 * `name = json`. Plain INI, read with `parse_ini_file` in raw mode — raw because
 * `8.5` is a version and not a float, and `0.1.0` must not be re-spelled on the
 * way back out.
 *
 * The format is closed: it has no extension point, so a key the schema does not
 * declare is an error rather than something a newer compiler might have meant.
 * `DESCRIPTOR_SCHEMA` below is therefore the whole format, and
 * `descriptorProblems()` is the one place that answers "is this a descriptor" —
 * the parser refuses a bad one, and `moggi check` prints the same list instead of
 * keeping its own idea of what is valid.
 */

const DESCRIPTOR_SUFFIX = '.moggi';

/**
 * Every section and every key the descriptor declares.
 *
 * `[dependencies]` is an empty list because every key in it is a package name —
 * the resolver is what knows those, not the descriptor reader.
 */
const DESCRIPTOR_SCHEMA = [
    'package' => ['name', 'version', 'license', 'license-file', 'copyright', 'description', 'backends', 'homepage', 'repository', 'keywords', 'maintainer'],
    'dependencies' => [],
    'lib' => ['source-dirs'],
    'docs' => ['target-dir'],
    'php' => ['version', 'composer', 'git', 'files'],
    'jvm' => ['version', 'git', 'files'],
    'dotnet' => ['version', 'git', 'files'],
];

/**
 * The `[<backend>.<vocabulary>]` tables: one package item per key, its
 * requirement in the value.
 *
 * The keys are free-form — an extension name, a `group:artifact`, a NuGet id —
 * because what each vocabulary accepts is the vocabulary's business, not the
 * format's, exactly as `[dependencies]` keys are package names. A value is the
 * requirement: `*` for any, a version or a constraint, or a path an extension is
 * built against.
 */
const DESCRIPTOR_BACKEND_TABLES = ['php.extensions', 'jvm.maven', 'dotnet.nuget'];

/** The sections that carry a `.<id>` suffix, and the keys they accept. */
const DESCRIPTOR_REPEATABLE_SECTIONS = [
    'author' => ['name', 'email', 'npub'],
    'executable' => ['main', 'source-dirs', 'backend'],
    'test-suite' => ['main', 'source-dirs', 'backend'],
];

/**
 * The one descriptor at a project root.
 *
 * A directory is globbed at its top level; a path to a file is taken as given,
 * so `moggi install app/json.moggi` works too.
 */
function findDescriptor(string $path): string
{
    $suffix = DESCRIPTOR_SUFFIX;
    if (\is_file($path)) {
        return $path;
    }
    if (!\is_dir($path)) {
        throw new \RuntimeException("no such directory: {$path}");
    }

    $found = \array_values(\array_filter(
        \glob(\rtrim($path, '/') . '/*' . $suffix) ?: [],
        'is_file',
    ));
    if ($found === []) {
        throw new \RuntimeException(
            "no package descriptor in {$path}: a package needs a <name>{$suffix} file at its root",
        );
    }
    if (\count($found) > 1) {
        throw new \RuntimeException(
            "more than one descriptor in {$path} — a package declares itself once:\n  "
            . \implode("\n  ", \array_map('basename', $found)),
        );
    }

    return $found[0];
}

/**
 * Everything wrong with a descriptor, in one list, without throwing.
 *
 * Text first — a key that does not exist, a key written twice, a comment where a
 * header should be — then the values the format fixes: an identity that is not
 * an npub, a version that is not a version, a dependency floor that is not a
 * constraint.
 *
 * @return list<string>
 */
function descriptorProblems(string $path): array
{
    $problems = descriptorFormatProblems($path);

    $ini = @\parse_ini_file($path, true, \INI_SCANNER_RAW);
    if ($ini === false) {
        return [...$problems, "{$path} cannot be read as INI"];
    }

    $package = \is_array($ini['package'] ?? null) ? $ini['package'] : [];

    $name = isset($package['name']) ? \trim((string) $package['name']) : '';
    if ($name === '') {
        $problems[] = "{$path}: [package] requires `name`";
    } else {
        $nameProblem = packageNameProblem($name);
        if ($nameProblem !== null) {
            $problems[] = "{$path}: {$nameProblem}";
        }
        $expected = \basename($path);
        if ($name . DESCRIPTOR_SUFFIX !== $expected) {
            $problems[] = "{$path}: a descriptor is named after its package — expected {$name}" . DESCRIPTOR_SUFFIX . ", found {$expected}";
        }
    }

    $version = isset($package['version']) ? \trim((string) $package['version']) : '';
    if ($version === '') {
        $problems[] = "{$path}: [package] requires `version`";
    } elseif (\preg_match('/^\d+(\.\d+)*([-+][0-9A-Za-z.\-+]+)?$/', $version) !== 1) {
        $problems[] = "{$path}: `version = {$version}` is not a version — `1.2.3`, optionally with a `-tag` or `+meta`, is";
    }

    if (isset($package['backends']) && \trim((string) $package['backends']) === '') {
        $problems[] = "{$path}: `backends =` is empty, which reads as every backend — drop the key if that is what is meant";
    }
    foreach (['homepage', 'repository'] as $key) {
        if (!isset($package[$key])) {
            continue;
        }
        $problem = metadataUrlProblem($key, \trim((string) $package[$key]));
        if ($problem !== null) {
            $problems[] = "{$path}: {$problem}";
        }
    }
    foreach (['keywords', 'maintainer'] as $key) {
        if (isset($package[$key]) && \trim((string) $package[$key]) === '') {
            $problems[] = "{$path}: `{$key} =` is empty, which says nothing — drop the key if that is what is meant";
        }
    }
    try {
        $backends = descriptorBackends($ini, $path);
        if (\count($backends) !== \count(\array_unique($backends))) {
            $problems[] = "{$path}: [package] backends names the same backend twice";
        }
    } catch (\RuntimeException $error) {
        $problems[] = $error->getMessage();
    }

    $authors = [];
    foreach ($ini as $section => $values) {
        if (!\is_array($values) || !\preg_match('/^author(\.(.+))?$/', (string) $section)) {
            continue;
        }
        $npub = \trim((string) ($values['npub'] ?? ''));
        if ($npub === '') {
            continue;
        }
        if (!isNpub($npub)) {
            $problems[] = "{$path}: [{$section}] `npub = {$npub}` is not an npub (63 characters, `npub1` then bech32)";
        }
        $authors[] = $npub;
    }
    if ($authors === []) {
        $problems[] = "{$path}: [author] needs an npub — releases are signed by identity, not by name";
    }
    if (\count($authors) !== \count(\array_unique($authors))) {
        $problems[] = "{$path}: the same npub appears in more than one [author] block";
    }

    foreach (\is_array($ini['dependencies'] ?? null) ? $ini['dependencies'] : [] as $dependency => $constraint) {
        $dependencyProblem = packageNameProblem((string) $dependency);
        if ($dependencyProblem !== null) {
            $problems[] = "{$path}: [dependencies] {$dependencyProblem}";
        }
        $problem = constraintProblem((string) $constraint);
        if ($problem !== null) {
            $problems[] = "{$path}: [dependencies] `{$dependency} = {$constraint}`: {$problem}";
        }
        if ($name !== '' && (string) $dependency === $name) {
            $problems[] = "{$path}: [dependencies] `{$dependency}` depends on itself";
        }
    }

    foreach (requirementSections($ini) as $section => $entries) {
        foreach ($entries as $entry) {
            $problem = extensionEntryProblem($entry);
            if ($problem !== null) {
                $problems[] = "{$path}: [{$section}] `{$entry}`: {$problem}";
            }
        }
    }

    return $problems;
}

/**
 * Every requirement table in a parsed descriptor, keyed by the section spelling,
 * each as the inline entries the rest of the compiler has always read.
 *
 * The table is `name = requirement`, so a checker still sees `bcmath`,
 * `intl^8.0` or `libcurl=/path`; the spelling only changed at the file.
 *
 * @param array<string, mixed> $ini a parsed descriptor
 * @return array<string, list<string>> section => entries
 */
function requirementSections(array $ini): array
{
    $extensions = [];
    foreach (\is_array($ini['php.extensions'] ?? null) ? $ini['php.extensions'] : [] as $name => $requirement) {
        $entry = phpExtensionRequirement((string) $name, (string) $requirement);
        if ($entry !== null) {
            $extensions[] = $entry;
        }
    }
    $extensions = \array_values(\array_unique($extensions));
    if ($extensions === []) {
        return [];
    }

    return ['php.extensions' => $extensions];
}

/**
 * One `[php.extensions]` line as the inline entry a checker reads: `bcmath` for
 * `*`, `intl^8.0` for a constraint, `libcurl=/path` for a path.
 *
 * The key names the extension and the value is its requirement, so the inline
 * entry keeps the one grammar it has always had. An empty name is not an entry.
 */
function phpExtensionRequirement(string $name, string $requirement): ?string
{
    $name = \trim($name);
    if ($name === '') {
        return null;
    }
    $requirement = \trim($requirement);
    if ($requirement === '' || $requirement === '*') {
        return $name;
    }
    if (\str_contains($requirement, '/')) {
        return $name . '=' . $requirement;
    }

    return $name . $requirement;
}

/**
 * The `group:artifact:version` coordinates a `[jvm.maven]` / `[dotnet.nuget]`
 * table declares, in the one spelling the host-tool layer reads.
 *
 * @param array<string, mixed> $ini a parsed descriptor
 * @return list<string>
 */
function backendTableCoordinates(array $ini, string $table): array
{
    $coordinates = [];
    foreach (\is_array($ini[$table] ?? null) ? $ini[$table] : [] as $item => $requirement) {
        $item = \trim((string) $item);
        if ($item === '') {
            continue;
        }
        $requirement = \trim((string) $requirement);
        $coordinates[] = ($requirement === '' || $requirement === '*') ? $item : $item . ':' . $requirement;
    }

    return $coordinates;
}

/**
 * What is wrong with one `[php] extension` entry, or null when it is one.
 *
 * The entry grammar is the descriptor's own: `foo`, `foo^1.2`, `foo=path`.
 * A typo here is invisible until a runtime is checked against it, which is a
 * refusal at the far end of a build rather than at the file that has it.
 */
function extensionEntryProblem(string $entry): ?string
{
    $text = \trim($entry);
    if ($text === '') {
        return 'an empty entry names no extension';
    }
    $parsed = \Moggi\Registry\parsePhpExtensionEntry($text);
    if ($parsed['name'] === '' || \preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $parsed['name']) !== 1) {
        return "`{$parsed['name']}` is not an extension name (letters, digits and `_`, starting with a letter)";
    }

    return null;
}

/**
 * The descriptor's *text* against the format: sections and keys that do not
 * exist, keys written twice, lines that are neither.
 *
 * Duplicate keys are found in the text because INI itself resolves them by
 * keeping the last one, silently — which is exactly how a package ends up
 * published with a `npub` nobody meant to write.
 *
 * @return list<string>
 */
function descriptorFormatProblems(string $path): array
{
    $raw = @\file_get_contents($path);
    if ($raw === false) {
        return ["cannot read {$path}"];
    }

    $problems = [];
    if (\str_starts_with($raw, "\xEF\xBB\xBF")) {
        $problems[] = "{$path}: starts with a byte-order mark — a descriptor is plain UTF-8";
        $raw = \substr($raw, 3);
    }

    $section = '';
    $seenSections = [];
    $seenKeys = [];

    foreach (\preg_split('/\R/', $raw) ?: [] as $number => $line) {
        $trimmed = \trim($line);
        if ($trimmed === '' || $trimmed[0] === ';' || $trimmed[0] === '#') {
            continue;
        }
        $where = "{$path}:" . ($number + 1);

        if ($trimmed[0] === '[') {
            if (\preg_match('/^\[([^\]]+)\]\s*(?:[;#].*)?$/', $trimmed, $match) !== 1) {
                $problems[] = "{$where}: malformed section header `{$trimmed}`";

                continue;
            }
            $section = $match[1];
            if (isset($seenSections[$section])) {
                $problems[] = "{$where}: section [{$section}] is declared twice";
            }
            $seenSections[$section] = true;
            if (!descriptorSectionKnown($section)) {
                $problems[] = "{$where}: unknown section [{$section}]";
            }

            continue;
        }

        $equals = \strpos($trimmed, '=');
        if ($equals === false || \strcspn($trimmed, ';#') < $equals) {
            $problems[] = "{$where}: not `key = value` and not a section header";

            continue;
        }
        \preg_match('/^([A-Za-z0-9_.\-:\/]+)\s*=/', $trimmed, $match);
        $key = $match[1];

        if ($section === '') {
            $problems[] = "{$where}: `{$key}` is outside any section";

            continue;
        }
        if (isset($seenKeys[$section][$key])) {
            $problems[] = "{$where}: `{$key}` is set twice in [{$section}]";
        }
        $seenKeys[$section][$key] = true;

        if (!descriptorSectionAllows($section, $key)) {
            $problems[] = "{$where}: " . descriptorUnknownKey($section, $key);
        }
    }

    return $problems;
}

/** The sentence for a key the section does not take, naming what it does. */
function descriptorUnknownKey(string $section, string $key): string
{
    $base = \explode('.', $section, 2)[0];
    if ($base === 'lib' && $key === 'backend') {
        return '[lib] takes no `backend` — a library has no entry point to build';
    }

    $allowed = DESCRIPTOR_REPEATABLE_SECTIONS[$base] ?? DESCRIPTOR_SCHEMA[$section] ?? [];
    if ($allowed === []) {
        return "[{$section}] does not take `{$key}`";
    }

    return "[{$section}] does not take `{$key}` (it takes: " . \implode(', ', $allowed) . ')';
}

/**
 * Whether a section is a `[<backend>.<vocabulary>]` requirement table, whose keys
 * are free-form and so checked by the vocabulary, not the format.
 */
function descriptorSectionIsTable(string $section): bool
{
    return \in_array($section, DESCRIPTOR_BACKEND_TABLES, true);
}

function descriptorSectionKnown(string $section): bool
{
    return isset(DESCRIPTOR_SCHEMA[$section])
        || isset(DESCRIPTOR_REPEATABLE_SECTIONS[\explode('.', $section, 2)[0]])
        || descriptorSectionIsTable($section);
}

function descriptorSectionAllows(string $section, string $key): bool
{
    if ($section === 'dependencies' || descriptorSectionIsTable($section)) {
        return true;
    }
    $allowed = DESCRIPTOR_REPEATABLE_SECTIONS[\explode('.', $section, 2)[0]] ?? DESCRIPTOR_SCHEMA[$section] ?? null;

    return $allowed !== null && \in_array($key, $allowed, true);
}

/**
 * What is wrong with a metadata URL, or null when it is one.
 *
 * A URL here is a link a reader follows, so it has to be absolute and use a
 * scheme a browser opens without help: `https`, or `http` for a server that has
 * not moved yet. A bare hostname, a relative path or a `git`/`ssh` remote each
 * reads as a link but is not one, so each is refused.
 */
function metadataUrlProblem(string $key, string $value): ?string
{
    $scheme = \parse_url($value, \PHP_URL_SCHEME);
    $host = \parse_url($value, \PHP_URL_HOST);
    if (\is_string($scheme) && \in_array(\strtolower($scheme), ['http', 'https'], true)
        && \is_string($host) && $host !== '') {
        return null;
    }

    return "`{$key} = {$value}` is not a URL — `https://host/path` is";
}

/** Whether a key is an npub of the shape an npub has. */
function isNpub(string $npub): bool
{
    return \strlen($npub) === 63
        && \str_starts_with($npub, 'npub1')
        && \preg_match('/^npub1[023456789acdefghjklmnpqrstuvwxyz]+$/', $npub) === 1;
}

/**
 * @return array{
 *   path: string,
 *   name: string,
 *   version: string,
 *   authors: list<string>,
 *   dependencies: array<string, string>,
 *   requirements: array<string, list<string>>,
 *   backends: list<string>,
 *   php: array{version: ?string, extensions: list<string>, composer: list<string>, git: ?string, files: ?string},
 *   jvm: array{maven: list<string>, git: ?string, files: ?string},
 *   dotnet: array{nuget: list<string>, git: ?string, files: ?string},
 *   lib: array{sourceDirs: list<string>},
 *   executables: array<string, array{main: string, sourceDirs: list<string>, backend: ?string}>,
 *   testSuites: array<string, array{main: string, sourceDirs: list<string>, backend: ?string}>,
 *   docs: array{targetDir: ?string},
 *   license: ?string,
 *   licenseFile: ?string,
 *   copyright: ?string,
 *   description: ?string,
 *   homepage: ?string,
 *   repository: ?string,
 *   keywords: list<string>,
 *   maintainer: ?string
 * }
 */
function readDescriptor(string $path): array
{
    $problems = descriptorProblems($path);
    if ($problems !== []) {
        throw new \RuntimeException(\implode("\n  ", $problems));
    }

    $ini = \parse_ini_file($path, true, \INI_SCANNER_RAW);
    if ($ini === false) {
        throw new \RuntimeException("cannot read {$path} as INI");
    }

    $package = \is_array($ini['package'] ?? null) ? $ini['package'] : [];
    $authors = [];
    $authorEmails = [];
    foreach ($ini as $section => $values) {
        if (!\is_array($values) || !\preg_match('/^author(\.(.+))?$/', (string) $section)) {
            continue;
        }
        if (isset($values['npub'])) {
            $authors[] = (string) $values['npub'];
        }
        $email = \trim((string) ($values['email'] ?? ''));
        if ($email !== '') {
            $authorEmails[] = $email;
        }
    }

    $dependencies = [];
    foreach (\is_array($ini['dependencies'] ?? null) ? $ini['dependencies'] : [] as $dependency => $range) {
        $dependencies[(string) $dependency] = (string) $range;
    }

    $requirements = requirementSections($ini);

    return [
        'path' => $path,
        'name' => \trim((string) ($package['name'] ?? '')),
        'version' => \trim((string) ($package['version'] ?? '')),
        'authors' => $authors,
        'dependencies' => $dependencies,
        'requirements' => $requirements,
        'backends' => descriptorBackends($ini, $path),
        'php' => [
            'version' => isset($ini['php']['version']) ? (string) $ini['php']['version'] : null,
            'extensions' => $requirements['php.extensions'] ?? [],
            'composer' => splitList((string) ($ini['php']['composer'] ?? '')),
            'git' => optionalString($ini, 'php', 'git'),
            'files' => optionalString($ini, 'php', 'files'),
        ],
        'jvm' => [
            'version' => isset($ini['jvm']['version']) ? (string) $ini['jvm']['version'] : null,
            'maven' => backendTableCoordinates($ini, 'jvm.maven'),
            'git' => optionalString($ini, 'jvm', 'git'),
            'files' => optionalString($ini, 'jvm', 'files'),
        ],
        'dotnet' => [
            'version' => isset($ini['dotnet']['version']) ? (string) $ini['dotnet']['version'] : null,
            'nuget' => backendTableCoordinates($ini, 'dotnet.nuget'),
            'git' => optionalString($ini, 'dotnet', 'git'),
            'files' => optionalString($ini, 'dotnet', 'files'),
        ],
        'lib' => ['sourceDirs' => splitList((string) ($ini['lib']['source-dirs'] ?? ''))],
        'executables' => readRepeatableSection($ini, 'executable'),
        'testSuites' => readRepeatableSection($ini, 'test-suite'),
        'docs' => ['targetDir' => optionalString($ini, 'docs', 'target-dir')],
        'license' => optionalString($ini, 'package', 'license'),
        'licenseFile' => optionalString($ini, 'package', 'license-file'),
        'copyright' => optionalString($ini, 'package', 'copyright'),
        'description' => optionalString($ini, 'package', 'description'),
        'homepage' => optionalString($ini, 'package', 'homepage'),
        'repository' => optionalString($ini, 'package', 'repository'),
        'keywords' => splitList((string) ($package['keywords'] ?? '')),
        'maintainer' => optionalString($ini, 'package', 'maintainer') ?? ($authorEmails[0] ?? null),
    ];
}

/** @param array<string, mixed> $ini */
function optionalString(array $ini, string $section, string $key): ?string
{
    $values = \is_array($ini[$section] ?? null) ? $ini[$section] : [];
    if (!isset($values[$key])) {
        return null;
    }
    $value = \trim((string) $values[$key]);

    return $value === '' ? null : $value;
}

/**
 * Every backend this compiler can build for, which is what an unset
 * `[package] backends` means.
 *
 * @return list<string>
 */
function allBackends(): array
{
    return \Moggi\Backend\implementedBackendIds();
}

/**
 * `[package] backends` — the backends a package says it can be built for.
 *
 * Optional, and absent means every backend: a package that is pure Moggi works
 * everywhere, and demanding three names of every descriptor would be noise. It is
 * a declaration, not a proof — the compiler still fails on whatever the sources
 * actually reach for — but it turns "this package has no PHP behind it" into one
 * sentence naming the package, at the point the backend is chosen, instead of a
 * missing symbol deep in code generation.
 *
 * `$path` is used to name the file in an error; passing an empty one is how a
 * *dependency's* descriptor is read, where an unknown name is left as written —
 * the only question there is whether it supports the backend being built.
 *
 * @param array<string, mixed> $ini a parsed descriptor
 * @return list<string>
 */
function descriptorBackends(array $ini, string $path): array
{
    $package = \is_array($ini['package'] ?? null) ? $ini['package'] : [];
    $raw = \trim((string) ($package['backends'] ?? ''));
    if ($raw === '') {
        return allBackends();
    }

    $known = allBackends();
    $backends = [];
    foreach (splitList($raw) as $backend) {
        if ($path !== '' && !\in_array($backend, $known, true)) {
            throw new \RuntimeException(
                "{$path}: [package] backends names an unknown backend `{$backend}` (this compiler builds: "
                . \implode(', ', $known) . ')',
            );
        }
        $backends[] = $backend;
    }

    return $backends;
}

/**
 * The backends a descriptor's bytes declare, for a package being *linked
 * against* rather than built.
 *
 * Read straight from the INI rather than through `readDescriptor`, because an
 * installed dependency's tree must not have to satisfy every rule a local
 * descriptor does — `<name>.moggi` is named after its package by construction and
 * was checked when it was packed.
 *
 * A descriptor that cannot be read is refused, not assumed to support everything:
 * defaulting to every backend would let a package the build cannot actually link
 * against through the check that exists to stop exactly that.
 */
function declaredBackends(string $iniBytes): array
{
    $ini = @\parse_ini_string($iniBytes, true, \INI_SCANNER_RAW);
    if (!\is_array($ini)) {
        throw new \RuntimeException('a descriptor could not be read as INI, so the backends it declares are unknown');
    }

    return descriptorBackends($ini, '');
}

/**
 * Every `[<section>]` / `[<section>.<id>]` block, keyed by id. The unprefixed
 * block has the empty key, because that is the one `moggi run` starts when no
 * `--exe` is given.
 *
 * @param array<string, mixed> $ini
 * @return array<string, array{main: string, sourceDirs: list<string>, backend: ?string}>
 */
function readRepeatableSection(array $ini, string $name): array
{
    $blocks = [];
    foreach ($ini as $section => $values) {
        if (!\is_array($values) || !\preg_match('/^' . \preg_quote($name, '/') . '(\.(.+))?$/', (string) $section, $match)) {
            continue;
        }
        $blocks[$match[2] ?? ''] = [
            'main' => (string) ($values['main'] ?? ''),
            'sourceDirs' => splitList((string) ($values['source-dirs'] ?? '')),
            'backend' => isset($values['backend']) ? (string) $values['backend'] : null,
        ];
    }

    return $blocks;
}

/**
 * The list-valued keys the descriptor declares: comma-separated, trimmed, empty
 * entries dropped. Everything else is a single value, commas and all, so a
 * `description` keeps its punctuation.
 *
 * @return list<string>
 */
function splitList(string $value): array
{
    $entries = [];
    foreach (\explode(',', $value) as $entry) {
        $entry = \trim($entry);
        if ($entry !== '') {
            $entries[] = $entry;
        }
    }

    return $entries;
}
