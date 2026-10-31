<?php declare(strict_types=1);

namespace Moggi\Backend\Managed;

require_once __DIR__ . '/../stack/source_lines.php';

use Moggi\IR;

use function Moggi\Backend\Stack\SourceLines\operandLoc;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Semantics\Kernel\boolCtorValue;

/**
 * The target operations one operand emission needs.
 *
 * Each managed backend's `EmitEnv` implements this: padding an int into a boxed value, loading a
 * slot, boxing a boolean and making a dictionary call are the places classfile and CIL genuinely
 * differ. Everything else about emitting an operand — which node is which, and in what order the
 * parts are emitted — is the same on both targets and lives in {@see emitOperand()}.
 */
interface OperandTarget
{
    public function pushInt(int $value): void;

    public function pushString(string $value): void;

    public function pushDouble(float $value): void;

    public function pushNull(): void;

    public function pushBool(bool $value): void;

    public function loadNamed(string $name): void;

    public function loadTemp(int $id): void;

    public function noteLoc(?IR\SrcLoc $loc): void;

    public function intrinsic(IR\Intrinsic $op): void;

    public function listLit(IR\ListLit $op): void;

    public function foreign(IR\ForeignCall $op): void;

    public function partial(IR\Partial $op): void;

    public function dictMethodCall(): void;

    public function directCall(string $name): void;

    public function fnValue(string $name, int $arity): void;

    /** The target's name in diagnostics: `JVM`, `DotNet`. */
    public function label(): string;

    public function unsupported(string $class): \RuntimeException;
}

/**
 * Emit one operand, for any managed target.
 *
 * The two managed emitters carried this chain as near-copies (same node order, same function-
 * reference resolution, same dictionary-method sequence), differing only in how a value is pushed
 * or a slot is loaded — which is now the {@see OperandTarget} implementation rather than a second
 * copy of the chain. A new operand variant is added here once; a target that cannot represent it
 * refuses through `unsupported()`, naming itself.
 *
 * @param object $env the backend's `EmitEnv`; both read `lambdaMeta` and `functionArity` from it
 */
function emitOperand(object $env, IR\Operand $op, OperandTarget $target): void
{
    if ($op instanceof IR\ConstInt || $op instanceof IR\ConstChar) {
        $target->pushInt($op->value);

        return;
    }
    if ($op instanceof IR\ConstStr) {
        $target->pushString($op->value);

        return;
    }
    if ($op instanceof IR\ConstDouble) {
        $target->pushDouble($op->value);

        return;
    }
    if ($op instanceof IR\Unit) {
        $target->pushNull();

        return;
    }
    if ($op instanceof IR\Local) {
        $target->loadNamed($op->name);

        return;
    }
    if ($op instanceof IR\Temp) {
        $target->loadTemp($op->id);

        return;
    }
    if ($op instanceof IR\Intrinsic) {
        $target->noteLoc(operandLoc($op));
        $target->intrinsic($op);

        return;
    }
    if ($op instanceof IR\ListLit) {
        $target->listLit($op);

        return;
    }
    if ($op instanceof IR\ForeignCall) {
        $target->foreign($op);

        return;
    }
    if ($op instanceof IR\Partial) {
        $target->partial($op);

        return;
    }
    if ($op instanceof IR\DictMethod) {
        emitOperand($env, $op->evidence, $target);
        $target->pushString($op->method);
        $target->dictMethodCall();

        return;
    }
    if ($op instanceof IR\FnRef) {
        if (($boolValue = boolCtorValue($op->name)) !== null) {
            $target->pushBool($boolValue);

            return;
        }
        if (isset($env->lambdaMeta[$op->name])) {
            $arity = \count($env->lambdaMeta[$op->name]['params'] ?? []);
            if ($arity === 0) {
                $target->directCall($op->name);

                return;
            }
            $target->fnValue($op->name, $arity);

            return;
        }
        $arity = $env->functionArity[$op->name] ?? null;
        if ($arity === null) {
            $parsed = parseResolvedSymbol($op->name);
            if ($parsed !== null && ! isset($env->lambdaMeta[$parsed['name']])) {
                $arity = $env->functionArity[$parsed['name']] ?? null;
            }
        }
        if ($arity === null) {
            throw new \RuntimeException($target->label() . " emit: FnRef `{$op->name}` has unknown arity");
        }
        if ($arity === 0) {
            $target->directCall($op->name);

            return;
        }
        $target->fnValue($op->name, $arity);

        return;
    }

    throw $target->unsupported($op::class);
}
