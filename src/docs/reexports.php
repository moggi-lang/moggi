<?php declare(strict_types=1);

namespace Moggi\Docs;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Ast\Program;

use function Moggi\Modules\classMethodsFromUnits;
use function Moggi\Modules\exportItemChildNames;

function isLocallyDefinedValue(Program $program, string $name): bool
{
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl && $item->name === $name) {
            return true;
        }
        if ($item instanceof Ast\ForeignImportDecl && $item->name === $name) {
            return true;
        }
        if ($item instanceof Ast\ForeignTypeDecl && $item->name === $name) {
            return true;
        }
        if ($item instanceof Ast\ClassDecl) {
            foreach ($item->methods as $method) {
                if ($method->name === $name) {
                    return true;
                }
            }
        }
    }

    return false;
}

function isLocallyDefinedType(Program $program, string $name): bool
{
    foreach ($program->items as $item) {
        if ($item instanceof Ast\DataDecl && $item->name === $name) {
            return true;
        }
        if ($item instanceof Ast\TypeSynonymDecl && $item->name === $name) {
            return true;
        }
        if ($item instanceof Ast\ClassDecl && $item->name === $name) {
            return true;
        }
        if ($item instanceof Ast\ForeignTypeDecl && $item->name === $name) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, array<string, mixed>> $units
 */
function homeModuleForExportedType(string $name, Program $program, array $units): ?string
{
    if (isLocallyDefinedType($program, $name)) {
        return $program->module;
    }

    foreach ($units as $moduleName => $unit) {
        $unitProgram = $unit['program'] ?? null;
        if (!$unitProgram instanceof Program) {
            continue;
        }
        if (isLocallyDefinedType($unitProgram, $name)) {
            return $moduleName;
        }
    }

    return null;
}

/**
 * @param array<string, array<string, mixed>> $units
 * @param array<string, mixed> $exports
 */
function homeModuleForExportedValue(
    string $name,
    Program $program,
    array $units,
    array $exports,
): ?string {
    $origin = $exports['origins'][$name]['module'] ?? null;
    if ($origin !== null && $origin !== $program->module) {
        return $origin;
    }

    if (isLocallyDefinedValue($program, $name)) {
        return $program->module;
    }

    foreach ($program->exports ?? [] as $item) {
        if (($item['tag'] ?? '') !== 'type') {
            continue;
        }

        $className = $item['name'];
        $methods = classMethodsFromUnits($units, $className);
        if ($methods === null) {
            continue;
        }

        if (!\in_array($name, exportItemChildNames($item, $methods), true)) {
            continue;
        }

        $classHome = homeModuleForExportedType($className, $program, $units);
        if ($classHome !== null && $classHome !== $program->module) {
            return $classHome;
        }
    }

    foreach ($units as $moduleName => $unit) {
        if ($moduleName === $program->module) {
            continue;
        }
        $unitProgram = $unit['program'] ?? null;
        if (!$unitProgram instanceof Program) {
            continue;
        }
        if (isLocallyDefinedValue($unitProgram, $name)) {
            return $moduleName;
        }
    }

    return null;
}

/** @param array<string, DocEntity> $canonical */
function canonicalEntityAt(
    array $canonical,
    string $homeModule,
    string $kind,
    string $name,
): ?DocEntity {
    $key = $homeModule . "\0" . $kind . "\0" . $name;

    return $canonical[$key] ?? null;
}

/** @param array<string, DocEntity> $canonical */
function canonicalValueForReexport(
    string $name,
    Program $program,
    array $units,
    array $exports,
    array $canonical,
): ?DocEntity {
    if (isLocallyDefinedValue($program, $name)) {
        return null;
    }

    $home = homeModuleForExportedValue($name, $program, $units, $exports);
    if ($home === null || $home === $program->module) {
        return null;
    }

    return canonicalEntityAt($canonical, $home, 'value', $name)
        ?? canonicalEntityAt($canonical, $home, 'foreign', $name)
        ?? canonicalEntityAt($canonical, $home, 'primop', $name);
}

/** @param array<string, DocEntity> $canonical */
function canonicalTypeForReexport(
    string $name,
    Program $program,
    array $units,
    array $exports,
    array $canonical,
): ?DocEntity {
    if (isLocallyDefinedType($program, $name)) {
        return null;
    }

    $origin = $exports['origins'][$name]['module'] ?? null;
    $home = ($origin !== null && $origin !== $program->module)
        ? $origin
        : homeModuleForExportedType($name, $program, $units);
    if ($home === null || $home === $program->module) {
        return null;
    }

    return canonicalEntityAt($canonical, $home, 'type', $name)
        ?? canonicalEntityAt($canonical, $home, 'class', $name);
}
