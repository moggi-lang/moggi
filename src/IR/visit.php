<?php declare(strict_types=1);

namespace Moggi\IR\Visit;

use Moggi\IR;

/**
 * Rebuild a guard list through `$mapCond` and `$mapStmt`.
 *
 * A guard owns the statements that compute it, so a pass that rewrites an arm's
 * operands or statements must go through here instead of rebuilding the arm from
 * `$arm->guards` by hand: a guard whose condition was rewritten while its
 * preparation was not would read temps that no longer exist.
 *
 * @param list<IR\Guard> $guards
 * @param callable(IR\Operand): IR\Operand $mapCond
 * @param callable(IR\Stmt): IR\Stmt $mapStmt
 * @return list<IR\Guard>
 */
function mapGuards(array $guards, callable $mapCond, callable $mapStmt): array
{
    if ($guards === []) {
        return [];
    }

    return \array_map(
        static fn (IR\Guard $guard): IR\Guard => new IR\Guard(
            new IR\Block(\array_map($mapStmt, $guard->prep->items)),
            $mapCond($guard->cond),
        ),
        $guards,
    );
}

/** @return list<IR\Operand> */
function operandChildren(IR\Operand $operand): array
{
    return match ($operand::class) {
        IR\ExprBinop::class => [$operand->left, $operand->right],
        IR\ExprCall::class => $operand->args,
        IR\ExprCallValue::class => [$operand->callee, ...$operand->args],
        IR\ExprPartial::class => $operand->args,
        IR\Partial::class => $operand->args,
        IR\Intrinsic::class => $operand->args,
        IR\DictMethod::class => [$operand->evidence],
        IR\ListLit::class => $operand->elements,
        IR\ForeignCall::class => $operand->args,
        IR\IoAction::class => [$operand->expr],
        default => [],
    };
}

/**
 * Map a callable over an operand's direct children.
 *
 * Structure-shares: when no child changes (mapped child is identical to the
 * original), the original operand is returned unchanged so callers avoid
 * reallocating unchanged subtrees.
 *
 * @param callable(IR\Operand): IR\Operand $mapChild
 */
function mapOperandChildren(IR\Operand $operand, callable $mapChild): IR\Operand
{
    return match ($operand::class) {
        IR\ExprBinop::class => (function () use ($operand, $mapChild): IR\Operand {
            $left = $mapChild($operand->left);
            $right = $mapChild($operand->right);
            if ($left === $operand->left && $right === $operand->right) {
                return $operand;
            }

            return new IR\ExprBinop($operand->op, $left, $right);
        })(),
        IR\ExprCall::class => (function () use ($operand, $mapChild): IR\Operand {
            [$args, $changed] = mapChildList($operand->args, $mapChild);

            return $changed ? new IR\ExprCall($operand->callee, $args, $operand->srcLoc) : $operand;
        })(),
        IR\ExprCallValue::class => (function () use ($operand, $mapChild): IR\Operand {
            $callee = $mapChild($operand->callee);
            [$args, $changed] = mapChildList($operand->args, $mapChild);
            if (!$changed && $callee === $operand->callee) {
                return $operand;
            }

            return new IR\ExprCallValue($callee, $args, $operand->srcLoc);
        })(),
        IR\ExprPartial::class => (function () use ($operand, $mapChild): IR\Operand {
            [$args, $changed] = mapChildList($operand->args, $mapChild);

            return $changed ? new IR\ExprPartial($operand->fn, $operand->arity, $args) : $operand;
        })(),
        IR\Partial::class => (function () use ($operand, $mapChild): IR\Operand {
            [$args, $changed] = mapChildList($operand->args, $mapChild);

            return $changed ? new IR\Partial($operand->fn, $operand->arity, $args) : $operand;
        })(),
        IR\Intrinsic::class => (function () use ($operand, $mapChild): IR\Operand {
            [$args, $changed] = mapChildList($operand->args, $mapChild);

            return $changed ? new IR\Intrinsic($operand->name, $args, $operand->srcLoc) : $operand;
        })(),
        IR\DictMethod::class => (function () use ($operand, $mapChild): IR\Operand {
            $evidence = $mapChild($operand->evidence);

            return $evidence === $operand->evidence
                ? $operand
                : new IR\DictMethod($evidence, $operand->method);
        })(),
        IR\ListLit::class => (function () use ($operand, $mapChild): IR\Operand {
            [$elements, $changed] = mapChildList($operand->elements, $mapChild);

            return $changed ? new IR\ListLit($elements) : $operand;
        })(),
        IR\ForeignCall::class => (function () use ($operand, $mapChild): IR\Operand {
            [$args, $changed] = mapChildList($operand->args, $mapChild);
            if (!$changed) {
                return $operand;
            }

            return new IR\ForeignCall(
                $operand->backend,
                $operand->kind,
                $operand->path,
                $operand->dispatch,
                $args,
                $operand->classPath,
                $operand->member,
                $operand->ioWrap,
                $operand->phpValueBox,
                $operand->handleBox,
                $operand->handleUnboxArgs,
                $operand->nativeSig,
            );
        })(),
        default => $operand,
    };
}

/**
 * @param list<IR\Operand> $children
 * @param callable(IR\Operand): IR\Operand $mapChild
 * @return array{0: list<IR\Operand>, 1: bool}
 */
function mapChildList(array $children, callable $mapChild): array
{
    $changed = false;
    foreach ($children as $i => $child) {
        $mapped = $mapChild($child);
        if ($mapped !== $child) {
            $children[$i] = $mapped;
            $changed = true;
        }
    }

    return [$children, $changed];
}

/**
 * Bottom-up map over an operand tree: apply `$map` to a node, then recurse into
 * the *mapped* node's children.
 *
 * Callers that replace a node with an expression drawn from elsewhere (e.g.
 * parameter substitution at an inline site) must not use this — remapping into
 * the replacement re-applies `$map` to caller locals that share callee names.
 * Use an explicit children-first walk that returns replacements verbatim instead
 * (see Interproc\substituteOperand).
 *
 * @param callable(IR\Operand): IR\Operand $map
 */
function mapOperandTree(IR\Operand $operand, callable $map, int $depth = 0): IR\Operand
{
    if ($depth > 64) {
        return $operand;
    }

    $operand = $map($operand);

    return mapOperandChildren(
        $operand,
        static fn (IR\Operand $child): IR\Operand => mapOperandTree($child, $map, $depth + 1),
    );
}

/** @param callable(IR\Operand): void $visit */
function walkOperand(IR\Operand $operand, callable $visit, int $depth = 0): void
{
    if ($depth > 64) {
        return;
    }

    $visit($operand);
    foreach (operandChildren($operand) as $child) {
        walkOperand($child, $visit, $depth + 1);
    }
}

/** @param list<IR\Operand> $operands @param callable(IR\Operand): void $visit */
function walkOperands(array $operands, callable $visit): void
{
    foreach ($operands as $operand) {
        walkOperand($operand, $visit);
    }
}

/** @return list<IR\Operand> */
function stmtDirectOperands(IR\Stmt $stmt): array
{
    return match ($stmt::class) {
        IR\Ret::class, IR\Assign::class, IR\Let::class => [$stmt->value],
        IR\Binop::class => [$stmt->left, $stmt->right],
        IR\Call::class => $stmt->args,
        IR\IoCall::class => $stmt->args,
        IR\IoRun::class => [$stmt->action],
        IR\IoThrow::class => [$stmt->exception],
        IR\IoCatch::class => [$stmt->action, $stmt->handler],
        IR\IoFinally::class => [$stmt->action, $stmt->cleanup],
        IR\IoAssignAction::class => [$stmt->result],
        IR\CallValue::class => [$stmt->callee, ...$stmt->args],
        IR\DictCall::class => [$stmt->evidence, ...$stmt->args],
        IR\MatchStmt::class, IR\MatchReturn::class, IR\IoMatch::class => \array_merge(
            [$stmt->scrutinee],
            ...\array_map(
                static fn (IR\MatchArm $arm): array => \array_map(
                    static fn (IR\Guard $guard): IR\Operand => $guard->cond,
                    $arm->guards,
                ),
                $stmt->arms,
            ),
        ),
        IR\TailRecall::class => $stmt->args,
        default => [],
    };
}

/** @return list<IR\Block> */
function stmtNestedBlocks(IR\Stmt $stmt): array
{
    return match ($stmt::class) {
        // Arm bodies and the statements their guards need: both are part of the
        // match for every walk (reachability, liveness, import collection).
        IR\MatchStmt::class, IR\MatchReturn::class, IR\IoMatch::class => \array_merge(
            \array_map(
                static fn ($arm) => $arm->body,
                $stmt->arms,
            ),
            ...\array_map(
                static fn (IR\MatchArm $arm): array => \array_map(
                    static fn (IR\Guard $guard): IR\Block => $guard->prep,
                    $arm->guards,
                ),
                $stmt->arms,
            ),
        ),
        IR\Loop::class => [$stmt->body],
        IR\IoAssignAction::class => [$stmt->body],
        default => [],
    };
}

/** @param callable(IR\Stmt): void $visitStmt @param callable(IR\Operand): void $visitOperand */
function walkBlock(IR\Block $block, callable $visitStmt, callable $visitOperand): void
{
    foreach ($block->items as $item) {
        walkStmt($item, $visitStmt, $visitOperand);
    }
}

/** @param callable(IR\Stmt): void $visitStmt @param callable(IR\Operand): void $visitOperand */
function walkStmt(IR\Stmt $stmt, callable $visitStmt, callable $visitOperand): void
{
    $visitStmt($stmt);
    walkOperands(stmtDirectOperands($stmt), $visitOperand);
    foreach (stmtNestedBlocks($stmt) as $nested) {
        walkBlock($nested, $visitStmt, $visitOperand);
    }
}

/** @param callable(IR\Stmt): void $visitStmt @param callable(IR\Operand): void $visitOperand */
function walkModule(IR\Module $module, callable $visitStmt, callable $visitOperand): void
{
    foreach ($module->functions as $function) {
        walkBlock($function->body, $visitStmt, $visitOperand);
    }
}

/** @return array{callees: array<string, true>, intrinsics: array<string, true>} */
function collectIrCodegenUsage(IR\Module $module): array
{
    $callees = [];
    $intrinsics = [];
    $visitOperand = static function (IR\Operand $operand) use (&$callees, &$intrinsics): void {
        match ($operand::class) {
            IR\FnRef::class => $callees[$operand->name] = true,
            IR\Partial::class => $callees[$operand->fn] = true,
            IR\ExprCall::class => $callees[$operand->callee] = true,
            IR\ExprPartial::class => $callees[$operand->fn] = true,
            IR\Intrinsic::class => $intrinsics[$operand->name] = true,
            default => null,
        };
    };
    $visitStmt = static function (IR\Stmt $stmt) use (&$callees): void {
        if ($stmt instanceof IR\Call) {
            $callees[$stmt->callee] = true;
        }
        if ($stmt instanceof IR\IoCall) {
            $callees[$stmt->callee] = true;
        }
    };

    walkModule($module, $visitStmt, $visitOperand);

    return ['callees' => $callees, 'intrinsics' => $intrinsics];
}

function moduleUsesMoggiRuntime(IR\Module $module): bool
{
    $found = false;
    $noteForeignPath = static function (mixed $path) use (&$found): void {
        if (\is_string($path) && str_starts_with($path, 'Moggi\\')) {
            $found = true;
        }
    };
    $runtimeIntrinsics = [
        'error#' => true,
        'fix#' => true,
        'exceptionWrap#' => true,
        'exceptionUnwrap#' => true,
        'exceptionThrow#' => true,
        'exceptionDisplay#' => true,
    ];
    $visitOperand = static function (IR\Operand $operand) use ($noteForeignPath, $runtimeIntrinsics, &$found): void {
        if ($operand instanceof IR\ForeignCall) {
            $noteForeignPath($operand->path);
            if ($operand->phpValueBox || $operand->handleBox) {
                $found = true;
            }
        }
        if ($operand instanceof IR\Intrinsic && isset($runtimeIntrinsics[$operand->name])) {
            $found = true;
        }
        if ($operand instanceof IR\IoThrow
            || $operand instanceof IR\IoCatch
            || $operand instanceof IR\IoFinally) {
            $found = true;
        }
    };
    $visitStmt = static function (IR\Stmt $stmt) use ($noteForeignPath, $runtimeIntrinsics, &$found): void {
        // IO normalization turns an IO-typed primop call into a statement with
        // the intrinsic as the callee string (`error "x" :: IO a` → `io_call
        // error#`), so runtime-backed intrinsics must be recognised here too.
        if ($stmt instanceof IR\IoCall) {
            if (isset($runtimeIntrinsics[$stmt->callee])) {
                $found = true;
            }
            if ($stmt->foreign !== null) {
                $noteForeignPath($stmt->foreign->path);
                if ($stmt->foreign->phpValueBox || $stmt->foreign->handleBox) {
                    $found = true;
                }
            }
        }
        if ($stmt instanceof IR\IoThrow
            || $stmt instanceof IR\IoCatch
            || $stmt instanceof IR\IoFinally) {
            $found = true;
        }
    };

    walkModule($module, $visitStmt, $visitOperand);

    return $found;
}

/** @return list<string> */
function collectLocalsInOperand(IR\Operand $operand): array
{
    $locals = [];
    walkOperand($operand, static function (IR\Operand $op) use (&$locals): void {
        if ($op instanceof IR\Local) {
            $locals[] = $op->name;
        }
    });

    return $locals;
}

/** @return list<string> */
function collectLocalsInBlock(IR\Block $block): array
{
    $locals = [];
    walkBlock(
        $block,
        static function (IR\Stmt $stmt) use (&$locals): void {
        },
        static function (IR\Operand $op) use (&$locals): void {
            if ($op instanceof IR\Local) {
                $locals[] = $op->name;
            }
        },
    );

    return array_values(array_unique($locals));
}

/**
 * @param callable(string): bool $isLambda
 * @return list<string>
 */
function collectLambdaRefsInBlock(IR\Block $block, callable $isLambda): array
{
    $refs = [];
    walkBlock(
        $block,
        static function (IR\Stmt $stmt) use (&$refs): void {
        },
        static function (IR\Operand $op) use (&$refs, $isLambda): void {
            if ($op instanceof IR\FnRef && $isLambda($op->name)) {
                $refs[] = $op->name;
            }
        },
    );

    return array_values(array_unique($refs));
}

/**
 * @param callable(string): bool $isLambda
 * @return array{locals: list<string>, lambdaRefs: list<string>}
 */
function analyzeBlockForCodegen(IR\Block $block, callable $isLambda): array
{
    $locals = [];
    $lambdaRefs = [];
    walkBlock(
        $block,
        static function (IR\Stmt $stmt) use (&$locals, &$lambdaRefs): void {
        },
        static function (IR\Operand $op) use (&$locals, &$lambdaRefs, $isLambda): void {
            if ($op instanceof IR\Local) {
                $locals[] = $op->name;
            }

            if ($op instanceof IR\FnRef && $isLambda($op->name)) {
                $lambdaRefs[] = $op->name;
            }
        },
    );

    return [
        'locals' => array_values(array_unique($locals)),
        'lambdaRefs' => array_values(array_unique($lambdaRefs)),
    ];
}

/**
 * Names bound by an IR pattern (match-arm binders, including nested
 * constructor / cons / record / tuple sub-patterns).
 *
 * @return list<string>
 */
function irPatternBoundNames(IR\Pattern $pattern): array
{
    if ($pattern instanceof IR\PatVar) {
        return [$pattern->name];
    }
    if ($pattern instanceof IR\PatCon) {
        $names = [];
        foreach ($pattern->args as $arg) {
            foreach (irPatternBoundNames($arg) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
    if ($pattern instanceof IR\PatTuple) {
        $names = [];
        foreach ($pattern->elements as $el) {
            foreach (irPatternBoundNames($el) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
    if ($pattern instanceof IR\PatCons) {
        return [...irPatternBoundNames($pattern->head), ...irPatternBoundNames($pattern->tail)];
    }

    return [];
}

/**
 * Locals referenced in `$block` that are not among `$bound` and not bound by
 * match-arm patterns / Lets inside the block. Used for lambda capture sets:
 * pattern binders (e.g. `arm a :| xs`) must not be treated as free captures.
 *
 * @param array<string, true> $bound
 * @return list<string>
 */
function freeLocalsInBlock(IR\Block $block, array $bound): array
{
    $free = [];
    freeLocalsInItems($block->items, $bound, $free);

    return array_keys($free);
}

/**
 * @param list<IR\Stmt> $items
 * @param array<string, true> $bound
 * @param array<string, true> $free
 */
function freeLocalsInItems(array $items, array $bound, array &$free): void
{
    foreach ($items as $item) {
        if (!($item instanceof IR\Stmt)) {
            continue;
        }
        // A match is walked by hand: each arm binds its own pattern names and
        // its guards.
        if ($item instanceof IR\MatchStmt || $item instanceof IR\MatchReturn || $item instanceof IR\IoMatch) {
            freeLocalsInOperand($item->scrutinee, $bound, $free);
            foreach ($item->arms as $arm) {
                $armBound = $bound;
                foreach (irPatternBoundNames($arm->pattern) as $name) {
                    $armBound[$name] = true;
                }
                foreach ($arm->guards as $guard) {
                    freeLocalsInItems($guard->prep->items, $armBound, $free);
                    freeLocalsInOperand($guard->cond, $armBound, $free);
                }
                freeLocalsInItems($arm->body->items, $armBound, $free);
            }
            continue;
        }
        // A `let` binds its name after its value, so the rest of the block sees
        // it as bound.
        if ($item instanceof IR\Let) {
            freeLocalsInOperand($item->value, $bound, $free);
            $bound[$item->name] = true;
            continue;
        }
        foreach (stmtDirectOperands($item) as $operand) {
            freeLocalsInOperand($operand, $bound, $free);
        }
        foreach (stmtNestedBlocks($item) as $nested) {
            freeLocalsInItems($nested->items, $bound, $free);
        }
    }
}

/** @param array<string, true> $bound @param array<string, true> $free */
function freeLocalsInOperand(IR\Operand $operand, array $bound, array &$free): void
{
    if ($operand instanceof IR\Local) {
        if (!isset($bound[$operand->name])) {
            $free[$operand->name] = true;
        }

        return;
    }

    foreach (operandChildren($operand) as $child) {
        freeLocalsInOperand($child, $bound, $free);
    }
}
