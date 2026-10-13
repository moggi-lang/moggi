<?php declare(strict_types=1);

namespace Moggi\Repl;

use Moggi\Pipeline\PipelineStage;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;
use Moggi\Syntax\Parser\ReplFragment;

use function Moggi\Backend\backendById;
use function Moggi\Backend\knownBackendIds;
use function Moggi\Backend\setCompileBackend;
use function Moggi\Syntax\Parser\parseReplFragment;
use function Moggi\formatExceptionReport;

function formatReplThrowable(\Throwable $e, string $userSource = ''): string
{
    $msg = $e->getMessage();
    if (str_starts_with(ltrim($msg), 'moggi:')) {
        return remapReplExceptionReport(rtrim($msg, "\r\n") . "\n", $userSource);
    }

    if (!\function_exists('\\Moggi\\formatExceptionReport')) {
        foreach (backendById('php')->runtimeFiles() as $runtime) {
            require_once $runtime;
        }
    }
    if (\function_exists('\\Moggi\\formatExceptionReport')) {
        return remapReplExceptionReport(formatExceptionReport($e), $userSource);
    }

    return 'error: ' . $msg . "\n";
}

/**
 * Map Interactive / Main REPL wrappers onto <interactive>:line:col and drop bootstrap frames.
 */
function remapReplExceptionReport(string $report, string $userSource = ''): string
{
    $userSource = rtrim($userSource, "\r\n");
    $userLine = 1;
    $userCol = 1;
    $lines = preg_split('/\R/', rtrim($report, "\r\n")) ?: [];
    $out = [];
    $sawInteractive = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*at Main\.main\s*\(.*Main\.mog:/', $line) === 1) {
            continue;
        }
        $isInteractive = preg_match(
            '/^\s*at Interactive\.(?:__repl_eval|it|__repl_ty|__repl_inspect)\b/',
            $line,
        ) === 1
            || preg_match('/^\s*at .+\((?:.*\/)?Interactive\.mog:\d+/', $line) === 1;
        if ($isInteractive) {
            if ($sawInteractive) {
                continue;
            }
            $sawInteractive = true;
            $out[] = "  at <interactive>:{$userLine}:{$userCol}";
            continue;
        }
        $out[] = $line;
    }
    if ($userSource !== '' && !$sawInteractive) {
        $out[] = "  at <interactive>:{$userLine}:{$userCol}";
    }

    return implode("\n", $out) . "\n";
}

function helpText(): string
{
    $backends = implode('|', knownBackendIds());

    return <<<TXT
Commands (start with `:`):
  :backend {$backends}  switch evaluation backend
  :help                show this help
  :quit, :q            exit the REPL
  :type expr|decl, :t  show the type of an expression or binding
  :kind Type|expr, :k  show the kind of a type (or of an expression's type)
  :info Name, :i       show declaration information
  :ast [expr|decl]     show the parsed tree (fragment or last)
  :typed-ast [expr|decl] show the tree after type checking
  :ir [expr|decl]      show IR
  :opt-ir [expr|decl]  show optimized IR
  :emit [expr|decl]    show emitted code for a fragment binding
  :emit-opt [expr|decl] show optimized emit for a fragment
  :dump ast|typed-ast|ir|opt-ir|emit|emit-opt
                       dump stage of the full interactive module
  :load file, :l       load a Moggi module
  :reload, :r          reload the current module
  :browse              list interactive bindings
  :show imports        show active imports
  :module [Name]       show or set interactive module name
  :history             show command history
  :clear               reset interactive state

Declarations and prompt statements persist; expressions are evaluated.

TXT;
}

/**
 * @return array{handled: bool, quit?: bool, output?: string}
 */
function handleCommand(State $state, string $line): array
{
    $raw = ltrim($line);
    if ($raw === '' || $raw[0] !== ':') {
        return ['handled' => false];
    }

    $body = substr($raw, 1);
    $parts = preg_split('/\s+/', $body, 2) ?: [];
    $cmd = strtolower($parts[0] ?? '');
    $arg = isset($parts[1]) ? trim($parts[1]) : '';

    try {
        $output = match ($cmd) {
            'help', '?' => helpText(),
            'quit', 'q' => null,
            'backend' => (static function () use ($state, $arg): string {
                $ids = knownBackendIds();
                if (!\in_array($arg, $ids, true)) {
                    return 'usage: :backend ' . implode('|', $ids) . "\n";
                }
                $state->backend = $arg;
                setCompileBackend($arg);
                clearSession($state);

                return "backend = {$arg}\n";
            })(),
            'type', 't' => $arg === '' ? "usage: :type expr|decl\n" : (typeOfInput($state, $arg) . "\n"),
            'kind', 'k' => $arg === '' ? "usage: :kind Type|expr\n" : (kindOfInput($state, $arg) . "\n"),
            'info', 'i' => $arg === '' ? "usage: :info Name\n" : infoForName($state, $arg),
            'browse' => browseBindings($state),
            'history' => ($state->history === [] ? "(empty)\n" : implode("\n", $state->history) . "\n"),
            'clear' => (static function () use ($state): string {
                clearSession($state);

                return '';
            })(),
            'show' => match (strtolower($arg)) {
                'imports' => ($state->importLines === []
                    ? "(no explicit imports; Prelude is implicit)\n"
                    : implode("\n", $state->importLines) . "\n"),
                default => "usage: :show imports\n",
            },
            'module' => (static function () use ($state, $arg): string {
                if ($arg === '') {
                    return "module {$state->moduleName}\n";
                }
                if (!preg_match('/^[A-Z][A-Za-z0-9_.]*$/', $arg)) {
                    return "invalid module name `{$arg}`\n";
                }
                $state->moduleName = $arg;
                prepareInteractive($state);

                return "module {$state->moduleName}\n";
            })(),
            'load', 'l' => $arg === '' ? "usage: :load file\n" : loadModuleFile($state, $arg),
            'reload', 'r' => reloadSession($state),
            'dump' => (static function () use ($state, $arg): string {
                $stage = strtolower($arg);
                $spec = pipelineStageSpec($stage);
                if ($spec === null) {
                    return 'usage: :dump ' . implode('|', array_keys(pipelineStageTable())) . "\n";
                }
                [$stop, $optimize] = $spec;

                return formatStage(
                    $state->backend,
                    compileInteractiveModule($state, $stop, $optimize),
                    $stage,
                );
            })(),
            // A command named after a stage is that stage's command ({@see pipelineStageTable});
            // there is no second list of them to keep in step with the enum.
            default => pipelineStageSpec($cmd) !== null
                ? handleStageCommand($state, $cmd, $arg)
                : "unknown command `:{$cmd}` (try :help)\n",
        };
    } catch (LexError|ParseError|TypeError $e) {
        $userSrc = $arg !== '' ? $arg : $line;
        return ['handled' => true, 'output' => displayReplError($e, $userSrc)];
    } catch (\Throwable $e) {
        return ['handled' => true, 'output' => formatReplThrowable($e)];
    }

    if ($cmd === 'quit' || $cmd === 'q') {
        return ['handled' => true, 'quit' => true, 'output' => ''];
    }

    return ['handled' => true, 'output' => $output ?? ''];
}

/**
 * The stage commands, keyed by the name the user types: each stage is named by its
 * own value, so `:dump opt-ir` and `--opt-ir` are one stage under one name, and a
 * rename cannot land in one of them only.
 *
 * `:dump` accepts every one of these; a command named after one of them is that
 * stage's command. The single name a stage cannot carry is `emit-opt` — the emit
 * stage with the optimizer on.
 *
 * @return array<string, array{0: PipelineStage, 1: bool}> stage, optimize
 */
function pipelineStageTable(): array
{
    $table = [];
    foreach (PipelineStage::cases() as $stage) {
        // `opt-ir` is the optimized IR: reaching the stage means running the optimizer.
        $table[$stage->value] = [$stage, $stage === PipelineStage::IrOpt];
    }
    $table['emit-opt'] = [PipelineStage::Emit, true];

    return $table;
}

/**
 * @return array{0: PipelineStage, 1: bool}|null stage, optimize
 */
function pipelineStageSpec(string $stage): ?array
{
    return pipelineStageTable()[$stage] ?? null;
}

function handleStageCommand(State $state, string $stage, string $arg): string
{
    if ($arg === '') {
        return formatLastOrModule($state, $stage);
    }

    [$stop, $optimize] = pipelineStageSpec($stage)
        ?? throw new \InvalidArgumentException("unknown stage `{$stage}`");

    return inspectInput($state, $arg, $stop, $optimize);
}

function formatLastOrModule(State $state, string $stage): string
{
    [$stop, $optimize] = pipelineStageSpec($stage)
        ?? throw new \InvalidArgumentException("unknown stage `{$stage}`");

    if ($state->lastFragment !== null) {
        try {
            return formatStage(
                $state->backend,
                $state->lastFragment,
                $stage,
                $state->lastFragmentFocus ?? [],
            );
        } catch (\Throwable) {
        }
    }
    $artifacts = compileInteractiveModule($state, $stop, $optimize);
    $state->lastFragmentFocus = null;

    return formatStage($state->backend, $artifacts, $stage);
}

/**
 * Process a complete (non-command) input unit.
 */
function handleFragment(State $state, string $source, ?ReplFragment $frag = null): string
{
    $frag ??= parseReplFragment($source, '<interactive>');
    if ($frag->kind === ReplFragment::KIND_INCOMPLETE) {
        throw new \LogicException('handleFragment requires complete input');
    }
    if ($frag->kind === ReplFragment::KIND_ERROR) {
        return $frag->error?->display() ?? "parse error\n";
    }

    try {
        if ($frag->kind === ReplFragment::KIND_DECL) {
            if ($frag->node instanceof Ast\ImportDecl) {
                $line = trim($source);
                if (!\in_array($line, $state->importLines, true)) {
                    $state->importLines[] = $line;
                    prepareInteractive($state);
                }

                return '';
            }

            commitDeclaration($state, $frag->source);

            return '';
        }

        if ($frag->kind === ReplFragment::KIND_STMT) {
            $out = evaluateStatements($state, $frag->source, $frag->node);

            return $out === '' ? '' : (str_ends_with($out, "\n") ? $out : $out . "\n");
        }

        $out = evaluateExpression($state, $frag->source);

        return $out === '' ? '' : (str_ends_with($out, "\n") ? $out : $out . "\n");
    } catch (LexError|ParseError|TypeError $e) {
        return displayReplError($e, $frag->source);
    } catch (\Throwable $e) {
        return formatReplThrowable($e, $frag->source);
    }
}
