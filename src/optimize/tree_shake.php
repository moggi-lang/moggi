<?php declare(strict_types=1);

namespace Moggi\Optimize\TreeShake;

use Moggi\IR;
use Moggi\IR\FunctionDecl;
use Moggi\IR\Module;

use function Moggi\IR\Projection\leafName;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Optimize\Effects\scanBody;

/**
 * Whole-program removal of Moggi functions nothing can reach.
 *
 * Roots: an entry and an export. A root keeps whatever it names — `call`, `io_call`, a function value, a
 * partial application — transitively, and that is also how a `foreign` binding survives: it is kept when a kept
 * declaration calls it, never merely for being declared. A name is resolved by identity (`module::name`): a
 * bare name resolves inside its own module first, and when it cannot be placed exactly it is kept in every
 * module declaring that leaf, because an ambiguous spelling is no evidence of absence.
 *
 * Dictionary dispatch is the one edge the IR does not spell as a call: a `dict_call` looks its method up in
 * an instance-evidence table, and the emitted table names the methods while no operand does. So an evidence
 * entry is kept when a reachable function mentions it (that is how a dictionary is passed: as a value), or
 * when its module still dispatches through one of the methods that entry declares — the method name is the
 * edge, not the mere presence of a dispatch, because a table that names no method its module dispatches
 * cannot be the one that call needs. A kept entry keeps every method the table names. An entry nothing can
 * reach is dropped with its methods, which is the whole point of shaking an instance-heavy module.
 *
 * Modules are rebuilt rather than mutated: a caller holding the optimized IR keeps what it had, and a module
 * nothing was removed from is returned as the same object.
 *
 * @param array<string, Module> $modulesByName moduleName => optimized IR module
 * @return array{modules: array<string, Module>, removed: int, kept: int, total: int}
 */
function treeShakeModules(array $modulesByName): array
{
    $total = 0;
    $hasEntry = false;
    foreach ($modulesByName as $module) {
        $total += \count($module->functions);
        if ($module->entry !== null) {
            $hasEntry = true;
        }
    }

    if (! $hasEntry) {
        return ['modules' => $modulesByName, 'removed' => 0, 'kept' => $total, 'total' => $total];
    }

    /** @var array<string, FunctionDecl> $byKey */
    $byKey = [];
    /** @var array<string, list<string>> $keysByLeaf */
    $keysByLeaf = [];
    foreach ($modulesByName as $moduleName => $module) {
        foreach ($module->functions as $function) {
            $key = $moduleName . '::' . $function->name;
            $byKey[$key] = $function;
            $keysByLeaf[leafName($function->name)][] = $key;
        }
    }

    /** @var array<string, true> $reachable */
    $reachable = [];
    /** @var list<string> $queue */
    $queue = [];
    $mark = static function (string $key) use (&$reachable, &$queue): void {
        if (! isset($reachable[$key])) {
            $reachable[$key] = true;
            $queue[] = $key;
        }
    };

    foreach ($modulesByName as $moduleName => $module) {
        foreach ($module->functions as $function) {
            if ($function->shape->entryKind !== null || $function->shape->export) {
                $mark($moduleName . '::' . $function->name);
            }
        }
    }

    if ($reachable === []) {
        return ['modules' => $modulesByName, 'removed' => 0, 'kept' => $total, 'total' => $total];
    }

    /** @var array<string, true> $mentioned names any reachable declaration refers to */
    $mentioned = [];
    /** @var array<string, array<string, true>> $dispatched module => methods its reachable code dispatches */
    $dispatched = [];
    /** @var array<string, array<int, true>> $keptEvidence module => evidence index */
    $keptEvidence = [];

    while (true) {
        while ($queue !== []) {
            $key = \array_pop($queue);
            $function = $byKey[$key] ?? null;
            if ($function === null) {
                continue;
            }

            $moduleName = \substr($key, 0, (int) \strpos($key, '::'));
            $body = scanBody($function->body);
            foreach (\array_keys($body['dictMethods']) as $method) {
                $dispatched[$moduleName][$method] = true;
            }

            foreach (\array_keys($body['mentions']) as $name) {
                $mentioned[$name] = true;
                $mentioned[leafName($name)] = true;

                $parsed = parseResolvedSymbol($name);
                if ($parsed !== null) {
                    $mark($parsed['module'] . '::' . $parsed['name']);

                    continue;
                }

                $localKey = $moduleName . '::' . $name;
                if (isset($byKey[$localKey])) {
                    $mark($localKey);

                    continue;
                }

                foreach ($keysByLeaf[leafName($name)] ?? [] as $ambiguousKey) {
                    $mark($ambiguousKey);
                }
            }
        }

        $marked = false;
        foreach ($modulesByName as $moduleName => $module) {
            foreach ($module->instanceEvidence as $index => $evidence) {
                if (isset($keptEvidence[$moduleName][$index])) {
                    continue;
                }
                if (! isset($mentioned[$evidence->evidenceName]) && ! dispatchesOneOf($dispatched[$moduleName] ?? [], $evidence)) {
                    continue;
                }

                $keptEvidence[$moduleName][$index] = true;
                $marked = true;
                foreach ($evidence->methods as $irName) {
                    $mark($moduleName . '::' . $irName);
                }
            }
        }

        if (! $marked) {
            break;
        }
    }

    $modules = [];
    $removed = 0;
    $kept = 0;
    foreach ($modulesByName as $moduleName => $module) {
        $functions = [];
        foreach ($module->functions as $function) {
            if (isset($reachable[$moduleName . '::' . $function->name])) {
                $functions[] = $function;
                ++$kept;

                continue;
            }
            ++$removed;
        }

        $evidence = [];
        foreach ($module->instanceEvidence as $index => $entry) {
            if (isset($keptEvidence[$moduleName][$index])) {
                $evidence[] = $entry;
            }
        }

        $modules[$moduleName] = \count($functions) === \count($module->functions)
            && \count($evidence) === \count($module->instanceEvidence)
            ? $module
            : new Module(
                $functions,
                $module->data,
                $evidence,
                $module->entry,
                $module->moduleName,
                $module->sourceFile,
            );
    }

    return ['modules' => $modules, 'removed' => $removed, 'kept' => $kept, 'total' => $total];
}

/** Whether a reachable function of the module dispatches a method this evidence table declares. */
function dispatchesOneOf(array $methods, IR\InstanceEvidence $evidence): bool
{
    foreach (\array_keys($evidence->methods) as $declared) {
        if (isset($methods[$declared])) {
            return true;
        }
    }

    return false;
}
