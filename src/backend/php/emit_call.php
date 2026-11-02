<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Php\Naming\buildPhpNameMap;
use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpFunctionName;
use Moggi\IR;
use function Moggi\Backend\Meta\returnedCall;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\Backend\Php\Naming\basePhpFunctionName;
use function Moggi\IR\Projection\parse as projectionParse;
use function Moggi\IR\Projection\tupleArity as projectionTupleArity;
use function Moggi\Modules\isQualifiedSymbol;
use function Moggi\Modules\moduleNameToNamespace;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Syntax\isConstructorName as isSyntaxConstructorName;
use function Moggi\Backend\Php\Codegen\emitOperand;

function emitDictMethodOperand(IR\Operand $operand, EmitEnv $env): string
{
    $evidence = emitOperand($env, $operand->evidence);
    $method = \json_encode($operand->method, JSON_UNESCAPED_UNICODE);

    return '(' . $evidence . '[' . $method . '])';
}

/** @param list<IR\Operand> $args */
function emitStaticCall(EmitEnv $env, string $callee, array $args, ?string $ownerModule = null): string
{
    $argsCode = \implode(', ', \array_map(static fn (IR\Operand $arg): string => emitOperand($env, $arg), $args));

    $projection = projectionParse($callee);
    if ($projection !== null) {
        $base = emitOperand($env, $args[0]);

        return $projection['tuple']
            ? $base . '[' . $projection['index'] . ']'
            : $base . '[' . ($projection['index'] + 1) . ']';
    }

    $tupleArity = projectionTupleArity($callee);
    if ($tupleArity !== null) {
        return $tupleArity === 0 ? 'null' : '[' . $argsCode . ']';
    }

    $ctorTag = constructorTag($callee);
    if ($ctorTag !== null) {
        if (isset($env->newtypeConstructors[$ctorTag]) && \count($args) === 1) {
            return emitOperand($env, $args[0]);
        }

        if ($ctorTag === $callee && isset($env->phpNames[$ctorTag])) {
            return phpFunctionName($ctorTag, $env->phpNames) . "({$argsCode})";
        }

        $tag = \json_encode($ctorTag, JSON_UNESCAPED_UNICODE);
        if ($argsCode === '') {
            return "[{$tag}]";
        }

        return "[{$tag}, {$argsCode}]";
    }

    if (isQualifiedSymbol($callee)) {
        return formatQualifiedCallee($callee, $env) . "({$argsCode})";
    }

    $external = $env->externalFns[$callee] ?? null;
    if ($external !== null) {
        return basePhpFunctionName($callee) . "({$argsCode})";
    }

    if (! isset($env->phpNames[$callee])
        && ($env->externalFns[$callee] ?? null) === null
        && ! isConstructorName($callee)
        && ! \str_starts_with($callee, '__')) {
        return '$' . mangleVar($callee) . "({$argsCode})";
    }

    return phpFunctionName($callee, $env->phpNames) . "({$argsCode})";
}

/** @param list<IR\Operand> $args */
function emitCallStmt(string $callee, array $args, int $dest, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);

    return $pad . phpTemp($dest) . ' = ' . emitStaticCall($env, $callee, $args) . ";\n";
}

/**
 * A call rendered as the value a `return` produces, for the one case where the call's destination
 * has no other reader (`\Moggi\Backend\Meta\returnedCall`). Same builders the statement form uses,
 * so the emitted call is the same call — only the store and the load of its temp are gone.
 */
function emitReturnedCall(IR\Call|IR\CallValue $call, EmitEnv $env): string
{
    return $call instanceof IR\Call
        ? emitStaticCall($env, $call->callee, $call->args)
        : emitCallValue($env, $call);
}

/**
 * The PHP callee for a qualified symbol.
 *
 * `$aliasNamespaces` is off for a callable that is emitted as a *string* (a partial's target):
 * a namespace alias is resolved at compile time and does not apply when the runtime invokes the
 * name dynamically, so such a value has to carry the canonical, fully qualified name.
 *
 * @param array{externalFns?: array<string, string>, moduleAsNames?: array<string, string>} $env
 */
function formatQualifiedCallee(string $name, EmitEnv $env, bool $aliasNamespaces = true): string
{
    if (\str_contains($name, '::')) {
        $parsed = parseResolvedSymbol($name);
        if ($parsed !== null) {
            $namespace = moduleNameToNamespace($parsed['module']);
            $member = basePhpFunctionName($parsed['name']);
            $asName = $aliasNamespaces ? ($env->moduleAsNames[$namespace] ?? null) : null;

            return $asName !== null ? $asName . '\\' . $member : '\\' . $namespace . '\\' . $member;
        }
    }

    $phpName = $name;
    if (! isQualifiedSymbol($phpName)) {
        return basePhpFunctionName($phpName);
    }

    $separator = \strrpos($phpName, '\\');
    $namespace = \substr($phpName, 0, $separator);
    $member = basePhpFunctionName(\substr($phpName, $separator + 1));
    $asName = $aliasNamespaces ? ($env->moduleAsNames[$namespace] ?? null) : null;
    if ($asName !== null) {
        return $asName . '\\' . $member;
    }

    return '\\' . $namespace . '\\' . $member;
}

/**
 * Whether a callee spelling may name a source constructor.
 *
 * The `__` refusal is a collision guard only: generated symbols (workers, evidence, partials) are
 * reserved that way, so a reference spelled like one cannot be a source constructor. Which
 * declaration a name resolves to is the resolver's answer, never this test's.
 */
function isConstructorName(string $name): bool
{
    return $name !== ''
        && ! \str_contains($name, '\\')
        && ! \str_contains($name, '::')
        && ! \str_starts_with($name, '__')
        && isSyntaxConstructorName($name);
}

/**
 * The ADT tag of the constructor a callee denotes, or null when it denotes an
 * ordinary binding.
 *
 * A qualified reference carries the constructor in its last segment
 * (`Data.Maybe::Just`, `M\Just`); an upper-case head is reserved for
 * constructors, so the segment alone is enough to recognise one. Callers must
 * not call such a constructor by name — see `buildPhpNameMap`.
 */
function constructorTag(string $name): ?string
{
    if (isConstructorName($name)) {
        return $name;
    }

    $separator = \str_contains($name, '::')
        ? \strrpos($name, '::') + 2
        : (isQualifiedSymbol($name) ? \strrpos($name, '\\') + 1 : null);
    if ($separator === null) {
        return null;
    }

    $member = \substr($name, $separator);

    return isConstructorName($member) ? $member : null;
}

/** @param list<IR\Operand> $args */
function emitCallValue(EmitEnv $env, IR\Call|IR\CallValue $call): string
{
    if ($call instanceof IR\Call) {
        return emitStaticCall($env, $call->callee, $call->args);
    }

    $callee = $call->callee;
    $args = $call->args;

    if ($callee instanceof IR\Partial) {
        $all = [...$callee->args, ...$args];
        if (\count($all) === $callee->arity) {
            return emitStaticCall($env, $callee->fn, $all);
        }

        return emitPartialValue($env, $callee->fn, $callee->arity, $all);
    }

    if ($callee instanceof IR\FnRef && lambdaKeyFor($callee->name, $env) !== null) {
        $lambdaFn = $env->lambdaIndex[lambdaKeyFor($callee->name, $env)];
        if ($lambdaFn !== null && canInlineLambdaAsArrow($lambdaFn) && \count($args) === \count($lambdaFn->params)) {
            $arrow = emitLambdaArrow($lambdaFn, $env);
            $callArgs = \array_map(static fn (IR\Operand $arg): string => emitOperand($env, $arg), $args);

            return '(' . $arrow . ')(' . \implode(', ', $callArgs) . ')';
        }

    }

    if ($callee instanceof IR\FnRef) {
        $arity = $env->functionArity[$callee->name] ?? null;
        if ($arity !== null) {
            if (\count($args) === $arity) {
                return emitStaticCall($env, $callee->name, $args);
            }

            if (\count($args) < $arity) {
                return emitPartialValue($env, $callee->name, $arity, $args);
            }
        }
    }

    $calleeCode = emitOperand($env, $callee);
    $argsCode = \implode(', ', \array_map(static fn (IR\Operand $arg): string => emitOperand($env, $arg), $args));

    return '__apply(' . $calleeCode . ($argsCode === '' ? '' : ', ' . $argsCode) . ')';
}

