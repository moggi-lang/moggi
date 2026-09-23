<?php declare(strict_types=1);

namespace Moggi\Compiler;

// The whole-project typed AST + compile cache blobs can exceed PHP's stock 128M
// limit on larger closures; raise it (only if the current limit is lower).
(static function (): void {
    $current = trim((string) ini_get('memory_limit'));
    if ($current === '-1') {
        return;
    }
    $bytes = (static function (string $v): int {
        if ($v === '') {
            return 0;
        }
        $unit = strtolower($v[strlen($v) - 1]);
        $n = (int) $v;
        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    })($current);
    if ($bytes > 0 && $bytes < 1024 * 1024 * 1024) {
        ini_set('memory_limit', '1024M');
    }
})();

require __DIR__ . '/errors.php';
require __DIR__ . '/cache/cache.php';
require __DIR__ . '/version.php';
require __DIR__ . '/executables.php';
require __DIR__ . '/paths.php';
require __DIR__ . '/IR/types.php';
require __DIR__ . '/pipeline/types.php';
require __DIR__ . '/syntax/lexer.php';
require __DIR__ . '/syntax/names.php';
require __DIR__ . '/syntax/ast.php';
require __DIR__ . '/syntax/fixity.php';
require __DIR__ . '/syntax/parser.php';
require __DIR__ . '/semantics/type_expr.php';
require __DIR__ . '/patterns/walk.php';
require __DIR__ . '/semantics/evidence_names.php';
require __DIR__ . '/semantics/types.php';
require __DIR__ . '/semantics/kinds.php';
require_once __DIR__ . '/semantics/exhaustiveness.php';
require __DIR__ . '/semantics/intrinsic_registry.php';
require __DIR__ . '/semantics/io_boundary.php';
require __DIR__ . '/semantics/strict_io_normalize.php';
require __DIR__ . '/semantics/io_escape.php';
require __DIR__ . '/foreign/path.php';
require __DIR__ . '/semantics/foreign.php';
require __DIR__ . '/semantics/effects.php';
require __DIR__ . '/IR/lower.php';
require __DIR__ . '/IR/dump.php';
require __DIR__ . '/IR/visit.php';
require __DIR__ . '/debug/sourcemap.php';
require __DIR__ . '/pipeline/pipeline.php';
require __DIR__ . '/optimize/support.php';
require __DIR__ . '/optimize/ir_transform.php';
require __DIR__ . '/optimize/global_pass.php';
require __DIR__ . '/optimize/local.php';
require __DIR__ . '/optimize/tco.php';
require __DIR__ . '/optimize/interproc.php';
require __DIR__ . '/optimize/effects.php';
require __DIR__ . '/optimize/partial.php';
require __DIR__ . '/optimize/intrinsic.php';
require __DIR__ . '/optimize/dict.php';
require __DIR__ . '/optimize/case_fold.php';
require __DIR__ . '/optimize/specialize.php';
require __DIR__ . '/optimize/fold_intrinsic.php';
require __DIR__ . '/optimize/io_specialize.php';
require __DIR__ . '/optimize/tree_shake.php';
require __DIR__ . '/optimize/optimize.php';
require __DIR__ . '/backend/ir_meta.php';
require __DIR__ . '/backend/php/intrinsics.php';
require __DIR__ . '/backend/php/foreign.php';
require __DIR__ . '/backend/php/io.php';
require __DIR__ . '/backend/php/codegen.php';
require __DIR__ . '/backend/Backend.php';
require __DIR__ . '/backend/inspect.php';
require __DIR__ . '/backend/php/package.php';
require __DIR__ . '/backend/php/PhpBackend.php';
require __DIR__ . '/backend/jvm/JvmBackend.php';
require __DIR__ . '/backend/dotnet/DotNetBackend.php';
require __DIR__ . '/backend/codegen.php';
require __DIR__ . '/modules/types.php';
require __DIR__ . '/modules/paths.php';
require __DIR__ . '/modules/library.php';
require __DIR__ . '/modules/facade.php';
require __DIR__ . '/modules/exports.php';
require __DIR__ . '/modules/prim_modules.php';
require __DIR__ . '/modules/graph.php';
require __DIR__ . '/modules/imports.php';
require __DIR__ . '/modules/typecheck_env.php';
require __DIR__ . '/modules/prepare.php';
require __DIR__ . '/modules/modules.php';
require __DIR__ . '/syntax/parse_repl.php';
require __DIR__ . '/repl/state.php';
require __DIR__ . '/repl/input.php';
require __DIR__ . '/repl/shadow.php';
require __DIR__ . '/repl/session.php';
require __DIR__ . '/repl/compile.php';
require __DIR__ . '/repl/inspect.php';
require __DIR__ . '/repl/eval_runner.php';
require __DIR__ . '/repl/commands.php';
require __DIR__ . '/repl/repl.php';
require __DIR__ . '/CLI/usage.php';
require __DIR__ . '/CLI/args.php';
require __DIR__ . '/CLI/docs_args.php';
require __DIR__ . '/CLI/paths.php';
require __DIR__ . '/docs/scheme_fmt.php';
require __DIR__ . '/docs/markup.php';
require __DIR__ . '/docs/style.php';
require __DIR__ . '/docs/project.php';
require __DIR__ . '/docs/exports.php';
require __DIR__ . '/docs/reexports.php';
require __DIR__ . '/docs/index.php';
require __DIR__ . '/docs/mogdoc.php';
require __DIR__ . '/docs/moogle_search.php';
require __DIR__ . '/docs/emit_moogle_js.php';
require __DIR__ . '/docs/moogle.php';
require __DIR__ . '/docs/serve.php';
// LSP — load order = dependency order (support layers first, then
// feature handlers, then the server loop last).
require __DIR__ . '/lsp/protocol/transport.php';
require __DIR__ . '/lsp/protocol/positions.php';
require __DIR__ . '/lsp/protocol/messages.php';
require __DIR__ . '/lsp/index/occurrences.php';
require __DIR__ . '/lsp/index/tokenMap.php';
require __DIR__ . '/lsp/index/moduleIndex.php';
require __DIR__ . '/lsp/analysis/analyzer.php';
require __DIR__ . '/lsp/analysis/diagnostics.php';
require __DIR__ . '/lsp/analysis/service.php';
require __DIR__ . '/lsp/formatter/format.php';
require __DIR__ . '/lsp/textdocument/hierarchy.php';
require __DIR__ . '/lsp/textdocument/hover.php';
require __DIR__ . '/lsp/textdocument/navigation.php';
require __DIR__ . '/lsp/textdocument/symbols.php';
require __DIR__ . '/lsp/textdocument/records.php';
require __DIR__ . '/lsp/textdocument/completion.php';
require __DIR__ . '/lsp/textdocument/signature.php';
require __DIR__ . '/lsp/textdocument/semanticTokens.php';
require __DIR__ . '/lsp/textdocument/hints.php';
require __DIR__ . '/lsp/textdocument/color.php';
require __DIR__ . '/lsp/textdocument/formatting.php';
require __DIR__ . '/lsp/textdocument/rename.php';
require __DIR__ . '/lsp/textdocument/codeAction.php';
require __DIR__ . '/lsp/textdocument/codeLens.php';
require __DIR__ . '/lsp/textdocument/moniker.php';
require __DIR__ . '/lsp/textdocument/diagnostics.php';
require __DIR__ . '/lsp/workspace/fileOperations.php';
require __DIR__ . '/lsp/workspace/configuration.php';
require __DIR__ . '/lsp/workspace/watchedFiles.php';
require __DIR__ . '/lsp/capabilities.php';
require __DIR__ . '/lsp/progress.php';
require __DIR__ . '/lsp/staleness.php';
require __DIR__ . '/lsp/sync.php';
require __DIR__ . '/lsp/lifecycle.php';
require __DIR__ . '/lsp/server.php';

require __DIR__ . '/CLI/commands/compile.php';
require __DIR__ . '/CLI/commands/run.php';
require __DIR__ . '/CLI/commands/cache.php';
require __DIR__ . '/CLI/commands/repl.php';
require __DIR__ . '/CLI/commands/mogdoc.php';
require __DIR__ . '/CLI/commands/moogle.php';
require __DIR__ . '/CLI/commands/lsp.php';
require __DIR__ . '/CLI/commands/version.php';
require __DIR__ . '/CLI/main.php';

use Moggi\Pipeline\CompilePurpose;
use Moggi\Pipeline\PipelineRequest;
use Moggi\Pipeline\PipelineStage;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast\Program;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\Backend\setCompileBackend;
use function Moggi\Modules\compileModuleFile;
use function Moggi\Modules\compileModuleFileOutputs;
use function Moggi\Modules\isModuleSourceProgram;
use function Moggi\Modules\moduleCompileContext;
use function Moggi\Pipeline\run as pipelineRun;
use function Moggi\Semantics\Effects\checkAndNormalize;
use function Moggi\Pipeline\selectOutput;
use function Moggi\Pipeline\stageFromOutputMode;
use function Moggi\IR\Dump\dump as dumpIr;
use function Moggi\Syntax\Ast\dump as dumpAst;
use function Moggi\Syntax\Lexer\dump as dumpTokens;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

/** @return 'php'|'emit'|'ir'|'opt-ir'|'tokens'|'ast'|'typed-ast' */
function normalizeOutputMode(string $mode): string
{
    return match ($mode) {
        // 'php' remains a CLI alias for emitted backend output.
        'php', 'emit', 'ir', 'opt-ir', 'tokens', 'ast', 'typed-ast' => $mode,
        default => throw new \InvalidArgumentException("unknown output mode `{$mode}`"),
    };
}

/** A mode that prints one pre-lowering stage of a source instead of compiling it. */
function isIntrospectionMode(string $mode): bool
{
    return $mode === 'tokens' || $mode === 'ast' || $mode === 'typed-ast';
}

/**
 * Dump one introspection stage of a source: its token stream, its parse tree, or its typed tree.
 *
 * The CLI flags and the suite's `tokens`/`ast`/`typed-ast` goldens both come through here, so what a
 * flag prints and what a golden pins cannot drift apart.
 */
function dumpSourceStage(string $source, string $filename, string $stage): string
{
    $tokens = lex($source, $filename);
    if ($stage === 'tokens') {
        return dumpTokens($tokens);
    }

    $program = parse($tokens, $source, $filename);
    if ($stage === 'ast') {
        return dumpAst($program);
    }

    $typed = moduleCompileContext($filename, $program)['checked'];

    return dumpAst($typed);
}

function applyCompileBackend(?string $backend): void
{
    if ($backend !== null) {
        setCompileBackend($backend);
    }
}

function compile(string $source, string $filename, string $mode = 'php', bool $optimize = true, ?Program $program = null, ?string $backend = null): string|array
{
    applyCompileBackend($backend);
    $mode = normalizeOutputMode($mode);
    if (isIntrospectionMode($mode)) {
        return dumpSourceStage($source, $filename, $mode);
    }

    if ($program === null) {
        $program = parse(lex($source, $filename), $source, $filename);
    }

    $purpose = ($program->module === 'Main')
        ? CompilePurpose::Executable
        : CompilePurpose::Library;

    $stopAt = stageFromOutputMode($mode);

    $artifacts = pipelineRun(new PipelineRequest(
        purpose: $purpose,
        source: $source,
        filename: $filename,
        program: $program,
        optimize: $optimize,
        backend: $backend,
    ), $stopAt);

    return selectOutput($artifacts, $mode);
}

/** @throws LexError|ParseError|TypeError|\RuntimeException @return string|array<string, string> */
function compileFile(string $path, string $mode = 'php', bool $optimize = true, ?string $outputFile = null, ?string $backend = null): string|array
{
    applyCompileBackend($backend);
    $source = file_get_contents($path);
    if ($source === false) {
        throw new \RuntimeException("cannot read {$path}");
    }

    $mode = normalizeOutputMode($mode);
    if (isIntrospectionMode($mode)) {
        return dumpSourceStage($source, $path, $mode);
    }

    $tokens = lex($source, $path);
    $program = parse($tokens, $source, $path);
    if (isModuleSourceProgram($program)) {
        return compileModuleFile($path, $mode, $optimize, $outputFile, $program);
    }

    return compile($source, $path, $mode, $optimize, $program);
}

/** @return array{output: string, exitCode: 0}|array{output: string, exitCode: 1} */
function compileFileCapturingErrors(string $path, string $mode = 'php', bool $optimize = true, ?string $backend = null): array
{
    try {
        $output = compileFile($path, $mode, $optimize, null, $backend);
        if (\is_array($output)) {
            $php = '';
            foreach ($output as $rel => $bytes) {
                if (\is_string($rel) && \is_string($bytes) && str_ends_with($rel, '.php') && !str_ends_with($rel, '.moggi.map')) {
                    $php = $bytes;
                    break;
                }
            }
            if ($php !== '') {
                $output = $php;
            } else {
                $output = \implode("\n", \array_map(
                    static fn (string $rel, string $bytes): string => $rel . ' (' . strlen($bytes) . ' bytes)',
                    \array_keys($output),
                    array_values($output),
                )) . "\n";
            }
        }

        return ['output' => $output, 'exitCode' => 0];
    } catch (LexError|ParseError|TypeError|\RuntimeException $e) {
        if ($e instanceof LexError || $e instanceof ParseError || $e instanceof TypeError) {
            return ['output' => $e->display(), 'exitCode' => 1];
        }

        return ['output' => $e->getMessage() . "\n", 'exitCode' => 1];
    }
}

/**
 * @return array{
 *   emit: array{output: string, exitCode: 0}|array{output: string, exitCode: 1},
 *   opt-ir: array{output: string, exitCode: 0}|array{output: string, exitCode: 1}
 * }
 */
function compileFileCapturingErrorsBoth(string $path, bool $optimize = true, ?string $backend = null): array
{
    try {
        applyCompileBackend($backend);
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("cannot read {$path}");
        }

        $tokens = lex($source, $path);
        $program = parse($tokens, $source, $path);
        if (isModuleSourceProgram($program)) {
            $outputs = compileModuleFileOutputs($path, $optimize, null, $program);

            return [
                'emit' => ['output' => $outputs['emit'], 'exitCode' => 0],
                'opt-ir' => ['output' => $outputs['opt-ir'], 'exitCode' => 0],
            ];
        }

        $purpose = ($program->module === 'Main')
            ? CompilePurpose::Executable
            : CompilePurpose::Library;
        $artifacts = pipelineRun(new PipelineRequest(
            purpose: $purpose,
            source: $source,
            filename: $path,
            program: $program,
            optimize: $optimize,
            backend: $backend,
        ), PipelineStage::Emit);

        return [
            'emit' => ['output' => $artifacts->emit ?? '', 'exitCode' => 0],
            'opt-ir' => ['output' => dumpIr($artifacts->irOpt ?? throw new \RuntimeException('missing optimized IR')), 'exitCode' => 0],
        ];
    } catch (LexError|ParseError|TypeError $e) {
        $failure = ['output' => $e->display(), 'exitCode' => 1];

        return ['emit' => $failure, 'opt-ir' => $failure];
    } catch (\RuntimeException $e) {
        $failure = ['output' => $e->getMessage() . "\n", 'exitCode' => 1];

        return ['emit' => $failure, 'opt-ir' => $failure];
    }
}
