<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Backend\compileBackend;
use function Moggi\Errors\appendDidYouMean;
use function Moggi\Paths\canonicalPath;
use function Moggi\Paths\canonicalSeparators;
use function Moggi\Syntax\Ast\moduleName;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\importedFixityForImports;
use function Moggi\Syntax\Parser\mergeFixity;
use function Moggi\Syntax\Parser\parseModuleHeader;

/**
 * Module graph, prelude, fixity, and dependency-ordering helpers.
 *
 * This layer decides which source files participate in a module compilation
 * and in what order they must be processed. It deliberately does not build
 * type environments, export tables, or PHP import plans, and it does not
 * decide where the standard library lives (see library.php for that).
 */

/** @return list<string> */
function findMogFilesUnder(string $rootDir): array
{
    $root = resolvePath($rootDir);
    if (!\is_dir($root)) {
        return [];
    }

    $directories = new \RecursiveDirectoryIterator(
        $root,
        \FilesystemIterator::SKIP_DOTS,
    );

    $filtered = new \RecursiveCallbackFilterIterator(
        $directories,
        static function (\SplFileInfo $current): bool {
            if (!$current->isDir()) {
                return true;
            }

            if ($current->isLink()) {
                return false;
            }

            $name = $current->getFilename();
            if ($name !== '' && $name[0] === '.') {
                return false;
            }

            return !\in_array($name, ['var', 'dist', 'build', 'node_modules', 'vendor'], true);
        },
    );

    $files = [];
    try {
        foreach (new \RecursiveIteratorIterator($filtered) as $file) {
            if ($file->isFile() && $file->getExtension() === 'mog') {
                $files[] = $file->getPathname();
            }
        }
    } catch (\UnexpectedValueException) {
    }

    sort($files);

    return $files;
}

function isModuleSourceProgram(Ast\Program $program): bool
{
    return $program->module !== null || $program->imports !== [];
}

/**
 * Build-routing counterpart of `isModuleSourceProgram`, using only a header parse.
 *
 * @param array<string, mixed> $header
 */
function headerIsModuleSource(array $header): bool
{
    return ($header['module'] ?? null) !== null || ($header['imports'] ?? []) !== [];
}

/**
 * Whether `$rootDir` can be a module root: its modules only import each other or `$known`.
 *
 * @param list<string> $mogPaths
 * @param array<string, mixed> $known module names resolvable outside the directory
 */
function moduleProjectImportsResolve(string $rootDir, array $mogPaths, array $known = []): bool
{
    $root = resolvePath($rootDir);
    if (!\is_dir($root)) {
        return false;
    }

    $modules = [];
    $programs = [];
    foreach ($mogPaths as $path) {
        try {
            $header = cachedModuleHeader($path);
        } catch (LexError|ParseError|TypeError|\RuntimeException) {
            return false;
        }

        $programs[] = $header;
        $moduleName = $header['module'] ?? null;
        if ($moduleName === null) {
            continue;
        }

        $modules[$moduleName] = true;
    }

    foreach ($programs as $header) {
        foreach ($header['imports'] as $import) {
            $importName = Ast\moduleName($import->path);
            if (!isset($modules[$importName]) && !isset($known[$importName])) {
                return false;
            }
        }
    }

    return $modules !== [];
}

/** @return array<string, string> module name => path */
function moduleIndexFromPaths(array $paths): array
{
    $index = [];
    foreach ($paths as $path) {
        try {
            $header = cachedModuleHeader($path);
        } catch (LexError|ParseError) {
            continue;
        }
        $moduleName = $header['module'] ?? null;
        if ($moduleName === null) {
            continue;
        }

        $index[$moduleName] = $path;
    }

    return $index;
}

function isStandaloneWorkspaceTest(string $filePath): bool
{
    $file = resolvePath($filePath);
    if (!\is_file($file)) {
        return false;
    }

    $file = \str_replace('\\', '/', $file);
    if (!str_contains($file, '/tests/')) {
        return false;
    }

    if (str_contains($file, '/tests/modules/')) {
        return false;
    }

    if (\is_file(\dirname($file) . '/Main.mog')) {
        return false;
    }

    return true;
}

/**
 * Add the implicit `import Prelude` unless the module already imports it, or
 * unless Prelude itself (transitively) depends on this module — injecting there
 * would make the import graph cyclic. Modules with `NoImplicitPrelude` also skip.
 *
 * @param list<Ast\ImportDecl> $imports
 * @param array<string, true>|null $availableModules
 * @param array<string, true> $language LANGUAGE flags from the module header
 * @return list<Ast\ImportDecl>
 */
function injectPreludeImport(
    array $imports,
    string $path,
    ?array $availableModules = null,
    array $language = [],
): array {
    if ($language === []) {
        $real = resolvePath($path);
        if (\is_file($real)) {
            $language = cachedModuleHeader($real)['language'] ?? [];
        }
    }

    if (!empty($language['NoImplicitPrelude'])) {
        return $imports;
    }

    if (hasPreludeImport($imports)) {
        return $imports;
    }

    if ($availableModules !== null && !isset($availableModules['Prelude'])) {
        return $imports;
    }

    if (isset(preludeDependencyClosure($path)[moduleNameForPath($path)])) {
        return $imports;
    }

    return [...$imports, syntheticPreludeImport()];
}

function moduleNameForPath(string $path): ?string
{
    $real = resolvePath($path);
    if (!\is_file($real)) {
        return null;
    }

    return cachedModuleHeader($real)['module'] ?? null;
}

/**
 * Prelude plus everything Prelude transitively depends on, as a module-name set.
 *
 * Follows the explicit imports written in each source plus the backend
 * implementation each facade routes to, since a facade's implementation is as
 * much a dependency as an import. Only explicit edges are followed, so the walk
 * terminates regardless of where the implicit import would otherwise be added.
 *
 * @return array<string, true>
 */
function preludeDependencyClosure(string $fromPath): array
{
    $root = locateStdlibRoot($fromPath);
    if ($root === null) {
        return [];
    }

    static $closures = [];

    $index = stdlibModuleIndex($root);
    $preludePath = $index['Prelude'] ?? null;
    if ($preludePath === null) {
        return [];
    }

    $key = $preludePath . ':' . stdlibMaxMtime($root);
    if (isset($closures[$key])) {
        return $closures[$key];
    }

    $closure = ['Prelude' => true];
    $queue = ['Prelude'];
    while ($queue !== []) {
        $moduleName = array_shift($queue);
        $modulePath = $index[$moduleName] ?? null;
        if ($modulePath === null) {
            continue;
        }

        $header = cachedModuleHeader($modulePath);
        $dependencies = \array_map(
            static fn (Ast\ImportDecl $import): string => Ast\moduleName($import->path),
            $header['imports'],
        );

        $implName = resolveImplModuleName($header['backendMap'] ?? []);
        if ($implName !== null) {
            $dependencies[] = $implName;
        }

        foreach ($dependencies as $dependency) {
            if (!isset($closure[$dependency])) {
                $closure[$dependency] = true;
                $queue[] = $dependency;
            }
        }
    }

    $closures[$key] = $closure;

    return $closure;
}

/** @param list<Ast\ImportDecl> $imports */
function hasPreludeImport(array $imports): bool
{
    foreach ($imports as $import) {
        if (Ast\moduleName($import->path) === 'Prelude') {
            return true;
        }
    }

    return false;
}

function syntheticPreludeImport(): Ast\ImportDecl
{
    return new Ast\ImportDecl(['Prelude'], 'qualified', [], [], null, true);
}

/**
 * @param array<string, array{imports: list<array<string, mixed>>}> $pending
 * @param array<string, array<string, array{assoc: string, prec: int}>> $localFixityByModule
 * @return array<string, array<string, array{assoc: string, prec: int}>>
 */
function computeEffectiveFixityByModule(array $pending, array $localFixityByModule): array
{
    $effective = $localFixityByModule;

    do {
        $changed = false;
        foreach ($pending as $moduleName => $info) {
            $merged = mergeFixity(
                $localFixityByModule[$moduleName] ?? [],
                importedFixityForImports($info['imports'], $effective),
            );

            if (($effective[$moduleName] ?? []) !== $merged) {
                $effective[$moduleName] = $merged;
                $changed = true;
            }
        }
    } while ($changed);

    return $effective;
}

/**
 * Identity for on-disk (or LSP overlay) sources.
 *
 * Stable lib files key on mtime+size. Overlay rewrites under `moggi-lsp-vfs`
 * often keep the same size within the same second, so those paths hash content.
 */
function sourceIdentityKey(string $path): string
{
    $real = resolvePath($path);
    $overlay = str_contains($real, '/moggi-lsp-vfs/')
        || str_contains($real, DIRECTORY_SEPARATOR . 'moggi-lsp-vfs' . DIRECTORY_SEPARATOR);
    if ($overlay) {
        $hash = @hash_file('xxh3', $real);

        return $real . ':h:' . ($hash !== false ? $hash : '0');
    }
    clearstatcache(true, $real);

    return $real . ':' . (filemtime($real) ?: 0) . ':' . (filesize($real) ?: 0);
}

/** @return array<string, mixed> */
function cachedModuleHeader(string $path): array
{
    static $headers = [];

    $realPath = resolvePath($path);
    if (!\is_file($realPath)) {
        throw new TypeError("cannot resolve `{$path}`");
    }

    $key = sourceIdentityKey($realPath);
    if (isset($headers[$key])) {
        return $headers[$key];
    }

    $source = file_get_contents($realPath);
    if ($source === false) {
        throw new \RuntimeException("cannot read {$realPath}");
    }

    $header = parseModuleHeader(lex($source, $realPath), $source, $realPath);
    $header['__source'] = $source;
    $headers[$key] = $header;

    return $header;
}

/**
 * The closure of one file: the standard library it compiles against, the workspace
 * modules around it, and everything they import.
 *
 * A workspace module that shares its name with a library module is refused when the
 * walk reaches that name ({@see refuseAmbiguousModule}) rather than replaced in the
 * index: the name resolves to one file, and which one must follow from the name. The
 * file being compiled is not reached by a name, though -- the caller named it -- so
 * the entry keeps its own name whatever else offers it.
 *
 * @return array{0: list<string>, 1: string}
 */
function moduleFileClosure(string $path): array
{
    $realPath = resolvePath($path);
    if (!\is_file($realPath)) {
        throw new TypeError("cannot resolve `{$path}`");
    }

    $byModule = [...syntheticCompilerModuleIndex()];
    $duplicates = [];

    $stdlibRoot = locateStdlibRoot($path);
    if ($stdlibRoot !== null) {
        warnIfStdlibAutoDiscovered($path, $stdlibRoot);
        [$stdlibPaths, $stdlibDuplicates] = stdlibModuleScan($stdlibRoot);
        $byModule = [...$byModule, ...$stdlibPaths];
        $duplicates = $stdlibDuplicates;
    }

    if (!isStandaloneWorkspaceTest($path)) {
        $localRoot = findBoundedModuleRoot($path);
        if ($localRoot !== null) {
            foreach (moduleIndexFromPaths(findMogFilesUnder($localRoot)) as $localName => $localPath) {
                $existing = $byModule[$localName] ?? null;
                if ($existing === null || canonicalPath($existing) === canonicalPath($localPath)) {
                    $byModule[$localName] = $localPath;
                    continue;
                }
                $duplicates[$localName][] = $localPath;
            }
        }
    }

    $start = cachedModuleHeader($realPath);
    $startModule = $start['module'] ?? null;
    if ($startModule === null) {
        $source = file_get_contents($realPath);
        throw new TypeError(
            "missing `module` declaration in {$realPath}",
            $realPath,
            $source === false ? '' : $source,
        );
    }

    // The file the caller named is the module being compiled. A sibling of the same
    // name (another program under the same root) is not an import of it, so the name
    // has one meaning here and neither is a clash to refuse.
    unset($duplicates[$startModule]);
    $byModule[$startModule] = $realPath;

    $implOwners = backendImplOwners($byModule);

    $needed = [];
    $queue = [$startModule];
    $enqueuedBy = [];
    $queueHead = 0;
    while ($queueHead < count($queue)) {
        $moduleName = $queue[$queueHead++];
        if (isset($needed[$moduleName])) {
            continue;
        }

        if (isset($duplicates[$moduleName])) {
            refuseAmbiguousModule(
                $moduleName,
                [$byModule[$moduleName], ...$duplicates[$moduleName]],
                importSiteAt($enqueuedBy[$moduleName] ?? null, $byModule[$moduleName]),
            );
        }

        $modulePath = $byModule[$moduleName] ?? null;
        if ($modulePath === null) {
            $origin = $enqueuedBy[$moduleName] ?? null;
            $requiredBy = $origin['module'] ?? $startModule;

            $originSource = '';
            if ($origin !== null) {
                $read = file_get_contents($origin['path']);
                $originSource = $read === false ? '' : $read;
            }

            throw new TypeError(
                appendDidYouMean(
                    "unknown module `{$moduleName}` imported by `{$requiredBy}`",
                    $moduleName,
                    \array_keys($byModule),
                ),
                $origin['path'] ?? '',
                $originSource,
                $origin['line'] ?? 0,
                $origin['col'] ?? 0,
                $origin['endCol'] ?? 0,
            );
        }

        $needed[$moduleName] = $modulePath;

        if (isSyntheticCompilerPath($modulePath)) {
            continue;
        }

        $header = cachedModuleHeader($modulePath);
        $program = [
            'module' => $header['module'],
            'imports' => $header['imports'],
            'backendMap' => $header['backendMap'] ?? [],
        ];
        foreach (injectPreludeImport(
            $program['imports'],
            $modulePath,
            $byModule,
            $header['language'] ?? [],
        ) as $import) {
            $importName = Ast\moduleName($import->path);
            $owner = $implOwners[$importName] ?? null;
            if ($owner !== null && !isset($implOwners[$moduleName])) {
                throw backendImplImportError(
                    $importName,
                    $owner,
                    $modulePath,
                    $header['__source'] ?? '',
                    $import,
                );
            }

            if (!isset($enqueuedBy[$importName]) && $importName !== $startModule) {
                $enqueuedBy[$importName] = [
                    'module' => $moduleName,
                    'path' => $modulePath,
                    'line' => $import->line,
                    'col' => $import->col,
                    'endCol' => $import->endCol,
                ];
            }
            if (!isset($needed[$importName])) {
                $queue[] = $importName;
            }
        }

        $implName = resolveImplModuleName($header['backendMap'] ?? []);
        if ($implName !== null && !isset($needed[$implName])) {
            $queue[] = $implName;
        }

        foreach ($byModule as $facadeName => $facadePath) {
            if ($facadeName === $moduleName) {
                continue;
            }

            if (isSyntheticCompilerPath($facadePath)) {
                continue;
            }

            $facadeHeader = cachedModuleHeader($facadePath);
            $facadeBackendMap = $facadeHeader['backendMap'] ?? [];
            if ($facadeBackendMap === []) {
                continue;
            }

            $matches = false;
            foreach ($facadeBackendMap as $impl) {
                if ($impl === $moduleName) {
                    $matches = true;
                    break;
                }
            }

            if ($matches && !isset($needed[$facadeName])) {
                $queue[] = $facadeName;
            }
        }
    }

    $files = [];
    foreach ($needed as $modulePath) {
        if (!isSyntheticCompilerPath($modulePath)) {
            $files[] = $modulePath;
        }
    }
    $root = commonPathPrefix($files);
    if ($root === '') {
        throw new \RuntimeException('cannot determine project root for module closure');
    }

    return [$files, $root];
}

/** @return array{0: list<string>, 1: string} */
function moduleFileClosureCached(string $path): array
{
    static $closures = [];

    $realPath = resolvePath($path);
    if (!\is_file($realPath)) {
        return moduleFileClosure($path);
    }

    $stdlibRoot = locateStdlibRoot($path);
    $stdlibMtime = $stdlibRoot !== null ? stdlibMaxMtime($stdlibRoot) : 0;
    $backend = compileBackend();

    $key = $realPath . ':' . (filemtime($realPath) ?: 0) . ':' . $stdlibMtime . ':' . $backend;
    if (isset($closures[$key])) {
        return $closures[$key];
    }

    $closure = moduleFileClosure($path);
    $closures[$key] = $closure;

    return $closure;
}

function findBoundedModuleRoot(string $filePath): ?string
{
    $file = realpath($filePath);
    if ($file === false) {
        return null;
    }

    $stdlibRoot = locateStdlibRoot($filePath);
    $workspaceRoot = $stdlibRoot !== null ? realpath(dirname($stdlibRoot)) : null;
    $searchDirOnly = $workspaceRoot === null || $workspaceRoot === false;
    $knownModules = $stdlibRoot === null
        ? syntheticCompilerModuleIndex()
        : [...syntheticCompilerModuleIndex(), ...stdlibModuleIndex($stdlibRoot)];

    $dir = dirname($file);
    while ($dir !== false) {
        $realDir = realpath($dir);
        if (
            $workspaceRoot !== false
            && $workspaceRoot !== null
            && $realDir !== false
            && $realDir !== $workspaceRoot
            && !str_starts_with($realDir, $workspaceRoot . DIRECTORY_SEPARATOR)
        ) {
            break;
        }

        if (basename($dir) === 'tests' && \is_dir($dir . DIRECTORY_SEPARATOR . 'errors')) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
            continue;
        }

        $mogs = findMogFilesUnder($dir);
        $paths = array_values(array_filter(\array_map(static fn (string $p): ?string => realpath($p), $mogs)));
        if ($paths !== [] && \in_array($file, $paths, true) && moduleProjectImportsResolve($dir, $mogs, $knownModules)) {
            if (!\is_dir($dir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'errors')) {
                return $dir;
            }
        }

        if ($searchDirOnly) {
            break;
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }

        $dir = $parent;
    }

    return null;
}

/** @param list<string> $paths */
function commonPathPrefix(array $paths): string
{
    if ($paths === []) {
        return '';
    }

    $dirs = \array_map(static fn (string $path): string => dirname($path), $paths);
    $prefix = $dirs[0];
    foreach ($dirs as $dir) {
        while (
            $prefix !== ''
            && $prefix !== DIRECTORY_SEPARATOR
            && !str_starts_with($dir, $prefix . DIRECTORY_SEPARATOR)
            && $dir !== $prefix
        ) {
            $parent = dirname($prefix);
            if ($parent === $prefix) {
                $prefix = DIRECTORY_SEPARATOR;
                break;
            }
            $prefix = $parent;
        }
    }

    return $prefix === '.' ? dirname($paths[0]) : $prefix;
}

/**
 * The merged module index across library roots, keeping the names more than one
 * root offers.
 *
 * A name two roots both offer is not a fault in itself: an installed tree can
 * carry two packages that each ship a module called `A`, and a program that never
 * imports `A` is unaffected. Which file the name means is a question only a
 * program reaches, so the clash is recorded here and refused there
 * ({@see refuseAmbiguousModule}).
 *
 * The first root wins the index entry, which is what a name only one root offers
 * needs; a name two roots offer is never resolved from this index alone. A root
 * listed twice, or a root nested in another, offers the same file and so offers
 * nothing twice.
 *
 * @param list<string> $libDirs
 * @return array{0: array<string, string>, 1: array<string, list<string>>}
 *   the module name => source path table, then the name => other paths that offer
 *   it table in root order
 */
function libraryModuleScan(array $libDirs): array
{
    $paths = [];
    $duplicates = [];
    foreach ($libDirs as $dir) {
        foreach (moduleIndexFromPaths(findMogFilesUnder($dir)) as $moduleName => $path) {
            $existing = $paths[$moduleName] ?? null;
            if ($existing === null) {
                $paths[$moduleName] = $path;
                continue;
            }

            if (canonicalPath($existing) !== canonicalPath($path)) {
                $duplicates[$moduleName][] = $path;
            }
        }
    }

    return [$paths, $duplicates];
}

/**
 * Refuse a module name the program reaches that more than one root offers.
 *
 * The name has to mean one file, and no rule can pick the file for the author: a
 * project module that stood in for a library module of its name would change what
 * every import of that name means, silently, and so would the first of two roots
 * in an installed tree. The list is every file the name could mean, so the one to
 * rename is in the message, and the location is the import that reached the name
 * -- or the module itself when the build compiles it and no import did.
 *
 * `Prelude` gets its own sentence: the implicit import resolves by name, so a
 * project module of that name would decide what every module in the program gets
 * by default.
 *
 * @param list<string> $candidates every file the name could resolve to
 * @param array{path: string, source: string, line: int, col: int, endCol: int} $at
 */
function refuseAmbiguousModule(string $moduleName, array $candidates, array $at): never
{
    $roots = \array_map(static fn (string $path): string => canonicalSeparators($path), $candidates);
    $last = \array_pop($roots);
    $listed = $roots === [] ? $last : \implode(', ', $roots) . ' and ' . $last;
    $count = \count($candidates) === 2 ? 'two roots' : 'several roots';

    $hint = $moduleName === 'Prelude'
        ? "\n\n`Prelude` is the standard library's module, and the implicit `import Prelude` has to find "
            . "it there: a project's own prelude belongs under another name, imported explicitly with "
            . '`NoImplicitPrelude`'
        : '';

    throw new TypeError(
        "module `{$moduleName}` is offered by {$count} ({$listed})"
            . "\n\na module name resolves to one file: rename one of them"
            . $hint,
        $at['path'],
        $at['source'],
        $at['line'],
        $at['col'],
        $at['endCol'],
    );
}

/**
 * The location of a module's own header, for a refusal that no import led to.
 *
 * @param array<string, mixed> $header
 * @return array{path: string, source: string, line: int, col: int, endCol: int}
 */
function moduleHeaderAt(string $path, array $header): array
{
    [$line, $col, $endCol] = moduleHeaderPoint($header);

    return [
        'path' => $path,
        'source' => (string) ($header['__source'] ?? ''),
        'line' => $line,
        'col' => $col,
        'endCol' => $endCol,
    ];
}

/**
 * The import that reached a module, or the module's own header when it was an entry
 * (the build compiles it, so no import led to it).
 *
 * @param ?array{module: string, path: string, line: int, col: int, endCol?: int} $origin
 * @return array{path: string, source: string, line: int, col: int, endCol: int}
 */
function importSiteAt(?array $origin, string $fallbackPath): array
{
    if ($origin === null) {
        return moduleHeaderAt($fallbackPath, cachedModuleHeader($fallbackPath));
    }

    $read = @file_get_contents($origin['path']);

    return [
        'path' => $origin['path'],
        'source' => $read === false ? '' : $read,
        'line' => (int) $origin['line'],
        'col' => (int) $origin['col'],
        'endCol' => (int) ($origin['endCol'] ?? $origin['col']),
    ];
}

/**
 * The standard library modules a program carries by default -- the closure of `Prelude` --
 * minus the ones `$files` already provides.
 *
 * Which instances exist is a property of the project a module is checked in, not of the
 * module's import list. `Data.Either`'s `Show` instance compares the precedence argument
 * with `>` at `Int`, and `Ord Int` is declared by `Data.Int`, which the module neither
 * imports nor needs to: a program that imports `Data.Either` has the instance because
 * *some* module in it pulled `Data.Int` in, and every program has `Prelude`. A whole-program
 * build names every module it carries; a checker looking at one file at a time has to name
 * the ones a program would have anyway, and this returns them.
 *
 * The closure of `Prelude` rather than the whole library: an instance for a library module
 * nothing imports is not in a program's project, and adding it would report a duplicate for
 * a module that declares its own (`tests/lsp/UnusedInstanceHead.mog` does exactly that).
 *
 * A stdlib module the workspace also provides is refused ({@see refuseModuleShadowing}): the name
 * resolves to one of the two, and which one is exactly what a checker must not have to guess.
 * The same file reached from both sides (the workspace *is* the library root) is one module, not
 * a collision.
 *
 * @param list<string> $files
 * @return list<string>
 */
function stdlibSourcesMissingFrom(array $files, ?string $stdlibDir): array
{
    if ($stdlibDir === null) {
        return [];
    }

    $seed = rtrim($stdlibDir, '/') . '/Prelude.mog';
    if (!\is_file($seed)) {
        return [];
    }

    try {
        [$closure] = moduleFileClosureCached($seed);
    } catch (\Throwable) {
        return [];
    }

    $have = [];
    foreach ($files as $file) {
        $header = cachedModuleHeader($file);
        $module = $header['module'] ?? null;
        if (\is_string($module)) {
            $have[$module] = $file;
        }
    }

    $missing = [];
    foreach ($closure as $file) {
        $header = cachedModuleHeader($file);
        $module = $header['module'] ?? null;
        if (!\is_string($module)) {
            continue;
        }

        $provider = $have[$module] ?? null;
        if ($provider !== null) {
            if (canonicalPath($provider) !== canonicalPath($file)) {
                refuseAmbiguousModule(
                    $module,
                    [$provider, $file],
                    moduleHeaderAt($provider, cachedModuleHeader($provider)),
                );
            }
            continue;
        }

        $missing[] = $file;
    }

    return $missing;
}

/**
 * Whole-project source closure across explicit library roots (`moggi compile --lib`).
 *
 * Unlike `moduleFileClosure`, which starts from a single entry file and auto-
 * discovers the standard library, this treats *every* module found under the
 * input directory as a root and resolves imports against the supplied `--lib`
 * directories (stdlib, third-party, app libraries). The result is the set of
 * sources needed to emit a self-contained bundle: the user's own modules plus
 * every reachable library module.
 *
 * A name two roots offer is refused when the program reaches it, not because the
 * roots both exist ({@see libraryModuleScan}, {@see refuseAmbiguousModule}), and a
 * module the build itself compiles counts as reached: the program would carry two
 * modules of one name, and which one an import meant would depend on the closure's
 * insertion order.
 *
 * @param list<string> $inputFiles project `.mog` files (roots to keep)
 * @param list<string> $libDirs    additional module search roots
 * @return array{0: list<string>, 1: string} [closureFiles, commonRoot]
 */
function projectSourceClosure(array $inputFiles, array $libDirs): array
{
    [$libraryPaths, $duplicates] = libraryModuleScan($libDirs);
    $byModule = [...syntheticCompilerModuleIndex(), ...$libraryPaths];

    $entryModules = [];
    foreach ($inputFiles as $path) {
        $real = realpath($path) ?: $path;
        $header = cachedModuleHeader($real);
        $moduleName = $header['module'] ?? null;
        if ($moduleName === null) {
            continue;
        }

        if (isset($entryModules[$moduleName])) {
            if (canonicalPath($byModule[$moduleName]) === canonicalPath($real)) {
                continue;
            }
            throw new TypeError(
                "duplicate module `{$moduleName}` in project ("
                . canonicalSeparators($byModule[$moduleName]) . ' and ' . canonicalSeparators($real) . ')',
                $real,
                (string) ($header['__source'] ?? ''),
                ...moduleHeaderPoint($header),
            );
        }

        $libraryPath = $byModule[$moduleName] ?? null;
        if ($libraryPath !== null
            && !isSyntheticCompilerPath($libraryPath)
            && canonicalPath($libraryPath) !== canonicalPath($real)
        ) {
            refuseAmbiguousModule(
                $moduleName,
                [$real, $libraryPath, ...($duplicates[$moduleName] ?? [])],
                moduleHeaderAt($real, $header),
            );
        }

        $byModule[$moduleName] = $real;
        $entryModules[$moduleName] = true;
    }

    if ($entryModules === []) {
        throw new \RuntimeException('no modules found in build input');
    }

    $primaryPath = $byModule[array_key_first($entryModules)];

    return closureFromModuleIndex($entryModules, $byModule, $primaryPath, $duplicates);
}

/**
 * Breadth-first module closure over a prebuilt name->path index.
 *
 * Shared graph walk used to gather the sources reachable from a set of entry
 * modules: it injects the implicit `Prelude` import, follows platform facade
 * backend maps (and the reverse facade lookup), and reports unknown imports with
 * a source-located "did you mean" hint.
 *
 * A name `$duplicates` records as offered by more than one root is refused as soon
 * as the walk reaches it ({@see refuseAmbiguousModule}): a name no import reaches
 * stays unimported, which is what lets an installed tree carry two packages with a
 * module of one name.
 *
 * @param array<string, bool>   $entryModules module name => true (roots, already in $byModule)
 * @param array<string, string> $byModule     module name => source path
 * @param array<string, list<string>> $duplicates module name => the other paths offering it
 * @return array{0: list<string>, 1: string} [closureFiles, commonRoot]
 */
function closureFromModuleIndex(
    array $entryModules,
    array $byModule,
    string $primaryPath,
    array $duplicates = [],
): array {
    $implOwners = backendImplOwners($byModule);

    $needed = [];
    $queue = \array_keys($entryModules);
    $enqueuedBy = [];
    $queueHead = 0;

    while ($queueHead < count($queue)) {
        $moduleName = $queue[$queueHead++];
        if (isset($needed[$moduleName])) {
            continue;
        }

        if (isset($duplicates[$moduleName])) {
            refuseAmbiguousModule(
                $moduleName,
                [$byModule[$moduleName], ...$duplicates[$moduleName]],
                importSiteAt($enqueuedBy[$moduleName] ?? null, $byModule[$moduleName]),
            );
        }

        $modulePath = $byModule[$moduleName] ?? null;
        if ($modulePath === null) {
            $origin = $enqueuedBy[$moduleName] ?? null;
            $requiredBy = $origin['module'] ?? array_key_first($entryModules);

            $originSource = '';
            if ($origin !== null) {
                $read = file_get_contents($origin['path']);
                $originSource = $read === false ? '' : $read;
            }

            throw new TypeError(
                appendDidYouMean(
                    "unknown module `{$moduleName}` imported by `{$requiredBy}`; "
                        . 'add a --lib PATH for the library that provides it',
                    $moduleName,
                    \array_keys($byModule),
                ),
                $origin['path'] ?? $primaryPath,
                $originSource,
                $origin['line'] ?? 0,
                $origin['col'] ?? 0,
                $origin['endCol'] ?? 0,
            );
        }

        $needed[$moduleName] = $modulePath;

        if (isSyntheticCompilerPath($modulePath)) {
            continue;
        }

        $header = cachedModuleHeader($modulePath);

        foreach (injectPreludeImport(
            $header['imports'],
            $modulePath,
            $byModule,
            $header['language'] ?? [],
        ) as $import) {
            $importName = Ast\moduleName($import->path);
            $owner = $implOwners[$importName] ?? null;
            if ($owner !== null && !isset($implOwners[$moduleName])) {
                throw backendImplImportError(
                    $importName,
                    $owner,
                    $modulePath,
                    $header['__source'] ?? '',
                    $import,
                );
            }

            if (!isset($enqueuedBy[$importName]) && !isset($entryModules[$importName])) {
                $enqueuedBy[$importName] = [
                    'module' => $moduleName,
                    'path' => $modulePath,
                    'line' => $import->line,
                    'col' => $import->col,
                    'endCol' => $import->endCol,
                ];
            }
            if (!isset($needed[$importName])) {
                $queue[] = $importName;
            }
        }

        $implName = resolveImplModuleName($header['backendMap'] ?? []);
        if ($implName !== null && !isset($needed[$implName])) {
            $queue[] = $implName;
        }

        foreach ($byModule as $facadeName => $facadePath) {
            if ($facadeName === $moduleName) {
                continue;
            }

            if (isSyntheticCompilerPath($facadePath)) {
                continue;
            }

            $facadeHeader = cachedModuleHeader($facadePath);
            $facadeBackendMap = $facadeHeader['backendMap'] ?? [];
            if ($facadeBackendMap === []) {
                continue;
            }

            if (\in_array($moduleName, $facadeBackendMap, true) && !isset($needed[$facadeName])) {
                $queue[] = $facadeName;
            }
        }
    }

    $files = [];
    foreach ($needed as $modulePath) {
        if (!isSyntheticCompilerPath($modulePath)) {
            $files[] = $modulePath;
        }
    }
    $root = commonPathPrefix($files);
    if ($root === '') {
        throw new \RuntimeException('cannot determine project root for module closure');
    }

    return [$files, $root];
}

/**
 * @param array<string, array<string, mixed>> $units
 * @return list<string>
 */
function moduleDependencyNames(array $units, string $moduleName): array
{
    $imports = \array_map(
        static fn (Ast\ImportDecl $import): string => moduleName($import->path),
        $units[$moduleName]['imports'] ?? $units[$moduleName]['program']->imports,
    );

    if (isset($units[$moduleName]['program'])
        || (($units[$moduleName]['backendMap'] ?? []) !== [])
    ) {
        $imports = [...$imports, ...facadeDependencyModules($units, $moduleName)];
    }

    return $imports;
}

/**
 * @param array<string, array<string, mixed>> $units
 * @param list<string> $pending
 * @param array<string, true> $sortedSet
 */
function throwCyclicModuleImports(array $units, array $pending, array $sortedSet): void
{
    $pendingSet = \array_fill_keys($pending, true);
    $deps = [];
    foreach ($pending as $moduleName) {
        $unsatisfied = [];
        foreach (moduleDependencyNames($units, $moduleName) as $importName) {
            if (!isset($sortedSet[$importName]) && isset($pendingSet[$importName])) {
                $unsatisfied[] = $importName;
            }
        }

        $deps[$moduleName] = $unsatisfied;
    }

    $cycle = findModuleImportCycle($deps);
    if ($cycle === null) {
        throw new TypeError('cyclic module imports');
    }

    $chain = [];
    foreach ($cycle as $index => $moduleName) {
        $next = $cycle[($index + 1) % count($cycle)];
        $chain[] = "`{$moduleName}` imports `{$next}`";
    }

    $start = $cycle[0];
    $next = $cycle[1] ?? $start;
    throw new TypeError(
        'cyclic module imports: ' . \implode(' -> ', $chain),
        $units[$start]['path'] ?? '',
        $units[$start]['source'] ?? '',
        ...moduleImportPoint($units[$start] ?? [], $next),
    );
}

/**
 * Where a module's import of `$importName` is written, falling back to the module header.
 *
 * @param array<string, mixed> $unit
 * @return array{0: int, 1: int, 2: int} [line, col, endCol]
 */
function moduleImportPoint(array $unit, string $importName): array
{
    foreach ($unit['imports'] ?? [] as $import) {
        if (Ast\moduleName($import->path) === $importName) {
            return [$import->line, $import->col, $import->endCol];
        }
    }

    return moduleHeaderPoint($unit);
}

/**
 * Where a module names itself, falling back to the first character of the file.
 *
 * @param array<string, mixed> $header
 * @return array{0: int, 1: int, 2: int} [line, col, endCol]
 */
function moduleHeaderPoint(array $header): array
{
    $span = $header['headerSpan'] ?? null;

    return [
        (int) ($span['line'] ?? 1),
        (int) ($span['col'] ?? 1),
        (int) ($span['endCol'] ?? 1),
    ];
}

/**
 * @param array<string, list<string>> $deps
 * @return ?list<string>
 */
function findModuleImportCycle(array $deps): ?array
{
    $visiting = [];
    $visited = [];
    $stack = [];

    $visit = function (string $node) use (&$visit, &$deps, &$visiting, &$visited, &$stack): ?array {
        if (isset($visiting[$node])) {
            $start = array_search($node, $stack, true);
            if ($start === false) {
                return [$node];
            }

            return \array_slice($stack, $start);
        }

        if (isset($visited[$node])) {
            return null;
        }

        $visiting[$node] = true;
        $stack[] = $node;
        foreach ($deps[$node] ?? [] as $dep) {
            $cycle = $visit($dep);
            if ($cycle !== null) {
                return $cycle;
            }
        }

        array_pop($stack);
        unset($visiting[$node]);
        $visited[$node] = true;

        return null;
    };

    foreach (\array_keys($deps) as $node) {
        $cycle = $visit($node);
        if ($cycle !== null) {
            return $cycle;
        }
    }

    return null;
}

/**
 * @param array<string, array<string, mixed>> $units
 * @return list<string>
 */
function sortModulesByDependencies(array $units): array
{
    $pending = \array_keys($units);
    $sorted = [];
    $sortedSet = [];

    while ($pending !== []) {
        $progress = false;
        foreach ($pending as $index => $moduleName) {
            $imports = moduleDependencyNames($units, $moduleName);

            $ready = true;
            foreach ($imports as $importName) {
                if (!isset($units[$importName])) {
                    throw new TypeError(
                        appendDidYouMean(
                            "unknown module `{$importName}` imported by `{$moduleName}`",
                            $importName,
                            \array_keys($units),
                        ),
                        $units[$moduleName]['path'] ?? '',
                        $units[$moduleName]['source'] ?? '',
                        ...moduleImportPoint($units[$moduleName] ?? [], $importName),
                    );
                }

                if (!isset($sortedSet[$importName])) {
                    $ready = false;
                    break;
                }
            }

            if (!$ready) {
                continue;
            }

            $sorted[] = $moduleName;
            $sortedSet[$moduleName] = true;
            unset($pending[$index]);
            $progress = true;
        }

        if (!$progress) {
            throwCyclicModuleImports($units, array_values($pending), $sortedSet);
        }
    }

    return $sorted;
}
