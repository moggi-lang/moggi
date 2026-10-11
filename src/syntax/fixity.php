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
 * The fixity of an operator nothing in scope declares.
 *
 * An operator's fixity comes from the `infix*` declaration of the module that
 * declares it, reached through the imports that bring the name into scope (or
 * written in the module itself). Two names are left: `:` is the language's list
 * cons, which no import brings into scope and the inference pass resolves on its
 * own, and anything declared by a module the file does not import -- which is a
 * name error at type checking, and gets the default fixity until then.
 *
 * @return array{assoc: string, prec: int}
 */
function fixityFor(ParserState $state, string $operator): array
{
    if (isset($state->fixity[$operator])) {
        return $state->fixity[$operator];
    }

    return $operator === ':'
        ? ['assoc' => 'infixr', 'prec' => 5]
        : ['assoc' => 'infixl', 'prec' => 9];
}
