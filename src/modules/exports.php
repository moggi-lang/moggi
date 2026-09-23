<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;

use function Moggi\Errors\appendDidYouMean;
use function Moggi\Semantics\Types\ambiguousImportMessage;
use function Moggi\Syntax\isConstructorName;

function moduleNameToNamespace(string $moduleName): string
{
    $parts = explode('.', $moduleName);

    return join('\\', $parts);
}

/** Backend-neutral cross-module symbol: `Data.String::unpack`. */
function resolvedSymbol(string $moduleName, string $name): string
{
    return $moduleName . '::' . $name;
}

/** @return ?array{module: string, name: string} */
function parseResolvedSymbol(string $resolved): ?array
{
    // First `::` only: operator constructors can start with `:` (e.g. `:*:`), so
    // `Module:::*:` would make strrpos pick the wrong split.
    $pos = strpos($resolved, '::');
    if ($pos === false) {
        return null;
    }

    return [
        'module' => substr($resolved, 0, $pos),
        'name' => substr($resolved, $pos + 2),
    ];
}

/**
 * Is this a `Module::name` symbol, or an already-qualified `Module\name` path?
 *
 * A bare operator can contain a backslash of its own (`Data.List`'s list
 * difference, `\\`), so "contains a backslash" is not enough: what precedes the
 * last backslash has to look like a module path, or `(\\)` gets split in half.
 */
function isQualifiedSymbol(string $name): bool
{
    if (str_contains($name, '::')) {
        return true;
    }
    if (!str_contains($name, '\\')) {
        return false;
    }

    $prefix = substr($name, 0, (int) strrpos($name, '\\'));

    return $prefix !== ''
        && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*$/', $prefix) === 1;
}

/** PHP FQN for a resolved symbol (emit / `use function` only). */
function phpFqnFromResolved(string $resolved): string
{
    $parsed = parseResolvedSymbol($resolved);
    if ($parsed === null) {
        return $resolved;
    }

    return moduleNameToNamespace($parsed['module']) . '\\' . $parsed['name'];
}

/**
 * Per-module export memo, live only for the duration of a prepare pass.
 *
 * Exports depend on the whole unit table, so the cache is explicitly enabled
 * while a project is being prepared and invalidated per module as units are
 * re-checked; outside that window it stays off rather than risk a stale answer.
 */
final class ExportsCache
{
    /** @var ?array<string, array<string, mixed>> */
    private static ?array $entries = null;

    public static function enable(): void
    {
        self::$entries = [];
    }

    public static function disable(): void
    {
        self::$entries = null;
    }

    /** @return ?array<string, mixed> */
    public static function get(string $moduleName): ?array
    {
        return self::$entries[$moduleName] ?? null;
    }

    /** @param array<string, mixed> $exports */
    public static function remember(string $moduleName, array $exports): void
    {
        if (self::$entries !== null) {
            self::$entries[$moduleName] = $exports;
        }
    }
}

function enableCollectExportsCache(): void
{
    ExportsCache::enable();
}

function disableCollectExportsCache(): void
{
    ExportsCache::disable();
}

/**
 * @param array{env: array<string, mixed>, data: array<string, mixed>, typeSynonyms: array<string, mixed>} $localTypes
 * @param array<string, array<string, mixed>> $units
 * @return array{
 *   env: array<string, Scheme>,
 *   typeSynonyms: array<string, array<string, mixed>>,
 *   data: array<string, array<string, mixed>>,
 *   origins: array<string, array{module: string, namespace: string, phpName: string}>,
 *   qualifiedModules: array<string, string>,
 *   moduleAsNames: array<string, string>
 * }
 */
function collectExports(Ast\Program $program, array $localTypes, array $units, string $moduleName): array
{
    if (isSyntheticCompilerModuleName($moduleName)) {
        if (isset($units[$moduleName]['exports']) && \is_array($units[$moduleName]['exports'])) {
            return $units[$moduleName]['exports'];
        }

        return syntheticExports($moduleName, $localTypes);
    }

    $cached = ExportsCache::get($moduleName);
    if ($cached !== null) {
        return $cached;
    }

    $exports = collectExportsUncached($program, $localTypes, $units, $moduleName);
    ExportsCache::remember($moduleName, $exports);

    return $exports;
}

/**
 * A `foreign type` declaration the backend provides.
 *
 * @param array<string, mixed> $available
 * @param array<string, mixed> $localTypes
 */
function exposeForeignType(array &$available, array $localTypes, string $name): void
{
    if (isset($localTypes['data'][$name])) {
        $available['data'][$name] = $localTypes['data'][$name];
    }
}

/**
 * A declared value: a `foreign import`, or a function (which also keeps its intrinsic wrapper).
 *
 * @param array<string, mixed> $available
 * @param array<string, mixed> $localTypes
 */
function exposeDeclaredValue(
    array &$available,
    array $localTypes,
    string $moduleName,
    string $namespace,
    string $name,
    bool $keepIntrinsicWrapper = false,
): void {
    $scheme = $localTypes['env'][$name] ?? null;
    if ($scheme === null) {
        return;
    }

    $available['env'][$name] = $scheme;
    $available['origins'][$name] = exportOrigin($moduleName, $namespace, $name);
    if ($keepIntrinsicWrapper && isset($localTypes['intrinsicWrappers'][$name])) {
        $available['intrinsicWrappers'][$name] = $localTypes['intrinsicWrappers'][$name];
    }
}

/**
 * The methods of a class declaration, whose schemes carry `classMethod`.
 *
 * @param array<string, mixed> $available
 * @param array<string, mixed> $localTypes
 */
function exposeClassMethods(array &$available, array $localTypes, Ast\ClassDecl $decl): void
{
    foreach ($decl->methods as $method) {
        $scheme = $localTypes['env'][$method->name] ?? null;
        if ($scheme === null || !$scheme->classMethod) {
            continue;
        }

        $available['env'][$method->name] = $scheme;
    }
}

/**
 * A type synonym, exported whole (it has no constructors).
 *
 * @param array<string, mixed> $available
 * @param array<string, mixed> $localTypes
 */
function exposeTypeSynonym(array &$available, array $localTypes, string $name): void
{
    $available['typeSynonyms'][$name] = $localTypes['typeSynonyms'][$name];
}

/**
 * A data declaration together with every constructor it brings into scope.
 *
 * @param array<string, mixed> $available
 * @param array<string, mixed> $localTypes
 */
function exposeDataType(
    array &$available,
    array $localTypes,
    string $moduleName,
    string $namespace,
    Ast\DataDecl $decl,
): void {
    $name = $decl->name;
    $available['data'][$name] = $localTypes['data'][$name];

    $ctorNames = isset($localTypes['data'][$name]['constructors'])
        ? \array_keys($localTypes['data'][$name]['constructors'])
        : allConstructors($decl);
    foreach ($ctorNames as $ctorName) {
        if (isset($localTypes['env'][$ctorName])) {
            $available['env'][$ctorName] = $localTypes['env'][$ctorName];
            $available['origins'][$ctorName] = exportOrigin($moduleName, $namespace, $ctorName);
        }
    }
}

/**
 * @param array{env: array<string, mixed>, data: array<string, mixed>, typeSynonyms: array<string, mixed>} $localTypes
 * @param array<string, array<string, mixed>> $units
 * @return array{
 *   env: array<string, Scheme>,
 *   typeSynonyms: array<string, array<string, mixed>>,
 *   data: array<string, array<string, mixed>>,
 *   origins: array<string, array{module: string, namespace: string, phpName: string}>,
 *   qualifiedModules: array<string, string>,
 *   moduleAsNames: array<string, string>
 * }
 */
function collectExportsUncached(Ast\Program $program, array $localTypes, array $units, string $moduleName): array
{
    $exports = [
        'env' => [],
        'typeSynonyms' => [],
        'data' => [],
        'origins' => [],
        'intrinsicWrappers' => [],
        'constructorRenames' => [],
        'ambientTypes' => [],
        'classes' => [],
        'qualifiedModules' => [],
        'moduleAsNames' => [],
        'ambiguous' => [],
    ];

    $namespace = moduleNameToNamespace($moduleName);
    $available = [
        'env' => [],
        'typeSynonyms' => [],
        'data' => [],
        'origins' => [],
        'intrinsicWrappers' => [],
        'qualifiedModules' => [],
        'moduleAsNames' => [],
        'ambiguous' => [],
    ];

    foreach ($program->items as $item) {
        match ($item::class) {
            Ast\ForeignTypeDecl::class => exposeForeignType($available, $localTypes, $item->name),
            Ast\ForeignImportDecl::class => exposeDeclaredValue(
                $available,
                $localTypes,
                $moduleName,
                $namespace,
                $item->name,
            ),
            Ast\FunctionDecl::class => exposeDeclaredValue(
                $available,
                $localTypes,
                $moduleName,
                $namespace,
                $item->name,
                keepIntrinsicWrapper: true,
            ),
            Ast\ClassDecl::class => exposeClassMethods($available, $localTypes, $item),
            Ast\TypeSynonymDecl::class => exposeTypeSynonym($available, $localTypes, $item->name),
            Ast\DataDecl::class => exposeDataType($available, $localTypes, $moduleName, $namespace, $item),
            default => null,
        };
    }

    // An instance method may provide an export the module does not declare, but never
    // over one it does (`Data.Map.toList` vs `Foldable (Map k)`).
    $declared = $available['env'];
    foreach ($program->items as $item) {
        if (!$item instanceof Ast\InstanceDecl) {
            continue;
        }

        foreach ($item->methods as $method) {
            $name = $method->name;
            $scheme = $localTypes['env'][$name] ?? null;
            if ($scheme === null || isset($declared[$name])) {
                continue;
            }
            // Prefer class-method schemes so `Ord(..)` / `Eq(..)` exports stay stable
            // when this module also defines instances of that class.
            if (($available['env'][$name] ?? null)?->classMethod) {
                continue;
            }

            $available['env'][$name] = $scheme;
            $available['origins'][$name] = exportOrigin($moduleName, $namespace, $name);
        }
    }

    redirectFacadeOrigins($available, $program, $units, $moduleName);

    $exportItems = $program->exports;
    if ($exportItems === null) {
        exportAllLocalDeclarations($exports, $program, $available, $moduleName, $units);

        return $exports;
    }

    // Omitted header exports only `main`; if it is undefined, export nothing.
    if ($program->implicitMain
        && count($exportItems) === 1
        && ($exportItems[0]['tag'] ?? '') === 'value'
        && ($exportItems[0]['name'] ?? null) === 'main'
        && !isset($available['env']['main'])
    ) {
        return $exports;
    }

    $importsMerged = false;
    foreach ($exportItems as $item) {
        if (($item['tag'] ?? '') === 'module') {
            addExportedModule($exports, $program, $item, $available, $units, $moduleName);
            continue;
        }

        try {
            if ($item['tag'] === 'value') {
                addExportedValue($exports, $available, $item['name'], $moduleName, $item, $units);
                continue;
            }

            addExportedType($exports, $available, $program, $item, $moduleName, $units);
        } catch (TypeError $e) {
            if ($importsMerged) {
                throw $e;
            }

            mergeImportExportsIntoAvailable($available, $program->imports, $units, $moduleName);
            $importsMerged = true;

            if ($item['tag'] === 'value') {
                addExportedValue($exports, $available, $item['name'], $moduleName, $item, $units);
                continue;
            }

            addExportedType($exports, $available, $program, $item, $moduleName, $units);
        }
    }

    return $exports;
}

/**
 * `module M` export: re-export everything selected by imports of M
 * (or all locals when M is the current module).
 *
 * @param array<string, mixed> $exports
 * @param array{tag: string, name: string, path?: list<string>, children?: array{mode: string, names: list<string>}, section?: string} $item
 * @param array<string, mixed> $available
 * @param array<string, array<string, mixed>> $units
 */
function addExportedModule(
    array &$exports,
    Ast\Program $program,
    array $item,
    array $available,
    array $units,
    string $moduleName,
): void {
    $target = $item['name'];

    if ($target === $moduleName) {
        exportAllLocalDeclarations($exports, $program, $available, $moduleName, $units);

        return;
    }

    $matched = false;
    foreach ($program->imports as $import) {
        if (Ast\moduleName($import->path) !== $target) {
            continue;
        }

        $matched = true;
        if (!isset($units[$target])) {
            throw new TypeError("unknown module `{$target}`");
        }

        $targetUnit = $units[$target];
        $targetExports = collectExports(
            $targetUnit['program'],
            $targetUnit['localTypes'],
            $units,
            $target,
        );
        $selected = selectImportedNames($import, $targetExports);
        mergeSelectedIntoExportsStrict($exports, $selected, $moduleName, $item, $units[$moduleName] ?? []);
    }

    if (!$matched) {
        $unit = $units[$moduleName] ?? [];
        $span = exportItemSpan($item);
        throw new TypeError(
            "module `{$moduleName}` cannot re-export module `{$target}`; it is not imported",
            $unit['path'] ?? '',
            $unit['source'] ?? '',
            $span['line'],
            $span['col'],
            $span['endCol'],
        );
    }
}

/**
 * The source position of an export-list item, as the header parser recorded it.
 *
 * @param array<string, mixed> $item
 * @return array{line: int, col: int, endCol: int}
 */
function exportItemSpan(array $item): array
{
    return [
        'line' => (int) ($item['line'] ?? 0),
        'col' => (int) ($item['col'] ?? 0),
        'endCol' => (int) ($item['endCol'] ?? 0),
    ];
}

/**
 * A diagnostic about an export item, placed at the item's own source position.
 *
 * @param array<string, mixed> $item the export item (or synthesized declaration item) it is about
 * @param array<string, mixed> $unit the unit the item was written in
 */
function exportError(string $message, array $item, array $unit): TypeError
{
    $span = exportItemSpan($item);

    return new TypeError(
        $message,
        $unit['path'] ?? '',
        $unit['source'] ?? '',
        $span['line'],
        $span['col'],
        $span['endCol'],
    );
}

/**
 * An export item for a declaration exported whole (no export list), so a diagnostic about it points
 * at the declaration rather than nowhere.
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function declarationExportItem(string $tag, string $name, Ast\AstNode $decl, array $extra = []): array
{
    return [
        'tag' => $tag,
        ...$extra,
        'name' => $name,
        'line' => $decl->line,
        'col' => $decl->col,
        'endCol' => $decl->endCol,
    ];
}

/**
 * @param array<string, mixed> $exports
 * @param array<string, mixed> $selected
 * @param array<string, mixed> $item   the re-export item that pulled `$selected` in
 * @param array<string, mixed> $unit   the re-exporting module, for the diagnostic's filename
 */
function mergeSelectedIntoExportsStrict(
    array &$exports,
    array $selected,
    string $moduleName,
    array $item = [],
    array $unit = [],
): void {
    $span = exportItemSpan($item);
    $duplicateExport = static fn (string $name): TypeError => new TypeError(
        "duplicate export `{$name}` in module `{$moduleName}`",
        $unit['path'] ?? '',
        $unit['source'] ?? '',
        $span['line'],
        $span['col'],
        $span['endCol'],
    );

    foreach ($selected['env'] as $name => $scheme) {
        if (isset($exports['env'][$name])) {
            if (exportOriginsMatch(
                $exports['origins'][$name] ?? null,
                $selected['origins'][$name] ?? null,
            )) {
                continue;
            }

            throw $duplicateExport($name);
        }

        $exports['env'][$name] = $scheme;
        if (isset($selected['origins'][$name])) {
            $exports['origins'][$name] = $selected['origins'][$name];
        }
        if (isset($selected['intrinsicWrappers'][$name])) {
            $exports['intrinsicWrappers'][$name] = $selected['intrinsicWrappers'][$name];
        }
    }

    foreach ($selected['data'] as $name => $info) {
        if (isset($exports['data'][$name])) {
            if ($exports['data'][$name] === $info) {
                continue;
            }

            throw $duplicateExport($name);
        }

        $exports['data'][$name] = $info;
    }

    foreach ($selected['typeSynonyms'] as $name => $info) {
        if (isset($exports['typeSynonyms'][$name])) {
            if ($exports['typeSynonyms'][$name] === $info) {
                continue;
            }

            throw $duplicateExport($name);
        }

        $exports['typeSynonyms'][$name] = $info;
    }
}

/** @param ?array<string, mixed> $a @param ?array<string, mixed> $b */
function exportOriginsMatch(?array $a, ?array $b): bool
{
    if ($a === null && $b === null) {
        return true;
    }
    if ($a === null || $b === null) {
        return false;
    }

    return ($a['module'] ?? null) === ($b['module'] ?? null)
        && ($a['phpName'] ?? null) === ($b['phpName'] ?? null);
}

/** @param list<Ast\ImportDecl> $imports */
function mergeImportExportsIntoAvailable(array &$available, array $imports, array $units, string $moduleName): void
{
    foreach ($imports as $import) {
        $targetName = Ast\moduleName($import->path);
        if (!isset($units[$targetName])) {
            throw new TypeError("unknown module `{$targetName}`");
        }

        $target = $units[$targetName];
        $targetExports = collectExports($target['program'], $target['localTypes'], $units, $targetName);
        // Only an unqualified import puts a name in scope: `import M qualified as
        // A` cannot be named bare, so it can make no name available -- and so it
        // cannot clash with (or be re-exported as) one that is in scope.
        if (!$import->qualifiedOnly) {
            $selected = selectImportedNames($import, $targetExports);
            mergeIntoExports($available, $selected, $moduleName);
        }

        if ($import->kind === 'qualifiedAs' && $import->asName !== null) {
            $available['qualifiedModules'][$import->asName] = $targetName;
            $available['moduleAsNames'][$target['namespace']] = $import->asName;
        }
    }
}

/** @return array<string, array<string, mixed>> */
function exportedTypeItems(Ast\Program $program): array
{
    $types = [];
    if ($program->exports === null) {
        foreach ($program->items as $item) {
            if ($item instanceof Ast\DataDecl
                || $item instanceof Ast\TypeSynonymDecl
                || $item instanceof Ast\ForeignTypeDecl
            ) {
                $types[$item->name] = ['tag' => 'type', 'name' => $item->name];
            }
        }

        return $types;
    }

    foreach ($program->exports as $item) {
        if ($item['tag'] === 'type') {
            $types[$item['name']] = $item;
        }
    }

    return $types;
}

/**
 * @param array<string, mixed> $exports
 * @param array<string, array<string, mixed>> $units
 */
function exportAllLocalDeclarations(
    array &$exports,
    Ast\Program $program,
    array $available,
    string $moduleName,
    array $units = [],
): void {
    foreach ($program->items as $item) {
        match ($item::class) {
            Ast\FunctionDecl::class => isset($available['env'][$item->name])
                ? addExportedValue(
                    $exports,
                    $available,
                    $item->name,
                    $moduleName,
                    declarationExportItem('value', $item->name, $item),
                    $units,
                )
                : null,
            // A data declaration exported whole exports its constructors too.
            Ast\DataDecl::class => addExportedType(
                $exports,
                $available,
                $program,
                declarationExportItem('type', $item->name, $item, ['children' => ['mode' => 'all', 'names' => []]]),
                $moduleName,
                $units,
            ),
            Ast\ForeignTypeDecl::class,
            Ast\TypeSynonymDecl::class => addExportedType(
                $exports,
                $available,
                $program,
                declarationExportItem('type', $item->name, $item),
                $moduleName,
                $units,
            ),
            default => null,
        };
    }
}

/**
 * @param array<string, mixed> $exports
 * @param array<string, mixed> $item  the export item being added, for its source position
 * @param array<string, array<string, mixed>> $units
 */
function addExportedValue(
    array &$exports,
    array $available,
    string $name,
    string $moduleName,
    array $item = [],
    array $units = [],
): void {
    $unit = $units[$moduleName] ?? [];

    if (isset($available['ambiguous'][$name])) {
        throw exportError(
            ambiguousImportMessage($name, $available['ambiguous'][$name]),
            $item,
            $unit,
        );
    }

    if (!isset($available['env'][$name])) {
        throw exportError(
            appendDidYouMean(
                "module `{$moduleName}` cannot export unknown value `{$name}`",
                $name,
                \array_keys($available['env'] ?? []),
            ),
            $item,
            $unit,
        );
    }

    if (isset($exports['env'][$name])) {
        throw exportError("duplicate export `{$name}` in module `{$moduleName}`", $item, $unit);
    }

    $exports['env'][$name] = $available['env'][$name];
    if (isset($available['origins'][$name])) {
        $exports['origins'][$name] = $available['origins'][$name];
    }
    if (isset($available['intrinsicWrappers'][$name])) {
        $exports['intrinsicWrappers'][$name] = $available['intrinsicWrappers'][$name];
    }
}

/** @param array<string, mixed> $exports */
function addExportedType(
    array &$exports,
    array $available,
    Ast\Program $program,
    array $item,
    string $moduleName,
    array $units = [],
): void {
    $name = $item['name'];
    $unit = $units[$moduleName] ?? [];
    if (isset($available['data'][$name])) {
        if (isset($exports['data'][$name])) {
            throw exportError("duplicate export `{$name}` in module `{$moduleName}`", $item, $unit);
        }

        $childrenSpec = $item['children'] ?? ['mode' => 'none', 'names' => []];
        if (isset($available['data'][$name]['foreign']) && ($childrenSpec['mode'] ?? 'none') !== 'none') {
            throw exportError("foreign type `{$name}` has no constructors to export", $item, $unit);
        }

        $children = exportItemChildren($program, $available, $item, $moduleName);
        foreach ($children as $childName) {
            addExportedValue($exports, $available, $childName, $moduleName, $item, $units);
            $ctor = $available['data'][$name]['constructors'][$childName] ?? null;
            if (\is_array($ctor) && isset($ctor['aliasOf'])) {
                $exports['constructorRenames'][$childName] = $ctor['aliasOf'];
            }
        }
        $exports['data'][$name] = $available['data'][$name];

        return;
    }

    if (isset($available['typeSynonyms'][$name])) {
        if (isset($exports['typeSynonyms'][$name])) {
            throw exportError("duplicate export `{$name}` in module `{$moduleName}`", $item, $unit);
        }

        // Type synonyms have no constructors; `Int` and `Int(..)` both export the type.
        // Class methods live on Num/Integral/…; instances are linked with the module.
        $exports['typeSynonyms'][$name] = $available['typeSynonyms'][$name];

        return;
    }

    // Built-in Tuple2..Tuple64 live in the kind env (not as data decls). Allow
    // type-only re-export (`TupleN` / `TupleN(..)`) from Data.Tuple without
    // installing a real synonym — parametric synonyms would block partial
    // apps such as `Functor (Tuple3 a1 a2)`.
    if (isBuiltinTupleExportName($name)) {
        if (isset($exports['ambientTypes'][$name]) || isset($exports['typeSynonyms'][$name]) || isset($exports['data'][$name])) {
            throw exportError("duplicate export `{$name}` in module `{$moduleName}`", $item, $unit);
        }
        $children = $item['children'] ?? ['mode' => 'none', 'names' => []];
        if ($children['mode'] === 'some' && $children['names'] !== []) {
            throw exportError("built-in `{$name}` has no constructors to export", $item, $unit);
        }
        $exports['ambientTypes'][$name] = true;

        return;
    }

    $classMethods = classMethodsForExport($program, $name);
    if ($classMethods === null) {
        $classMethods = classMethodsFromUnits($units, $name);
    }
    if ($classMethods !== null) {
        $exportedMethods = exportItemChildNames($item, $classMethods);
        // The class name itself is importable (`import M (C(..))`), which is
        // also what brings its methods along.
        $exports['classes'][$name] = $exportedMethods;
        foreach ($exportedMethods as $methodName) {
            addExportedValue($exports, $available, $methodName, $moduleName, $item, $units);
        }

        return;
    }

    if (isset($item['children']) && $item['children']['mode'] === 'some') {
        foreach ($item['children']['names'] as $methodName) {
            addExportedValue($exports, $available, $methodName, $moduleName, $item, $units);
        }

        return;
    }

    throw exportError(
        appendDidYouMean(
            "module `{$moduleName}` cannot export unknown type or class `{$name}`",
            $name,
            [...\array_keys($available['data'] ?? []), ...\array_keys($available['typeSynonyms'] ?? [])],
        ),
        $item,
        $unit,
    );
}

function isBuiltinTupleExportName(string $name): bool
{
    if (!preg_match('/^Tuple([1-9][0-9]?)$/', $name, $m)) {
        return false;
    }
    $arity = (int) $m[1];

    return $arity >= 2 && $arity <= 64;
}

/**
 * `Solo a = MkSolo a` is a constructor alias: both names are exportable
 * constructors even when the AST only declares `MkSolo`.
 *
 * @param list<string> $constructors
 * @return list<string>
 */
function withSurfaceConstructorAliases(string $typeName, array $constructors): array
{
    if ($typeName === 'Solo'
        && \in_array('MkSolo', $constructors, true)
        && !\in_array('Solo', $constructors, true)
    ) {
        $constructors[] = 'Solo';
    }

    return $constructors;
}

/** @return list<string> */
function exportItemChildren(Ast\Program $program, array $available, array $item, string $moduleName): array
{
    // Prefer typecheck-time constructors (includes aliases like Solo→MkSolo).
    if (isset($available['data'][$item['name']]['constructors'])) {
        $constructors = withSurfaceConstructorAliases(
            $item['name'],
            \array_keys($available['data'][$item['name']]['constructors']),
        );
    } else {
        $constructors = constructorsForExport($program, $item['name']);
    }

    if ($constructors === null) {
        throw new TypeError("module `{$moduleName}` cannot export constructors for unknown data type `{$item['name']}`");
    }

    return exportItemChildNames($item, $constructors);
}

/** @param list<string> $all @return list<string> */
function exportItemChildNames(array $item, array $all): array
{
    $children = $item['children'] ?? ['mode' => 'none', 'names' => []];
    if ($children['mode'] === 'none') {
        return [];
    }

    if ($children['mode'] === 'all') {
        return $all;
    }

    $available = \array_fill_keys($all, true);
    foreach ($children['names'] as $name) {
        if (!isset($available[$name])) {
            throw new TypeError(appendDidYouMean(
                "`{$item['name']}` has no exported child `{$name}`",
                $name,
                $all,
            ));
        }
    }

    return $children['names'];
}

/** @return list<string>|null */
function constructorsForExport(Ast\Program $program, string $typeName): ?array
{
    foreach ($program->items as $item) {
        if ($item instanceof Ast\DataDecl && $item->name === $typeName) {
            return withSurfaceConstructorAliases($typeName, allConstructors($item));
        }
    }

    return null;
}

/** @return list<string>|null */
function classMethodsForExport(Ast\Program $program, string $className): ?array
{
    foreach ($program->items as $item) {
        if ($item instanceof Ast\ClassDecl && $item->name === $className) {
            return \array_map(static fn (Ast\ClassMethodSig $method): string => $method->name, $item->methods);
        }
    }

    return null;
}

/** @param array<string, array<string, mixed>> $units @return list<string>|null */
function classMethodsFromUnits(array $units, string $className): ?array
{
    foreach ($units as $unit) {
        $program = $unit['program'] ?? null;
        if (!$program instanceof Ast\Program) {
            continue;
        }

        $found = classMethodsForExport($program, $className);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/** @return array{module: string, namespace: string, phpName: string} */
function exportOrigin(string $moduleName, string $namespace, string $phpName): array
{
    return ['module' => $moduleName, 'namespace' => $namespace, 'phpName' => $phpName];
}

/**
 * @param array{
 *   env: array<string, mixed>,
 *   typeSynonyms: array<string, mixed>,
 *   data: array<string, mixed>,
 *   origins: array<string, array{module: string, namespace: string, phpName: string}>,
 *   qualifiedModules: array<string, string>,
 *   moduleAsNames: array<string, string>
 * } $exports
 * @param array{env: array<string, mixed>, typeSynonyms: array<string, mixed>, data: array<string, mixed>, origins: array<string, array{module: string, namespace: string, phpName: string}>} $selected
 */
function mergeIntoExports(array &$exports, array $selected, string $moduleName): void
{
    foreach ($selected['env'] as $name => $scheme) {
        if (isset($exports['env'][$name])) {
            // Two imports of one name as different entities: legal to import,
            // so the name stays usable as an *available* one for a while -- but
            // re-exporting or naming it is the error (reported at the item).
            $existing = $exports['origins'][$name] ?? null;
            $incoming = $selected['origins'][$name] ?? null;
            if ($existing !== null && $incoming !== null
                && ($existing['module'] ?? null) !== $moduleName
                && ($incoming['module'] ?? null) !== $moduleName
                && ($existing['module'] ?? null) !== ($incoming['module'] ?? null)
            ) {
                $exports['ambiguous'][$name] = [
                    'origins' => \array_values(\array_unique([
                        ...($exports['ambiguous'][$name]['origins'] ?? [$existing['module']]),
                        $incoming['module'],
                    ])),
                ];
                unset($exports['env'][$name], $exports['origins'][$name]);
            }

            continue;
        }

        $exports['env'][$name] = $scheme;
        if (isset($selected['origins'][$name])) {
            $exports['origins'][$name] = $selected['origins'][$name];
        }
        if (isset($selected['intrinsicWrappers'][$name])) {
            $exports['intrinsicWrappers'][$name] = $selected['intrinsicWrappers'][$name];
        }
    }

    foreach ($selected['typeSynonyms'] as $name => $type) {
        if (isset($exports['typeSynonyms'][$name])) {
            continue;
        }

        $exports['typeSynonyms'][$name] = $type;
    }

    foreach ($selected['data'] as $name => $info) {
        if (isset($exports['data'][$name])) {
            continue;
        }

        $exports['data'][$name] = $info;
    }
}

/** @return list<string> */
function allConstructors(Ast\DataDecl $dataDecl): array
{
    return \array_map(
        static fn (Ast\ConstructorDecl $ctor): string => $ctor->name,
        $dataDecl->constructors,
    );
}

/**
 * @param array{
 *   env: array<string, mixed>,
 *   typeSynonyms: array<string, mixed>,
 *   data: array<string, mixed>,
 *   origins: array<string, array{module: string, namespace: string, phpName: string}>
 * } $exports
 * @return array{
 *   env: array<string, mixed>,
 *   typeSynonyms: array<string, mixed>,
 *   data: array<string, mixed>,
 *   constructorRenames: array<string, string>,
 *   origins: array<string, array{module: string, namespace: string, phpName: string}>
 * }
 */
/**
 * Add the named children of an imported class or type (methods or constructors)
 * to the selection. Every one of them is an `env` entry in the export.
 *
 * @param array<string, mixed> $selected
 * @param array<string, mixed> $exports
 * @param list<string> $all
 * @param list<string> $names
 */
function selectImportedChildren(array &$selected, array $exports, array $all, array $names, string $missing): void
{
    $available = \array_fill_keys($all, true);
    foreach ($names as $name) {
        if (!isset($available[$name])) {
            throw new TypeError(appendDidYouMean("{$missing} `{$name}`", $name, $all));
        }
        if (!isset($exports['env'][$name])) {
            continue;
        }
        $selected['env'][$name] = $exports['env'][$name];
        if (isset($exports['origins'][$name])) {
            $selected['origins'][$name] = $exports['origins'][$name];
        }
        if (isset($exports['intrinsicWrappers'][$name])) {
            $selected['intrinsicWrappers'][$name] = $exports['intrinsicWrappers'][$name];
        }
    }
}

function selectImportedNames(Ast\ImportDecl $import, array $exports): array
{
    $selected = [
        'env' => [],
        'typeSynonyms' => [],
        'data' => [],
        'constructorRenames' => [],
        'ambientTypes' => [],
        'origins' => [],
        'intrinsicWrappers' => [],
    ];
    $hiding = \array_fill_keys($import->hiding, true);

    if ($import->kind === 'glob') {
        foreach ($exports['env'] as $name => $scheme) {
            if (!isset($hiding[$name])) {
                $selected['env'][$name] = $scheme;
                if (isset($exports['origins'][$name])) {
                    $selected['origins'][$name] = $exports['origins'][$name];
                }
                if (isset($exports['intrinsicWrappers'][$name])) {
                    $selected['intrinsicWrappers'][$name] = $exports['intrinsicWrappers'][$name];
                }
            }
        }

        foreach ($exports['typeSynonyms'] as $name => $type) {
            if (!isset($hiding[$name])) {
                $selected['typeSynonyms'][$name] = $type;
            }
        }

        foreach ($exports['data'] as $name => $info) {
            if (!isset($hiding[$name])) {
                $selected['data'][$name] = $info;
            }
        }

        foreach ($exports['ambientTypes'] ?? [] as $name => $_) {
            if (!isset($hiding[$name])) {
                $selected['ambientTypes'][$name] = true;
            }
        }

        foreach ($exports['constructorRenames'] ?? [] as $alias => $canonical) {
            if (!isset($hiding[$alias]) && isset($selected['env'][$alias])) {
                $selected['constructorRenames'][$alias] = $canonical;
            }
        }

        return $selected;
    }

    if ($import->kind === 'named') {
        foreach ($import->items as $item) {
            $name = $item->name;
            $localName = $item->asName ?? $name;
            if (isset($hiding[$localName])) {
                continue;
            }

            $matched = false;

            if (isset($exports['env'][$name])) {
                $selected['env'][$localName] = $exports['env'][$name];
                if (isset($exports['origins'][$name])) {
                    $selected['origins'][$localName] = $exports['origins'][$name];
                }
                if (isset($exports['intrinsicWrappers'][$name])) {
                    $selected['intrinsicWrappers'][$localName] = $exports['intrinsicWrappers'][$name];
                }
                if ($localName !== $name && isConstructorName($name)) {
                    $selected['constructorRenames'][$localName] = $name;
                }
                if (isset($exports['constructorRenames'][$name])) {
                    $selected['constructorRenames'][$localName] = $exports['constructorRenames'][$name];
                }
                $matched = true;
            }

            if (isset($exports['typeSynonyms'][$name])) {
                $selected['typeSynonyms'][$localName] = $exports['typeSynonyms'][$name];
                $matched = true;
            }

            if (isset($exports['data'][$name])) {
                $selected['data'][$localName] = $exports['data'][$name];
                $matched = true;
            }

            if (isset($exports['ambientTypes'][$name])) {
                $selected['ambientTypes'][$localName] = true;
                $matched = true;
            }

            // `import M (C)`, `(C(..))`, `(C(m))`: importing a class brings its
            // methods, which are the env entries the export of the class made.
            // A data type's sublist selects constructors the same way.
            $classMethods = $exports['classes'][$name] ?? null;
            if ($classMethods !== null) {
                selectImportedChildren(
                    $selected,
                    $exports,
                    $classMethods,
                    ($item->methods === null || $item->methods === 'all') ? $classMethods : $item->methods,
                    "class `{$name}` has no method",
                );
                $matched = true;
            }

            $constructors = isset($exports['data'][$name]['constructors'])
                ? \array_keys($exports['data'][$name]['constructors'])
                : null;
            if ($constructors !== null && $item->methods !== null) {
                selectImportedChildren(
                    $selected,
                    $exports,
                    $constructors,
                    $item->methods === 'all' ? $constructors : $item->methods,
                    "`{$name}` has no constructor",
                );
            }

            if (!$matched) {
                $moduleName = Ast\moduleName($import->path);
                $exported = [
                    ...\array_keys($exports['env'] ?? []),
                    ...\array_keys($exports['typeSynonyms'] ?? []),
                    ...\array_keys($exports['data'] ?? []),
                    ...\array_keys($exports['ambientTypes'] ?? []),
                    ...\array_keys($exports['classes'] ?? []),
                ];
                throw new TypeError(appendDidYouMean(
                    "module `{$moduleName}` does not export `{$name}`",
                    $name,
                    array_values(array_unique($exported)),
                ));
            }
        }

        return $selected;
    }

    foreach ($exports['env'] as $name => $scheme) {
        if (!isset($hiding[$name])) {
            $selected['env'][$name] = $scheme;
            if (isset($exports['origins'][$name])) {
                $selected['origins'][$name] = $exports['origins'][$name];
            }
            if (isset($exports['intrinsicWrappers'][$name])) {
                $selected['intrinsicWrappers'][$name] = $exports['intrinsicWrappers'][$name];
            }
        }
    }

    foreach ($exports['typeSynonyms'] as $name => $type) {
        if (!isset($hiding[$name])) {
            $selected['typeSynonyms'][$name] = $type;
        }
    }

    foreach ($exports['data'] as $name => $info) {
        if (!isset($hiding[$name])) {
            $selected['data'][$name] = $info;
        }
    }

    foreach ($exports['ambientTypes'] ?? [] as $name => $_) {
        if (!isset($hiding[$name])) {
            $selected['ambientTypes'][$name] = true;
        }
    }

    foreach ($exports['constructorRenames'] ?? [] as $alias => $canonical) {
        if (!isset($hiding[$alias]) && isset($selected['env'][$alias])) {
            $selected['constructorRenames'][$alias] = $canonical;
        }
    }

    return $selected;
}
