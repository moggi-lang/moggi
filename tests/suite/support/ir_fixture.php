<?php declare(strict_types=1);

namespace Moggi\Test\IrFixture;

use Moggi\IR;
use Moggi\Syntax\Ast;

/** One-way loader: JSON/array IR fixtures → typed IR objects (test harness only). */
function moduleFromArray(array $module): IR\Module
{
    $functions = \array_map(functionFromArray(...), $module['functions'] ?? []);
    $data = \array_map(dataFromArray(...), $module['data'] ?? []);
    $evidence = [];
    foreach ($module['instanceEvidence'] ?? [] as $ev) {
        $evidence[] = $ev instanceof IR\InstanceEvidence
            ? $ev
            : new IR\InstanceEvidence($ev['evidenceName'], $ev['methods']);
    }
    $entry = null;
    if (isset($module['entry']) && \is_array($module['entry'])) {
        $kind = $module['entry']['kind'] ?? 'main';
        $entry = new IR\EntryPoint(
            $module['entry']['name'],
            IR\EntryPointKind::from($kind),
        );
    } else {
        $entryMain = $module['entryMain'] ?? null;
        if (\is_array($entryMain)) {
            $kind = $entryMain['kind'] ?? 'main';
            $name = $entryMain['name'] ?? null;
            if (\is_string($name)) {
                $entry = new IR\EntryPoint($name, IR\EntryPointKind::from($kind));
            }
        } elseif (\is_string($entryMain)) {
            $entry = new IR\EntryPoint($entryMain, IR\EntryPointKind::Main);
        }
    }

    return new IR\Module($functions, $data, $evidence, $entry);
}

function functionFromArray(array $fn): IR\FunctionDecl
{
    $ioBodyKind = IR\IoBodyKind::StraightLine;
    if ($fn['ioActionReturn'] ?? false) {
        $ioBodyKind = IR\IoBodyKind::ActionReturn;
    }

    $entryKind = null;
    if (isset($fn['entryKind']) && \is_string($fn['entryKind'])) {
        $entryKind = IR\EntryPointKind::from($fn['entryKind']);
    } elseif ($fn['entryPoint'] ?? false) {
        $entryKind = IR\EntryPointKind::Main;
    }

    return new IR\FunctionDecl(
        $fn['name'],
        $fn['params'],
        typeFromArray($fn['type'] ?? null),
        blockFromArray($fn['body']),
        $fn['export'] ?? false,
        $fn['instanceMethod'] ?? false,
        $entryKind,
        $fn['ioEffect'] ?? false,
        $fn['ioStraightLine'] ?? false,
        $fn['foreign'] ?? false,
        $ioBodyKind,
    );
}

function dataFromArray(array $decl): IR\DataDecl
{
    return new IR\DataDecl(
        $decl['name'],
        $decl['params'] ?? [],
        \array_map(
            static fn (array $ctor): IR\DataConstructor => new IR\DataConstructor(
                $ctor['name'],
                $ctor['fields'] ?? [],
            ),
            $decl['constructors'] ?? [],
        ),
        (bool) ($decl['newtype'] ?? $decl['isNewtype'] ?? false),
    );
}

function blockFromArray(array $block): IR\Block
{
    return new IR\Block(\array_map(stmtFromArray(...), $block['items'] ?? []));
}

function stmtFromArray(array $stmt): IR\Stmt
{
    return match ($stmt['tag']) {
        'ret' => new IR\Ret(operandFromArray($stmt['value'])),
        'binop' => new IR\Binop($stmt['op'], operandFromArray($stmt['left']), operandFromArray($stmt['right']), $stmt['dest']),
        'call' => new IR\Call($stmt['callee'], \array_map(operandFromArray(...), $stmt['args']), $stmt['dest']),
        'call_value' => new IR\CallValue(operandFromArray($stmt['callee']), \array_map(operandFromArray(...), $stmt['args']), $stmt['dest']),
        'dict_call' => new IR\DictCall(operandFromArray($stmt['evidence']), $stmt['method'], \array_map(operandFromArray(...), $stmt['args']), $stmt['dest']),
        'assign' => new IR\Assign($stmt['dest'], operandFromArray($stmt['value'])),
        'let' => new IR\Let($stmt['name'], operandFromArray($stmt['value'])),
        'match' => new IR\MatchStmt(
            operandFromArray($stmt['scrutinee']),
            \array_map(armFromArray(...), $stmt['arms']),
            $stmt['dest'],
            $stmt['exhaustive'] ?? false,
        ),
        'match_return' => new IR\MatchReturn(
            operandFromArray($stmt['scrutinee']),
            \array_map(armFromArray(...), $stmt['arms']),
            $stmt['exhaustive'] ?? false,
        ),
        'tail_recall' => new IR\TailRecall(\array_map(operandFromArray(...), $stmt['args'])),
        'loop' => new IR\Loop(blockFromArray($stmt['body'])),
        'io_call' => new IR\IoCall(
            $stmt['callee'],
            \array_map(operandFromArray(...), $stmt['args'] ?? []),
            $stmt['dest'] ?? null,
            $stmt['intrinsic'] ?? null,
            $stmt['runtime'] ?? null,
            isset($stmt['foreign']) && \is_array($stmt['foreign']) ? foreignFromArray($stmt['foreign']) : null,
        ),
        'io_run' => new IR\IoRun(operandFromArray($stmt['action']), $stmt['dest'] ?? null),
        'io_match' => new IR\IoMatch(
            operandFromArray($stmt['scrutinee']),
            \array_map(armFromArray(...), $stmt['arms']),
            $stmt['dest'] ?? null,
            $stmt['exhaustive'] ?? false,
        ),
        'io_assign_action' => new IR\IoAssignAction(
            $stmt['dest'],
            blockFromArray($stmt['body']),
            operandFromArray($stmt['result']),
        ),
        default => throw new \RuntimeException('unsupported IR stmt tag in fixture: ' . $stmt['tag']),
    };
}

function armFromArray(array $arm): IR\MatchArm
{
    return new IR\MatchArm(
        patternFromArray($arm['pattern']),
        blockFromArray($arm['body']),
        \array_map(operandFromArray(...), $arm['guards'] ?? []),
    );
}

function patternFromArray(array $pattern): IR\Pattern
{
    return match ($pattern['tag']) {
        'pat_wild' => new IR\PatWild(),
        'pat_var' => new IR\PatVar($pattern['name']),
        'pat_lit' => new IR\PatLit($pattern['value']),
        'pat_char' => new IR\PatChar($pattern['value']),
        'pat_nil' => new IR\PatNil(),
        'pat_cons' => new IR\PatCons(patternFromArray($pattern['head']), patternFromArray($pattern['tail'])),
        'pat_con' => new IR\PatCon($pattern['name'], \array_map(patternFromArray(...), $pattern['args'] ?? [])),
        'pat_tuple' => new IR\PatTuple(\array_map(patternFromArray(...), $pattern['elements'] ?? [])),
        'pat_record' => new IR\PatRecord(
            $pattern['name'],
            \array_map(
                static fn (array $field): IR\PatField => new IR\PatField($field['name'], patternFromArray($field['pattern'])),
                $pattern['fields'] ?? [],
            ),
        ),
        default => throw new \RuntimeException('unsupported IR pattern tag in fixture: ' . $pattern['tag']),
    };
}

function operandFromArray(array $operand): IR\Operand
{
    return match ($operand['tag']) {
        'const' => new IR\ConstInt($operand['value']),
        'const_str' => new IR\ConstStr($operand['value']),
        'const_char' => new IR\ConstChar($operand['value']),
        'const_double' => new IR\ConstDouble($operand['value']),
        'local' => new IR\Local($operand['name']),
        'temp' => new IR\Temp($operand['id']),
        'fn' => new IR\FnRef(
            $operand['name'],
            $operand['evidenceClass'] ?? null,
            $operand['evidenceHead'] ?? null,
        ),
        'list_lit' => new IR\ListLit(\array_map(operandFromArray(...), $operand['elements'] ?? [])),
        'unit' => new IR\Unit(),
        'partial' => new IR\Partial($operand['fn'], $operand['arity'], \array_map(operandFromArray(...), $operand['args'] ?? [])),
        'expr_partial' => new IR\ExprPartial($operand['fn'], $operand['arity'], \array_map(operandFromArray(...), $operand['args'] ?? [])),
        'expr_binop' => new IR\ExprBinop($operand['op'], operandFromArray($operand['left']), operandFromArray($operand['right'])),
        'expr_call' => new IR\ExprCall($operand['callee'], \array_map(operandFromArray(...), $operand['args'] ?? [])),
        'expr_call_value' => new IR\ExprCallValue(operandFromArray($operand['callee']), \array_map(operandFromArray(...), $operand['args'] ?? [])),
        'intrinsic' => new IR\Intrinsic($operand['name'], \array_map(operandFromArray(...), $operand['args'] ?? [])),
        'dict_method' => new IR\DictMethod(operandFromArray($operand['evidence']), $operand['method']),
        'foreign_call' => foreignFromArray($operand),
        'io_action' => new IR\IoAction(operandFromArray($operand['expr'])),
        default => throw new \RuntimeException('unsupported IR operand tag in fixture: ' . $operand['tag']),
    };
}

function foreignFromArray(array $foreign): IR\ForeignCall
{
    $ioWrap = $foreign['ioWrap'] ?? 'none';
    if (\is_string($ioWrap)) {
        $ioWrap = IR\IoWrap::from($ioWrap);
    }

    return new IR\ForeignCall(
        $foreign['backend'] ?? 'php',
        $foreign['kind'] ?? 'call',
        $foreign['path'],
        $foreign['dispatch'] ?? 'global',
        \array_map(operandFromArray(...), $foreign['args'] ?? []),
        $foreign['classPath'] ?? null,
        $foreign['member'] ?? null,
        $ioWrap,
        $foreign['phpValueBox'] ?? false,
        $foreign['handleBox'] ?? false,
        $foreign['handleUnboxArgs'] ?? [],
    );
}

function typeFromArray(mixed $type): ?Ast\TypeNode
{
    // Fixtures embed type ASTs as arrays; only object-typed fixtures that
    // already carry a `TypeNode` survive. Optimizer/codegen mostly need null
    // or an `Ast\TypeNode` for IO checks.
    return $type instanceof Ast\TypeNode ? $type : null;
}
