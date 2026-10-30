<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpFunctionName;
use Moggi\IR;
use function Moggi\Backend\Php\Naming\basePhpFunctionName;
use function Moggi\IR\Visit\walkOperand;
use function Moggi\Modules\isQualifiedSymbol;
use function Moggi\Backend\Php\Codegen\emitOperand;
use function Moggi\Backend\Php\Codegen\emitFunctionReturn;

function canInlineLambdaAsArrow(IR\FunctionDecl $lambdaFn): bool
{
    $items = $lambdaFn->body->items;
    if (! (\count($items) === 1 && $items[0] instanceof IR\Ret)) {
        return false;
    }

    return ! retValueMentionsFn($items[0]->value, $lambdaFn->name);
}

function retValueMentionsFn(IR\Operand $operand, string $name): bool
{
    $found = false;
    walkOperand($operand, static function (IR\Operand $op) use (&$found, $name): void {
        if ($found) {
            return;
        }
        if ($op instanceof IR\FnRef && $op->name === $name) {
            $found = true;
        } elseif (
            ($op instanceof IR\Partial)
            && $op->fn === $name
        ) {
            $found = true;
        }
    });

    return $found;
}

function emitLambdaArrow(IR\FunctionDecl $lambdaFn, EmitEnv $env): string
{
    $params = \array_map(static fn (string $p): string => '$' . mangleVar($p), $lambdaFn->params);
    $ret = $lambdaFn->body->items[0];

    return 'fn(' . \implode(', ', $params) . ') => ' . emitFunctionReturn($ret->value, $env);
}

function emitLambdaReference(string $name, EmitEnv $env): string
{
    $lambdaFn = $env->lambdaIndex[$name] ?? null;
    if ($lambdaFn !== null && canInlineLambdaAsArrow($lambdaFn)) {
        return emitLambdaArrow($lambdaFn, $env);
    }

    $mangled = phpFunctionName($name, $env->phpNames);
    $params = $lambdaFn?->params ?? ($env->lambdaMeta[$name]['params'] ?? []);
    $paramArgs = \array_map(static fn (string $p): string => '$' . mangleVar($p), $params);

    return 'fn(' . \implode(', ', $paramArgs) . ') => ' . $mangled . '(' . \implode(', ', $paramArgs) . ')';
}

/** @param array{phpNames: array<string, string>, functionArity?: array<string, int>, externalFns?: array<string, string>, boolConstructors?: array<string, bool>} $env */
function emitTopLevelFnValue(string $name, EmitEnv $env): string
{
    if (\array_key_exists($name, $env->boolConstructors)) {
        return $env->boolConstructors[$name] ? 'true' : 'false';
    }

    $ctorTag = constructorTag($name);
    if ($ctorTag !== null && ! ($ctorTag === $name && isset($env->phpNames[$ctorTag]))) {
        return emitConstructorValue(
            $ctorTag,
            $env->functionArity[$name] ?? $env->functionArity[$ctorTag] ?? null,
        );
    }

    if (isQualifiedSymbol($name)) {
        $member = \str_contains($name, '::')
            ? \substr($name, \strrpos($name, '::') + 2)
            : \substr($name, \strrpos($name, '\\') + 1);
        $arity = $env->functionArity[$name] ?? $env->functionArity[$member] ?? null;
        $callee = formatQualifiedCallee($name, $env);

        return $arity === 0 ? $callee . '()' : $callee . '(...)';
    }

    $external = $env->externalFns[$name] ?? null;
    $phpName = $external !== null
        ? basePhpFunctionName($name)
        : phpFunctionName($name, $env->phpNames);
    $arity = $env->functionArity[$name] ?? null;

    if ($arity === 0) {
        return $phpName . '()';
    }

    return $phpName . '(...)';
}

function emitPartialValue(IR\Partial $partial, EmitEnv $env): string
{
    $fn = emitPartialCallableName($partial->fn, $env);
    $args = \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $env), $partial->args);

    return "['__partial', {$partial->arity}, {$fn}" . ($args === [] ? '' : ', ' . \implode(', ', $args)) . ']';
}

function emitPartialCallableName(string $name, EmitEnv $env): string
{
    $ctorTag = constructorTag($name);
    if ($ctorTag !== null && ! ($ctorTag === $name && isset($env->phpNames[$ctorTag]))) {
        return emitConstructorValue(
            $ctorTag,
            $env->functionArity[$name] ?? $env->functionArity[$ctorTag] ?? null,
        );
    }

    $external = $env->externalFns[$name] ?? null;
    if ($external !== null) {
        return \var_export(formatQualifiedCallee($external, $env), true);
    }

    if (\str_contains($name, '::') || \str_contains($name, '\\')) {
        return \var_export(formatQualifiedCallee($name, $env), true);
    }

    $phpName = phpFunctionName($name, $env->phpNames);
    $namespace = $env->moduleNamespace ?? null;
    if ($namespace !== null && $namespace !== '') {
        $phpName = '\\' . $namespace . '\\' . $phpName;
    }

    return \var_export($phpName, true);
}
