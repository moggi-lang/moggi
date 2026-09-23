<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use function Moggi\Backend\compileBackend;
use function Moggi\Semantics\Kinds\dataKind;
use function Moggi\Semantics\Kinds\registerTypeKind;
use function Moggi\Semantics\Types\registerData;
use function Moggi\Semantics\Types\registerForeignType;
use function Moggi\Semantics\Types\registerTypeSynonym;
use function Moggi\Semantics\Types\typeToString;

use const Moggi\Semantics\IntrinsicRegistry\MODULE_IO;
use const Moggi\Semantics\IntrinsicRegistry\MODULE_PRIM;

/** @param array<string, string> $mapping */
function typesAlphaEqual(Type $left, Type $right, array &$mapping = []): bool
{
    if ($left::class !== $right::class) {
        return false;
    }

    return match ($left::class) {
        TVar::class => match (true) {
            isset($mapping[$left->name]) => $mapping[$left->name] === $right->name,
            \in_array($right->name, $mapping, true) => false,
            default => (($mapping[$left->name] = $right->name) || true),
        },
        TCon::class => $left->name === $right->name
            && count($left->args) === count($right->args)
            && array_all(
                $left->args,
                static fn (Type $arg, int $i): bool => typesAlphaEqual($arg, $right->args[$i], $mapping),
            ),
        TArrow::class => typesAlphaEqual($left->from, $right->from, $mapping)
            && typesAlphaEqual($left->to, $right->to, $mapping),
        default => true,
    };
}

function schemesAlphaEqual(Scheme $left, Scheme $right): bool
{
    $mapping = [];

    return typesAlphaEqual($left->type, $right->type, $mapping);
}

function isFacadeProgram(Ast\Program $program): bool
{
    return $program->backendMap !== [];
}

/**
 * The implementation module a backend map selects, or null when it has no entry for that backend.
 *
 * A missing entry is not an internal error: a facade may deliberately omit a backend (a host
 * binding that exists for one platform only), so callers that must have an implementation raise a
 * diagnostic instead — see `facadeImplModuleNameFor`.
 *
 * @param array<string, string> $backendMap
 */
function resolveImplModuleName(array $backendMap, ?string $compileBackend = null): ?string
{
    return $backendMap[$compileBackend ?? compileBackend()] ?? null;
}

/**
 * The implementation module a facade builds against, or a diagnostic naming the facade and the
 * backends it does implement for.
 *
 * The message carries both names because either side may be the thing to change: compile for a
 * backend the module implements, or add an implementation module and a map entry for the one you
 * are compiling for.
 *
 * @param array<string, array<string, mixed>> $units
 */
function facadeImplModuleNameFor(string $moduleName, array $units): ?string
{
    $unit = $units[$moduleName] ?? [];
    $program = $unit['program'] ?? null;
    $backendMap = $program instanceof Ast\Program
        ? $program->backendMap
        : ($unit['backendMap'] ?? []);

    if ($backendMap === []) {
        return null;
    }

    $implName = resolveImplModuleName($backendMap);
    if ($implName === null) {
        throw facadeMissingBackendError($moduleName, $backendMap, $unit);
    }

    return $implName;
}

/**
 * @param array<string, string> $backendMap
 * @param array<string, mixed> $unit
 */
function facadeMissingBackendError(string $moduleName, array $backendMap, array $unit): TypeError
{
    $span = $unit['headerSpan'] ?? null;

    return new TypeError(
        "module `{$moduleName}` has no `" . compileBackend() . '` implementation (it implements: '
            . \implode(', ', \array_keys($backendMap)) . ')',
        (string) ($unit['path'] ?? ''),
        (string) ($unit['source'] ?? ''),
        \is_array($span) ? (int) ($span['line'] ?? 0) : 0,
        \is_array($span) ? (int) ($span['col'] ?? 0) : 0,
        \is_array($span) ? (int) ($span['endCol'] ?? 0) : 0,
    );
}

function facadeImplModuleName(Ast\Program $program): ?string
{
    if (!isFacadeProgram($program)) {
        return null;
    }

    return resolveImplModuleName($program->backendMap);
}

/** @param array<string, array<string, mixed>> $units */
function redirectFacadeOrigins(array &$available, Ast\Program $program, array $units, string $moduleName): void
{
    if (!isFacadeProgram($program)) {
        return;
    }

    $implName = facadeImplModuleNameFor($moduleName, $units);
    if ($implName === null || !isset($units[$implName])) {
        throw new TypeError('unknown facade implementation module `' . ($implName ?? '') . '`');
    }

    $impl = $units[$implName];
    if (($impl['parsed'] ?? false) !== true) {
        throw new TypeError("facade implementation module `{$implName}` is not fully parsed");
    }

    $implExports = implExportsForFacadeMerge($impl, $units, $implName);
    if ($implExports === null) {
        throw new TypeError(
            "facade implementation module `{$implName}` is missing export metadata required by `{$moduleName}`",
            $units[$moduleName]['path'] ?? '',
            $units[$moduleName]['source'] ?? '',
        );
    }

    // Foreign types (and other data) declared only in the impl must be
    // available for facade export lists and signature elaborations.
    foreach ($implExports['data'] ?? [] as $name => $info) {
        if (!isset($available['data'][$name])) {
            $available['data'][$name] = $info;
        }
    }

    foreach ($program->items as $item) {
        if (!$item instanceof Ast\FunctionDecl || !$item->signatureOnly) {
            continue;
        }

        $name = $item->name;
        $scheme = $available['env'][$name] ?? null;
        if ($scheme === null) {
            continue;
        }

        validateFacadeExport($name, $scheme, $implExports, $implName, $units[$moduleName]['path'], $units[$moduleName]['source']);
        $available['origins'][$name] = $implExports['origins'][$name];
    }
}

/**
 * @param array{env: array<string, mixed>, origins: array<string, array{module: string, namespace: string, phpName: string}>} $implExports
 * @param Scheme $facadeScheme
 */
function validateFacadeExport(
    string $name,
    Scheme $facadeScheme,
    array $implExports,
    string $implName,
    string $filename,
    string $source,
): void {
    if (!isset($implExports['env'][$name])) {
        throw new TypeError(
            "facade export `{$name}` is missing from implementation module `{$implName}`",
            $filename,
            $source,
        );
    }

    $implType = $implExports['env'][$name];
    $facadeType = $facadeScheme;
    if (!schemesAlphaEqual($facadeType, $implType)) {
        throw new TypeError(
            "facade export `{$name}` type `"
            . typeToString($facadeType->type)
            . '` does not match implementation `'
            . typeToString($implType->type)
            . '`',
            $filename,
            $source,
        );
    }
}

/** @param array<string, array<string, mixed>> $units @return list<string> */
function facadeDependencyModules(array $units, string $moduleName): array
{
    $implName = facadeImplModuleNameFor($moduleName, $units);

    return $implName === null ? [] : [$implName];
}

/** @param array<string, array<string, mixed>> $units */
function facadeModuleForImpl(array $units, string $implModuleName): ?string
{
    foreach ($units as $moduleName => $unit) {
        $program = $unit['program'] ?? null;
        if ($program instanceof Ast\Program) {
            if (!isFacadeProgram($program)) {
                continue;
            }
            $backendMap = $program->backendMap;
        } else {
            $backendMap = $unit['backendMap'] ?? [];
            if ($backendMap === []) {
                continue;
            }
        }

        foreach ($backendMap as $impl) {
            if ($impl === $implModuleName) {
                return $moduleName;
            }
        }
    }

    return null;
}

/**
 * Backend impl modules cannot import their facade (cycle). Copy facade ADT/type-synonym
 * declarations into the impl typecheck state so foreign imports can name them.
 *
 *
 * @param array<string, array<string, mixed>> $units
 */
/**
 * Types registered in localTypes but not declared in the module program (e.g. facade
 * ADTs copied into backend impl modules) must appear in the typecheck import context.
 *
 * @param array<string, mixed> $importContext
 * @param array{data?: array<string, mixed>, typeSynonyms?: array<string, mixed>} $localTypes
 */
function mergeModuleLocalTypesIntoImportContext(array &$importContext, Ast\Program $program, array $localTypes): void
{
    $declaredData = [];
    $declaredTypeSynonyms = [];
    foreach ($program->items as $item) {
        // DataDecl and ForeignTypeDecl both live in localTypes['data'] and are
        // re-registered during checkProgram — exclude them here so they are not
        // applied twice via import context (duplicate type error).
        if ($item instanceof Ast\DataDecl || $item instanceof Ast\ForeignTypeDecl) {
            $declaredData[$item->name] = true;
        }

        if ($item instanceof Ast\TypeSynonymDecl) {
            $declaredTypeSynonyms[$item->name] = true;
        }
    }

    foreach ($localTypes['data'] ?? [] as $name => $info) {
        if (!isset($declaredData[$name]) && !isset($importContext['data'][$name])) {
            $importContext['data'][$name] = $info;
        }
    }

    foreach ($localTypes['typeSynonyms'] ?? [] as $name => $type) {
        if (!isset($declaredTypeSynonyms[$name]) && !isset($importContext['typeSynonyms'][$name])) {
            $importContext['typeSynonyms'][$name] = $type;
        }
    }
}

function mergeFacadeTypesForImpl(TypeCheckState $state, array $units, string $implModuleName): void
{
    $facadeName = facadeModuleForImpl($units, $implModuleName);
    if ($facadeName === null || !isset($units[$facadeName]['program'])) {
        return;
    }

    // Facade synonyms like `type Double = Double#` need Magichash types in scope.
    // Impl modules are typechecked before their facade (facade depends on impl),
    // so pull primitive type data from the synthetic Prim/IO units directly.
    foreach ([
        MODULE_PRIM,
        MODULE_IO,
    ] as $primModule) {
        $primLocal = $units[$primModule]['localTypes'] ?? null;
        if (!\is_array($primLocal)) {
            continue;
        }
        foreach ($primLocal['data'] ?? [] as $name => $info) {
            if (!isset($state->data[$name])) {
                $state->data[$name] = $info;
                registerTypeKind(
                    $state,
                    $name,
                    dataKind(count($info['params'])),
                );
            }
        }
    }

    foreach ($units[$facadeName]['program']->items as $item) {
        if ($item instanceof Ast\DataDecl) {
            registerData($state, $item);
            continue;
        }

        if ($item instanceof Ast\ForeignTypeDecl) {
            registerForeignType($state, $item);
            continue;
        }

        if ($item instanceof Ast\TypeSynonymDecl) {
            registerTypeSynonym($state, $item);
        }
    }
}
