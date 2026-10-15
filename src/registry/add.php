<?php declare(strict_types=1);

namespace Moggi\Registry;

use function Moggi\Backend\implementedBackendIds;

/**
 * `moggi add` — the descriptor edit, as text in and text out.
 *
 * The attractive half of Sky's `sky add <go module>` is the verb: one command
 * that fetches, inspects, generates and records, with nothing hand-typed. Of the
 * four steps, three already exist here — a dependency of ours is resolved by the
 * registry and a host coordinate by `resolveHostTools()` — and this file is the
 * one that did not: *records*. Writing one requirement into the table it belongs
 * to is the whole of it, and it needs no host toolchain and no new resolution
 * path, which is why it comes before any binding generator.
 *
 * **A bare name is a package of ours; a prefix is somebody else's ecosystem.**
 * `moggi add json` writes `[dependencies] json = …` — the registry we already
 * resolve and lock — and `moggi add jvm:com.google.code.gson:gson:2.11.0` writes
 * `[jvm.maven]`. So the verb is one, the table is decided by the spelling, and
 * there is no guessing: a name that is not a package name is refused with the
 * prefix it was probably missing, because with four tables a silent guess is the
 * expensive kind of convenience. `moggi:` is accepted as the explicit spelling of
 * the bare case, for a script that would rather say it.
 *
 * A host coordinate belongs to exactly one backend — a Maven artifact has no PHP
 * or .NET meaning — so the host half is per-backend by construction, and that is
 * the whole of the multi-backend answer. There is nothing to marry: the portable
 * half of a three-backend library is a facade
 * (`{-# BACKEND php = … jvm = … dotnet = … #-}`), hand-written, because the three
 * ecosystems' packages are three different packages. Three `add`s for one feature
 * is the expected shape, not a failure.
 *
 * The descriptor format is closed (`src/registry/descriptor.php`), so two rules
 * follow from it:
 *
 * 1. **The writer follows the descriptor's own shape.** `[dependencies]` is one
 *    key per package, `[jvm.maven]` and `[dotnet.nuget]` likewise, while
 *    `[php] composer` is one comma-separated list (`composer` is a list,
 *    `[php.extensions]` is a table). The spellings written here are the ones
 *    `readDescriptor()` and `hostToolCoordinates()` read back, so a written
 *    descriptor resolves immediately and re-reading it reports `present`.
 * 2. **The edit is line-based.** A descriptor is hand-maintained and in version
 *    control: comments, key order and the `[package]` block survive untouched,
 *    because a canonical re-render would destroy all three. Only the one line the
 *    requirement needs is rewritten; a requirement already declared identically
 *    leaves the file byte-identical. `descriptorAdditionProblems()` runs the
 *    candidate through `descriptorProblems()` before anything is written, so the
 *    one command that writes a descriptor cannot write one the parser refuses.
 */

/**
 * Every field a parsed requirement carries, whichever table it goes in.
 *
 * `kind` is which world it came from (`registry` — a package of ours — or `host`),
 * `package` is the part a key or a list entry is judged by (the name, the
 * `vendor/package` for Composer, the `group:artifact` for Maven, the id for NuGet),
 * `entry` is the requirement as the ecosystem spells it, and `requires` is what
 * "already declared" compares — so an existing line is judged by its requirement
 * rather than by its whitespace. `deferred` marks the one thing a parser cannot
 * know: a registry dependency added without a constraint, whose constraint the
 * registry decides (`withAddConstraint()` fills it in).
 *
 * @return array{kind: string, ecosystem: string, package: string, coordinate: string, section: string, key: string, value: string, entry: string, requires: string, list: bool, deferred: bool}
 */
function addRequirement(string $ecosystem, string $package, string $coordinate, string $value, string $entry, string $requires, bool $deferred = false): array
{
    $table = ADD_ECOSYSTEMS[$ecosystem];

    return [
        'kind' => $ecosystem === 'moggi' ? 'registry' : 'host',
        'ecosystem' => $ecosystem,
        'package' => $package,
        'coordinate' => $coordinate,
        'section' => $table['section'],
        'key' => $table['key'] ?? $package,
        'value' => $value,
        'entry' => $entry,
        'requires' => $requires,
        'list' => $table['list'],
        'deferred' => $deferred,
    ];
}

/**
 * The descriptor table each ecosystem's requirements live in.
 *
 * `moggi` is the registry: `[dependencies]`, the table `moggi install` resolves and
 * locks. `php` is the odd host one — Composer requirements are one comma-separated
 * list under `[php] composer`, while a Maven or NuGet coordinate is its own key.
 * That is the descriptor's shape, not this file's invention —
 * `readRepeatableSection()`/`requirementSections()` read the one,
 * `backendTableCoordinates()` the other.
 */
const ADD_ECOSYSTEMS = [
    'moggi' => ['section' => 'dependencies', 'list' => false],
    'php' => ['section' => 'php', 'key' => 'composer', 'list' => true],
    'jvm' => ['section' => 'jvm.maven', 'list' => false],
    'dotnet' => ['section' => 'dotnet.nuget', 'list' => false],
];

/**
 * Read one `moggi add` argument as the descriptor edit it means.
 *
 * The spelling decides the table, and nothing else does: a `php:`/`jvm:`/`dotnet:`
 * prefix is a host package in that ecosystem's vocabulary, everything else is a
 * package of ours (`moggi:` says so explicitly). `$version` is what `--version`
 * said — a constraint for a package of ours, a version for a host coordinate whose
 * ecosystem lets it be left out (`jvm:group:artifact`, `dotnet:Id`). Two versions
 * in one command (the coordinate's and `--version`) is a typo rather than an
 * override, so it is refused.
 *
 * @return array{kind: string, ecosystem: string, package: string, coordinate: string, section: string, key: string, value: string, entry: string, requires: string, list: bool, deferred: bool}
 */
function parseAddCoordinate(string $argument, ?string $version = null): array
{
    $argument = \trim($argument);
    if ($argument === '') {
        throw new \RuntimeException('add needs a name or a `backend:coordinate`, not an empty argument');
    }

    $version = $version === null ? null : \trim($version);
    $version = $version === '' ? null : $version;

    $prefix = null;
    $rest = $argument;
    if (\preg_match('/^([A-Za-z]+):(.*)$/', $argument, $match) === 1
        && \in_array($match[1], ['moggi', ...implementedBackendIds()], true)) {
        $prefix = $match[1];
        $rest = $match[2];
    }
    if ($rest === '') {
        throw new \RuntimeException("`{$argument}` names nothing after the `{$prefix}:` prefix");
    }

    if ($prefix === null || $prefix === 'moggi') {
        return parseRegistryRequirement($rest, $version);
    }

    if ($version !== null) {
        $problem = addValueProblem($version, 'the version');
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
    }

    return match ($prefix) {
        'php' => parsePhpCoordinate($rest, $version),
        'jvm' => parseJvmCoordinate($rest, $version),
        default => parseDotnetCoordinate($rest, $version),
    };
}

/**
 * A package of ours: `[dependencies]` is `name = constraint`, and the constraint
 * may be left to the registry.
 *
 * A name that is not a package name is refused with the hint a host package needs,
 * because `add monolog/monolog` is the mistake this spelling invites: a `/` (or a
 * second colon) cannot be a name here, and the fix is three characters. A name with
 * neither carries no ecosystem to read one from — a NuGet id (`Newtonsoft.Json`) is
 * that case — so the refusal states the rule instead of guessing a table from the
 * shape: a bare name is a package of ours, and a host package is spelled with its
 * ecosystem.
 */
function parseRegistryRequirement(string $name, ?string $constraint): array
{
    $problem = packageNameProblem($name);
    if ($problem !== null) {
        $hint = ' — a bare name is a package of ours; a host package is spelled with its ecosystem: `php:vendor/package`, `jvm:group:artifact` or `dotnet:Id`';
        if (\str_contains($name, '/')) {
            $hint = " — for a Composer package say `php:{$name}`";
        } elseif (\str_contains($name, ':')) {
            $hint = " — for a host package say `jvm:{$name}`, `php:{$name}` or `dotnet:{$name}`";
        }

        throw new \RuntimeException($problem . $hint);
    }

    if ($constraint !== null) {
        $constraintProblem = constraintProblem($constraint);
        if ($constraintProblem !== null) {
            throw new \RuntimeException("`--version {$constraint}`: {$constraintProblem}");
        }
    }

    return addRequirement(
        'moggi',
        $name,
        $constraint === null ? $name : "{$name} = {$constraint}",
        $constraint ?? '',
        $constraint ?? '',
        $constraint ?? '',
        $constraint === null,
    );
}

/**
 * The same requirement with the constraint the registry resolved to.
 *
 * The one edit a parser cannot finish: `moggi add json` names no version, and what
 * `[dependencies] json = …` should say is the newest release's line — which is a
 * question only the registry can answer, so the command asks it and fills the
 * answer in here.
 *
 * @param array<string, mixed> $edit
 * @return array<string, mixed>
 */
function withAddConstraint(array $edit, string $constraint): array
{
    $name = (string) $edit['package'];

    return [
        ...$edit,
        'coordinate' => "{$name} = {$constraint}",
        'value' => $constraint,
        'entry' => $constraint,
        'requires' => $constraint,
        'deferred' => false,
    ];
}

/** `vendor/package[:constraint]` — Composer's own spelling. */
function parsePhpCoordinate(string $rest, ?string $version): array
{
    if (\preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)(?::(.*))?$#', $rest, $match) !== 1) {
        throw new \RuntimeException("`{$rest}` is not a Composer package (`vendor/package[:constraint]`)");
    }

    $package = $match[1] . '/' . $match[2];
    $declared = \trim($match[3] ?? '');
    if ($declared !== '' && $version !== null) {
        throw new \RuntimeException("the coordinate already asks for `{$declared}` — drop --version");
    }
    $constraint = $declared !== '' ? $declared : $version;
    if ($constraint !== null) {
        $problem = addValueProblem($constraint, 'the constraint');
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
    }

    $entry = $constraint === null ? $package : $package . ':' . $constraint;

    return addRequirement('php', $package, $entry, $constraint ?? '', $entry, $constraint ?? '*');
}

/** `group:artifact[:version]` — and `mvn` needs the version, so a missing one is refused. */
function parseJvmCoordinate(string $rest, ?string $version): array
{
    $parts = \explode(':', $rest);
    if (\count($parts) !== 2 && \count($parts) !== 3) {
        throw new \RuntimeException("`{$rest}` is not a Maven coordinate (`group:artifact[:version]`)");
    }

    foreach ([[$parts[0], 'the groupId'], [$parts[1], 'the artifactId']] as [$part, $what]) {
        $problem = addNameProblem(\trim($part), $what);
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
    }

    $package = \trim($parts[0]) . ':' . \trim($parts[1]);
    $declared = \count($parts) === 3 ? \trim($parts[2]) : '';
    if ($declared !== '' && $version !== null) {
        throw new \RuntimeException("the coordinate already asks for `{$declared}` — drop --version");
    }
    $resolved = $declared !== '' ? $declared : $version;
    if ($resolved === null || $resolved === '') {
        throw new \RuntimeException(
            "`{$rest}` names no version — write `group:artifact:version`, or say `--version VERSION`: mvn wants one",
        );
    }
    $problem = addValueProblem($resolved, 'the version');
    if ($problem !== null) {
        throw new \RuntimeException($problem);
    }

    $entry = $package . ':' . $resolved;

    return addRequirement('jvm', $package, $entry, $resolved, $entry, $resolved);
}

/** `id[:version]` — a bare id floats, because NuGet has `*` for exactly that. */
function parseDotnetCoordinate(string $rest, ?string $version): array
{
    $parts = \explode(':', $rest);
    if (\count($parts) > 2) {
        throw new \RuntimeException("`{$rest}` is not a NuGet package (`id[:version]`)");
    }

    $id = \trim($parts[0]);
    $problem = addNameProblem($id, 'the package id');
    if ($problem !== null) {
        throw new \RuntimeException($problem);
    }
    if (\count($parts) === 2 && \trim($parts[1]) === '') {
        throw new \RuntimeException("`{$rest}` says nothing after the colon — write `{$id}` for any version, or `{$id}:VERSION`");
    }

    $declared = \count($parts) === 2 ? \trim($parts[1]) : '';
    if ($declared !== '' && $version !== null) {
        throw new \RuntimeException("the coordinate already asks for `{$declared}` — drop --version");
    }
    $resolved = $declared !== '' ? $declared : ($version ?? '*');
    $problem = addValueProblem($resolved, 'the version');
    if ($problem !== null) {
        throw new \RuntimeException($problem);
    }

    $entry = $resolved === '*' ? $id : $id . ':' . $resolved;

    return addRequirement('dotnet', $id, $entry, $resolved, $entry, $resolved);
}

/** Whether an ecosystem's identifier is one — a name an ecosystem can carry, not a shell word. */
function addNameProblem(string $part, string $what): ?string
{
    if ($part === '' || \preg_match('/^[A-Za-z0-9_.-]+$/', $part) !== 1) {
        return "`{$part}` is not {$what} (letters, digits, `.`, `-` and `_`)";
    }

    return null;
}

/**
 * Whether a version or constraint can be written to a descriptor at all.
 *
 * `,` separates the entries of a list value, `;` and `#` start a comment, and a
 * newline ends the line — so a value carrying one of them could not be read back
 * as what it meant. Spaces are legal and common (`>=1.0 <2.0`), so they are not
 * refused.
 */
function addValueProblem(string $value, string $what): ?string
{
    if (\preg_match('/[,;#\r\n\t]/', $value) === 1) {
        return "{$what} `{$value}` cannot be written to a descriptor: a value carries no `,`, `;`, `#` or newline";
    }

    return null;
}

/**
 * The descriptor text with one coordinate written into the table its backend owns.
 *
 * @param array<string, mixed> $edit an edit from `parseAddCoordinate`
 * @return array{text: string, section: string, status: string, sectionCreated: bool, previous: ?string, line: ?string, detail: string}
 *   `status` is `added` (the coordinate was written), `present` (it was already
 *   declared with the same requirement, so the text is unchanged) or `conflict`
 *   (the same package is declared with another requirement; the text is unchanged
 *   and `detail` is the sentence the caller refuses with).
 */
function descriptorWithCoordinate(string $text, array $edit, string $descriptorPath = ''): array
{
    $newline = \str_contains($text, "\r\n") ? "\r\n" : "\n";
    $lines = \explode($newline, $text);
    if ($lines !== [] && \end($lines) === '') {
        \array_pop($lines);
    }

    $section = (string) $edit['section'];
    $at = descriptorSectionLine($lines, $section);
    $sectionCreated = false;
    if ($at === null) {
        if ($lines !== [] && \end($lines) !== '') {
            $lines[] = '';
        }
        $lines[] = '[' . $section . ']';
        $at = \count($lines) - 1;
        $sectionCreated = true;
    }

    $end = descriptorSectionEnd($lines, $at);
    $found = descriptorEntryLine($lines, $at, $end, $edit);

    $previous = $found === null ? null : $lines[$found];
    $line = null;
    $status = 'added';
    $detail = '';

    if ($found !== null) {
        $verdict = descriptorEntryVerdict((string) $previous, $edit, $descriptorPath);
        $status = $verdict['status'];
        $detail = $verdict['detail'];
        $line = $verdict['line'] ?? $previous;
        if ($status === 'added') {
            $lines[$found] = (string) $line;
        }
    } else {
        $written = ($edit['list'] ?? false) === true ? (string) $edit['entry'] : (string) $edit['value'];
        $indent = descriptorSectionIndent($lines, $at, $end);
        $line = $indent . (string) $edit['key'] . ' = ' . $written;
        \array_splice($lines, descriptorSectionInsertLine($lines, $at, $end), 0, [(string) $line]);
    }

    return [
        'text' => \implode($newline, $lines) . $newline,
        'section' => $section,
        'status' => $status,
        'sectionCreated' => $sectionCreated,
        'previous' => $previous,
        'line' => $line,
        'detail' => $detail,
    ];
}

/** The line of the `[section]` header, or null when the descriptor has none. */
function descriptorSectionLine(array $lines, string $section): ?int
{
    foreach ($lines as $index => $line) {
        $trimmed = \trim((string) $line);
        if (\preg_match('/^\[([^\]]+)\]\s*(?:[;#].*)?$/', $trimmed, $match) === 1 && \trim($match[1]) === $section) {
            return (int) $index;
        }
    }

    return null;
}

/** The line that ends a section's body: the next header, or the end of the file. */
function descriptorSectionEnd(array $lines, int $sectionLine): int
{
    for ($index = $sectionLine + 1, $count = \count($lines); $index < $count; ++$index) {
        $trimmed = \trim((string) $lines[$index]);
        if ($trimmed !== '' && $trimmed[0] === '[' && \preg_match('/^\[[^\]]+\]/', $trimmed) === 1) {
            return $index;
        }
    }

    return \count($lines);
}

/**
 * Where a new key belongs: with its table, under the last key the section already
 * has, rather than after the blank line in front of the next header.
 */
function descriptorSectionInsertLine(array $lines, int $sectionLine, int $end): int
{
    $insert = $sectionLine + 1;
    for ($index = $sectionLine + 1; $index < $end; ++$index) {
        if (\trim((string) $lines[$index]) !== '') {
            $insert = $index + 1;
        }
    }

    return $insert;
}

/** The indentation the section's own keys use, so a new key looks like its neighbours. */
function descriptorSectionIndent(array $lines, int $sectionLine, int $end): string
{
    for ($index = $sectionLine + 1; $index < $end; ++$index) {
        if (\preg_match('/^(\s*)\S/', (string) $lines[$index], $match) === 1) {
            return $match[1];
        }
    }

    return '';
}

/** The line in the section that declares this coordinate's package, or null when none does. */
function descriptorEntryLine(array $lines, int $sectionLine, int $end, array $edit): ?int
{
    $pattern = '/^\s*' . \preg_quote((string) $edit['key'], '/') . '\s*=/';
    for ($index = $sectionLine + 1; $index < $end; ++$index) {
        if (\preg_match($pattern, (string) $lines[$index]) === 1) {
            return $index;
        }
    }

    return null;
}

/**
 * What a line that already declares the package says: it satisfies this
 * coordinate (`present`), it asks for something else (`conflict`), or it is a list
 * this entry is appended to (`added`).
 *
 * @param array<string, mixed> $edit
 * @return array{status: string, line: ?string, detail: string}
 */
function descriptorEntryVerdict(string $line, array $edit, string $descriptorPath = ''): array
{
    $equals = \strpos($line, '=');
    $value = $equals === false ? '' : \substr($line, $equals + 1);
    $comment = '';
    $cut = null;
    foreach ([';', '#'] as $mark) {
        $at = \strpos($value, $mark);
        if ($at !== false && ($cut === null || $at < $cut)) {
            $cut = $at;
        }
    }
    if ($cut !== null) {
        $comment = \ltrim(\substr($value, $cut));
        $value = \substr($value, 0, $cut);
    }

    if (($edit['list'] ?? false) === true) {
        foreach (splitList($value) as $entry) {
            [$package, $constraint] = hostToolSplitCoordinate($entry, (string) $edit['ecosystem']);
            if ($package !== $edit['package']) {
                continue;
            }
            if ($constraint === $edit['requires']) {
                return ['status' => 'present', 'line' => $line, 'detail' => ''];
            }

            return [
                'status' => 'conflict',
                'line' => $line,
                'detail' => "`{$edit['package']}` is already declared as `{$entry}` in [{$edit['section']}]"
                    . ($descriptorPath === '' ? '' : " of {$descriptorPath}"),
            ];
        }

        $entries = splitList($value);
        $entries[] = (string) $edit['entry'];
        $head = $equals === false ? (string) $edit['key'] . ' =' : \substr($line, 0, $equals + 1);

        return [
            'status' => 'added',
            'line' => $head . ' ' . \implode(', ', $entries) . ($comment === '' ? '' : ' ' . $comment),
            'detail' => '',
        ];
    }

    if (\trim($value) === (string) $edit['value']) {
        return ['status' => 'present', 'line' => $line, 'detail' => ''];
    }

    return [
        'status' => 'conflict',
        'line' => $line,
        'detail' => "`{$edit['key']}` is already declared as `" . \trim($value) . "` in [{$edit['section']}]"
            . ($descriptorPath === '' ? '' : " of {$descriptorPath}"),
    ];
}

/**
 * Record one `[ffi] bindings` entry (`backend:package`) for a bound host
 * package: create the section or key when missing, append to the list
 * otherwise, and report `present` when the exact entry is already there.
 *
 * @return array{text: string, section: string, status: string, sectionCreated: bool, previous: ?string, line: ?string, detail: string}
 */
function descriptorWithFfiBinding(string $text, string $entry): array
{
    $newline = \str_contains($text, "\r\n") ? "\r\n" : "\n";
    $lines = \explode($newline, $text);
    if ($lines !== [] && \end($lines) === '') {
        \array_pop($lines);
    }

    $at = descriptorSectionLine($lines, 'ffi');
    $sectionCreated = false;
    if ($at === null) {
        if ($lines !== [] && \end($lines) !== '') {
            $lines[] = '';
        }
        $lines[] = '[ffi]';
        $at = \count($lines) - 1;
        $sectionCreated = true;
    }

    $end = descriptorSectionEnd($lines, $at);
    $found = null;
    $pattern = '/^\\s*bindings\\s*=/';
    for ($index = $at + 1; $index < $end; ++$index) {
        if (\preg_match($pattern, (string) $lines[$index]) === 1) {
            $found = $index;

            break;
        }
    }

    if ($found === null) {
        $indent = descriptorSectionIndent($lines, $at, $end);
        $line = $indent . 'bindings = ' . $entry;
        \array_splice($lines, descriptorSectionInsertLine($lines, $at, $end), 0, [$line]);

        return [
            'text' => \implode($newline, $lines) . $newline,
            'section' => 'ffi',
            'status' => 'added',
            'sectionCreated' => $sectionCreated,
            'previous' => null,
            'line' => $line,
            'detail' => '',
        ];
    }

    $line = (string) $lines[$found];
    $equals = \strpos($line, '=');
    $value = $equals === false ? '' : \substr($line, $equals + 1);
    $comment = '';
    $cut = null;
    foreach ([';', '#'] as $mark) {
        $position = \strpos($value, $mark);
        if ($position !== false && ($cut === null || $position < $cut)) {
            $cut = $position;
        }
    }
    if ($cut !== null) {
        $comment = \ltrim(\substr($value, $cut));
        $value = \substr($value, 0, $cut);
    }

    foreach (splitList($value) as $existing) {
        if ($existing === $entry) {
            return [
                'text' => \implode($newline, $lines) . $newline,
                'section' => 'ffi',
                'status' => 'present',
                'sectionCreated' => $sectionCreated,
                'previous' => $line,
                'line' => $line,
                'detail' => '',
            ];
        }
    }

    $entries = splitList($value);
    $entries[] = $entry;
    $head = $equals === false ? 'bindings =' : \substr($line, 0, $equals + 1);
    $line = $head . ' ' . \implode(', ', $entries) . ($comment === '' ? '' : ' ' . $comment);
    $lines[$found] = $line;

    return [
        'text' => \implode($newline, $lines) . $newline,
        'section' => 'ffi',
        'status' => 'added',
        'sectionCreated' => $sectionCreated,
        'previous' => null,
        'line' => $line,
        'detail' => '',
    ];
}

/**
 * Every problem with a descriptor edit's candidate text, in the parser's own
 * words.
 *
 * The candidate is checked *as a descriptor* — through `descriptorProblems()`, in a
 * scratch directory named after the package, because the format fixes a
 * descriptor's filename too — so the writer can only ever save a file the reader
 * accepts. A writer that could produce something `readDescriptor()` refuses would
 * turn `add` into a way to break a project with one command.
 *
 * @return list<string>
 */
function descriptorAdditionProblems(string $text, string $descriptorPath): array
{
    $name = \basename($descriptorPath);
    $directory = \sys_get_temp_dir() . '/moggi-add-' . \bin2hex(\random_bytes(8));
    if (! @\mkdir($directory, 0700, true)) {
        return ["cannot check the edited descriptor: {$directory} could not be created"];
    }

    $candidate = $directory . '/' . $name;
    $written = @\file_put_contents($candidate, $text);

    try {
        if ($written === false) {
            return ["cannot check the edited descriptor: {$candidate} could not be written"];
        }

        return descriptorProblems($candidate);
    } finally {
        @\unlink($candidate);
        @\rmdir($directory);
    }
}
