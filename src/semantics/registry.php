<?php declare(strict_types=1);

namespace Moggi\Semantics\Registry;

use Moggi\Modules\ImportContext;
use Moggi\Semantics\Deriving\ClassDeriveFacts;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Deriving\derivedProjectInstanceRecords;
use function Moggi\Semantics\Deriving\standaloneDerivingProjectRecords;
use function Moggi\Semantics\Foreign\foreignParamNames;
use function Moggi\Semantics\Types\associatedEquationsMapFromDecl;
use function Moggi\Semantics\Types\installTypeSynonymKind;
use function Moggi\Semantics\Types\instanceIntrinsicMethods;
use function Moggi\Semantics\Types\registerClass;
use function Moggi\Semantics\Types\registerClassMethodExportSchemes;
use function Moggi\Semantics\Types\registerConstructorAlias;
use function Moggi\Semantics\Types\registerData;
use function Moggi\Semantics\Types\registerForeignType;
use function Moggi\Semantics\Types\registerFunctionScheme;
use function Moggi\Semantics\Types\registerInferredFunctionPlaceholder;
use function Moggi\Semantics\Types\registerInferredSignatureScheme;
use function Moggi\Semantics\Types\registerTypeSynonym;
use function Moggi\Semantics\Types\syncAssociatedFamiliesFromClasses;
use function Moggi\Semantics\Types\typeFail;
use function Moggi\Syntax\exportedChildrenSpec;
use function Moggi\Syntax\surfaceConstructorAlias;

/** Synthesize the `FunctionDecl` a foreign import registers its scheme through. */
function foreignFunctionDecl(Ast\ForeignImportDecl $item): Ast\FunctionDecl
{
    return new Ast\FunctionDecl(
        $item->name,
        $item->type,
        \array_map(
            static fn (string $param): Ast\PatVar => new Ast\PatVar($param),
            foreignParamNames($item->type),
        ),
        new Ast\SignatureOnly(),
        foreign: true,
    );
}

function applyImportContext(TypeCheckState $state, Ast\Program $program, ImportContext $importContext): void
{
    $interface = $importContext->interface;

    // One table, read once per payload the checker keeps separately: the records are the source and
    // these are the state maps inference resolves through.
    foreach ($interface->env() as $name => $scheme) {
        $state->env[$name] = $scheme;
    }

    foreach ($interface->constructorRenames() as $local => $canonical) {
        $state->constructorRenames[$local] = $canonical;
    }

    foreach ($interface->typeSynonyms() as $name => $type) {
        $state->typeSynonyms[$name] = $type;
        installTypeSynonymKind($state, $name, $type);
    }

    foreach ($interface->data() as $name => $info) {
        $state->data[$name] = $info;
        Kinds\registerTypeKind($state, $name, Kinds\kindFromDataInfo($info));
        Kinds\installPromotedFromDataInfo($state, $name, $info);
    }

    foreach ($importContext->classes as $name => $info) {
        $state->classes[$name] = $info;
    }
    $state->classParamKindsCache = null;
    syncAssociatedFamiliesFromClasses($state);

    $state->qualifiedModules = $interface->qualifiedModules;
    $state->qualifiedOrigins = $interface->qualifiedOrigins();
    $state->qualifiedEnv = $interface->qualifiedEnv();
    $state->intrinsicWrappers = $interface->intrinsicWrappers();

    $state->ambiguousImports = $interface->ambiguousNames();
    $state->classModuleScopes = $importContext->classScopes;
    $state->externalFns = $interface->externalFns();

    $world = $importContext->instanceWorld;
    $state->instanceWorld = $world;
    $index = $world->index();
    $state->projectInstancesByClass = $index['byClass'];
    $state->projectInstancesByClassHead = $index['byClassHead'];
    $state->associatedEquations = $index['associatedEquations'];

    $state->currentModule = $importContext->currentModule;
    $state->checkedInstances = [];
    $state->programModuleBackend = $program->moduleBackend;

    $declaredTypeNames = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\TypeSynonymDecl || $item instanceof Ast\DataDecl) {
            $declaredTypeNames[$item->name] = true;
        }
    }
    $state->declaredTypeNames = $declaredTypeNames;
    syncAssociatedFamiliesFromClasses($state);
}

function registerTypeDeclarations(TypeCheckState $state, Ast\Program $program): void
{
    foreach ($program->items as $item) {
        if ($item instanceof Ast\DataDecl) {
            registerData($state, $item);
            registerSurfaceConstructorAlias($state, $item, $program->exports);
        }

        if ($item instanceof Ast\TypeSynonymDecl) {
            registerTypeSynonym($state, $item);
        }

        if ($item instanceof Ast\ForeignTypeDecl) {
            registerForeignType($state, $item);
        }

        if ($item instanceof Ast\ClassDecl) {
            if (! isset($state->classes[$item->name])) {
                registerClass($state, $item, $state->currentModule ?? '');
            }
            registerClassMethodExportSchemes($state, $item);
        }
    }
}

/**
 * Register the constructor alias a module's own export list declares, so the
 * name the module gives the type constructs and matches wherever the declaration
 * is in scope (`Data.Tuple`'s `Solo (Solo, MkSolo)` -- the rule is
 * `Syntax\surfaceConstructorAlias`).
 *
 * @param ?list<array{tag: string, name: string, path?: list<string>, children?: array{mode: string, names: list<string>}, section?: string}> $exports
 */
function registerSurfaceConstructorAlias(TypeCheckState $state, Ast\DataDecl $decl, ?array $exports): void
{
    $constructors = \array_keys($state->data[$decl->name]['constructors'] ?? []);
    $canonical = surfaceConstructorAlias(
        $decl->name,
        $constructors,
        exportedChildrenSpec($exports, $decl->name),
    );
    if ($canonical !== null) {
        registerConstructorAlias($state, $decl->name, $canonical, $decl->name);
    }
}

function registerFunctionSchemesFromProgram(TypeCheckState $state, Ast\Program $program): void
{
    $seenFunctions = [];
    $inferredFunctions = [];

    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl || $item instanceof Ast\ForeignImportDecl) {
            $state->localDeclNames[$item->name] = true;
        }
    }

    foreach ($program->items as $item) {
        if ($item instanceof Ast\ForeignImportDecl) {
            if (isset($seenFunctions[$item->name])) {
                throw typeFail($state, \sprintf('duplicate function `%s`', $item->name), $item);
            }
            $seenFunctions[$item->name] = true;
            registerFunctionScheme($state, foreignFunctionDecl($item));

            continue;
        }

        if (! $item instanceof Ast\FunctionDecl) {
            continue;
        }

        if (isset($seenFunctions[$item->name])) {
            throw typeFail($state, \sprintf('duplicate function `%s`', $item->name), $item);
        }
        $seenFunctions[$item->name] = true;

        if (Ast\hasDeclaredSignature($item)) {
            registerFunctionScheme($state, $item);
        } elseif ($item->inferredSignatureType !== null) {
            registerInferredSignatureScheme($state, $item);
        } elseif (! $item->signatureOnly) {
            $inferredFunctions[] = $item;
        }
    }

    foreach ($inferredFunctions as $function) {
        registerInferredFunctionPlaceholder($state, $function);
    }
}

/**
 * Build project-instance records (including associated equations) from a single program.
 *
 * This is the record shape a module is its own instance world in — the
 * single-module boundary `applyImportContext` falls back to, and the shape the
 * project collector produces for the same declarations (a record for every
 * instance declaration, plus the ones data declarations and standalone deriving
 * declarations contribute).
 *
 * `$dataDecls` is what a standalone `deriving instance` needs to answer for a type the program
 * does not declare itself: the project collector passes the whole project, the single-module
 * route passes the program's own declarations.
 *
 * @param array<string, Ast\DataDecl> $dataDecls every data/newtype the caller can name
 * @return list<array{module: string, class: string, head: Ast\TypeNode, constraints: list<Ast\TypeNode>, intrinsicMethods: list<string>, associatedEquations: array<string, array{lhsArgs: list<Ast\TypeNode>, rhs: Ast\TypeNode}>}>
 */
function projectInstancesFromProgram(
    Ast\Program $program,
    ClassDeriveFacts $facts,
    ?string $moduleName = null,
    array $dataDecls = [],
): array
{
    $instances = [];
    $mod = $moduleName ?? $program->module ?? '';
    foreach ($program->items as $item) {
        if ($item instanceof Ast\InstanceDecl) {
            $instances[] = [
                'module' => $mod,
                'class' => $item->class,
                'instanceHead' => $item->head,
                'constraints' => $item->constraints,
                'intrinsicMethods' => instanceIntrinsicMethods($item),
                'associatedEquations' => associatedEquationsMapFromDecl($item),
            ];

            continue;
        }

        if ($item instanceof Ast\DataDecl && $item->derivingClasses !== []) {
            foreach (derivedProjectInstanceRecords($item, $mod, $facts) as $record) {
                $instances[] = $record;
            }

            continue;
        }

        if ($item instanceof Ast\StandaloneDerivingDecl) {
            foreach (standaloneDerivingProjectRecords($item, $mod, $facts, $dataDecls) as $record) {
                $instances[] = $record;
            }
        }
    }

    return $instances;
}

