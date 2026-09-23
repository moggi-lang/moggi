<?php declare(strict_types=1);

namespace Moggi\Docs;

use Moggi\Modules\PreparedProject;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Syntax\Ast;

use function Moggi\Cache\atomicWrite;
use function Moggi\Cache\cacheGenerationDir;
use function Moggi\CLI\resolveLibraryDirs;
use function Moggi\Modules\isSyntheticCompilerModuleName;
use function Moggi\Syntax\Parser\standardFixity;

/**
 * Version of the docs search-index schema.
 *
 * The index is stored under the compile cache's generation directory, beside
 * the module artifacts it was built from: it is only valid for the compiler
 * state that produced it, and a fingerprint-named parent is what keeps one
 * state's index out of another's way.
 */
function docIndexSchemaVersion(): int
{
    return 1;
}

final class DocEntity
{
    /**
     * @param list<string> $aliases Other modules that re-export this entity
     */
    public function __construct(
        public readonly string $module,
        public readonly string $name,
        /** module|value|type|class|ctor|field|foreign|primop */
        public readonly string $kind,
        public readonly ?string $signature,
        public readonly ?string $doc,
        public readonly ?string $section,
        public readonly string $href,
        public readonly array $aliases = [],
        /** Parent type or class name for constructors, fields, and methods. */
        public readonly ?string $parentName = null,
        /** data|newtype|synonym|primtype|class|ctor|method|instance|primop */
        public readonly ?string $declKind = null,
        public readonly ?int $sourceLine = null,
        public readonly ?string $sourcePath = null,
        public readonly ?string $fixity = null,
    ) {
    }
}

final class DocIndex
{
    /**
     * @param array<string, list<DocEntity>> $byModule
     * @param list<DocEntity> $all
     * @param list<DocEntity> $search deduplicated entities for moogle
     * @param array<string, array<string, string>> $facadeBackends facade module => backend id => impl module
     * @param array<string, array{facade: string, backend: string}> $implFacades impl module => facade info
     */
    public function __construct(
        public readonly array $byModule,
        public readonly array $all,
        public readonly array $search = [],
        public readonly array $facadeBackends = [],
        public readonly array $implFacades = [],
        public readonly string $rootPrefix = '',
    ) {
    }
}

/**
 * @param array<string, ParsedModule> $modules
 * @param array<string, Ast\Program> $checkedPrograms
 */
function buildIndex(
    array $modules,
    array $checkedPrograms = [],
    ?PreparedProject $prepared = null,
): DocIndex {
    $byModule = [];
    $all = [];
    $canonical = [];
    $rootPrefix = $prepared?->rootPrefix ?? '';

    foreach ($modules as $moduleName => $parsed) {
        if (isBackendImplModule($moduleName)) {
            continue;
        }

        $program = $parsed->program;
        $checked = $checkedPrograms[$moduleName] ?? null;
        $exportFilter = docExportFilter($program);
        $unit = $prepared?->units[$moduleName] ?? null;
        $localFixity = $unit['localFixity'] ?? [];
        $effectiveFixity = $unit['fixity'] ?? [];
        $moduleSourcePath = relativeSourcePath($parsed->path, $rootPrefix);
        $moduleSource = $parsed->source;

        $entities = [];
        $modHref = modulePageName($moduleName) . '#' . anchorId($moduleName, $moduleName);
        $entities[] = new DocEntity(
            $moduleName,
            $moduleName,
            'module',
            null,
            moduleDocWithFixity($program->moduleDoc, $program->fixityDocs),
            null,
            $modHref,
        );

        foreach ($program->items as $item) {
            if ($item instanceof Ast\FunctionDecl) {
                if (!isExportedValue($exportFilter, $program, $item->name)) {
                    continue;
                }
                $entity = makeEntity(
                    $moduleName,
                    $item->name,
                    'value',
                    valueDocSignature($item->name, $item->type, $checked, $moduleSource),
                    nodeDoc($item->doc),
                    exportSectionForName($program, $item->name),
                    null,
                    'function',
                    declarationSourceLine($moduleSource, $item->name, $item->line),
                    $moduleSourcePath,
                    fixityForName($item->name, $localFixity, $effectiveFixity),
                );
                $entities[] = $entity;
                rememberCanonical($canonical, $entity);
                continue;
            }

            if ($item instanceof Ast\DataDecl) {
                if (!isExportedType($exportFilter, $item->name)) {
                    continue;
                }
                $declKind = $item->isNewtype ? 'newtype' : 'data';
                $entity = makeEntity(
                    $moduleName,
                    $item->name,
                    'type',
                    dataTypeHeadSignature($item),
                    nodeDoc($item->doc),
                    exportSectionForName($program, $item->name),
                    null,
                    $declKind,
                    declarationSourceLine($moduleSource, $item->name, $item->line),
                    $moduleSourcePath,
                );
                $entities[] = $entity;
                rememberCanonical($canonical, $entity);
                foreach ($item->constructors as $ctor) {
                    if (!isExportedCtor(
                        $exportFilter,
                        $program,
                        $item->name,
                        $ctor->name,
                        $checked?->constructorRenames ?? [],
                    )) {
                        continue;
                    }
                    $ctorEntity = makeEntity(
                        $moduleName,
                        $ctor->name,
                        'ctor',
                        constructorSignature($item, $ctor),
                        nodeDoc($ctor->doc),
                        exportSectionForName($program, $ctor->name),
                        $item->name,
                        'ctor',
                        $ctor->line,
                        $moduleSourcePath,
                    );
                    $entities[] = $ctorEntity;
                    rememberCanonical($canonical, $ctorEntity);
                }
                continue;
            }

            if ($item instanceof Ast\TypeSynonymDecl) {
                if (!isExportedType($exportFilter, $item->name)) {
                    continue;
                }
                $sig = 'type ' . $item->name
                    . (count($item->params) > 0 ? ' ' . implode(' ', $item->params) : '')
                    . ' = ' . surfaceTypeSignature($item->type);
                $entity = makeEntity(
                    $moduleName,
                    $item->name,
                    'type',
                    $sig,
                    nodeDoc($item->doc),
                    exportSectionForName($program, $item->name),
                    null,
                    'synonym',
                    declarationSourceLine($moduleSource, $item->name, $item->line),
                    $moduleSourcePath,
                );
                $entities[] = $entity;
                rememberCanonical($canonical, $entity);
                continue;
            }

            if ($item instanceof Ast\ClassDecl) {
                if (!isExportedType($exportFilter, $item->name)) {
                    continue;
                }
                $entity = makeEntity(
                    $moduleName,
                    $item->name,
                    'class',
                    classDeclHeader($item),
                    nodeDoc($item->doc),
                    exportSectionForName($program, $item->name),
                    null,
                    'class',
                    declarationSourceLine($moduleSource, $item->name, $item->line),
                    $moduleSourcePath,
                );
                $entities[] = $entity;
                rememberCanonical($canonical, $entity);
                foreach ($item->methods as $method) {
                    if (!isExportedValue($exportFilter, $program, $method->name)) {
                        continue;
                    }
                    $methodEntity = makeEntity(
                        $moduleName,
                        $method->name,
                        'value',
                        valueDocSignature($method->name, $method->type, $checked, $moduleSource),
                        nodeDoc($method->doc),
                        exportSectionForName($program, $method->name),
                        $item->name,
                        'method',
                        declarationSourceLine($moduleSource, $method->name),
                        $moduleSourcePath,
                        fixityForName($method->name, $localFixity, $effectiveFixity),
                    );
                    $entities[] = $methodEntity;
                    rememberCanonical($canonical, $methodEntity);
                }
                continue;
            }

            if ($item instanceof Ast\InstanceDecl) {
                $parent = instanceParentTypeName($item->head);
                if ($parent === null || !isExportedType($exportFilter, $parent)) {
                    continue;
                }
                $instName = instanceEntityName($item);
                $instEntity = makeEntity(
                    $moduleName,
                    $instName,
                    'instance',
                    instanceDeclSignature($item),
                    nodeDoc($item->doc),
                    null,
                    $parent,
                    'instance',
                    $item->line,
                    $moduleSourcePath,
                );
                $entities[] = $instEntity;
                continue;
            }

            if ($item instanceof Ast\ForeignTypeDecl) {
                if (!isExportedValue($exportFilter, $program, $item->name)) {
                    continue;
                }
                $entity = makeEntity(
                    $moduleName,
                    $item->name,
                    'foreign-type',
                    $item->name . ' = "' . $item->hostType . '"',
                    nodeDoc($item->doc),
                    exportSectionForName($program, $item->name),
                    null,
                    'foreign',
                    declarationSourceLine($moduleSource, $item->name, $item->line),
                    $moduleSourcePath,
                );
                $entities[] = $entity;
                rememberCanonical($canonical, $entity);
                continue;
            }

            if ($item instanceof Ast\ForeignTypeDecl) {
                if (!isExportedValue($exportFilter, $program, $item->name)) {
                    continue;
                }
                $entity = makeEntity(
                    $moduleName,
                    $item->name,
                    'foreign_type',
                    '',
                    nodeDoc($item->doc),
                    exportSectionForName($program, $item->name),
                    null,
                    'foreign',
                    declarationSourceLine($moduleSource, $item->name, $item->line),
                    $moduleSourcePath,
                );
                $entities[] = $entity;
                rememberCanonical($canonical, $entity);
                continue;
            }

            if ($item instanceof Ast\ForeignImportDecl) {
                if (!isExportedValue($exportFilter, $program, $item->name)) {
                    continue;
                }
                $entity = makeEntity(
                    $moduleName,
                    $item->name,
                    'foreign',
                    valueDocSignature($item->name, $item->type, $checked, $moduleSource),
                    nodeDoc($item->doc),
                    exportSectionForName($program, $item->name),
                    null,
                    'foreign',
                    declarationSourceLine($moduleSource, $item->name, $item->line),
                    $moduleSourcePath,
                );
                $entities[] = $entity;
                rememberCanonical($canonical, $entity);
            }
        }

        $unit = $prepared?->units[$moduleName] ?? null;
        if (($unit['synthetic'] ?? false) === true) {
            foreach (entitiesFromSyntheticUnit($moduleName, $unit) as $entity) {
                $entities[] = $entity;
                rememberCanonical($canonical, $entity);
            }
        }

        // Keep export-only facades (e.g. Prelude) so re-export entries can attach below.
        $isSynthetic = isSyntheticCompilerModuleName($moduleName);
        $hasIndexedBody = count($entities) > 1 || $program->fixityDocs !== [];
        if (!$isSynthetic && !$hasIndexedBody && ($program->exports === null || $program->exports === [])) {
            continue;
        }

        $byModule[$moduleName] = $entities;
        foreach ($entities as $entity) {
            $all[] = $entity;
        }
    }

    if ($prepared !== null) {
        attachReexportAliases($canonical, $modules, $prepared, $checkedPrograms);
        syncCanonicalIntoByModule($byModule, $all, $canonical);
        addReexportModuleEntries($byModule, $canonical, $modules, $prepared, $all, $checkedPrograms);
    }

    ksort($byModule);

    $search = searchEntitiesFromCanonical($canonical);
    foreach ($byModule as $entities) {
        foreach ($entities as $entity) {
            if ($entity->kind === 'module') {
                $search[] = $entity;
            }
        }
    }
    usort($search, static fn (DocEntity $a, DocEntity $b): int => $a->module <=> $b->module ?: $a->name <=> $b->name);

    [$facadeBackends, $implFacades] = facadeBackendMaps($modules);

    return new DocIndex($byModule, $all, $search, $facadeBackends, $implFacades, $rootPrefix);
}

function relativeSourcePath(string $path, string $rootPrefix): ?string
{
    if ($path === '') {
        return null;
    }

    if ($rootPrefix !== '' && str_starts_with($path, $rootPrefix)) {
        return ltrim(substr($path, strlen($rootPrefix)), '/\\');
    }

    return $path;
}

function declarationSourceLine(string $source, string $name, int $fallback = 0): int
{
    if ($fallback > 0) {
        return $fallback;
    }

    $pattern = docSourceNamePattern($name);
    $lineNo = 1;
    foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
        if (preg_match('/^\s*' . $pattern . '\s*::/', $line) === 1
            || preg_match('/^\s*' . $pattern . '\s*,/', $line) === 1
            || preg_match('/,\s*' . $pattern . '\s*::/', $line) === 1
            || preg_match('/,\s*' . $pattern . '\s*,/', $line) === 1) {
            return $lineNo;
        }
        ++$lineNo;
    }

    return $fallback;
}

function docSourceNamePattern(string $name): string
{
    if (preg_match('/^\w+$/', $name) === 1) {
        return preg_quote($name, '/');
    }

    return '\(' . preg_quote($name, '/') . '\)';
}

function signatureFromSource(string $source, string $name): ?string
{
    $pattern = docSourceNamePattern($name);
    $lines = preg_split('/\r\n|\n|\r/', $source) ?: [];
    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount; ++$i) {
        $line = $lines[$i];
        $matched = null;
        if (preg_match('/^\s*' . $pattern . '\s*::\s*(.*)$/', $line, $match) === 1) {
            $matched = $match[1];
        } elseif (preg_match('/,\s*' . $pattern . '\s*::\s*(.*)$/', $line, $match) === 1) {
            $matched = $match[1];
        }
        if ($matched === null) {
            continue;
        }

        $typePart = rtrim($matched);
        while ($i + 1 < $lineCount && signatureTypeContinues($typePart)) {
            ++$i;
            $typePart .= ' ' . trim($lines[$i]);
        }

        return $name . ' :: ' . $typePart;
    }

    return null;
}

function signatureTypeContinues(string $typePart): bool
{
    $paren = 0;
    $bracket = 0;
    foreach (str_split($typePart) as $ch) {
        if ($ch === '(') {
            ++$paren;
        } elseif ($ch === ')') {
            --$paren;
        } elseif ($ch === '[') {
            ++$bracket;
        } elseif ($ch === ']') {
            --$bracket;
        }
    }

    return $paren > 0 || $bracket > 0 || str_ends_with(rtrim($typePart), '=>');
}

/**
 * @param array<string, array{assoc: string, prec: int}> $localFixity
 * @param array<string, array{assoc: string, prec: int}> $effectiveFixity
 */
function fixityForName(string $name, array $localFixity, array $effectiveFixity): ?string
{
    $info = $localFixity[$name] ?? $effectiveFixity[$name] ?? standardFixity($name);
    if ($info === null) {
        return null;
    }

    return $info['assoc'] . ' ' . (string) $info['prec'];
}

function valueDocSignature(
    string $name,
    ?Ast\TypeNode $annotated,
    ?Ast\Program $checked,
    ?string $source = null,
): ?string {
    $base = null;

    if ($checked !== null && isset($checked->exportedInferredSchemes[$name])) {
        $base = $name . ' :: ' . schemeToString($checked->exportedInferredSchemes[$name]);
    } elseif ($annotated !== null && $source !== null && astTypeUsesDictEncoding($annotated)) {
        $base = signatureFromSource($source, $name);
    }

    if ($base === null && $annotated !== null) {
        $surface = surfaceTypeSignature($annotated);
        $base = $surface !== null ? $name . ' :: ' . $surface : null;
    } elseif ($base === null && $checked !== null) {
        foreach ($checked->items as $item) {
            if ($item instanceof Ast\FunctionDecl && $item->name === $name && $item->type !== null) {
                $surface = surfaceTypeSignature($item->type);
                $base = $surface !== null ? $name . ' :: ' . $surface : null;
                break;
            }
        }
    }

    return $base;
}

/** @param array<string, DocEntity> $canonical */
function rememberCanonical(array &$canonical, DocEntity $entity): void
{
    if ($entity->kind === 'module') {
        return;
    }

    $canonical[canonicalKey($entity)] = $entity;
}

function canonicalKey(DocEntity $entity): string
{
    return $entity->module . "\0" . $entity->kind . "\0" . $entity->name;
}

/**
 * @param array<string, DocEntity> $canonical
 * @param array<string, ParsedModule> $modules
 */
function attachReexportAliases(
    array &$canonical,
    array $modules,
    PreparedProject $prepared,
    array $checkedPrograms,
): void {
    $units = $prepared->units;
    foreach ($modules as $moduleName => $_parsed) {
        if (!isset($units[$moduleName])) {
            continue;
        }

        $unit = $units[$moduleName];
        $exports = collectExportsForDocs(
            $unit['program'],
            $unit['localTypes'] ?? [],
            $units,
            $moduleName,
            $checkedPrograms,
        );
        if ($exports === null) {
            continue;
        }

        foreach ($exports['env'] as $name => $_scheme) {
            $home = canonicalValueForReexport(
                $name,
                $unit['program'],
                $units,
                $exports,
                $canonical,
            );
            if ($home === null) {
                continue;
            }

            $key = canonicalKey($home);
            if (!isset($canonical[$key])) {
                continue;
            }

            $entity = $canonical[$key];
            $aliases = array_values(array_unique([...$entity->aliases, $moduleName]));
            $canonical[$key] = copyDocEntity($entity, ['aliases' => $aliases]);
        }

        foreach (['data', 'typeSynonyms'] as $bucket) {
            foreach ($exports[$bucket] ?? [] as $name => $_info) {
                $home = canonicalTypeForReexport(
                    $name,
                    $unit['program'],
                    $units,
                    $exports,
                    $canonical,
                );
                if ($home === null) {
                    continue;
                }

                $key = canonicalKey($home);
                if (!isset($canonical[$key])) {
                    continue;
                }

                $entity = $canonical[$key];
                $aliases = array_values(array_unique([...$entity->aliases, $moduleName]));
                $canonical[$key] = copyDocEntity($entity, ['aliases' => $aliases]);
            }
        }

        attachClassReexportAliases($canonical, $unit['program'], $moduleName);
    }
}

/**
 * @param array<string, list<DocEntity>> $byModule
 * @param array<string, DocEntity> $canonical
 * @param array<string, ParsedModule> $modules
 * @param list<DocEntity> $all
 */
function addReexportModuleEntries(
    array &$byModule,
    array $canonical,
    array $modules,
    PreparedProject $prepared,
    array &$all,
    array $checkedPrograms,
): void {
    $units = $prepared->units;
    foreach ($modules as $moduleName => $_parsed) {
        if (!isset($units[$moduleName], $byModule[$moduleName])) {
            continue;
        }

        $unit = $units[$moduleName];
        $exports = collectExportsForDocs(
            $unit['program'],
            $unit['localTypes'] ?? [],
            $units,
            $moduleName,
            $checkedPrograms,
        );
        if ($exports === null) {
            continue;
        }

        $extra = [];
        $existing = [];
        foreach ($byModule[$moduleName] as $entity) {
            if ($entity->kind !== 'module') {
                $existing[$entity->name] = true;
            }
        }

        foreach ($exports['env'] as $name => $_scheme) {
            if (isset($existing[$name])) {
                continue;
            }
            $home = canonicalValueForReexport(
                $name,
                $unit['program'],
                $units,
                $exports,
                $canonical,
            );
            if ($home === null) {
                continue;
            }

            foreach (reexportEntityBundle($home, $moduleName, $byModule, exportSectionForName($unit['program'], $name)) as $entity) {
                if (isset($existing[$entity->name]) && $entity->parentName === null) {
                    continue;
                }
                $extra[] = $entity;
                if ($entity->parentName === null) {
                    $existing[$entity->name] = true;
                }
            }
        }

        foreach (['data', 'typeSynonyms'] as $bucket) {
            foreach ($exports[$bucket] ?? [] as $name => $_info) {
                if (isset($existing[$name])) {
                    continue;
                }
                $home = canonicalTypeForReexport(
                    $name,
                    $unit['program'],
                    $units,
                    $exports,
                    $canonical,
                );
                if ($home === null) {
                    continue;
                }
                foreach (reexportEntityBundle($home, $moduleName, $byModule, exportSectionForName($unit['program'], $name)) as $entity) {
                    if (isset($existing[$entity->name]) && $entity->parentName === null) {
                        continue;
                    }
                    $extra[] = $entity;
                    if ($entity->parentName === null) {
                        $existing[$entity->name] = true;
                    }
                }
            }
        }

        foreach (reexportedClassHomes($unit['program'], $canonical, $moduleName) as $home) {
            if (isset($existing[$home->name])) {
                continue;
            }
            foreach (reexportEntityBundle($home, $moduleName, $byModule, null) as $entity) {
                if (isset($existing[$entity->name]) && $entity->parentName === null) {
                    continue;
                }
                $extra[] = $entity;
                if ($entity->parentName === null) {
                    $existing[$entity->name] = true;
                }
            }
        }

        if ($extra === []) {
            continue;
        }

        $merged = [...$byModule[$moduleName], ...$extra];
        usort($merged, static fn (DocEntity $a, DocEntity $b): int => $a->name <=> $b->name);
        $byModule[$moduleName] = $merged;
        foreach ($extra as $entity) {
            $all[] = $entity;
        }
    }
}

/**
 * @param array<string, DocEntity> $canonical
 * @return list<DocEntity>
 */
function searchEntitiesFromCanonical(array $canonical): array
{
    $entities = array_values($canonical);
    usort($entities, static fn (DocEntity $a, DocEntity $b): int => $a->module <=> $b->module ?: $a->name <=> $b->name);

    return $entities;
}

/**
 * @param list<array{doc: string, operators: list<string>, assoc: string, prec: int}> $fixityDocs
 */
function moduleDocWithFixity(?string $moduleDoc, array $fixityDocs): ?string
{
    $parts = [];
    if ($moduleDoc !== null && $moduleDoc !== '') {
        $parts[] = $moduleDoc;
    }

    foreach ($fixityDocs as $entry) {
        $ops = implode(', ', $entry['operators']);
        $header = $entry['assoc'] . ' ' . $entry['prec'] . ' ' . $ops;
        $doc = trim($entry['doc'] ?? '');
        $parts[] = $doc !== '' ? $doc . "\n\n" . $header : $header;
    }

    if ($parts === []) {
        return null;
    }

    return implode("\n\n", $parts);
}

/**
 * @param array<string, list<DocEntity>> $byModule
 * @param list<DocEntity> $all
 * @param array<string, DocEntity> $canonical
 */
function syncCanonicalIntoByModule(array &$byModule, array &$all, array $canonical): void
{
    $allByKey = [];
    foreach ($all as $index => $entity) {
        $allByKey[canonicalKey($entity)] = $index;
    }

    foreach ($byModule as $moduleName => $entities) {
        $updated = [];
        foreach ($entities as $entity) {
            $key = canonicalKey($entity);
            $updated[] = $canonical[$key] ?? $entity;
            if (isset($allByKey[$key])) {
                $all[$allByKey[$key]] = $canonical[$key] ?? $entity;
            }
        }
        $byModule[$moduleName] = $updated;
    }
}

function nodeDoc(?string $doc): ?string
{
    return ($doc !== null && $doc !== '') ? $doc : null;
}

function exportSectionForName(Ast\Program $program, string $name): ?string
{
    if ($program->exports === null) {
        return null;
    }

    foreach ($program->exports as $item) {
        if (($item['name'] ?? '') !== $name) {
            continue;
        }
        if (isset($item['section'])) {
            return (string) $item['section'];
        }
    }

    return null;
}

/** @return list<string> */
function reexportedClassNames(Ast\Program $program): array
{
    $local = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\ClassDecl) {
            $local[$item->name] = true;
        }
    }

    $names = [];
    foreach ($program->exports ?? [] as $item) {
        if (($item['tag'] ?? '') !== 'type') {
            continue;
        }
        $name = $item['name'];
        if (!isset($local[$name])) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * @param array<string, DocEntity> $canonical
 * @return list<DocEntity>
 */
function reexportedClassHomes(Ast\Program $program, array $canonical, string $facadeModule): array
{
    $classesByName = [];
    foreach ($canonical as $entity) {
        if ($entity->kind === 'class') {
            $classesByName[$entity->name] = $entity;
        }
    }

    $homes = [];
    foreach (reexportedClassNames($program) as $name) {
        $home = $classesByName[$name] ?? null;
        if ($home === null || $home->module === $facadeModule) {
            continue;
        }
        $homes[] = $home;
    }

    return $homes;
}

/** @param array<string, DocEntity> $canonical */
function attachClassReexportAliases(array &$canonical, Ast\Program $program, string $facadeModule): void
{
    foreach (reexportedClassHomes($program, $canonical, $facadeModule) as $home) {
        $key = canonicalKey($home);
        if (!isset($canonical[$key])) {
            continue;
        }

        $entity = $canonical[$key];
        $aliases = array_values(array_unique([...$entity->aliases, $facadeModule]));
        $canonical[$key] = copyDocEntity($entity, ['aliases' => $aliases]);
    }
}

function makeEntity(
    string $module,
    string $name,
    string $kind,
    ?string $signature,
    ?string $doc,
    ?string $section,
    ?string $parentName = null,
    ?string $declKind = null,
    ?int $sourceLine = null,
    ?string $sourcePath = null,
    ?string $fixity = null,
): DocEntity {
    $href = entityHref($module, $name, $declKind, $parentName);

    return new DocEntity(
        $module,
        $name,
        $kind,
        $signature,
        $doc,
        $section,
        $href,
        [],
        $parentName,
        $declKind,
        $sourceLine,
        $sourcePath,
        $fixity,
    );
}

/**
 * @param array<string, mixed> $overrides
 */
function copyForReexport(DocEntity $entity, string $facadeModule, ?string $section): DocEntity
{
    return copyDocEntity($entity, [
        'module' => $facadeModule,
        'href' => entityHref($facadeModule, $entity->name, $entity->declKind, $entity->parentName),
        'section' => $section ?? $entity->section,
        'aliases' => [],
    ]);
}

/**
 * @param array<string, list<DocEntity>> $byModule
 * @return list<DocEntity>
 */
function reexportEntityBundle(
    DocEntity $home,
    string $facadeModule,
    array $byModule,
    ?string $section,
): array {
    $bundle = [copyForReexport($home, $facadeModule, $section)];
    if (!in_array($home->kind, ['type', 'class'], true)) {
        return $bundle;
    }

    foreach ($byModule[$home->module] ?? [] as $child) {
        if ($child->parentName !== $home->name || $child->module !== $home->module) {
            continue;
        }
        $bundle[] = copyForReexport($child, $facadeModule, null);
    }

    return $bundle;
}

function entityHrefMatchesModule(DocEntity $entity): bool
{
    return str_starts_with($entity->href, modulePageName($entity->module));
}

function copyDocEntity(DocEntity $entity, array $overrides = []): DocEntity
{
    return new DocEntity(
        $overrides['module'] ?? $entity->module,
        $overrides['name'] ?? $entity->name,
        $overrides['kind'] ?? $entity->kind,
        array_key_exists('signature', $overrides) ? $overrides['signature'] : $entity->signature,
        array_key_exists('doc', $overrides) ? $overrides['doc'] : $entity->doc,
        array_key_exists('section', $overrides) ? $overrides['section'] : $entity->section,
        $overrides['href'] ?? $entity->href,
        $overrides['aliases'] ?? $entity->aliases,
        array_key_exists('parentName', $overrides) ? $overrides['parentName'] : $entity->parentName,
        array_key_exists('declKind', $overrides) ? $overrides['declKind'] : $entity->declKind,
        array_key_exists('sourceLine', $overrides) ? $overrides['sourceLine'] : $entity->sourceLine,
        array_key_exists('sourcePath', $overrides) ? $overrides['sourcePath'] : $entity->sourcePath,
        array_key_exists('fixity', $overrides) ? $overrides['fixity'] : $entity->fixity,
    );
}

/** @param array<string, mixed> $unit @return list<DocEntity> */
function entitiesFromSyntheticUnit(string $moduleName, array $unit): array
{
    $entities = [];
    $localTypes = $unit['localTypes'] ?? [];

    $typeNames = array_keys($localTypes['data'] ?? []);
    sort($typeNames);
    foreach ($typeNames as $typeName) {
        $entities[] = makeEntity(
            $moduleName,
            $typeName,
            'type',
            'primitive type ' . $typeName,
            null,
            null,
            null,
            'primtype',
        );
    }

    $valueNames = array_keys($localTypes['env'] ?? []);
    sort($valueNames);
    foreach ($valueNames as $name) {
        $scheme = $localTypes['env'][$name] ?? null;
        if (!$scheme instanceof Scheme) {
            continue;
        }
        $entities[] = makeEntity(
            $moduleName,
            $name,
            'primop',
            $name . ' :: ' . schemeToString($scheme),
            null,
            null,
            null,
            'primop',
        );
    }

    return $entities;
}

function entityHref(
    string $module,
    string $name,
    ?string $declKind = null,
    ?string $parentName = null,
): string {
    return modulePageName($module) . '#' . entityAnchorId($module, $name, $declKind, $parentName);
}

function modulePageName(string $moduleName): string
{
    return str_replace('.', '-', $moduleName) . '.html';
}

/** @return array<string, mixed> */
function entityToSearchRow(DocEntity $entity): array
{
    return [
        'module' => $entity->module,
        'name' => $entity->name,
        'kind' => $entity->kind,
        'signature' => $entity->signature,
        'doc' => $entity->doc,
        'section' => $entity->section,
        'href' => $entity->href,
        'aliases' => $entity->aliases,
    ];
}

/**
 * Browser-facing search payload (no per-module duplicate blob).
 *
 * @return array{version: int, revision: string, search: list<array<string, mixed>>}
 */
function searchIndexToJson(DocIndex $index): array
{
    $search = [];
    foreach ($index->search as $entity) {
        $search[] = entityToSearchRow($entity);
    }

    return [
        'version' => docIndexSchemaVersion(),
        'revision' => searchIndexRevision($search),
        'search' => $search,
    ];
}

/** @param list<array<string, mixed>> $search */
function searchIndexRevision(array $search): string
{
    $payload = json_encode($search, JSON_UNESCAPED_UNICODE);

    return hash('crc32b', $payload !== false ? $payload : '');
}

/**
 * @return array{
 *   version: int,
 *   modules: array<string, list<array<string, mixed>>>,
 *   search: list<array<string, mixed>>,
 *   facadeBackends: array<string, array<string, string>>,
 *   implFacades: array<string, array{backend: string, facade: string}>,
 *   rootPrefix: string
 * }
 */
function indexToJson(DocIndex $index): array
{
    $modules = [];
    foreach ($index->byModule as $moduleName => $entities) {
        $rows = [];
        foreach ($entities as $entity) {
            $rows[] = [
                'module' => $entity->module,
                'name' => $entity->name,
                'kind' => $entity->kind,
                'signature' => $entity->signature,
                'doc' => $entity->doc,
                'section' => $entity->section,
                'href' => $entity->href,
                'aliases' => $entity->aliases,
                'parentName' => $entity->parentName,
                'declKind' => $entity->declKind,
                'sourceLine' => $entity->sourceLine,
                'sourcePath' => $entity->sourcePath,
                'fixity' => $entity->fixity,
            ];
        }
        $modules[$moduleName] = $rows;
    }

    $search = [];
    foreach ($index->search as $entity) {
        $search[] = entityToSearchRow($entity);
    }

    return ['version' => docIndexSchemaVersion(), 'modules' => $modules, 'search' => $search,
        'facadeBackends' => $index->facadeBackends,
        'implFacades' => $index->implFacades,
        'rootPrefix' => $index->rootPrefix];
}

/**
 * @param array{
 *   version: int,
 *   modules: array<string, list<array<string, mixed>>>,
 *   search?: list<array<string, mixed>>,
 *   facadeBackends?: array<string, array<string, string>>,
 *   implFacades?: array<string, array{backend: string, facade: string}>,
 *   rootPrefix?: string
 * } $data
 */
function indexFromJson(array $data): DocIndex
{
    $byModule = [];
    $all = [];
    foreach ($data['modules'] as $moduleName => $rows) {
        $entities = [];
        foreach ($rows as $row) {
            $entity = entityFromRow($row);
            $entities[] = $entity;
            $all[] = $entity;
        }
        $byModule[$moduleName] = $entities;
    }

    $search = [];
    foreach ($data['search'] ?? [] as $row) {
        $search[] = entityFromRow($row);
    }

    return new DocIndex(
        $byModule,
        $all,
        $search,
        isset($data['facadeBackends']) && \is_array($data['facadeBackends'])
            ? normalizeFacadeBackends($data['facadeBackends'])
            : [],
        isset($data['implFacades']) && \is_array($data['implFacades'])
            ? normalizeImplFacades($data['implFacades'])
            : [],
        isset($data['rootPrefix']) && \is_string($data['rootPrefix']) ? $data['rootPrefix'] : '',
    );
}

/** @param array<mixed, mixed> $raw @return array<string, array<string, string>> */
function normalizeFacadeBackends(array $raw): array
{
    $out = [];
    foreach ($raw as $facade => $map) {
        if (!\is_string($facade) || !\is_array($map)) {
            continue;
        }
        $backends = [];
        foreach ($map as $backend => $impl) {
            if (\is_string($backend) && \is_string($impl)) {
                $backends[$backend] = $impl;
            }
        }
        if ($backends !== []) {
            $out[$facade] = $backends;
        }
    }

    return $out;
}

/** @param array<mixed, mixed> $raw @return array<string, array{facade: string, backend: string}> */
function normalizeImplFacades(array $raw): array
{
    $out = [];
    foreach ($raw as $impl => $info) {
        if (!\is_string($impl) || !\is_array($info)) {
            continue;
        }
        $facade = $info['facade'] ?? null;
        $backend = $info['backend'] ?? null;
        if (\is_string($facade) && \is_string($backend)) {
            $out[$impl] = ['facade' => $facade, 'backend' => $backend];
        }
    }

    return $out;
}

/** @param array<string, mixed> $row */
function entityFromRow(array $row): DocEntity
{
    return new DocEntity(
        (string) $row['module'],
        (string) $row['name'],
        (string) $row['kind'],
        isset($row['signature']) ? (string) $row['signature'] : null,
        isset($row['doc']) ? (string) $row['doc'] : null,
        isset($row['section']) ? (string) $row['section'] : null,
        (string) $row['href'],
        isset($row['aliases']) && \is_array($row['aliases'])
            ? array_values(array_map('strval', $row['aliases']))
            : [],
        isset($row['parentName']) ? (string) $row['parentName'] : null,
        isset($row['declKind']) ? (string) $row['declKind'] : null,
        isset($row['sourceLine']) ? (int) $row['sourceLine'] : null,
        isset($row['sourcePath']) ? (string) $row['sourcePath'] : null,
        isset($row['fixity']) ? (string) $row['fixity'] : null,
    );
}

function indexCachePath(string $inputPath, array $libDirs): string
{
    $resolvedLibDirs = resolveLibraryDirs($libDirs);
    $key = hash(
        'sha256',
        $inputPath . '|' . implode(',', $resolvedLibDirs) . '|docs-v' . docIndexSchemaVersion(),
    );

    return cacheGenerationDir() . '/docs-index/' . substr($key, 0, 16) . '.json';
}

function loadOrBuildIndex(
    string $inputPath,
    array $libDirs,
    bool $rebuild = false,
    ?string &$sourceFingerprint = null,
): DocIndex {
    $docClosure = resolveDocFileClosure($inputPath, $libDirs);
    $files = $docClosure[1];
    $mtimeFingerprint = docSourceFingerprintMtime($files);
    $cachePath = indexCachePath($inputPath, $libDirs);
    if (!$rebuild && \is_file($cachePath)) {
        $raw = file_get_contents($cachePath);
        if (\is_string($raw)) {
            $data = json_decode($raw, true);
            if (\is_array($data) && ($data['version'] ?? 0) === docIndexSchemaVersion()) {
                $cachedSource = (string) ($data['sourceFingerprint'] ?? '');
                if (($data['mtimeFingerprint'] ?? '') === $mtimeFingerprint
                    || $cachedSource === docSourceFingerprint($files)) {
                    try {
                        $index = indexFromJson($data);
                        $sourceFingerprint = $cachedSource !== ''
                            ? $cachedSource
                            : docSourceFingerprint($files);

                        return $index;
                    } catch (\Throwable) {
                        // Fall through and rebuild corrupt cache payloads.
                    }
                }
            }
        }
    }

    $contentFingerprint = docSourceFingerprint($files);
    $sourceFingerprint = $contentFingerprint;

    $project = loadDocProject($inputPath, $libDirs, $docClosure);
    $prepared = $project['prepared'];
    $modules = $project['modules'];
    \fwrite(STDERR, 'mogdoc: loaded ' . count($modules) . " module(s)\n");
    \fwrite(STDERR, 'mogdoc: type-checked ' . count($prepared->checked) . " module(s)\n");
    $index = buildIndex($modules, $prepared->checked, $prepared);
    \fwrite(STDERR, 'mogdoc: indexed ' . count($index->search) . " searchable entries\n");
    $payload = json_encode(
        [
            ...indexToJson($index),
            'mtimeFingerprint' => $mtimeFingerprint,
            'sourceFingerprint' => $contentFingerprint,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
    );
    if ($payload !== false) {
        atomicWrite($cachePath, $payload);
    }

    return $index;
}
