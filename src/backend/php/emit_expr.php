<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;

use Moggi\IR;

use function Moggi\Backend\Php\Foreign\emitForeignExpression;
use function Moggi\Backend\Php\Intrinsics\emitCall as emitIntrinsicCall;
use function Moggi\Backend\Php\Naming\basePhpFunctionName;
use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpFunctionName;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\IR\Visit\walkOperand;
use function Moggi\Modules\isQualifiedSymbol;
use function Moggi\Modules\moduleNameToNamespace;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Optimize\Support\isCapturedFnName;
use function Moggi\Syntax\isConstructorName as isSyntaxConstructorName;

function emitDictMethodOperand(IR\Operand $operand, array $ctx): string
{
    $evidence = emitOperand($operand->evidence, $ctx);
    $method = json_encode($operand->method, JSON_UNESCAPED_UNICODE);

    return '(' . $evidence . '[' . $method . '])';
}

/** @param list<IR\Operand> $args */
function emitCallExpr(string $callee, array $args, array $ctx): string
{
    $partial = partialArrayForUnderappliedLambda($callee, $args, $ctx);
    if ($partial !== null) {
        return $partial;
    }

    $argsCode = join(', ', \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $args));

    if (str_starts_with($callee, '__tuple_field')) {
        $index = (int) substr($callee, strlen('__tuple_field'));

        return emitOperand($args[0], $ctx) . '[' . $index . ']';
    }

    if (str_starts_with($callee, '__tuple')) {
        $arity = (int) substr($callee, strlen('__tuple'));
        if ($arity === 0) {
            return 'null';
        }

        return '[' . $argsCode . ']';
    }

    if (str_starts_with($callee, '__field')) {
        $index = (int) substr($callee, strlen('__field'));

        return emitOperand($args[0], $ctx) . '[' . ($index + 1) . ']';
    }

    $ctorTag = constructorTag($callee);
    if ($ctorTag !== null) {
        if ($ctorTag === $callee && isset($ctx['phpNames'][$ctorTag])) {
            return phpFunctionName($ctorTag, $ctx['phpNames']) . "({$argsCode})";
        }

        $tag = json_encode($ctorTag, JSON_UNESCAPED_UNICODE);
        if ($argsCode === '') {
            return "[{$tag}]";
        }

        return "[{$tag}, {$argsCode}]";
    }

    if (isQualifiedCallee($callee)) {
        return formatQualifiedCallee($callee, $ctx) . "({$argsCode})";
    }

    $external = $ctx['externalFns'][$callee] ?? null;
    if ($external !== null) {
        return basePhpFunctionName($callee) . "({$argsCode})";
    }

    if (!isset($ctx['phpNames'][$callee])
        && ($ctx['externalFns'][$callee] ?? null) === null
        && !isConstructorName($callee)
        && !str_starts_with($callee, '__')) {
        return '$' . mangleVar($callee) . "({$argsCode})";
    }

    return phpFunctionName($callee, $ctx['phpNames']) . "({$argsCode})";
}

/** @param list<IR\Operand> $args */
function emitCall(string $callee, array $args, int $dest, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $arity = $ctx['functionArity'][$callee] ?? null;

    if (
        $arity !== null
        && count($args) !== $arity
        && !str_starts_with($callee, '__ev_')
    ) {
        return $pad . phpTemp($dest) . ' = '
            . emitApplyExpr(new IR\FnRef($callee), $args, $ctx) . ";\n";
    }

    return $pad . phpTemp($dest) . ' = ' . emitCallExpr($callee, $args, $ctx) . ";\n";
}

function emitRetValue(IR\Operand $value, array $ctx): string
{
    return match ($value::class) {
        IR\ExprBinop::class => emitOperand($value->left, $ctx) . ' ' . phpBinop($value->op) . ' ' . emitOperand($value->right, $ctx),
        IR\ExprCall::class => emitCallExpr($value->callee, $value->args, $ctx),
        IR\ExprCallValue::class => emitApplyExpr($value->callee, $value->args, $ctx),
        IR\ExprPartial::class => emitPartialArray($value, $ctx),
        IR\Intrinsic::class => emitOperand($value, $ctx),
        // A lifted function's name is a capture array; every other reference is
        // the top-level name, which is where Bool constructors become
        // `true`/`false`.
        IR\FnRef::class => isCapturedFnName($value->name)
            ? emitLambdaReference($value->name, $ctx)
            : emitTopLevelFnUse($value->name, $ctx),
        default => emitOperand($value, $ctx),
    };
}

/**
 * A host int literal. PHP reads `-9223372036854775808` as a float (the minus
 * applies to a positive literal that does not fit), so `minBound` is spelled
 * out.
 */
function emitIntLiteral(int $value): string
{
    return $value === \PHP_INT_MIN ? '\\PHP_INT_MIN' : (string) $value;
}

function emitOperand(IR\Operand $operand, array $ctx): string
{
    if ($operand instanceof IR\FnRef && isCapturedFnName($operand->name)) {
        return emitLambdaReference($operand->name, $ctx);
    }

    return match ($operand::class) {
        IR\ConstInt::class => emitIntLiteral($operand->value),
        IR\ConstDouble::class => formatDoubleLiteral($operand->value),
        IR\ConstStr::class => exportStringLiteral($operand->value),
        IR\ConstChar::class => (string) $operand->value,
        IR\Local::class => $ctx['patternLocals'][$operand->name]
            ?? ('$' . mangleVar($operand->name)),
        IR\Temp::class => phpTemp($operand->id),
        IR\Unit::class => 'null',
        IR\FnRef::class => emitTopLevelFnUse($operand->name, $ctx),
        IR\ListLit::class => '[' . join(', ', \array_map(
            static fn (IR\Operand $element): string => emitOperand($element, $ctx),
            $operand->elements,
        )) . ']',
        IR\Partial::class => emitPartialArray($operand, $ctx),
        IR\ExprCall::class => emitCallExpr($operand->callee, $operand->args, $ctx),
        IR\ExprPartial::class => emitPartialArray($operand, $ctx),
        IR\ExprBinop::class, IR\ExprCallValue::class => '(' . emitRetValue($operand, $ctx) . ')',
        IR\Intrinsic::class => emitIntrinsicCall(
            $operand->name,
            \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $operand->args),
            $ctx['localIntrinsicHelpers'] ?? [],
            $operand->srcLoc,
            $ctx,
            \array_map(static fn (IR\Operand $arg): bool => isLeafOperand($arg), $operand->args),
        ),
        IR\ForeignCall::class => emitForeignExpression(
            $operand,
            \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $operand->args),
        ),
        IR\DictMethod::class => emitDictMethodOperand($operand, $ctx),
        default => throw new \RuntimeException("unsupported operand `" . $operand::class . "` in codegen"),
    };
}

/** An operand that is free to repeat: a temp, a local or a literal. */
function isLeafOperand(IR\Operand $op): bool
{
    return $op instanceof IR\Temp
        || $op instanceof IR\Local
        || $op instanceof IR\ConstInt
        || $op instanceof IR\ConstStr
        || $op instanceof IR\ConstChar
        || $op instanceof IR\Unit
        || $op instanceof IR\FnRef;
}

function formatDoubleLiteral(float $value): string
{
    if (is_nan($value)) {
        return 'NAN';
    }

    if (is_infinite($value)) {
        return $value < 0 ? '-INF' : 'INF';
    }

    $text = rtrim(rtrim(\sprintf('%.17F', $value), '0'), '.');

    return str_contains($text, '.') ? $text : $text . '.0';
}

function phpBinop(string $op): string
{
    return match ($op) {
        '<>' => '.',
        '/=' => '!=',
        default => $op,
    };
}

function exportStringLiteral(string $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function canInlineLambdaAsArrow(IR\FunctionDecl $lambdaFn): bool
{
    $items = $lambdaFn->body->items;
    if (!(count($items) === 1 && $items[0] instanceof IR\Ret)) {
        return false;
    }

    return !retValueMentionsFn($items[0]->value, $lambdaFn->name);
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
        } elseif ($op instanceof IR\ExprCall && $op->callee === $name) {
            $found = true;
        } elseif (
            ($op instanceof IR\Partial || $op instanceof IR\ExprPartial)
            && $op->fn === $name
        ) {
            $found = true;
        }
    });

    return $found;
}

function emitLambdaArrow(IR\FunctionDecl $lambdaFn, array $ctx): string
{
    $params = \array_map(static fn (string $p): string => '$' . mangleVar($p), $lambdaFn->params);
    $ret = $lambdaFn->body->items[0];

    return 'fn(' . join(', ', $params) . ') => ' . emitRetValue($ret->value, $ctx);
}

function emitLambdaReference(string $name, array $ctx): string
{
    $lambdaFn = $ctx['lambdaIndex'][$name] ?? null;
    if ($lambdaFn !== null && canInlineLambdaAsArrow($lambdaFn)) {
        return emitLambdaArrow($lambdaFn, $ctx);
    }

    $meta = $ctx['lambdaMeta'][$name];
    $mangled = phpFunctionName($name, $ctx['phpNames']);
    $captureArgs = \array_map(static fn (string $v): string => '$' . mangleVar($v), $meta['captures']);
    $paramArgs = \array_map(static fn (string $p): string => '$' . mangleVar($p), $meta['params']);

    return 'fn(' . join(', ', \array_map(static fn (string $p): string => '$' . mangleVar($p), $meta['params'])) . ') => '
        . $mangled . '(' . join(', ', [...$captureArgs, ...$paramArgs]) . ')';
}

/** @param array{phpNames: array<string, string>, functionArity?: array<string, int>, externalFns?: array<string, string>, boolConstructors?: array<string, bool>} $ctx */
function emitTopLevelFnUse(string $name, array $ctx): string
{
    if (\array_key_exists($name, $ctx['boolConstructors'] ?? [])) {
        return $ctx['boolConstructors'][$name] ? 'true' : 'false';
    }

    $ctorTag = constructorTag($name);
    if ($ctorTag !== null && !($ctorTag === $name && isset($ctx['phpNames'][$ctorTag]))) {
        return emitConstructorValue(
            $ctorTag,
            $ctx['functionArity'][$name] ?? $ctx['functionArity'][$ctorTag] ?? null,
        );
    }

    if (isQualifiedCallee($name)) {
        $member = str_contains($name, '::')
            ? substr($name, strrpos($name, '::') + 2)
            : substr($name, strrpos($name, '\\') + 1);
        $arity = $ctx['functionArity'][$name] ?? $ctx['functionArity'][$member] ?? null;
        $callee = formatQualifiedCallee($name, $ctx);

        return $arity === 0 ? $callee . '()' : $callee . '(...)';
    }

    $external = $ctx['externalFns'][$name] ?? null;
    $phpName = $external !== null
        ? basePhpFunctionName($name)
        : phpFunctionName($name, $ctx['phpNames']);
    $arity = $ctx['functionArity'][$name] ?? null;

    if ($arity === 0) {
        return $phpName . '()';
    }

    return $phpName . '(...)';
}

/**
 * A constructor used as a value.
 *
 * A nullary constructor *is* its tag value; a constructor of arity ≥ 1 is a
 * function, emitted as a closure building the tag form.
 */
function emitConstructorValue(string $tag, ?int $arity): string
{
    $name = json_encode($tag, JSON_UNESCAPED_UNICODE);
    if ($arity === 0) {
        return "[{$name}]";
    }

    if ($arity === null) {
        return "fn(...\$__a) => [{$name}, ...\$__a]";
    }

    $params = [];
    for ($i = 0; $i < $arity; ++$i) {
        $params[] = '$__a' . $i;
    }

    return 'fn(' . join(', ', $params) . ") => [{$name}, " . join(', ', $params) . ']';
}

/** Is this a `Module::name` symbol or an already-qualified PHP FQN? */
function isQualifiedCallee(string $name): bool
{
    return isQualifiedSymbol($name);
}

/** @param array{externalFns?: array<string, string>, moduleAsNames?: array<string, string>} $ctx */
function formatQualifiedCallee(string $name, array $ctx): string
{
    if (str_contains($name, '::')) {
        $parsed = parseResolvedSymbol($name);
        if ($parsed !== null) {
            $namespace = moduleNameToNamespace($parsed['module']);
            $member = basePhpFunctionName($parsed['name']);
            $asName = $ctx['moduleAsNames'][$namespace] ?? null;

            return $asName !== null ? $asName . '\\' . $member : '\\' . $namespace . '\\' . $member;
        }
    }

    $phpName = $name;
    if (!isQualifiedCallee($phpName)) {
        return basePhpFunctionName($phpName);
    }

    $separator = strrpos($phpName, '\\');
    $namespace = substr($phpName, 0, $separator);
    $member = basePhpFunctionName(substr($phpName, $separator + 1));
    $asName = $ctx['moduleAsNames'][$namespace] ?? null;
    if ($asName !== null) {
        return $asName . '\\' . $member;
    }

    return '\\' . $namespace . '\\' . $member;
}

function isConstructorName(string $name): bool
{
    return $name !== ''
        && !str_contains($name, '\\')
        && !str_contains($name, '::')
        && !str_starts_with($name, '__')
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

    $separator = str_contains($name, '::')
        ? strrpos($name, '::') + 2
        : (isQualifiedCallee($name) ? strrpos($name, '\\') + 1 : null);
    if ($separator === null) {
        return null;
    }

    $member = substr($name, $separator);

    return isConstructorName($member) ? $member : null;
}

/** @param list<IR\Operand> $args */
function emitApplyExpr(IR\Operand $callee, array $args, array $ctx): string
{
    if ($callee instanceof IR\Partial || $callee instanceof IR\ExprPartial) {
        $all = [...$callee->args, ...$args];
        if (count($all) === $callee->arity) {
            return emitCallExpr($callee->fn, $all, $ctx);
        }

        return emitPartialArray(new IR\Partial($callee->fn, $callee->arity, $all), $ctx);
    }

    if ($callee instanceof IR\FnRef && isCapturedFnName($callee->name)) {
        $lambdaFn = $ctx['lambdaIndex'][$callee->name] ?? null;
        if ($lambdaFn !== null && canInlineLambdaAsArrow($lambdaFn) && count($args) === count($lambdaFn->params)) {
            $arrow = emitLambdaArrow($lambdaFn, $ctx);
            $callArgs = \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $args);

            return '(' . $arrow . ')(' . join(', ', $callArgs) . ')';
        }

        $partial = partialArrayForUnderappliedLambda($callee->name, $args, $ctx);
        if ($partial !== null) {
            return $partial;
        }
    }

    if ($callee instanceof IR\FnRef) {
        $arity = $ctx['functionArity'][$callee->name] ?? null;
        if ($arity !== null) {
            if (count($args) === $arity) {
                return emitCallExpr($callee->name, $args, $ctx);
            }

            if (count($args) < $arity) {
                return emitPartialArray(new IR\Partial($callee->name, $arity, $args), $ctx);
            }
        }
    }

    $calleeCode = emitOperand($callee, $ctx);
    $argsCode = join(', ', \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $args));

    return '__apply(' . $calleeCode . ($argsCode === '' ? '' : ', ' . $argsCode) . ')';
}

/**
 * A lambda applied to fewer arguments than it declares is a partial
 * application, not a call: PHP would reject the call outright ("too few
 * arguments"), and the runtime already knows how to hold the arguments and
 * dispatch once the rest arrive.
 *
 * This is what a constrained local binding with two or more parameters lowers
 * to -- `let go = λ(dict, p) -> λ3(dict, p)` -- so without it the adapter dies
 * the moment it is applied.
 *
 * @param list<IR\Operand> $args
 */
function partialArrayForUnderappliedLambda(string $callee, array $args, array $ctx): ?string
{
    $lambdaFn = $ctx['lambdaIndex'][$callee] ?? null;
    if ($lambdaFn === null || !isCapturedFnName($callee)) {
        return null;
    }

    $arity = count($lambdaFn->params) + count($ctx['lambdaMeta'][$callee]['captures'] ?? []);
    if (count($args) >= $arity) {
        return null;
    }

    return emitPartialArray(new IR\Partial($callee, $arity, $args), $ctx);
}

function emitPartialArray(IR\Partial|IR\ExprPartial $partial, array $ctx): string
{
    $fn = emitPartialCallableName($partial->fn, $ctx);
    $args = \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $partial->args);

    return "['__partial', {$partial->arity}, {$fn}" . ($args === [] ? '' : ', ' . join(', ', $args)) . ']';
}

function emitPartialCallableName(string $name, array $ctx): string
{
    $ctorTag = constructorTag($name);
    if ($ctorTag !== null && !($ctorTag === $name && isset($ctx['phpNames'][$ctorTag]))) {
        return emitConstructorValue(
            $ctorTag,
            $ctx['functionArity'][$name] ?? $ctx['functionArity'][$ctorTag] ?? null,
        );
    }

    // A partial's callable is a *string*, invoked from the runtime's namespace,
    // so an imported function has to be spelled out in full: `use function`
    // aliases and the global-function fallback only work for direct calls.
    $external = $ctx['externalFns'][$name] ?? null;
    if ($external !== null) {
        return var_export(formatQualifiedCallee($external, $ctx), true);
    }

    if (str_contains($name, '::') || str_contains($name, '\\')) {
        return var_export(formatQualifiedCallee($name, $ctx), true);
    }

    $phpName = phpFunctionName($name, $ctx['phpNames']);
    $namespace = $ctx['moduleNamespace'] ?? null;
    if ($namespace !== null && $namespace !== '') {
        $phpName = '\\' . $namespace . '\\' . $phpName;
    }

    return var_export($phpName, true);
}
