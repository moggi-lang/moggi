<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Ast\ImportDecl;

/** @param array<string, array{assoc: string, prec: int}> ...$maps */
function mergeFixity(array ...$maps): array
{
    $merged = [];
    foreach ($maps as $map) {
        foreach ($map as $operator => $info) {
            $merged[$operator] = $info;
        }
    }

    return $merged;
}

/**
 * @param array<string, array{assoc: string, prec: int}> $fixity
 * @param ImportDecl $import
 * @return array<string, array{assoc: string, prec: int}>
 */
function fixityFromImport(array $fixity, ImportDecl $import): array
{
    if ($import->qualifiedOnly) {
        return [];
    }

    if ($import->kind === 'named' && $import->items !== []) {
        $selected = [];
        foreach ($import->items as $item) {
            $name = $item->name;
            if (isset($fixity[$name])) {
                $selected[$name] = $fixity[$name];
            }
        }

        return $selected;
    }

    if ($import->hiding !== []) {
        $hidden = array_flip($import->hiding);
        $visible = [];
        foreach ($fixity as $operator => $info) {
            if (!isset($hidden[$operator])) {
                $visible[$operator] = $info;
            }
        }

        return $visible;
    }

    return $fixity;
}

/**
 * @param list<ImportDecl> $imports
 * @param array<string, array<string, array{assoc: string, prec: int}>> $fixityByModule
 */
function importedFixityForImports(array $imports, array $fixityByModule): array
{
    $merged = [];
    foreach ($imports as $import) {
        $moduleName = Ast\moduleName($import->path);
        $moduleFixity = $fixityByModule[$moduleName] ?? [];
        $merged = mergeFixity($merged, fixityFromImport($moduleFixity, $import));
    }

    return $merged;
}

/**
 * Default fixities for known operators.
 * Local `infix*` declarations in a module override these.
 *
 * @return array{assoc: string, prec: int}|null
 */
function standardFixity(string $operator): ?array
{
    return match ($operator) {
        '>>=' => ['assoc' => 'infixl', 'prec' => 1],
        '<>' => ['assoc' => 'infixr', 'prec' => 6],
        ':' => ['assoc' => 'infixr', 'prec' => 5],
        '==', '/=', '<', '<=', '>', '>=' => ['assoc' => 'infix', 'prec' => 4],
        '<*>' => ['assoc' => 'infixl', 'prec' => 4],
        '+', '-' => ['assoc' => 'infixl', 'prec' => 6],
        '*' => ['assoc' => 'infixl', 'prec' => 7],
        '&&' => ['assoc' => 'infixr', 'prec' => 3],
        '||' => ['assoc' => 'infixr', 'prec' => 2],
        default => null,
    };
}

/**
 * @return array{assoc: string, prec: int}
 */
function fixityFor(ParserState $state, string $operator): array
{
    if (isset($state->fixity[$operator])) {
        return $state->fixity[$operator];
    }

    return standardFixity($operator) ?? ['assoc' => 'infixl', 'prec' => 9];
}
