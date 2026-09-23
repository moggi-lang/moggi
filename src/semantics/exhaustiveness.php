<?php declare(strict_types=1);

namespace Moggi\Semantics\Exhaustiveness;

use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\prune;
use function Moggi\Semantics\Types\typeFail;

function caseExhaustivenessProven(TypeCheckState $state, Ast\CaseExpr $expr, Type $scrutineeType): bool
{
    $mode = caseExhaustivenessMode($state, $expr, $scrutineeType);

    return match ($mode['kind']) {
        'all' => true,
        'data' => dataCaseExhaustivenessProven($state, $expr, $mode['dataName']),
        'int' => intCaseExhaustivenessProven($expr),
        'list' => listCaseExhaustivenessProven($expr),
        default => false,
    };
}

function dataCaseExhaustivenessProven(TypeCheckState $state, Ast\CaseExpr $expr, string $dataName): bool
{
    $data = $state->data[$dataName] ?? null;
    if ($data === null) {
        return false;
    }

    $covered = coveredDataConstructorsInCase($state, $expr);

    foreach ($data['constructors'] as $ctor) {
        if (isset($ctor['aliasOf'])) {
            continue;
        }
        $canonical = $ctor['name'];
        if (empty($covered[$canonical])) {
            return false;
        }
    }

    return true;
}

function intCaseExhaustivenessProven(Ast\CaseExpr $expr): bool
{
    return intCaseCoverageMissing($expr) === [];
}

function intCaseCoverageMissing(Ast\CaseExpr $expr): array
{
    foreach ($expr->alts as $alt) {
        if (patternCoversAll($alt->pattern)) {
            return [];
        }
    }

    $literals = [];
    foreach ($expr->alts as $alt) {
        if ($alt->pattern instanceof Ast\PatLit) {
            $literals[] = $alt->pattern->value;
        }
    }

    return missingIntegerPatterns($literals);
}

function listCaseExhaustivenessProven(Ast\CaseExpr $expr): bool
{
    $coversEmpty = false;
    $coversNonempty = false;
    foreach ($expr->alts as $alt) {
        $coversEmpty = $coversEmpty || patternCoversEmptyList($alt->pattern);
        $coversNonempty = $coversNonempty || patternCoversNonemptyList($alt->pattern);
    }

    return $coversEmpty && $coversNonempty;
}

function checkCaseExhaustiveness(TypeCheckState $state, Ast\CaseExpr $expr, Type $scrutineeType): void
{
    $mode = caseExhaustivenessMode($state, $expr, $scrutineeType);

    match ($mode['kind']) {
        'all' => null,
        'data' => checkDataCaseExhaustiveness($state, $expr, $mode['dataName']),
        'int' => checkIntCaseExhaustiveness($state, $expr),
        'list' => checkListCaseExhaustiveness($state, $expr),
        default => null,
    };
}

function isListScrutineeType(Type $type): bool
{
    return $type instanceof TCon && $type->name === 'List';
}

function checkDataCaseExhaustiveness(TypeCheckState $state, Ast\CaseExpr $expr, string $dataName): void
{
    $data = $state->data[$dataName] ?? null;
    if ($data === null) {
        return;
    }

    foreach ($expr->alts as $alt) {
        if (patternCoversAll($alt->pattern)) {
            return;
        }
    }

    $covered = coveredDataConstructorsInCase($state, $expr);

    $missing = [];
    foreach ($data['constructors'] as $ctor) {
        if (isset($ctor['aliasOf'])) {
            continue;
        }
        if (empty($covered[$ctor['name']])) {
            $missing[] = formatMissingConstructorPattern($ctor);
        }
    }

    if ($missing === []) {
        return;
    }

    throw typeFailExhaustive(
        $state,
        'non-exhaustive case: missing patterns: ' . \implode(', ', $missing),
        $expr,
        $missing,
    );
}

/**
 * @return array{kind: 'all'|'data'|'int'|'list'|'none', dataName?: string}
 */
function caseExhaustivenessMode(TypeCheckState $state, Ast\CaseExpr $expr, Type $scrutineeType): array
{
    $scrutineeType = prune($state, $scrutineeType);

    foreach ($expr->alts as $alt) {
        if (patternCoversAll($alt->pattern)) {
            return ['kind' => 'all'];
        }
    }

    $dataName = dataNameFromScrutinee($state, $scrutineeType)
        ?? dataNameFromCasePatterns($state, $expr);
    if ($dataName !== null) {
        return ['kind' => 'data', 'dataName' => $dataName];
    }

    if ($scrutineeType instanceof TInt || intCaseFromPatterns($expr)) {
        return ['kind' => 'int'];
    }

    if (isListScrutineeType($scrutineeType) && listCaseFromPatterns($expr)) {
        return ['kind' => 'list'];
    }

    return ['kind' => 'none'];
}

function coveredDataConstructorsInCase(TypeCheckState $state, Ast\CaseExpr $expr): array
{
    $covered = [];
    foreach ($expr->alts as $alt) {
        foreach (coveredConstructors($state, $alt->pattern) as $ctor) {
            $covered[$ctor] = true;
        }
    }

    return $covered;
}

function intCaseFromPatterns(Ast\CaseExpr $expr): bool
{
    foreach ($expr->alts as $alt) {
        if ($alt->pattern instanceof Ast\PatLit) {
            return true;
        }
    }

    return false;
}

function listCaseFromPatterns(Ast\CaseExpr $expr): bool
{
    foreach ($expr->alts as $alt) {
        if ($alt->pattern instanceof Ast\PatNil || $alt->pattern instanceof Ast\PatCons) {
            return true;
        }
    }

    return false;
}

function checkListCaseExhaustiveness(TypeCheckState $state, Ast\CaseExpr $expr): void
{
    foreach ($expr->alts as $alt) {
        if (patternCoversAll($alt->pattern)) {
            return;
        }
    }

    $coversEmpty = false;
    $coversNonempty = false;
    foreach ($expr->alts as $alt) {
        $coversEmpty = $coversEmpty || patternCoversEmptyList($alt->pattern);
        $coversNonempty = $coversNonempty || patternCoversNonemptyList($alt->pattern);
    }

    if ($coversEmpty && $coversNonempty) {
        return;
    }

    $missing = [];
    if (!$coversEmpty) {
        $missing[] = '[]';
    }

    if (!$coversNonempty) {
        $missing[] = '(_ : _)';
    }

    throw typeFailExhaustive(
        $state,
        'non-exhaustive case: missing patterns: ' . \implode(', ', $missing),
        $expr,
        $missing,
    );
}

/**
 * @param list<string> $missing
 */
function typeFailExhaustive(
    TypeCheckState $state,
    string $message,
    Ast\AstNode $at,
    array $missing,
): TypeError {
    $err = typeFail($state, $message, $at);
    $err->missingPatterns = $missing;
    $err->diagnosticCode = 'non-exhaustive';

    return $err;
}

function patternCoversEmptyList(Ast\AstNode $pattern): bool
{
    return match ($pattern::class) {
        Ast\PatWild::class,
        Ast\PatVar::class,
        Ast\PatNil::class => true,
        Ast\PatCons::class => false,
        default => patternCoversAll($pattern),
    };
}

function patternCoversNonemptyList(Ast\AstNode $pattern): bool
{
    return match ($pattern::class) {
        Ast\PatWild::class,
        Ast\PatVar::class,
        Ast\PatCons::class => true,
        Ast\PatNil::class => false,
        default => patternCoversAll($pattern),
    };
}

function checkIntCaseExhaustiveness(TypeCheckState $state, Ast\CaseExpr $expr): void
{
    $missing = intCaseCoverageMissing($expr);
    if ($missing === []) {
        return;
    }

    throw typeFailExhaustive(
        $state,
        formatIntCaseMissingMessage($missing),
        $expr,
        $missing,
    );
}

/** @param array<int, string> $missing */
function formatIntCaseMissingMessage(array $missing): string
{
    $literals = array_values(array_filter($missing, static fn (string $pattern): bool => $pattern !== '_'));
    $needsCatchAll = \in_array('_', $missing, true);

    if ($literals === [] && $needsCatchAll) {
        return 'non-exhaustive case: add a catch-all (_) pattern';
    }

    if ($literals !== [] && $needsCatchAll) {
        return 'non-exhaustive case: missing patterns: ' . \implode(', ', $literals) . '; add a catch-all (_) pattern';
    }

    return 'non-exhaustive case: missing patterns: ' . \implode(', ', $missing);
}

/** @param array<int, int> $literals */
function missingIntegerPatterns(array $literals): array
{
    $literals = array_values(array_unique($literals));
    sort($literals);

    $missing = [];
    for ($i = 0; $i < count($literals) - 1; ++$i) {
        for ($value = $literals[$i] + 1; $value < $literals[$i + 1]; ++$value) {
            $missing[] = (string) $value;
        }
    }

    $missing[] = '_';

    return $missing;
}

function dataNameFromScrutinee(TypeCheckState $state, Type $scrutineeType): ?string
{
    if (!$scrutineeType instanceof TCon) {
        return null;
    }

    return isset($state->data[$scrutineeType->name]) ? $scrutineeType->name : null;
}

function dataNameFromCasePatterns(TypeCheckState $state, Ast\CaseExpr $expr): ?string
{
    foreach ($expr->alts as $alt) {
        if (!$alt->pattern instanceof Ast\PatCon && !$alt->pattern instanceof Ast\PatRecord) {
            continue;
        }

        foreach ($state->data as $name => $data) {
            foreach ($data['constructors'] as $ctor) {
                if ($ctor['name'] === $alt->pattern->name) {
                    return $name;
                }
            }
        }
    }

    return null;
}

function patternCoversAll(Ast\AstNode $pattern): bool
{
    return $pattern instanceof Ast\PatWild || $pattern instanceof Ast\PatVar;
}

function coveredConstructors(TypeCheckState $state, Ast\AstNode $pattern): array
{
    return match ($pattern::class) {
        Ast\PatCon::class,
        Ast\PatRecord::class => [canonicalConstructorName($state, $pattern->name)],
        default => [],
    };
}

function canonicalConstructorName(TypeCheckState $state, string $name): string
{
    return $state->constructorRenames[$name] ?? $name;
}

/** @param array{name: string, fields: array<int, string>} $ctor */
function formatMissingConstructorPattern(array $ctor): string
{
    if ($ctor['fields'] === []) {
        return $ctor['name'];
    }

    return $ctor['name'] . ' ' . \implode(' ', \array_fill(0, count($ctor['fields']), '_'));
}
