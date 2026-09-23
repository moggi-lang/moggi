<?php declare(strict_types=1);

namespace Moggi\IR\Dump;

use Moggi\IR;

use function Moggi\Syntax\isConstructorOperator;

require_once __DIR__ . '/../syntax/names.php';

function dump(IR\IrNode $node, int $indent = 0): string
{
    $pad = str_repeat('  ', $indent);

    return match ($node::class) {
        IR\Module::class => $pad . "module\n"
            . join('', \array_map(static fn (IR\DataDecl $d): string => dump($d, $indent + 1), $node->data))
            . join('', \array_map(static fn (IR\FunctionDecl $f): string => dump($f, $indent + 1), $node->functions)),
        IR\DataDecl::class => $pad . ($node->isNewtype ? 'newtype ' : 'data ') . $node->name
            . (count($node->params) > 0 ? ' ' . join(' ', $node->params) : '') . "\n"
            . join('', \array_map(
                static fn ($ctor): string => $pad . '  constructor ' . $ctor->name
                    . (count($ctor->fields) > 0 ? ' ' . join(' ', $ctor->fields) : '') . "\n",
                $node->constructors,
            )),
        IR\FunctionDecl::class => $pad . 'function ' . $node->name
            . (count($node->params) > 0 ? '(' . join(', ', $node->params) . ')' : '()') . "\n"
            . dump($node->body, $indent + 1),
        IR\Block::class => join('', \array_map(
            static fn (IR\Stmt $item): string => dump($item, $indent + 1),
            $node->items,
        )),
        IR\Ret::class => $pad . 'ret ' . dumpExpr($node->value) . "\n",
        IR\Binop::class => $pad . 't' . $node->dest . ' = ' . dumpOperand($node->left)
            . ' ' . $node->op . ' ' . dumpOperand($node->right) . "\n",
        IR\Call::class => $pad . 't' . $node->dest . ' = call ' . $node->callee
            . '(' . join(', ', \array_map(dumpOperand(...), $node->args)) . ")\n",
        IR\CallValue::class => $pad . 't' . $node->dest . ' = call_value ' . dumpOperand($node->callee)
            . '(' . join(', ', \array_map(dumpOperand(...), $node->args)) . ")\n",
        IR\IoCall::class => $pad
            . ($node->dest === null ? '' : 't' . $node->dest . ' = ')
            . 'io_call ' . $node->callee
            . '(' . join(', ', \array_map(dumpOperand(...), $node->args)) . ")\n",
        IR\DictCall::class => $pad . 't' . $node->dest . ' = dict_call ' . dumpOperand($node->evidence)
            . '->' . $node->method
            . '(' . join(', ', \array_map(dumpOperand(...), $node->args)) . ")\n",
        IR\Assign::class => $pad . 't' . $node->dest . ' = ' . dumpExpr($node->value) . "\n",
        IR\Let::class => $pad . 'let ' . $node->name . ' = ' . dumpOperand($node->value) . "\n",
        IR\MatchStmt::class => $pad . 't' . $node->dest . ' = match ' . dumpOperand($node->scrutinee) . "\n"
            . join('', \array_map(
                static fn (IR\MatchArm $arm): string => dumpMatchArm($arm, $indent + 1),
                $node->arms,
            )),
        IR\MatchReturn::class => $pad . 'match_return ' . dumpOperand($node->scrutinee) . "\n"
            . join('', \array_map(
                static fn (IR\MatchArm $arm): string => dumpMatchArm($arm, $indent + 1),
                $node->arms,
            )),
        IR\IoMatch::class => $pad . 'io_match ' . dumpOperand($node->scrutinee) . "\n"
            . join('', \array_map(
                static fn (IR\MatchArm $arm): string => dumpMatchArm($arm, $indent + 1),
                $node->arms,
            )),
        IR\IoRun::class => $pad
            . ($node->dest === null ? '' : 't' . $node->dest . ' = ')
            . 'io_run ' . dumpOperand($node->action) . "\n",
        IR\IoAssignAction::class => $pad . 't' . $node->dest . " = io_assign_action\n"
            . dump($node->body, $indent + 1)
            . $pad . '  result ' . dumpOperand($node->result) . "\n",
        IR\IoThrow::class => $pad
            . ($node->dest === null ? '' : 't' . $node->dest . ' = ')
            . 'io_throw ' . dumpOperand($node->exception) . "\n",
        IR\IoCatch::class => $pad
            . ($node->dest === null ? '' : 't' . $node->dest . ' = ')
            . 'io_catch ' . dumpOperand($node->action)
            . ' ' . dumpOperand($node->handler) . "\n",
        IR\IoFinally::class => $pad
            . ($node->dest === null ? '' : 't' . $node->dest . ' = ')
            . 'io_finally ' . dumpOperand($node->action)
            . ' ' . dumpOperand($node->cleanup) . "\n",
        IR\Loop::class => $pad . "loop\n"
            . dump($node->body, $indent + 1),
        IR\TailRecall::class => $pad . 'tail_recall(' . join(', ', \array_map(dumpExpr(...), $node->args)) . ")\n",
        default => throw new \InvalidArgumentException('unknown ir node: ' . $node::class),
    };
}

function dumpMatchArm(IR\MatchArm $arm, int $indent): string
{
    $pad = str_repeat('  ', $indent);

    // Statements a guard needs run before the guard is tested, so they are
    // printed above the arm rather than among its body items.
    $prep = '';
    foreach ($arm->guards as $guard) {
        foreach ($guard->prep->items as $item) {
            $prep .= dump($item, $indent);
        }
    }

    // Without the guard, `x | p = a | q = b` reads as two identical arms.
    $guard = $arm->guards === []
        ? ''
        : ' when ' . join(', ', \array_map(
            static fn (IR\Guard $g): string => dumpOperand($g->cond),
            $arm->guards,
        ));

    return $prep . $pad . 'arm ' . dumpPatternInline($arm->pattern) . $guard . "\n"
        . dump($arm->body, $indent + 1);
}

function dumpOperand(IR\Operand $node): string
{
    if (!$node instanceof IR\ConstInt && !$node instanceof IR\ConstDouble && !$node instanceof IR\ConstStr
        && !$node instanceof IR\ConstChar
        && !$node instanceof IR\Local && !$node instanceof IR\Temp && !$node instanceof IR\FnRef
        && !$node instanceof IR\Unit && !$node instanceof IR\ListLit && !$node instanceof IR\Partial && !$node instanceof IR\Intrinsic
        && !$node instanceof IR\ForeignCall && !$node instanceof IR\DictMethod) {
        return dumpExpr($node);
    }

    return match ($node::class) {
        IR\ConstInt::class => (string) $node->value,
        IR\ConstDouble::class => dumpDoubleLiteral($node->value),
        IR\ConstStr::class => json_encode($node->value),
        IR\ConstChar::class => \sprintf("'\\u{%X}'", $node->value),
        IR\Local::class => $node->name,
        IR\Temp::class => 't' . $node->id,
        IR\Unit::class => '()',
        IR\FnRef::class => '@' . $node->name,
        IR\ListLit::class => '[' . join(', ', \array_map(dumpExpr(...), $node->elements)) . ']',
        IR\Partial::class => 'partial ' . $node->fn . '(' . join(', ', \array_map(dumpExpr(...), $node->args)) . ')',
        IR\Intrinsic::class => 'intrinsic ' . $node->name
            . '(' . join(', ', \array_map(dumpExpr(...), $node->args)) . ')',
        IR\ForeignCall::class => 'foreign ' . json_encode($node->path)
            . '(' . join(', ', \array_map(dumpExpr(...), $node->args)) . ')',
        IR\DictMethod::class => 'dict_method ' . dumpOperand($node->evidence) . '->' . $node->method,
        default => throw new \InvalidArgumentException('unknown operand: ' . $node::class),
    };
}

function dumpExpr(IR\Operand $node): string
{
    return match ($node::class) {
        IR\ConstInt::class, IR\ConstDouble::class, IR\ConstStr::class, IR\ConstChar::class, IR\Local::class, IR\Temp::class, IR\Unit::class,
        IR\FnRef::class, IR\Partial::class, IR\Intrinsic::class, IR\ForeignCall::class, IR\ListLit::class, IR\DictMethod::class
            => dumpOperand($node),
        IR\ExprBinop::class => dumpExpr($node->left) . ' ' . $node->op . ' ' . dumpExpr($node->right),
        IR\ExprCall::class => 'call ' . $node->callee
            . '(' . join(', ', \array_map(dumpExpr(...), $node->args)) . ')',
        IR\ExprCallValue::class => 'call_value ' . dumpOperand($node->callee)
            . '(' . join(', ', \array_map(dumpExpr(...), $node->args)) . ')',
        IR\ExprPartial::class => 'partial ' . $node->fn
            . '(' . join(', ', \array_map(dumpExpr(...), $node->args)) . ')',
        default => throw new \InvalidArgumentException('unknown expr: ' . $node::class),
    };
}

function dumpPatternInline(IR\Pattern $pattern): string
{
    return match ($pattern::class) {
        IR\PatWild::class => '_',
        IR\PatVar::class => $pattern->name,
        IR\PatLit::class => (string) $pattern->value,
        IR\PatChar::class => \sprintf("'\\u{%X}'", $pattern->value),
        IR\PatCon::class => (isConstructorOperator($pattern->name) && count($pattern->args) === 2)
            ? dumpPatternInline($pattern->args[0]) . ' ' . $pattern->name . ' ' . dumpPatternInline($pattern->args[1])
            : $pattern->name . (count($pattern->args) > 0
                ? ' ' . join(' ', \array_map(dumpPatternInline(...), $pattern->args))
                : ''),
        IR\PatTuple::class => '(' . join(', ', \array_map(dumpPatternInline(...), $pattern->elements)) . ')',
        IR\PatNil::class => '[]',
        IR\PatCons::class => dumpPatternInline($pattern->head) . ' : ' . dumpPatternInline($pattern->tail),
        default => throw new \InvalidArgumentException('unknown pattern: ' . $pattern::class),
    };
}

function dumpDoubleLiteral(float $value): string
{
    if (is_nan($value)) {
        return 'nan';
    }

    if (is_infinite($value)) {
        return $value < 0 ? '-inf' : 'inf';
    }

    $text = rtrim(rtrim(\sprintf('%.17F', $value), '0'), '.');

    return str_contains($text, '.') ? $text : $text . '.0';
}
