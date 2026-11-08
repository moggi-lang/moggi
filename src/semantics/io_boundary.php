<?php declare(strict_types=1);

namespace Moggi\Semantics\IoBoundary;

use Moggi\IR\EntryPointKind;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\IntrinsicRegistry\ioPrimopRole;
use function Moggi\Semantics\IntrinsicRegistry\isIoIntrinsic;
use function Moggi\Semantics\IntrinsicRegistry\primopArity;
use function Moggi\Semantics\IntrinsicRegistry\primopParameterTypes;
use function Moggi\Semantics\Kernel\bindRole;
use function Moggi\Semantics\Kinds\canonicalTypeConName;

/**
 * Validate IO surface constraints on a typed program.
 */
function validate(Ast\Program $program, string $source = '', string $filename = ''): void
{
    foreach ($program->items as $item) {
        validateItem($item, $source, $filename);
    }
}

function validateItem(Ast\AstNode $item, string $source, string $filename): void
{
    match (\get_class($item)) {
        Ast\FunctionDecl::class => validateFunction($item, $source, $filename),
        Ast\DataDecl::class => validateData($item, $source, $filename),
        Ast\TypeSynonymDecl::class => validateTypeSynonym($item, $source, $filename),
        default => null,
    };
}

function validateTypeSynonym(Ast\TypeSynonymDecl $item, string $source, string $filename): void
{
    if ($item->type === null) {
        return;
    }

    $head = synonymHead($item->type);
    if ($head !== null && canonicalTypeConName($head) === $item->name) {
        return;
    }

    validateTypeNoIo($item->type, $item, $source, $filename);
}

/**
 * The head constructor of a synonym RHS (`IORef#` for `type IORef a = IORef# a`).
 *
 * A synonym that *is* its primop's public spelling — the name is the kernel id
 * under the readable spelling — is exempt: confinement applies at use sites
 * through the name itself, exactly as for the transparent newtype wrapper
 * below. A synonym that hides a cell under an unrelated name stays rejected.
 */
function synonymHead(Ast\TypeNode $type): ?string
{
    while ($type instanceof Ast\TypeApp && $type->con instanceof Ast\TypeNode) {
        $type = $type->con;
    }

    return $type instanceof Ast\TypeCon ? $type->name : null;
}

/**
 * The primop a declaration's body applies, when the whole body is a call of one.
 *
 * The declaration that runs an action is written once, as a thin binding over the
 * primop that does it (`unsafePerformIO action = unsafePerformIO# action`), and
 * that shape is what the surface rule below has to be able to recognise.
 */
function bodyPrimopName(Ast\AstNode $body): ?string
{
    $head = $body;
    while ($head instanceof Ast\Apply) {
        $head = $head->function;
    }

    return match ($head::class) {
        Ast\IntrinsicCall::class, Ast\Variable::class => $head->name,
        default => null,
    };
}

/**
 * A declaration whose signature takes an action because that is its whole job.
 *
 * A run of an action (`unsafePerformIO# :: IO a -> a`) names `IO` in parameter
 * position on purpose, so it is the one place the surface rule cannot apply: the
 * crossing happens here, spelled out, and every other declaration keeps the
 * guarantee that a pure signature cannot perform effects. The test is the
 * primop's own scheme, not a declaration's name.
 */
function isActionRunDeclaration(Ast\FunctionDecl $fn): bool
{
    $name = bodyPrimopName($fn->body);
    if ($name === null) {
        return false;
    }

    foreach (primopParameterTypes($name) as $parameterType) {
        if (isIoCheckedType($parameterType)) {
            return true;
        }
    }

    return false;
}

function validateFunction(Ast\FunctionDecl $fn, string $source, string $filename): void
{
    if ($fn->signatureOnly) {
        return;
    }

    if ($fn->type !== null && ! isActionRunDeclaration($fn)) {
        validateFunctionTypeIoSurface($fn->type, $source, $filename);
    }

    if ($fn->entryKind === EntryPointKind::Main && ioReturnKind($fn->type) === 'none') {
        fail('entry point `main` must have type IO a', $fn->type ?? $fn, $source, $filename);
    }

    if (functionReturnsIo($fn->type)) {
        if ($fn->params === [] && hasLeadingArrow($fn->type)) {
            $inner = lambdaChainBody($fn->body);
            if ($inner !== null) {
                validateStraightLineIoBody($inner, $source, $filename);
            }

            return;
        }

        validateStraightLineIoBody($fn->body, $source, $filename);

        return;
    }

    validatePureExpr($fn->body, $source, $filename);
}

function validateData(Ast\DataDecl $item, string $source, string $filename): void
{
    if (isTransparentPrimopWrapper($item)) {
        return;
    }

    foreach ($item->constructors as $ctor) {
        foreach ($ctor->fields as $field) {
            validateTypeNoIo($field->type, $field->type, $source, $filename);
        }
    }
}

/**
 * A newtype that *is* its primop's public spelling (`newtype IOArray i e =
 * MkIOArray (IOArray# i e)`): a single constructor with a single field whose
 * head is the primop the declaration canonically names. Transparent rather
 * than hiding — confinement applies at use sites through the name itself —
 * so it is exempt. Anything else stays rejected: that would smuggle IO
 * state behind an innocent name.
 */
function isTransparentPrimopWrapper(Ast\DataDecl $item): bool
{
    if (\count($item->constructors) !== 1) {
        return false;
    }

    $fields = $item->constructors[0]->fields;
    if (\count($fields) !== 1) {
        return false;
    }

    $head = transparentWrapperHead($fields[0]->type);

    return $head !== null && canonicalTypeConName($head) === $item->name;
}

/** The head constructor of a wrapper field type (`IOArray#` for `IOArray# i e`). */
function transparentWrapperHead(Ast\TypeNode $type): ?string
{
    while ($type instanceof Ast\TypeApp && $type->con instanceof Ast\TypeNode) {
        $type = $type->con;
    }

    return $type instanceof Ast\TypeCon ? $type->name : null;
}

function validateFunctionTypeIoSurface(Ast\TypeNode $type, string $source, string $filename): void
{
    $result = stripConstraints($type);
    $return = $result;
    while ($return instanceof Ast\TypeArrow) {
        $return = $return->to;
    }

    $returnsIo = isIoType($return);

    $result = stripConstraints($type);
    while ($result instanceof Ast\TypeArrow) {
        if (! $returnsIo) {
            validateTypeNoIo($result->from, $result->from, $source, $filename);
        }
        $result = $result->to;
    }

    if (! isIoType($result)) {
        validateTypeNoIo($result, $result, $source, $filename);
    }
}

function validateTypeNoIo(Ast\TypeNode $type, Ast\AstNode $at, string $source, string $filename): void
{
    if (typeMentionsIo($type)) {
        fail('IO values are forbidden outside an IO-returning function', $at, $source, $filename);
    }
}

/** @param ?Ast\TypeNode $type */
function ioReturnKind(?Ast\TypeNode $type): string
{
    if ($type === null) {
        return 'none';
    }

    $result = stripConstraints($type);
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    if (! isIoType($result)) {
        return 'none';
    }

    return isIoUnitType($result) ? 'unit' : 'other';
}

/** @param ?Ast\TypeNode $type */
function functionReturnsIo(?Ast\TypeNode $type): bool
{
    return ioReturnKind($type) !== 'none';
}

/** Whether a declared type is a function (a leading arrow behind any constraints). */
function hasLeadingArrow(?Ast\TypeNode $type): bool
{
    return $type !== null && stripConstraints($type) instanceof Ast\TypeArrow;
}

/**
 * The body of the innermost lambda in `f = \a -> \b -> <body>`, or null when
 * `$expr` is not a lambda at all.
 */
function lambdaChainBody(Ast\AstNode $expr): ?Ast\AstNode
{
    if (! $expr instanceof Ast\Lambda) {
        return null;
    }

    $inner = $expr;
    while ($inner->body instanceof Ast\Lambda) {
        $inner = $inner->body;
    }

    return $inner->body;
}

function stripConstraints(Ast\TypeNode $type): Ast\TypeNode
{
    if ($type instanceof Ast\TypeConstrained) {
        return $type->body;
    }

    return $type;
}

/** The IO type constructor: the kernel id `IO#`, or the readable name the library publishes it under. */
function isIoTypeConName(string $name): bool
{
    return $name === 'IO#' || $name === canonicalTypeConName('IO#');
}

/**
 * The mutable IO array type constructor: the kernel id `IOArray#`, or the
 * name the array library publishes it under. An `IOArray` value is IO state
 * like an `IO` action itself: `ST` functions cannot take one, and neither
 * `ST` nor pure results may mention it.
 */
function isIoArrayTypeConName(string $name): bool
{
    return $name === 'IOArray#' || $name === canonicalTypeConName('IOArray#');
}

/**
 * The mutable `IO` cell type constructor: the kernel id `IORef#`, or the name
 * the library publishes it under. A cell is IO state exactly like a mutable
 * array: `ST` functions cannot take one, and neither `ST` nor pure results
 * may mention it.
 */
function isIoRefTypeConName(string $name): bool
{
    return $name === 'IORef#' || $name === canonicalTypeConName('IORef#');
}

function isIoType(Ast\TypeNode $type): bool
{
    return $type instanceof Ast\TypeApp
        && $type->con instanceof Ast\TypeCon
        && isIoTypeConName($type->con->name);
}

function isIoUnitType(Ast\TypeNode $type): bool
{
    return isIoType($type)
        && \count($type->args) === 1
        && $type->args[0] instanceof Ast\TypeUnit;
}

/**
 * The same IO tests over the checker's own types — the authority a term's type
 * carries.
 *
 * A declaration's signature is still read as the source syntax it was written
 * as, so the projection-based tests above keep their callers; a *term*, whose
 * annotation is only a projection, is read here.
 */
function isIoCheckedType(Type $type): bool
{
    return $type instanceof TCon
        && isIoTypeConName($type->name)
        && \count($type->args) === 1;
}

function isIoUnitCheckedType(Type $type): bool
{
    return isIoCheckedType($type) && $type->args[0] instanceof TUnit;
}

function isNestedIoCheckedType(Type $type): bool
{
    return isIoCheckedType($type) && isIoCheckedType($type->args[0]);
}

function typeMentionsIo(Ast\TypeNode $type): bool
{
    return ($type instanceof Ast\TypeCon
            && (isIoTypeConName($type->name)
                || isIoArrayTypeConName($type->name)
                || isIoRefTypeConName($type->name)))
        || ($type instanceof Ast\TypeApp
            && (typeMentionsIo($type->con)
                || \array_any($type->args, static fn (Ast\TypeNode $arg): bool => typeMentionsIo($arg))))
        || ($type instanceof Ast\TypeArrow && (typeMentionsIo($type->from) || typeMentionsIo($type->to)))
        || ($type instanceof Ast\TypeConstrained
            && (\array_any($type->constraints, static fn (Ast\TypeNode $constraint): bool => typeMentionsIo($constraint))
                || typeMentionsIo($type->body)));
}

function validateStraightLineIoBody(Ast\AstNode $expr, string $source, string $filename): void
{
    if ($expr instanceof Ast\DoExpr) {
        validateStraightLineDo($expr->stmts, $source, $filename);

        return;
    }

    if ($expr instanceof Ast\CaseExpr) {
        validateStraightLineCase($expr, $source, $filename);

        return;
    }

    if ($expr instanceof Ast\IntrinsicCall && isIoIntrinsic($expr->name)) {
        validateIoIntrinsic($expr, $source, $filename);

        return;
    }

    if (ioBindParts($expr) !== null) {
        validateIoBindExpr($expr, $source, $filename);

        return;
    }

    if (! exprReturnsIo($expr)) {
        fail('IO function body must evaluate to an IO expression', $expr, $source, $filename);
    }

    validateIoActionArgs($expr, $source, $filename);
}

/**
 * The IO construct's arity is the one its scheme declares, so the check here cannot disagree with
 * the signature the checker resolved; which construct it is comes from the registry's roles.
 */
function validateIoIntrinsic(Ast\IntrinsicCall $expr, string $source, string $filename): void
{
    $role = ioPrimopRole($expr->name);
    $arity = primopArity($expr->name);
    if ($role === null || $arity === null) {
        fail('unknown IO intrinsic', $expr, $source, $filename);
    }

    if (\count($expr->args) !== $arity) {
        fail(
            \sprintf('%s expects %d argument%s', $expr->name, $arity, $arity === 1 ? '' : 's'),
            $expr,
            $source,
            $filename,
        );
    }

    if ($role === 'io-pure' || $role === 'io-throw') {
        validatePureExpr($expr->args[0], $source, $filename);

        return;
    }

    if ($role === 'io-bind') {
        if (! exprReturnsIo($expr->args[0])) {
            fail('left side of IO bind must return IO', $expr->args[0], $source, $filename);
        }
        validateIoActionArgs($expr->args[0], $source, $filename);
        validateIoBindContinuation($expr->args[1], $source, $filename);

        return;
    }

    if ($role === 'io-mfix') {
        validateIoBindContinuation($expr->args[0], $source, $filename);

        return;
    }

    if ($role === 'io-action') {
        if ($expr->name === 'mkWeak#' || $expr->name === 'mkWeakPair#') {
            validatePureExpr($expr->args[0], $source, $filename);
            validatePureExpr($expr->args[1], $source, $filename);
            if (! exprReturnsIo($expr->args[2])) {
                fail('weak finalizer must return IO', $expr->args[2], $source, $filename);
            }
            validateIoBuildExpr($expr->args[2], $source, $filename);

            return;
        }
        foreach ($expr->args as $arg) {
            validatePureExpr($arg, $source, $filename);
        }

        return;
    }

    if ($role === 'io-catch') {
        if (! exprReturnsIo($expr->args[0])) {
            fail('exceptionCatch# action must return IO', $expr->args[0], $source, $filename);
        }
        validateIoBuildExpr($expr->args[0], $source, $filename);
        validateIoBindContinuation($expr->args[1], $source, $filename);

        return;
    }

    if (! exprReturnsIo($expr->args[0])) {
        fail('exceptionFinally# action must return IO', $expr->args[0], $source, $filename);
    }
    if (! exprReturnsIo($expr->args[1])) {
        fail('exceptionFinally# cleanup must return IO', $expr->args[1], $source, $filename);
    }
    validateIoBuildExpr($expr->args[0], $source, $filename);
    validateIoBuildExpr($expr->args[1], $source, $filename);
}

function validateIoBindContinuation(Ast\AstNode $expr, string $source, string $filename): void
{
    if ($expr instanceof Ast\Lambda) {
        validateStraightLineIoBody($expr->body, $source, $filename);

        return;
    }

    if (exprReturnsIo($expr)) {
        validateIoBuildExpr($expr, $source, $filename);

        return;
    }

    if (exprIsIoContinuation($expr)) {
        validateIoActionArgs($expr, $source, $filename);

        return;
    }

    validatePureExpr($expr, $source, $filename);
}

/** @param list<Ast\AstNode> $stmts */
function validateStraightLineDo(array $stmts, string $source, string $filename): void
{
    foreach ($stmts as $i => $stmt) {
        $last = $i === \count($stmts) - 1;
        if ($stmt instanceof Ast\DoLet) {
            foreach ($stmt->bindings as $binding) {
                if (doBindingHasIoType($binding)) {
                    validateIoBuildExpr($binding->expr, $source, $filename);
                } else {
                    validatePureExpr($binding->expr, $source, $filename);
                }
            }

            continue;
        }

        if ($stmt instanceof Ast\DoBind) {
            if (! exprReturnsIo($stmt->expr)) {
                fail('do-bind expression must return IO', $stmt->expr, $source, $filename);
            }
            validateIoActionArgs($stmt->expr, $source, $filename);

            continue;
        }

        if (! $stmt instanceof Ast\DoExprStmt) {
            fail('invalid IO statement', $stmt, $source, $filename);
        }

        if ($stmt->expr instanceof Ast\CaseExpr) {
            validateStraightLineCase($stmt->expr, $source, $filename);

            continue;
        }

        if ($stmt->expr instanceof Ast\DoExpr) {
            validateStraightLineIoBody($stmt->expr, $source, $filename);

            continue;
        }

        if (ioBindParts($stmt->expr) !== null) {
            validateIoBindExpr($stmt->expr, $source, $filename);

            continue;
        }

        if ($last && $stmt->expr instanceof Ast\Variable) {
            $varType = $stmt->expr->inferredInternalType;
            if ($varType !== null && isIoCheckedType($varType)) {
                continue;
            }
        }

        $stmtType = $stmt->expr->inferredInternalType;
        if (! $last && ! ($stmtType !== null && isIoUnitCheckedType($stmtType))) {
            fail('intermediate IO statements must return ()', $stmt->expr, $source, $filename);
        }

        if (! $last && ! exprReturnsIo($stmt->expr)) {
            fail('intermediate IO statements must be IO expressions', $stmt->expr, $source, $filename);
        }

        if ($last && ! exprReturnsIo($stmt->expr)) {
            fail('IO blocks must end with an IO () action', $stmt->expr, $source, $filename);
        }

        validateIoActionArgs($stmt->expr, $source, $filename);
    }
}

function validateStraightLineCase(Ast\CaseExpr $expr, string $source, string $filename): void
{
    validatePureExpr($expr->scrutinee, $source, $filename);
    foreach ($expr->alts as $alt) {
        validateStraightLineIoBody($alt->body, $source, $filename);
    }
}

function validatePureExpr(Ast\AstNode $expr, string $source, string $filename): void
{
    if (exprReturnsIo($expr)) {
        fail('IO expressions cannot be used as pure values', $expr, $source, $filename);
    }

    match ($expr::class) {
        Ast\Infix::class => validateInfix($expr, $source, $filename),
        Ast\Apply::class => validateApply($expr, $source, $filename),
        Ast\Lambda::class => validatePureExpr($expr->body, $source, $filename),
        Ast\DoExpr::class => validatePureDo($expr, $source, $filename),
        Ast\Let::class => validateLet($expr, $source, $filename),
        Ast\Where::class => validateWhere($expr, $source, $filename),
        Ast\CaseExpr::class => validatePureCase($expr, $source, $filename),
        Ast\RecordCon::class => (static function () use ($expr, $source, $filename): void {
            foreach ($expr->fields as $field) {
                validatePureExpr($field->expr, $source, $filename);
            }
        })(),
        Ast\RecordUpdate::class => (static function () use ($expr, $source, $filename): void {
            validatePureExpr($expr->object, $source, $filename);
            foreach ($expr->fields as $field) {
                validatePureExpr($field->expr, $source, $filename);
            }
        })(),
        Ast\FieldAccess::class => validatePureExpr($expr->object, $source, $filename),
        Ast\TypeAsc::class => (static function () use ($expr, $source, $filename): void {
            validateTypeNoIo($expr->type, $expr->type, $source, $filename);
            validatePureExpr($expr->expr, $source, $filename);
        })(),
        Ast\IntrinsicCall::class => validateIntrinsic($expr, $source, $filename),
        Ast\ForeignCall::class => (static function () use ($expr, $source, $filename): void {
            foreach ($expr->args as $arg) {
                validatePureExpr($arg, $source, $filename);
            }
        })(),
        Ast\Tuple::class, Ast\ListLit::class => validateContainerElements($expr->elements, $source, $filename),
        default => null,
    };
}

/** @param list<Ast\AstNode> $elements */
function validateContainerElements(array $elements, string $source, string $filename): void
{
    foreach ($elements as $element) {
        if (exprReturnsIo($element)) {
            validateIoBuildExpr($element, $source, $filename);
        } else {
            validatePureExpr($element, $source, $filename);
        }
    }
}

function validatePureDo(Ast\DoExpr $expr, string $source, string $filename): void
{
    foreach ($expr->stmts as $stmt) {
        if ($stmt instanceof Ast\DoBind) {
            validatePureExpr($stmt->expr, $source, $filename);

            continue;
        }

        if ($stmt instanceof Ast\DoLet) {
            foreach ($stmt->bindings as $binding) {
                validatePureExpr($binding->expr, $source, $filename);
            }

            continue;
        }

        if ($stmt instanceof Ast\DoExprStmt) {
            validatePureExpr($stmt->expr, $source, $filename);
        }
    }
}

function validateInfix(Ast\Infix $expr, string $source, string $filename): void
{
    validatePureExpr($expr->left, $source, $filename);
    validatePureExpr($expr->right, $source, $filename);
}

/**
 * The value-argument types of a flattened application's head, in order, up to
 * `$count`.
 *
 * A class method carries an arrow per constraint it needs before its value
 * arguments; those arrows are dictionary plumbing and say nothing about what a
 * written argument is passed at, so they are skipped. What is left lines up with
 * the arguments as they were written.
 *
 * @return list<Type>
 */
function calleeArgumentTypes(Ast\AstNode $function, int $count): array
{
    $type = $function->inferredInternalType ?? null;
    $types = [];
    while ($type instanceof TArrow && \count($types) < $count) {
        $from = $type->from;
        if ($from instanceof TCon && \str_starts_with($from->name, '__Dict_')) {
            $type = $type->to;
            continue;
        }
        $types[] = $from;
        $type = $type->to;
    }

    return $types;
}

/**
 * An application in value position.
 *
 * An argument whose type is IO is refused here — a pure expression may not hold
 * an action — unless the callee's own parameter is that IO type: `liftIO` and a
 * run of an action both take one, and what they do with it is the point of the
 * call. The parameter type is what decides, never the callee's name.
 */
function validateApply(Ast\Apply $expr, string $source, string $filename): void
{
    $parts = flattenApply($expr);
    validatePureExpr($parts['function'], $source, $filename);
    $argumentTypes = calleeArgumentTypes($parts['function'], \count($parts['args']));
    foreach ($parts['args'] as $index => $arg) {
        $parameterType = $argumentTypes[$index] ?? null;
        if (exprReturnsIo($arg) && $parameterType !== null && isIoCheckedType($parameterType)) {
            validateIoBuildExpr($arg, $source, $filename);

            continue;
        }
        validatePureExpr($arg, $source, $filename);
    }
}

function validateLet(Ast\Let $expr, string $source, string $filename): void
{
    foreach ($expr->bindings as $binding) {
        validatePureExpr($binding->value, $source, $filename);
    }
    validatePureExpr($expr->body, $source, $filename);
}

function validateWhere(Ast\Where $expr, string $source, string $filename): void
{
    validatePureExpr($expr->expr, $source, $filename);
    foreach ($expr->bindings as $binding) {
        validatePureExpr($binding->value, $source, $filename);
    }
}

function validatePureCase(Ast\CaseExpr $expr, string $source, string $filename): void
{
    validatePureExpr($expr->scrutinee, $source, $filename);
    foreach ($expr->alts as $alt) {
        validatePureExpr($alt->body, $source, $filename);
    }
}

/**
 * A primop call in value position.
 *
 * The primop's own parameter types decide where an action may be passed: the
 * primop that runs one (`unsafePerformIO#`) takes the `IO` type there, and every
 * other argument of every other primop stays a pure expression.
 */
function validateIntrinsic(Ast\IntrinsicCall $expr, string $source, string $filename): void
{
    $parameterTypes = primopParameterTypes($expr->name);
    foreach ($expr->args as $index => $arg) {
        $parameterType = $parameterTypes[$index] ?? null;
        if (exprReturnsIo($arg) && $parameterType !== null && isIoCheckedType($parameterType)) {
            validateIoBuildExpr($arg, $source, $filename);

            continue;
        }
        validatePureExpr($arg, $source, $filename);
    }
}

function validateIoActionArgs(Ast\AstNode $expr, string $source, string $filename): void
{
    if ($expr instanceof Ast\IntrinsicCall && isIoIntrinsic($expr->name)) {
        validateIoIntrinsic($expr, $source, $filename);

        return;
    }

    if ($expr instanceof Ast\ForeignCall || $expr instanceof Ast\IntrinsicCall) {
        foreach ($expr->args as $arg) {
            validatePureExpr($arg, $source, $filename);
        }

        return;
    }

    $parts = flattenApply($expr);
    foreach ($parts['args'] as $arg) {
        if (exprReturnsIo($arg)) {
            validateIoBuildExpr($arg, $source, $filename);
        } elseif (exprIsIoContinuation($arg)) {
            validateIoBindContinuation($arg, $source, $filename);
        } else {
            validatePureExpr($arg, $source, $filename);
        }
    }
}

function exprIsIoContinuation(Ast\AstNode $expr): bool
{
    $type = $expr->inferredInternalType;
    while ($type instanceof TArrow) {
        if (isIoCheckedType($type->to)) {
            return true;
        }
        $type = $type->to;
    }

    return false;
}

/**
 * Whether an expression can stand where IO is expected.
 *
 * This is the recorded type alone, so no stage asks for a name: `error` and `undefined` are
 * ordinary declarations (`Moggi.Err` types them `String -> a` and `a`), and a call to one unifies
 * with `IO a` like any other bottom. What the boundary needs — "this never returns" — is exactly
 * what let the checker give it the IO type in the first place.
 */
function exprReturnsIo(Ast\AstNode $expr): bool
{
    $type = $expr->inferredInternalType;

    return $type !== null && isIoCheckedType($type);
}

function validateIoBindExpr(Ast\AstNode $expr, string $source, string $filename): void
{
    $parts = ioBindParts($expr);
    if ($parts === null) {
        fail('IO bind expression must return IO', $expr, $source, $filename);
    }

    if (! exprReturnsIo($expr)) {
        fail('IO bind expression must return IO', $expr, $source, $filename);
    }

    if (! exprReturnsIo($parts['left'])) {
        fail('left side of IO bind must return IO', $parts['left'], $source, $filename);
    }

    validateIoActionArgs($parts['left'], $source, $filename);
    validateIoBindContinuation($parts['right'], $source, $filename);
}

function doBindingHasIoType(Ast\DoBind $binding): bool
{
    $type = $binding->expr->inferredInternalType;

    return $type !== null && isIoCheckedType($type);
}

function validateIoBuildExpr(Ast\AstNode $expr, string $source, string $filename): void
{
    if (exprReturnsIo($expr)) {
        $type = $expr->inferredInternalType;
        if ($type !== null && isNestedIoCheckedType($type)) {
            fail('nested IO actions are not supported', $expr, $source, $filename);
        }

        if (isUnsaturatedPartialIo($expr)) {
            fail('unsaturated partial application cannot be used as an IO action', $expr, $source, $filename);
        }

        validateIoActionArgs($expr, $source, $filename);

        return;
    }

    match ($expr::class) {
        Ast\Infix::class => validateInfixBuild($expr, $source, $filename),
        Ast\Apply::class => validateApplyBuild($expr, $source, $filename),
        Ast\Lambda::class => validateIoBuildExpr($expr->body, $source, $filename),
        Ast\DoExpr::class => validateIoBuildDo($expr, $source, $filename),
        Ast\Let::class => validateLetBuild($expr, $source, $filename),
        Ast\Where::class => validateWhereBuild($expr, $source, $filename),
        Ast\CaseExpr::class => validateIoBuildCase($expr, $source, $filename),
        Ast\RecordCon::class => (static function () use ($expr, $source, $filename): void {
            foreach ($expr->fields as $field) {
                validateIoBuildExpr($field->expr, $source, $filename);
            }
        })(),
        Ast\RecordUpdate::class => (static function () use ($expr, $source, $filename): void {
            validateIoBuildExpr($expr->object, $source, $filename);
            foreach ($expr->fields as $field) {
                validateIoBuildExpr($field->expr, $source, $filename);
            }
        })(),
        Ast\FieldAccess::class => validateIoBuildExpr($expr->object, $source, $filename),
        Ast\TypeAsc::class => (static function () use ($expr, $source, $filename): void {
            validateTypeNoIo($expr->type, $expr->type, $source, $filename);
            validateIoBuildExpr($expr->expr, $source, $filename);
        })(),
        Ast\IntrinsicCall::class => validateIntrinsicBuild($expr, $source, $filename),
        Ast\ForeignCall::class => (static function () use ($expr, $source, $filename): void {
            foreach ($expr->args as $arg) {
                validateIoBuildExpr($arg, $source, $filename);
            }
        })(),
        Ast\Tuple::class, Ast\ListLit::class => (static function () use ($expr, $source, $filename): void {
            foreach ($expr->elements as $element) {
                validateIoBuildExpr($element, $source, $filename);
            }
        })(),
        default => null,
    };
}

function validateInfixBuild(Ast\Infix $expr, string $source, string $filename): void
{
    validateIoBuildExpr($expr->left, $source, $filename);
    validateIoBuildExpr($expr->right, $source, $filename);
}

function validateApplyBuild(Ast\Apply $expr, string $source, string $filename): void
{
    $parts = flattenApply($expr);
    validateIoBuildExpr($parts['function'], $source, $filename);
    foreach ($parts['args'] as $arg) {
        validateIoBuildExpr($arg, $source, $filename);
    }
}

function validateLetBuild(Ast\Let $expr, string $source, string $filename): void
{
    foreach ($expr->bindings as $binding) {
        validateIoBuildExpr($binding->value, $source, $filename);
    }
    validateIoBuildExpr($expr->body, $source, $filename);
}

function validateWhereBuild(Ast\Where $expr, string $source, string $filename): void
{
    validateIoBuildExpr($expr->expr, $source, $filename);
    foreach ($expr->bindings as $binding) {
        validateIoBuildExpr($binding->value, $source, $filename);
    }
}

function validateIoBuildDo(Ast\DoExpr $expr, string $source, string $filename): void
{
    foreach ($expr->stmts as $stmt) {
        if ($stmt instanceof Ast\DoBind) {
            validateIoBuildExpr($stmt->expr, $source, $filename);

            continue;
        }

        if ($stmt instanceof Ast\DoLet) {
            foreach ($stmt->bindings as $binding) {
                validateIoBuildExpr($binding->expr, $source, $filename);
            }

            continue;
        }

        if ($stmt instanceof Ast\DoExprStmt) {
            validateIoBuildExpr($stmt->expr, $source, $filename);
        }
    }
}

function validateIoBuildCase(Ast\CaseExpr $expr, string $source, string $filename): void
{
    validatePureExpr($expr->scrutinee, $source, $filename);
    foreach ($expr->alts as $alt) {
        validateIoBuildExpr($alt->body, $source, $filename);
    }
}

function validateIntrinsicBuild(Ast\IntrinsicCall $expr, string $source, string $filename): void
{
    if (isIoIntrinsic($expr->name)) {
        validateIoIntrinsic($expr, $source, $filename);

        return;
    }

    foreach ($expr->args as $arg) {
        validatePureExpr($arg, $source, $filename);
    }
}

function isUnsaturatedPartialIo(Ast\AstNode $expr): bool
{
    if (! $expr instanceof Ast\Apply) {
        return false;
    }

    $type = $expr->inferredInternalType;
    if (! $type instanceof TArrow) {
        return false;
    }

    return ! isIoCheckedType($type->to);
}

/** @return ?array{left: Ast\AstNode, right: Ast\AstNode} */
function ioBindParts(Ast\AstNode $expr): ?array
{
    if (! $expr instanceof Ast\Apply) {
        return null;
    }

    $right = $expr->argument;
    $inner = $expr->function;
    if (! $inner instanceof Ast\Apply) {
        return null;
    }

    $method = $inner->function;
    if (! isIoMethodWithRole($method, bindRole())) {
        return null;
    }

    return ['left' => $inner->argument, 'right' => $right];
}

/**
 * The `IO` instance's method that fulfils a language role (`>>=`, `pure`).
 *
 * Two facts have to line up. The projection is ordinary instance evidence whose head is `IO` — not an
 * ambient `__io_*` dictionary — and the method is the one the language's role resolves to
 * ({@see \Moggi\Semantics\Kernel}), which the checker recorded on the evidence when it built it. Nothing
 * here reads the operator's spelling, so a local operator that shadows the monad's is a different
 * declaration and is not taken for it.
 */
function isIoMethodWithRole(Ast\AstNode $expr, string $role): bool
{
    if (! $expr instanceof Ast\EvidenceMethod || $expr->role !== $role) {
        return false;
    }

    $head = $expr->evidenceInstance;
    if ($head instanceof Ast\TypeCon && isIoTypeConName($head->name)) {
        return true;
    }

    return $head instanceof Ast\TypeApp
        && $head->con instanceof Ast\TypeCon
        && isIoTypeConName($head->con->name);
}

/** @return array{function: Ast\AstNode, args: list<Ast\AstNode>} */
function flattenApply(Ast\AstNode $expr): array
{
    $args = [];
    while ($expr instanceof Ast\Apply) {
        \array_unshift($args, $expr->argument);
        $expr = $expr->function;
    }

    return ['function' => $expr, 'args' => $args];
}

function fail(string $message, Ast\AstNode $at, string $source, string $filename): never
{
    throw new TypeError(
        $message,
        $filename,
        $source,
        $at->line,
        $at->col,
        $at->endCol,
    );
}

/**
 * The form of a `do` statement's expression (or of a `do` body) — the one action-versus-value
 * question, answered here and published on the node by {@see \Moggi\Semantics\IoEscape\analyze}.
 *
 * This is the only caller of this function: lowering reads the published field
 * (`Lower\Io::ioStatementFormOf`) and never re-asks, so a node cannot be classified twice with two
 * answers. `Deferred` is lexical: the producer passes the binder identities its `let`-bound actions
 * introduced in the enclosing sequence.
 *
 * Anything this function does not recognise is `Action`: an expression whose value is an action the
 * action ABI handles at the call site. That is deliberate — statement and body positions are
 * guarded at publication, where an unclassified shape is a compiler error rather than a quiet
 * fallback.
 *
 * @param array<int, true> $deferred binder identities a `let` bound to an action, in scope here
 */
function ioStatementForm(Ast\AstNode $expr, array $deferred): \Moggi\IR\IoStatementForm
{
    if ($expr instanceof Ast\IoPure) {
        return \Moggi\IR\IoStatementForm::Pure;
    }

    if ($expr instanceof Ast\IoCase) {
        return \Moggi\IR\IoStatementForm::Case;
    }

    if ($expr instanceof Ast\IoSequence) {
        return \Moggi\IR\IoStatementForm::Sequence;
    }

    $inner = $expr instanceof Ast\IoAction ? $expr->expr : $expr;
    if ($inner instanceof Ast\Variable
        && $inner->binderId !== null
        && isset($deferred[$inner->binderId])) {
        return \Moggi\IR\IoStatementForm::Deferred;
    }

    return \Moggi\IR\IoStatementForm::Action;
}

/**
 * Whether a node is one of the four shapes a `do` statement's expression (or a `do` body) can have.
 *
 * The answer is the AST's own marker ({@see Ast\IoStatementExpression}), not a list kept here, so a
 * new IO expression node is classified by declaring itself one. The producer refuses an expression
 * outside the marker, by class and line, so a new IO node reaches the boundary as an error rather
 * than as an unclassified expression lowering has to guess at. A nested `do` in bind position
 * arrived exactly that way once.
 */
function isIoStatementExpression(Ast\AstNode $expr): bool
{
    return $expr instanceof Ast\IoStatementExpression;
}
