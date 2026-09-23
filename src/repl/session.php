<?php declare(strict_types=1);

namespace Moggi\Repl;

use Moggi\Cache;
use Moggi\Modules;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\Registry;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\Types;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;
use Moggi\Syntax\Parser\ReplFragment;

use function Moggi\Backend\backendById;
use function Moggi\Backend\setCompileBackend;
use function Moggi\Modules\buildImportContext;
use function Moggi\Modules\buildModuleLocalTypes;
use function Moggi\Modules\indexProjectInstances;
use function Moggi\Modules\injectPreludeImport;
use function Moggi\Modules\mergeModuleLocalTypesIntoImportContext;
use function Moggi\Modules\moduleNameToNamespace;
use function Moggi\Semantics\Effects\checkAndNormalize;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\importedFixityForImports;
use function Moggi\Syntax\Parser\mergeFixity;
use function Moggi\Syntax\Parser\parse;
use function Moggi\Syntax\Parser\parseModuleHeader;
use function Moggi\Syntax\Parser\parseReplFragment;
use function Moggi\Syntax\Parser\parseReplType;

/** stdout marker line before a Show-encoded IO expression result */
const REPL_VAL_MARK = '__moggi_repl_val__';

/** stdout marker line before a Show-encoded statement binding (`__moggi_repl_bind__name`) */
const REPL_BIND_PREFIX = '__moggi_repl_bind__';

function interactiveSource(State $state, string $extra = ''): string
{
    $lines = ["module {$state->moduleName} where", ''];
    foreach ($state->importLines as $imp) {
        $lines[] = $imp;
    }
    if ($state->importLines !== []) {
        $lines[] = '';
    }
    foreach ($state->declSources as $decl) {
        $lines[] = $decl;
        $lines[] = '';
    }
    if ($extra !== '') {
        $lines[] = rtrim($extra);
        $lines[] = '';
    }

    return implode("\n", $lines);
}

function writeInteractive(State $state, string $extra = ''): string
{
    return writeInteractiveSource($state, interactiveSource($state, $extra));
}

/** Write the interactive module source verbatim, creating the session dir. */
function writeInteractiveSource(State $state, string $source): string
{
    $path = $state->interactivePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (file_put_contents($path, $source) === false) {
        throw new \RuntimeException("cannot write {$path}");
    }

    return $path;
}

/**
 * Project roots for Interactive typecheck / PHP deps: Prelude + :load paths.
 *
 * @return list<string>
 */
function replSeedFiles(State $state): array
{
    $seed = [];
    foreach ($state->libDirs as $libDir) {
        $prelude = rtrim($libDir, '/\\') . '/Prelude.mog';
        if (is_file($prelude)) {
            $seed[] = $prelude;
            break;
        }
    }
    if ($seed === []) {
        $bundled = Modules\bundledStdlibLibPath();
        if ($bundled !== null && is_file($bundled . '/Prelude.mog')) {
            $seed[] = $bundled . '/Prelude.mog';
        }
    }
    foreach ($state->loadedPaths as $loaded) {
        $seed[] = $loaded;
    }

    return $seed;
}

/**
 * Prepare the stdlib + :load closure once; Interactive is typechecked against it
 * without re-lexing/parsing Prelude on every line.
 */
function ensureBasePrepared(State $state): Modules\PreparedProject
{
    $fp = hash('sha256', implode('|', [
        'backend=' . $state->backend,
        'libs=' . implode(',', $state->libDirs),
        'loaded=' . implode(',', $state->loadedPaths),
    ]));
    if ($state->basePreparedFp === $fp && $state->basePrepared instanceof Modules\PreparedProject) {
        return $state->basePrepared;
    }

    $seed = replSeedFiles($state);
    if ($seed === []) {
        throw new TypeError(
            'REPL could not find Prelude; pass --lib PATH',
            '<interactive>',
            '',
        );
    }

    [$files, $root] = Modules\projectSourceClosure($seed, $state->libDirs);
    $state->basePrepared = Modules\prepareProjectCached($files, $root);
    $state->basePreparedFp = $fp;

    return $state->basePrepared;
}

/**
 * Identity of a prepared interactive module: everything the typecheck and the
 * emitted artifacts depend on. Memoized so the probe → restore pattern and
 * repeated queries on an unchanged session skip the whole front end.
 */
function interactiveFingerprint(State $state, string $extra, bool $normalizeIo): string
{
    return hash('sha256', implode("\x1f", [
        'backend=' . $state->backend,
        'normalizeIo=' . ($normalizeIo ? '1' : '0'),
        'module=' . $state->moduleName,
        'libs=' . implode("\x1e", $state->libDirs),
        'loaded=' . implode("\x1e", $state->loadedPaths),
        'imports=' . implode("\x1e", $state->importLines),
        'decls=' . implode("\x1e", $state->declSources),
        'extra=' . $extra,
    ]));
}

/** Re-publish a memoized context (and its module file) on the session. */
function adoptInteractiveCtx(State $state, array $ctx): array
{
    $state->prepared = $ctx['prepared'];
    $state->typedInteractive = $ctx['checked'];
    writeInteractiveSource($state, (string) $ctx['source']);

    return $ctx;
}

/** Remember a context, keeping the newest two (current + restore target). */
function rememberInteractiveCtx(State $state, string $fingerprint, array $ctx): void
{
    $cache = [$fingerprint => $ctx] + $state->interactiveCache;
    if (count($cache) > 2) {
        $cache = \array_slice($cache, 0, 2, true);
    }
    $state->interactiveCache = $cache;
}

/**
 * Typecheck the interactive module (current declarations plus an optional
 * `$extra` probe) and publish it on the session.
 *
 * Memoized per {@see interactiveFingerprint}: `:type` / `:ast` / `:ir` / `:emit`
 * probe with an extra declaration and then restore the base module, and repeated
 * queries on an unchanged session are common — neither should pay for a second
 * full typecheck.
 *
 * @return array{prepared: Modules\PreparedProject, checked: Ast\Program, importContext: array<string, mixed>}
 */
function prepareInteractive(State $state, string $extra = '', bool $normalizeIo = true): array
{
    $fingerprint = interactiveFingerprint($state, $extra, $normalizeIo);
    $cached = $state->interactiveCache[$fingerprint] ?? null;
    if (\is_array($cached)) {
        return adoptInteractiveCtx($state, $cached);
    }

    setCompileBackend($state->backend);
    $source = interactiveSource($state, $extra);
    $path = writeInteractiveSource($state, $source);
    $base = ensureBasePrepared($state);
    $moduleName = $state->moduleName;

    $tokens = lex($source, $path);
    $header = parseModuleHeader($tokens, $source, $path);
    $available = array_fill_keys(array_keys($base->units), true);
    $imports = injectPreludeImport(
        $header['imports'],
        $path,
        $available,
        $header['language'] ?? [],
    );

    $fixityByModule = [];
    foreach ($base->units as $name => $unit) {
        $fixityByModule[$name] = $unit['localFixity'] ?? [];
    }
    $localFixity = $header['localFixity'] ?? [];
    $importedFixity = importedFixityForImports($imports, $fixityByModule);
    $program = parse($tokens, $source, $path, $importedFixity, $localFixity, $imports);

    $units = $base->units;
    $units[$moduleName] = [
        'path' => $path,
        'source' => $source,
        'tokens' => $tokens,
        'imports' => $imports,
        'namespace' => moduleNameToNamespace($moduleName),
        'fixity' => mergeFixity($importedFixity, $localFixity),
        'importedFixity' => $importedFixity,
        'localFixity' => $localFixity,
        'program' => $program,
        'parsed' => true,
    ];

    $sampleCtx = [];
    foreach ($base->importContexts as $ctx) {
        $sampleCtx = $ctx;
        break;
    }
    $projectClasses = $sampleCtx['classes'] ?? [];
    $projectInstances = $sampleCtx['projectInstances'] ?? [];
    $projectInstanceIndex = $sampleCtx['projectInstanceIndex']
        ?? indexProjectInstances($projectInstances);

    buildModuleLocalTypes($units, $moduleName, $projectClasses, $projectInstanceIndex);

    $outputRelative = str_replace('.', '/', $moduleName)
        . backendById($state->backend)->extension();
    $importContext = buildImportContext(
        $imports,
        $units,
        $moduleName,
        $outputRelative,
        $base->rootPrefix,
    );
    $importContext['classes'] = $projectClasses;
    $importContext['projectInstances'] = $projectInstances;
    $importContext['projectInstanceIndex'] = $projectInstanceIndex;
    $importContext['currentModule'] = $moduleName;
    mergeModuleLocalTypesIntoImportContext(
        $importContext,
        $program,
        $units[$moduleName]['localTypes'],
    );

    // :type probes must not run the IO boundary: wrapping `putStrLn` as a
    // binding is fine for inference but fails "IO function body" validation.
    $checked = $normalizeIo
        ? checkAndNormalize(
            $program,
            $source,
            $path,
            $importContext,
            CompilePurpose::Repl,
        )
        : Types\checkRaw(
            $program,
            $source,
            $path,
            $importContext,
            CompilePurpose::Repl,
        );

    $checkedMap = $base->checked;
    $checkedMap[$moduleName] = $checked;
    $ctxMap = $base->importContexts;
    $ctxMap[$moduleName] = $importContext;
    $prepared = new Modules\PreparedProject($base->rootPrefix, $units, $checkedMap, $ctxMap);

    $state->prepared = $prepared;
    $state->typedInteractive = $checked;
    $ctx = [
        'prepared' => $prepared,
        'checked' => $checked,
        'importContext' => $importContext,
        'source' => $source,
    ];
    rememberInteractiveCtx($state, $fingerprint, $ctx);

    return $ctx;
}

function dropItBindings(State $state): void
{
    $state->declSources = array_values(array_filter(
        $state->declSources,
        static fn (string $d): bool => !preg_match('/^it\b/', ltrim($d)),
    ));
}

function commitDeclaration(State $state, string $declSource): string
{
    $name = leadingBindingName($declSource);
    if ($name !== null && bindingNameExists($state, $name)) {
        // Later interactive bindings shadow earlier ones. Prior
        // binders are renamed to unique names so closures keep the old binding.
        shadowInteractiveBinding($state, $name);
    }

    $state->declSources[] = $declSource;
    try {
        prepareInteractive($state);
    } catch (\Throwable $e) {
        array_pop($state->declSources);
        throw $e;
    }

    // Successful declarations are silent (use :type / :browse).
    return '';
}

/**
 * Rename existing interactive OccName `$name` to a fresh unique binder so a
 * new declaration of `$name` can be added without colliding at module scope.
 */
function shadowInteractiveBinding(State $state, string $name): void
{
    $uniq = $name . '__s' . $state->generation++;
    $state->declSources = \array_map(
        static fn (string $decl): string => rewriteDeclSourceForShadow($decl, $name, $uniq),
        $state->declSources,
    );
}

function bindingNameExists(State $state, string $name): bool
{
    foreach ($state->declSources as $decl) {
        if (declSourceName($decl) === $name) {
            return true;
        }
    }

    return false;
}

/** Leading identifier of a declaration source line, if any. */
function leadingBindingName(string $source): ?string
{
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_\']*)\b/', ltrim($source), $m) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * Name a declaration source introduces. Unlike {@see leadingBindingName} this
 * skips the keyword of type-level declarations, so `data Per = …` reports
 * `Per` and not `data`.
 */
function declSourceName(string $source): ?string
{
    if (preg_match('/^\s*(?:data|newtype|type|class)\s+([A-Z][A-Za-z0-9_\']*)/', $source, $m) === 1) {
        return $m[1];
    }

    return leadingBindingName($source);
}

/** The name a fragment declares, from the AST when it has one. */
function fragmentDeclaredName(ReplFragment $frag): ?string
{
    $node = $frag->node;
    $name = match (true) {
        $node instanceof Ast\FunctionDecl => $node->name,
        $node instanceof Ast\DataDecl => $node->name,
        $node instanceof Ast\TypeSynonymDecl => $node->name,
        $node instanceof Ast\ClassDecl => $node->name,
        default => leadingBindingName($frag->source),
    };

    return $name !== null && $name !== '' ? $name : null;
}

function evaluateExpression(State $state, string $exprSource): string
{
    $evalName = '__repl_eval';
    dropItBindings($state);

    $pureExtra = "it = ({$exprSource})\n"
        . "{$evalName} :: IO ()\n"
        . "{$evalName} = putStrLn (show it)\n";

    try {
        prepareInteractive($state, $pureExtra);
        $out = runEvalEntry($state, $evalName);
        // Persist `it` without an eager re-prepare: the next command rebuilds the
        // module from `declSources` anyway, so the restore is done lazily there.
        $state->declSources[] = 'it = (' . $exprSource . ')';

        return rtrim($out);
    } catch (TypeError $pureErr) {
        // IO (): run for effects only (no `it` — unit is not Showable here).
        $ioUnitExtra = "{$evalName} :: IO ()\n{$evalName} = ({$exprSource})\n";
        try {
            prepareInteractive($state, $ioUnitExtra);
            $out = runEvalEntry($state, $evalName);

            return rtrim($out);
        } catch (TypeError) {
            // IO a: run, print Show result, persist `it` from shown form.
            $ioValExtra = "{$evalName} :: IO ()\n"
                . "{$evalName} = do\n"
                . "  __moggi_it <- ({$exprSource})\n"
                . '  putStrLn "' . REPL_VAL_MARK . "\"\n"
                . "  putStrLn (show __moggi_it)\n";
            try {
                prepareInteractive($state, $ioValExtra);
                $raw = runEvalEntry($state, $evalName);
                $split = splitReplProtocol($raw);
                dropItBindings($state);
                if ($split['val'] !== null) {
                    commitShownBindings($state, ['it' => $split['val']]);
                }

                $display = $split['display'];
                if ($split['val'] !== null) {
                    if ($display !== '' && !str_ends_with($display, "\n")) {
                        $display .= "\n";
                    }
                    $display .= $split['val'] . "\n";
                }

                return rtrim($display);
            } catch (TypeError) {
                throw $pureErr;
            }
        }
    }
}

/**
 * Evaluate prompt statements (`x <- e`, stmt `let`, sequences).
 *
 * Bound names are persisted via Show into the interactive module (no shadowing).
 * Bind results are not printed.
 */
function evaluateStatements(State $state, string $source, ?Ast\AstNode $node): string
{
    if (!$node instanceof Ast\DoExpr) {
        throw new TypeError('expected statement block', '<interactive>', $source);
    }

    $names = collectReplStmtBindNames($node->stmts);
    foreach ($names as $name) {
        if (bindingNameExists($state, $name)) {
            throw new TypeError(
                "duplicate binding `{$name}`",
                '<interactive>',
                $source,
            );
        }
    }

    $evalName = '__repl_eval';
    dropItBindings($state);

    $body = indentReplBlock($source);
    $dump = '';
    foreach ($names as $name) {
        $dump .= '  putStrLn "' . REPL_BIND_PREFIX . $name . "\"\n";
        $dump .= "  putStrLn (show {$name})\n";
    }

    $extra = "{$evalName} :: IO ()\n"
        . "{$evalName} = do\n"
        . $body . "\n"
        . $dump;

    prepareInteractive($state, $extra);
    $raw = runEvalEntry($state, $evalName);
    $split = splitReplProtocol($raw);
    commitShownBindings($state, $split['binds']);

    return rtrim($split['display']);
}

/**
 * @param list<Ast\AstNode> $stmts
 * @return list<string>
 */
function collectReplStmtBindNames(array $stmts): array
{
    $names = [];
    foreach ($stmts as $stmt) {
        if ($stmt instanceof Ast\DoBind) {
            appendPatVarNames($stmt->pattern, $names);
        } elseif ($stmt instanceof Ast\DoLet) {
            foreach ($stmt->bindings as $bind) {
                appendPatVarNames($bind->pattern, $names);
            }
        }
    }

    return array_values(array_unique($names));
}

/**
 * @param list<string> $names
 */
function appendPatVarNames(Ast\AstNode $pattern, array &$names): void
{
    if ($pattern instanceof Ast\PatVar) {
        $names[] = $pattern->name;
    }
}

function indentReplBlock(string $source): string
{
    $lines = preg_split('/\R/', rtrim($source)) ?: [''];
    $out = [];
    foreach ($lines as $line) {
        $out[] = '  ' . $line;
    }

    return implode("\n", $out);
}

/** Wrap a fragment as a binding RHS (`do …` for stmts, `(…)` for exprs). */
function replFragmentBindingRhs(ReplFragment $frag): string
{
    if ($frag->kind === ReplFragment::KIND_STMT) {
        return "do\n" . indentReplBlock($frag->source);
    }

    return '(' . $frag->source . ')';
}

/**
 * @return array{display: string, val: ?string, binds: array<string, string>}
 */
function splitReplProtocol(string $raw): array
{
    $lines = preg_split('/\R/', $raw) ?: [];
    if ($lines !== [] && $lines[count($lines) - 1] === '' && str_ends_with($raw, "\n")) {
        array_pop($lines);
    }

    $display = [];
    $binds = [];
    $val = null;
    $n = count($lines);
    for ($i = 0; $i < $n; ++$i) {
        $line = $lines[$i];
        if ($line === REPL_VAL_MARK) {
            $val = $lines[$i + 1] ?? '';
            ++$i;
            continue;
        }
        if (str_starts_with($line, REPL_BIND_PREFIX)) {
            $name = substr($line, strlen(REPL_BIND_PREFIX));
            if ($name !== '') {
                $binds[$name] = $lines[$i + 1] ?? '';
            }
            ++$i;
            continue;
        }
        $display[] = $line;
    }

    $displayStr = $display === [] ? '' : (implode("\n", $display) . "\n");

    return ['display' => $displayStr, 'val' => $val, 'binds' => $binds];
}

/**
 * Persist Show-encoded bindings; roll back if the interactive module rejects them.
 *
 * With nothing to persist this is a no-op: the probe module stays published on
 * the session and the next command rebuilds the canonical module from
 * `declSources` (and pays for it once instead of twice).
 *
 * @param array<string, string> $binds name => Show text used as RHS
 */
function commitShownBindings(State $state, array $binds): void
{
    if ($binds === []) {
        return;
    }

    $added = 0;
    foreach ($binds as $name => $shown) {
        if (bindingNameExists($state, $name)) {
            while ($added > 0) {
                array_pop($state->declSources);
                --$added;
            }
            throw new TypeError(
                "duplicate binding `{$name}`",
                '<interactive>',
                "{$name} = {$shown}",
            );
        }
        $state->declSources[] = $name . ' = ' . $shown;
        ++$added;
    }

    try {
        prepareInteractive($state);
    } catch (\Throwable $e) {
        while ($added > 0) {
            array_pop($state->declSources);
            --$added;
        }
        prepareInteractive($state);
        throw $e;
    }
}

function runEvalEntry(State $state, string $entryName): string
{
    $state->generation++;
    $state->lastFragmentFocus = [$entryName];

    return EvalRunner\runEntry($state, $entryName);
}

function bindingTypeLine(Ast\Program $checked, string $name): ?string
{
    $scheme = $checked->exportedInferredSchemes[$name] ?? null;
    if ($scheme instanceof Scheme) {
        $typeStr = Types\typeToString(
            $scheme->type,
            Types\friendlyTypeVarNames([$scheme->type]),
        );

        return "{$name} :: {$typeStr}";
    }

    foreach ($checked->items as $item) {
        if ($item instanceof Ast\FunctionDecl && $item->name === $name && $item->type !== null) {
            return "{$name} :: " . Ast\dumpTypeInline($item->type);
        }
    }

    return null;
}

function typeOfInput(State $state, string $source): string
{
    $frag = parseReplFragment($source, '<interactive>');
    if ($frag->kind === ReplFragment::KIND_INCOMPLETE) {
        throw new TypeError('incomplete input', '<interactive>', $source);
    }
    if ($frag->kind === ReplFragment::KIND_ERROR) {
        throw $frag->error ?? new TypeError('parse error', '<interactive>', $source);
    }

    if ($frag->kind === ReplFragment::KIND_DECL) {
        $name = fragmentDeclaredName($frag);
        if ($name !== null && bindingNameExists($state, $name)) {
            $ctx = prepareInteractive($state);
            $line = bindingTypeLine($ctx['checked'], $name);
            if ($line === null) {
                throw new TypeError("could not infer type of `{$name}`", '<interactive>', $source);
            }

            return $line;
        }

        $ctx = prepareInteractive($state, $frag->source, normalizeIo: false);
        $line = $name !== null ? bindingTypeLine($ctx['checked'], $name) : null;
        prepareInteractive($state);
        if ($line === null) {
            throw new TypeError('could not infer type', '<interactive>', $source);
        }

        return $line;
    }

    // Bare name: prefer env/scheme lookup (avoids IO-boundary issues on values
    // like `putStrLn :: String -> IO ()`).
    $trimmed = trim($frag->source);
    if ($frag->kind !== ReplFragment::KIND_STMT
        && preg_match('/^([A-Za-z_][A-Za-z0-9_\']*)$/', $trimmed, $m) === 1) {
        $ctx = prepareInteractive($state);
        $schemeLine = schemeTypeLine($ctx, $m[1]);
        if ($schemeLine !== null) {
            return $schemeLine;
        }
    }

    $name = '__repl_ty';
    $extra = "{$name} = " . replFragmentBindingRhs($frag) . "\n";
    $ctx = prepareInteractive($state, $extra, normalizeIo: false);
    $line = bindingTypeLine($ctx['checked'], $name);
    prepareInteractive($state);
    if ($line === null) {
        throw new TypeError('could not infer type', '<interactive>', $source);
    }

    // Strip the synthetic name prefix: `__repl_ty :: T` → `T`
    return preg_replace('/^__repl_ty :: /', '', $line) ?? $line;
}

/**
 * @param array{checked: Ast\Program, importContext: array<string, mixed>} $ctx
 */
function schemeTypeLine(array $ctx, string $name): ?string
{
    $scheme = $ctx['checked']->exportedInferredSchemes[$name]
        ?? ($ctx['importContext']['env'][$name] ?? null);
    if (!$scheme instanceof Scheme) {
        return null;
    }

    return $name . ' :: ' . Types\typeToString(
        $scheme->type,
        Types\friendlyTypeVarNames([$scheme->type]),
    );
}

/**
 * Kind of a type, or of the type of an expression/binding (`:kind 10` → `Type`).
 */
function kindOfInput(State $state, string $source): string
{
    try {
        return kindOfParsedType($state, $source);
    } catch (LexError|ParseError) {
        // Fall through: treat as expression/decl and report the kind of its type.
    }

    $typeLine = typeOfInput($state, $source);
    $typeOnly = $typeLine;
    if (preg_match('/^.*? :: (.+)$/s', $typeLine, $m) === 1) {
        $typeOnly = $m[1];
    }

    try {
        return kindOfParsedType($state, $typeOnly);
    } catch (LexError|ParseError $e) {
        throw new TypeError(
            'not a type; use :type for expressions (`' . trim($source) . '` has type `' . $typeLine . '`)',
            '<interactive>',
            $source,
        );
    }
}

function kindOfParsedType(State $state, string $typeSource): string
{
    $ctx = prepareInteractive($state);
    $typeAst = parseReplType($typeSource, '<interactive>');
    $stateTc = Types\newState($typeSource, '<interactive>');
    Registry\applyImportContext($stateTc, $ctx['checked'], $ctx['importContext']);
    Registry\registerTypeDeclarations($stateTc, $ctx['checked']);
    $kindCtx = Kinds\newKindInferCtx($stateTc);
    $kind = Kinds\inferKindAst($kindCtx, $typeAst);

    return Kinds\kindToString(Kinds\pruneKind($kindCtx, $kind));
}

function infoForName(State $state, string $name): string
{
    $ctx = prepareInteractive($state);
    $lines = [];
    $schemeLine = schemeTypeLine($ctx, $name);
    if ($schemeLine !== null) {
        $lines[] = $schemeLine;
    }
    foreach ($ctx['checked']->items as $item) {
        if ($item instanceof Ast\FunctionDecl && $item->name === $name) {
            $lines[] = 'binding in module ' . $state->moduleName;
            $lines[] = 'arity ' . count($item->params);
        }
        if ($item instanceof Ast\DataDecl && $item->name === $name) {
            $lines[] = 'data ' . $item->name;
        }
        if ($item instanceof Ast\ClassDecl && $item->name === $name) {
            $lines[] = 'class ' . $item->name;
        }
    }
    if ($lines === []) {
        return "name `{$name}` not found in the interactive environment\n";
    }

    return implode("\n", $lines) . "\n";
}

function browseBindings(State $state): string
{
    $ctx = prepareInteractive($state);
    $names = [];
    foreach ($ctx['checked']->items as $item) {
        if ($item instanceof Ast\FunctionDecl && !$item->signatureOnly && !$item->instanceMethod) {
            if (isShadowedInteractiveName($item->name)) {
                continue;
            }
            $names[$item->name] = true;
        }
        if ($item instanceof Ast\DataDecl) {
            $names[$item->name] = true;
        }
    }
    ksort($names);
    if ($names === []) {
        return "(no bindings in {$state->moduleName})\n";
    }
    $out = '';
    foreach (array_keys($names) as $name) {
        $line = bindingTypeLine($ctx['checked'], $name);
        $out .= ($line ?? $name) . "\n";
    }

    return $out;
}

function clearSession(State $state): void
{
    $state->declSources = [];
    $state->importLines = [];
    $state->loadedPaths = [];
    $state->loadedPrimary = null;
    $state->prepared = null;
    $state->basePrepared = null;
    $state->basePreparedFp = null;
    $state->typedInteractive = null;
    $state->lastFragment = null;
    $state->lastFragmentFocus = null;
    $state->buffer = '';
    $state->generation++;
    $state->phpDepsFingerprint = null;
    $state->phpDepsModuleRel = [];
    $state->forgetInteractiveCache();
    Modules\clearPrepareProjectCaches();
    writeInteractive($state);
    prepareInteractive($state);
}

/** Remove the session scratch tree (generated modules, dependencies, live trees). */
function destroySession(State $state): void
{
    if ($state->sessionDir !== '' && is_dir($state->sessionDir)) {
        Cache\removeTree($state->sessionDir);
    }
}

/**
 * @param list<string> $libDirs
 */
function createSession(string $backend, array $libDirs): State
{
    $dir = sys_get_temp_dir() . '/moggi-repl-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new \RuntimeException("cannot create session dir {$dir}");
    }
    $state = new State(
        backend: $backend,
        sessionDir: $dir,
        libDirs: $libDirs,
    );
    writeInteractive($state);
    prepareInteractive($state);

    return $state;
}

function loadModuleFile(State $state, string $path): string
{
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        throw new \RuntimeException("cannot load `{$path}`");
    }
    if (!str_ends_with($real, '.mog')) {
        throw new \RuntimeException('`:load` expects a .mog file');
    }

    [$files, $root] = Modules\moduleFileClosureCached($real);
    $header = Modules\cachedModuleHeader($real);
    $mod = $header['module'] ?? null;
    if ($mod === null) {
        throw new \RuntimeException("file `{$path}` is not a module");
    }

    Modules\prepareProjectCached($files, $root, $mod);

    $loadedBefore = $state->loadedPaths;
    $primaryBefore = $state->loadedPrimary;
    $importsBefore = $state->importLines;

    if (!\in_array($real, $state->loadedPaths, true)) {
        $state->loadedPaths[] = $real;
    }
    $state->loadedPrimary = $real;
    $state->phpDepsFingerprint = null;
    $state->phpDepsModuleRel = [];
    $state->basePrepared = null;
    $state->basePreparedFp = null;
    $state->forgetInteractiveCache();
    $importLine = "import {$mod}";
    if (!\in_array($importLine, $state->importLines, true)) {
        $state->importLines[] = $importLine;
    }

    try {
        prepareInteractive($state);
    } catch (\Throwable $e) {
        // A `:load` that fails to typecheck must leave the session untouched.
        $state->loadedPaths = $loadedBefore;
        $state->loadedPrimary = $primaryBefore;
        $state->importLines = $importsBefore;
        prepareInteractive($state);
        throw $e;
    }

    return "loaded {$mod} from {$real}\n";
}

function reloadSession(State $state): string
{
    Modules\clearPrepareProjectCaches();
    $state->phpDepsFingerprint = null;
    $state->phpDepsModuleRel = [];
    $state->basePrepared = null;
    $state->basePreparedFp = null;
    $state->forgetInteractiveCache();
    if ($state->loadedPrimary !== null) {
        loadModuleFile($state, $state->loadedPrimary);

        return "reloaded\n";
    }
    prepareInteractive($state);

    return "reloaded\n";
}
