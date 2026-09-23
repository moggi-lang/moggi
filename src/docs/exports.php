<?php declare(strict_types=1);

namespace Moggi\Docs;

use Moggi\Syntax\Ast;

use function Moggi\Modules\classMethodsForExport;
use function Moggi\Modules\collectExports;
use function Moggi\Modules\constructorsForExport;
use function Moggi\Modules\exportItemChildNames;
use function Moggi\Modules\isModuleActiveForCompileBackend;

/** Resolved export list for documentation (local declarations only). */
final class DocExportFilter
{
    /**
     * @param array<string, true> $values
     * @param array<string, array{mode: string, names: list<string>}> $types
     */
    public function __construct(
        public readonly bool $exportAll,
        public readonly array $values,
        public readonly array $types,
    ) {
    }
}

/** Backend implementation modules (`Data.Foo.PHP`, `*.JVM`, `*.DotNet`, …). */
function isBackendImplModule(string $moduleName): bool
{
    $lower = strtolower($moduleName);

    return str_ends_with($lower, '.php')
        || str_ends_with($lower, '.jvm')
        || str_ends_with($lower, '.js')
        || str_ends_with($lower, '.dotnet');
}

/** @return list<string> */
function backendDisplayOrder(): array
{
    return ['php', 'jvm', 'dotnet', 'js'];
}

function backendLabel(string $backendId): string
{
    return match (strtolower($backendId)) {
        'dotnet' => '.NET',
        default => strtoupper($backendId),
    };
}

/**
 * @param array<string, ParsedModule> $modules
 * @return array{
 *   0: array<string, array<string, string>>,
 *   1: array<string, array{facade: string, backend: string}>
 * }
 */
function facadeBackendMaps(array $modules): array
{
    $facades = [];
    $impls = [];
    foreach ($modules as $moduleName => $parsed) {
        $map = $parsed->program->backendMap;
        if ($map === []) {
            continue;
        }
        $facades[$moduleName] = $map;
        foreach ($map as $backend => $impl) {
            $impls[$impl] = ['facade' => $moduleName, 'backend' => $backend];
        }
    }

    return [$facades, $impls];
}

function docExportFilter(Ast\Program $program): DocExportFilter
{
    $exports = $program->exports;
    if ($exports === null) {
        return new DocExportFilter(true, [], []);
    }

    $values = [];
    $types = [];
    foreach ($exports as $item) {
        if (($item['tag'] ?? '') === 'module') {
            continue;
        }
        if (($item['tag'] ?? '') === 'value') {
            $values[$item['name']] = true;
        }
        if (($item['tag'] ?? '') === 'type') {
            $types[$item['name']] = $item['children'] ?? ['mode' => 'none', 'names' => []];
        }
    }

    return new DocExportFilter(false, $values, $types);
}

function isExportedValue(DocExportFilter $filter, Ast\Program $program, string $name): bool
{
    if ($filter->exportAll) {
        return true;
    }
    if (isset($filter->values[$name])) {
        return true;
    }

    foreach ($filter->types as $typeName => $children) {
        $methods = classMethodsForExport($program, $typeName);
        if ($methods === null) {
            continue;
        }
        $exported = exportItemChildNames(['name' => $typeName, 'children' => $children], $methods);
        if (\in_array($name, $exported, true)) {
            return true;
        }
    }

    return false;
}

function isExportedType(DocExportFilter $filter, string $name): bool
{
    return $filter->exportAll || isset($filter->types[$name]);
}

function isExportedCtor(
    DocExportFilter $filter,
    Ast\Program $program,
    string $typeName,
    string $ctorName,
    array $constructorRenames = [],
): bool {
    if ($filter->exportAll) {
        return true;
    }
    if (!isset($filter->types[$typeName])) {
        return false;
    }

    $ctors = constructorsForExport($program, $typeName) ?? [];
    $available = $ctors;
    foreach ($constructorRenames as $alias => $canonical) {
        if (\in_array($canonical, $ctors, true)) {
            $available[] = $alias;
        }
    }
    $selected = exportItemChildNames(
        ['name' => $typeName, 'children' => $filter->types[$typeName]],
        array_values(array_unique($available)),
    );
    foreach ($selected as $name) {
        if (($constructorRenames[$name] ?? $name) === $ctorName) {
            return true;
        }
    }

    return false;
}

/**
 * Collect exports for documentation; returns null when the module was not type-checked.
 *
 * @param array<string, array<string, mixed>> $units
 * @return ?array<string, mixed>
 */
function collectExportsForDocs(
    Ast\Program $program,
    array $localTypes,
    array $units,
    string $moduleName,
    array $checkedModules,
): ?array {
    if (!isset($checkedModules[$moduleName])) {
        return null;
    }
    if (!isModuleActiveForCompileBackend($units, $moduleName)) {
        return null;
    }

    return collectExports($program, $localTypes, $units, $moduleName);
}
