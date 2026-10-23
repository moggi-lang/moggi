<?php declare(strict_types=1);

namespace Moggi\Semantics\Registry;

use Moggi\Semantics\Deriving\ClassDeriveFacts;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Deriving\derivedProjectInstanceRecords;
use function Moggi\Semantics\Foreign\foreignParamNames;
use function Moggi\Semantics\Types\associatedEquationsMapFromDecl;
use function Moggi\Semantics\Types\indexAssociatedEquations;
use function Moggi\Semantics\Types\installTypeSynonymKind;
use function Moggi\Semantics\Types\instanceHeadIndexKeyFromAst;
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

function applyImportContext(TypeCheckState $state, Ast\Program $program, array $importContext): void
{
    foreach ($importContext['env'] ?? [] as $name => $scheme) {
        $state->env[$name] = $scheme;
    }

    foreach ($importContext['constructorRenames'] ?? [] as $local => $canonical) {
        $state->constructorRenames[$local] = $canonical;
    }

    foreach ($importContext['typeSynonyms'] ?? [] as $name => $type) {
        $state->typeSynonyms[$name] = $type;
        installTypeSynonymKind($state, $name, $type);
    }

    foreach ($importContext['data'] ?? [] as $name => $info) {
        $state->data[$name] = $info;
        Kinds\registerTypeKind($state, $name, Kinds\kindFromDataInfo($info));
        Kinds\installPromotedFromDataInfo($state, $name, $info);
    }

    foreach ($importContext['classes'] ?? [] as $name => $info) {
        $state->classes[$name] = $info;
    }
    $state->classParamKindsCache = null;
    syncAssociatedFamiliesFromClasses($state);

    $state->qualifiedModules = $importContext['qualifiedModules'] ?? [];
    $state->qualifiedOrigins = $importContext['qualifiedOrigins'] ?? [];
    $state->qualifiedEnv = $importContext['qualifiedEnv'] ?? [];
    $state->intrinsicWrappers = $importContext['intrinsicWrappers'] ?? [];

    $state->ambiguousImports = $importContext['ambiguousNames'] ?? [];
    $state->classModuleScopes = $importContext['classScopes'] ?? [];
    $state->externalFns = $importContext['codegen']['externalFns'] ?? [];
    $instanceIndex = $importContext['projectInstanceIndex'] ?? null;
    if ($instanceIndex !== null) {
        $state->projectInstancesByClass = $instanceIndex['byClass'];
        $state->projectInstancesByClassHead = $instanceIndex['byClassHead'];
        $state->associatedEquations = $instanceIndex['associatedEquations'];
    } else {
        $instancesByClass = [];
        $instancesByClassHead = [];
        $addInstance = static function (array $instance) use (&$instancesByClass, &$instancesByClassHead): void {
            $instancesByClass[$instance['class']][] = $instance;
            $headKey = instanceHeadIndexKeyFromAst($instance['instanceHead']);
            $instancesByClassHead[$instance['class']][$headKey][] = $instance;
        };
        foreach ($importContext['projectInstances'] ?? [] as $instance) {
            $addInstance($instance);
        }
        $currentModule = $importContext['currentModule'] ?? '';
        foreach ($program->items as $item) {
            if (! $item instanceof Ast\InstanceDecl) {
                continue;
            }
            $addInstance([
                'module' => $currentModule,
                'class' => $item->class,
                'instanceHead' => $item->head,
                'constraints' => $item->constraints,
                'intrinsicMethods' => instanceIntrinsicMethods($item),
                'associatedEquations' => associatedEquationsMapFromDecl($item),
            ]);
        }
        $state->projectInstancesByClass = $instancesByClass;
        $state->projectInstancesByClassHead = $instancesByClassHead;
        $equationSource = $importContext['projectInstances'] ?? [];
        if ($equationSource === []) {
            $equationSource = projectInstancesFromProgram($program, ClassDeriveFacts::fromState($state));
        }
        indexAssociatedEquations($state, $equationSource);
    }
    $state->currentModule = $importContext['currentModule'] ?? null;
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
 * @return list<array{module: string, class: string, head: Ast\TypeNode, constraints: list<Ast\TypeNode>, associatedEquations: array<string, array{lhsArgs: list<Ast\TypeNode>, rhs: Ast\TypeNode}>}>
 */
function projectInstancesFromProgram(Ast\Program $program, ClassDeriveFacts $facts, string $moduleName = ''): array
{
    $instances = [];
    $mod = $moduleName !== '' ? $moduleName : ($program->module ?? '');
    foreach ($program->items as $item) {
        if ($item instanceof Ast\InstanceDecl) {
            $instances[] = [
                'module' => $mod,
                'class' => $item->class,
                'instanceHead' => $item->head,
                'constraints' => $item->constraints,
                'associatedEquations' => associatedEquationsMapFromDecl($item),
            ];

            continue;
        }

        if ($item instanceof Ast\DataDecl && $item->derivingClasses !== []) {
            foreach (derivedProjectInstanceRecords($item, $mod, $facts) as $record) {
                $instances[] = $record;
            }
        }
    }

    return $instances;
}
