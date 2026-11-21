<?php declare(strict_types=1);

use function Moggi\Format\formatFileBatch;
use function Moggi\Format\formatImportedFixityByFile;
use function Moggi\Format\formatSource;
use function Moggi\Modules\findMogFilesUnder;

/**
 * The first library module formatting would break, or null when the whole library is safe.
 *
 * The library is the formatter's real corpus: a fixture only covers the constructs it happens to
 * contain, while these 396 modules exercise everything the stdlib writes. The sources are not kept
 * canonical in the repository — `moggi format --check libraries` reports every module as
 * would-change — so the gate cannot be that check. What must not regress is that running it is
 * *safe*: the formatter accepts each module (a refusal is a failure, not a pass), a second run is
 * byte-identical, and neither the parse tree nor the header facts (module, pragmas, backend map,
 * exports, imports, fixity) move. Every formatter bug found so far — a dropped `{-# BACKEND #-}`, a
 * lost export namespace, an emptied import list — shows up as one of those, on a module no fixture
 * covers.
 */
function firstLibraryFormatFailure(): ?string
{
    $prefix = MOGGI_PROJECT_ROOT . '/';
    $files = findMogFilesUnder(MOGGI_PROJECT_ROOT . '/libraries');
    $batch = formatFileBatch($files);
    $importedFixity = formatImportedFixityByFile($files);

    foreach ($files as $file) {
        $name = \str_starts_with($file, $prefix) ? \substr($file, \strlen($prefix)) : $file;
        $source = (string) \file_get_contents($file);
        $result = $batch['results'][$file] ?? ['formatted' => null, 'error' => "no result from the worker\n"];
        if ($result['error'] !== null) {
            return $name . ': ' . $result['error'];
        }

        $formatted = (string) $result['formatted'];

        if ($formatted === $source) {
            continue;
        }

        if (formatSource($formatted, $file, true, $importedFixity[$file] ?? []) !== $formatted) {
            return $name . ": the formatted text is not stable (formatting it again changes it)\n";
        }

        try {
            $before = normalizeFormatDump((string) \Moggi\Syntax\Ast\dump(parseLibrarySource($source, $file, $importedFixity[$file] ?? [])));
            $after = normalizeFormatDump((string) \Moggi\Syntax\Ast\dump(parseLibrarySource($formatted, $file, $importedFixity[$file] ?? [])));
        } catch (\Throwable $e) {
            return $name . ': cannot parse the formatted text: ' . $e->getMessage() . "\n";
        }

        if ($before !== $after) {
            return $name . ": the parse tree changed, so the layout rewrite is not meaning-preserving\n";
        }

        $factsBefore = libraryHeaderFacts($source, $file, $importedFixity[$file] ?? []);
        $factsAfter = libraryHeaderFacts($formatted, $file, $importedFixity[$file] ?? []);
        if ($factsBefore !== $factsAfter) {
            $moved = \implode(', ', \array_keys(\array_filter(
                $factsBefore,
                static fn (mixed $value, string $key): bool => ($factsAfter[$key] ?? null) !== $value,
                \ARRAY_FILTER_USE_BOTH,
            )));

            return $name . ': the header changed (' . $moved . ")\n";
        }
    }

    return null;
}

/**
 * A dump with generated lambda names stamped out.
 *
 * A comprehension with a pattern qualifier desugars to lambdas whose names are generated from a
 * process-wide counter, so the same source parsed twice in one process can number them differently
 * even though nothing in the tree changed. The number carries no meaning the gate can check, and
 * without this the gate would fail the first time a library module wrote `[(x, y) | (x, y) <- pairs]`.
 */
function normalizeFormatDump(string $dump): string
{
    return (string) \preg_replace('/__lam\d+/', '__lam', $dump);
}

/** The module as parsed from `$source`, with the path a diagnostic would name. */
function parseLibrarySource(string $source, string $path, array $importedFixity = []): \Moggi\Syntax\Ast\Program
{
    return \Moggi\Syntax\Parser\parse(
        \Moggi\Syntax\Lexer\lex($source, $path),
        $source,
        $path,
        $importedFixity,
    );
}

/**
 * What the header says, with positions and doc text dropped.
 *
 * A layout rewrite may move a declaration and re-indent its doc, but not change what the module is
 * called, which pragmas it carries, what it exports or imports, or which fixities it declares.
 *
 * @return array<string, mixed>
 */
function libraryHeaderFacts(string $source, string $path, array $importedFixity = []): array
{
    $program = parseLibrarySource($source, $path, $importedFixity);

    return [
        'module' => $program->module,
        'language' => \array_keys($program->language),
        'backendMap' => $program->backendMap,
        'exports' => scrubLibraryPositions($program->exports),
        'imports' => \array_map(
            static fn (object $import): mixed => scrubLibraryPositions(\get_object_vars($import)),
            $program->imports,
        ),
        'fixity' => $program->fixityDecls,
    ];
}

/**
 * The value with the fields that only say where something was written removed.
 *
 * Positions move when a layout rewrites a line, and a doc's location is not part of the fact that
 * the doc exists; everything else in the header is what the gate compares.
 */
function scrubLibraryPositions(mixed $value): mixed
{
    if (! \is_array($value)) {
        return \is_object($value) ? \get_class($value) : $value;
    }

    $scrubbed = [];
    foreach ($value as $key => $entry) {
        if (\in_array($key, ['line', 'col', 'endCol', 'endLine', 'doc', 'docIsTrailing', 'sourceFile'], true)) {
            continue;
        }
        $scrubbed[$key] = scrubLibraryPositions($entry);
    }

    return \array_is_list($value) ? \array_values($scrubbed) : $scrubbed;
}
