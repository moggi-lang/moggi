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
        $hidden = \array_flip($import->hiding);
        $visible = [];
        foreach ($fixity as $operator => $info) {
            if (! isset($hidden[$operator])) {
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
 * Fixity of the language's standard operators, used when nothing in scope declares one.
 *
 * A bare source text has no imports to resolve, so a formatter that parses it directly would
 * otherwise read every operator as left-associative at precedence 9. That turns `a == c && b == d`
 * into `((a == c) && b) == d`, which is a different program. The compiler supplies imported
 * fixities from the modules a file imports; these defaults are the same facts for the standard
 * operators, and a declaration in scope still wins over them.
 *
 * @return ?array{assoc: string, precedence: int}
 */
function standardFixity(string $operator): ?array
{
    static $fixity = null;
    $fixity ??= [
        '$' => ['assoc' => 'infixr', 'precedence' => 0],
        '=<<' => ['assoc' => 'infixr', 'precedence' => 1],
        '>=>' => ['assoc' => 'infixr', 'precedence' => 1],
        '<=<' => ['assoc' => 'infixr', 'precedence' => 1],
        '>>>' => ['assoc' => 'infixr', 'precedence' => 1],
        '<<<' => ['assoc' => 'infixr', 'precedence' => 1],
        '^<<' => ['assoc' => 'infixr', 'precedence' => 1],
        '<<^' => ['assoc' => 'infixr', 'precedence' => 1],
        '^>>' => ['assoc' => 'infixr', 'precedence' => 1],
        '>>^' => ['assoc' => 'infixr', 'precedence' => 1],
        '>>=' => ['assoc' => 'infixl', 'precedence' => 1],
        '>>' => ['assoc' => 'infixl', 'precedence' => 1],
        '&' => ['assoc' => 'infixl', 'precedence' => 1],
        '<&>' => ['assoc' => 'infixl', 'precedence' => 1],
        '||' => ['assoc' => 'infixr', 'precedence' => 2],
        '+++' => ['assoc' => 'infixr', 'precedence' => 2],
        '|||' => ['assoc' => 'infixr', 'precedence' => 2],
        '&&' => ['assoc' => 'infixr', 'precedence' => 3],
        '&&&' => ['assoc' => 'infixr', 'precedence' => 3],
        '***' => ['assoc' => 'infixr', 'precedence' => 3],
        '==' => ['assoc' => 'infix', 'precedence' => 4],
        '/=' => ['assoc' => 'infix', 'precedence' => 4],
        '<' => ['assoc' => 'infix', 'precedence' => 4],
        '<=' => ['assoc' => 'infix', 'precedence' => 4],
        '>' => ['assoc' => 'infix', 'precedence' => 4],
        '>=' => ['assoc' => 'infix', 'precedence' => 4],
        '<$>' => ['assoc' => 'infixl', 'precedence' => 4],
        '<$' => ['assoc' => 'infixl', 'precedence' => 4],
        '$>' => ['assoc' => 'infixl', 'precedence' => 4],
        '<*>' => ['assoc' => 'infixl', 'precedence' => 4],
        '<*' => ['assoc' => 'infixl', 'precedence' => 4],
        '*>' => ['assoc' => 'infixl', 'precedence' => 4],
        '<**>' => ['assoc' => 'infixl', 'precedence' => 4],
        '<|>' => ['assoc' => 'infixl', 'precedence' => 3],
        '>$<' => ['assoc' => 'infixl', 'precedence' => 4],
        '>$' => ['assoc' => 'infixl', 'precedence' => 4],
        '$<' => ['assoc' => 'infixl', 'precedence' => 4],
        '>$$<' => ['assoc' => 'infixl', 'precedence' => 4],
        '++' => ['assoc' => 'infixr', 'precedence' => 5],
        '<+>' => ['assoc' => 'infixr', 'precedence' => 5],
        '</>' => ['assoc' => 'infixr', 'precedence' => 5],
        '<|' => ['assoc' => 'infixr', 'precedence' => 5],
        '>*<' => ['assoc' => 'infixr', 'precedence' => 5],
        '\\\\' => ['assoc' => 'infix', 'precedence' => 5],
        '+' => ['assoc' => 'infixl', 'precedence' => 6],
        '-' => ['assoc' => 'infixl', 'precedence' => 6],
        '<>' => ['assoc' => 'infixr', 'precedence' => 6],
        '<.>' => ['assoc' => 'infixr', 'precedence' => 7],
        '-<.>' => ['assoc' => 'infixr', 'precedence' => 7],
        '*' => ['assoc' => 'infixl', 'precedence' => 7],
        '/' => ['assoc' => 'infixl', 'precedence' => 7],
        '%' => ['assoc' => 'infixl', 'precedence' => 7],
        '.&.' => ['assoc' => 'infixl', 'precedence' => 7],
        'quot' => ['assoc' => 'infixl', 'precedence' => 7],
        'rem' => ['assoc' => 'infixl', 'precedence' => 7],
        'div' => ['assoc' => 'infixl', 'precedence' => 7],
        'mod' => ['assoc' => 'infixl', 'precedence' => 7],
        '**' => ['assoc' => 'infixr', 'precedence' => 8],
        '^' => ['assoc' => 'infixr', 'precedence' => 8],
        '^^' => ['assoc' => 'infixr', 'precedence' => 8],
        '.>>.' => ['assoc' => 'infixl', 'precedence' => 8],
        '.<<.' => ['assoc' => 'infixl', 'precedence' => 8],
        '!>>.' => ['assoc' => 'infixl', 'precedence' => 8],
        '!<<.' => ['assoc' => 'infixl', 'precedence' => 8],
        'shift' => ['assoc' => 'infixl', 'precedence' => 8],
        'rotate' => ['assoc' => 'infixl', 'precedence' => 8],
        'shiftL' => ['assoc' => 'infixl', 'precedence' => 8],
        'shiftR' => ['assoc' => 'infixl', 'precedence' => 8],
        'rotateL' => ['assoc' => 'infixl', 'precedence' => 8],
        'rotateR' => ['assoc' => 'infixl', 'precedence' => 8],
        '.' => ['assoc' => 'infixr', 'precedence' => 9],
        '!' => ['assoc' => 'infixl', 'precedence' => 9],
        '!!' => ['assoc' => 'infixl', 'precedence' => 9],
        '!?' => ['assoc' => 'infixl', 'precedence' => 9],
        'on' => ['assoc' => 'infixl', 'precedence' => 0],
        'xor' => ['assoc' => 'infixl', 'precedence' => 6],
        '.^.' => ['assoc' => 'infixl', 'precedence' => 6],
        '.|.' => ['assoc' => 'infixl', 'precedence' => 5],
    ];

    return $fixity[$operator] ?? null;
}

/**
 * The fixity of an operator nothing in scope declares.
 *
 * An operator's fixity comes from the `infix*` declaration of the module that
 * declares it, reached through the imports that bring the name into scope (or
 * written in the module itself). Two names are left after the standard table:
 * `:` is the language's list cons, which no import brings into scope and the
 * inference pass resolves on its own, and anything declared by a module the
 * file does not import -- which is a name error at type checking, and gets the
 * default fixity until then.
 *
 * @return array{assoc: string, prec: int}
 */
function fixityFor(ParserState $state, string $operator): array
{
    if (isset($state->fixity[$operator])) {
        return $state->fixity[$operator];
    }

    $standard = standardFixity($operator);
    if ($standard !== null) {
        return ['assoc' => $standard['assoc'], 'precedence' => $standard['precedence']];
    }

    return $operator === ':'
        ? ['assoc' => 'infixr', 'precedence' => 5]
        : ['assoc' => 'infixl', 'precedence' => 9];
}
