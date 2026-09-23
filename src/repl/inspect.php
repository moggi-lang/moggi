<?php declare(strict_types=1);

namespace Moggi\Repl;

use Moggi\Backend\Backend;
use Moggi\IR\EntryPointKind;
use Moggi\IR\Module;
use Moggi\Pipeline\PipelineArtifacts;
use Moggi\Pipeline\PipelineStage;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;
use Moggi\Syntax\Parser\ReplFragment;

use function Moggi\Backend\backendById;
use function Moggi\Errors\formatDiagnostic;
use function Moggi\IR\Dump\dump as dumpIr;
use function Moggi\IR\entryFromFunctions;
use function Moggi\Syntax\Ast\dump as dumpAst;
use function Moggi\Syntax\Parser\parseReplFragment;

/**
 * REPL inspection: the `:ast` / `:ir` / `:emit` fragment dispatch, compiler
 * diagnostics remapped onto the user's input, and the stage dumps themselves.
 *
 * This layer drives the session core ({@see prepareInteractive},
 * `compileFromInteractiveCtx`) without owning it — the session module owns
 * compilation and evaluation, and each backend owns how its own emit
 * artifacts are displayed ({@see Backend::describeEmit}).
 */

/**
 * Inspect a REPL fragment (expression or declaration) through the pipeline.
 * Declarations are not wrapped as `__repl_inspect = (…)` — that only works for exprs.
 */
function inspectInput(State $state, string $source, PipelineStage $stopAt, bool $optimize): string
{
    $frag = parseReplFragment($source, '<interactive>');
    if ($frag->kind === ReplFragment::KIND_INCOMPLETE) {
        throw new TypeError('incomplete input', '<interactive>', $source);
    }
    if ($frag->kind === ReplFragment::KIND_ERROR) {
        throw $frag->error ?? new TypeError('parse error', '<interactive>', $source);
    }

    $stage = match ($stopAt) {
        PipelineStage::Ast, PipelineStage::TypedAst => 'ast',
        PipelineStage::Ir => 'ir',
        PipelineStage::IrOpt => 'ir-opt',
        PipelineStage::Emit => $optimize ? 'emit-opt' : 'emit',
    };

    if ($frag->kind === ReplFragment::KIND_DECL) {
        $name = fragmentDeclaredName($frag);
        $focus = $name !== null ? [$name] : [];
        // Already in the session: dump the interactive module (includes that binding).
        if ($name !== null && bindingNameExists($state, $name)) {
            $artifacts = compileInteractiveInspect($state, $stopAt, $optimize);
            $state->lastFragment = $artifacts;
            $state->lastFragmentFocus = $focus;

            return formatStage($state->backend, $artifacts, $stage, $focus);
        }

        // Probe with the declaration as a temporary extra; do not commit.
        $ctx = prepareInteractive($state, $frag->source);
        $artifacts = compileFromInteractiveCtx($state, $ctx, $stopAt, $optimize);
        $state->lastFragment = $artifacts;
        $state->lastFragmentFocus = $focus;
        prepareInteractive($state);

        return formatStage($state->backend, $artifacts, $stage, $focus);
    }

    $evalName = '__repl_inspect';
    $extra = "{$evalName} = " . replFragmentBindingRhs($frag) . "\n";
    $ctx = prepareInteractive($state, $extra);
    foreach ($ctx['checked']->items as $item) {
        if ($item instanceof Ast\FunctionDecl && $item->name === $evalName) {
            $item->entryKind = EntryPointKind::ReplExpression;
        }
    }
    $artifacts = compileFromInteractiveCtx($state, $ctx, $stopAt, $optimize);
    $state->lastFragment = $artifacts;
    $state->lastFragmentFocus = [$evalName];
    prepareInteractive($state);

    return formatStage($state->backend, $artifacts, $stage, [$evalName]);
}

/**
 * Re-render a compiler diagnostic against the user's REPL input (not the
 * synthetic Interactive.mog wrapper like `it = (…)` / `__repl_eval = (…)`).
 */
function displayReplError(\Throwable $e, string $userSource): string
{
    if (!($e instanceof TypeError || $e instanceof ParseError || $e instanceof LexError)) {
        return method_exists($e, 'display') ? $e->display() : ($e->getMessage() . "\n");
    }

    $kind = match (true) {
        $e instanceof TypeError => 'type error',
        $e instanceof ParseError => 'parse error',
        $e instanceof LexError => 'lex error',
    };

    [$line, $col, $endCol] = mapErrorSpanToUserSource(
        $e->source,
        $e->line(),
        $e->col(),
        $e->endCol(),
        $userSource,
    );

    return formatDiagnostic('<interactive>', $kind, $e->getMessage(), $userSource, $line, $col, $endCol);
}

/**
 * @return array{0: int, 1: int, 2: int} line, col, endCol (1-based)
 */
function mapErrorSpanToUserSource(
    string $fullSource,
    int $line,
    int $col,
    int $endCol,
    string $userSource,
): array {
    $userSource = rtrim($userSource, "\r\n");
    if ($userSource === '') {
        return [1, 1, 1];
    }

    if ($line <= 0 || $fullSource === '') {
        return [1, 1, max(1, strlen($userSource))];
    }

    $lines = preg_split('/\R/', $fullSource) ?: [''];
    $text = $lines[$line - 1] ?? '';
    $endCol = $endCol > 0 ? $endCol : $col;

    foreach (['it', '__repl_eval', '__repl_ty', '__repl_inspect'] as $wrap) {
        if (preg_match(
            '/^' . preg_quote($wrap, '/') . '(?:\s*::[^=]*)?\s*=\s*\(/',
            $text,
        ) !== 1) {
            continue;
        }
        $open = strpos($text, '(');
        if ($open === false) {
            continue;
        }
        // Map 1-based cols on the wrapper line into the parenthesized payload.
        $innerCol = max(1, $col - $open);
        $innerEnd = max($innerCol, $endCol - $open);

        return spanWithinUserSource($userSource, $innerCol, $innerEnd);
    }

    // Decl / probe line that is exactly the user fragment.
    if (trim($text) === trim($userSource)) {
        return spanWithinUserSource($userSource, max(1, $col), max(1, $endCol));
    }

    return [1, 1, max(1, strlen((preg_split('/\R/', $userSource) ?: [''])[0]))];
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function spanWithinUserSource(string $userSource, int $col, int $endCol): array
{
    $userLines = preg_split('/\R/', $userSource) ?: [''];
    if (count($userLines) === 1) {
        $len = max(1, strlen($userLines[0]));

        return [1, min(max(1, $col), $len), min(max(1, $endCol), $len)];
    }

    // Multi-line payload: cols count across the joined text with newlines.
    $offset = max(0, $col - 1);
    $endOffset = max($offset, $endCol - 1);
    $line = 1;
    foreach ($userLines as $ul) {
        $lineLen = strlen($ul);
        if ($offset <= $lineLen) {
            $localEnd = min($lineLen, $endOffset - ($col - 1 - $offset));

            return [
                $line,
                max(1, $offset + 1),
                max(1, $localEnd + 1),
            ];
        }
        $offset -= $lineLen + 1;
        $endOffset -= $lineLen + 1;
        $line++;
    }

    $last = $userLines[count($userLines) - 1];

    return [count($userLines), 1, max(1, strlen($last))];
}

/**
 * Render one pipeline stage. `emit` delegates to the backend, which owns the
 * shape of its own artifacts (PHP source, JVM bytecode, .NET IL).
 *
 * @param list<string> $focusNames when non-empty, show only those bindings
 */
function formatStage(string $backend, PipelineArtifacts $artifacts, string $which, array $focusNames = []): string
{
    $which = $which === 'emit-opt' ? 'emit' : $which;

    return match ($which) {
        'ast' => focusAstDump($artifacts->typedAst ?? $artifacts->ast ?? throw new \RuntimeException('no AST'), $focusNames),
        'typed-ast' => focusAstDump($artifacts->typedAst ?? throw new \RuntimeException('no typed AST'), $focusNames),
        'ir' => focusIrDump($artifacts->ir ?? throw new \RuntimeException('no IR'), $focusNames),
        'ir-opt' => focusIrDump($artifacts->irOpt ?? throw new \RuntimeException('no optimized IR'), $focusNames),
        'emit' => backendById($backend)->describeEmit(
            $artifacts->emit ?? throw new \RuntimeException('no emit'),
            $focusNames,
        ),
        default => throw new \InvalidArgumentException("unknown dump stage `{$which}`"),
    };
}

/** @param list<string> $focusNames */
function focusAstDump(Ast\Program $program, array $focusNames): string
{
    if ($focusNames === []) {
        return dumpAst($program);
    }
    $wanted = array_fill_keys($focusNames, true);
    $items = [];
    foreach ($program->items as $item) {
        $name = match (true) {
            $item instanceof Ast\FunctionDecl => $item->name,
            $item instanceof Ast\DataDecl => $item->name,
            $item instanceof Ast\TypeSynonymDecl => $item->name,
            $item instanceof Ast\ClassDecl => $item->name,
            $item instanceof Ast\InstanceDecl => null,
            default => null,
        };
        if ($name !== null && isset($wanted[$name])) {
            $items[] = $item;
        }
    }
    $focused = new Ast\Program(
        items: $items,
        module: $program->module,
        moduleBackend: $program->moduleBackend,
        backendMap: $program->backendMap,
        implicitMain: $program->implicitMain,
        language: $program->language,
        moduleDoc: $program->moduleDoc,
        fixityDocs: $program->fixityDocs,
        line: $program->line,
        col: $program->col,
        endCol: $program->endCol,
    );

    return dumpAst($focused);
}

/** @param list<string> $focusNames */
function focusIrDump(Module $ir, array $focusNames): string
{
    if ($focusNames === []) {
        return dumpIr($ir);
    }
    $wanted = array_fill_keys($focusNames, true);
    $fns = [];
    foreach ($ir->functions as $fn) {
        if (isset($wanted[$fn->name])) {
            $fns[] = $fn;
        }
    }

    return dumpIr(new Module(
        functions: $fns,
        data: [],
        entry: entryFromFunctions($fns),
    ));
}
