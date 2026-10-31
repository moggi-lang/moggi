<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;

require_once __DIR__ . '/codegen.php';
require_once __DIR__ . '/emit_env.php';
require_once __DIR__ . '/emit_stmt.php';
require_once __DIR__ . '/emit_expr.php';
require_once __DIR__ . '/emit_call.php';
require_once __DIR__ . '/emit_fn_value.php';
require_once __DIR__ . '/emit_value.php';
require_once __DIR__ . '/emit_literal.php';
require_once __DIR__ . '/emit_intrinsic.php';
require_once __DIR__ . '/emit_primop.php';
require_once __DIR__ . '/emit_foreign.php';
require_once __DIR__ . '/emit_match.php';
require_once __DIR__ . '/emit_pattern.php';
require_once __DIR__ . '/emit_data.php';
require_once __DIR__ . '/emit_io.php';
require_once __DIR__ . '/naming.php';
require_once __DIR__ . '/foreign.php';
require_once __DIR__ . '/dependencies.php';
const MAP_PLACEHOLDER = '//@@moggi-source-map@@';


use const Moggi\Backend\Php\Dependencies\COMPOSER_AUTOLOAD;
use function Moggi\Backend\Php\Dependencies\composerIsBundled;
use function Moggi\Backend\Php\Dependencies\phpCompanionFilesForModule;
use function Moggi\Backend\Meta\boolConstructorMap;
use function Moggi\Backend\Meta\boolConstructorMapFromRegistry;
use function Moggi\Backend\Meta\constructorArityMap;
use function Moggi\Backend\Meta\constructorArityMapFromRegistry;
use function Moggi\Backend\Meta\newtypeConstructorMap;
use function Moggi\Backend\Meta\newtypeConstructorMapFromRegistry;
use function Moggi\Backend\Meta\tempReadCounts;
use function Moggi\Backend\Php\Naming\buildPhpNameMap;
use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpFunctionName;
use const Moggi\Backend\Php\RUNTIME_OUTPUT_PATH;
use function Moggi\Debug\normalizeDisplayPath;
use Moggi\Debug\SourceMapBuilder;
use Moggi\IR;
use Moggi\Modules\EmitPlan;
use function Moggi\IR\Callables\captureMeta;
use function Moggi\IR\Visit\collectIrCodegenUsage;
use function Moggi\IR\Visit\moduleUsesMoggiRuntime;
use function Moggi\Optimize\Partial\moduleUsesPartialApply;
use Moggi\IR\SrcLoc;
use function Moggi\Backend\Php\Codegen\emitBlock;

/**
 * @param IR\Module $module
 * @param array{namespace?: ?string, outputRelative?: string, artifactModuleName?: string, runtimeRequire?: string, plan: EmitPlan, irUsage?: array<string, mixed>, callableTables?: array<string, \Moggi\IR\Callables\CallableTable>} $options
 * @return array<string, string>
 */
function emitModule(IR\Module $module, string $sourcePath, array $options = []): array
{
    $namespace = $options['namespace'] ?? null;
    $analysis = analyzeFunctionsForCodegen($module->functions);
    $lambdaIndex = $analysis['lambdaIndex'];
    $lambdaMeta = $analysis['lambdaMeta'];
    $phpNames = buildPhpNameMap($module);
    $plan = $options['plan'] ?? null;
    if (! $plan instanceof EmitPlan) {
        throw new \RuntimeException(
            "emitting `{$module->moduleName}` needs its EmitPlan",
        );
    }
    $functionArity = $analysis['functionArity'];
    $localBindings = [];
    foreach ($module->functions as $fn) {
        $localBindings[$fn->name] = true;
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
    foreach ($module->instanceEvidence as $evidence) {
        $functionArity[$evidence->evidenceName] = \count($evidence->contextParams);
    }
    foreach ($plan->externalFnRuntimeArity as $name => $runtimeArity) {
        if (! isset($localBindings[$name])) {
            $functionArity[$name] = $runtimeArity;
        }
    }
    $moduleName = (string) ($options['artifactModuleName'] ?? $module->moduleName ?? '');
    $artifact = (string) ($options['outputRelative'] ?? (\preg_replace('/\.mog$/', '.php', \basename($sourcePath)) ?? 'out.php'));
    $sourceMap = new SourceMapBuilder($moduleName, 'php', $artifact);
    $displaySource = normalizeDisplayPath($module->sourceFile !== '' ? $module->sourceFile : $sourcePath);
    $env = new EmitEnv(
        moduleNamespace: $namespace,
        artifactModuleName: $moduleName,
        moduleAsNames: $plan->moduleAsNames,
        constructorRenames: $plan->constructorRenames,
        externalFns: $plan->externalFns,
        functionArity: $functionArity,
        boolConstructors: [
            ...boolConstructorMapFromRegistry($plan->importedData),
            ...boolConstructorMap($module->data),
        ],
        newtypeConstructors: [
            ...newtypeConstructorMapFromRegistry($plan->importedData),
            ...newtypeConstructorMap($module->data),
        ],
        phpNames: $phpNames,
        sourceMap: $sourceMap,
    );
    $env->lambdaIndex = $lambdaIndex;
    $env->lambdaMeta = $lambdaMeta;
    $lines = ['<?php declare(strict_types=1);', ''];

    if ($namespace !== null && $namespace !== '') {
        $lines[] = "namespace {$namespace};";
        $lines[] = '';
    }

    if (moduleHasMainEntry($module)) {
        $lines[] = 'if (\\PHP_INT_SIZE < 8) {';
        $lines[] = '    \\fwrite(\\STDERR, "moggi: this program requires 64-bit PHP (PHP_INT_SIZE=8); detected " . \\PHP_INT_SIZE . "-byte int\\n");';
        $lines[] = '    exit(70);';
        $lines[] = '}';
        $lines[] = '';
    }

    $mapLineIndex = \count($lines);

    if (moduleUsesPartialApply($module) || moduleUsesMoggiRuntime($module) || moduleHasMainEntry($module)) {
        $runtime = $options['runtimeRequire'] ?? runtimeRequirePath($sourcePath, $options);
        $lines[] = 'require_once ' . $runtime . ';';

        if (moduleHasMainEntry($module) && composerIsBundled()) {
            $lines[] = 'require_once ' . ($options['composerAutoloadRequire']
                ?? composerAutoloadRequirePath($sourcePath, $options)) . ';';
        }

        $lines[] = '';
    }

    $hostCompanionRequires = [];
    $hostCompanionArtifacts = [];
    $hostFiles = phpCompanionFilesForModule($sourcePath);
    if ($hostFiles !== []) {
        $artifactDir = \dirname(\str_replace('\\', '/', $artifact));
        foreach ($hostFiles as $hostFile) {
            $base = \basename($hostFile);
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

    foreach ($plan->requireLines as $requireLine) {
        $lines[] = "require_once {$requireLine};";
    }

    if ($plan->requireLines !== []) {
        $lines[] = '';
    }

    foreach ($plan->functionUseLines as $useLine) {
        $lines[] = "use {$useLine};";
    }

    foreach ($plan->namespaceUseLines as $useLine) {
        $lines[] = "use {$useLine};";
    }

    if ($plan->functionUseLines !== [] || $plan->namespaceUseLines !== []) {
        $lines[] = '';
    }

    $irUsage = $options['irUsage'] ?? collectIrCodegenUsage($module);
    $helperCode = phpHelpersForModule($irUsage['intrinsics']);
    if ($helperCode !== '') {
        $lines[] = \rtrim($helperCode, "\n");
        $lines[] = '';
    }

    foreach ($module->data as $decl) {
        $lines[] = \rtrim(emitDataDecl($decl, $phpNames), "\n");
        $lines[] = '';
    }

    foreach ($module->instanceEvidence as $evidence) {
        $lines[] = \rtrim(emitEvidence($evidence, $phpNames), "\n");
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

        $lines[] = \rtrim(emitFunction($function, $env), "\n");
        $lines[] = '';
    }

    if ($sourceMap->carriesRuntimeMap()) {
        \array_splice($lines, $mapLineIndex, 0, [MAP_PLACEHOLDER, '']);
    }
    $php = \rtrim(\implode("\n", $lines), "\n") . "\n";
    $php = $sourceMap->resolveFrames($php);

    $const = $sourceMap->toRuntimeConst();
    if ($const !== null) {
        $php = \str_replace(MAP_PLACEHOLDER, $const, $php);
    } else {
        $php = \str_replace([MAP_PLACEHOLDER . "\n\n", MAP_PLACEHOLDER . "\n"], '', $php);
    }

    $bootstrap = \rtrim(emitMain($module, $phpNames), "\n");
    if ($bootstrap !== '') {
        $php .= "\n" . $bootstrap . "\n";
    }

    return [
        $artifact => $php,
        ...$hostCompanionArtifacts,
    ];
}

/** @param array<string, string> $phpNames */
function emitEvidence(IR\InstanceEvidence $evidence, array $phpNames): string
{
    $name = phpFunctionName($evidence->evidenceName, $phpNames);
    $contextParams = $evidence->contextParams;
    $contextList = \implode(', ', \array_map(
        static fn (string $p): string => '$' . mangleVar($p),
        $contextParams,
    ));
    $entries = [];
    foreach ($evidence->methods as $surfaceName => $irName) {
        $methodName = phpFunctionName($irName, $phpNames);
        if ($contextParams === []) {
            $entries[] = '        ' . \json_encode($surfaceName, JSON_UNESCAPED_UNICODE)
                . " => {$methodName}(...)";
        } else {
            $entries[] = '        ' . \json_encode($surfaceName, JSON_UNESCAPED_UNICODE)
                . " => fn(...\$args) => {$methodName}({$contextList}, ...\$args)";
        }
    }

    return "function {$name}({$contextList}): array {\n    return [\n" . \implode(",\n", $entries) . "\n    ];\n}\n\n";
}

/** @param list<IR\FunctionDecl> $functions @return array{lambdaIndex: array<string, IR\FunctionDecl>, lambdaMeta: array<string, array{captures: array<int, string>, params: array<int, string>}>, functionArity: array<string, int>} */
function analyzeFunctionsForCodegen(array $functions): array
{
    $lambdaIndex = [];
    $functionArity = [];
    foreach ($functions as $function) {
        if ($function->shape->kind->takesCaptures()) {
            $lambdaIndex[$function->name] = $function;

            continue;
        }

        $functionArity[$function->name] = \count($function->params);
    }

    return [
        'lambdaIndex' => $lambdaIndex,
        'lambdaMeta' => captureMeta($lambdaIndex),
        'functionArity' => $functionArity,
    ];
}

/** @param IR\FunctionDecl $function @param EmitEnv $env the module's env, cloned into the one this function's body emits through */
function emitFunction(IR\FunctionDecl $function, EmitEnv $env): string
{
    $name = phpFunctionName($function->name, $env->phpNames);
    $params = $function->params;

    $namespace = (string) ($env->moduleNamespace ?? '');
    $generated = $namespace !== '' ? $namespace . '\\' . $name : $name;

    if ($env->sourceMap instanceof SourceMapBuilder
        && $function->shape->kind === IR\FunctionKind::Worker
    ) {
        $env->sourceMap->markGeneratedFrame($generated, false);
        if (isset($env->phpNames[(string) $function->shape->origin])) {
            $entry = phpFunctionName((string) $function->shape->origin, $env->phpNames);
            $env->sourceMap->markGeneratedFrame($namespace !== '' ? $namespace . '\\' . $entry : $entry, true);
        }
    }

    if ($function->shape->kind->takesCaptures()) {
        $captures = $env->lambdaMeta[$function->name]['captures'] ?? [];
        $params = [...$captures, ...$params];
    }

    $paramsCode = \implode(', ', \array_map(static fn (string $p): string => '$' . mangleVar($p), $params));
    $fnEnv = clone $env;
    $fnEnv->functionParams = $params;
    $fnEnv->tailRecParams = $function->params;
    $fnEnv->frameFunction = $generated;
    $fnEnv->tempReads = tempReadCounts($function->body);
    $body = emitBlock($function->body, 1, $fnEnv);

    return "function {$name}({$paramsCode}) {\n"
        . $body
        . "}\n\n";
}

function runtimeRequirePath(string $sourcePath, array $options = []): string
{
    $relative = $options['outputRelative'] ?? $sourcePath;
    $relative = \str_replace('\\', '/', $relative);
    $dir = \dirname($relative);
    $depth = ($dir === '.' || $dir === '') ? 0 : \substr_count($dir, '/') + 1;

    return "__DIR__ . '/" . \str_repeat('../', $depth) . RUNTIME_OUTPUT_PATH . "'";
}

/**
 * Where the entry module reaches Composer's autoloader, relative to itself.
 *
 * Same depth arithmetic as the runtime require, pointed at the `vendor/`
 * directory the build copies in beside it. Inside a PHAR the path resolves
 * through the same `phar://` prefix, so one expression covers both artifact
 * shapes.
 */
function composerAutoloadRequirePath(string $sourcePath, array $options = []): string
{
    $relative = $options['outputRelative'] ?? $sourcePath;
    $relative = \str_replace('\\', '/', $relative);
    $dir = \dirname($relative);
    $depth = ($dir === '.' || $dir === '') ? 0 : \substr_count($dir, '/') + 1;

    return "__DIR__ . '/" . \str_repeat('../', $depth) . COMPOSER_AUTOLOAD . "'";
}

/** Whether the module carries the application entry an emitted bootstrap auto-runs. */
function moduleHasMainEntry(IR\Module $module): bool
{
    return $module->entry !== null && $module->entry->kind === IR\EntryPointKind::Main;
}

/** @param IR\Module $module @param array<string, string> $phpNames */
function emitMain(IR\Module $module, array $phpNames): string
{
    $entry = $module->entry;
    if (! moduleHasMainEntry($module)) {
        return '';
    }

    $mainName = phpFunctionName($entry->name, $phpNames);
    $call = entryReturnsAction($module)
        ? "\$__moggiEntry = {$mainName}();\n    (\$__moggiEntry[1])();"
        : "{$mainName}();";

    return "\ntry {\n    {$call}\n} catch (\\Throwable \$__moggiUncaught) {\n"
        . "    \\Moggi\\reportUncaught(\$__moggiUncaught);\n"
        . "}\n";
}

/** Whether the module's entry answers with an action value — its own published convention. */
function entryReturnsAction(IR\Module $module): bool
{
    foreach ($module->functions as $function) {
        if ($function->name === $module->entry?->name) {
            return $function->shape->actionConvention === IR\ActionConvention::ReturnsAction;
        }
    }

    throw new \LogicException('the entry `' . ($module->entry?->name ?? '?') . '` has no lowered declaration');
}
