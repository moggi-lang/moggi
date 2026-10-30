<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpSingleQuoted;
use Moggi\IR;
use function Moggi\Backend\Php\Codegen\emitOperand;
use function Moggi\IR\Visit\mapOperandChildren;

/** @param int|string $value */
function emitPatLitPhp(int|string $value): string
{
    return \is_string($value) ? \json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
}

/** @return array<string, string> */
function buildPatternLocals(IR\Pattern $pattern, string $scrutinee): array
{
    $locals = [];

    if ($pattern instanceof IR\PatCon) {
        foreach ($pattern->args as $i => $arg) {
            if ($arg instanceof IR\PatVar) {
                $locals[$arg->name] = "{$scrutinee}[" . ($i + 1) . ']';
            }
        }

        return $locals;
    }

    return $locals;
}

/** @return array<string, true> */
function patternBoundNames(IR\Pattern $pattern): array
{
    $bound = [];
    if ($pattern instanceof IR\PatVar) {
        $bound[$pattern->name] = true;

        return $bound;
    }
    if ($pattern instanceof IR\PatCon) {
        foreach ($pattern->args as $arg) {
            foreach (patternBoundNames($arg) as $name => $_) {
                $bound[$name] = true;
            }
        }

        return $bound;
    }
    if ($pattern instanceof IR\PatTuple) {
        foreach ($pattern->elements as $el) {
            foreach (patternBoundNames($el) as $name => $_) {
                $bound[$name] = true;
            }
        }

        return $bound;
    }
    if ($pattern instanceof IR\PatCons) {
        return patternBoundNames($pattern->head) + patternBoundNames($pattern->tail);
    }

    return $bound;
}

/** @param array<string, string> $map */
function collectPatternBinderNames(IR\Pattern $pattern, array &$map, int $id, int &$n): void
{
    if ($pattern instanceof IR\PatVar) {
        if (! \str_starts_with($pattern->name, '__f')) {
            return;
        }
        if (! isset($map[$pattern->name])) {
            $map[$pattern->name] = $pattern->name . '__m' . $id . '_' . $n;
            ++$n;
        }

        return;
    }
    if ($pattern instanceof IR\PatCon) {
        foreach ($pattern->args as $arg) {
            collectPatternBinderNames($arg, $map, $id, $n);
        }

        return;
    }
    if ($pattern instanceof IR\PatTuple) {
        foreach ($pattern->elements as $el) {
            collectPatternBinderNames($el, $map, $id, $n);
        }

        return;
    }
    if ($pattern instanceof IR\PatCons) {
        collectPatternBinderNames($pattern->head, $map, $id, $n);
        collectPatternBinderNames($pattern->tail, $map, $id, $n);
    }
}

/** @param array<string, string> $map */
function renamePatternBinders(IR\Pattern $pattern, array $map): IR\Pattern
{
    if ($pattern instanceof IR\PatVar) {
        return new IR\PatVar($map[$pattern->name] ?? $pattern->name);
    }
    if ($pattern instanceof IR\PatCon) {
        return new IR\PatCon(
            $pattern->name,
            \array_map(static fn (IR\Pattern $a): IR\Pattern => renamePatternBinders($a, $map), $pattern->args),
        );
    }
    if ($pattern instanceof IR\PatTuple) {
        return new IR\PatTuple(\array_map(
            static fn (IR\Pattern $e): IR\Pattern => renamePatternBinders($e, $map),
            $pattern->elements,
        ));
    }
    if ($pattern instanceof IR\PatCons) {
        return new IR\PatCons(
            renamePatternBinders($pattern->head, $map),
            renamePatternBinders($pattern->tail, $map),
        );
    }

    return $pattern;
}

/**
 * Rename Locals in statements. Nested match arm bodies are rewritten for
 * outer binder renames, but nested patterns keep their own binder names
 * (shadowing removes those keys from the rename map).
 *
 * @param list<IR\Stmt> $items
 * @param array<string, string> $map
 * @return list<IR\Stmt>
 */
function renameLocalsInItemsShallow(array $items, array $map): array
{
    $out = [];
    foreach ($items as $item) {
        if ($item instanceof IR\MatchStmt) {
            $arms = [];
            foreach ($item->arms as $arm) {
                $arms[] = renameNestedMatchArmPreservingBinders($arm, $map);
            }
            $out[] = new IR\MatchStmt(
                renameLocalsInOperand($item->scrutinee, $map),
                $arms,
                $item->dest,
                $item->exhaustive,
            );

            continue;
        }
        if ($item instanceof IR\MatchReturn) {
            $arms = [];
            foreach ($item->arms as $arm) {
                $arms[] = renameNestedMatchArmPreservingBinders($arm, $map);
            }
            $out[] = new IR\MatchReturn(
                renameLocalsInOperand($item->scrutinee, $map),
                $arms,
                $item->exhaustive,
            );

            continue;
        }
        $out[] = renameLocalsInStmtDeep($item, $map);
    }

    return $out;
}

/**
 * @param array<string, string> $map
 */
function renameNestedMatchArmPreservingBinders(IR\MatchArm $arm, array $map): IR\MatchArm
{
    $bound = [];
    $n = 0;
    $tmp = [];
    collectPatternBinderNames($arm->pattern, $tmp, 0, $n);
    foreach ($tmp as $old => $_fresh) {
        $bound[$old] = true;
    }
    $innerMap = [];
    foreach ($map as $old => $fresh) {
        if (! isset($bound[$old])) {
            $innerMap[$old] = $fresh;
        }
    }
    $guards = IR\Visit\mapGuards(
        $arm->guards,
        static fn (IR\Operand $g): IR\Operand => renameLocalsInOperand($g, $innerMap),
        static fn (IR\Stmt $s): IR\Stmt => renameLocalsInItemsShallow([$s], $innerMap)[0],
    );

    return new IR\MatchArm(
        $arm->pattern,
        new IR\Block(renameLocalsInItemsShallow($arm->body->items, $innerMap)),
        $guards,
    );
}

/** @param array<string, string> $map */
function renameLocalsInStmtDeep(IR\Stmt $stmt, array $map): IR\Stmt
{
    return match ($stmt::class) {
        IR\Ret::class => new IR\Ret(renameLocalsInOperand($stmt->value, $map)),
        IR\Assign::class => new IR\Assign($stmt->dest, renameLocalsInOperand($stmt->value, $map)),
        IR\Let::class => new IR\Let($stmt->name, renameLocalsInOperand($stmt->value, $map)),
        IR\Call::class => new IR\Call(
            $stmt->callee,
            \array_map(static fn (IR\Operand $a): IR\Operand => renameLocalsInOperand($a, $map), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        IR\CallValue::class => new IR\CallValue(
            renameLocalsInOperand($stmt->callee, $map),
            \array_map(static fn (IR\Operand $a): IR\Operand => renameLocalsInOperand($a, $map), $stmt->args),
            $stmt->dest,
            $stmt->srcLoc,
        ),
        default => $stmt,
    };
}

/** @param array<string, string> $map */
function renameLocalsInOperand(IR\Operand $operand, array $map): IR\Operand
{
    if ($operand instanceof IR\Local) {
        return new IR\Local($map[$operand->name] ?? $operand->name);
    }

    return mapOperandChildren(
        $operand,
        static fn (IR\Operand $child): IR\Operand => renameLocalsInOperand($child, $map),
    );
}

function emitMatchArmCondition(IR\MatchArm $arm, string $scrutinee, EmitEnv $env): string
{
    $tests = [emitPatternTest($arm->pattern, $scrutinee, $env)];
    foreach ($arm->guards as $guard) {
        $tests[] = emitOperand($guard->cond, $env);
    }

    return joinPatternTests($tests);
}

/**
 * Conjoin pattern/guard tests, dropping the no-op `true` that a wildcard or
 * variable argument contributes: `arm Left _` is `… === 'Left' && true`
 * otherwise, which is a real (if pointless) operation on every arm.
 */
function joinPatternTests(array $tests): string
{
    $tests = \array_values(\array_filter($tests, static fn (string $test): bool => $test !== 'true'));
    if ($tests === []) {
        return 'true';
    }

    return \count($tests) === 1 ? $tests[0] : '(' . \implode(' && ', $tests) . ')';
}

function isCatchAllPattern(IR\Pattern $pattern): bool
{
    return match ($pattern::class) {
        IR\PatWild::class, IR\PatVar::class => true,
        IR\PatTuple::class => \array_reduce(
            $pattern->elements,
            static fn (bool $carry, IR\Pattern $element): bool => $carry && isCatchAllPattern($element),
            true,
        ),
        default => false,
    };
}

function patternConstructorName(IR\Pattern $pattern, EmitEnv $env): string
{
    $name = $pattern->name;

    return $env->constructorRenames[$name] ?? $name;
}

function emitPatternTest(IR\Pattern $pattern, string $scrutinee, EmitEnv $env): string
{
    if ($pattern instanceof IR\PatCon && $pattern->args === []) {
        $name = patternConstructorName($pattern, $env);
        if (\array_key_exists($name, $env->boolConstructors)) {
            $value = $env->boolConstructors[$name] ? 'true' : 'false';

            return "{$scrutinee} === {$value}";
        }
    }

    return match ($pattern::class) {
        IR\PatWild::class => 'true',
        IR\PatVar::class => 'true',
        IR\PatLit::class => "{$scrutinee} === " . emitPatLitPhp($pattern->value),
        IR\PatChar::class => "{$scrutinee} === " . $pattern->value,
        IR\PatCon::class => emitConPatternTest($pattern, $scrutinee, $env),
        IR\PatTuple::class => emitTuplePatternTest($pattern, $scrutinee, $env),
        IR\PatNil::class => "{$scrutinee} === []",
        IR\PatCons::class => joinPatternTests([
            $scrutinee . ' !== []',
            emitPatternTest($pattern->head, $scrutinee . '[0]', $env),
            emitPatternTest($pattern->tail, '\array_slice(' . $scrutinee . ', 1)', $env),
        ]),
        default => throw new \RuntimeException('unsupported pattern `' . $pattern::class . '` in codegen'),
    };
}

function emitConPatternTest(IR\Pattern $pattern, string $scrutinee, EmitEnv $env): string
{
    $name = patternConstructorName($pattern, $env);
    if (isset($env->newtypeConstructors[$name])) {
        $tests = [];
        foreach ($pattern->args as $arg) {
            $tests[] = emitPatternTest($arg, $scrutinee, $env);
        }

        return joinPatternTests($tests);
    }

    $tests = ["(({$scrutinee})[0] ?? null) === " . phpSingleQuoted($name)];
    foreach ($pattern->args as $i => $arg) {
        $tests[] = emitPatternTest($arg, "{$scrutinee}[" . ($i + 1) . ']', $env);
    }

    return joinPatternTests($tests);
}

function emitTuplePatternTest(IR\Pattern $pattern, string $scrutinee, EmitEnv $env): string
{
    $tests = [];
    foreach ($pattern->elements as $i => $element) {
        $tests[] = emitPatternTest($element, "{$scrutinee}[{$i}]", $env);
    }

    return joinPatternTests($tests);
}

function emitPatternBindings(IR\Pattern $pattern, string $scrutinee, int $indent, EmitEnv $env): string
{
    return match ($pattern::class) {
        IR\PatWild::class, IR\PatLit::class, IR\PatChar::class, IR\PatVar::class => '',
        IR\PatCon::class => emitConPatternBindings($pattern, $scrutinee, $indent, $env),
        IR\PatTuple::class => emitTuplePatternBindings($pattern, $scrutinee, $indent, $env),
        IR\PatNil::class => '',
        IR\PatCons::class => emitListConsPatternBindings($pattern, $scrutinee, $indent, $env),
        default => throw new \RuntimeException('unsupported pattern `' . $pattern::class . '` in codegen'),
    };
}

function emitConPatternBindings(IR\Pattern $pattern, string $scrutinee, int $indent, EmitEnv $env): string
{
    $name = patternConstructorName($pattern, $env);
    if (isset($env->newtypeConstructors[$name])) {
        return $pattern->args === []
            ? ''
            : emitPatternBinding($pattern->args[0], $scrutinee, $indent, $env);
    }

    if ($pattern->args === []) {
        return '';
    }

    $pad = \str_repeat('    ', $indent);
    $snap = nextPhpScrutineeSnapshot($env);
    $out = $pad . "{$snap} = {$scrutinee};\n";
    foreach ($pattern->args as $i => $arg) {
        $field = "{$snap}[" . ($i + 1) . ']';
        $out .= emitPatternBinding($arg, $field, $indent, $env);
    }

    return $out;
}

function emitTuplePatternBindings(IR\Pattern $pattern, string $scrutinee, int $indent, EmitEnv $env): string
{
    if ($pattern->elements === []) {
        return '';
    }

    $pad = \str_repeat('    ', $indent);
    $snap = nextPhpScrutineeSnapshot($env);
    $out = $pad . "{$snap} = {$scrutinee};\n";
    foreach ($pattern->elements as $i => $element) {
        $field = "{$snap}[{$i}]";
        $out .= emitPatternBinding($element, $field, $indent, $env);
    }

    return $out;
}

function emitListConsPatternBindings(IR\Pattern $pattern, string $scrutinee, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $snap = nextPhpScrutineeSnapshot($env);
    $out = $pad . "{$snap} = {$scrutinee};\n";
    $head = "{$snap}[0]";
    $tail = "\array_slice({$snap}, 1)";

    return $out
        . emitPatternBinding($pattern->head, $head, $indent, $env)
        . emitPatternBinding($pattern->tail, $tail, $indent, $env);
}

function nextPhpScrutineeSnapshot(EmitEnv $env): string
{
    return '$__scrut_' . $env->snapshots->next();
}

function emitPatternBinding(IR\Pattern $pattern, string $source, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);

    return match ($pattern::class) {
        IR\PatWild::class, IR\PatLit::class, IR\PatChar::class => '',
        IR\PatVar::class => $pad . '$' . mangleVar($pattern->name) . " = {$source};\n",
        IR\PatCon::class => emitConPatternBindings($pattern, $source, $indent, $env),
        IR\PatTuple::class => emitTuplePatternBindings($pattern, $source, $indent, $env),
        IR\PatNil::class => '',
        IR\PatCons::class => emitListConsPatternBindings($pattern, $source, $indent, $env),
        default => throw new \RuntimeException('unsupported pattern `' . $pattern::class . '` in codegen'),
    };
}
