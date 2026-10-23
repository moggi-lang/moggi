<?php declare(strict_types=1);

namespace Moggi\Semantics\StBoundary;

use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\IntrinsicRegistry\isStIntrinsic;
use function Moggi\Semantics\IntrinsicRegistry\primopArity;
use function Moggi\Semantics\IntrinsicRegistry\stPrimopRole;
use function Moggi\Semantics\IoBoundary\stripConstraints;
use function Moggi\Semantics\IoBoundary\typeMentionsIo;
use function Moggi\Semantics\Kinds\canonicalTypeConName;

/** Validate ST surface constraints on a typed program. */
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
        Ast\ForeignImportDecl::class => validateForeign($item, $source, $filename),
        default => null,
    };
}

function validateTypeSynonym(Ast\TypeSynonymDecl $item, string $source, string $filename): void
{
    if (synonymHead($item->type) !== null
        && canonicalTypeConName(synonymHead($item->type)) === $item->name) {
        return;
    }

    validateTypeNoSt($item->type, $item, $source, $filename);
}

/** The head constructor of a synonym RHS (`STRef#` for `type STRef s a = STRef# s a`). */
function synonymHead(Ast\TypeNode $type): ?string
{
    while ($type instanceof Ast\TypeApp && $type->con instanceof Ast\TypeNode) {
        $type = $type->con;
    }

    return $type instanceof Ast\TypeCon ? $type->name : null;
}

function validateFunction(Ast\FunctionDecl $fn, string $source, string $filename): void
{
    if ($fn->signatureOnly) {
        return;
    }

    if ($fn->type !== null) {
        validateFunctionTypeStSurface($fn->type, $fn, $source, $filename);
    }

    walkRunStChecks($fn->body, $source, $filename);
}

function validateData(Ast\DataDecl $item, string $source, string $filename): void
{
    if (isTransparentPrimopWrapper($item)) {
        return;
    }

    foreach ($item->constructors as $ctor) {
        foreach ($ctor->fields as $field) {
            validateTypeNoSt($field->type, $field->type, $source, $filename);
        }
    }
}

/**
 * A newtype that *is* its primop's public spelling (`newtype ST s a =
 * MkST (ST# s a)`): a single constructor with a single field whose head is
 * the primop the declaration canonically names. Transparent rather than
 * hiding — confinement applies at use sites through the name itself — so
 * it is exempt. Anything else (`newtype Sneaky = MkSneaky (STRef# s a)`)
 * stays rejected: that would smuggle a cell behind an innocent name.
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

    $head = synonymHead($fields[0]->type);

    return $head !== null && canonicalTypeConName($head) === $item->name;
}

function validateForeign(Ast\ForeignImportDecl $item, string $source, string $filename): void
{
    if ($item->type !== null && typeMentionsSt($item->type)) {
        fail('foreign imports cannot mention ST (no foreign ST actions in this version)', $item, $source, $filename);
    }
}

function validateFunctionTypeStSurface(Ast\TypeNode $type, Ast\FunctionDecl $fn, string $source, string $filename): void
{
    $result = stripConstraints($type);
    $return = $result;
    while ($return instanceof Ast\TypeArrow) {
        $return = $return->to;
    }

    $returnsSt = isStType($return);

    if ($returnsSt && typeMentionsIo($return)) {
        fail('ST-returning types cannot mention IO', at($return, $fn), $source, $filename);
    }

    $cursor = stripConstraints($type);
    while ($cursor instanceof Ast\TypeArrow) {
        if ($returnsSt && typeMentionsIo($cursor->from)) {
            fail('ST-returning functions cannot take IO arguments', at($cursor->from, $fn), $source, $filename);
        }
        $cursor = $cursor->to;
    }

    if (! $returnsSt) {
        validateTypeNoSt($return, at($return, $fn), $source, $filename);
    } elseif (isNestedStType($return)) {
        fail('nested ST actions are not supported', at($return, $fn), $source, $filename);
    }
}

/**
 * The node to blame: the type itself when the checker left it located,
 * else the function (inferred types carry no source positions).
 */
function at(Ast\AstNode $node, Ast\FunctionDecl $fn): Ast\AstNode
{
    return $node->line > 0 ? $node : $fn;
}

function validateTypeNoSt(Ast\TypeNode $type, Ast\AstNode $at, string $source, string $filename): void
{
    if (typeMentionsSt($type)) {
        fail('ST values are forbidden outside an ST-returning function', $at, $source, $filename);
    }
}

/** Whether a declared type returns an ST action. */
function functionReturnsSt(?Ast\TypeNode $type): bool
{
    if ($type === null) {
        return false;
    }

    $result = stripConstraints($type);
    while ($result instanceof Ast\TypeArrow) {
        $result = $result->to;
    }

    return isStType($result);
}

/** The ST type constructor: the kernel id `ST#`, or the name the library publishes it under. */
function isStTypeConName(string $name): bool
{
    return $name === 'ST#' || $name === canonicalTypeConName('ST#');
}

function isStRefTypeConName(string $name): bool
{
    return $name === 'STRef#' || $name === canonicalTypeConName('STRef#');
}

/**
 * The mutable array type constructor: the kernel id `STArray#`, or the name
 * the array library publishes it under. An `STArray` value is confined like a
 * cell: it may only appear inside `ST` (or behind the transparent `STArray`
 * newtype itself), never in a pure result, a data field, or a synonym.
 */
function isStArrayTypeConName(string $name): bool
{
    return $name === 'STArray#' || $name === canonicalTypeConName('STArray#');
}

function isRealWorldTypeConName(string $name): bool
{
    return $name === 'RealWorld#' || $name === canonicalTypeConName('RealWorld#');
}

function isStType(Ast\TypeNode $type): bool
{
    return $type instanceof Ast\TypeApp
        && $type->con instanceof Ast\TypeCon
        && isStTypeConName($type->con->name);
}

function isNestedStType(Ast\TypeNode $type): bool
{
    if (! isStType($type)) {
        return false;
    }

    $inner = $type->args[1] ?? null;

    return $inner instanceof Ast\TypeNode && isStType($inner);
}

function typeMentionsSt(Ast\TypeNode $type): bool
{
    if ($type instanceof Ast\TypeCon) {
        return isStTypeConName($type->name)
            || isStRefTypeConName($type->name)
            || isStArrayTypeConName($type->name)
            || isRealWorldTypeConName($type->name);
    }

    if ($type instanceof Ast\TypeApp) {
        return typeMentionsSt($type->con)
            || \array_any($type->args, static fn (Ast\TypeNode $arg): bool => typeMentionsSt($arg));
    }

    if ($type instanceof Ast\TypeArrow) {
        return typeMentionsSt($type->from) || typeMentionsSt($type->to);
    }

    if ($type instanceof Ast\TypeConstrained) {
        return \array_any($type->constraints, static fn (Ast\TypeNode $c): bool => typeMentionsSt($c))
            || typeMentionsSt($type->body);
    }

    return false;
}

/**
 * Check every ST eliminator use in a body, in any function kind: each ST
 * primop must be saturated, and each `runST#` must not let its thread
 * escape. All other ST values are inert descriptions and need no check —
 * confinement is enforced on types (signatures, fields, synonyms), where
 * an escape would have to surface.
 */
function walkRunStChecks(Ast\AstNode $expr, string $source, string $filename): void
{
    if ($expr instanceof Ast\IntrinsicCall && isStIntrinsic($expr->name)) {
        validateStPrimop($expr, $source, $filename);
        if ($expr->name === 'runST#') {
            checkRunStEscape($expr, $source, $filename);
        }
    }

    foreach (childExpressions($expr) as $child) {
        walkRunStChecks($child, $source, $filename);
    }
}

/**
 * An ST primop's arity is the one its scheme declares, so the check cannot
 * disagree with the signature the checker resolved.
 */
function validateStPrimop(Ast\IntrinsicCall $expr, string $source, string $filename): void
{
    $role = stPrimopRole($expr->name);
    $arity = primopArity($expr->name);
    if ($role === null || $arity === null) {
        fail('unknown ST intrinsic', $expr, $source, $filename);
    }

    if (\count($expr->args) !== $arity) {
        fail(
            \sprintf('%s expects %d argument%s', $expr->name, $arity, $arity === 1 ? '' : 's'),
            $expr,
            $source,
            $filename,
        );
    }
}

/**
 * The `runST#` escape check: with argument type `ST s a`, the thread
 * variable `s` must not occur free in `a`, so no cell of that thread can
 * leave it.
 */
function checkRunStEscape(Ast\IntrinsicCall $expr, string $source, string $filename): void
{
    $arg = $expr->args[0] ?? null;
    if (! $arg instanceof Ast\AstNode) {
        return;
    }

    $argType = $arg->inferredType;
    if (! $argType instanceof Ast\TypeApp
        || ! $argType->con instanceof Ast\TypeCon
        || ! isStTypeConName($argType->con->name)
        || ! isset($argType->args[0], $argType->args[1])
    ) {
        fail('runST# argument must have type ST s a', $arg, $source, $filename);
    }

    $thread = $argType->args[0];
    $result = $argType->args[1];
    if (! $thread instanceof Ast\TypeVar) {
        fail('runST# cannot run the shared RealWorld thread', $expr, $source, $filename);
    }

    $free = freeTypeVars($result);
    if (isset($free[$thread->name])) {
        fail(
            'runST# would let the state thread escape (thread variable occurs in the result type)',
            $expr,
            $source,
            $filename,
        );
    }
}

/** @return array<string, true> */
function freeTypeVars(Ast\AstNode $node): array
{
    $free = [];
    collectFreeTypeVars($node, $free);

    return $free;
}

/** @param array<string, true> $free */
function collectFreeTypeVars(Ast\AstNode $node, array &$free): void
{
    if ($node instanceof Ast\TypeVar) {
        $free[$node->name] = true;

        return;
    }

    foreach (\get_object_vars($node) as $key => $value) {
        if ($key === 'inferredType' || $key === 'pendingConstraints') {
            continue;
        }

        if ($value instanceof Ast\AstNode) {
            collectFreeTypeVars($value, $free);
        } elseif (\is_array($value)) {
            foreach ($value as $child) {
                if ($child instanceof Ast\AstNode) {
                    collectFreeTypeVars($child, $free);
                }
            }
        }
    }
}

/** @return list<Ast\AstNode> */
function childExpressions(Ast\AstNode $expr): array
{
    $children = [];
    foreach (\get_object_vars($expr) as $key => $value) {
        if ($key === 'inferredType' || $key === 'pendingConstraints') {
            continue;
        }

        if ($value instanceof Ast\AstNode) {
            $children[] = $value;
        } elseif (\is_array($value)) {
            foreach ($value as $child) {
                if ($child instanceof Ast\AstNode) {
                    $children[] = $child;
                }
            }
        }
    }

    return $children;
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
