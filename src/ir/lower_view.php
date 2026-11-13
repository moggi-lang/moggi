<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Modules\CheckedPattern;
use Moggi\Modules\PatternShape;
use Moggi\Syntax\Ast;

/**
 * Lowering for view patterns.
 *
 * A target tests patterns structurally, and a view is not one of those tests: it
 * is a computation applied to the value being matched, followed by a test of its
 * result. No backend has to learn that. Lowering compiles a clause that uses a
 * view into a nest of one-level matches whose failure arm runs the next clause,
 * which is exactly the fall-through a failed constructor test already has.
 *
 * A view at the root costs no match at all: the expression is applied to the
 * value and the sub-pattern is attempted against the result. A view under a
 * constructor splits that level — the arguments that contain a view become fresh
 * variables, the shallow pattern binds them from their fields, and each one is
 * attempted in turn. Every level therefore tests only patterns the backends
 * already know.
 */

/** Whether a pattern subtree contains a view. */
function patternHasView(CheckedPattern $term, LowerCtx $ctx): bool
{
    if ($term->shape === PatternShape::View) {
        return true;
    }

    foreach ($term->children as $id) {
        if (patternHasView(patternTermById($id, $ctx, $term), $ctx)) {
            return true;
        }
    }

    foreach ($term->fields as $field) {
        if (patternHasView(patternTermById($field['id'], $ctx, $term), $ctx)) {
            return true;
        }
    }

    return false;
}

/**
 * Bind a pattern that may contain views, in a position with no failure.
 *
 * A `let`, a `where` member or a `do` binding assumes its pattern matches; a
 * constructor pattern there is already just field projection. A view in one is
 * the same: apply its expression, bind the sub-pattern to the result, and keep
 * going, without a test and without a fall-through.
 */
function bindPatternWithViews(Ast\AstNode $site, Operand $value, LowerCtx $ctx): void
{
    $view = materializeSite($site, $ctx);
    $term = plannedPattern($view, $ctx);

    if ($term->shape === PatternShape::View) {
        [$stmts, $result] = applyView($view, $value, $ctx);
        \array_push($ctx->items, ...$stmts);
        bindPatternWithViews(viewPatternSite($view)->pattern, $result, $ctx);

        return;
    }

    if (! patternHasView($term, $ctx)) {
        bindPattern(lowerPatternTerm($term, $ctx), $value, $ctx);

        return;
    }

    [$shallow, $steps] = splitFrontier($term, $view, $ctx);
    bindPattern($shallow, $value, $ctx);

    foreach ($steps as $step) {
        $bound = $ctx->env[$step['placeholder']] ?? throw new \RuntimeException(
            "the deferred view at line " . $step['site']->line . " has no bound value",
        );
        bindPatternWithViews($step['site'], $bound, $ctx);
    }
}

/**
 * A view's expression applied to the value it was matched against.
 *
 * The statements are returned rather than emitted: the caller places them inside
 * the arm that owns the viewed value, never before the match that binds it.
 *
 * @return array{0: list<Stmt>, 1: Operand}
 */
function applyView(Ast\AstNode $site, Operand $value, LowerCtx $ctx): array
{
    $view = viewPatternSite($site);

    $saved = $ctx->items;
    $ctx->items = [];

    try {
        $callee = lowerExpr($view->expression, $ctx);
        $dest = freshTemp($ctx);
        $name = freshLocalName($ctx, '_viewed');
        $ctx->items[] = new CallValue($callee, [$value], $dest);
        $ctx->items[] = new Let($name, new Temp($dest));

        return [$ctx->items, new Local($name)];
    } finally {
        $ctx->items = $saved;
    }
}

function viewPatternSite(Ast\AstNode $site): Ast\PatView
{
    return $site instanceof Ast\PatView
        ? $site
        : throw new \RuntimeException('a view pattern term was published from `' . $site::class . '`');
}

/**
 * One clause of a `case` whose patterns contain views, as a match chain.
 *
 * @param list<array{site: Ast\AstNode, body: Ast\AstNode, guard: ?Ast\AstNode}> $clauses
 */
function clauseChain(array $clauses, int $index, Operand $value, LowerCtx $ctx, int $dest): Block
{
    if (! isset($clauses[$index])) {
        return new Block([]);
    }

    $clause = $clauses[$index];
    $onFailure = static fn (): Block => clauseChain($clauses, $index + 1, $value, $ctx, $dest);

    $onSuccess = static function (LowerCtx $armCtx, Pattern $pattern) use ($clause, $dest): array {
        if ($clause['guard'] === null) {
            $guards = [];
        } else {
            $bindings = $armCtx->items;
            $isVarPattern = $pattern instanceof PatVar;
            $armCtx->items = [];
            $guards = [lowerArmGuard($clause['guard'], $armCtx, $isVarPattern ? $bindings : [])];
            $armCtx->items = $isVarPattern ? [] : $bindings;
        }
        $body = lowerExpr($clause['body'], $armCtx);

        return [[...$armCtx->items, new Assign($dest, $body)], $guards];
    };

    return patternAttempt($clause['site'], $value, $ctx, $clause['body'], $dest, $onSuccess, $onFailure);
}

/**
 * Attempt one pattern against a value, continuing on a match and falling through
 * to the failure continuation when the value does not match.
 *
 * @param callable(LowerCtx, Pattern): array{0: list<Stmt>, 1: list<Guard>} $onSuccess
 * @param callable(): Block $onFailure
 */
function patternAttempt(
    Ast\AstNode $site,
    Operand $value,
    LowerCtx $ctx,
    Ast\AstNode $bodyAst,
    int $dest,
    callable $onSuccess,
    callable $onFailure,
): Block {
    $term = plannedPattern($site, $ctx);

    if ($term->shape === PatternShape::View) {
        [$stmts, $result] = applyView($site, $value, $ctx);
        $inner = patternAttempt(
            viewPatternSite($site)->pattern,
            $result,
            $ctx,
            $bodyAst,
            $dest,
            $onSuccess,
            $onFailure,
        );

        return new Block([...$stmts, ...$inner->items]);
    }

    if (! patternHasView($term, $ctx)) {
        return matchedPattern($term, $value, $ctx, $bodyAst, $dest, $onSuccess, $onFailure);
    }

    [$shallow, $steps] = splitFrontier($term, $site, $ctx);

    $armCtx = newArmCtx($ctx, $bodyAst);
    bindPattern($shallow, $value, $armCtx);
    $bindings = $armCtx->items;
    $armCtx->items = [];
    $body = new Block([
        ...$bindings,
        ...attemptSteps($steps, 0, $armCtx, $bodyAst, $dest, $onSuccess, $onFailure)->items,
    ]);

    $arms = [new MatchArm(eraseNewtypesInPattern($shallow, $armCtx->newtypes), $body)];
    $failure = $onFailure();
    if ($failure->items !== []) {
        $arms[] = new MatchArm(new PatWild(), $failure);
    }

    return new Block([new MatchStmt($value, $arms, $dest, false)]);
}

/**
 * The one-level attempt for a pattern whose views have all been consumed.
 *
 * @param callable(LowerCtx, Pattern): array{0: list<Stmt>, 1: list<Guard>} $onSuccess
 * @param callable(): Block $onFailure
 */
function matchedPattern(
    CheckedPattern $term,
    Operand $value,
    LowerCtx $ctx,
    Ast\AstNode $bodyAst,
    int $dest,
    callable $onSuccess,
    callable $onFailure,
): Block {
    $armCtx = newArmCtx($ctx, $bodyAst);
    $pattern = lowerPatternTerm($term, $armCtx);
    bindPattern($pattern, $value, $armCtx);

    [$items, $guards] = $onSuccess($armCtx, $pattern);

    $arms = [new MatchArm(eraseNewtypesInPattern($pattern, $armCtx->newtypes), new Block($items), $guards)];
    $failure = $onFailure();
    if ($failure->items !== []) {
        $arms[] = new MatchArm(new PatWild(), $failure);
    }

    return new Block([new MatchStmt($value, $arms, $dest, false)]);
}

/**
 * Attempt the deferred views of one structural level, in the order they were
 * found, then continue with the clause.
 *
 * Every step is attempted against the local the shallow pattern bound for it; a
 * step that does not match falls through to the clause's failure continuation,
 * exactly as the level that deferred it would.
 *
 * @param list<array{placeholder: string, site: Ast\AstNode}> $steps
 * @param callable(LowerCtx, Pattern): array{0: list<Stmt>, 1: list<Guard>} $onSuccess
 * @param callable(): Block $onFailure
 */
function attemptSteps(
    array $steps,
    int $index,
    LowerCtx $ctx,
    Ast\AstNode $bodyAst,
    int $dest,
    callable $onSuccess,
    callable $onFailure,
): Block {
    $step = $steps[$index];
    $value = $ctx->env[$step['placeholder']] ?? throw new \RuntimeException(
        "the deferred view at line " . $step['site']->line . " has no bound value",
    );

    if (! isset($steps[$index + 1])) {
        return patternAttempt($step['site'], $value, $ctx, $bodyAst, $dest, $onSuccess, $onFailure);
    }

    $next = static function (LowerCtx $nextCtx, Pattern $pattern) use ($steps, $index, $bodyAst, $dest, $onSuccess, $onFailure): array {
        $block = attemptSteps($steps, $index + 1, $nextCtx, $bodyAst, $dest, $onSuccess, $onFailure);

        return [$block->items, []];
    };

    return patternAttempt($step['site'], $value, $ctx, $bodyAst, $dest, $next, $onFailure);
}

/**
 * Split one structural level into the pattern that can be tested now and the
 * views its arguments leave for later.
 *
 * An argument (or record field) that contains a view is replaced by a fresh
 * variable pattern, which the shallow pattern binds from that field; the
 * argument itself becomes a step, attempted once the shallow match succeeded.
 * Everything else is lowered as it always was.
 *
 * @return array{0: Pattern, 1: list<array{placeholder: string, site: Ast\AstNode}>}
 */
function splitFrontier(CheckedPattern $term, Ast\AstNode $site, LowerCtx $ctx): array
{
    $steps = [];

    switch ($term->shape) {
        case PatternShape::Con:
            $name = $term->name ?? throw new \RuntimeException("the constructor pattern at {$term->origin} publishes no name");
            if (! $site instanceof Ast\PatCon) {
                throw new \RuntimeException("the constructor pattern term was published from `" . $site::class . "`");
            }
            $args = [];
            foreach ($term->children as $index => $id) {
                $args[] = frontierChild(
                    patternTermById($id, $ctx, $term),
                    $site->args[$index] ?? throw new \RuntimeException("the constructor pattern at {$term->origin} publishes no site for argument #{$index}"),
                    $ctx,
                    $steps,
                );
            }

            return [new PatCon($ctx->constructorRenames[$name] ?? $name, $args), $steps];

        case PatternShape::Tuple:
            if (! $site instanceof Ast\PatTuple) {
                throw new \RuntimeException("the tuple pattern term was published from `" . $site::class . "`");
            }
            $elements = [];
            foreach ($term->children as $index => $id) {
                $elements[] = frontierChild(
                    patternTermById($id, $ctx, $term),
                    $site->elements[$index] ?? throw new \RuntimeException("the tuple pattern at {$term->origin} publishes no site for element #{$index}"),
                    $ctx,
                    $steps,
                );
            }

            return [new PatTuple($elements), $steps];

        case PatternShape::Cons:
            if (! $site instanceof Ast\PatCons) {
                throw new \RuntimeException("the list pattern term was published from `" . $site::class . "`");
            }

            return [
                new PatCons(
                    frontierChild(patternTermById($term->children[0], $ctx, $term), $site->head, $ctx, $steps),
                    frontierChild(patternTermById($term->children[1], $ctx, $term), $site->tail, $ctx, $steps),
                ),
                $steps,
            ];

        case PatternShape::Record:
            $name = $term->name ?? throw new \RuntimeException("the record pattern at {$term->origin} publishes no name");
            $order = $ctx->data[$name] ?? throw new \RuntimeException("record pattern `{$name}`: unknown field order");
            if (! $site instanceof Ast\PatRecord) {
                throw new \RuntimeException("the record pattern term was published from `" . $site::class . "`");
            }

            $sites = [];
            foreach ($site->fields as $field) {
                $sites[$field->name] = $field->pattern;
            }
            $ids = [];
            foreach ($term->fields as $field) {
                $ids[$field['name']] = $field['id'];
            }

            $args = [];
            foreach ($order as $fieldName) {
                $id = $ids[$fieldName] ?? null;
                if ($id === null) {
                    $args[] = new PatWild();

                    continue;
                }
                $args[] = frontierChild(
                    patternTermById($id, $ctx, $term),
                    $sites[$fieldName] ?? throw new \RuntimeException("record pattern `{$name}` publishes no site for field `{$fieldName}`"),
                    $ctx,
                    $steps,
                );
            }

            return [new PatCon($ctx->constructorRenames[$name] ?? $name, $args), $steps];

        default:
            throw new \RuntimeException(
                "the pattern at {$term->origin} has views but no structural level to split",
            );
    }
}

/**
 * A structural argument as the shallow pattern sees it: lowered in place when it
 * has no view, and a fresh variable plus a deferred step when it has one.
 *
 * @param list<array{placeholder: string, site: Ast\AstNode}> $steps
 */
function frontierChild(CheckedPattern $child, Ast\AstNode $site, LowerCtx $ctx, array &$steps): Pattern
{
    if (! patternHasView($child, $ctx)) {
        return lowerPatternTerm($child, $ctx);
    }

    $placeholder = freshLocalName($ctx, '_view');
    $steps[] = ['placeholder' => $placeholder, 'site' => $site];

    return new PatVar($placeholder);
}
