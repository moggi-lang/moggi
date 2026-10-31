<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeError;

use function Moggi\Syntax\Ast\markSourceFile;

/**
 * The half a backend map selects, or null when it has no entry for that backend.
 *
 * A missing entry is a deliberate omission (a host binding that exists on one
 * platform only), so the caller raises the located refusal rather than a
 * runtime error.
 *
 * @param array<string, string> $backendMap
 */
function resolveImplModuleName(array $backendMap, string $backendId): ?string
{
    return $backendMap[$backendId] ?? null;
}

/** @param array<string, string> $backendMap @param array<string, mixed> $unit */
function facadeMissingBackendError(
    string $moduleName,
    array $backendMap,
    string $backendId,
    array $unit,
): TypeError {
    $span = $unit['headerSpan'] ?? null;

    return new TypeError(
        "module `{$moduleName}` has no `{$backendId}` implementation (it implements: "
            . \implode(', ', \array_keys($backendMap)) . ')',
        (string) ($unit['path'] ?? ''),
        (string) ($unit['source'] ?? ''),
        \is_array($span) ? (int) ($span['line'] ?? 0) : 0,
        \is_array($span) ? (int) ($span['col'] ?? 0) : 0,
        \is_array($span) ? (int) ($span['endCol'] ?? 0) : 0,
    );
}

/**
 * The facade each backend half belongs to, and the backend it implements.
 *
 * A half is the `php`/`jvm`/`dotnet` half of its facade, merged into it, so it is
 * not a module a program may name: only the `{-# BACKEND … #-}` pragma that
 * selects it may mention it.
 *
 * @param array<string, string> $byModule module name => source path
 * @return array<string, array{facade: string, backend: string}> half module => owner
 */
function backendImplOwners(array $byModule): array
{
    $owners = [];
    foreach ($byModule as $facadeName => $facadePath) {
        if (isSyntheticCompilerPath($facadePath) || ! \is_file($facadePath)) {
            continue;
        }

        foreach (cachedModuleHeader($facadePath)['backendMap'] ?? [] as $backend => $impl) {
            $owners[$impl] ??= ['facade' => $facadeName, 'backend' => $backend];
        }
    }

    return $owners;
}

/** @param array{facade: string, backend: string} $owner */
function backendImplImportError(
    string $implName,
    array $owner,
    string $path,
    string $source,
    Ast\ImportDecl $import,
): TypeError {
    $error = new TypeError(
        "module `{$implName}` is the `{$owner['backend']}` implementation of `{$owner['facade']}`: "
            . "it is merged into that module, so import `{$owner['facade']}` instead",
        $path,
        $source,
        $import->line,
        $import->col,
        $import->endCol,
    );
    $error->diagnosticCode = 'import/backend-impl';

    return $error;
}

/**
 * Reads one compilation unit out of a facade program and its half: the facade's
 * items in the facade's order, each implemented by the half where the half binds
 * the name, then the half's own items (its private helpers, its instances).
 *
 * A name the half binds is the half's implementation, whether the facade stated
 * only a signature or also a portable default; the facade's declaration stays,
 * so the unit still publishes the interface `-- |` docs and signatures describe.
 */
function mergeBackendHalfProgram(
    Ast\Program $facade,
    Ast\Program $half,
    string $halfName,
    string $halfPath,
): Ast\Program {
    $facadeName = (string) $facade->module;
    markHalfSourceFiles($half, $halfPath);

    $halfValues = [];
    $halfTypes = [];
    foreach ($half->items as $item) {
        if ($item instanceof Ast\FunctionDecl || $item instanceof Ast\ForeignImportDecl) {
            $halfValues[$item->name] ??= $item;

            continue;
        }
        if (
            $item instanceof Ast\DataDecl
            || $item instanceof Ast\TypeSynonymDecl
            || $item instanceof Ast\ForeignTypeDecl
        ) {
            $halfTypes[$item->name] ??= true;
        }
    }

    $items = [];
    $absorbed = [];
    foreach ($facade->items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $halfItem = $halfValues[$item->name] ?? null;
            if ($halfItem === null) {
                $items[] = $item;

                continue;
            }

            $absorbed[$item->name] = true;
            if ($halfItem instanceof Ast\ForeignImportDecl) {
                $halfItem->doc ??= $item->doc;
                $items[] = $halfItem;

                continue;
            }

            $item->params = $halfItem->params;
            $item->body = $halfItem->body;
            $item->foreign = $halfItem->foreign;
            $item->intrinsicWrapper = $halfItem->intrinsicWrapper;
            $item->actionConvention = $halfItem->actionConvention;
            $item->signatureOnly = false;
            $items[] = $item;

            continue;
        }

        if (
            ($item instanceof Ast\DataDecl
                || $item instanceof Ast\TypeSynonymDecl
                || $item instanceof Ast\ForeignTypeDecl)
            && isset($halfTypes[$item->name])
        ) {
            throw facadeHalfCollision($facadeName, $halfName, $halfPath, $item->name, $item);
        }

        $items[] = $item;
    }

    foreach ($half->items as $item) {
        if (
            ($item instanceof Ast\FunctionDecl || $item instanceof Ast\ForeignImportDecl)
            && isset($absorbed[$item->name])
        ) {
            continue;
        }

        $items[] = $item;
    }

    return new Ast\Program(
        items: $items,
        module: $facade->module,
        imports: $facade->imports,
        exports: $facade->exports,
        constructorRenames: $facade->constructorRenames,
        backendMap: $facade->backendMap,
        implicitMain: $facade->implicitMain,
        language: $facade->language,
        moduleDoc: $facade->moduleDoc,
        fixityDocs: $facade->fixityDocs,
        line: $facade->line,
        col: $facade->col,
        endCol: $facade->endCol,
    );
}

/**
 * The files a unit is written in: its own, and the half merged into it.
 *
 * A route that re-prepares from source paths has to hand back both, or the unit
 * arrives without the half its pragma names.
 *
 * @param array<string, mixed> $unit
 * @return list<string>
 */
function unitSourceFiles(array $unit): array
{
    $files = [];
    foreach (['path', 'halfPath'] as $field) {
        $file = $unit[$field] ?? null;
        if (\is_string($file) && $file !== '') {
            $files[] = $file;
        }
    }

    return $files;
}

/**
 * Name the file every node of a half is written in, so a diagnostic or a frame
 * lands in the half rather than in the unit's own file — and so the site a record
 * publishes for a declaration the half owns is the half's own file.
 */
function markHalfSourceFiles(Ast\Program $half, string $path): void
{
    foreach ($half->items as $item) {
        if ($item instanceof Ast\AstWalkable) {
            markSourceFile($item, $path);
        }
    }
}

/**
 * A name the facade declares as a type and its half declares too: a half
 * supplies bindings and its own host types, not a second shape for one name.
 */
function facadeHalfCollision(
    string $facadeName,
    string $halfName,
    string $halfPath,
    string $name,
    Ast\AstNode $at,
): TypeError {
    return new TypeError(
        "`{$name}` is declared by `{$facadeName}` and by its `{$halfName}` half:"
            . ' a half binds what the facade declares, so the type has to live on one side',
        $halfPath,
        '',
        $at->line,
        $at->col,
        $at->endCol,
    );
}

/**
 * The imports one unit has: the half's, then the facade's.
 *
 * The facade owns the namespace a program imports, so its imports come last and
 * win where the two lists disagree — a half that means another module's `map`
 * has to say so. A name only the half imports stays in scope for both sides.
 *
 * @param list<Ast\ImportDecl> $facadeImports
 * @param list<Ast\ImportDecl> $halfImports
 * @return list<Ast\ImportDecl>
 */
function mergeUnitImports(array $facadeImports, array $halfImports): array
{
    $merged = [];
    $seen = [];
    foreach ([...$halfImports, ...$facadeImports] as $import) {
        $key = importIdentity($import);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[] = $import;
    }

    return $merged;
}

/** Module plus the spec it was written with, so an identical import is not listed twice. */
function importIdentity(Ast\ImportDecl $import): string
{
    $item = static fn (mixed $entry): string => \is_object($entry) && isset($entry->name)
        ? (string) $entry->name . '(' . \implode(',', (array) ($entry->children ?? [])) . ')'
        : (\is_string($entry) ? $entry : '');

    return \implode('.', $import->path)
        . '|' . $import->kind
        . '|' . \implode(',', \array_map($item, $import->items))
        . '|' . \implode(',', \array_map($item, $import->hiding))
        . '|' . ($import->asName ?? '')
        . '|' . ($import->qualifiedOnly ? 'q' : '');
}
