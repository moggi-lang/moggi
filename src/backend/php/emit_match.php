<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpSingleQuoted;
use Moggi\IR;
use function Moggi\Backend\Meta\returnedCall;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\IR\Visit\walkOperand;
use function Moggi\Backend\Php\Codegen\emitOperand;
use function Moggi\Backend\Php\Codegen\emitBlock;
use function Moggi\Backend\Php\Codegen\emitFunctionReturn;

function emitMatchStmt(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $depth = $env->matchDepth;
    $env->matchDepth = $depth + 1;

    try {
        $body = canUsePhpMatch($stmt, $env)
            ? emitPhpMatch($stmt, $indent, $env)
            : emitMatchIo($stmt, $indent, $env);
        if ($depth > 0) {
            return emitWithSavedFieldBinders($body, $indent, $depth);
        }

        return $body;
    } finally {
        $env->matchDepth = $depth;
    }
}

/**
 * Wrap match emission with save/restore of synthetic `__fN` binders that the
 * nested body actually mentions (any N — Generic records are not capped at 16).
 * Saving every binder at every nest depth dominated Generic decode PE.
 */
function emitWithSavedFieldBinders(string $body, int $indent, int $depth): string
{
    $used = [];
    if (\preg_match_all('/\$__f(\d+)(?!\d)/', $body, $m) > 0) {
        foreach ($m[1] as $digits) {
            $used[(int) $digits] = true;
        }
        $used = \array_keys($used);
        \sort($used);
    }
    if ($used === []) {
        return $body;
    }

    $pad = \str_repeat('    ', $indent);
    $tag = (string) $depth;
    $out = '';
    foreach ($used as $i) {
        $v = '$__f' . $i;
        $s = '$__fs' . $tag . '_' . $i;
        $h = '$__fh' . $tag . '_' . $i;
        $out .= $pad . "{$h} = isset({$v});\n";
        $out .= $pad . "if ({$h}) {\n";
        $out .= $pad . "    {$s} = {$v};\n";
        $out .= $pad . "}\n";
    }
    $out .= $body;
    foreach ($used as $i) {
        $v = '$__f' . $i;
        $s = '$__fs' . $tag . '_' . $i;
        $h = '$__fh' . $tag . '_' . $i;
        $out .= $pad . "if ({$h}) {\n";
        $out .= $pad . "    {$v} = {$s};\n";
        $out .= $pad . "}\n";
    }

    return $out;
}

/**
 * Whether a match can be PHP's own `match` expression.
 *
 * That expression answers with the matched arm's value, so this form is the *return*-position one:
 * a value-position region names its result in its own slot, which its arms write, so they are never
 * the single `Ret` this rendering needs.
 */
function canUsePhpMatch(IR\Stmt $stmt, EmitEnv $env): bool
{
    $mode = null;
    $lastIndex = \count($stmt->arms) - 1;
    foreach ($stmt->arms as $i => $arm) {
        $pattern = $arm->pattern;
        if (isCatchAllPattern($pattern)) {
            if ($i !== $lastIndex) {
                return false;
            }
        } elseif ($pattern instanceof IR\PatLit || $pattern instanceof IR\PatChar) {
            if ($mode !== null && $mode !== 'literal') {
                return false;
            }
            $mode = 'literal';
        } elseif ($pattern instanceof IR\PatCon) {
            if (isset($env->newtypeConstructors[patternConstructorName($pattern, $env)])) {
                return false;
            }
            if ($mode !== null && $mode !== 'constructor') {
                return false;
            }
            $mode = 'constructor';
        } else {
            return false;
        }

        if (($arm->guards) !== []) {
            return false;
        }

        if (! patternIsShallowForPhpMatch($pattern)) {
            return false;
        }

        $items = $arm->body->items;
        if ($items === []) {
            return false;
        }

        if (\count($items) !== 1) {
            return false;
        }

        $last = $items[0];
        if (! ($last instanceof IR\Ret)) {
            return false;
        }
    }

    return $stmt->arms !== [];
}

function patternIsShallowForPhpMatch(IR\Pattern $pattern): bool
{
    if ($pattern instanceof IR\PatWild || $pattern instanceof IR\PatVar || $pattern instanceof IR\PatLit
        || $pattern instanceof IR\PatChar) {
        return true;
    }

    if ($pattern instanceof IR\PatCon) {
        foreach ($pattern->args as $arg) {
            if (! ($arg instanceof IR\PatWild || $arg instanceof IR\PatVar)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

function isBoolConstructorMatch(IR\Stmt $stmt, EmitEnv $env): bool
{
    $boolArms = 0;

    foreach ($stmt->arms as $arm) {
        if (isCatchAllPattern($arm->pattern)) {
            continue;
        }

        if (! ($arm->pattern instanceof IR\PatCon) || $arm->pattern->args !== []) {
            return false;
        }

        $name = patternConstructorName($arm->pattern, $env);
        if (! \array_key_exists($name, $env->boolConstructors)) {
            return false;
        }

        $boolArms++;
    }

    return $boolArms > 0;
}

/** @return 'literal'|'constructor' */
function phpMatchMode(IR\Stmt $stmt): string
{
    foreach ($stmt->arms as $arm) {
        if (isCatchAllPattern($arm->pattern)) {
            continue;
        }

        return ($arm->pattern instanceof IR\PatLit || $arm->pattern instanceof IR\PatChar) ? 'literal' : 'constructor';
    }

    return 'literal';
}

function emitPhpMatch(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $scrutinee = emitOperand($stmt->scrutinee, $env);
    $mode = phpMatchMode($stmt);
    $boolMatch = isBoolConstructorMatch($stmt, $env);
    $matchScrutinee = match (true) {
        $mode === 'literal' => $scrutinee,
        $boolMatch => $scrutinee,
        default => "(({$scrutinee})[0] ?? null)",
    };
    $exhaustive = $stmt->exhaustive;
    $out = $pad . "return match ({$matchScrutinee}) {\n";

    foreach ($stmt->arms as $arm) {
        $pattern = $arm->pattern;
        $armEnv = clone $env;
        $armEnv->patternLocals = buildPatternLocals($pattern, $scrutinee) + $env->patternLocals;
        $armEnv->matchScrutineeOperand = $scrutinee;
        $expr = emitMatchArmExpression($arm, $armEnv, $indent);
        $matchKey = emitPhpMatchKey($pattern, $env, $boolMatch);
        $out .= $pad . "    {$matchKey} => {$expr},\n";
        if ($matchKey === 'default') {
            $exhaustive = true;
        }
    }

    if (! $exhaustive) {
        $out .= $pad . "    default => throw new \\RuntimeException('non-exhaustive match'),\n";
    }

    $out .= $pad . '}';

    return $out . ";\n";
}

function emitPhpMatchKey(IR\Pattern $pattern, EmitEnv $env, bool $boolMatch): string
{
    if (isCatchAllPattern($pattern)) {
        return 'default';
    }

    if ($pattern instanceof IR\PatLit) {
        return emitPatLitPhp($pattern->value);
    }

    if ($pattern instanceof IR\PatChar) {
        return (string) $pattern->value;
    }

    $tag = patternConstructorName($pattern, $env);

    return $boolMatch
        ? (($env->boolConstructors[$tag] ?? false) ? 'true' : 'false')
        : phpSingleQuoted($tag);
}

function emitMatchArmExpression(IR\MatchArm $arm, EmitEnv $env, int $indent): string
{
    $items = $arm->body->items;
    if ($items === []) {
        return 'null';
    }

    $last = $items[\count($items) - 1];
    if (! ($last instanceof IR\Ret)) {
        throw new \RuntimeException('match arm body must end with ret');
    }

    if (\count($items) === 1) {
        return emitFunctionReturn($last->value, $env);
    }

    $fused = returnedCall($items[\count($items) - 2], $last, $env->tempReads);

    $innerPad = \str_repeat('    ', $indent + 2);
    $inner = '';
    foreach (\array_slice($items, 0, $fused !== null ? -2 : -1) as $item) {
        $inner .= emitStmt($item, $indent + 2, $env);
    }
    $inner .= $innerPad . 'return '
        . ($fused !== null ? emitReturnedCall($fused, $env) : emitFunctionReturn($last->value, $env)) . ";\n";

    $uses = matchArmClosureUses($arm->body, $env);
    $useClause = $uses === [] ? '' : ' use (' . \implode(', ', $uses) . ')';

    return '(function ()' . $useClause . " {\n{$inner}" . \str_repeat('    ', $indent + 1) . '})()';
}

/** @param array<string, mixed> $env Codegen context map @return list<string> */
function matchArmClosureUses(IR\Block $block, EmitEnv $env): array
{
    $definedTemps = [];
    $definedLocals = [];
    foreach ($block->items as $item) {
        $dest = stmtDefinedTemp($item);
        if ($dest !== null) {
            $definedTemps[$dest] = true;
        }
        if ($item instanceof IR\Let) {
            $definedLocals[$item->name] = true;
        }
    }

    $patternLocals = $env->patternLocals;
    $captured = [];
    if ($patternLocals !== [] && isset($env->matchScrutineeOperand)) {
        $scrut = $env->matchScrutineeOperand;
        if (\is_string($scrut) && \str_starts_with($scrut, '$')) {
            $captured[\substr($scrut, 1)] = $scrut;
        }
    }
    $params = $env->functionParams;
    if ($params !== []) {
        $paramSet = \array_fill_keys($params, true);
        foreach ($block->items as $item) {
            foreach (stmtReferencedLocals($item) as $name) {
                if (isset($paramSet[$name])) {
                    $captured[$name] = '$' . mangleVar($name);
                }
            }
        }
    }

    foreach ($block->items as $item) {
        foreach (stmtReferencedLocals($item) as $name) {
            if (isset($captured[$name]) || isset($definedLocals[$name]) || isset($patternLocals[$name])) {
                continue;
            }

            $captured[$name] = '$' . mangleVar($name);
        }

        foreach (stmtReferencedTemps($item) as $tempId) {
            if (isset($definedTemps[$tempId])) {
                continue;
            }

            $key = '__t' . $tempId;
            $captured[$key] = phpTemp($tempId);
        }
    }

    return \array_values($captured);
}

function stmtDefinedTemp(IR\Stmt $stmt): ?int
{
    return match ($stmt::class) {
        IR\Assign::class,
        IR\Call::class,
        IR\CallValue::class,
        IR\DictCall::class,
        IR\MatchStmt::class,
        IR\IoAssignAction::class => $stmt->dest,
        IR\IoRun::class, IR\IoCall::class => $stmt->dest,
        default => null,
    };
}

/** @return list<string> */
function stmtReferencedLocals(IR\Stmt $stmt): array
{
    return match ($stmt::class) {
        IR\Ret::class => operandReferencedLocals($stmt->value),
        IR\Call::class => \array_merge(...\array_map(operandReferencedLocals(...), $stmt->args)),
        IR\CallValue::class => [...operandReferencedLocals($stmt->callee), ...\array_merge(...\array_map(operandReferencedLocals(...), $stmt->args))],
        IR\DictCall::class => [...operandReferencedLocals($stmt->evidence), ...\array_merge([], ...\array_map(operandReferencedLocals(...), $stmt->args))],
        IR\Assign::class => operandReferencedLocals($stmt->value),
        IR\Let::class => operandReferencedLocals($stmt->value),
        IR\MatchStmt::class => matchStmtReferencedLocals($stmt),
        default => [],
    };
}

/** @return list<string> */
function matchStmtReferencedLocals(IR\MatchStmt $stmt): array
{
    $locals = operandReferencedLocals($stmt->scrutinee);
    foreach ($stmt->arms as $arm) {
        $bound = patternBoundNames($arm->pattern);
        foreach ($arm->guards as $guard) {
            $guardLocals = operandReferencedLocals($guard->cond);
            foreach ($guard->prep->items as $prepItem) {
                foreach (stmtReferencedLocals($prepItem) as $name) {
                    $guardLocals[] = $name;
                }
            }
            foreach ($guardLocals as $name) {
                if (! isset($bound[$name])) {
                    $locals[] = $name;
                }
            }
        }
        foreach ($arm->body->items as $item) {
            foreach (stmtReferencedLocals($item) as $name) {
                if (! isset($bound[$name])) {
                    $locals[] = $name;
                }
            }
        }
    }

    return $locals;
}

/** @return list<int> */
function stmtReferencedTemps(IR\Stmt $stmt): array
{
    return match ($stmt::class) {
        IR\Ret::class => operandReferencedTemps($stmt->value),
        IR\Call::class => $stmt->args === [] ? [] : \array_merge(...\array_map(operandReferencedTemps(...), $stmt->args)),
        IR\CallValue::class => [...operandReferencedTemps($stmt->callee), ...($stmt->args === [] ? [] : \array_merge(...\array_map(operandReferencedTemps(...), $stmt->args)))],
        IR\DictCall::class => [...operandReferencedTemps($stmt->evidence), ...($stmt->args === [] ? [] : \array_merge(...\array_map(operandReferencedTemps(...), $stmt->args)))],
        IR\Assign::class => operandReferencedTemps($stmt->value),
        IR\Let::class => operandReferencedTemps($stmt->value),
        IR\MatchStmt::class => matchStmtReferencedTemps($stmt),
        default => [],
    };
}

/** @return list<int> */
function matchStmtReferencedTemps(IR\MatchStmt $stmt): array
{
    $temps = operandReferencedTemps($stmt->scrutinee);
    foreach ($stmt->arms as $arm) {
        foreach ($arm->guards as $guard) {
            $temps = [...$temps, ...operandReferencedTemps($guard->cond)];
            foreach ($guard->prep->items as $prepItem) {
                $temps = [...$temps, ...stmtReferencedTemps($prepItem)];
            }
        }
        foreach ($arm->body->items as $item) {
            $temps = [...$temps, ...stmtReferencedTemps($item)];
        }
    }

    return $temps;
}

/** @return list<string> */
function operandReferencedLocals(IR\Operand $operand): array
{
    $locals = [];
    IR\Visit\walkOperand($operand, static function (IR\Operand $op) use (&$locals): void {
        if ($op instanceof IR\Local) {
            $locals[] = $op->name;
        }
    });

    return $locals;
}

/** @return list<int> */
function operandReferencedTemps(IR\Operand $operand): array
{
    $temps = [];
    IR\Visit\walkOperand($operand, static function (IR\Operand $op) use (&$temps): void {
        if ($op instanceof IR\Temp) {
            $temps[] = $op->id;
        }
    });

    return $temps;
}

function emitMatchIo(IR\Stmt $stmt, int $indent, EmitEnv $env): string
{
    $pad = \str_repeat('    ', $indent);
    $scrutinee = emitOperand($stmt->scrutinee, $env);
    $arms = $stmt->arms;
    $out = '';
    $exhaustive = ($stmt->exhaustive)
        || (isCatchAllPattern($arms[\count($arms) - 1]->pattern)
            && ($arms[\count($arms) - 1]->guards) === []);

    foreach ($arms as $arm) {
        if ($arm->guards !== []) {
            return emitGuardedIfMatch($stmt, $indent, $env, $scrutinee, $exhaustive);
        }
    }

    foreach ($arms as $i => $arm) {
        $isLast = $i === \count($arms) - 1;
        $catchAll = isCatchAllPattern($arm->pattern) && ($arm->guards) === [];

        if ($i === 0) {
            $prefix = 'if';
        } elseif ($isLast && $exhaustive && $catchAll) {
            $prefix = 'else';
        } else {
            $prefix = 'elseif';
        }

        if ($prefix === 'else') {
            $out .= $pad . "else {\n";
        } else {
            $cond = emitMatchArmCondition($arm, $scrutinee, $env);
            $out .= $pad . "{$prefix} ({$cond}) {\n";
        }

        $out .= emitPatternBindings($arm->pattern, $scrutinee, $indent + 1, $env);
        $out .= emitBlock($arm->body, $indent + 1, $env);
        $out .= $pad . "}\n";
    }

    if (! $exhaustive) {
        $out .= $pad . "else {\n";
        $out .= $pad . "    throw new \\RuntimeException('non-exhaustive match');\n";
        $out .= $pad . "}\n";
    }

    return $out;
}

/**
 * A guarded match, emitted as independent arms instead of an if/elseif chain.
 *
 * A guard reads what its own pattern bound (`describe (x : _) | x > 0 = …`), so
 * it is tested after the bindings, not as part of the arm's condition. That also
 * means a guard that fails has to try the *following* clause, exactly like a
 * failed pattern -- so the arms are a sequence, each one leaving through the
 * join when it takes its body and falling through to the next when it does not.
 */
function emitGuardedIfMatch(IR\Stmt $stmt, int $indent, EmitEnv $env, string $scrutinee, bool $exhaustive): string
{
    $pad = \str_repeat('    ', $indent);
    $out = $pad . "do {\n";

    foreach ($stmt->arms as $arm) {
        $out .= $pad . '    if (' . emitPatternTest($arm->pattern, $scrutinee, $env) . ") {\n";
        $out .= emitPatternBindings($arm->pattern, $scrutinee, $indent + 2, $env);

        if ($arm->guards === []) {
            $out .= emitBlock($arm->body, $indent + 2, $env);
            $out .= $pad . "        break;\n";
        } else {
            $conditions = [];
            foreach ($arm->guards as $guard) {
                foreach ($guard->prep->items as $prepItem) {
                    $out .= emitStmt($prepItem, $indent + 2, $env);
                }
                $conditions[] = emitOperand($guard->cond, $env);
            }
            $out .= $pad . '        if (' . joinPatternTests($conditions) . ") {\n";
            $out .= emitBlock($arm->body, $indent + 3, $env);
            $out .= $pad . "            break;\n";
            $out .= $pad . "        }\n";
        }

        $out .= $pad . "    }\n";
    }

    if (! $exhaustive) {
        $out .= $pad . "    throw new \\RuntimeException('non-exhaustive match');\n";
    }

    return $out . $pad . "} while (false);\n";
}

