<?php declare(strict_types=1);

namespace Moggi\Semantics\IoBoundary;

use Moggi\IR\EntryPointKind;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\isErrorCallExpr;

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
    match (get_class($item)) {
        Ast\FunctionDecl::class => validateFunction($item, $source, $filename),
        Ast\DataDecl::class => validateData($item, $source, $filename),
        Ast\TypeSynonymDecl::class => validateTypeSynonym($item, $source, $filename),
        default => null,
    };
}

function validateTypeSynonym(Ast\TypeSynonymDecl $item, string $source, string $filename): void
{
    // Official primitive type synonym: `type IO = IO#` (same pattern as Int/List).
    if ($item->name === 'IO'
        && $item->type instanceof Ast\TypeCon
        && $item->type->name === 'IO#') {
        return;
    }

    if ($item->name === 'SomeException'
        && $item->type instanceof Ast\TypeCon
        && $item->type->name === 'SomeException#') {
        return;
    }

    validateTypeNoIo($item->type, $item, $source, $filename);
}

function validateFunction(Ast\FunctionDecl $fn, string $source, string $filename): void
{
    if ($fn->signatureOnly) {
        return;
    }

    if ($fn->type !== null) {
        validateFunctionTypeIoSurface($fn->type, $source, $filename);
    }

    if ($fn->entryKind === EntryPointKind::Main && ioReturnKind($fn->type) === 'none') {
        fail('entry point `main` must have type IO a', $fn->type ?? $fn, $source, $filename);
    }

    if (functionReturnsIo($fn->type)) {
        // A binding that *is* a function has no IO-typed body: its action is the function's
        // body (`tryAny = try` is `tryAny action = try action`).
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
    foreach ($item->constructors as $ctor) {
        foreach ($ctor->fields as $field) {
            validateTypeNoIo($field->type, $field->type, $source, $filename);
        }
    }
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
        if (!$returnsIo) {
            validateTypeNoIo($result->from, $result->from, $source, $filename);
        }
        $result = $result->to;
    }

    if (!isIoType($result)) {
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

    if (!isIoType($result)) {
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
    if (!$expr instanceof Ast\Lambda) {
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

function isIoTypeConName(string $name): bool
{
    return $name === 'IO' || $name === 'IO#';
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
        && count($type->args) === 1
        && $type->args[0] instanceof Ast\TypeUnit;
}

function isNestedIoType(Ast\TypeNode $type): bool
{
    if (!isIoType($type)) {
        return false;
    }

    $inner = $type->args[0] ?? null;

    return $inner instanceof Ast\TypeNode && isIoType($inner);
}

function typeMentionsIo(Ast\TypeNode $type): bool
{
    return ($type instanceof Ast\TypeCon && isIoTypeConName($type->name))
        || ($type instanceof Ast\TypeApp
            && (typeMentionsIo($type->con)
                || array_any($type->args, static fn (Ast\TypeNode $arg): bool => typeMentionsIo($arg))))
        || ($type instanceof Ast\TypeArrow && (typeMentionsIo($type->from) || typeMentionsIo($type->to)))
        || ($type instanceof Ast\TypeConstrained
            && (array_any($type->constraints, static fn (Ast\TypeNode $constraint): bool => typeMentionsIo($constraint))
                || typeMentionsIo($type->body)));
}

function validateStraightLineIoBody(Ast\AstNode $expr, string $source, string $filename): void
{
    if ($expr instanceof Ast\DoExpr) {
        // Validate surface statements. The desugared >>= form is for typechecking
        // and normalization; bind continuations are IO bodies, not pure values.
        validateStraightLineDo($expr->stmts, $source, $filename);
        return;
    }

    if ($expr instanceof Ast\CaseExpr) {
        validateStraightLineCase($expr, $source, $filename);
        return;
    }

    if ($expr instanceof Ast\IntrinsicCall && isIoExceptionIntrinsic($expr->name)) {
        validateIoIntrinsic($expr, $source, $filename);
        return;
    }

    if (ioBindParts($expr) !== null) {
        validateIoBindExpr($expr, $source, $filename);
        return;
    }

    if (!exprIsIoCapable($expr)) {
        fail('IO function body must evaluate to an IO expression', $expr, $source, $filename);
    }

    validateIoActionArgs($expr, $source, $filename);
}

function isIoExceptionIntrinsic(string $name): bool
{
    return $name === 'ioBind#'
        || $name === 'ioPure#'
        || $name === 'exceptionThrowIo#'
        || $name === 'exceptionCatch#'
        || $name === 'exceptionFinally#';
}

function validateIoIntrinsic(Ast\IntrinsicCall $expr, string $source, string $filename): void
{
    if ($expr->name === 'ioPure#') {
        if (count($expr->args) !== 1) {
            fail('ioPure# expects one argument', $expr, $source, $filename);
        }
        validatePureExpr($expr->args[0], $source, $filename);
        return;
    }

    if ($expr->name === 'ioBind#') {
        if (count($expr->args) !== 2) {
            fail('ioBind# expects two arguments', $expr, $source, $filename);
        }
        if (!exprReturnsIo($expr->args[0])) {
            fail('left side of IO bind must return IO', $expr->args[0], $source, $filename);
        }
        validateIoActionArgs($expr->args[0], $source, $filename);
        validateIoBindContinuation($expr->args[1], $source, $filename);
        return;
    }

    if ($expr->name === 'exceptionThrowIo#') {
        if (count($expr->args) !== 1) {
            fail('exceptionThrowIo# expects one argument', $expr, $source, $filename);
        }
        validatePureExpr($expr->args[0], $source, $filename);
        return;
    }

    if ($expr->name === 'exceptionCatch#') {
        if (count($expr->args) !== 2) {
            fail('exceptionCatch# expects two arguments', $expr, $source, $filename);
        }
        if (!exprReturnsIo($expr->args[0])) {
            fail('exceptionCatch# action must return IO', $expr->args[0], $source, $filename);
        }
        validateIoBuildExpr($expr->args[0], $source, $filename);
        validateIoBindContinuation($expr->args[1], $source, $filename);
        return;
    }

    if ($expr->name === 'exceptionFinally#') {
        if (count($expr->args) !== 2) {
            fail('exceptionFinally# expects two arguments', $expr, $source, $filename);
        }
        if (!exprReturnsIo($expr->args[0])) {
            fail('exceptionFinally# action must return IO', $expr->args[0], $source, $filename);
        }
        if (!exprReturnsIo($expr->args[1])) {
            fail('exceptionFinally# cleanup must return IO', $expr->args[1], $source, $filename);
        }
        validateIoBuildExpr($expr->args[0], $source, $filename);
        validateIoBuildExpr($expr->args[1], $source, $filename);
        return;
    }

    fail('unknown IO intrinsic', $expr, $source, $filename);
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

    // Partial handlers like `mkCatchHandler handler` :: SomeException -> IO a
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
        $last = $i === count($stmts) - 1;
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
            if (!exprIsIoCapable($stmt->expr)) {
                fail('do-bind expression must return IO', $stmt->expr, $source, $filename);
            }
            validateIoActionArgs($stmt->expr, $source, $filename);
            continue;
        }

        if (!$stmt instanceof Ast\DoExprStmt) {
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
            $varType = $stmt->expr->inferredType;
            if ($varType !== null && isIoType($varType)) {
                continue;
            }
        }

        $stmtType = $stmt->expr->inferredType;
        if (!$last && !($stmtType !== null && isIoUnitType($stmtType))) {
            fail('intermediate IO statements must return ()', $stmt->expr, $source, $filename);
        }

        if (!$last && !exprIsIoCapable($stmt->expr)) {
            fail('intermediate IO statements must be IO expressions', $stmt->expr, $source, $filename);
        }

        if ($last && !exprIsIoCapable($stmt->expr)) {
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

function validateApply(Ast\Apply $expr, string $source, string $filename): void
{
    $parts = flattenApply($expr);
    validatePureExpr($parts['function'], $source, $filename);
    foreach ($parts['args'] as $arg) {
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

function validateIntrinsic(Ast\IntrinsicCall $expr, string $source, string $filename): void
{
    foreach ($expr->args as $arg) {
        validatePureExpr($arg, $source, $filename);
    }
}

function validateIoActionArgs(Ast\AstNode $expr, string $source, string $filename): void
{
    if ($expr instanceof Ast\IntrinsicCall && isIoExceptionIntrinsic($expr->name)) {
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
    $type = $expr->inferredType;
    while ($type instanceof Ast\TypeArrow) {
        if (isIoType($type->to)) {
            return true;
        }
        $type = $type->to;
    }

    return false;
}

function exprReturnsIo(Ast\AstNode $expr): bool
{
    $type = $expr->inferredType;

    return $type !== null && isIoType($type);
}

function exprIsIoCapable(Ast\AstNode $expr): bool
{
    if (exprReturnsIo($expr)) {
        return true;
    }

    return isErrorCallExpr($expr);
}

function validateIoBindExpr(Ast\AstNode $expr, string $source, string $filename): void
{
    $parts = ioBindParts($expr);
    if ($parts === null) {
        fail('IO bind expression must return IO', $expr, $source, $filename);
    }

    if (!exprReturnsIo($expr)) {
        fail('IO bind expression must return IO', $expr, $source, $filename);
    }

    if (!exprReturnsIo($parts['left'])) {
        fail('left side of IO bind must return IO', $parts['left'], $source, $filename);
    }

    validateIoActionArgs($parts['left'], $source, $filename);
    validateIoBindContinuation($parts['right'], $source, $filename);
}

function doBindingHasIoType(Ast\DoBind $binding): bool
{
    $type = $binding->expr->inferredType;

    return $type !== null && isIoType($type);
}

function validateIoBuildExpr(Ast\AstNode $expr, string $source, string $filename): void
{
    if (exprIsIoCapable($expr)) {
        $type = $expr->inferredType;
        if ($type !== null && isNestedIoType($type)) {
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
    if (isIoExceptionIntrinsic($expr->name)) {
        validateIoIntrinsic($expr, $source, $filename);
        return;
    }

    foreach ($expr->args as $arg) {
        validatePureExpr($arg, $source, $filename);
    }
}

function isUnsaturatedPartialIo(Ast\AstNode $expr): bool
{
    if (!$expr instanceof Ast\Apply) {
        return false;
    }

    $type = $expr->inferredType;
    if (!$type instanceof Ast\TypeArrow) {
        return false;
    }

    return !isIoType($type->to);
}

/** @return ?array{left: Ast\AstNode, right: Ast\AstNode} */
function ioBindParts(Ast\AstNode $expr): ?array
{
    if ($expr instanceof Ast\Infix && $expr->operator === '>>=') {
        return ['left' => $expr->left, 'right' => $expr->right];
    }

    if (!$expr instanceof Ast\Apply) {
        return null;
    }

    $right = $expr->argument;
    $inner = $expr->function;
    if (!$inner instanceof Ast\Apply) {
        return null;
    }

    $method = $inner->function;
    if (!isIoMonadMethod($method, '>>=')) {
        return null;
    }

    return ['left' => $inner->argument, 'right' => $right];
}

/**
 * IO class methods are recognized by ordinary instance evidence whose head is
 * IO, not by ambient __io_* dictionaries.
 */
function isIoMonadMethod(Ast\AstNode $expr, string $method): bool
{
    if (!$expr instanceof Ast\EvidenceMethod || $expr->method !== $method) {
        return false;
    }

    $head = $expr->evidenceInstance;
    if ($head instanceof Ast\TypeCon && ($head->name === 'IO' || $head->name === 'IO#')) {
        return true;
    }

    return $head instanceof Ast\TypeApp
        && $head->con instanceof Ast\TypeCon
        && ($head->con->name === 'IO' || $head->con->name === 'IO#');
}

/** @return array{function: Ast\AstNode, args: list<Ast\AstNode>} */
function flattenApply(Ast\AstNode $expr): array
{
    $args = [];
    while ($expr instanceof Ast\Apply) {
        array_unshift($args, $expr->argument);
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
