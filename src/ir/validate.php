<?php declare(strict_types=1);

namespace Moggi\IR\Validate;

use Moggi\IR;

use function Moggi\IR\Visit\irPatternBoundNames;
use function Moggi\IR\Visit\operandChildren;
use function Moggi\IR\Visit\stmtDirectOperands;

final class InvalidIr extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $pass,
        public readonly string $function,
    ) {
        $suffix = " (pass {$pass})";
        parent::__construct(\str_ends_with($message, $suffix) ? $message : $message . $suffix);
    }
}

function validateModule(IR\Module $module, string $pass): void
{
    $names = [];
    foreach ($module->functions as $function) {
        $names[$function->name] = true;
    }

    $entry = $module->entry;
    if ($entry !== null && ! isset($names[$entry->name])) {
        throw new InvalidIr(
            "entry point `{$entry->name}` names no function",
            $pass,
            $entry->name,
        );
    }

    $source = $module->sourceFile !== '' ? $module->sourceFile : ($module->moduleName !== '' ? $module->moduleName : '<module>');
    validateFunctionList($module->functions, $pass, $source);
}

/**
 * Validates a function list without the entry-point check.
 *
 * The per-pass optimizer checkpoint (F1a) sees stages between which a module
 * does not exist yet — `optimize()` mostly rewrites `list<FunctionDecl>`. The
 * entry rule stays with the two seams that do have a module (post-lower and
 * post-opt); a stage is not allowed to invent functions that later stages must
 * look up by name, and that is what `validateModule` still checks.
 *
 * @param list<IR\FunctionDecl> $functions
 */
function validateFunctionList(array $functions, string $pass, string $source): void
{
    foreach ($functions as $function) {
        try {
            validateFunction($function, $pass);
        } catch (InvalidIr $e) {
            throw new InvalidIr(
                "{$source}: {$e->getMessage()}",
                $pass,
                $function->name,
            );
        }
    }
}

function validateFunction(IR\FunctionDecl $function, string $pass): void
{
    $bound = \array_fill_keys($function->params, true);
    /** @var array<int, true> $temps */
    $temps = [];

    validateBlock(
        $function,
        $function->body,
        $bound,
        $temps,
        $pass,
        $function->kind->takesCaptures(),
        'body',
    );
}

/**
 * `$bound` and `$temps` are extended in place: this block is a region of the
 * function, not a nested scope. Match arms and guard preparations get their own
 * copy; `IoAssignAction` and `Loop` do not, because both are emitted inline and
 * their `let` binders stay visible to whatever follows.
 *
 * Return regions are deliberately not restricted (D17, 2026-10-21): the tree's
 * own optimized IR contains both `Ret` and `MatchReturn` inside `Loop` bodies —
 * TCO rewrites a tail-recursive function into a loop whose arms return — and the
 * backends emit them correctly. A legality rule here would reject the standard
 * library, so the only return rule on record is the observed one.
 *
 * @param array<string, true> $bound
 * @param array<int, true> $temps defined before this block starts; extended in place
 */
function validateBlock(
    IR\FunctionDecl $function,
    IR\Block $block,
    array &$bound,
    array &$temps,
    string $pass,
    bool $capturesAllowed,
    string $path,
): void {
    foreach ($block->items as $index => $stmt) {
        $where = $path . '[' . $index . ']';

        if ($stmt instanceof IR\Let) {
            checkOperand($function, $stmt->value, $bound, $temps, $pass, $capturesAllowed, $where);
            $bound[$stmt->name] = true;

            continue;
        }

        if ($stmt instanceof IR\MatchStmt || $stmt instanceof IR\MatchReturn || $stmt instanceof IR\IoMatch) {
            checkOperand($function, $stmt->scrutinee, $bound, $temps, $pass, $capturesAllowed, $where);
            $outerTemps = $temps;
            /** @var list<array<int, true>> $arms' temps, one entry per arm */
            $armTempsList = [];
            foreach ($stmt->arms as $armIndex => $arm) {
                $armWhere = $where . '.arm[' . $armIndex . ']';
                $armBound = $bound;
                $armTemps = $outerTemps;
                foreach (irPatternBoundNames($arm->pattern) as $name) {
                    $armBound[$name] = true;
                }

                $bodyTemps = $armTemps;
                foreach ($arm->guards as $guardIndex => $guard) {
                    validateBlock(
                        $function,
                        $guard->prep,
                        $armBound,
                        $bodyTemps,
                        $pass,
                        $capturesAllowed,
                        $armWhere . '.guard[' . $guardIndex . '].prep',
                    );
                    checkOperand(
                        $function,
                        $guard->cond,
                        $armBound,
                        $bodyTemps,
                        $pass,
                        $capturesAllowed,
                        $armWhere . '.guard[' . $guardIndex . '].cond',
                    );
                }
                validateBlock($function, $arm->body, $armBound, $bodyTemps, $pass, $capturesAllowed, $armWhere . '.body');
                $armTempsList[] = $bodyTemps;
            }

            $common = $armTempsList[0] ?? $temps;
            foreach ($armTempsList as $armTemps) {
                $common = \array_intersect_key($common, $armTemps);
            }
            $temps = $common;
            if (($stmt instanceof IR\MatchStmt || $stmt instanceof IR\IoMatch) && $stmt->dest !== null) {
                $temps[$stmt->dest] = true;
            }

            continue;
        }

        if ($stmt instanceof IR\Loop) {
            validateBlock($function, $stmt->body, $bound, $temps, $pass, $capturesAllowed, $where . '.body');

            continue;
        }

        if ($stmt instanceof IR\IoAssignAction) {
            validateBlock($function, $stmt->body, $bound, $temps, $pass, $capturesAllowed, $where . '.body');
            checkOperand($function, $stmt->result, $bound, $temps, $pass, $capturesAllowed, $where);
            $temps[$stmt->dest] = true;

            continue;
        }

        foreach (stmtDirectOperands($stmt) as $operand) {
            checkOperand($function, $operand, $bound, $temps, $pass, $capturesAllowed, $where);
        }

        if ($stmt instanceof IR\Assign
            || $stmt instanceof IR\Binop
            || $stmt instanceof IR\Call
            || $stmt instanceof IR\CallValue
            || $stmt instanceof IR\DictCall) {
            $temps[$stmt->dest] = true;

            continue;
        }

        if (($stmt instanceof IR\IoRun
            || $stmt instanceof IR\IoThrow
            || $stmt instanceof IR\IoCatch
            || $stmt instanceof IR\IoFinally)
            && $stmt->dest !== null) {
            $temps[$stmt->dest] = true;

            continue;
        }

        if ($stmt instanceof IR\IoCall && $stmt->dest !== null) {
            $temps[$stmt->dest] = true;
        }
    }
}

/**
 * @param array<string, true> $bound
 * @param array<int, true> $temps
 */
function checkOperand(
    IR\FunctionDecl $function,
    IR\Operand $operand,
    array $bound,
    array $temps,
    string $pass,
    bool $capturesAllowed,
    string $path,
): void {
    if ($operand instanceof IR\Local && ! isset($bound[$operand->name]) && ! $capturesAllowed) {
        throw new InvalidIr(
            "function {$function->name}: unbound local `{$operand->name}` at {$path} (node Local)",
            $pass,
            $function->name,
        );
    }

    if ($operand instanceof IR\Temp && ! isset($temps[$operand->id])) {
        throw new InvalidIr(
            "function {$function->name}: temp t{$operand->id} read before it is defined at {$path} (node Temp)",
            $pass,
            $function->name,
        );
    }

    foreach (operandChildren($operand) as $child) {
        checkOperand($function, $child, $bound, $temps, $pass, $capturesAllowed, $path);
    }
}
