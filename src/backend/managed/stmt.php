<?php declare(strict_types=1);

namespace Moggi\Backend\Managed;

require_once __DIR__ . '/../stack/source_lines.php';
require_once __DIR__ . '/operand.php';

use Moggi\IR;

use function Moggi\Backend\Stack\SourceLines\statementLoc;

/**
 * The target operations one statement emission needs.
 *
 * Each managed backend's `EmitEnv` implements this. Storing a slot, making a runtime IO call
 * and invoking a dictionary helper are where classfile and CIL genuinely differ; which
 * statement is which, and in what order its parts are emitted, is shared by both targets in
 * {@see emitManagedStmt()}.
 */
interface StmtTarget extends OperandTarget
{
    public function storeTemp(int $dest): void;

    public function storeLocal(string $name): void;

    public function storeTempOrPop(?int $dest): void;

    public function emitReturn(): void;

    public function emitCall(IR\Call $stmt): void;

    public function emitCallValue(IR\CallValue $stmt): void;

    public function emitIoRun(IR\IoRun $stmt): void;

    public function emitIoCall(IR\IoCall $stmt): void;

    public function emitIoAssignAction(IR\IoAssignAction $stmt): void;

    public function emitIoMatch(IR\IoMatch $stmt): void;

    public function emitIoThrow(IR\IoThrow $stmt): void;

    public function emitIoCatch(IR\IoCatch $stmt): void;

    public function emitIoFinally(IR\IoFinally $stmt): void;

    public function emitMatchReturn(IR\MatchReturn $stmt): void;

    public function emitMatchStmt(IR\MatchStmt $stmt): void;

    public function emitTailLoop(IR\Loop $stmt): void;

    public function emitTailRecall(IR\TailRecall $stmt): void;

    public function emitDictCall(IR\DictCall $stmt): void;

    public function unsupportedStmt(string $class): \RuntimeException;
}

/**
 * Emit one statement, for any managed target.
 *
 * The two managed emitters carried the same statement switch — the same cases in the same order,
 * each with the same operand/store/pop sequence — and only the instruction that performed the
 * operation differed. The dispatch now lives here once; the target answers through
 * {@see StmtTarget}. IO/match/tail/dictionary statement encodings stay in the host modules, as
 * they must, but their placement in the statement sequence is no longer duplicated.
 */
function emitManagedStmt(StmtTarget $env, IR\Stmt $stmt): void
{
    $env->noteLoc(statementLoc($stmt));

    switch ($stmt::class) {
        case IR\Ret::class:
            /** @var IR\Ret $stmt */
            emitOperand($env, $stmt->value, $env);
            $env->emitReturn();

            return;

        case IR\Assign::class:
            /** @var IR\Assign $stmt */
            emitOperand($env, $stmt->value, $env);
            $env->storeTemp($stmt->dest);

            return;

        case IR\Let::class:
            /** @var IR\Let $stmt */
            emitOperand($env, $stmt->value, $env);
            $env->storeLocal($stmt->name);

            return;

        case IR\Call::class:
            /** @var IR\Call $stmt */
            $env->emitCall($stmt);

            return;

        case IR\CallValue::class:
            /** @var IR\CallValue $stmt */
            $env->emitCallValue($stmt);

            return;

        case IR\IoRun::class:
            /** @var IR\IoRun $stmt */
            $env->emitIoRun($stmt);
            $env->storeTempOrPop($stmt->dest);

            return;

        case IR\IoCall::class:
            /** @var IR\IoCall $stmt */
            $env->emitIoCall($stmt);

            return;

        case IR\IoAssignAction::class:
            /** @var IR\IoAssignAction $stmt */
            $env->emitIoAssignAction($stmt);

            return;

        case IR\IoMatch::class:
            /** @var IR\IoMatch $stmt */
            $env->emitIoMatch($stmt);

            return;

        case IR\IoThrow::class:
            /** @var IR\IoThrow $stmt */
            $env->emitIoThrow($stmt);
            $env->storeTempOrPop($stmt->dest);

            return;

        case IR\IoCatch::class:
            /** @var IR\IoCatch $stmt */
            $env->emitIoCatch($stmt);
            $env->storeTempOrPop($stmt->dest);

            return;

        case IR\IoFinally::class:
            /** @var IR\IoFinally $stmt */
            $env->emitIoFinally($stmt);
            $env->storeTempOrPop($stmt->dest);

            return;

        case IR\MatchReturn::class:
            /** @var IR\MatchReturn $stmt */
            $env->emitMatchReturn($stmt);

            return;

        case IR\MatchStmt::class:
            /** @var IR\MatchStmt $stmt */
            $env->emitMatchStmt($stmt);

            return;

        case IR\Loop::class:
            /** @var IR\Loop $stmt */
            $env->emitTailLoop($stmt);

            return;

        case IR\TailRecall::class:
            /** @var IR\TailRecall $stmt */
            $env->emitTailRecall($stmt);

            return;

        case IR\DictCall::class:
            /** @var IR\DictCall $stmt */
            $env->emitDictCall($stmt);
            $env->storeTemp($stmt->dest);

            return;
    }

    throw $env->unsupportedStmt($stmt::class);
}
