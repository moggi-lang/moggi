<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Modules\CheckedApplication;
use Moggi\Modules\CheckedCase;
use Moggi\Modules\CheckedCaseArm;
use Moggi\Modules\CheckedGuards;
use Moggi\Modules\CheckedLiteral;
use Moggi\Modules\CheckedRecord;
use Moggi\Modules\CheckedEvidenceMethod;
use Moggi\Modules\CheckedEvidenceRef;
use Moggi\Modules\CheckedForeignCall;
use Moggi\Modules\CheckedIntrinsicCall;
use Moggi\Modules\CheckedLambda;
use Moggi\Modules\CheckedLetGroup;
use Moggi\Modules\CheckedTerm;
use Moggi\Modules\CheckedVariable;
use Moggi\Modules\EvidencePlan;
use Moggi\Modules\EvidencePlanKind;
use Moggi\Modules\LiteralShape;
use Moggi\Modules\PatternShape;
use Moggi\Semantics\TypeExpr;
use Moggi\Syntax\Ast;

use function Moggi\Debug\normalizeDisplayPath;

use function Moggi\Modules\intrinsicNameFor;
use function Moggi\Semantics\Exhaustiveness\guardIsConstantTrue;
use function Moggi\Semantics\IntrinsicRegistry\isIoExceptionPrimop;
use function Moggi\Semantics\IntrinsicRegistry\isShortCircuitPrimop;
use function Moggi\Semantics\IntrinsicRegistry\primopForPublicOperator;
use function Moggi\Semantics\IntrinsicRegistry\schemeArity;
use function Moggi\Semantics\IntrinsicRegistry\shortCircuitPrimop;
use function Moggi\Semantics\IntrinsicRegistry\typeSchemes;
use function Moggi\Semantics\IoBoundary\isIoCheckedType;
use function Moggi\Semantics\Kernel\boolFalseCtor;
use function Moggi\Semantics\Kernel\boolTrueCtor;
use function Moggi\Semantics\Types\peelBindingAnnotation;
use function Moggi\Syntax\isIdentifier;

function lowerExpr(Ast\AstNode $expr, LowerCtx $ctx): Operand
{
    return match ($expr::class) {
        Ast\IntegerLit::class,
        Ast\DoubleLit::class,
        Ast\StringLit::class,
        Ast\CharLit::class => lowerLiteral($expr, $ctx),
        Ast\Variable::class => lowerVariable($expr, $ctx),
        Ast\ConstructorRef::class => new FnRef(
            $ctx->constructorRenames[$expr->name] ?? $expr->name,
        ),
        Ast\OperatorRef::class => (static function () use ($expr, $ctx): Operand {
            $primop = primopForPublicOperator($expr->name);
            if ($primop !== null) {
                return lowerIntrinsicValue($primop, [], $expr, $ctx);
            }

            $origin = crossModuleOrigin($expr, $ctx);
            if ($origin !== null) {
                return new FnRef($origin);
            }

            return new FnRef($expr->name);
        })(),
        Ast\Infix::class => lowerInfix($expr, $ctx),
        Ast\Apply::class => lowerApplyFromExpr($expr, $ctx),
        Ast\Tuple::class => lowerTuple($expr->elements, $ctx),
        Ast\ListLit::class => lowerList($expr->elements, $ctx),
        Ast\Lambda::class => lowerCheckedLambda($expr, $ctx),
        Ast\DoExpr::class => lowerDoExpr($expr, $ctx),
        Ast\Let::class, Ast\Where::class => lowerLetGroup($expr, $ctx),
        Ast\CaseExpr::class => lowerCase($expr, $ctx),
        Ast\GuardsExpr::class => lowerGuards($expr, $ctx),
        Ast\RecordCon::class, Ast\RecordUpdate::class => lowerRecord($expr, $ctx),
        Ast\FieldAccess::class => lowerFieldAccess($expr, $ctx),
        Ast\QualifiedRef::class => (static function () use ($expr, $ctx): Operand {
            $intrinsic = plannedIntrinsic($expr, $ctx);
            if ($intrinsic !== null) {
                return lowerIntrinsicValue($intrinsic->intrinsic, [], $expr, $ctx);
            }

            return new FnRef($expr->backendResolved ?? $expr->name);
        })(),
        Ast\TypeAsc::class => lowerExpr($expr->expr, $ctx),
        Ast\EvidenceRef::class => evidencePlanOperand(plannedEvidence($expr, $ctx)->plan, $ctx),
        Ast\EvidenceMethod::class => lowerEvidenceMethod($expr, $ctx),
        Ast\IntrinsicCall::class => (static function () use ($expr, $ctx): Operand {
            $intrinsic = plannedIntrinsic($expr, $ctx)
                ?? throw new \LogicException('an intrinsic call is always a primop site');

            if (isIoExceptionPrimop($intrinsic->intrinsic)) {
                return lowerActionValueToBox($expr, $ctx);
            }

            return lowerIntrinsic($expr, $intrinsic->intrinsic, $ctx);
        })(),
        Ast\ForeignCall::class => lowerForeignCall($expr, $ctx),
        Ast\ExprHole::class => throw new \RuntimeException('typed hole reached IR lowering'),
        default => throw new \RuntimeException(\sprintf(
            'unsupported expression in IR lowering: %s at %s::%s line %d',
            $expr::class,
            $ctx->moduleName,
            $ctx->functionName,
            $expr->line,
        )),
    };
}

function lowerEvidenceMethod(Ast\EvidenceMethod $expr, LowerCtx $ctx): Operand
{
    $term = checkedEvidenceMethod($expr, $ctx);
    $method = new DictMethod(
        evidencePlanOperand($term->plan, $ctx),
        $term->method,
        $term->plan->contextWidth(),
    );

    if ($term->nullary) {
        return lowerDictMethodCallFromValue($method, [], $ctx, srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile));
    }

    return $method;
}

function lowerDoExpr(Ast\DoExpr $expr, LowerCtx $ctx): Operand
{
    if ($expr->desugared === null) {
        throw new \RuntimeException('do expression reached IR lowering before desugaring');
    }

    return lowerExpr($expr->desugared, $ctx);
}

function lowerIntrinsic(Ast\IntrinsicCall $expr, string $intrinsic, LowerCtx $ctx): Intrinsic
{
    $args = [];
    foreach ($expr->args as $arg) {
        $args[] = lowerExpr($arg, $ctx);
    }

    return new Intrinsic(
        $intrinsic,
        $args,
        srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
    );
}

/**
 * The checked term for the primop a site names, or null when the site names none.
 *
 * `null` answers "this node is not a primop site", which is the shape question
 * {@see intrinsicNameFor()} settles once for the producer and for lowering. A site
 * that *is* one and has no term is refused: a primop the record does not plan is a
 * primop decided after publication — including one a lowering pass synthesized
 * itself, which can therefore no longer be lowered at all.
 */
function plannedIntrinsic(Ast\AstNode $site, LowerCtx $ctx): ?CheckedIntrinsicCall
{
    if (intrinsicNameFor($site) === null) {
        return null;
    }

    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedIntrinsicCall) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a primop site"
        . ' (`' . $site::class . "` at line {$site->line}) that its checked-core record does not plan",
    );
}

/**
 * The checked term for a lambda, or a refusal.
 *
 * A lambda's slots and capture set are published facts; without them lowering
 * would have to answer both from the node it is handed, which is what a
 * lowering-built callable cannot offer.
 */
function plannedLambda(Ast\Lambda $site, LowerCtx $ctx): CheckedLambda
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedLambda) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a lambda at line {$site->line}"
        . ' that its checked-core record does not plan',
    );
}

/**
 * The checked term for an application, or a refusal.
 *
 * The one fact lowering needs and cannot derive is how many arguments the applied
 * expression still wants, which the checker settled on the term's own evidence.
 * A site the record does not cover — including one a lowering pass built itself —
 * is refused rather than answered from whichever type the node happens to carry.
 */
function plannedApplication(Ast\Apply $site, LowerCtx $ctx): CheckedApplication
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedApplication) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met an application at line {$site->line}"
        . ' that its checked-core record does not plan',
    );
}

/**
 * A variable use, lowered through the term that says what it refers to.
 *
 * A primop site keeps the intrinsic family's answer: a `Variable` can carry a primop wrapper, and
 * that arm already owns it.
 */
function lowerVariable(Ast\Variable $expr, LowerCtx $ctx): Operand
{
    $intrinsic = plannedIntrinsic($expr, $ctx);
    if ($intrinsic !== null) {
        return lowerIntrinsicValue($intrinsic->intrinsic, [], $expr, $ctx);
    }

    return variableOperand($expr, $ctx);
}

/**
 * The operand a variable use resolves to, without lowering it into a call site.
 *
 * Two kinds of reader ask this — the value arm, and the IO lowering that asks "is this name bound
 * to something I can run" — and both get the same answer, because the answer is the term's. A use
 * whose term names a binder is answered by that binder's operand: the slot the pattern allocated
 * for it, or the entry a recursive group pre-bound. The spelling on the node is not consulted, so
 * two binders of one name cannot answer for each other, and a use the record does not cover is
 * refused rather than resolved from whichever map holds the name.
 */
function variableOperand(Ast\Variable $expr, LowerCtx $ctx): Operand
{
    $term = siteTerm($expr, $ctx);
    if (! $term instanceof CheckedVariable) {
        throw new \RuntimeException(
            "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a variable"
            . " (`{$expr->name}` at line {$expr->line}) that its checked-core record does not plan",
        );
    }

    if ($term->binder !== null) {
        return $ctx->binders[$term->binder]
            ?? throw new \RuntimeException(
                "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a use of `{$expr->name}`"
                . " at line {$expr->line} whose binder this scope does not hold",
            );
    }

    $origin = crossModuleOrigin($expr, $ctx);
    if ($origin !== null) {
        return new FnRef($origin);
    }

    return $ctx->env[$expr->name]
        ?? throw new \RuntimeException(\sprintf('undefined variable `%s` in IR lowering', $expr->name));
}

/**
 * The checked term for one foreign call, or a refusal.
 *
 * The host target, the native signature and the boxing convention were decided when the
 * import was checked; they are read from the term. A foreign site the record does not
 * cover is reported rather than lowered from whatever the node still carries.
 */
function plannedForeignCall(Ast\ForeignCall $site, LowerCtx $ctx): CheckedForeignCall
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedForeignCall) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a foreign call"
        . " (`{$site->name}` at line {$site->line}) that its checked-core record does not plan",
    );
}

function lowerForeignCall(Ast\ForeignCall $expr, LowerCtx $ctx): ForeignCall
{
    $term = plannedForeignCall($expr, $ctx);

    $args = [];
    foreach ($expr->args as $arg) {
        $args[] = lowerExpr($arg, $ctx);
    }

    return foreignCallOperand($term, $args);
}

/** The IR foreign call a resolved term and its already-lowered arguments name. */
function foreignCallOperand(CheckedForeignCall $term, array $args): ForeignCall
{
    return new ForeignCall(
        backend: $term->backend,
        kind: $term->kind,
        path: $term->path,
        dispatch: $term->dispatch,
        args: $args,
        classPath: $term->classPath,
        member: $term->member,
        ioWrap: $term->ioWrap,
        phpValueBox: $term->phpValueBox,
        handleBox: $term->handleBox,
        handleUnboxArgs: $term->handleUnboxArgs,
        nativeSig: $term->nativeSig,
    );
}

/**
 * The checked term for one evidence position, or a refusal.
 *
 * The position carries the id the producer wrote on the final elaboration, and
 * the record answers with the term that id names — not the node's object id and
 * not its origin string. Nothing here reads the node's evidence fields, so a
 * record that does not cover this position is reported instead of papered over.
 */
/**
 * The term published for one site, or null when publication covered none.
 *
 * The one read of the node→term link: an id the producer wrote, resolved in the
 * declaration's own record. Every family's reader starts here, so the lookup and
 * its "no term" answer are spelled once.
 */
function siteTerm(Ast\AstNode $site, LowerCtx $ctx): ?CheckedTerm
{
    $id = $site->checkedTerm;

    return $id === null ? null : ($ctx->terms[$id] ?? null);
}

function plannedEvidence(
    Ast\EvidenceRef|Ast\EvidenceMethod $site,
    LowerCtx $ctx,
): CheckedEvidenceRef|CheckedEvidenceMethod {
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedEvidenceRef || $term instanceof CheckedEvidenceMethod) {
        return $term;
    }

    $class = $site::class;
    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met an evidence site"
        . " (`{$class}` at line {$site->line}) that its checked-core record does not plan",
    );
}

/**
 * The checked term that owns a `case`'s structure, or a refusal.
 *
 * The arms, their order, their guard flattening and the match's totality are published
 * facts; without them lowering would have to read `CaseExpr::$alts` and re-ask each arm
 * whether its body is a `GuardsExpr`. A `case` the record does not cover — one built after
 * publication — is refused rather than answered from whatever the node holds.
 */
function plannedCase(Ast\CaseExpr|Ast\IoCase $site, LowerCtx $ctx): CheckedCase
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedCase) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a `case` at line {$site->line}"
        . ' that its checked-core record does not plan',
    );
}

/**
 * The checked term that owns a binding group's members and order, or a refusal.
 *
 * One term covers the three shapes that carry a binding group: `let`, `where` and a `do`
 * statement's `let`. Their decomposition — which members bind together and in what order —
 * is the answer the check published, not a second derivation lowering runs for itself.
 */
function plannedLetGroup(Ast\AstNode $site, LowerCtx $ctx): CheckedLetGroup
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedLetGroup) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a binding group (`" . $site::class
        . "` at line {$site->line}) that its checked-core record does not plan",
    );
}

/**
 * The checked term that owns a record construction's or update's fields, or a refusal.
 *
 * Both node shapes are one family: a construction is an update with no receiver, and the
 * constructor of either is the checker's answer. A site the record does not cover — one built
 * after publication — is refused rather than read.
 */
function plannedRecord(Ast\RecordCon|Ast\RecordUpdate $site, LowerCtx $ctx): CheckedRecord
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedRecord) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a record (`" . $site::class
        . "` at line {$site->line}) that its checked-core record does not plan",
    );
}

/** The checked term that owns a literal's value, or a refusal. */
function plannedLiteral(Ast\AstNode $site, LowerCtx $ctx): CheckedLiteral
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedLiteral) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a literal (`" . $site::class
        . "` at line {$site->line}) that its checked-core record does not plan",
    );
}

/** The checked term that owns a guard chain's clauses, or a refusal. */
function plannedGuards(Ast\GuardsExpr $site, LowerCtx $ctx): CheckedGuards
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedGuards) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a guard chain at line {$site->line}"
        . ' that its checked-core record does not plan',
    );
}

/** The checked term for a method position, or a refusal when the family is wrong. */
function checkedEvidenceMethod(Ast\EvidenceRef|Ast\EvidenceMethod $site, LowerCtx $ctx): CheckedEvidenceMethod
{
    $term = plannedEvidence($site, $ctx);
    if (! $term instanceof CheckedEvidenceMethod) {
        throw new \RuntimeException(
            "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a method position at line"
            . " {$site->line} whose checked term is " . $term::class,
        );
    }

    return $term;
}

/**
 * Lower one planned evidence decision.
 *
 * A parameter is the local its slot names, a reference is the instance's builder
 * (applied to its own dictionaries when the instance takes any), and a projection
 * acquires its owner dictionary once per block — a dictionary is immutable, so
 * later uses in the same block read the operand the first one produced.
 */
function evidencePlanOperand(EvidencePlan $plan, LowerCtx $ctx): Operand
{
    if ($plan->kind === EvidencePlanKind::Parameter) {
        return new Local($plan->parameter
            ?? throw new \LogicException("an evidence plan for `{$plan->class}` names no dictionary parameter"));
    }

    $args = [];
    foreach ($plan->context as $inner) {
        $args[] = evidencePlanOperand($inner, $ctx);
    }

    $fn = new FnRef(
        $plan->builder
            ?? throw new \LogicException("an evidence plan for `{$plan->class}` names no evidence builder"),
        $plan->class,
        $plan->builderHead,
    );

    if ($plan->kind === EvidencePlanKind::Reference) {
        return $args === [] ? $fn : lowerCall($fn->name, $args, $ctx);
    }

    if ($args !== []) {
        return lowerCall($fn->name, $args, $ctx);
    }

    $bound = $ctx->evidenceBindings[$fn->name] ?? null;
    if ($bound !== null) {
        return $bound;
    }

    $value = lowerCall($fn->name, [], $ctx);
    $ctx->evidenceBindings[$fn->name] = $value;

    return $value;
}

function lowerInfix(Ast\Infix $expr, LowerCtx $ctx): Operand
{
    $intrinsic = plannedIntrinsic($expr, $ctx)?->intrinsic;

    if ($intrinsic !== null && isShortCircuitPrimop($intrinsic)) {
        return lowerShortCircuitAndOr($expr, $intrinsic, $ctx);
    }

    if ($intrinsic !== null) {
        return new Intrinsic($intrinsic, [
            lowerExpr($expr->left, $ctx),
            lowerExpr($expr->right, $ctx),
        ], srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile));
    }

    $args = [lowerExpr($expr->left, $ctx), lowerExpr($expr->right, $ctx)];
    $infixLoc = srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile);

    $named = lowerNamedApply($expr->operator, $args, $ctx, $infixLoc);
    if ($named !== null) {
        return $named;
    }

    if (isIdentifier($expr->operator)) {
        return lowerCall($expr->operator, $args, $ctx, $infixLoc);
    }

    throw new \RuntimeException(\sprintf(
        'operator `%s` reached IR lowering with no instance',
        $expr->operator,
    ));
}

/**
 * `a && b` / `a || b` as the conditional they mean.
 *
 * Both operands are `Bool` by the time an infix resolves to `boolAnd#`/
 * `boolOr#`, so `True`/`False` cover the scrutinee and the match cannot fall
 * through.
 */
function lowerShortCircuitAndOr(Ast\Infix $expr, string $intrinsic, LowerCtx $ctx): Operand
{
    $isAnd = shortCircuitPrimop($intrinsic) === 'bool-and';
    $scrutineeOp = lowerExpr($expr->left, $ctx);
    $dest = freshTemp($ctx);
    $rightCtx = newArmCtx($ctx, $expr->right);
    $arms = [];

    // The match this operator means is the lowering's decision, so its arms are IR. They used to
    // be a `case` over two `Ast\Alt` nodes built here — a `case` the checked core never planned,
    // which is exactly the shape the term boundary now refuses.
    foreach ([boolTrueCtor(), boolFalseCtor()] as $ctor) {
        $takesRight = $isAnd === ($ctor === boolTrueCtor());
        $value = $takesRight
            ? lowerExpr($expr->right, $rightCtx)
            : new FnRef($ctx->constructorRenames[$ctor] ?? $ctor);
        $constructor = $ctx->constructorRenames[$ctor] ?? $ctor;
        $arms[] = new MatchArm(
            new PatCon($constructor, []),
            new Block([...($takesRight ? $rightCtx->items : []), new Assign($dest, $value)]),
            [],
        );
    }

    $ctx->items[] = new MatchStmt($scrutineeOp, $arms, $dest, true);

    return new Temp($dest);
}

function lowerApplyFromExpr(Ast\Apply $expr, LowerCtx $ctx): Operand
{
    $intrinsic = plannedIntrinsic($expr, $ctx);
    if ($intrinsic !== null) {
        $astArgs = [];
        $callee = $expr;
        while ($callee instanceof Ast\Apply) {
            \array_unshift($astArgs, $callee->argument);
            $callee = $callee->function;
        }

        return lowerIntrinsicValue($intrinsic->intrinsic, $astArgs, $expr, $ctx);
    }

    $partial = lowerDictMethodPartial($expr, plannedApplication($expr, $ctx), $ctx);
    if ($partial !== null) {
        return $partial;
    }

    return lowerApply($expr->function, $expr->argument, $ctx);
}

/**
 * A class method that is applied to fewer arguments than it takes (`(+) 1`, the
 * unsectioned form) has no saturated `DictCall` to lower to: the dictionary slot
 * is a function of the method's full arity. The missing parameters are
 * eta-expanded into a lambda, the same way an unsaturated primop is (see
 * {@see lowerIntrinsicValue}); a saturated call still lowers to a plain
 * `DictCall` with no closure.
 *
 * The missing count is the application term's (`remainingArity`), and the lambda's
 * body is IR — the checked application applied to the new slots — so no
 * post-publication node is built for it.
 */
function lowerDictMethodPartial(Ast\Apply $expr, CheckedApplication $term, LowerCtx $ctx): ?Operand
{
    $callee = $expr->function;
    while ($callee instanceof Ast\Apply) {
        $callee = $callee->function;
    }

    if (! ($callee instanceof Ast\EvidenceMethod)) {
        return null;
    }

    $methodArity = checkedEvidenceMethod($callee, $ctx)->arity;
    $missing = $term->remainingArity;
    if ($missing <= 0 && $methodArity !== null) {
        $applied = 0;
        $spine = $expr;
        while ($spine instanceof Ast\Apply) {
            ++$applied;
            $spine = $spine->function;
        }
        $missing = $methodArity - $applied;
    }
    if ($missing <= 0) {
        return null;
    }

    $paramNames = [];
    for ($i = 0; $i < $missing; ++$i) {
        $paramNames[] = 'methodArg' . $i . '_' . $ctx->state->nextLambda;
    }

    $argNodes = [];
    $spine = $expr;
    while ($spine instanceof Ast\Apply) {
        \array_unshift($argNodes, $spine->argument);
        $spine = $spine->function;
    }

    return lowerLoweredLambda(
        $paramNames,
        freeVarsExpr($expr, [], evidenceParameterFromTerms($ctx), $ctx->terms),
        static function (LowerCtx $fnCtx) use ($callee, $argNodes, $paramNames): Operand {
            $args = [];
            foreach ($argNodes as $argNode) {
                $args[] = lowerExpr($argNode, $fnCtx);
            }
            foreach ($paramNames as $param) {
                $args[] = new Local($param);
            }

            return lowerApplySpine($callee, $args, $fnCtx);
        },
        null,
        $ctx,
    );
}

/**
 * How many user-facing arrows an expression's type still has -- the arguments
 * it is still waiting for. 0 when the type is not known here.
 *
 * A term's type is the checker's internal type; a declaration's signature is
 * still the surface type it was written as. Both carry the arrow spine, so the
 * one counting here does not care which it was handed.
 */
function remainingArity(Ast\TypeNode|TypeExpr\Type|null $type): int
{
    $remaining = 0;
    while (true) {
        if ($type instanceof Ast\TypeArrow) {
            $type = $type->to;
        } elseif ($type instanceof TypeExpr\TArrow) {
            $type = $type->to;
        } else {
            return $remaining;
        }
        ++$remaining;
    }
}

/**
 * Lower a primop (`#`) in value position. A primop exists in the IR only as a
 * saturated `Intrinsic`, so an unsaturated reference is eta-expanded into a
 * lambda that applies it — `intAdd#` becomes `\a b -> intAdd# a b` and a
 * partial application `intAdd# 1` becomes `\b -> intAdd# 1 b`. That is what
 * makes bare aliases (`fix = fix#`, `not = boolNot#`), primops passed as
 * arguments (`map boolNot# xs`) and partial applications first-class values.
 * Saturated applications still lower to a plain `Intrinsic` (no closure).
 *
 * @param list<Ast\AstNode> $astArgs arguments already supplied (possibly none)
 */
function lowerIntrinsicValue(string $name, array $astArgs, Ast\AstNode $node, LowerCtx $ctx): Operand
{
    $scheme = typeSchemes()[$name] ?? null;
    $arity = $scheme === null ? 0 : schemeArity($scheme);
    $missing = $arity - \count($astArgs);

    if ($missing <= 0) {
        $args = [];
        foreach ($astArgs as $arg) {
            $args[] = lowerExpr($arg, $ctx);
        }

        return new Intrinsic(
            $name,
            $args,
            srcLocFromAst($node, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
        );
    }

    $paramNames = [];
    for ($i = 0; $i < $missing; ++$i) {
        $paramNames[] = 'primopArg' . $i . '_' . $ctx->state->nextLambda;
    }

    // The supplied arguments are checked terms of the enclosing declaration, so
    // capture analysis runs over the trees that exist; the body below is IR, not a
    // node this pass invents for the checker to have missed.
    $evidenceParameter = static fn (Ast\EvidenceMethod $method): ?string
        => checkedEvidenceMethod($method, $ctx)->plan->parameter;
    $captures = [];
    foreach ($astArgs as $arg) {
        $captures = [...$captures, ...freeVarsExpr($arg, [], $evidenceParameter, $ctx->terms)];
    }

    return lowerLoweredLambda(
        $paramNames,
        $captures,
        static function (LowerCtx $fnCtx) use ($name, $astArgs, $paramNames, $node, $ctx): Operand {
            $args = [];
            foreach ($astArgs as $arg) {
                $args[] = lowerExpr($arg, $fnCtx);
            }
            foreach ($paramNames as $param) {
                $args[] = new Local($param);
            }

            return new Intrinsic(
                $name,
                $args,
                srcLocFromAst($node, $ctx->moduleName, $fnCtx->functionName, $ctx->sourceFile),
            );
        },
        $node,
        $ctx,
    );
}

/**
 * Lower a call to a known top-level function by name, honouring the number of
 * parameters it is declared with: exact fit is a direct call, fewer arguments
 * build a partial application, more arguments apply the returned value (a
 * function may be eta-reduced, e.g. `($) f = f`). Returns null when the callee
 * is not a known top-level function.
 *
 * @param list<Operand> $args
 */
function lowerNamedApply(string $name, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): ?Operand
{
    $arity = $ctx->functionArity[$name] ?? null;
    if ($arity === null) {
        return null;
    }

    if (\count($args) === $arity) {
        return lowerCall($name, $args, $ctx, $srcLoc);
    }

    if (\count($args) < $arity) {
        return new Partial($name, $arity, $args);
    }

    if ($arity === 0) {
        return applyToValue(lowerCall($name, [], $ctx, $srcLoc), $args, $ctx, $srcLoc);
    }

    $first = \array_slice($args, 0, $arity);
    $rest = \array_slice($args, $arity);

    return applyToValue(lowerCall($name, $first, $ctx, $srcLoc), $rest, $ctx, $srcLoc);
}

function lowerApply(Ast\AstNode $function, Ast\AstNode $argument, LowerCtx $ctx): Operand
{
    $callee = $function;
    $argNodes = [$argument];

    while ($callee instanceof Ast\Apply) {
        \array_unshift($argNodes, $callee->argument);
        $callee = $callee->function;
    }

    $args = [];
    foreach ($argNodes as $argNode) {
        $args[] = lowerExpr($argNode, $ctx);
    }

    return lowerApplySpine($callee, $args, $ctx);
}

/**
 * One application, once its callee and its already-lowered arguments are in hand.
 *
 * Split out because two callers reach this decision with arguments no node holds:
 * an application's own spine, flattened out of the tree, and a method partial's
 * eta-expanded body, which is the checked application applied to the lambda's new
 * slots. Both hand over the same shape, so "dispatch it, call it by name, or apply
 * the value" is written once.
 *
 * @param list<Operand> $args
 */
function lowerApplySpine(Ast\AstNode $callee, array $args, LowerCtx $ctx): Operand
{
    $srcLoc = srcLocFromAst($callee, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile);

    if ($callee instanceof Ast\EvidenceMethod) {
        $methodArity = checkedEvidenceMethod($callee, $ctx)->arity;
        if ($methodArity !== null && \count($args) > $methodArity) {
            $result = lowerDictMethodCall($callee, \array_slice($args, 0, $methodArity), $ctx, $srcLoc);

            return applyToValue($result, \array_slice($args, $methodArity), $ctx, $srcLoc);
        }

        return lowerDictMethodCall($callee, $args, $ctx, $srcLoc);
    }

    $name = applyCalleeName($callee, $ctx);
    if ($name !== null) {
        $lowered = lowerNamedApply($name, $args, $ctx, $srcLoc);
        if ($lowered !== null) {
            return $lowered;
        }
    }

    $result = lowerExpr($callee, $ctx);

    if ($result instanceof DictMethod) {
        return lowerDictMethodCallFromValue($result, $args, $ctx, $srcLoc);
    }

    return applyToValue($result, $args, $ctx, $srcLoc);
}

/** @param list<Operand> $args */
function lowerDictMethodCall(Ast\EvidenceMethod $expr, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $dest = freshTemp($ctx);
    $term = checkedEvidenceMethod($expr, $ctx);
    $ctx->items[] = new DictCall(
        evidencePlanOperand($term->plan, $ctx),
        $term->method,
        $args,
        $dest,
        $ctx->stmtSrcLoc
            ?? $srcLoc
            ?? srcLocFromAst($expr, $ctx->moduleName, $ctx->functionName, $ctx->sourceFile),
        $term->plan->contextWidth(),
    );

    return new Temp($dest);
}

/** @param list<Operand> $args */
function lowerDictMethodCallFromValue(DictMethod $value, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $dest = freshTemp($ctx);
    $ctx->items[] = new DictCall(
        $value->evidence,
        $value->method,
        $args,
        $dest,
        $ctx->stmtSrcLoc ?? $srcLoc,
        $value->localEvidence,
    );

    return new Temp($dest);
}

/** @param list<Operand> $args */
function applyToValue(Operand $value, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $result = $value;
    foreach ($args as $arg) {
        if ($result instanceof Partial) {
            $nextArgs = [...$result->args, $arg];
            if (\count($nextArgs) === $result->arity) {
                $result = lowerCall($result->fn, $nextArgs, $ctx, $srcLoc);

                continue;
            }

            $result = new Partial($result->fn, $result->arity, $nextArgs);

            continue;
        }

        if ($result instanceof FnRef) {
            $arity = $ctx->functionArity[$result->name] ?? null;
            if ($arity !== null) {
                if ($arity === 0) {
                    $result = applyToValue(lowerCall($result->name, [], $ctx, $srcLoc), [$arg], $ctx, $srcLoc);

                    continue;
                }

                if ($arity === 1) {
                    $result = lowerCall($result->name, [$arg], $ctx, $srcLoc);

                    continue;
                }

                $result = new Partial($result->name, $arity, [$arg]);

                continue;
            }

            $result = lowerCallValue($result, [$arg], $ctx, $srcLoc);

            continue;
        }

        if ($result instanceof Local || $result instanceof Temp) {
            $result = lowerCallValue($result, [$arg], $ctx, $srcLoc);

            continue;
        }

        if ($result instanceof DictMethod) {
            $result = lowerDictMethodCallFromValue($result, [$arg], $ctx, $srcLoc);

            continue;
        }

        throw new \RuntimeException('unsupported apply target in IR lowering');
    }

    return $result;
}

function applyCalleeName(Ast\AstNode $expr, LowerCtx $ctx): ?string
{
    return match ($expr::class) {
        Ast\ConstructorRef::class,
        Ast\OperatorRef::class => $expr->name,
        Ast\QualifiedRef::class => $expr->backendResolved ?? $expr->name,
        Ast\Variable::class => (static function () use ($expr, $ctx): ?string {
            $origin = crossModuleOrigin($expr, $ctx);
            if ($origin !== null) {
                return $origin;
            }

            $binding = $ctx->env[$expr->name] ?? null;
            if ($binding instanceof FnRef) {
                return $binding->name;
            }

            if ($binding !== null) {
                return null;
            }

            return \in_array($expr->name, $ctx->externalFnNames, true) ? $expr->name : null;
        })(),
        default => null,
    };
}

function crossModuleOrigin(Ast\Variable|Ast\OperatorRef $expr, LowerCtx $ctx): ?string
{
    $origin = $expr->resolvedOrigin;
    // "this use is bound locally" is the term's answer, not the node's: a bound use names a binder
    // ({@see CheckedVariable}), and the node's own copy of that identity is not read anywhere in
    // lowering any more.
    $term = siteTerm($expr, $ctx);
    $bound = $term instanceof CheckedVariable && $term->binder !== null;
    if (! $expr->declarationScoped || $origin === null || $bound || $ctx->moduleName === '') {
        return null;
    }

    $separator = \strpos($origin, '::');
    if ($separator === false) {
        return null;
    }

    return \substr($origin, 0, $separator) === $ctx->moduleName ? null : $origin;
}

/** @param list<Operand> $args */
function lowerCall(string $callee, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $callee = $ctx->constructorRenames[$callee] ?? $callee;

    if (isset($ctx->newtypes[$callee]) && \count($args) === 1) {
        return $args[0];
    }

    $dest = freshTemp($ctx);
    $ctx->items[] = new Call($callee, $args, $dest, $ctx->stmtSrcLoc ?? $srcLoc);

    return new Temp($dest);
}

/** @param list<Operand> $args */
function lowerCallValue(Operand $callee, array $args, LowerCtx $ctx, ?SrcLoc $srcLoc = null): Operand
{
    $dest = freshTemp($ctx);
    $ctx->items[] = new CallValue($callee, $args, $dest, $ctx->stmtSrcLoc ?? $srcLoc);

    return new Temp($dest);
}

/** @param array<int, Ast\AstNode> $elements */
function lowerTuple(array $elements, LowerCtx $ctx): Operand
{
    $args = [];
    foreach ($elements as $element) {
        $args[] = lowerIoValueExpr($element, $ctx);
    }

    return lowerCall('__tuple' . \count($elements), $args, $ctx);
}

function lowerIoValueExpr(Ast\AstNode $element, LowerCtx $ctx): Operand
{
    if (astExprReturnsIo($element)) {
        return lowerActionValueToBox(
            $element,
            $ctx,
        );
    }

    return lowerExpr($element, $ctx);
}

/** @param array<int, Ast\AstNode> $elements */
function lowerList(array $elements, LowerCtx $ctx): ListLit
{
    $lowered = [];
    foreach ($elements as $element) {
        $lowered[] = lowerIoValueExpr($element, $ctx);
    }

    return new ListLit($lowered);
}

/**
 * A source lambda, lowered from its checked term.
 *
 * The slots it takes and the names it closes over are the term's
 * ({@see \Moggi\Modules\CheckedLambda}); the body is the term's child and is
 * lowered in place, and the type the callable table records is the checker's
 * projection already on the node.
 */
function lowerCheckedLambda(Ast\Lambda $expr, LowerCtx $ctx): FnRef
{
    $term = plannedLambda($expr, $ctx);

    return lowerLoweredLambda(
        $term->parameterNames,
        $term->captures,
        lambdaBody($expr, $term),
        $expr->body,
        $ctx,
        $expr->inferredType,
    );
}

/**
 * A source lambda's body, lowered in the frame its own parameters open.
 *
 * The frame's binders are the term's, not the node's: `CheckedLambda` publishes each slot's
 * identity beside its name, so a use of a parameter inside the body reaches *its* binder even when
 * an enclosing scope binds the same spelling, and a node whose parameter list was re-parsed or
 * scrubbed cannot change which binder a use reaches. Both callers that lower a source lambda body
 * (a lambda expression and a recursive group's lambda member) go through this one helper, because
 * a frame without its own parameters' binders could only answer by spelling.
 *
 * @return callable(LowerCtx): Operand
 */
function lambdaBody(Ast\Lambda $lambda, CheckedLambda $term): callable
{
    return static function (LowerCtx $fnCtx) use ($lambda, $term): Operand {
        foreach ($term->parameterBinders as $index => $binder) {
            if ($binder !== null && isset($term->parameterNames[$index])) {
                $fnCtx->binders[$binder] = new Local($term->parameterNames[$index]);
            }
        }

        return lowerExpr($lambda->body, $fnCtx);
    };
}

/**
 * A lifted callable's declaration. `$type` is the checker's type for the lambda, recorded where
 * the declaration is made — the callable table publishes the entry's result convention from it,
 * which is why a lifted entry is no longer `Unclassified`.
 *
 * The slots and the capture set are handed in rather than read off a node: a source lambda
 * publishes both ({@see \Moggi\Modules\CheckedLambda}) and an eta-expanded one has no node at
 * all, so one builder taking them as facts is the only shape that serves both. `$body` is a
 * closure of the same reason — the checked body for a source lambda, IR for a lifted one.
 * `$origin` is the node whose span names the lifted entry, or null for a callable lowered from
 * facts only (its site is then line 0, as a synthesized declaration's is).
 *
 * @param list<string> $paramNames the callable's slots, in positional order
 * @param list<string> $captures the body's free names, already filtered by scope
 * @param callable(LowerCtx): Operand $body the callable's result, lowered in its own context
 * @param ?Ast\AstNode $origin the site whose span names this declaration, when it has one
 * @param ?Ast\AstNode $type the lambda's inferred type, when the checker left one on the node
 */
function lowerNamedLambda(
    string $name,
    array $paramNames,
    array $captures,
    ?Ast\AstNode $origin,
    callable $body,
    LowerCtx $ctx,
    FunctionKind $kind,
    ?Ast\AstNode $type = null,
): FnRef {
    $srcLoc = $origin === null
        ? new SrcLoc($ctx->moduleName, $name, normalizeDisplayPath($ctx->sourceFile), 0, 0)
        : srcLocFromAst($origin, $ctx->moduleName, $name, $ctx->sourceFile);

    $fnCtx = newCtx($paramNames, $ctx->state, $ctx->data, $ctx->newtypes, $ctx);
    $fnCtx->functionArity = $ctx->functionArity;
    $fnCtx->externalFnNames = $ctx->externalFnNames;
    $fnCtx->functionName = $name;
    if ($origin !== null && $origin->line > 0) {
        $fnCtx->stmtSrcLoc = $srcLoc;
    }

    foreach ($captures as $free) {
        if (isset($ctx->env[$free])) {
            $fnCtx->env[$free] = $ctx->env[$free];
        }
    }

    $tail = $body($fnCtx);
    $fnCtx->items[] = new Ret($tail);
    $ctx->state->functions[] = new FunctionDecl(
        $name,
        $paramNames,
        $type,
        new Block($fnCtx->items),
        new FunctionShape(kind: $kind),
        $srcLoc,
        $ctx->evidenceNames,
    );
    $ctx->functionArity[$name] = \count($paramNames);

    return new FnRef($name);
}

function lowerNamedNullary(string $name, Ast\AstNode $body, LowerCtx $ctx, FunctionKind $kind): FnRef
{
    return lowerNamedLambda(
        $name,
        [],
        freeVarsExpr($body, [], evidenceParameterFromTerms($ctx), $ctx->terms),
        $body,
        static fn (LowerCtx $fnCtx): Operand => lowerExpr($body, $fnCtx),
        $ctx,
        $kind,
    );
}

/**
 * The dictionary-parameter answer for an evidence site, read from the term.
 *
 * The one spelling of "where does this method get its dictionary", shared by every
 * lowering caller that has to scope a body before lowering it (a lambda's capture
 * scan, a letrec thunk's).
 */
function evidenceParameterFromTerms(LowerCtx $ctx): callable
{
    return static fn (Ast\EvidenceMethod $method): ?string
        => checkedEvidenceMethod($method, $ctx)->plan->parameter;
}

/**
 * A lambda whose body is IR the lowering pass already decided.
 *
 * Eta-expansion builds a body the elaboration never had: an unsaturated primop
 * becomes `\a b -> primop a b`, and there is no checked node for that application.
 * Fabricating one was the inversion the checked core exists to remove — a node
 * created after publication has no term, so asking it for its primop both invented
 * a tree the checker never saw and then read that tree for a decision. The builder
 * takes the body as a closure over its own context instead, and the trees capture
 * analysis needs (`$captures`, resolved from the checked arguments by the caller)
 * are passed in, because the node that would have carried them no longer exists.
 *
 * Everything else is the declaration {@see lowerNamedLambda()} makes: same slot
 * order, same function kind, same source site, same evidence scope.
 *
 * @param list<string> $paramNames the lambda's parameters, in positional order
 * @param list<string> $captures free names of the body, as the caller resolved them
 * @param callable(LowerCtx): Operand $body the lambda's result, lowered in its own context
 * @param ?Ast\AstNode $origin the span that names this lambda, or null when lowering has none
 * @param ?Ast\AstNode $type the lambda's inferred type, when the checker left one on the node
 */
function lowerLoweredLambda(
    array $paramNames,
    array $captures,
    callable $body,
    ?Ast\AstNode $origin,
    LowerCtx $ctx,
    ?Ast\AstNode $type = null,
): FnRef {
    return lowerNamedLambda(
        'λ' . $ctx->state->nextLambda++,
        $paramNames,
        $captures,
        $origin,
        $body,
        $ctx,
        FunctionKind::Lambda,
        $type,
    );
}

/**
 * A `let`/`where` group, lowered from the term that owns its members and their order.
 *
 * The components arrive already decomposed ({@see plannedLetGroup()}), so this function binds
 * them in the order the check published and then lowers the expression the term names as their
 * scope. The node's own `$bindings` list is never read: an order re-derived here would be a
 * second answer to a question inference already answered.
 */
function lowerLetGroup(Ast\AstNode $node, LowerCtx $ctx): Operand
{
    $term = plannedLetGroup($node, $ctx);
    $body = $term->body
        ?? throw new \LogicException(
            'the checked term for the `' . $node::class . "` at line {$node->line} names no scoped expression",
        );

    $savedEnv = $ctx->env;
    lowerBindingGroups($term, $ctx);
    $result = lowerExpr($body, $ctx);
    $ctx->env = $savedEnv;

    return $result;
}

/**
 * A `do` statement's binding group: the same members and order, scoped by the surrounding
 * statement list rather than by an expression the term names.
 */
/** Bind one group's components in the order its term publishes. */
function lowerBindingGroups(CheckedLetGroup $term, LowerCtx $ctx): void
{
    foreach ($term->components as $component) {
        if ($component->recursive) {
            lowerRecursiveBindings($component->bindings, $ctx);

            continue;
        }
        foreach ($component->bindings as $binding) {
            [$rhs] = peelBindingAnnotation($binding->value);
            bindPattern(
                lowerPattern($binding->pattern, $ctx),
                lowerExpr($rhs, $ctx),
                $ctx,
            );
        }
    }
}

/**
 * Recursive let group: pre-bind names as function refs (lambdas or nullary
 * value thunks), then lower bodies so self/mutual refs resolve.
 *
 * @param list<Ast\Binding> $bindings
 */
function lowerRecursiveBindings(array $bindings, LowerCtx $ctx): void
{
    /** @var array<string, array{kind: 'lambda'|'nullary', fnName: string, rhs: Ast\AstNode}> $planned */
    $planned = [];

    foreach ($bindings as $binding) {
        // A member that binds a single variable can be the group's own function entry; a structural
        // pattern cannot, and both questions are the pattern term's shape rather than the node's.
        $site = plannedPattern($binding->pattern, $ctx);
        if ($site->shape !== PatternShape::Var) {
            continue;
        }
        [$rhs] = peelBindingAnnotation($binding->value);
        $name = $site->name
            ?? throw new \RuntimeException("the recursive binder at {$site->origin} publishes no name");
        $fnName = '__letrec' . $ctx->state->nextLambda++;
        if ($rhs instanceof Ast\Lambda) {
            $planned[$name] = ['kind' => 'lambda', 'fnName' => $fnName, 'rhs' => $rhs];
            // The group member's arity is its lambda term's, not its parameter list's:
            // the same term names the slots below, and a call to this entry is shaped
            // by this number.
            $ctx->functionArity[$fnName] = plannedLambda($rhs, $ctx)->arity();
        } else {
            $planned[$name] = ['kind' => 'nullary', 'fnName' => $fnName, 'rhs' => $rhs];
            $ctx->functionArity[$fnName] = 0;
        }
        $ctx->env[$name] = new FnRef($fnName);
        // A member's own name is a binder too: a use inside the group resolves by identity to the
        // entry the group pre-bound, not by finding the spelling in the environment. The identity is
        // the binding site's term, the same one the `let`'s own slot is allocated from.
        $identity = $site->binder;
        if ($identity !== null) {
            $ctx->binders[$identity] = new FnRef($fnName);
        }
    }

    foreach ($planned as $plan) {
        if ($plan['kind'] === 'lambda') {
            /** @var Ast\Lambda $lambda */
            $lambda = $plan['rhs'];
            $term = plannedLambda($lambda, $ctx);
            lowerNamedLambda(
                $plan['fnName'],
                $term->parameterNames,
                $term->captures,
                $lambda->body,
                lambdaBody($lambda, $term),
                $ctx,
                FunctionKind::Letrec,
            );
        } else {
            lowerNamedNullary($plan['fnName'], $plan['rhs'], $ctx, FunctionKind::Letrec);
        }
    }

    foreach ($bindings as $binding) {
        $site = plannedPattern($binding->pattern, $ctx);
        if ($site->shape === PatternShape::Var) {
            continue;
        }
        [$rhs] = peelBindingAnnotation($binding->value);
        bindPattern(
            lowerPatternTerm($site, $ctx),
            lowerExpr($rhs, $ctx),
            $ctx,
        );
    }
}

/**
 * A `case`, lowered from the term that owns its arms.
 *
 * The arms, their order and their guard clauses are published facts ({@see CheckedCaseArm}), so
 * this arm no longer asks each alternative whether its body happens to be a `GuardsExpr` nor
 * reads `CaseExpr::$alts`: it walks the term's clauses, one match arm each, in the order the term
 * names them. The scrutinee stays the node's — it is one expression, lowered in place.
 *
 * "Every arm always matches" is decided here rather than published because it is not a fact of
 * the source alone: the last clause's guard has to be a name the lowering context already proved
 * `True` ({@see guardIsConstantTrue()}), and that context is only complete once enclosing branches
 * have been lowered.
 */
function lowerCase(Ast\CaseExpr $expr, LowerCtx $ctx): Operand
{
    $term = plannedCase($expr, $ctx);
    $scrutineeOp = lowerExpr($expr->scrutinee, $ctx);
    $dest = freshTemp($ctx);
    $arms = [];
    $guardsAlwaysHold = true;

    foreach ($term->arms as $arm) {
        $lastGuard = $arm->lastGuard();
        if ($lastGuard !== null) {
            $guardsAlwaysHold = $guardsAlwaysHold && guardIsConstantTrue($lastGuard, $ctx->trueBindings);
        }

        foreach ($arm->clauses as $clause) {
            $armCtx = newArmCtx($ctx, $clause['body']);
            $pattern = lowerPattern($arm->pattern, $ctx);
            bindPattern($pattern, $scrutineeOp, $armCtx);
            if ($clause['guard'] === null) {
                $guards = [];
            } else {
                $bindings = $armCtx->items;
                // Whether the arm binds by a variable is the pattern's own shape — the IR pattern
                // this arm just bound — not a question about the source node behind it.
                $isVarPattern = $pattern instanceof PatVar;
                $armCtx->items = [];
                $guards = [lowerArmGuard($clause['guard'], $armCtx, $isVarPattern ? $bindings : [])];
                $armCtx->items = $isVarPattern ? [] : $bindings;
            }
            $value = lowerExpr($clause['body'], $armCtx);
            $arms[] = new MatchArm(
                eraseNewtypesInPattern($pattern, $armCtx->newtypes),
                new Block([...$armCtx->items, new Assign($dest, $value)]),
                $guards,
            );
        }
    }

    $ctx->items[] = new MatchStmt($scrutineeOp, $arms, $dest, $term->exhaustive && $guardsAlwaysHold);

    return new Temp($dest);
}

/**
 * A bare guard chain, lowered from the term that owns its clauses.
 *
 * The clauses, their order and their count are the term's; each clause's guard and body stay
 * children, lowered in place. "The chain always succeeds" is decided here rather than published,
 * because it needs the lowering context's proved-true bindings, and that context is only complete
 * once enclosing branches have been lowered.
 */
function lowerGuards(Ast\GuardsExpr $expr, LowerCtx $ctx): Operand
{
    $term = plannedGuards($expr, $ctx);
    $dest = freshTemp($ctx);
    $arms = [];

    foreach ($term->clauses as $clause) {
        $guard = lowerExpr($clause->guard, $ctx);
        $armCtx = newArmCtx($ctx, $clause->body);
        $value = lowerExpr($clause->body, $armCtx);
        $arms[] = new MatchArm(
            new PatWild(),
            new Block([...$armCtx->items, new Assign($dest, $value)]),
            [new Guard(new Block([]), $guard)],
        );
    }

    $ctx->items[] = new MatchStmt(
        new ConstInt(0),
        $arms,
        $dest,
        guardIsConstantTrue($term->lastGuard(), $ctx->trueBindings),
    );

    return new Temp($dest);
}

/**
 * A case arm and a guarded clause are the same kind of scope: their pattern
 * bindings and the statements their guard/body need stay inside the arm.
 */
function newArmCtx(LowerCtx $ctx, Ast\AstNode $body): LowerCtx
{
    $armCtx = newCtx([], $ctx->state, $ctx->data, $ctx->newtypes, $ctx);
    $armCtx->env = $ctx->env;
    if ($body->line > 0) {
        $armCtx->stmtSrcLoc = srcLocFromAst(
            $body,
            $ctx->moduleName,
            $ctx->functionName,
            $ctx->sourceFile,
        );
    }

    return $armCtx;
}

/**
 * Lower one arm guard into a guard that owns its preparation.
 *
 * Guard expressions are not always operand-shaped (`| ok (g x) = …`), so the
 * statements that compute one belong to the guard: they run right after the
 * pattern bound its variables and before the guard is tested. The pattern's own
 * bindings are part of that preparation, since a guard reads them.
 *
 * @param list<Stmt> $bindings
 */
function lowerArmGuard(Ast\AstNode $guard, LowerCtx $armCtx, array $bindings): Guard
{
    $cond = lowerExpr($guard, $armCtx);
    $prep = new Block([...$bindings, ...$armCtx->items]);
    $armCtx->items = [];

    return new Guard($prep, $cond);
}

/**
 * A record construction or update, lowered from the term that owns its fields.
 *
 * One function for both shapes because they are one family — a construction is an update with no
 * receiver. The term names the constructor (for an update, the checker's answer from the
 * receiver's type), the fields in source order and whether a `newtype` update is identity; the
 * declared field order is lowering's context, as it is for a record pattern. What the term
 * removes is the node read: `RecordCon::$name`, `RecordUpdate::$constructor`,
 * `::$fieldOnNewtype` and both `$fields` lists.
 */
function lowerRecord(Ast\RecordCon|Ast\RecordUpdate $expr, LowerCtx $ctx): Operand
{
    $term = plannedRecord($expr, $ctx);
    $order = $ctx->data[$term->constructor]
        ?? throw new \RuntimeException("unknown record constructor `{$term->constructor}` in IR lowering");

    $byName = [];
    foreach ($term->fields as $field) {
        $byName[$field->name] = $field->value;
    }

    if (! $term->isUpdate()) {
        $args = \array_map(
            static fn (string $fieldName): Operand => lowerExpr($byName[$fieldName], $ctx),
            $order,
        );

        return lowerCall($term->constructor, $args, $ctx);
    }

    $receiver = stabilizeOperand(lowerExpr($term->receiver, $ctx), $ctx);
    $args = [];
    foreach ($order as $position => $name) {
        if (isset($byName[$name])) {
            $args[] = lowerExpr($byName[$name], $ctx);

            continue;
        }

        $args[] = $term->fieldOnNewtype
            ? $receiver
            : lowerCall('__field' . $position, [$receiver], $ctx);
    }

    return lowerCall($term->constructor, $args, $ctx);
}

/**
 * A literal, lowered from its term.
 *
 * The term's shape says which constant to build and its value is the one the checker settled; the
 * node's `value` is not read. The shape check on the value is a refusal, not a coercion: a term
 * whose value does not fit its shape is a producer defect, and emitting a coerced constant would
 * hide it.
 */
function lowerLiteral(Ast\AstNode $site, LowerCtx $ctx): Operand
{
    $term = plannedLiteral($site, $ctx);

    return match ($term->shape) {
        LiteralShape::Int => new ConstInt(
            \is_int($term->value)
                ? $term->value
                : throw new \RuntimeException("the integer literal at {$term->origin} publishes a non-integer value"),
        ),
        LiteralShape::Double => new ConstDouble(
            \is_float($term->value)
                ? $term->value
                : throw new \RuntimeException("the double literal at {$term->origin} publishes a non-float value"),
        ),
        LiteralShape::Str => new ConstStr(
            \is_string($term->value)
                ? $term->value
                : throw new \RuntimeException("the string literal at {$term->origin} publishes a non-string value"),
        ),
        LiteralShape::Char => new ConstChar(
            \is_int($term->value)
                ? $term->value
                : throw new \RuntimeException("the character literal at {$term->origin} publishes a non-integer value"),
        ),
    };
}

/**
 * Bind a value to a temp so it can be read more than once.
 *
 * An operand is usually a `Temp` a previous statement wrote, but some shapes are
 * the expression themselves (`Intrinsic`, `Partial`) and would be
 * recomputed by every read. A record update reads its receiver once per field it
 * keeps, so the receiver goes through here first.
 */
function stabilizeOperand(Operand $value, LowerCtx $ctx): Operand
{
    return match (true) {
        $value instanceof ConstInt,
        $value instanceof ConstStr,
        $value instanceof ConstChar,
        $value instanceof ConstDouble,
        $value instanceof Local,
        $value instanceof Temp,
        $value instanceof FnRef,
        $value instanceof Unit => $value,
        default => (static function () use ($value, $ctx): Operand {
            $dest = freshTemp($ctx);
            $ctx->items[] = new Assign($dest, $value);

            return new Temp($dest);
        })(),
    };
}

function lowerFieldAccess(Ast\FieldAccess $expr, LowerCtx $ctx): Operand
{
    $index = $expr->fieldIndex
        ?? throw new \RuntimeException("field access `.{$expr->field}` reached lowering without a type");
    $object = lowerExpr($expr->object, $ctx);

    return $expr->fieldOnNewtype ? $object : lowerCall('__field' . $index, [$object], $ctx);
}

function astExprReturnsIo(Ast\AstNode $expr): bool
{
    $type = $expr->inferredInternalType;

    return $type !== null && isIoCheckedType($type);
}
