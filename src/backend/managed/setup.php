<?php declare(strict_types=1);

namespace Moggi\Backend\Managed;

use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;
use Moggi\IR\Callables\CallableTable;
use Moggi\Modules\EmitPlan;

use function Moggi\Backend\Meta\constructorArityMap;
use function Moggi\Backend\Meta\constructorArityMapFromRegistry;
use function Moggi\Backend\Meta\newtypeConstructorMap;
use function Moggi\Backend\Meta\newtypeConstructorMapFromRegistry;
use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\IR\Callables\arityBySymbol;
use function Moggi\IR\Callables\captureMeta;
use function Moggi\IR\Callables\table;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Optimize\Support\indexCapturedFunctions;

/**
 * The module-wide facts a managed emitter builds before it emits anything.
 *
 * JVM and .NET carried this same block — capture metadata, the callable table, the arity map from
 * local functions/constructors/imported data/external arities/program arities/instance evidence,
 * the local-callable set, the newtype constructors — as two near-copies, and every new fact had to
 * be added twice or the two targets would answer differently about the same module. This is the
 * one producer; the two emitters differ below it, in class builders and instruction encoding, which
 * is where they must differ.
 *
 * The returned tuple is the caller's local names, in this order: the display source path, the
 * artifact path, the source map, capture metadata, the arity map, the external-function map, the
 * local callable set and the newtype constructors.
 *
 * @param array<string, mixed> $options
 * @return array{
 *     string,
 *     string,
 *     SourceMapBuilder,
 *     array<string, array{captures: list<string>, params: list<string>}>,
 *     array<string, int>,
 *     array<string, string>,
 *     array<string, true>,
 *     array<string, true>
 * }
 */
function managedModuleFacts(
    IR\Module $module,
    string $sourcePath,
    array $options,
    string $target,
    string $defaultArtifact,
): array {
    $moduleName = $options['artifactModuleName'] ?? '';
    $mapOwner = $moduleName !== '' ? $moduleName : 'Main';
    $displaySource = normalizeDisplayPath($module->sourceFile !== '' ? $module->sourceFile : $sourcePath);
    $artifact = (string) ($options['outputRelative'] ?? $defaultArtifact);
    $sourceMap = new SourceMapBuilder($mapOwner, $target, $artifact);
    $lambdaMeta = captureMeta(indexCapturedFunctions($module->functions));

    $callableTables = $options['callableTables'] ?? [];
    $callableTable = ($callableTables[$moduleName] ?? null) instanceof CallableTable
        ? $callableTables[$moduleName]
        : table($module->functions, (string) $moduleName);
    $programArity = arityBySymbol($callableTables);

    $plan = $options['plan'] ?? null;
    if (! $plan instanceof EmitPlan) {
        throw new \RuntimeException(
            "emitting `{$moduleName}` needs its EmitPlan",
        );
    }

    $functionArity = [];
    /** @var array<string, true> $localBindings */
    $localBindings = [];
    foreach ($module->functions as $fn) {
        $localBindings[$fn->name] = true;
        $functionArity[$fn->name] = $callableTable->sig($fn->name)?->runtimeArity()
            ?? \count($fn->params);
    }
    foreach (constructorArityMap($module->data) as $name => $arity) {
        $localBindings[$name] = true;
        $functionArity[$name] = $arity;
    }
    foreach (constructorArityMapFromRegistry($plan->importedData) as $name => $arity) {
        if (! isset($localBindings[$name])) {
            $functionArity[$name] = $arity;
        }
    }
    foreach ($plan->externalFnRuntimeArity as $name => $arity) {
        if (! isset($localBindings[$name])) {
            $functionArity[$name] = $arity;
        }
        $resolved = $plan->externalFns[$name] ?? null;
        if (\is_string($resolved)) {
            $parsed = parseResolvedSymbol($resolved);
            if ($parsed !== null) {
                $functionArity[resolvedSymbol($parsed['module'], $parsed['name'])] = $arity;
            }
        }
    }
    foreach ($programArity as $name => $arity) {
        if (! isset($functionArity[$name])) {
            $functionArity[$name] = $arity;
        }
    }
    // An evidence entry's arity is the dictionaries it closes over, and the table publishes it as
    // evidence + captures + values; the declaration wins because that is the number the call
    // passes. This order is the JVM's, which the .NET emitter did not share — unifying it here
    // removes a divergence rather than introducing one.
    foreach ($module->instanceEvidence as $ev) {
        $functionArity[$ev->evidenceName] = \count($ev->contextParams);
    }
    if ($moduleName !== '') {
        foreach ($functionArity as $name => $arity) {
            if (! \is_string($name) || \str_contains($name, '::')) {
                continue;
            }
            $functionArity[resolvedSymbol($moduleName, $name)] = $arity;
        }
    }

    $externalFns = $plan->externalFns;

    /** @var array<string, true> $localCallables */
    $localCallables = [];
    foreach ($module->functions as $fn) {
        $localCallables[$fn->name] = true;
    }
    foreach ($module->instanceEvidence as $ev) {
        $localCallables[$ev->evidenceName] = true;
        foreach ($ev->methods as $irName) {
            $localCallables[$irName] = true;
        }
    }

    $newtypeConstructors = [
        ...newtypeConstructorMapFromRegistry($plan->importedData),
        ...newtypeConstructorMap($module->data),
    ];

    return [
        $displaySource,
        $artifact,
        $sourceMap,
        $lambdaMeta,
        $functionArity,
        $externalFns,
        $localCallables,
        $newtypeConstructors,
    ];
}
