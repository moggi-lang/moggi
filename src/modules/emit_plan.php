<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\IR;

use function Moggi\Backend\backendById;
use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\Paths\relativeRequirePath;
use function Moggi\Syntax\isConstructorName;

/**
 * What one target needs to emit one module (A8 / D08).
 *
 * The interface says what a module *means*; this says where that meaning is
 * written for the target being compiled. It holds nothing of its own: every ABI
 * fact below is copied from the module's {@see ModuleInterface}, and every path
 * and alias is the target's own spelling of a module the interface named. That
 * is why it lives beside the interface rather than inside it — an importer's
 * require path is PHP's business, not a fact about the module — and why it is
 * rendered, never stored on a checked program.
 *
 * Two stages, both in this file:
 *
 *  - `forTarget()` is the layout the import walk gathers: which module is
 *    written where, which namespace each module's PHP lives in, and the aliases
 *    its imports gave those namespaces. It needs no IR.
 *  - `planEmission()` renders the plan an emitter consumes from that layout and
 *    the interface, pruned to what the module's IR actually calls. A target that
 *    emits before its IR exists (the REPL's non-PHP option builder) passes no IR
 *    and gets the whole interface published instead: planning less must never
 *    take a fact away.
 *
 * Emitters read this one value. Host foreign verification does not: it runs
 * while the declarations are checked, before any target planning happens, so a
 * plan rendered later cannot lose its diagnostics.
 */
final class EmitPlan
{
    /**
     * @param string $outputRelative       this module's own artifact, relative to the output root
     * @param array<string, string> $moduleOutputPaths module => its artifact, output-relative
     * @param array<string, string> $moduleAsNames     namespace => the alias this module imported it as
     * @param array<string, string> $namespaceModules  namespace => the module that defines it
     * @param list<string> $requireLines        `require_once` targets, relative to the output file
     * @param list<string> $functionUseLines    `use function ...` lines
     * @param list<string> $namespaceUseLines   `use Namespace as Alias` lines
     * @param array<string, string> $externalFns        called name => its resolved symbol
     * @param array<string, int> $externalFnRuntimeArity called name => runtime arity
     * @param array<string, string> $constructorRenames local constructor name => canonical name
     * @param array<string, array<string, mixed>> $importedData the imported data declarations the emitters install
     */
    public function __construct(
        public readonly string $backendId,
        public readonly string $outputRelative,
        public readonly array $moduleOutputPaths = [],
        public readonly array $moduleAsNames = [],
        public readonly array $namespaceModules = [],
        public readonly array $requireLines = [],
        public readonly array $functionUseLines = [],
        public readonly array $namespaceUseLines = [],
        public readonly array $externalFns = [],
        public readonly array $externalFnRuntimeArity = [],
        public readonly array $constructorRenames = [],
        public readonly array $importedData = [],
    ) {
    }

    /**
     * The target layout, as the import walk gathers it.
     *
     * `$moduleOutputPaths` is only filled for a target that links files (PHP);
     * the others name their artifacts by module, not by path.
     *
     * @param array<string, string> $moduleOutputPaths
     * @param array<string, string> $moduleAsNames
     * @param array<string, string> $namespaceModules
     */
    public static function forTarget(
        string $backendId,
        string $outputRelative,
        array $moduleOutputPaths = [],
        array $moduleAsNames = [],
        array $namespaceModules = [],
    ): self {
        return new self(
            backendId: $backendId,
            outputRelative: $outputRelative,
            moduleOutputPaths: $moduleOutputPaths,
            moduleAsNames: $moduleAsNames,
            namespaceModules: $namespaceModules,
        );
    }

    /**
     * Copy with more module output paths, the given spellings winning.
     *
     * A plan is laid out before a project knows every module it will write (the
     * class-scope pass publishes origins the import walk never saw), and a
     * single-file compile may be asked to emit against a prebuilt stdlib tree.
     * Both are path spellings of the same modules, so the caller supplies the
     * complete map and the plan carries it from there.
     *
     * @param array<string, string> $paths
     */
    public function withModuleOutputPaths(array $paths): self
    {
        return new self(
            backendId: $this->backendId,
            outputRelative: $this->outputRelative,
            moduleOutputPaths: [...$this->moduleOutputPaths, ...$paths],
            moduleAsNames: $this->moduleAsNames,
            namespaceModules: $this->namespaceModules,
            requireLines: $this->requireLines,
            functionUseLines: $this->functionUseLines,
            namespaceUseLines: $this->namespaceUseLines,
            externalFns: $this->externalFns,
            externalFnRuntimeArity: $this->externalFnRuntimeArity,
            constructorRenames: $this->constructorRenames,
            importedData: $this->importedData,
        );
    }
}

/**
 * Every unit's artifact path for a target that links files, keyed by module.
 *
 * The import walk lays a plan out over the modules its source names, but
 * class-scope references and a prebuilt library tree name modules it never
 * walked. Deriving the complete map from the units once covers both, in the
 * one spelling the emitter writes and the requires count from.
 *
 * @param array<string, array<string, mixed>> $units
 * @return array<string, string>
 */
function moduleOutputPathsForUnits(array $units, string $rootPrefix, string $backendId): array
{
    $paths = [];
    $extension = backendById($backendId)->extension();
    foreach ($units as $moduleName => $unit) {
        if (($unit['synthetic'] ?? false) === true || ! isset($unit['path'])) {
            continue;
        }

        $paths[$moduleName] = mogPathToOutputRelative($unit['path'], $rootPrefix, $extension);
    }

    return $paths;
}

/**
 * Render the emission plan from a module's interface.
 *
 * Without `$ir` the module's usage is unknown (the REPL builds its emit options
 * before the pipeline runs), so every ABI fact is published whole and every
 * external gets its use line; nothing is pruned and no file is planned, because
 * which module is written where is the emitter's to decide once it has the IR.
 * With `$ir`, the externals and files are the ones the module actually calls —
 * a listed module that is never reached must not be required.
 *
 * @param list<string>|null $usedCallees the callees the caller already collected from `$ir`
 */
function planEmission(
    ModuleInterface $interface,
    EmitPlan $target,
    ?IR\Module $ir,
    ?array $usedCallees = null,
    ?string $fromOutputRelative = null,
    ?string $absoluteRequireRoot = null,
): EmitPlan {
    $render = static fn (
        array $requireLines,
        array $functionUseLines,
        array $namespaceUseLines,
        array $externalFns,
        array $externalFnRuntimeArity,
        array $moduleAsNames,
    ): EmitPlan => new EmitPlan(
        backendId: $target->backendId,
        outputRelative: $target->outputRelative,
        moduleOutputPaths: $target->moduleOutputPaths,
        moduleAsNames: $moduleAsNames,
        namespaceModules: $target->namespaceModules,
        requireLines: $requireLines,
        functionUseLines: $functionUseLines,
        namespaceUseLines: $namespaceUseLines,
        externalFns: $externalFns,
        externalFnRuntimeArity: $externalFnRuntimeArity,
        constructorRenames: $interface->constructorRenames,
        importedData: $interface->data,
    );

    if ($ir === null) {
        $functionUseLines = [];
        foreach ($interface->externalFns as $local => $resolved) {
            if (isConstructorName($local)) {
                continue;
            }

            $functionUseLines[] = functionUseLine($local, $resolved);
        }

        return $render(
            [],
            dedupeUseLines($functionUseLines),
            [],
            $interface->externalFns,
            $interface->externalFnRuntimeArity,
            $target->moduleAsNames,
        );
    }

    $usedCallees ??= collectIrCodegenUsage($ir)['callees'];

    $localFnNames = [];
    foreach ($ir->functions as $fn) {
        $localFnNames[$fn->name] = true;
    }

    $usedExternal = [];
    $usedModules = [];
    $usedNamespaces = [];
    foreach (\array_keys($usedCallees) as $callee) {
        if (isset($interface->externalFns[$callee])) {
            $usedExternal[$callee] = true;
            $originModule = $interface->fnOriginModules[$callee] ?? null;
            if ($originModule !== null) {
                $usedModules[$originModule] = true;
            }

            continue;
        }

        $parsed = parseResolvedSymbol($callee);
        if ($parsed !== null) {
            $usedModules[$parsed['module']] = true;
            $usedNamespaces[moduleNameToNamespace($parsed['module'])] = true;
            if (isset($interface->externalFns[$parsed['name']])) {
                $usedExternal[$parsed['name']] = true;
            }

            continue;
        }

        if (! \str_contains($callee, '\\')) {
            continue;
        }

        $separator = \strrpos($callee, '\\');
        $namespace = \substr($callee, 0, $separator);
        $member = \substr($callee, $separator + 1);
        $usedNamespaces[$namespace] = true;
        $originModule = $target->namespaceModules[$namespace] ?? null;
        if ($originModule !== null) {
            $usedModules[$originModule] = true;
        }

        if (isset($interface->externalFns[$member])) {
            $usedExternal[$member] = true;
        }
    }

    $externalFns = \array_intersect_key($interface->externalFns, $usedExternal);
    $externalFnRuntimeArity = \array_intersect_key($interface->externalFnRuntimeArity, $externalFns);

    $moduleAsNames = [];
    $namespaceUseLines = [];
    foreach ($target->moduleAsNames as $namespace => $asName) {
        if (! isset($usedNamespaces[$namespace])) {
            continue;
        }

        $moduleAsNames[$namespace] = $asName;
        $namespaceUseLines[] = "{$namespace} as {$asName}";
    }

    $fromOutput = $fromOutputRelative ?? $target->outputRelative;
    $absoluteRoot = $absoluteRequireRoot !== null
        ? \rtrim(\str_replace('\\', '/', $absoluteRequireRoot), '/')
        : null;
    $requireByModule = [];
    foreach (\array_keys($usedModules) as $moduleName) {
        $moduleOutput = $target->moduleOutputPaths[$moduleName] ?? null;
        if ($moduleOutput === null) {
            continue;
        }

        $requireByModule[$moduleName] = $absoluteRoot !== null
            ? \var_export($absoluteRoot . '/' . \str_replace('\\', '/', $moduleOutput), true)
            : relativeRequirePath($fromOutput, $moduleOutput);
    }
    \ksort($requireByModule, SORT_STRING);
    $requireLines = \array_values(\array_unique($requireByModule));

    $functionUseLines = [];
    foreach ($externalFns as $local => $resolved) {
        if (isConstructorName($local) || isset($localFnNames[$local])) {
            continue;
        }

        $functionUseLines[] = functionUseLine($local, $resolved);
    }

    return $render(
        $requireLines,
        dedupeUseLines($functionUseLines),
        dedupeUseLines($namespaceUseLines),
        $externalFns,
        $externalFnRuntimeArity,
        $moduleAsNames,
    );
}
