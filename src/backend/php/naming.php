<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Naming;

use Moggi\IR\Module;

use function Moggi\Optimize\Support\isLambdaName;

/** @return array<string, true> */
function phpKeywords(): array
{
    static $keywords = null;
    if ($keywords === null) {
        $keywords = \array_fill_keys([
            '__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
            'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else',
            'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile',
            'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global',
            'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface',
            'isset', 'list', 'match', 'namespace', 'new', 'or', 'parent', 'print', 'private', 'protected',
            'public', 'readonly', 'require', 'require_once', 'return', 'self', 'static', 'switch', 'throw',
            'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        ], true);
    }

    return $keywords;
}

function isPhpKeyword(string $name): bool
{
    return isset(phpKeywords()[strtolower($name)]);
}

function isPhpBuiltin(string $name): bool
{
    static $builtins = [
        'abs' => true,
    ];

    return isset($builtins[strtolower($name)]);
}

/**
 * PHP function name for every binding a module emits.
 *
 * PHP resolves function names case-insensitively, so a module declaring both
 * `UserError` and `userError` cannot emit both names verbatim and one of them is
 * renamed. Bindings and constructors are therefore allocated in two passes:
 * functions and instance evidence keep their base name unconditionally, and a
 * constructor only takes a suffix when its base name is taken.
 *
 * That ordering is what makes cross-module references safe. Referring modules
 * know a function's emitted name from its Moggi name alone (no shared table, no
 * artifact metadata, no dependence on whether the defining module was compiled
 * in this process); constructors are never addressed by name outside their own
 * module — they are emitted as the ADT tag form instead (see `emit_expr.php`).
 *
 * @return array<string, string>
 */
function buildPhpNameMap(Module $module): array
{
    $bindings = [];
    foreach ($module->functions as $function) {
        $bindings[$function->name] = true;
    }

    foreach ($module->instanceEvidence as $evidence) {
        $bindings[$evidence->evidenceName] = true;
        foreach ($evidence->methods as $irName) {
            $bindings[$irName] = true;
        }
    }

    $constructors = [];
    foreach ($module->data as $decl) {
        foreach ($decl->constructors as $ctor) {
            $constructors[$ctor->name] = true;
        }
    }

    $used = [];
    $map = [];
    foreach ([$bindings, $constructors] as $group) {
        $sorted = \array_keys($group);
        sort($sorted, SORT_STRING);
        foreach ($sorted as $name) {
            if (isset($map[$name])) {
                continue;
            }
            $map[$name] = allocatePhpFunctionName($name, $used);
        }
    }

    return $map;
}

/** @param array<string, true> $used */
function allocatePhpFunctionName(string $name, array &$used): string
{
    $base = basePhpFunctionName($name);
    $candidate = $base;
    $suffix = 2;

    while (isset($used[strtolower($candidate)])) {
        $candidate = str_ends_with($base, '_') ? $base . $suffix : $base . '_' . $suffix;
        ++$suffix;
    }

    $used[strtolower($candidate)] = true;

    return $candidate;
}

function basePhpFunctionName(string $name): string
{
    if (isLambdaName($name)) {
        return '__lambda_' . substr($name, strlen('λ'));
    }

    if ($name === '+') {
        return '__op_plus';
    }

    if ($name === '-') {
        return '__op_minus';
    }

    if ($name === '*') {
        return '__op_mul';
    }

    if ($name === '/') {
        return '__op_div';
    }

    if ($name === '==') {
        return '__op_eq';
    }

    if ($name === '/=') {
        return '__op_ne';
    }

    if ($name === '<>') {
        return '__op_concat';
    }

    if ($name === '<') {
        return '__op_lt';
    }

    if ($name === '<=') {
        return '__op_lte';
    }

    if ($name === '>') {
        return '__op_gt';
    }

    if ($name === '>=') {
        return '__op_gte';
    }

    if ($name === '&&') {
        return '__op_and';
    }

    if ($name === '||') {
        return '__op_or';
    }

    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1) {
        if (isPhpKeyword($name) || isPhpBuiltin($name)) {
            return $name . '_';
        }

        return $name;
    }

    return '__fn_' . bin2hex($name);
}

/** @param array<string, string> $phpNames */
function phpFunctionName(string $name, array $phpNames): string
{
    if (!isset($phpNames[$name])) {
        throw new \RuntimeException("missing php name mapping for `{$name}`");
    }

    return $phpNames[$name];
}

function mangleVar(string $name): string
{
    if ($name === '') {
        return '_';
    }

    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1) {
        return $name;
    }

    return '_v_' . bin2hex($name);
}

/** PHP variable for an IR temp. Prefixed so it cannot clash with locals like `t3`. */
function phpTemp(int $id): string
{
    return '$__t' . $id;
}
