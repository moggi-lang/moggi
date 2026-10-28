<?php declare(strict_types=1);

namespace Moggi\IR\Validate;

use Moggi\IR;
use Moggi\IR\Callables\CallableTable;

use const Moggi\IR\Callables\ABI_VERSION;

use function Moggi\IR\Callables\table;
use function Moggi\IR\Visit\irPatternBoundNames;
use function Moggi\IR\Visit\operandChildren;
use function Moggi\IR\Visit\stmtDirectOperands;
use function Moggi\IR\Visit\walkBlock;

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
 * The per-pass optimizer checkpoint sees stages between which a module
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

/**
 * Check every call, partial and reference against the published callable table.
 *
 * This is the post-legalization seam: after capture binding, a direct call's argument vector is
 * exactly the entry's published slots (evidence, captures, values, in that order), a partial is
 * unsaturated, and a lifted entry with captures is never referenced bare. It runs on the no-opt
 * path too, so an unoptimized compile is legal by the same contract as an optimized one.
 *
 * A call to an entry the module does not declare is checked against the arities the importer was
 * given (`$importedArities`, keyed by the name the IR uses, from the checked program's
 * `externalFnRuntimeArity`). That map comes from the defining module's published callable facts,
 * so an imported call is held to the same contract as a local one — its vector is the entry's
 * published arity, nothing shorter and nothing longer.
 *
 * @param array<string, int> $importedArities
 */
function validateCallableLayouts(IR\Module $module, string $pass, array $importedArities = []): void
{
    $table = table($module->functions, (string) $module->moduleName);
    if ($table->byEntry === [] && $importedArities === []) {
        return;
    }

    foreach ($module->functions as $function) {
        $name = $function->name;
        walkBlock(
            $function->body,
            static function (IR\Stmt $stmt) use ($table, $importedArities, $pass, $name): void {
                if ($stmt instanceof IR\Call) {
                    checkDirectCall($table, $importedArities, $stmt->callee, \count($stmt->args), $pass, $name);
                }
            },
            static function (IR\Operand $operand) use ($table, $importedArities, $pass, $name): void {
                if ($operand instanceof IR\Partial) {
                    checkPartial($table, $importedArities, $operand->fn, $operand->arity, \count($operand->args), $pass, $name);
                }

                if ($operand instanceof IR\FnRef) {
                    checkBareReference($table, $operand->name, $pass, $name);
                }
            },
        );
    }
}

function checkDirectCall(
    CallableTable $table,
    array $importedArities,
    string $callee,
    int $actual,
    string $pass,
    string $function,
): void {
    $sig = $table->sig($callee);
    if ($sig === null) {
        $imported = $importedArities[$callee] ?? null;
        if ($imported === null) {
            return;
        }

        if ($actual !== $imported) {
            throw new InvalidIr(
                "function {$function}: call `{$callee}` supplies {$actual} of {$imported} published "
                . 'slot(s) (imported entry, ABI v' . ABI_VERSION . ')',
                $pass,
                $function,
            );
        }

        return;
    }

    $expected = $sig->runtimeArity();
    if ($actual === $expected) {
        return;
    }

    if ($actual < $expected) {
        throw new InvalidIr(
            "function {$function}: direct call `{$callee}` supplies {$actual} of "
            . "{$expected} slots; an under-application is a `Partial`, not a call (ABI v{$sig->abiVersion})",
            $pass,
            $function,
        );
    }

    throw new InvalidIr(
        "function {$function}: direct call `{$callee}` carries {$actual} argument(s); its published "
        . 'layout is |E|=' . \count($sig->evidence) . ' |C|=' . \count($sig->captures)
        . ' |V|=' . \count($sig->params) . " ({$expected} slots, ABI v{$sig->abiVersion})",
        $pass,
        $function,
    );
}

/**
 * A reference that is not applied at all: legal only for an entry with no hidden slots.
 *
 * A captured entry's value is `Partial(entry, arity, captures)`; anything else is a second
 * encoding, because the emitter would have to recover the capture list from the entry's body to
 * make the reference callable.
 */
function checkBareReference(CallableTable $table, string $entry, string $pass, string $function): void
{
    $sig = $table->sig($entry);
    if ($sig === null || $sig->captures === []) {
        return;
    }

    throw new InvalidIr(
        "function {$function}: `{$entry}` is referenced bare; an entry with " . \count($sig->captures)
        . ' capture(s) is acquired as a partial of ' . $sig->runtimeArity()
        . " slot(s) (|E|=" . \count($sig->evidence) . ' |C|=' . \count($sig->captures)
        . ' |V|=' . \count($sig->params) . ', ABI v' . $sig->abiVersion . ')',
        $pass,
        $function,
    );
}

function checkPartial(
    CallableTable $table,
    array $importedArities,
    string $fn,
    int $arity,
    int $supplied,
    string $pass,
    string $function,
): void {
    $sig = $table->sig($fn);
    if ($sig === null) {
        $imported = $importedArities[$fn] ?? null;
        if ($imported === null) {
            return;
        }

        if ($arity !== $imported) {
            throw new InvalidIr(
                "function {$function}: partial of imported `{$fn}` declares arity {$arity}; its "
                . 'published runtime arity is ' . $imported . ' (ABI v' . ABI_VERSION . ')',
                $pass,
                $function,
            );
        }

        return;
    }

    $expected = $sig->runtimeArity();
    if ($arity !== $expected) {
        throw new InvalidIr(
            "function {$function}: partial of `{$fn}` declares arity {$arity}; its published runtime "
            . "arity is {$expected} (|E|=" . \count($sig->evidence) . ' |C|=' . \count($sig->captures)
            . ' |V|=' . \count($sig->params) . ', ABI v' . $sig->abiVersion . ')',
            $pass,
            $function,
        );
    }

    if ($supplied >= $arity) {
        throw new InvalidIr(
            "function {$function}: partial of `{$fn}` supplies {$supplied} of {$arity} slots, which is not "
            . 'an underapplication (ABI v' . $sig->abiVersion . ')',
            $pass,
            $function,
        );
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
 * A region that declares a result names it in its own slot: a value-position `MatchStmt` carries the
 * `dest` its arms write, and a boxed action carries the `result` its deferred body answers with. An
 * arm that leaves the destination undefined — which is what an arm ending in `Ret` amounts to — is
 * refused by name, and so is a deferred body whose last statement returns: both would give the
 * region a second answer, and only emission order would pick between them. This is not a return
 * rule: a `Ret` inside an arm stays legal wherever it sits, as long as the arm still names the
 * region's result.
 *
 * Return regions are deliberately not restricted: the tree's own optimized IR
 * contains both `Ret` and `MatchReturn` inside `Loop` bodies — TCO rewrites a
 * tail-recursive function into a loop whose arms return — and the backends emit
 * them correctly. A legality rule here would reject the standard library, so the
 * only return rule is the observed one.
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
            if ($stmt instanceof IR\MatchStmt && $stmt->dest !== null) {
                foreach ($armTempsList as $armIndex => $armDefinitions) {
                    if (! isset($armDefinitions[$stmt->dest])) {
                        throw new InvalidIr(
                            "function {$function->name}: arm[{$armIndex}] of {$where} names no result: a "
                            . "value-position region declares t{$stmt->dest} and every arm must define it",
                            $pass,
                            $function->name,
                        );
                    }
                }
                $temps[$stmt->dest] = true;
            }

            continue;
        }

        if ($stmt instanceof IR\Loop) {
            validateBlock($function, $stmt->body, $bound, $temps, $pass, $capturesAllowed, $where . '.body');

            continue;
        }

        if ($stmt instanceof IR\IoAssignAction) {
            $tail = $stmt->body->items === [] ? null : $stmt->body->items[\count($stmt->body->items) - 1];
            if ($tail instanceof IR\Ret || $tail instanceof IR\MatchReturn) {
                throw new InvalidIr(
                    "function {$function->name}: the deferred body of t{$stmt->dest} at {$where} answers "
                    . 'by ' . ($tail instanceof IR\Ret ? 'ret' : 'match_return')
                    . ': a region names its result in one slot, the statement\'s own `result`',
                    $pass,
                    $function->name,
                );
            }
            validateBlock($function, $stmt->body, $bound, $temps, $pass, $capturesAllowed, $where . '.body');
            checkOperand($function, $stmt->result, $bound, $temps, $pass, $capturesAllowed, $where);
            $temps[$stmt->dest] = true;

            continue;
        }

        if ($stmt instanceof IR\DictCall && $stmt->localEvidence === null) {
            throw new InvalidIr(
                "function {$function->name}: dict_call t{$stmt->dest}->{$stmt->method} publishes no "
                . "localEvidence at {$where} (the projection's context width is a required fact)",
                $pass,
                $function->name,
            );
        }

        foreach (stmtDirectOperands($stmt) as $operand) {
            checkOperand($function, $operand, $bound, $temps, $pass, $capturesAllowed, $where);
        }

        if ($stmt instanceof IR\Assign
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

    if ($operand instanceof IR\DictMethod && $operand->localEvidence === null) {
        throw new InvalidIr(
            "function {$function->name}: dict_method ->{$operand->method} publishes no localEvidence "
            . "at {$path} (the projection's context width is a required fact)",
            $pass,
            $function->name,
        );
    }

    foreach (operandChildren($operand) as $child) {
        checkOperand($function, $child, $bound, $temps, $pass, $capturesAllowed, $path);
    }
}
