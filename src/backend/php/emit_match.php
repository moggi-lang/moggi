<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;

use Moggi\IR;

use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\IR\Visit\mapOperandChildren;

function emitMatchStmt(IR\Stmt $stmt, int $indent, array $ctx, bool $isReturn): string
{
    $depth = (int) ($GLOBALS['__moggi_php_match_nest'] ?? 0);
    $GLOBALS['__moggi_php_match_nest'] = $depth + 1;
    try {
        $body = canUsePhpMatch($stmt, $ctx)
            ? emitPhpMatch($stmt, $indent, $ctx, $isReturn)
            : emitIfMatch($stmt, $indent, $ctx);
        // Nested matches often reuse specialize binders (`__f0`, …). Save any
        // already-bound outer `__f*` and restore after this match so later
        // statements in the same arm still see the outer values.
        if ($depth > 0) {
            return emitWithSavedFieldBinders($body, $indent, $depth);
        }

        return $body;
    } finally {
        $GLOBALS['__moggi_php_match_nest'] = $depth;
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
    if (preg_match_all('/\$__f(\d+)(?!\d)/', $body, $m) > 0) {
        foreach ($m[1] as $digits) {
            $used[(int) $digits] = true;
        }
        $used = \array_keys($used);
        sort($used);
    }
    if ($used === []) {
        return $body;
    }

    $pad = str_repeat('    ', $indent);
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

function canUsePhpMatch(IR\Stmt $stmt, array $ctx = []): bool
{
    $mode = null;
    $lastIndex = count($stmt->arms) - 1;
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
            // Newtypes are identity-represented; PHP `match` on tag `[0]` does not apply.
            if (isset($ctx['newtypeConstructors'][patternConstructorName($pattern, $ctx)])) {
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

        if (!patternIsShallowForPhpMatch($pattern)) {
            return false;
        }

        $items = $arm->body->items;
        if ($items === []) {
            return false;
        }

        // PHP `match` arms are expressions. Multi-statement bodies would force
        // IIFE wrappers; prefer if/elseif so those arms stay straight-line.
        if (count($items) !== 1) {
            return false;
        }

        $last = $items[0];
        if (!($last instanceof IR\Ret)) {
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
            if (!($arg instanceof IR\PatWild || $arg instanceof IR\PatVar)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

function isBoolConstructorMatch(IR\Stmt $stmt, array $ctx): bool
{
    $boolArms = 0;

    foreach ($stmt->arms as $arm) {
        // A catch-all arm emits `default`, so it proves nothing about a Bool match and must not
        // switch the arm to the tagged path (`((bool)[0] ?? null)`).
        if (isCatchAllPattern($arm->pattern)) {
            continue;
        }

        if (!($arm->pattern instanceof IR\PatCon) || $arm->pattern->args !== []) {
            return false;
        }

        $name = patternConstructorName($arm->pattern, $ctx);
        if (!\array_key_exists($name, $ctx['boolConstructors'] ?? [])) {
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

function emitPhpMatch(IR\Stmt $stmt, int $indent, array $ctx, bool $isReturn): string
{
    $pad = str_repeat('    ', $indent);
    $scrutinee = emitOperand($stmt->scrutinee, $ctx);
    $mode = phpMatchMode($stmt);
    $boolMatch = isBoolConstructorMatch($stmt, $ctx);
    $matchScrutinee = match (true) {
        $mode === 'literal' => $scrutinee,
        $boolMatch => $scrutinee,
        default => "(({$scrutinee})[0] ?? null)",
    };
    $exhaustive = $stmt->exhaustive;
    $prefix = $isReturn ? 'return ' : phpTemp($stmt->dest) . ' = ';
    $out = $pad . $prefix . "match ({$matchScrutinee}) {\n";

    foreach ($stmt->arms as $arm) {
        $pattern = $arm->pattern;
        // Merge with outer patternLocals so nested matches still inline
        // bindings from enclosing arms (e.g. `Just f -> case mx of ... f x`).
        $armCtx = [
            ...$ctx,
            'patternLocals' => buildPatternLocals($pattern, $scrutinee)
                + ($ctx['patternLocals'] ?? []),
            'matchScrutineeOperand' => $scrutinee,
        ];
        $expr = emitMatchArmExpression($arm, $armCtx, $indent);
        $matchKey = emitPhpMatchKey($pattern, $ctx, $boolMatch);
        $out .= $pad . "    {$matchKey} => {$expr},\n";
        if ($matchKey === 'default') {
            $exhaustive = true;
        }
    }

    if (!$exhaustive) {
        $out .= $pad . "    default => throw new \\RuntimeException('non-exhaustive match'),\n";
    }

    $out .= $pad . '}';

    return $out . ";\n";
}

/** @param int|string $value */
function emitPatLitPhp(int|string $value): string
{
    return \is_string($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
}

function emitPhpMatchKey(IR\Pattern $pattern, array $ctx, bool $boolMatch): string
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

    $tag = patternConstructorName($pattern, $ctx);

    return $boolMatch
        ? (($ctx['boolConstructors'][$tag] ?? false) ? 'true' : 'false')
        : "'{$tag}'";
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

function emitMatchArmExpression(IR\MatchArm $arm, array $ctx, int $indent): string
{
    $items = $arm->body->items;
    if ($items === []) {
        return 'null';
    }

    $last = $items[count($items) - 1];
    if (!($last instanceof IR\Ret)) {
        throw new \RuntimeException('match arm body must end with ret');
    }

    if (count($items) === 1) {
        return emitRetValue($last->value, $ctx);
    }

    $innerPad = str_repeat('    ', $indent + 2);
    $inner = '';
    foreach (\array_slice($items, 0, -1) as $item) {
        $inner .= emitStmt($item, $indent + 2, $ctx);
    }
    $inner .= $innerPad . 'return ' . emitRetValue($last->value, $ctx) . ";\n";

    $uses = matchArmClosureUses($arm->body, $ctx);
    $useClause = $uses === [] ? '' : ' use (' . join(', ', $uses) . ')';

    return '(function ()' . $useClause . " {\n{$inner}" . str_repeat('    ', $indent + 1) . '})()';
}

/** @param array<string, mixed> $ctx Codegen context map @return list<string> */
function matchArmClosureUses(IR\Block $block, array $ctx): array
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

    // Pattern bindings are inlined via patternLocals (e.g. `$ne[2]`), so the
    // scrutinee expression must be captured whenever pattern locals are used.
    $patternLocals = $ctx['patternLocals'] ?? [];
    $captured = [];
    if ($patternLocals !== [] && isset($ctx['matchScrutineeOperand'])) {
        $scrut = $ctx['matchScrutineeOperand'];
        if (\is_string($scrut) && str_starts_with($scrut, '$')) {
            $captured[substr($scrut, 1)] = $scrut;
        }
    }
    $params = $ctx['functionParams'] ?? [];
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

    return array_values($captured);
}

function stmtDefinedTemp(IR\Stmt $stmt): ?int
{
    return match ($stmt::class) {
        IR\Assign::class,
        IR\Binop::class,
        IR\Call::class,
        IR\CallValue::class,
        IR\DictCall::class,
        IR\MatchStmt::class,
        IR\IoAssignAction::class => $stmt->dest,
        IR\IoRun::class, IR\IoMatch::class, IR\IoCall::class => $stmt->dest,
        default => null,
    };
}

/** @return list<string> */
function stmtReferencedLocals(IR\Stmt $stmt): array
{
    return match ($stmt::class) {
        IR\Ret::class => operandReferencedLocals($stmt->value),
        IR\Binop::class => [...operandReferencedLocals($stmt->left), ...operandReferencedLocals($stmt->right)],
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
                if (!isset($bound[$name])) {
                    $locals[] = $name;
                }
            }
        }
        foreach ($arm->body->items as $item) {
            foreach (stmtReferencedLocals($item) as $name) {
                if (!isset($bound[$name])) {
                    $locals[] = $name;
                }
            }
        }
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

/** @return list<int> */
function stmtReferencedTemps(IR\Stmt $stmt): array
{
    return match ($stmt::class) {
        IR\Ret::class => operandReferencedTemps($stmt->value),
        IR\Binop::class => [...operandReferencedTemps($stmt->left), ...operandReferencedTemps($stmt->right)],
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

function emitIfMatch(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $scrutinee = emitOperand($stmt->scrutinee, $ctx);
    $arms = $stmt->arms;
    $out = '';
    $exhaustive = ($stmt->exhaustive)
        || (isCatchAllPattern($arms[count($arms) - 1]->pattern)
            && ($arms[count($arms) - 1]->guards) === []);

    foreach ($arms as $arm) {
        if ($arm->guards !== []) {
            return emitGuardedIfMatch($stmt, $indent, $ctx, $scrutinee, $exhaustive);
        }
    }

    foreach ($arms as $i => $arm) {
        $isLast = $i === count($arms) - 1;
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
            $cond = emitMatchArmCondition($arm, $scrutinee, $ctx);
            $out .= $pad . "{$prefix} ({$cond}) {\n";
        }

        $out .= emitPatternBindings($arm->pattern, $scrutinee, $indent + 1, $ctx);
        $out .= emitBlock($arm->body, $indent + 1, $ctx);
        $out .= $pad . "}\n";
    }

    if (!$exhaustive) {
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
function emitGuardedIfMatch(IR\Stmt $stmt, int $indent, array $ctx, string $scrutinee, bool $exhaustive): string
{
    $pad = str_repeat('    ', $indent);
    // `do { … } while (false)` is the join: an arm leaves it with `break`, and a
    // failed guard falls off its own arm into the next one.
    $out = $pad . "do {\n";

    foreach ($stmt->arms as $arm) {
        $out .= $pad . '    if (' . emitPatternTest($arm->pattern, $scrutinee, $ctx) . ") {\n";
        $out .= emitPatternBindings($arm->pattern, $scrutinee, $indent + 2, $ctx);

        if ($arm->guards === []) {
            $out .= emitBlock($arm->body, $indent + 2, $ctx);
            $out .= $pad . "        break;\n";
        } else {
            $conditions = [];
            foreach ($arm->guards as $guard) {
                // A guard may need statements of its own (`| ok (g x) = …`);
                // they run after the bindings and before the test.
                foreach ($guard->prep->items as $prepItem) {
                    $out .= emitStmt($prepItem, $indent + 2, $ctx);
                }
                $conditions[] = emitOperand($guard->cond, $ctx);
            }
            $out .= $pad . '        if (' . joinPatternTests($conditions) . ") {\n";
            $out .= emitBlock($arm->body, $indent + 3, $ctx);
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

/**
 * Rename pattern binders to unique names and rewrite shallow arm-body locals.
 * Nested MatchStmt/MatchReturn arms are left untouched (freshened when emitted).
 */
function freshenMatchArmBinders(IR\MatchArm $arm): IR\MatchArm
{
    $map = [];
    $id = nextPhpBinderFreshenId();
    $n = 0;
    collectPatternBinderNames($arm->pattern, $map, $id, $n);
    if ($map === []) {
        return $arm;
    }

    $pattern = renamePatternBinders($arm->pattern, $map);
    $guards = IR\Visit\mapGuards(
        $arm->guards,
        static fn (IR\Operand $g): IR\Operand => renameLocalsInOperand($g, $map),
        static fn (IR\Stmt $s): IR\Stmt => renameLocalsInItemsShallow([$s], $map)[0],
    );
    $body = new IR\Block(renameLocalsInItemsShallow($arm->body->items, $map));

    return new IR\MatchArm($pattern, $body, $guards);
}

function nextPhpBinderFreshenId(): int
{
    $n = (int) ($GLOBALS['__moggi_php_binder_freshen'] ?? 0);
    $GLOBALS['__moggi_php_binder_freshen'] = $n + 1;

    return $n;
}

/** @param array<string, string> $map */
function collectPatternBinderNames(IR\Pattern $pattern, array &$map, int $id, int &$n): void
{
    if ($pattern instanceof IR\PatVar) {
        // Only freshen specialize/PE synthetic field binders. Renaming source
        // binders (enc, a, err, …) breaks PHP `match` expression arms and
        // parameter references that share those names.
        if (!str_starts_with($pattern->name, '__f')) {
            return;
        }
        if (!isset($map[$pattern->name])) {
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
    // Reuse collector only for names; discard generated fresh names.
    $tmp = [];
    collectPatternBinderNames($arm->pattern, $tmp, 0, $n);
    foreach ($tmp as $old => $_fresh) {
        $bound[$old] = true;
    }
    $innerMap = [];
    foreach ($map as $old => $fresh) {
        if (!isset($bound[$old])) {
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
        IR\Binop::class => new IR\Binop(
            $stmt->op,
            renameLocalsInOperand($stmt->left, $map),
            renameLocalsInOperand($stmt->right, $map),
            $stmt->dest,
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

function emitMatchArmCondition(IR\MatchArm $arm, string $scrutinee, array $ctx): string
{
    $tests = [emitPatternTest($arm->pattern, $scrutinee, $ctx)];
    foreach ($arm->guards as $guard) {
        $tests[] = emitOperand($guard->cond, $ctx);
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

    return \count($tests) === 1 ? $tests[0] : '(' . \join(' && ', $tests) . ')';
}

function isCatchAllPattern(IR\Pattern $pattern): bool
{
    return match ($pattern::class) {
        IR\PatWild::class, IR\PatVar::class => true,
        IR\PatTuple::class => array_reduce(
            $pattern->elements,
            static fn (bool $carry, IR\Pattern $element): bool => $carry && isCatchAllPattern($element),
            true,
        ),
        default => false,
    };
}

function patternConstructorName(IR\Pattern $pattern, array $ctx): string
{
    $name = $pattern->name;

    return $ctx['constructorRenames'][$name] ?? $name;
}

function emitPatternTest(IR\Pattern $pattern, string $scrutinee, array $ctx): string
{
    if ($pattern instanceof IR\PatCon && $pattern->args === []) {
        $name = patternConstructorName($pattern, $ctx);
        if (\array_key_exists($name, $ctx['boolConstructors'] ?? [])) {
            $value = $ctx['boolConstructors'][$name] ? 'true' : 'false';

            return "{$scrutinee} === {$value}";
        }
    }

    return match ($pattern::class) {
        IR\PatWild::class => 'true',
        IR\PatVar::class => 'true',
        IR\PatLit::class => "{$scrutinee} === " . emitPatLitPhp($pattern->value),
        IR\PatChar::class => "{$scrutinee} === " . $pattern->value,
        IR\PatCon::class => emitConPatternTest($pattern, $scrutinee, $ctx),
        IR\PatTuple::class => emitTuplePatternTest($pattern, $scrutinee, $ctx),
        IR\PatNil::class => "{$scrutinee} === []",
        IR\PatCons::class => joinPatternTests([
            $scrutinee . ' !== []',
            emitPatternTest($pattern->head, $scrutinee . '[0]', $ctx),
            emitPatternTest($pattern->tail, '\array_slice(' . $scrutinee . ', 1)', $ctx),
        ]),
        default => throw new \RuntimeException("unsupported pattern `" . $pattern::class . "` in codegen"),
    };
}

function emitConPatternTest(IR\Pattern $pattern, string $scrutinee, array $ctx): string
{
    $name = patternConstructorName($pattern, $ctx);
    if (isset($ctx['newtypeConstructors'][$name])) {
        $tests = [];
        foreach ($pattern->args as $arg) {
            $tests[] = emitPatternTest($arg, $scrutinee, $ctx);
        }

        return joinPatternTests($tests);
    }

    $tests = ["(({$scrutinee})[0] ?? null) === '{$name}'"];
    foreach ($pattern->args as $i => $arg) {
        $tests[] = emitPatternTest($arg, "{$scrutinee}[" . ($i + 1) . ']', $ctx);
    }

    return joinPatternTests($tests);
}

function emitTuplePatternTest(IR\Pattern $pattern, string $scrutinee, array $ctx): string
{
    $tests = [];
    foreach ($pattern->elements as $i => $element) {
        $tests[] = emitPatternTest($element, "{$scrutinee}[{$i}]", $ctx);
    }

    return joinPatternTests($tests);
}

function emitPatternBindings(IR\Pattern $pattern, string $scrutinee, int $indent, array $ctx = []): string
{
    return match ($pattern::class) {
        IR\PatWild::class, IR\PatLit::class, IR\PatChar::class, IR\PatVar::class => '',
        IR\PatCon::class => emitConPatternBindings($pattern, $scrutinee, $indent, $ctx),
        IR\PatTuple::class => emitTuplePatternBindings($pattern, $scrutinee, $indent, $ctx),
        IR\PatNil::class => '',
        IR\PatCons::class => emitListConsPatternBindings($pattern, $scrutinee, $indent, $ctx),
        default => throw new \RuntimeException("unsupported pattern `" . $pattern::class . "` in codegen"),
    };
}

function emitConPatternBindings(IR\Pattern $pattern, string $scrutinee, int $indent, array $ctx = []): string
{
    $name = patternConstructorName($pattern, $ctx);
    if (isset($ctx['newtypeConstructors'][$name])) {
        return $pattern->args === []
            ? ''
            : emitPatternBinding($pattern->args[0], $scrutinee, $indent, $ctx);
    }

    if ($pattern->args === []) {
        return '';
    }

    // Snapshot before binding: nested Match arms often reuse binder names
    // (`arm RecNest __f0 __f1` then `arm PairI __f0 __f1`). Sequential
    // `$__f0 = $__f0[1]; $__f1 = $__f0[2]` would read the rebound Int as PairI.
    $pad = str_repeat('    ', $indent);
    $snap = nextPhpScrutineeSnapshot();
    $out = $pad . "{$snap} = {$scrutinee};\n";
    foreach ($pattern->args as $i => $arg) {
        $field = "{$snap}[" . ($i + 1) . ']';
        $out .= emitPatternBinding($arg, $field, $indent, $ctx);
    }

    return $out;
}

function emitTuplePatternBindings(IR\Pattern $pattern, string $scrutinee, int $indent, array $ctx = []): string
{
    if ($pattern->elements === []) {
        return '';
    }

    $pad = str_repeat('    ', $indent);
    $snap = nextPhpScrutineeSnapshot();
    $out = $pad . "{$snap} = {$scrutinee};\n";
    foreach ($pattern->elements as $i => $element) {
        $field = "{$snap}[{$i}]";
        $out .= emitPatternBinding($element, $field, $indent, $ctx);
    }

    return $out;
}

function emitListConsPatternBindings(IR\Pattern $pattern, string $scrutinee, int $indent, array $ctx = []): string
{
    $pad = str_repeat('    ', $indent);
    $snap = nextPhpScrutineeSnapshot();
    $out = $pad . "{$snap} = {$scrutinee};\n";
    $head = "{$snap}[0]";
    $tail = "\array_slice({$snap}, 1)";

    return $out
        . emitPatternBinding($pattern->head, $head, $indent, $ctx)
        . emitPatternBinding($pattern->tail, $tail, $indent, $ctx);
}

function nextPhpScrutineeSnapshot(): string
{
    $n = (int) ($GLOBALS['__moggi_php_scrut_snap'] ?? 0);
    $GLOBALS['__moggi_php_scrut_snap'] = $n + 1;

    return '$__scrut_' . $n;
}

function emitPatternBinding(IR\Pattern $pattern, string $source, int $indent, array $ctx = []): string
{
    $pad = str_repeat('    ', $indent);

    return match ($pattern::class) {
        IR\PatWild::class, IR\PatLit::class, IR\PatChar::class => '',
        IR\PatVar::class => $pad . '$' . mangleVar($pattern->name) . " = {$source};\n",
        IR\PatCon::class => emitConPatternBindings($pattern, $source, $indent, $ctx),
        IR\PatTuple::class => emitTuplePatternBindings($pattern, $source, $indent, $ctx),
        IR\PatNil::class => '',
        IR\PatCons::class => emitListConsPatternBindings($pattern, $source, $indent, $ctx),
        default => throw new \RuntimeException("unsupported pattern `" . $pattern::class . "` in codegen"),
    };
}
