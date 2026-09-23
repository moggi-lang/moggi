<?php declare(strict_types=1);

namespace Moggi\Optimize\TreeShake;

use Moggi\IR\FunctionDecl;
use Moggi\IR\Module;

use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\Modules\isQualifiedSymbol;
use function Moggi\Modules\parseResolvedSymbol;

/**
 * Whole-program dead-binding elimination for Moggi-defined functions.
 *
 * Reachability keys are `module::shortName` so instance methods that share a
 * surface name (e.g. `show` for Int vs List) are not kept as a single bucket.
 *
 * @param array<string, Module> $modulesByName  moduleName => optimized IR module
 * @return array{modules: array<string, Module>, removed: int, kept: int, total: int}
 */
function treeShakeModules(array $modulesByName): array
{
    $entryModule = null;
    foreach ($modulesByName as $name => $module) {
        if ($module->entry !== null) {
            $entryModule = $name;
            break;
        }
    }

    $total = 0;
    foreach ($modulesByName as $module) {
        $total += count($module->functions);
    }

    if ($entryModule === null) {
        return ['modules' => $modulesByName, 'removed' => 0, 'kept' => $total, 'total' => $total];
    }

    /** @var array<string, FunctionDecl> $functionsByKey module::short => fn */
    $functionsByKey = [];
    /** @var array<string, list<string>> $keysByShort short => list of module::short */
    $keysByShort = [];
    /** @var array<string, array{module: string, methods: list<string>}> $evidenceByShort */
    $evidenceByShort = [];
    $rootKeys = [];

    foreach ($modulesByName as $moduleName => $module) {
        foreach ($module->functions as $fn) {
            $short = shortName($fn->name);
            $key = $moduleName . '::' . $short;
            $functionsByKey[$key] = $fn;
            $keysByShort[$short][] = $key;
            // Main and ReplExpression are both live roots.
            if ($fn->entryKind !== null) {
                $rootKeys[$key] = true;
            }
        }

        foreach ($module->instanceEvidence as $evidence) {
            $methods = [];
            foreach ($evidence->methods as $irName) {
                $methods[] = shortName($irName);
            }
            $evidenceByShort[shortName($evidence->evidenceName)] = [
                'module' => $moduleName,
                'methods' => $methods,
            ];
        }
    }

    $reachable = $rootKeys;
    $queue = \array_keys($rootKeys);
    $head = 0;
    while ($head < count($queue)) {
        $key = $queue[$head++];
        [$moduleName, $short] = explode('::', $key, 2);

        $ev = $evidenceByShort[$short] ?? null;
        if ($ev !== null) {
            foreach ($ev['methods'] as $methodShort) {
                $methodKey = $ev['module'] . '::' . $methodShort;
                if (!isset($reachable[$methodKey]) && isset($functionsByKey[$methodKey])) {
                    $reachable[$methodKey] = true;
                    $queue[] = $methodKey;
                }
            }
            // Evidence constructor itself is not a FunctionDecl; mark for keep.
            $reachable[$ev['module'] . '::' . $short] = true;
        }

        $fn = $functionsByKey[$key] ?? null;
        if ($fn === null) {
            continue;
        }

        foreach (functionCalleeNames($fn) as $callee) {
            $parsed = parseResolvedSymbol($callee);
            if ($parsed !== null) {
                $calleeKey = $parsed['module'] . '::' . $parsed['name'];
                if (!isset($reachable[$calleeKey])) {
                    $reachable[$calleeKey] = true;
                    $queue[] = $calleeKey;
                }
                continue;
            }

            $localKey = $moduleName . '::' . $callee;
            if (isset($functionsByKey[$localKey])) {
                if (!isset($reachable[$localKey])) {
                    $reachable[$localKey] = true;
                    $queue[] = $localKey;
                }
                continue;
            }

            // Cross-module call: keep every definition of that short name.
            // (Ambiguous only when multiple modules export the same binder;
            // import resolution already picked one at typecheck time.)
            foreach ($keysByShort[$callee] ?? [] as $calleeKey) {
                if (!isset($reachable[$calleeKey])) {
                    $reachable[$calleeKey] = true;
                    $queue[] = $calleeKey;
                }
            }

            // Evidence zero-arg refs (e.g. @__ev_Show_Int) are not functions.
            if (isset($evidenceByShort[$callee])) {
                $evKey = $evidenceByShort[$callee]['module'] . '::' . $callee;
                if (!isset($reachable[$evKey])) {
                    $reachable[$evKey] = true;
                    $queue[] = $evKey;
                }
            }
        }
    }

    $removed = 0;
    $kept = 0;
    foreach ($modulesByName as $name => $module) {
        $filtered = [];
        foreach ($module->functions as $fn) {
            $key = $name . '::' . shortName($fn->name);
            if (isset($reachable[$key])) {
                $filtered[] = $fn;
                ++$kept;
            } else {
                ++$removed;
            }
        }
        $module->functions = $filtered;
        $module->instanceEvidence = array_values(array_filter(
            $module->instanceEvidence,
            static function ($ev) use ($reachable, $name): bool {
                $evKey = $name . '::' . shortName($ev->evidenceName);

                return isset($reachable[$evKey]);
            },
        ));
        $modulesByName[$name] = $module;
    }

    return ['modules' => $modulesByName, 'removed' => $removed, 'kept' => $kept, 'total' => $total];
}

function shortName(string $name): string
{
    $parsed = parseResolvedSymbol($name);
    if ($parsed !== null) {
        return $parsed['name'];
    }

    // A bare operator can contain a backslash (`\\`); only a really qualified
    // name has a member to strip, or the key would come out empty.
    if (!isQualifiedSymbol($name)) {
        return $name;
    }

    $pos = strrpos($name, '\\');

    return $pos === false ? $name : substr($name, $pos + 1);
}

/** @return list<string> */
function functionCalleeNames(FunctionDecl $fn): array
{
    $usage = collectIrCodegenUsage(new Module([$fn], []));

    return \array_keys($usage['callees']);
}

/** @return list<string> @deprecated use functionCalleeNames */
function functionCalleeShortNames(FunctionDecl $fn): array
{
    $names = [];
    foreach (functionCalleeNames($fn) as $callee) {
        $names[shortName($callee)] = true;
    }

    return \array_keys($names);
}
