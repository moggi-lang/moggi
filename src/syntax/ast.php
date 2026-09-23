<?php declare(strict_types=1);

namespace Moggi\Syntax\Ast;

use function Moggi\Syntax\isTypeOperator;

require_once __DIR__ . '/ast_types.php';
require_once __DIR__ . '/names.php';

use function Moggi\Syntax\isConstructorOperator;

/**
 * Deep-copy an AST subtree.
 *
 * Parsed declaration nodes are shared: a class default body is re-checked once
 * per instance site, and type checking rewrites bodies in place (evidence
 * resolution, intrinsic rewriting, type zonking). Every reuse must start from
 * a fresh copy, or the first instance's elaborated body leaks into the next.
 */
function copyNode(AstNode $node): AstNode
{
    return \unserialize(\serialize($node));
}

/**
 * A desugared `@@\x -> …` parameter carrying the position of the pattern the user wrote.
 *
 * The parameter is synthetic, but it stands for a real piece of source: reporting it at line 0
 * leaves a diagnostic about a `do` or list-comprehension pattern with nowhere to point.
 */
function lambdaParamFor(AstNode $pattern): LambdaParam
{
    return new LambdaParam(
        $pattern,
        null,
        $pattern->line,
        $pattern->col,
        $pattern->endCol,
    );
}

function desugarEnumFromTo(AstNode $from, AstNode $to): AstNode
{
    return new Apply(new Apply(new Variable('enumFromTo'), $from), $to);
}

function desugarEnumFromThenTo(AstNode $from, AstNode $then, AstNode $to): AstNode
{
    return new Apply(
        new Apply(new Apply(new Variable('enumFromThenTo'), $from), $then),
        $to,
    );
}

/**
 * @param list<array{tag: string, pattern?: array<string, mixed>, expr?: array<string, mixed>}> $qualifiers
 */
function desugarListComp(AstNode $expr, array $qualifiers): AstNode
{
    if ($qualifiers === []) {
        return new ListLit([$expr]);
    }

    $qual = $qualifiers[0];
    $rest = \array_slice($qualifiers, 1);

    if ($qual instanceof QualGen) {
        $inner = desugarListComp($expr, $rest);

        return new Apply(
            new Apply(
                new Variable('concatMap'),
                new Lambda([lambdaParamFor($qual->pattern)], $inner),
            ),
            $qual->expr,
        );
    }

    $inner = desugarListComp($expr, $rest);

    if (!$qual instanceof QualGuard) {
        throw new \InvalidArgumentException('list comprehension qualifier must be a guard');
    }

    return new CaseExpr($qual->expr, [
        new Alt(new PatCon('True', []), $inner),
        new Alt(new PatCon('False', []), new ListLit([])),
    ]);
}

/**
 * @param list<AstNode> $stmts `do` statements, as parsed
 */
function desugarDo(array $stmts): AstNode
{
    if ($stmts === []) {
        throw new \InvalidArgumentException('empty do block');
    }

    $first = $stmts[0];
    $rest = \array_slice($stmts, 1);

    return match ($first::class) {
        DoBind::class => (function () use ($first, $rest): AstNode {
            $inner = desugarDo($rest);
            $pattern = $first->pattern;
            // IR lowering still requires variable lambda params; wildcards from
            // `_ <- expr` become throwaway names.
            if ($pattern instanceof PatWild) {
                $pattern = new PatVar('__doWild');
            }

            return new Infix('>>=', $first->expr, new Lambda([lambdaParamFor($pattern)], $inner));
        })(),
        DoLet::class => (function () use ($first, $rest): AstNode {
            $inner = desugarDo($rest);

            return new Let(
                \array_map(
                    static fn (DoBind $bind): Binding => new Binding(
                        $bind->pattern,
                        $bind->expr,
                        $bind->line,
                        $bind->col,
                        $bind->endCol,
                    ),
                    $first->bindings,
                ),
                $inner,
                false,
            );
        })(),
        DoExprStmt::class => $rest === []
            ? $first->expr
            : new Infix('>>=', $first->expr, new Lambda([new LambdaParam(new PatVar('__doUnit'))], desugarDo($rest))),
        default => throw new \InvalidArgumentException('invalid do statement'),
    };
}

function moduleName(array $path): string
{
    return join('.', $path);
}

function dump(AstNode $node, int $indent = 0): string
{
    $pad = str_repeat('  ', $indent);

    return match ($node::class) {
        Program::class => $pad . "program\n"
            . ($node->moduleBackend !== null ? $pad . '  module_backend ' . $node->moduleBackend . "\n" : '')
            . ($node->backendMap !== []
                ? $pad . "  backend_map\n" . join('', \array_map(
                    static fn (string $backend, string $module): string => $pad . '    '
                        . $backend . ': ' . $module . "\n",
                    \array_keys($node->backendMap),
                    array_values($node->backendMap),
                ))
                : '')
            . join('', \array_map(
                static fn (AstNode $item): string => dump($item, $indent + 1),
                $node->items,
            )),
        ForeignTypeDecl::class => $pad . 'foreign_type ' . $node->backend . ' '
            . $node->name . ' ' . json_encode($node->hostType) . "\n",
        ForeignImportDecl::class => $pad . 'foreign_import ' . $node->backend . ' ' . $node->kind . ' '
            . $node->name . ' ' . json_encode($node->path) . ' :: '
            . dumpTypeInline($node->type) . "\n",
        SignatureOnly::class => $pad . "signature_only\n",
        DataDecl::class => $pad . ($node->isNewtype ? 'newtype ' : 'data ') . $node->name
            . (count($node->params) > 0 ? ' ' . join(' ', \array_map(
                static fn (DataParam $param): string => dumpDataParam($param),
                $node->params,
            )) : '') . "\n"
            . join('', \array_map(
                static fn (ConstructorDecl $ctor): string => dump($ctor, $indent + 1),
                $node->constructors,
            ))
            . ($node->derivingClasses !== []
                ? $pad . '  deriving (' . join(', ', \array_map(
                    static function (DerivingClassRef $c): string {
                        $prefix = match ($c->strategy) {
                            'stock' => 'stock ',
                            'newtype' => 'newtype ',
                            'anyclass' => 'anyclass ',
                            'via' => $c->viaType !== null
                                ? 'via (' . dumpTypeInline($c->viaType) . ') '
                                : 'via ',
                            default => '',
                        };

                        return $prefix . $c->name;
                    },
                    $node->derivingClasses,
                )) . ")\n"
                : ''),
        TypeSynonymDecl::class => $pad . 'type ' . $node->name
            . (count($node->params) > 0 ? ' ' . join(' ', $node->params) : '')
            . ' = ' . dumpTypeInline($node->type) . "\n",
        ClassDecl::class => $pad . 'class ' . $node->name
            . (count($node->params) > 0 ? ' ' . join(' ', \array_map(
                static fn (ClassParam $param): string => $param->name,
                $node->params,
            )) : '') . "\n"
            . join('', \array_map(
                static function (AssociatedTypeDecl $assoc) use ($pad): string {
                    $params = $assoc->params === [] ? '' : ' ' . join(' ', $assoc->params);
                    $kind = $assoc->resultKind !== null
                        ? ' :: ' . dumpKindInline($assoc->resultKind)
                        : '';

                    return $pad . '  associated ' . $assoc->name . $params . $kind . "\n";
                },
                $node->associatedTypes,
            ))
            . join('', \array_map(
                static fn (ClassMethodSig $method): string => $pad . '  method ' . $method->name
                    . ' :: ' . dumpTypeInline($method->type)
                    . ($method->body !== null ? ' = default' : '') . "\n",
                $node->methods,
            )),
        InstanceDecl::class => $pad . 'instance ' . $node->class . ' ' . dumpTypeInline($node->head) . "\n"
            . join('', \array_map(
                static function (AssociatedTypeEquation $eq) use ($pad): string {
                    $args = $eq->lhsArgs === []
                        ? ''
                        : ' ' . join(' ', \array_map(
                            static fn (TypeNode $arg): string => dumpTypeInline($arg),
                            $eq->lhsArgs,
                        ));

                    return $pad . '  associated ' . $eq->name . $args
                        . ' = ' . dumpTypeInline($eq->rhs) . "\n";
                },
                $node->associatedEquations,
            )),
        StandaloneDerivingDecl::class => $pad . 'deriving'
            . ($node->strategy !== null ? ' ' . $node->strategy : '')
            . ($node->viaType !== null ? ' via (' . dumpTypeInline($node->viaType) . ')' : '')
            . ' instance ' . $node->className . ' ' . dumpTypeInline($node->head) . "\n",
        ConstructorDecl::class => $pad . 'constructor ' . $node->name . "\n"
            . join('', \array_map(
                static function (CtorField $field) use ($pad): string {
                    return $pad . '  field ' . $field->name . ' :: '
                        . dumpTypeInline($field->type) . "\n";
                },
                $node->fields,
            )),
        FunctionDecl::class => $pad . 'function ' . $node->name
            . ($node->type !== null ? ' :: ' . dumpTypeInline($node->type) : '')
            . ($node->signatureOnly ? ' signature_only' : '')
            . "\n"
            . ($node->signatureOnly ? '' : join('', \array_map(
                static fn (AstNode $param): string => dumpPattern($param, $indent + 1),
                $node->params,
            )) . dump($node->body, $indent + 1)),
        IntegerLit::class => $pad . 'integer ' . $node->value . "\n",
        DoubleLit::class => $pad . 'float ' . $node->value . "\n",
        TypeAsc::class => $pad . 'type_asc ' . dumpTypeInline($node->type) . "\n"
            . dump($node->expr, $indent + 1),
        ExprHole::class => $pad . "hole\n",
        StringLit::class => $pad . 'string ' . json_encode($node->value) . "\n",
        CharLit::class => $pad . 'char ' . \sprintf("'\\u{%X}'", $node->value) . "\n",
        Variable::class => $pad . 'variable ' . $node->name . "\n",
        ConstructorRef::class => $pad . 'constructor_ref ' . $node->name . "\n",
        OperatorRef::class => $pad . 'operator_ref ' . $node->name . "\n",
        QualifiedRef::class => $pad . 'qualified_ref ' . $node->module . '.' . $node->name . "\n",
        EvidenceRef::class => $pad . 'evidence_ref ' . $node->class
            . ' ' . dumpTypeInline($node->head) . "\n",
        EvidenceMethod::class => $pad . 'evidence_method ' . $node->class . '.' . $node->method
            . ' evidence=' . $node->evidence
            . ' nullary=' . ($node->methodNullary ? '1' : '0') . "\n"
            . ($node->evidenceInstance === null
                ? ''
                : $pad . '  instance ' . dumpTypeInline($node->evidenceInstance) . "\n"),
        Apply::class => dumpApply($node, $indent),
        Infix::class => $pad . 'infix ' . $node->operator . "\n"
            . dump($node->left, $indent + 1)
            . dump($node->right, $indent + 1),
        Lambda::class => $pad . "lambda\n"
            . join('', \array_map(
                static fn (LambdaParam $param): string => dumpPattern($param->pattern, $indent + 1),
                $node->params,
            ))
            . dump($node->body, $indent + 1),
        Let::class => $pad . "let\n"
            . join('', \array_map(
                static fn (Binding $b): string => dumpBinding($b, $indent + 1),
                $node->bindings,
            ))
            . dump($node->body, $indent + 1),
        Where::class => $pad . "where\n"
            . dump($node->expr, $indent + 1)
            . join('', \array_map(
                static fn (Binding $b): string => dumpBinding($b, $indent + 1),
                $node->bindings,
            )),
        CaseExpr::class => $pad . "case\n"
            . dump($node->scrutinee, $indent + 1)
            . join('', \array_map(
                static fn (Alt $a): string => dumpAlt($a, $indent + 1),
                $node->alts,
            )),
        GuardsExpr::class => $pad . "guards\n"
            . join('', \array_map(
                static fn (Guarded $clause): string => dumpGuarded($clause, $indent + 1),
                $node->clauses,
            )),
        Tuple::class => $pad . "tuple\n" . join('', \array_map(
            static fn (AstNode $element): string => dump($element, $indent + 1),
            $node->elements,
        )),
        ListLit::class => $pad . "list\n" . join('', \array_map(
            static fn (AstNode $element): string => dump($element, $indent + 1),
            $node->elements,
        )),
        RecordCon::class => $pad . 'record_con ' . $node->name . "\n"
            . join('', \array_map(
                static function (RecordField $field) use ($pad, $indent): string {
                    return $pad . '  ' . $field->name . " =\n"
                        . dump($field->expr, $indent + 2);
                },
                $node->fields,
            )),
        RecordUpdate::class => $pad . "record_update\n"
            . $pad . "  receiver\n" . dump($node->object, $indent + 2)
            . join('', \array_map(
                static function (RecordField $field) use ($pad, $indent): string {
                    return $pad . '  ' . $field->name . " =\n"
                        . dump($field->expr, $indent + 2);
                },
                $node->fields,
            )),
        FieldAccess::class => $pad . 'field_access .' . $node->field . "\n"
            . dump($node->object, $indent + 1),
        ForeignCall::class => $pad . 'foreign_call ' . $node->backend . ' ' . $node->kind . ' '
            . json_encode($node->path) . ' dispatch=' . $node->dispatch
            . ' io=' . $node->ioWrap->value . "\n"
            . join('', \array_map(
                static fn (AstNode $arg): string => dump($arg, $indent + 1),
                $node->args,
            )),
        DoExpr::class => $pad . "do\n"
            . join('', \array_map(
                static fn (AstNode $stmt): string => dump($stmt, $indent + 1),
                $node->stmts,
            )),
        DoBind::class => $pad . "do_bind\n"
            . dumpPattern($node->pattern, $indent + 1)
            . dump($node->expr, $indent + 1),
        DoLet::class => $pad . "do_let\n"
            . join('', \array_map(
                static fn (DoBind $b): string => dump($b, $indent + 1),
                $node->bindings,
            )),
        DoExprStmt::class => $pad . "do_stmt\n"
            . dump($node->expr, $indent + 1),
        IoAction::class => $pad . "io_action\n"
            . dump($node->expr, $indent + 1),
        IoSequence::class => $pad . "io_sequence\n"
            . join('', \array_map(
                static fn (AstNode $stmt): string => dump($stmt, $indent + 1),
                $node->stmts,
            )),
        IoLet::class => $pad . "io_let\n"
            . join('', \array_map(
                static fn (Binding $b): string => dumpBinding($b, $indent + 1),
                $node->bindings,
            )),
        IoLetAction::class => $pad . "io_let_action\n"
            . dumpPattern($node->pattern, $indent + 1)
            . dump($node->expr, $indent + 1),
        IoBind::class => $pad . "io_bind\n"
            . dumpPattern($node->pattern, $indent + 1)
            . dump($node->expr, $indent + 1),
        IoExpr::class => $pad . "io_expr\n"
            . dump($node->expr, $indent + 1),
        IoPure::class => $pad . "io_pure\n"
            . dump($node->expr, $indent + 1),
        IoCase::class => $pad . "io_case\n"
            . dump($node->scrutinee, $indent + 1)
            . join('', \array_map(
                static fn (Alt $a): string => dumpAlt($a, $indent + 1),
                $node->alts,
            )),
        IntrinsicCall::class => $pad . 'intrinsic ' . $node->name . "\n"
            . join('', \array_map(
                static fn (AstNode $arg): string => dump($arg, $indent + 1),
                $node->args,
            )),
        default => throw new \InvalidArgumentException("unknown ast tag: " . $node::class),
    };
}

function dumpApply(Apply $node, int $indent): string
{
    $pad = str_repeat('  ', $indent);
    $binary = binaryConstructorOpApply($node);
    if ($binary !== null) {
        return $pad . 'infix_con ' . $binary['op'] . "\n"
            . dump($binary['left'], $indent + 1)
            . dump($binary['right'], $indent + 1);
    }

    return $pad . "apply\n"
        . dump($node->function, $indent + 1)
        . dump($node->argument, $indent + 1);
}

/** @return ?array{op: string, left: AstNode, right: AstNode} */
function binaryConstructorOpApply(Apply $node): ?array
{
    if (!$node->function instanceof Apply) {
        return null;
    }

    $inner = $node->function;
    if (!$inner->function instanceof ConstructorRef) {
        return null;
    }

    $op = $inner->function->name;
    if (!isConstructorOperator($op)) {
        return null;
    }

    return [
        'op' => $op,
        'left' => $inner->argument,
        'right' => $node->argument,
    ];
}

function dumpPatCon(PatCon $node, int $indent): string
{
    $pad = str_repeat('  ', $indent);
    if (isConstructorOperator($node->name) && count($node->args) === 2) {
        return $pad . 'pat_infix_con ' . $node->name . "\n"
            . dumpPattern($node->args[0], $indent + 1)
            . dumpPattern($node->args[1], $indent + 1);
    }

    return $pad . 'pat_con ' . $node->name . "\n"
        . join('', \array_map(
            static fn (AstNode $arg): string => dumpPattern($arg, $indent + 1),
            $node->args,
        ));
}

function dumpBinding(Binding $binding, int $indent): string
{
    $pad = str_repeat('  ', $indent);

    return $pad . "binding\n"
        . dumpPattern($binding->pattern, $indent + 1)
        . dump($binding->value, $indent + 1);
}

function dumpAlt(Alt $alt, int $indent): string
{
    $pad = str_repeat('  ', $indent);

    return $pad . "alt\n"
        . dumpPattern($alt->pattern, $indent + 1)
        . dump($alt->body, $indent + 1);
}

function dumpGuarded(Guarded $clause, int $indent): string
{
    $pad = str_repeat('  ', $indent);

    return $pad . "guarded\n"
        . $pad . "  guard\n"
        . dump($clause->guard, $indent + 2)
        . dump($clause->body, $indent + 1);
}

function dumpPattern(AstNode $node, int $indent): string
{
    $pad = str_repeat('  ', $indent);

    return match ($node::class) {
        PatWild::class => $pad . "pat_wild _\n",
        PatVar::class => $pad . 'pat_var ' . $node->name . "\n",
        PatLit::class => $pad . 'pat_lit ' . (\is_string($node->value) ? json_encode($node->value) : $node->value) . "\n",
        PatChar::class => $pad . 'pat_char ' . \sprintf("'\\u{%X}'", $node->value) . "\n",
        PatNil::class => $pad . "pat_nil\n",
        PatCons::class => $pad . "pat_cons\n"
            . dumpPattern($node->head, $indent + 1)
            . dumpPattern($node->tail, $indent + 1),
        PatCon::class => dumpPatCon($node, $indent),
        PatTuple::class => $pad . "pat_tuple\n" . join('', \array_map(
            static fn (AstNode $element): string => dumpPattern($element, $indent + 1),
            $node->elements,
        )),
        PatRecord::class => $pad . 'pat_record ' . $node->name . "\n"
            . join('', \array_map(
                static function (PatField $field) use ($pad, $indent): string {
                    return $pad . '  ' . $field->name . " =\n"
                        . dumpPattern($field->pattern, $indent + 2);
                },
                $node->fields,
            )),
        default => throw new \InvalidArgumentException("unknown pattern tag: " . $node::class),
    };
}

/** DataKinds: dump a `data` parameter, e.g. `c` or `(c :: Color)`. */
function dumpDataParam(DataParam $param): string
{
    if ($param->kind instanceof KindInfer) {
        return $param->name;
    }

    return '(' . $param->name . ' :: ' . dumpKindInline($param->kind) . ')';
}

function dumpKindInline(KindNode $node): string
{
    return match ($node::class) {
        KindType::class => 'Type',
        KindCon::class => $node->name,
        KindArrow::class => dumpKindInline($node->from) . ' -> ' . dumpKindInline($node->to),
        default => throw new \InvalidArgumentException("unknown kind: " . $node::class),
    };
}

/** Dump a surface type AST (not TypeExpr — that uses Types\typeToString). */
function dumpTypeInline(TypeNode $node): string
{
    return match ($node::class) {
        TypeVar::class => $node->name,
        TypeUnit::class => '()',
        TypeCon::class => $node->name,
        TypePromoted::class => "'" . $node->name,
        TypeStringLit::class => json_encode($node->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        TypeNatLit::class => ($node->negative ? '-' : '') . $node->digits,
        TypeQualified::class => $node->module . '.' . $node->name,
        TypeApp::class => (static function () use ($node): string {
            if (
                $node->con instanceof TypeCon
                && $node->con->name === 'List'
                && count($node->args) === 1
            ) {
                return '[' . dumpTypeInline($node->args[0]) . ']';
            }

            if (
                $node->con instanceof TypeCon
                && isTypeOperator($node->con->name)
                && count($node->args) === 2
            ) {
                return dumpTypeInline($node->args[0]) . ' ' . $node->con->name . ' ' . dumpTypeInline($node->args[1]);
            }

            return dumpTypeInline($node->con) . ' ' . join(' ', \array_map(
                static fn (TypeNode $arg): string => dumpTypeInline($arg),
                $node->args,
            ));
        })(),
        TypeArrow::class => dumpTypeInline($node->from) . ' -> ' . dumpTypeInline($node->to),
        TypeConstrained::class => join(', ', \array_map(
            static fn (TypeNode $constraint): string => dumpTypeInline($constraint),
            $node->constraints,
        )) . ' => ' . dumpTypeInline($node->body),
        default => throw new \InvalidArgumentException("unknown type: " . $node::class),
    };
}
