<?php declare(strict_types=1);

namespace Moggi\Backend\Meta;

use Moggi\IR;

use function Moggi\IR\Visit\collectLambdaRefsInBlock;
use function Moggi\IR\Visit\walkOperand;
use function Moggi\Optimize\Support\isCapturedFnName;

/**
 * Backend-neutral IR metadata shared by every code generator.
 *
 * These are facts about the optimized IR, not about a target: constructor
 * layout, newtype/bool backing, the transitive lambda-capture closure, and the
 * source location of a throwing call in operand position. Each backend used to
 * keep a private copy (`jvmBuildNewtypeConstructorMap`, `buildLambdaMeta`, …),
 * which is how identical helpers silently drifted apart. This module is the
 * single owner.
 *
 * Nothing here emits code or knows a target's naming/packaging rules; the
 * emitters stay in `Moggi\Backend\{Php,Jvm,DotNet}\Codegen`.
 */

// --- Constructors / data declarations -----------------------------------------

/**
 * IR data declarations from a module import registry.
 *
 * Several backend decisions are keyed by data declaration but the import
 * context carries the serialized registry shape; this is the one conversion so
 * every `*FromRegistry` helper agrees on the result.
 *
 * @param array<string, array{constructors: array<string, array{name: string, fields: array<int, string>}>, newtype?: bool}> $registryData
 * @return list<IR\DataDecl>
 */
function registryDataToDecls(array $registryData): array
{
    $decls = [];
    foreach ($registryData as $name => $info) {
        $ctors = [];
        foreach (array_values($info['constructors'] ?? []) as $ctor) {
            $ctors[] = new IR\DataConstructor($ctor['name'], $ctor['fields']);
        }
        $decls[] = new IR\DataDecl($name, [], $ctors, (bool) ($info['newtype'] ?? false));
    }

    return $decls;
}

/**
 * `Bool`-backed data: exactly two nullary constructors. Code generators lower
 * such a declaration to the host boolean instead of a boxed constructor.
 */
function isBoolBackedData(IR\DataDecl $decl): bool
{
    if ($decl->name !== 'Bool' || \count($decl->constructors) !== 2) {
        return false;
    }
    foreach ($decl->constructors as $ctor) {
        if ($ctor->fields !== []) {
            return false;
        }
    }

    return true;
}

/** @param list<IR\DataDecl> $dataDecls @return array<string, true> */
function newtypeConstructorMap(array $dataDecls): array
{
    $map = [];
    foreach ($dataDecls as $decl) {
        if (!$decl->isNewtype) {
            continue;
        }
        foreach ($decl->constructors as $ctor) {
            $map[$ctor->name] = true;
        }
    }

    return $map;
}

/** @param list<IR\DataDecl> $dataDecls @return array<string, int> */
function constructorArityMap(array $dataDecls): array
{
    $map = [];
    foreach ($dataDecls as $decl) {
        foreach ($decl->constructors as $ctor) {
            $map[$ctor->name] = \count($ctor->fields);
        }
    }

    return $map;
}

/**
 * Bool constructor → its host boolean (`False => false`, `True => true`).
 *
 * @param list<IR\DataDecl> $dataDecls
 * @return array<string, bool>
 */
function boolConstructorMap(array $dataDecls): array
{
    $map = [];
    foreach ($dataDecls as $decl) {
        if (!isBoolBackedData($decl)) {
            continue;
        }
        $ctors = $decl->constructors;
        $map[$ctors[0]->name] = false;
        $map[$ctors[1]->name] = true;
    }

    return $map;
}

/** @param array<string, mixed> $registryData @return array<string, true> */
function newtypeConstructorMapFromRegistry(array $registryData): array
{
    return newtypeConstructorMap(registryDataToDecls($registryData));
}

/** @param array<string, mixed> $registryData @return array<string, int> */
function constructorArityMapFromRegistry(array $registryData): array
{
    return constructorArityMap(registryDataToDecls($registryData));
}

/** @param array<string, mixed> $registryData @return array<string, bool> */
function boolConstructorMapFromRegistry(array $registryData): array
{
    return boolConstructorMap(registryDataToDecls($registryData));
}

// --- Lambda captures ----------------------------------------------------------

/**
 * Transitive capture closure.
 *
 * `$capturesOf` supplies each lifted function's direct free locals; this
 * propagates the captures a function needs from the functions it references, to
 * a fixpoint. A target may derive the direct captures itself, so that rule is a
 * parameter rather than a copy of the whole closure.
 *
 * @param array<string, IR\FunctionDecl> $byName
 * @param callable(IR\FunctionDecl): list<string> $capturesOf
 * @return array<string, array{captures: list<string>, params: list<string>}>
 */
function lambdaMetaFromCaptures(array $byName, callable $capturesOf): array
{
    $meta = [];
    foreach ($byName as $name => $function) {
        $meta[$name] = [
            'captures' => $capturesOf($function),
            'params' => $function->params,
        ];
    }

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($meta as $name => &$info) {
            $needed = [];
            foreach (collectLambdaRefsInBlock($byName[$name]->body, isCapturedFnName(...)) as $ref) {
                $child = $meta[$ref] ?? null;
                if ($child === null) {
                    continue;
                }
                foreach ($child['captures'] as $capture) {
                    if (!\in_array($capture, $info['params'], true) && !\in_array($capture, $info['captures'], true)) {
                        $needed[] = $capture;
                    }
                }
            }
            $needed = \array_values(\array_unique($needed));
            if ($needed !== []) {
                $info['captures'] = [...$info['captures'], ...$needed];
                $changed = true;
            }
        }
        unset($info);
    }

    return $meta;
}

/**
 * Names a block must have in scope in order to *build* the lifted-function
 * values it references.
 *
 * A `FnRef` to a lifted function is materialized as a closure over that
 * function's own captures, which therefore have to be locals of the enclosing
 * function — yet they appear nowhere in the block's operand tree, so a capture
 * set computed from the operands alone misses them (an IO thunk holding
 * `catch io (\e -> …)` needs the lambda's captures, not just its own free
 * locals). Transitivity is already folded into `$lambdaMeta` by
 * {@see lambdaMetaFromCaptures}.
 *
 * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 * @return list<string>
 */
function lambdaCaptureNamesInBlock(IR\Block $block, array $lambdaMeta): array
{
    $names = [];
    $refs = collectLambdaRefsInBlock(
        $block,
        static fn (string $name): bool => isset($lambdaMeta[$name]),
    );
    foreach ($refs as $lambdaName) {
        foreach ($lambdaMeta[$lambdaName]['captures'] ?? [] as $capture) {
            $names[$capture] = true;
        }
    }

    return \array_keys($names);
}

// --- Statement locations ------------------------------------------------------

/**
 * Source location of a throwing call in operand position.
 *
 * A statement whose value is a call (`error "boom"`, an `exceptionThrow#`,
 * a call value) carries no location of its own; the call operand does. Only the
 * throwing intrinsics are stack frames — arithmetic/comparison intrinsics are
 * not. The raw division primops do belong to that set: they are unchecked by
 * design and trap on a zero divisor, so a program reaching one directly must
 * still get a frame for the statement that faulted.
 */
function operandCallSrcLoc(?IR\Operand $operand): ?IR\SrcLoc
{
    if ($operand === null) {
        return null;
    }
    $found = null;
    walkOperand($operand, static function (IR\Operand $op) use (&$found): void {
        if ($found !== null) {
            return;
        }
        if (
            $op instanceof IR\Intrinsic
            && $op->srcLoc !== null
            && \in_array($op->name, ['error#', 'exceptionThrow#', 'exceptionThrowIo#', 'intDiv#', 'word64Quot#', 'word64Rem#', 'doubleDiv#'], true)
        ) {
            $found = $op->srcLoc;

            return;
        }
        if (($op instanceof IR\ExprCall || $op instanceof IR\ExprCallValue) && $op->srcLoc !== null) {
            $found = $op->srcLoc;
        }
    });

    return $found;
}
