<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;

use function Moggi\Backend\Php\Dependencies\phpCompanionFilesForModule;
use function Moggi\Backend\Php\Foreign\emitForeignIoStatement;

require __DIR__ . '/naming.php';
require __DIR__ . '/emit_expr.php';
require __DIR__ . '/emit_match.php';
require __DIR__ . '/dependencies.php';

use Moggi\IR;
use Moggi\Debug\SourceMapBuilder;

use function Moggi\Backend\Meta\boolConstructorMap;
use function Moggi\Backend\Meta\boolConstructorMapFromRegistry;
use function Moggi\Backend\Meta\constructorArityMap;
use function Moggi\Backend\Meta\constructorArityMapFromRegistry;
use function Moggi\Backend\Meta\isBoolBackedData;
use function Moggi\Backend\Meta\lambdaMetaFromCaptures;
use function Moggi\Backend\Meta\newtypeConstructorMap;
use function Moggi\Backend\Meta\newtypeConstructorMapFromRegistry;
use function Moggi\Backend\Meta\operandCallSrcLoc;
use function Moggi\Backend\Php\Naming\buildPhpNameMap;
use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpFunctionName;
use function Moggi\Backend\Php\Naming\phpTemp;
use function Moggi\Backend\Php\Io\emitIoAssignAction;
use function Moggi\Backend\Php\Io\emitIoCatch;
use function Moggi\Backend\Php\Io\emitIoFinally;
use function Moggi\Backend\Php\Io\emitIoRun;
use function Moggi\Backend\Php\Io\emitIoThrow;
use function Moggi\Backend\Php\Intrinsics\emitCall as emitIntrinsicCall;
use function Moggi\Backend\Php\Intrinsics\localHelpersForUsed;
use function Moggi\Backend\Php\Intrinsics\phpHelpersForModule;
use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\IR\Visit\collectLocalsInBlock;
use function Moggi\IR\Visit\collectLocalsInOperand;
use function Moggi\IR\Visit\freeLocalsInBlock;
use function Moggi\IR\Visit\moduleUsesMoggiRuntime;
use function Moggi\Optimize\Support\isCapturedFnName;
use function Moggi\Optimize\Partial\moduleUsesPartialApply;

use const Moggi\Backend\Php\RUNTIME_OUTPUT_PATH;

/** One line standing in for the module's source map until the map is known. */
const MAP_PLACEHOLDER = '//@@moggi-source-map@@';

/** @param IR\Module $module @param array{namespace?: ?string, outputRelative?: string, imports?: array{requireLines?: list<string>, functionUseLines?: list<string>, namespaceUseLines?: list<string>, externalFns?: array<string, string>, moduleAsNames?: array<string, string>, constructorRenames?: array<string, string>}} $options @return string|array<string, string> */
function emit(IR\Module $module, string $sourcePath, array $options = []): string|array
{
    $GLOBALS['__moggi_php_scrut_snap'] = 0;
    $GLOBALS['__moggi_php_binder_freshen'] = 0;
    $GLOBALS['__moggi_php_match_nest'] = 0;
    $namespace = $options['namespace'] ?? null;
    $analysis = analyzeFunctionsForCodegen($module->functions);
    $lambdaIndex = $analysis['lambdaIndex'];
    $lambdaMeta = $analysis['lambdaMeta'];
    $phpNames = buildPhpNameMap($module);
    $importOptions = $options['imports'] ?? [];
    $functionArity = $analysis['functionArity'];
    // The import tables are keyed by bare name, so a name this module defines
    // itself must not be overwritten by one: the call site resolves to the local
    // binding, and an arity that came from an import would emit it as an
    // under-applied runtime partial.
    /** @var array<string, true> $localBindings */
    $localBindings = [];
    foreach ($module->functions as $fn) {
        $localBindings[$fn->name] = true;
    }
    foreach (constructorArityMap($module->data) as $name => $arity) {
        $localBindings[$name] = true;
        $functionArity[$name] = $arity;
    }
    foreach (constructorArityMapFromRegistry($importOptions['importedData'] ?? []) as $name => $arity) {
        if (! isset($localBindings[$name])) {
            $functionArity[$name] = $arity;
        }
    }
    foreach ($module->instanceEvidence as $evidence) {
        $functionArity[$evidence->evidenceName] = count($evidence->contextParams);
    }
    foreach ($importOptions['externalFnRuntimeArity'] ?? [] as $name => $runtimeArity) {
        if (! isset($localBindings[$name])) {
            $functionArity[$name] = $runtimeArity;
        }
    }
    $moduleName = (string) ($options['moduleName'] ?? $module->moduleName ?? '');
    $artifact = (string) ($options['outputRelative'] ?? (preg_replace('/\.mog$/', '.php', basename($sourcePath)) ?? 'out.php'));
    $sourceMap = new SourceMapBuilder($moduleName, 'php', $artifact);
    $displaySource = normalizeDisplayPath($module->sourceFile !== '' ? $module->sourceFile : $sourcePath);
    $codegenCtx = [
        'moduleNamespace' => $namespace,
        'moduleName' => $moduleName,
        'moduleAsNames' => $importOptions['moduleAsNames'] ?? [],
        'constructorRenames' => $importOptions['constructorRenames'] ?? [],
        'externalFns' => $importOptions['externalFns'] ?? [],
        'functionArity' => $functionArity,
        'boolConstructors' => [
            // Bool is wired in: `if` desugars to a match on these, so a module
            // that never names Data.Bool must still compile them as booleans.
            'False' => false,
            'True' => true,
            ...boolConstructorMapFromRegistry($importOptions['importedData'] ?? []),
            ...boolConstructorMap($module->data),
        ],
        'newtypeConstructors' => [
            ...newtypeConstructorMapFromRegistry($importOptions['importedData'] ?? []),
            ...newtypeConstructorMap($module->data),
        ],
        'sourceMap' => $sourceMap,
        'phpNames' => $phpNames,
    ];
    $lines = ['<?php declare(strict_types=1);', ''];

    if ($namespace !== null && $namespace !== '') {
        $lines[] = "namespace {$namespace};";
        $lines[] = '';
    }

    // Where the module's source map goes: right below the namespace, once it is
    // known whether the module carries one at all.
    $mapLineIndex = \count($lines);

    if (moduleUsesPartialApply($module) || moduleUsesMoggiRuntime($module) || moduleHasMainEntry($module)) {
        // An entry module calls into the runtime from its own bootstrap
        // (`reportUncaught`), whether or not its code does.
        $runtime = $options['runtimeRequire'] ?? runtimeRequirePath($sourcePath, $options);
        $lines[] = 'require_once ' . $runtime . ';';
        $lines[] = '';
    }

    // Library-owned PHP helpers: the generically discovered `php/` dependency
    // dir for this module (`lib/<path>/php/` for `lib/<path>/PHP.mog`). Only the
    // PHP-backend module owns PHP helpers; a sibling JVM/.NET variant does not.
    $hostCompanionRequires = [];
    $hostCompanionArtifacts = [];
    $hostFiles = phpCompanionFilesForModule($sourcePath);
    if ($hostFiles !== []) {
        $artifactDir = dirname(\str_replace('\\', '/', $artifact));
        foreach ($hostFiles as $hostFile) {
            $base = basename($hostFile);
            $hostCompanionRequires[] = "require_once __DIR__ . '/php/{$base}';";
            $rel = ($artifactDir === '.' ? 'php' : $artifactDir . '/php') . '/' . $base;
            $bytes = \file_get_contents($hostFile);
            if ($bytes === false) {
                throw new \RuntimeException("cannot read PHP companion {$hostFile}");
            }
            if (isset($hostCompanionArtifacts[$rel]) && $hostCompanionArtifacts[$rel] !== $bytes) {
                throw new \RuntimeException(
                    "conflicting PHP companion artifact `{$rel}` from {$hostFile}",
                );
            }
            $hostCompanionArtifacts[$rel] = $bytes;
        }
    }
    foreach ($hostCompanionRequires as $req) {
        $lines[] = $req;
    }
    if ($hostCompanionRequires !== []) {
        $lines[] = '';
    }

    if (moduleUsesPartialApply($module)) {
        $lines[] = 'use function Moggi\\__apply;';
        $lines[] = '';
    }

    foreach ($importOptions['requireLines'] ?? [] as $requireLine) {
        $lines[] = "require_once {$requireLine};";
    }

    if (($importOptions['requireLines'] ?? []) !== []) {
        $lines[] = '';
    }

    foreach ($importOptions['functionUseLines'] ?? [] as $useLine) {
        $lines[] = "use {$useLine};";
    }

    foreach ($importOptions['namespaceUseLines'] ?? [] as $useLine) {
        $lines[] = "use {$useLine};";
    }

    if (($importOptions['functionUseLines'] ?? []) !== []
        || ($importOptions['namespaceUseLines'] ?? []) !== []) {
        $lines[] = '';
    }

    $irUsage = $options['irUsage'] ?? collectIrCodegenUsage($module);
    $usedHelpers = $irUsage['intrinsics'];
    $helperCode = phpHelpersForModule($usedHelpers);
    $codegenCtx = [
        ...$codegenCtx,
        'localIntrinsicHelpers' => localHelpersForUsed($usedHelpers),
    ];
    if ($helperCode !== '') {
        $lines[] = rtrim($helperCode, "\n");
        $lines[] = '';
    }

    foreach ($module->data as $decl) {
        $lines[] = rtrim(emitDataDecl($decl, $phpNames), "\n");
        $lines[] = '';
    }

    foreach ($module->instanceEvidence as $evidence) {
        $lines[] = rtrim(emitEvidenceFunction($evidence, $phpNames), "\n");
        $lines[] = '';
    }

    foreach ($module->functions as $function) {
        $gen = phpFunctionName($function->name, $phpNames);
        if ($namespace !== null && $namespace !== '') {
            $gen = $namespace . '\\' . $gen;
        }
        $sourceMap->ensureSymbol(
            $function->srcLoc ?? new IR\SrcLoc($moduleName, $function->name, $displaySource, 0, 0),
            $gen,
        );
        // Always emit lambda definitions. Arrow-inlining at call sites remains;
        // skipping the def breaks cross-module Partials/FnRefs (Main may hold
        // `Data.List::λ23` while List's local walk never sees that use).

        $lines[] = rtrim(emitFunction($function, $lambdaMeta, $lambdaIndex, $phpNames, $codegenCtx), "\n");
        $lines[] = '';
    }

    // The map travels inside the module as a single constant line: the runtime
    // reads it only when a frame in this module is reported, and a run that
    // never reports never touches one. The line is held back until the map is
    // known, since it can only be built from the final line numbers.
    if ($sourceMap->carriesRuntimeMap()) {
        array_splice($lines, $mapLineIndex, 0, [MAP_PLACEHOLDER, '']);
    }
    $php = rtrim(\implode("\n", $lines), "\n") . "\n";
    $php = $sourceMap->resolveFrames($php);

    $const = $sourceMap->toRuntimeConst();
    if ($const !== null) {
        $php = str_replace(MAP_PLACEHOLDER, $const, $php);
    } else {
        $php = str_replace([MAP_PLACEHOLDER . "\n\n", MAP_PLACEHOLDER . "\n"], '', $php);
    }

    $bootstrap = rtrim(emitEntryBootstrap($module, $phpNames), "\n");
    if ($bootstrap !== '') {
        $php .= "\n" . $bootstrap . "\n";
    }

    return [
        $artifact => $php,
        ...$hostCompanionArtifacts,
    ];
}

/** @param array<string, string> $phpNames */
function emitEvidenceFunction(IR\InstanceEvidence $evidence, array $phpNames): string
{
    $name = $evidence->evidenceName;
    $ctxParams = $evidence->contextParams;
    $ctxList = join(', ', \array_map(
        static fn (string $p): string => '$' . mangleVar($p),
        $ctxParams,
    ));
    $entries = [];
    foreach ($evidence->methods as $surfaceName => $irName) {
        $methodName = phpFunctionName($irName, $phpNames);
        // Capture instance-context dictionaries in the method closure so
        // `Bounded a => Bounded (Min a)` can project `maxBound` from `$__ev_Bounded`.
        if ($ctxParams === []) {
            $entries[] = '        ' . json_encode($surfaceName, JSON_UNESCAPED_UNICODE)
                . " => {$methodName}(...)";
        } else {
            $entries[] = '        ' . json_encode($surfaceName, JSON_UNESCAPED_UNICODE)
                . " => fn(...\$args) => {$methodName}({$ctxList}, ...\$args)";
        }
    }

    return "function {$name}({$ctxList}): array {\n    return [\n" . join(",\n", $entries) . "\n    ];\n}\n\n";
}

/** @param array<string, string> $phpNames */
function emitDataDecl(IR\DataDecl $decl, array $phpNames): string
{
    $out = '';

    if (isBoolBackedData($decl)) {
        $falseName = phpFunctionName($decl->constructors[0]->name, $phpNames);
        $trueName = phpFunctionName($decl->constructors[1]->name, $phpNames);
        $out .= "function {$falseName}() {\n    return false;\n}\n\n";
        $out .= "function {$trueName}() {\n    return true;\n}\n\n";

        return $out;
    }

    if ($decl->isNewtype) {
        $ctor = $decl->constructors[0];
        $name = phpFunctionName($ctor->name, $phpNames);
        $param = '$' . mangleVar($ctor->fields[0] !== '' ? $ctor->fields[0] : 'v0');
        $out .= "function {$name}({$param}) {\n    return {$param};\n}\n\n";

        return $out;
    }

    foreach ($decl->constructors as $ctor) {
        $name = phpFunctionName($ctor->name, $phpNames);
        $params = \array_map(
            static fn (string $field, int $i): string => '$' . mangleVar($field !== '' ? $field : "v{$i}"),
            $ctor->fields,
            \array_keys($ctor->fields),
        );
        $paramsCode = join(', ', $params);
        $argsCode = join(', ', \array_map(static fn (string $p): string => '$' . substr($p, 1), $params));

        if ($argsCode === '') {
            $out .= "function {$name}() {\n    return ['{$ctor->name}'];\n}\n\n";
            continue;
        }

        $out .= "function {$name}({$paramsCode}) {\n    return ['{$ctor->name}', {$argsCode}];\n}\n\n";
    }

    return $out;
}

/** @param list<IR\FunctionDecl> $functions @return array{lambdaIndex: array<string, IR\FunctionDecl>, lambdaMeta: array<string, array{captures: array<int, string>, params: array<int, string>}>, functionArity: array<string, int>} */
function analyzeFunctionsForCodegen(array $functions): array
{
    $lambdaIndex = [];
    $functionArity = [];
    foreach ($functions as $function) {
        if (isCapturedFnName($function->name)) {
            $lambdaIndex[$function->name] = $function;
            continue;
        }

        $functionArity[$function->name] = count($function->params);
    }

    return [
        'lambdaIndex' => $lambdaIndex,
        'lambdaMeta' => lambdaMetaFromCaptures(
            $lambdaIndex,
            // The function's free locals; the shared helper owns the
            // transitive closure. Scope-aware, so a name a match arm binds in
            // the body is not mistaken for a capture.
            static fn (IR\FunctionDecl $fn): array => freeLocalsInBlock(
                $fn->body,
                array_fill_keys($fn->params, true),
            ),
        ),
        'functionArity' => $functionArity,
    ];
}

/** @param IR\FunctionDecl $function @param array<string, array{captures: array<int, string>, params: array<int, string>}> $lambdaMeta @param array<string, IR\FunctionDecl> $lambdaIndex @param array<string, string> $phpNames @param array{externalFns: array<string, string>, moduleAsNames?: array<string, string>, constructorRenames?: array<string, string>, functionArity?: array<string, int>} $codegenCtx */
function emitFunction(IR\FunctionDecl $function, array $lambdaMeta, array $lambdaIndex, array $phpNames, array $codegenCtx = []): string
{
    $name = phpFunctionName($function->name, $phpNames);
    $params = $function->params;

    if (isCapturedFnName($function->name)) {
        $captures = $lambdaMeta[$function->name]['captures'] ?? [];
        $params = [...$captures, ...$params];
    }

    $paramsCode = join(', ', \array_map(static fn (string $p): string => '$' . mangleVar($p), $params));
    $moduleName = (string) ($codegenCtx['moduleName'] ?? $codegenCtx['moduleNamespace'] ?? '');
    if (str_contains($moduleName, '\\')) {
        $moduleName = str_replace('\\', '.', $moduleName);
    }
    $body = emitBlock($function->body, 1, [
        'lambdaMeta' => $lambdaMeta,
        'lambdaIndex' => $lambdaIndex,
        'phpNames' => $phpNames,
        'moduleNamespace' => $codegenCtx['moduleNamespace'] ?? null,
        'moduleName' => $codegenCtx['moduleName'] ?? $moduleName,
        'externalFns' => $codegenCtx['externalFns'] ?? [],
        'moduleAsNames' => $codegenCtx['moduleAsNames'] ?? [],
        'constructorRenames' => $codegenCtx['constructorRenames'] ?? [],
        'boolConstructors' => $codegenCtx['boolConstructors'] ?? [],
        'newtypeConstructors' => $codegenCtx['newtypeConstructors'] ?? [],
        'functionArity' => $codegenCtx['functionArity'] ?? [],
        'localIntrinsicHelpers' => $codegenCtx['localIntrinsicHelpers'] ?? [],
        'functionParams' => $params,
        'tailRecParams' => $function->params,
        'sourceMap' => $codegenCtx['sourceMap'] ?? null,
    ]);

    return "function {$name}({$paramsCode}) {\n"
        . $body
        . "}\n\n";
}

function emitTailRecall(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $params = $ctx['tailRecParams'] ?? [];
    $args = $stmt->args;
    $argExprs = \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $args);
    $order = tailRecallAssignOrder($params, $args);
    $out = '';

    if ($order === null) {
        foreach (\array_keys($params) as $i) {
            $out .= $pad . '$__tail' . $i . ' = ' . $argExprs[$i] . ";\n";
        }
        foreach ($params as $i => $param) {
            $out .= $pad . '$' . mangleVar($param) . ' = $__tail' . $i . ";\n";
        }

        return $out . $pad . "continue;\n";
    }

    foreach ($order as $i) {
        $out .= $pad . '$' . mangleVar($params[$i]) . ' = ' . $argExprs[$i] . ";\n";
    }

    return $out . $pad . "continue;\n";
}

/** @param array<int, string> $params @param list<IR\Operand> $args @return ?array<int, int> */
function tailRecallAssignOrder(array $params, array $args): ?array
{
    $n = count($params);
    if ($n <= 1) {
        return range(0, max(0, $n - 1));
    }

    $paramIndex = [];
    foreach ($params as $i => $param) {
        $paramIndex[$param] = $i;
    }

    $mustAssignFirst = \array_fill(0, $n, []);
    for ($i = 0; $i < $n; ++$i) {
        foreach (collectLocalsInOperand($args[$i]) as $local) {
            if (!isset($paramIndex[$local])) {
                continue;
            }

            $j = $paramIndex[$local];
            if ($j !== $i) {
                $mustAssignFirst[$j][] = $i;
            }
        }
    }

    $inDegree = \array_fill(0, $n, 0);
    foreach ($mustAssignFirst as $j => $predecessors) {
        $predecessors = array_values(array_unique($predecessors));
        $mustAssignFirst[$j] = $predecessors;
        $inDegree[$j] = count($predecessors);
    }

    $queue = [];
    for ($i = 0; $i < $n; ++$i) {
        if ($inDegree[$i] === 0) {
            $queue[] = $i;
        }
    }

    $order = [];
    while ($queue !== []) {
        $i = array_shift($queue);
        $order[] = $i;
        for ($j = 0; $j < $n; ++$j) {
            if (!\in_array($i, $mustAssignFirst[$j], true)) {
                continue;
            }

            --$inDegree[$j];
            if ($inDegree[$j] === 0) {
                $queue[] = $j;
            }
        }
    }

    return count($order) === $n ? $order : null;
}

function emitBlock(IR\Block $block, int $indent, array $ctx): string
{
    $out = '';

    foreach ($block->items as $item) {
        $out .= emitStmt($item, $indent, $ctx);
    }

    return $out;
}

/**
 * Zero-width marker recording that the following generated line is a stack
 * frame for `$loc`. Stripped (and turned into a line mapping the module's map
 * constant carries) by SourceMapBuilder::resolveFrames().
 */
function frameMark(array $ctx, ?IR\SrcLoc $loc): string
{
    $map = $ctx['sourceMap'] ?? null;
    if (!$map instanceof SourceMapBuilder || $loc === null) {
        return '';
    }

    return $map->allocFrame($loc, $loc->function);
}

/** The first tail-call argument that is itself a call or a faulting intrinsic. */
function tailRecallSrcLoc(IR\Stmt $stmt): ?IR\SrcLoc
{
    if (!($stmt instanceof IR\TailRecall)) {
        return null;
    }
    foreach ($stmt->args as $arg) {
        $loc = operandCallSrcLoc($arg);
        if ($loc !== null) {
            return $loc;
        }
    }

    return null;
}

function emitStmt(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);

    return match ($stmt::class) {
        // A statement is attributed to the innermost call or faulting intrinsic
        // it evaluates, else to its own site: a `Ret` whose value is a plain
        // function (no call site of its own) still needs a frame, or the host
        // reports the generated line under its PHP name with no `.mog` mapping.
        IR\Ret::class => frameMark($ctx, operandCallSrcLoc($stmt->value) ?? ($stmt->srcLoc ?? null))
            . $pad . 'return ' . emitRetValue($stmt->value, $ctx) . ";\n",
        IR\Binop::class => frameMark($ctx, operandCallSrcLoc($stmt->left) ?? operandCallSrcLoc($stmt->right) ?? ($stmt->srcLoc ?? null))
            . $pad . phpTemp($stmt->dest) . ' = ' . emitOperand($stmt->left, $ctx)
            . ' ' . phpBinop($stmt->op) . ' ' . emitOperand($stmt->right, $ctx) . ";\n",
        IR\Call::class => frameMark($ctx, $stmt->srcLoc) . emitCall($stmt->callee, $stmt->args, $stmt->dest, $indent, $ctx),
        IR\IoCall::class => frameMark($ctx, $stmt->srcLoc) . emitIoCall($stmt, $indent, $ctx),
        IR\IoRun::class => frameMark($ctx, $stmt->srcLoc) . emitIoRun($stmt, $indent, $ctx),
        IR\IoAssignAction::class => frameMark($ctx, $stmt->srcLoc) . emitIoAssignAction($stmt, $indent, $ctx),
        IR\IoThrow::class => emitIoThrow($stmt, $indent, $ctx),
        IR\IoCatch::class => emitIoCatch($stmt, $indent, $ctx),
        IR\IoFinally::class => emitIoFinally($stmt, $indent, $ctx),
        IR\CallValue::class => frameMark($ctx, $stmt->srcLoc) . $pad . phpTemp($stmt->dest) . ' = ' . emitApplyExpr($stmt->callee, $stmt->args, $ctx) . ";\n",
        IR\DictCall::class => frameMark($ctx, $stmt->srcLoc) . $pad . phpTemp($stmt->dest) . ' = (' . emitOperand($stmt->evidence, $ctx)
            . '[' . json_encode($stmt->method, JSON_UNESCAPED_UNICODE) . '])('
            . join(', ', \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $stmt->args))
            . ");\n",
        IR\Assign::class => frameMark($ctx, operandCallSrcLoc($stmt->value) ?? ($stmt->srcLoc ?? null))
            . $pad . phpTemp($stmt->dest) . ' = ' . emitOperand($stmt->value, $ctx) . ";\n",
        IR\Let::class => frameMark($ctx, operandCallSrcLoc($stmt->value) ?? ($stmt->srcLoc ?? null))
            . $pad . '$' . mangleVar($stmt->name) . ' = ' . emitOperand($stmt->value, $ctx) . ";\n",
        IR\MatchStmt::class => emitMatchStmt($stmt, $indent, $ctx, isReturn: false),
        IR\MatchReturn::class => emitMatchStmt($stmt, $indent, $ctx, isReturn: true),
        IR\IoMatch::class => emitIfMatch($stmt, $indent, $ctx),
        IR\Loop::class => $pad . "while (true) {\n" . emitBlock($stmt->body, $indent + 1, $ctx) . $pad . "}\n",
        IR\TailRecall::class => frameMark($ctx, tailRecallSrcLoc($stmt) ?? ($stmt->srcLoc ?? null))
            . emitTailRecall($stmt, $indent, $ctx),
        default => throw new \RuntimeException("unsupported ir stmt `" . $stmt::class . "` in codegen"),
    };
}

function emitIoCall(IR\Stmt $stmt, int $indent, array $ctx): string
{
    $pad = str_repeat('    ', $indent);
    $args = \array_map(static fn (IR\Operand $arg): string => emitOperand($arg, $ctx), $stmt->args);
    $dest = $stmt->dest;
    $assign = $dest === null ? '' : phpTemp($dest) . ' = ';
    $runtime = $stmt->runtime ?? null;

    if (\is_string($stmt->intrinsic) && $stmt->intrinsic !== '') {
        $leaves = \array_map(
            static fn (IR\Operand $arg): bool => isLeafOperand($arg),
            $stmt->args,
        );
        $expr = emitIntrinsicCall($stmt->intrinsic, $args, [], null, $ctx, $leaves);

        return $pad . $assign . $expr . ";\n";
    }

    if (\is_string($runtime) && str_starts_with($runtime, 'foreign:')) {
        $foreign = $stmt->foreign;
        if (!($foreign instanceof IR\ForeignCall)) {
            throw new \RuntimeException('foreign IO call missing metadata');
        }

        return emitForeignIoStatement(
            $foreign,
            $args,
            $dest,
            $pad,
            substr($runtime, strlen('foreign:')),
        );
    }

    return match ($runtime) {
        'stdout' => $pad . 'echo(' . $args[0] . ");\n",
        'stdout_line' => $pad . 'echo(' . $args[0] . ' . "\\n"' . ");\n",
        default => $pad . $assign . emitCallExpr($stmt->callee, $stmt->args, $ctx) . ";\n",
    };
}

/** @return list<string> */
function localsUsedInBlock(IR\Block $block): array
{
    return collectLocalsInBlock($block);
}

function runtimeRequirePath(string $sourcePath, array $options = []): string
{
    $relative = $options['outputRelative'] ?? $sourcePath;
    $relative = \str_replace('\\', '/', $relative);
    $dir = dirname($relative);
    $depth = ($dir === '.' || $dir === '') ? 0 : substr_count($dir, '/') + 1;

    return "__DIR__ . '/" . str_repeat('../', $depth) . RUNTIME_OUTPUT_PATH . "'";
}

/** Whether the module carries the application entry an emitted bootstrap auto-runs. */
function moduleHasMainEntry(IR\Module $module): bool
{
    return $module->entry !== null && $module->entry->kind === IR\EntryPointKind::Main;
}

/** @param IR\Module $module @param array<string, string> $phpNames */
function emitEntryBootstrap(IR\Module $module, array $phpNames): string
{
    $entry = $module->entry;
    // Only application Main auto-runs. ReplExpression is invoked by the REPL loader.
    if (!moduleHasMainEntry($module)) {
        return '';
    }

    $mainName = phpFunctionName($entry->name, $phpNames);

    return "\ntry {\n    {$mainName}();\n} catch (\\Throwable \$__moggiUncaught) {\n"
        . "    \\Moggi\\reportUncaught(\$__moggiUncaught);\n"
        . "}\n";
}
