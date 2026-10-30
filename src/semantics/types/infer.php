<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use function Moggi\Errors\appendDidYouMean;

use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TChar;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TDouble;
use Moggi\Semantics\TypeExpr\TStr;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Modules\resolvedSymbol;
use function Moggi\Semantics\Exhaustiveness\caseExhaustivenessProven;
use function Moggi\Semantics\Exhaustiveness\checkCaseExhaustiveness;
use function Moggi\Semantics\IntrinsicRegistry\resolveIntrinsicName;
use function Moggi\Semantics\Kernel\bindRole;
use function Moggi\Semantics\TypeExpr\scheme;
use function Moggi\Syntax\Ast\desugarDo;
use function Moggi\Syntax\isConstructorName;
use function Moggi\Syntax\isConstructorOperator;

require_once __DIR__ . '/../../patterns/walk.php';
require_once __DIR__ . '/let_groups.php';
require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/allocation.php';
require_once __DIR__ . '/constraints.php';
require_once __DIR__ . '/instances.php';
require_once __DIR__ . '/../exhaustiveness.php';


function isIoTypeCon(TypeCheckState $state, Type $type): bool
{
    $type = prune($state, $type);

    return $type instanceof TCon
        && $type->name === Kinds\canonicalTypeConName('IO#')
        && \count($type->args) === 1;
}

function inferExpr(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    $type = match ($expr::class) {
        Ast\IntegerLit::class => inferIntegerLit($state, $expr),
        Ast\DoubleLit::class => new TDouble(),
        Ast\StringLit::class => new TStr(),
        Ast\CharLit::class => new TChar(),
        Ast\Variable::class => inferVariable($state, $expr, $env),
        Ast\ConstructorRef::class => instantiate($state, $env[$expr->name] ?? throw typeFail(
            $state,
            appendDidYouMean(
                "undefined constructor `{$expr->name}`",
                $expr->name,
                constructorCandidateNames($env),
            ),
            $expr,
        )),
        Ast\OperatorRef::class => inferOperatorRef($state, $expr, $env),
        Ast\Apply::class => inferApplyAndElaborate($state, $expr, $env),
        Ast\Infix::class => inferInfix($state, $expr, $env),
        Ast\Tuple::class => inferTuple($state, $expr, $env),
        Ast\ListLit::class => inferList($state, $expr, $env),
        Ast\Lambda::class => inferLambda($state, $expr, $env),
        Ast\Let::class => inferLet($state, $expr, $env),
        Ast\Where::class => inferWhere($state, $expr, $env),
        Ast\CaseExpr::class => inferCase($state, $expr, $env),
        Ast\GuardsExpr::class => inferGuards($state, $expr, $env),
        Ast\DoExpr::class => inferDoExpr($state, $expr, $env),
        Ast\RecordCon::class => inferRecordCon($state, $expr, $env),
        Ast\FieldAccess::class => inferFieldAccess($state, $expr, $env),
        Ast\RecordUpdate::class => inferRecordUpdate($state, $expr, $env),
        Ast\QualifiedRef::class => inferQualifiedRef($state, $expr, $env),
        Ast\TypeAsc::class => inferTypeAsc($state, $expr, $env),
        Ast\ExprHole::class => inferHole($state, $expr),
        Ast\IntrinsicCall::class => inferIntrinsic($state, $expr, $env),
        Ast\ForeignCall::class => inferForeignCall($state, $expr, $env),
        Ast\EvidenceRef::class => new TCon('__Dict_' . $expr->class),
        Ast\EvidenceMethod::class => inferEvidenceMethod($state, $expr),
        default => throw typeFail($state, \sprintf('unsupported expression `%s`', $expr::class), $expr),
    };

    annotateExprType($state, $expr, $type);

    return $type;
}

function inferHole(TypeCheckState $state, Ast\ExprHole $expr): Type
{
    $type = freshType($state);
    $state->holes[] = $expr;

    return $type;
}

/**
 * After the body's unifications are zonked, reject any typed holes with their
 * refined types (`_` holes).
 */
function reportTypedHoles(TypeCheckState $state): void
{
    if ($state->holes === []) {
        return;
    }

    $hole = $state->holes[0];
    $type = $hole->inferredInternalType !== null
        ? $hole->inferredInternalType
        : freshType($state);
    $type = prune($state, $type);
    $rename = friendlyTypeVarNames([$type]);
    $typeStr = typeToString($type, $rename);

    $err = typeFail(
        $state,
        'Found hole with type: `' . $typeStr . '`',
        $hole,
    );
    $err->diagnosticCode = 'hole';
    $err->holeType = $typeStr;
    if (\count($state->holes) > 1) {
        $related = [];
        for ($i = 1, $n = \count($state->holes); $i < $n; $i++) {
            $h = $state->holes[$i];
            $ht = $h->inferredInternalType !== null
                ? typeToString(prune($state, $h->inferredInternalType), $rename)
                : '?';
            $related[] = [
                'message' => "also hole with type: `{$ht}`",
                'filename' => $state->filename,
                'line' => (int) ($h->line ?? 0),
                'col' => (int) ($h->col ?? 0),
                'endCol' => (int) (($h->endCol ?? 0) ?: ($h->col ?? 0)),
            ];
        }
        $err->relatedLocations = $related;
    }

    throw $err;
}

function inferDoExpr(TypeCheckState $state, Ast\DoExpr $expr, array $env): Type
{
    $bind = classMethodWithRole($state, bindRole());
    if ($bind === null) {
        throw typeFail(
            $state,
            '`do`-notation needs a class in scope with the monad\'s bind (`m a -> (a -> m b) -> m b`)',
            $expr,
        );
    }

    $desugared = desugarDo($expr->stmts, $bind);
    if ($expr->sourceFile !== null) {
        Ast\markSourceFile($desugared, $expr->sourceFile);
    }
    $type = inferExpr($state, $desugared, $env);
    $expr->desugared = $desugared;

    return $type;
}

function annotateExprType(TypeCheckState $state, Ast\AstNode $expr, Type $type): void
{
    publishInferredType($state, $expr, $type);
}

function inferTypeAsc(TypeCheckState $state, Ast\TypeAsc $expr, array $env): Type
{
    $exprType = inferExpr($state, $expr->expr, $env);
    $annotType = astType($state, $expr->type);
    unify($state, $exprType, $annotType, $expr);

    return prune($state, $annotType);
}

function inferVariable(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    if (! $expr instanceof Ast\Variable) {
        throw typeFail($state, 'expected variable', $expr);
    }

    if (isset($state->constraintMethodAmbiguities[$expr->name])) {
        throw typeFail($state, "ambiguous class method `{$expr->name}`", $expr);
    }

    if (isset($state->constraintMethods[$expr->name])) {
        $info = $state->constraintMethods[$expr->name];
        applyEvidenceMethodInfo($state, $expr, $info);

        return freshenConstraintMethod($state, $expr, $info);
    }

    if (isset($state->classMethodAmbiguities[$expr->name])) {
        throw typeFail($state, \sprintf('ambiguous class method `%s`', $expr->name), $expr);
    }

    if (isset($state->ambiguousImports[$expr->name])) {
        throw typeFail($state, ambiguousImportMessage($expr->name, $state->ambiguousImports[$expr->name]), $expr);
    }

    $localScheme = resolveBinderScheme($state, $expr->name, $env);
    $scheme = $localScheme
        ?? $env[$expr->name]
        ?? throw typeFail(
            $state,
            appendDidYouMean(
                \sprintf('undefined variable `%s`', $expr->name),
                $expr->name,
                visibleEnvNames($env),
            ),
            $expr,
        );
    if (isset($state->intrinsicWrappers[$expr->name])) {
        $expr->intrinsicWrapper = $state->intrinsicWrappers[$expr->name];
    } elseif (($localScheme !== null || isset($env[$expr->name])) && resolveIntrinsicName($expr->name) !== null) {
        $expr->intrinsicWrapper = $expr->name;
    }
    if ($scheme->binderId !== null) {
        $expr->binderId = $scheme->binderId;
    }
    if (isset($state->defaultBodyOrigins[$expr->name]) && $scheme->binderId === null) {
        $expr->resolvedOrigin = $state->defaultBodyOrigins[$expr->name];
        $expr->declarationScoped = true;
    } elseif (isset($state->externalFns[$expr->name])) {
        $expr->resolvedOrigin = $state->externalFns[$expr->name];
    } elseif ($state->currentModule !== null) {
        $expr->resolvedOrigin = resolvedSymbol($state->currentModule, $expr->name);
    }
    $instantiated = instantiateScheme($state, $scheme);
    if ($instantiated['constraints'] !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
        $expr->pendingDicts = $instantiated['dicts'];
    }

    return $instantiated['type'];
}

function inferOperatorRef(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    if (! $expr instanceof Ast\OperatorRef) {
        throw typeFail($state, 'expected operator_ref', $expr);
    }

    if (isConstructorOperator($expr->name) && lookupConstructorMeta($state, $expr->name) !== null) {
        $expr = new Ast\ConstructorRef($expr->name);

        return inferExpr($state, $expr, $env);
    }

    if (isset($state->constraintMethodAmbiguities[$expr->name])) {
        throw typeFail($state, "ambiguous class method `{$expr->name}`", $expr);
    }

    if (isset($state->constraintMethods[$expr->name])) {
        $info = $state->constraintMethods[$expr->name];
        applyEvidenceMethodInfo($state, $expr, $info);

        return freshenConstraintMethod($state, $expr, $info);
    }

    if (isset($state->classMethodAmbiguities[$expr->name])) {
        throw typeFail($state, "ambiguous class method `{$expr->name}`", $expr);
    }

    if (isset($state->ambiguousImports[$expr->name])) {
        throw typeFail($state, ambiguousImportMessage($expr->name, $state->ambiguousImports[$expr->name]), $expr);
    }

    $scheme = resolveBinderScheme($state, $expr->name, $env)
        ?? $env[$expr->name]
        ?? throw typeFail(
            $state,
            appendDidYouMean(
                "undefined operator `{$expr->name}`",
                $expr->name,
                operatorCandidateNames($env, $state),
            ),
            $expr,
        );

    if (isset($state->defaultBodyOrigins[$expr->name]) && $scheme->binderId === null) {
        $expr->resolvedOrigin = $state->defaultBodyOrigins[$expr->name];
        $expr->declarationScoped = true;
    } elseif (isset($state->externalFns[$expr->name])) {
        $expr->resolvedOrigin = $state->externalFns[$expr->name];
    } elseif ($state->currentModule !== null) {
        $expr->resolvedOrigin = resolvedSymbol($state->currentModule, $expr->name);
    }

    $instantiated = instantiateScheme($state, $scheme);
    if ($instantiated['constraints'] !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
        $expr->pendingDicts = $instantiated['dicts'];
    }

    return $instantiated['type'];
}

/** @param array<string, array<string, mixed>> $env @return list<string> */
function constructorCandidateNames(array $env): array
{
    $names = [];
    foreach (visibleEnvNames($env) as $name) {
        if (isConstructorName($name)) {
            $names[] = $name;
        }
    }

    return $names;
}

/** @param array<string, array<string, mixed>> $env */
function operatorCandidateNames(array $env, TypeCheckState $state): array
{
    $names = [];
    foreach ([...visibleEnvNames($env), ...\array_keys($state->constraintMethods)] as $name) {
        if ($name !== '' && ! \ctype_alpha($name[0]) && $name[0] !== '_') {
            $names[] = $name;
        }
    }

    return \array_values(\array_unique($names));
}

function inferEvidenceMethod(TypeCheckState $state, Ast\AstNode $expr): Type
{
    if (! $expr instanceof Ast\EvidenceMethod) {
        throw typeFail($state, 'expected evidence_method', $expr);
    }

    $info = $state->constraintMethods[$expr->method] ?? null;
    if ($info !== null && $expr->class === $info['class']) {
        applyEvidenceMethodInfo($state, $expr, $info);

        return freshenConstraintMethod($state, $expr, $info);
    }

    $classInfo = $state->classes[$expr->class] ?? throw typeFail(
        $state,
        appendDidYouMean(
            "unknown class `{$expr->class}`",
            $expr->class,
            \array_keys($state->classes),
        ),
        $expr,
    );

    $methodInfo = $classInfo['methods'][$expr->method];
    $userConstraints = $methodInfo['userConstraints'] ?? [];
    $instantiated = freshenTypeWithConstraints(
        $state,
        peelDictArrows($methodInfo['type'], \count($userConstraints)),
        $userConstraints,
    );
    if ($instantiated['constraints'] !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
    }

    return $instantiated['type'];
}

/**
 * The type of an application: unify the argument against the callee's domain and
 * park whatever the callee owes as an obligation.
 *
 * Inference only -- it never inserts evidence. The obligation is parked by
 * {@see parkApplyConstraints} and discharged by {@see elaborateApplyEvidence},
 * which every caller reaches through {@see inferApplyAndElaborate}.
 */
function inferApply(TypeCheckState $state, Ast\Apply $expr, array $env): Type
{
    $fnType = prune($state, inferMethodByArgument($state, $expr, $env));
    $argType = prune($state, takeKnownOperandType($state, $expr->argument) ?? inferExpr($state, $expr->argument, $env));

    if ($argType instanceof TCon && \str_starts_with($argType->name, '__Dict_')) {
        return $fnType;
    }

    if ($fnType instanceof TVar) {
        $result = freshType($state);
        unify($state, $fnType, new TArrow($argType, $result), $expr);
        parkApplyConstraints($state, $expr);
        propagateCallMetadata($expr);

        return $result;
    }

    $fnType = normalizeType($state, $fnType, reduceFamilies: false);
    if (! $fnType instanceof TArrow) {
        $result = freshType($state);
        unify($state, $fnType, new TArrow($argType, $result), $expr);
        parkApplyConstraints($state, $expr);
        propagateCallMetadata($expr);

        return $result;
    }

    resolveMethodHeadFromArgument($state, $expr->function, $fnType->from, $argType);

    unify($state, $fnType->from, $argType, $expr);
    $result = $fnType->to;

    parkApplyConstraints($state, $expr);
    propagateCallMetadata($expr);

    return $result;
}

/**
 * True when `$op` is a type-class method (locally declared or imported).
 * Class-method operators are dispatched through evidence, so they must not be
 * treated as ordinary function operators.
 */
function isClassMethodOperator(TypeCheckState $state, string $op): bool
{
    if (isset($state->classMethodOwners[$op])) {
        return true;
    }

    foreach ($state->classes as $classInfo) {
        if (isset($classInfo['methods'][$op])) {
            return true;
        }
    }

    return false;
}

/**
 * The application an infix expression stands for.
 *
 * The rewrite replaces a node the reader wrote with a tree they did not, so the
 * replacement carries the infix's own source position: the generated code's
 * statement map and its diagnostics both name the expression as written.
 */
function infixApplicationNode(string $op, Ast\AstNode $left, Ast\AstNode $right, int $line, int $col, int $endCol): Ast\Apply
{
    return new Ast\Apply(
        new Ast\Apply(
            new Ast\OperatorRef($op, $line, $col, $endCol),
            $left,
            $line,
            $col,
            $endCol,
        ),
        $right,
        $line,
        $col,
        $endCol,
    );
}

function inferInfix(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    if (! $expr instanceof Ast\Infix) {
        throw new \InvalidArgumentException('inferInfix expects an infix expression');
    }

    if ($expr->compilerIntrinsic !== null) {
        $bool = new TCon('Bool');
        unify($state, inferExpr($state, $expr->left, $env), $bool, $expr);
        unify($state, inferExpr($state, $expr->right, $env), $bool, $expr);
        $expr->resolvedIntrinsic = $expr->compilerIntrinsic;

        return $bool;
    }

    $op = $expr->operator;

    $expr->resolvedIntrinsic = null;

    if (lookupConstructorMeta($state, $op) !== null) {
        $left = $expr->left;
        $right = $expr->right;
        $surface = $expr;
        $expr = new Ast\Apply(
            new Ast\Apply(
                new Ast\ConstructorRef($op),
                $left,
            ),
            $right,
        );

        return inferRewrittenInfix($state, $surface, $expr, $env);
    }

    if (isset($state->constraintMethods[$op])) {
        $left = $expr->left;
        $right = $expr->right;
        $surface = $expr;
        $expr = new Ast\Apply(
            new Ast\Apply(
                new Ast\OperatorRef($op),
                $left,
            ),
            $right,
        );

        return inferRewrittenInfix($state, $surface, $expr, $env);
    }

    if (! isClassMethodOperator($state, $op)
        && ! isset($state->intrinsicWrappers[$op])
        && ($state->resolveBinder($op) !== null || isset($env[$op]) || isset($state->env[$op]))) {
        $left = $expr->left;
        $right = $expr->right;
        $surface = $expr;
        $expr = new Ast\Apply(
            new Ast\Apply(
                new Ast\OperatorRef($op),
                $left,
            ),
            $right,
        );

        return inferRewrittenInfix($state, $surface, $expr, $env);
    }

    $leftType = inferExpr($state, $expr->left, $env);
    $rightType = inferExpr($state, $expr->right, $env);

    $opScheme = resolveBinderScheme($state, $op, $env) ?? $env[$op] ?? null;

    if ($opScheme === null && isset($state->env[$op])) {
        $opScheme = $state->env[$op];
    }

    if ($opScheme !== null) {
        $primop = instanceOperatorPrimop($state, $op, prune($state, $leftType), prune($state, $rightType));
        if ($primop !== null) {
            return applyOperatorPrimop($state, $expr, $primop, $leftType, $rightType);
        }

        $declaredPrimop = $state->intrinsicWrappers[$op] ?? null;
        if ($declaredPrimop !== null && ! isset($state->localDeclNames[$op])) {
            return applyOperatorPrimop($state, $expr, $declaredPrimop, $leftType, $rightType);
        }
    }

    if ($opScheme === null) {
        if (isset($state->classMethodAmbiguities[$op])) {
            throw typeFail($state, "ambiguous class method `{$op}`", $expr);
        }

        throw typeFail($state, "undefined operator `{$op}`", $expr);
    }

    $instantiated = instantiateScheme($state, $opScheme);
    $result = freshType($state);
    unify($state, $instantiated['type'], new TArrow($leftType, new TArrow($rightType, $result)), $expr);

    $classOperator = isClassMethodOperator($state, $op) || isset($state->constraintMethods[$op]);
    $constraints = $instantiated['dicts'];

    if ($classOperator && $constraints === []) {
        $primop = instanceOperatorPrimop(
            $state,
            $op,
            prune($state, $leftType),
            prune($state, $rightType),
        );
        if ($primop !== null) {
            return applyOperatorPrimop($state, $expr, $primop, $leftType, $rightType);
        }
    }

    if ($classOperator
        && $constraints !== []
        && isset($state->constraintMethodAmbiguities[$op])
        && operandTypeIsUnresolved($state, $leftType, $rightType)
        && constraintsResolvable($state, $constraints)
    ) {
        $chosen = constraintMethodForOperandTypes(
            $state,
            $op,
            prune($state, $leftType),
            prune($state, $rightType),
        );
        if ($chosen !== null) {
            $savedMethods = $state->constraintMethods;
            $savedAmbiguities = $state->constraintMethodAmbiguities;
            $state->constraintMethods[$op] = $chosen;
            unset($state->constraintMethodAmbiguities[$op]);

            try {
                return inferRewrittenInfixFromOperands($state, $expr, $op, $leftType, $rightType, $env, false);
            } finally {
                $state->constraintMethods = $savedMethods;
                $state->constraintMethodAmbiguities = $savedAmbiguities;
            }
        }
    }

    if ($classOperator
        && $constraints !== []
        && ! isset($state->constraintMethodAmbiguities[$op])
        && operandTypeIsUnresolved($state, $leftType, $rightType)
    ) {
        if (constraintsResolvable($state, $constraints)) {
            return inferRewrittenInfixFromOperands($state, $expr, $op, $leftType, $rightType, $env, false);
        }

        $expr->pendingConstraints = $instantiated['constraints'];
        $expr->pendingDicts = $instantiated['dicts'];

        return $result;
    }

    if ($classOperator
        && (instanceHeadOperand($state, $op, $leftType, $rightType)
            || ($constraints !== []
                && ! operandTypeIsUnresolved($state, $leftType, $rightType)
                && constraintsResolvable($state, $constraints)))
    ) {
        return inferRewrittenInfixFromOperands($state, $expr, $op, $leftType, $rightType, $env, true);
    }

    if ($classOperator && $constraints !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
        $expr->pendingDicts = $instantiated['dicts'];

        return $result;
    }

    return $result;
}

/**
 * Infer an operator use that was rewritten into a call, and mirror the call's
 * type onto the `Infix` node the rewrite replaced.
 *
 * The rewrite swaps the node in the tree it was handed, but a do block keeps a
 * second handle on the same node: its statement list holds the surface shape
 * while inference runs over the desugared tree. Passes that read the surface
 * tree (the IO boundary pass reads `>>=` there) need a type on the nodes they
 * hold, and the node they hold is the one the rewrite moved away from.
 */
function inferRewrittenInfix(TypeCheckState $state, Ast\AstNode $surface, Ast\Apply $call, array $env): Type
{
    $type = inferApplyAndElaborate($state, $call, $env);
    annotateExprType($state, $call, $type);
    copyInferredType($surface, $call);

    return $type;
}

/**
 * Rewrite an infix into the call it stands for, handing the call the operand types inference has.
 *
 * `inferInfix`'s fall-through infers both operands before it can decide whether the infix is a call
 * to an instance method, and the rewrite then runs the ordinary call path, which infers both of
 * them a second time. That second walk is the whole of the operand: in a right-nested chain --
 * which is exactly what a `do` block desugars into -- it covers every remaining statement at every
 * level, so typing a block of N statements cost a quadratic in N (a 1600-statement block took 158
 * seconds, the same program written as an explicit chain about two). The two types are handed to
 * `inferApply` instead; the call is built, parked and elaborated exactly as it was, and each
 * operand is inferred once.
 *
 * `$mirrorSurface` picks what the rewrite ends in, matching the site that asked for it: the sites
 * that replaced an infix with a call mirror the call's type onto the node the rewrite moved away
 * from, and the two that elaborate in place do not.
 */
function inferRewrittenInfixFromOperands(
    TypeCheckState $state,
    Ast\AstNode &$expr,
    string $op,
    Type $leftType,
    Type $rightType,
    array $env,
    bool $mirrorSurface,
): Type {
    $left = $expr->left;
    $right = $expr->right;
    $surface = $expr;
    $line = $expr->line;
    $col = $expr->col;
    $endCol = $expr->endCol;
    $expr = infixApplicationNode($op, $left, $right, $line, $col, $endCol);

    $state->knownOperandTypes[\spl_object_id($left)] = $leftType;
    $state->knownOperandTypes[\spl_object_id($right)] = $rightType;

    try {
        return $mirrorSurface
            ? inferRewrittenInfix($state, $surface, $expr, $env)
            : inferApplyAndElaborate($state, $expr, $env);
    } finally {
        unset(
            $state->knownOperandTypes[\spl_object_id($left)],
            $state->knownOperandTypes[\spl_object_id($right)],
        );
    }
}

function inferTuple(TypeCheckState $state, Ast\Tuple $expr, array $env): Type
{
    if (\count($expr->elements) === 0) {
        return new TUnit();
    }

    $types = [];
    foreach ($expr->elements as &$element) {
        $types[] = inferExpr($state, $element, $env);
    }
    unset($element);

    return new TCon('Tuple' . \count($types), $types);
}

function inferList(TypeCheckState $state, Ast\ListLit $expr, array $env): Type
{
    if (\count($expr->elements) === 0) {
        return new TCon('List', [freshType($state)]);
    }

    $elemType = inferExpr($state, $expr->elements[0], $env);
    for ($i = 1, $n = \count($expr->elements); $i < $n; ++$i) {
        unify($state, inferExpr($state, $expr->elements[$i], $env), $elemType, $expr->elements[$i]);
    }

    return new TCon('List', [$elemType]);
}

function inferLambda(TypeCheckState $state, Ast\Lambda $expr, array $env): Type
{
    $bodyExpected = freshType($state);
    $bodyEnv = $env;
    $paramTypes = [];
    $paramPatterns = [];

    foreach ($expr->params as $param) {
        $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;
        if (! $pattern instanceof Ast\PatVar && ! $pattern instanceof Ast\PatWild) {
            throw typeFail($state, 'lambda parameters must be variables', $param);
        }
        $paramPatterns[] = $pattern;
    }
    assertParamListLinear($state, $paramPatterns);

    $state->pushBinderScope();

    try {
        foreach ($expr->params as $param) {
            $pattern = $param instanceof Ast\LambdaParam ? $param->pattern : $param;

            $paramType = $param instanceof Ast\LambdaParam && $param->type !== null
                ? astType($state, $param->type)
                : freshType($state);
            [, $bodyEnv] = bindPattern($state, $pattern, $paramType, $bodyEnv);
            $paramTypes[] = $paramType;
        }

        unify($state, inferExpr($state, $expr->body, $bodyEnv), $bodyExpected);
    } finally {
        $state->popBinderScope();
    }

    $fnType = $bodyExpected;
    foreach (\array_reverse($paramTypes) as $paramType) {
        $fnType = new TArrow($paramType, $fnType);
    }

    return $fnType;
}

function inferLet(TypeCheckState $state, Ast\Let $expr, array $env): Type
{
    assertBindingGroupLinear($state, $expr->bindings);
    $state->pushBinderScope();

    try {
        $local = inferBindingGroups($state, $expr->bindings, $env, $expr->generalizeBindings);

        return inferExpr($state, $expr->body, $local);
    } finally {
        $state->popBinderScope();
    }
}

function inferWhere(TypeCheckState $state, Ast\Where $expr, array $env): Type
{
    assertBindingGroupLinear($state, $expr->bindings);
    $state->pushBinderScope();

    try {
        $local = inferBindingGroups($state, $expr->bindings, $env, true);

        return inferExpr($state, $expr->expr, $local);
    } finally {
        $state->popBinderScope();
    }
}

function inferCase(TypeCheckState $state, Ast\CaseExpr $expr, array $env): Type
{
    $scrutineeType = inferExpr($state, $expr->scrutinee, $env);
    $altEnvs = [];
    /** @var list<array<string, int>> $altScopes each arm's frame, saved for its body */
    $altScopes = [];

    foreach ($expr->alts as $alt) {
        $altEnv = $env;
        assertPatternLinear($state, $alt->pattern);
        $state->pushBinderScope();
        [$patType, $altEnv] = bindPattern($state, $alt->pattern, freshType($state), $altEnv, generalize: false);
        unify($state, $patType, $scrutineeType, $expr);
        $altEnvs[] = $altEnv;
        $altScopes[] = $state->binderScopes[\count($state->binderScopes) - 1];
        $state->popBinderScope();
    }

    checkCaseExhaustiveness($state, $expr, $scrutineeType);
    $expr->exhaustive = caseExhaustivenessProven($state, $expr, $scrutineeType);

    foreach ($expr->alts as $i => $alt) {
        $state->binderScopes[] = $altScopes[$i];
        try {
            $altEnvs[$i] = overloadedLiteralPatterns($state, $alt, $altEnvs[$i]);
            $altScopes[$i] = $state->binderScopes[\count($state->binderScopes) - 1];
        } finally {
            \array_pop($state->binderScopes);
        }
    }

    $result = null;
    foreach ($expr->alts as $i => $alt) {
        $state->binderScopes[] = $altScopes[$i];
        try {
            $bodyType = inferExpr($state, $alt->body, $altEnvs[$i]);
        } finally {
            \array_pop($state->binderScopes);
        }
        if ($result === null) {
            $result = $bodyType;
        } else {
            unifyCaseBranches($state, $bodyType, $result, $alt->body);
        }
    }

    return $result ?? new TUnit();
}

function inferGuards(TypeCheckState $state, Ast\GuardsExpr $expr, array $env): Type
{
    $result = null;
    foreach ($expr->clauses as $clause) {
        $guardType = inferExpr($state, $clause->guard, $env);
        unify($state, $guardType, new TCon('Bool'), $clause->guard);
        $bodyType = inferExpr($state, $clause->body, $env);
        if ($result === null) {
            $result = $bodyType;
        } else {
            unifyCaseBranches($state, $bodyType, $result, $clause->body);
        }
    }

    return $result ?? new TUnit();
}

function describeUnresolvedConstraints(TypeCheckState $state, array $constraints): string
{
    $rendered = [];
    foreach ($constraints as $constraint) {
        $class = $constraint->class;
        if ($class === null) {
            continue;
        }

        $args = \array_map(
            static fn (Type $arg): Type => prune($state, $arg),
            $constraint->args,
        );
        $rename = friendlyTypeVarNames($args);
        $parts = \array_map(static fn (Type $arg): string => typeToStringArgument($arg, $rename), $args);
        $rendered[$class . ' ' . \implode(' ', $parts)] = $class . (($parts === []) ? '' : ' ' . \implode(' ', $parts));
    }

    if ($rendered === []) {
        return 'unresolved type class constraint';
    }

    $list = \array_values($rendered);
    $noun = \count($list) === 1 ? 'constraint' : 'constraints';

    return "unresolved type class {$noun}: `" . \implode('`, `', $list) . '`';
}

/**
 * Refuse an expression tree that still carries an unresolved obligation.
 */
function assertNoPendingConstraintsInExpr(TypeCheckState $state, Ast\AstNode $expr, ?Ast\AstNode $at = null): void
{
    walkAstValues($expr, static function (Ast\AstNode $node) use ($state, $at): void {
        if ($node->pendingConstraints === []) {
            return;
        }

        throw typeFail($state, describeUnresolvedConstraints($state, $node->pendingConstraints), $at ?? $node);
    });
}

function unifyCaseBranches(TypeCheckState $state, Type $left, Type $right, Ast\AstNode $at): void
{
    $saved = $state->unifyMessage;
    $state->unifyMessage = static function (Type $l, Type $r): string {
        $rename = friendlyTypeVarNames([$l, $r]);

        return 'case branches disagree: expected `'
            . typeToString($r, $rename) . '`, found `' . typeToString($l, $rename) . '`';
    };

    try {
        unify($state, $left, $right, $at);
    } finally {
        $state->unifyMessage = $saved;
    }
}

function inferRecordCon(TypeCheckState $state, Ast\RecordCon $expr, array $env): Type
{
    $meta = lookupConstructorMeta($state, $expr->name)
        ?? throw typeFail($state, "unknown constructor {$expr->name}", $expr);
    recordValueFields($state, $expr, $meta);
    [$fieldTypes, $resultType] = instantiateConstructorTypes($state, $expr->name);

    foreach ($meta['fields'] as $i => $fieldName) {
        $fieldExpr = recordValueFieldExpr($expr, $fieldName);
        unify($state, inferExpr($state, $fieldExpr, $env), $fieldTypes[$i], $fieldExpr);
    }

    return $resultType;
}

function instantiateConstructorTypes(TypeCheckState $state, string $ctorName): array
{
    $ctorScheme = $state->env[$ctorName] ?? throw typeFail($state, "unknown constructor {$ctorName}");
    $type = instantiate($state, $ctorScheme);
    $fieldTypes = [];
    while ($type instanceof TArrow) {
        $fieldTypes[] = $type->from;
        $type = $type->to;
    }

    return [$fieldTypes, $type];
}

function recordValueFieldExpr(Ast\RecordCon $expr, string $fieldName): Ast\AstNode
{
    foreach ($expr->fields as $field) {
        if ($field->name === $fieldName) {
            return $field->expr;
        }
    }

    throw new \LogicException("missing record field `{$fieldName}`");
}

function inferQualifiedRef(TypeCheckState $state, Ast\QualifiedRef $expr, array $env): Type
{
    $module = $expr->module;
    $name = $expr->name;
    $origin = $state->qualifiedOrigins[$module][$name] ?? null;
    if ($origin !== null) {
        $scheme = $state->qualifiedEnv[$module][$name]
            ?? $env[$name]
            ?? throw typeFail($state, "undefined name `{$origin['module']}.{$name}`", $expr);
        $expr->backendResolved = resolvedSymbol($origin['module'], $origin['phpName']);
        if (isset($state->intrinsicWrappers[$name])) {
            $expr->intrinsicWrapper = $state->intrinsicWrappers[$name];
        }

        return instantiateQualifiedScheme($state, $expr, $scheme);
    }

    $originModule = $state->qualifiedModules[$module] ?? null;
    if ($originModule === null) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown module prefix `{$module}`",
                $module,
                \array_keys($state->qualifiedModules),
            ),
            $expr,
        );
    }

    $scheme = $state->qualifiedEnv[$module][$name]
        ?? $env[$name]
        ?? throw typeFail($state, "undefined name `{$originModule}.{$name}`", $expr);
    $expr->backendResolved = resolvedSymbol($originModule, $name);
    if (isset($state->intrinsicWrappers[$name])) {
        $expr->intrinsicWrapper = $state->intrinsicWrappers[$name];
    }

    return instantiateQualifiedScheme($state, $expr, $scheme);
}

/**
 * Instantiate a qualified reference's scheme and keep the constraints on the
 * node. A qualified name is a value like any other, so `Mod.constrained` used
 * as an argument still has to carry its dictionaries to the use site
 * (`tryResolveValueEvidence` reads them off the node); dropping them here left
 * the call with a bare function value, e.g. Data.List.NonEmpty's
 * `liftList (DL.sort)`. `inferVariable` has always done this.
 */
function instantiateQualifiedScheme(TypeCheckState $state, Ast\QualifiedRef $expr, Scheme $scheme): Type
{
    $instantiated = instantiateScheme($state, $scheme);
    if ($instantiated['constraints'] !== []) {
        $expr->pendingConstraints = $instantiated['constraints'];
        $expr->pendingDicts = $instantiated['dicts'];
    }

    return $instantiated['type'];
}

function inferFieldAccess(TypeCheckState $state, Ast\AstNode &$expr, array $env): Type
{
    if (! $expr instanceof Ast\FieldAccess) {
        throw typeFail($state, 'expected field_access', $expr);
    }

    $object = $expr->object;
    if (($object instanceof Ast\ConstructorRef || $object instanceof Ast\Variable)
        && isset($state->qualifiedModules[$object->name])) {
        $expr = new Ast\QualifiedRef($object->name, $expr->field, $object->line, $object->col, $object->endCol);

        return inferQualifiedRef($state, $expr, $env);
    }

    $objectType = prune($state, inferExpr($state, $expr->object, $env));

    if ($objectType instanceof TCon && isset($state->data[$objectType->name])) {
        $fieldInfo = lookupFieldOnType($state, $objectType->name, $expr->field)
            ?? throw typeFail(
                $state,
                appendDidYouMean(
                    "record type `{$objectType->name}` has no field `{$expr->field}`",
                    $expr->field,
                    recordFieldNames($state, $objectType->name),
                ),
                $expr,
            );
        annotateFieldAccess($state, $expr, $objectType->name, $fieldInfo['fieldIndex']);

        return instantiateFieldTypeForObject($state, $objectType, $fieldInfo['type']);
    }

    $candidates = fieldCandidates($state, $expr->field);
    if ($candidates === []) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown field `{$expr->field}`",
                $expr->field,
                allFieldNames($state),
            ),
            $expr,
        );
    }

    if ($objectType instanceof TVar) {
        if (\count($candidates) === 1) {
            $candidate = $candidates[0];
            $freshDataType = freshDataTypeFromName($state, $candidate['dataName']);
            unify($state, $objectType, $freshDataType, $expr->object);
            annotateFieldAccess($state, $expr, $candidate['dataName'], $candidate['fieldIndex']);

            return instantiateFieldTypeForObject($state, $freshDataType, $candidate['type']);
        }

        throw typeFail(
            $state,
            "ambiguous field `{$expr->field}`; add a type signature",
            $expr,
        );
    }

    throw typeFail(
        $state,
        'field access requires a record value, found `' . typeToString($objectType, friendlyTypeVarNames([$objectType])) . '`',
        $expr,
    );
}

/**
 * `r { f = e }`: the receiver with the named fields replaced, and the receiver's
 * own type.
 *
 * The written fields name the constructor to rebuild; the fields the update does
 * not mention are read back off the receiver at each constructor position (IR
 * lowering does that from the constructor it is told here).
 */
function inferRecordUpdate(TypeCheckState $state, Ast\RecordUpdate $expr, array $env): Type
{
    if ($expr->fields === []) {
        throw typeFail($state, 'a record update names at least one field', $expr);
    }

    $objectType = prune($state, inferExpr($state, $expr->object, $env));
    if ($objectType instanceof TVar) {
        $objectType = resolveRecordUpdateReceiver($state, $expr, $objectType);
    }

    if (! ($objectType instanceof TCon) || ! isset($state->data[$objectType->name])) {
        throw typeFail(
            $state,
            'record update requires a record value, found `'
                . typeToString($objectType, friendlyTypeVarNames([$objectType])) . '`',
            $expr,
        );
    }

    $dataName = $objectType->name;
    [$constructor, $positions] = recordUpdateConstructor($state, $expr, $dataName);
    $expr->constructor = $constructor;
    $expr->fieldOnNewtype = (bool) ($state->data[$dataName]['newtype'] ?? false);

    [$fieldTypes, $resultType] = instantiateConstructorTypes($state, $constructor);
    unify($state, $resultType, $objectType, $expr->object);
    foreach ($expr->fields as $field) {
        $type = $fieldTypes[$positions[$field->name]];
        unify($state, inferExpr($state, $field->expr, $env), $type, $field->expr);
    }

    return $objectType;
}

/**
 * A receiver whose type is not known yet is pinned by the fields the update names,
 * the same way a projection is: the record that has them all is the record here.
 *
 * @return TCon
 */
function resolveRecordUpdateReceiver(TypeCheckState $state, Ast\RecordUpdate $expr, TVar $objectType): Type
{
    $candidates = null;
    foreach ($expr->fields as $field) {
        $here = [];
        foreach (fieldCandidates($state, $field->name) as $candidate) {
            $here[$candidate['dataName']] = true;
        }
        $candidates = $candidates === null ? $here : \array_intersect_key($candidates, $here);
    }

    $names = \array_keys($candidates ?? []);
    if (\count($names) === 1) {
        $fresh = freshDataTypeFromName($state, $names[0]);
        unify($state, $objectType, $fresh, $expr->object);

        return $fresh;
    }

    $first = $expr->fields[0];
    if ($names === []) {
        throw typeFail(
            $state,
            appendDidYouMean(
                "unknown field `{$first->name}`",
                $first->name,
                allFieldNames($state),
            ),
            $expr,
        );
    }

    throw typeFail(
        $state,
        "ambiguous record update: `{$first->name}` is a field of " . \implode(' and ', $names)
            . '; add a type signature',
        $expr,
    );
}

/**
 * The constructor an update rebuilds, and each written field's position in it.
 *
 * A field name can be shared by records, and by constructors of one record, so
 * the update's own field names pick the constructor — the one that carries every
 * one of them. It has to be the type's only constructor: the answer to "what does
 * the receiver look like now" has to hold for the value that arrives at runtime,
 * and with a second constructor it cannot. `base` reaches for a selector and
 * raises when the value was built by the other one; Moggi refuses the shape at
 * compile time, which is the whole point of not having a runtime case for it.
 *
 * @return array{0: string, 1: array<string, int>}
 */
function recordUpdateConstructor(TypeCheckState $state, Ast\RecordUpdate $expr, string $dataName): array
{
    $data = $state->data[$dataName];
    $carrying = [];
    foreach ($data['constructors'] as $constructor => $ctor) {
        $positions = [];
        foreach ($ctor['fields'] as $position => $name) {
            if ($name !== null && $name !== '') {
                $positions[$name] = $position;
            }
        }

        $holds = true;
        foreach ($expr->fields as $field) {
            if (! isset($positions[$field->name])) {
                $holds = false;

                break;
            }
        }

        if ($holds) {
            $carrying[] = [$constructor, $positions];
        }
    }

    if ($carrying === []) {
        $known = recordFieldNames($state, $dataName);
        foreach ($expr->fields as $field) {
            if (! \in_array($field->name, $known, true)) {
                throw typeFail(
                    $state,
                    appendDidYouMean(
                        "record type `{$dataName}` has no field `{$field->name}`",
                        $field->name,
                        $known,
                    ),
                    $expr,
                );
            }
        }

        throw typeFail(
            $state,
            'no constructor of `' . $dataName . '` carries every field the update names: `'
                . \implode('`, `', \array_map(static fn (Ast\RecordField $f): string => $f->name, $expr->fields))
                . '`',
            $expr,
        );
    }

    $constructors = \array_keys($data['constructors']);
    if (\count($carrying) > 1 || \count($constructors) > 1) {
        throw typeFail(
            $state,
            "record update needs a `{$dataName}` with one constructor, but it has: `"
                . \implode('`, `', $constructors) . '`',
            $expr,
        );
    }

    return $carrying[0];
}
