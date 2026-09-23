<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\Docs\surfaceTypeSignature;
use function Moggi\LSP\checkCancelledPoint;
use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Analysis\findNodeChainAt;
use function Moggi\LSP\Index\declForResolved;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\LSP\Protocol\splitLines;

function svcSignatureHelp(AnalysisService $svc, string $uri, array $pos): ?array
{
    checkCancelledPoint();
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $comp = lspPosToCompiler($analysis->source, $pos);

    // Walk outwards through the AST chain and collect every enclosing application, so the
    // innermost function with a signature wins and parameters count exactly.
    $chain = findNodeChainAt($analysis->program, $comp['line'], $comp['col']);
    $applications = []; // innermost-first list of [functionNode, consumedArgs]
    foreach ($chain as $node) {
        if ($node instanceof Ast\Apply) {
            // Count how many arguments the cursor's branch contributes:
            // flattened application spine, so `f a b c` yields arity 3.
            $fn = $node;
            $argc = 0;
            $cursorInArgs = false;
            while ($fn instanceof Ast\Apply) {
                if ($fn->argument === $node || ($cursorInArgs && $fn->function === $node)) {
                    $cursorInArgs = true;
                }
                $argc++;
                $fn = $fn->function;
            }
            $applications[] = ['fn' => $fn, 'argc' => $cursorInArgs ? $argc - 1 : $argc, 'applyNode' => $node];
        }
    }

    // Client-provided activeSignatureHelp keeps a shown signature stable; this server
    // re-derives from the AST, which is strictly more accurate.

    // Innermost-first: pick the first application whose function names a
    // function we can describe.
    foreach ($applications as $app) {
        $fnNode = $app['fn'];
        $name = functionNameOf($fnNode);
        if ($name === null) {
            continue;
        }
        $sig = signatureForFunction($svc, $analysis, $name);
        if ($sig === null) {
            continue;
        }
        // activeParameter: arguments consumed so far *within this application*,
        // clamped to the last declared parameter (spec: never out of range).
        $paramCount = count($sig['parameters'] ?? []);
        $active = $paramCount > 0 ? min($app['argc'], $paramCount - 1) : 0;
        if ($paramCount === 0) {
            $active = 0;
        }
        return [
            'signatures' => [$sig],
            'activeSignature' => 0,
            'activeParameter' => $active,
        ];
    }

    // No AST coverage (parse error / incomplete code): fall back to the
    // line-prefix heuristic so signature help still works while typing.
    return signatureHelpHeuristic($svc, $analysis, $comp, $pos);
}

/** Function name behind an application head (Variable / OperatorRef / Infix). */
function functionNameOf(?Ast\AstNode $node): ?string
{
    if ($node instanceof Ast\Variable) {
        return $node->name;
    }
    if ($node instanceof Ast\OperatorRef) {
        return $node->name;
    }
    if ($node instanceof Ast\Infix) {
        return $node->operator;
    }
    return null;
}

/**
 * Build the SignatureInformation for a function name, preferring the local
 * checked AST (real params), then the module index (cross-module DeclInfo).
 */
function signatureForFunction(AnalysisService $svc, object $analysis, string $name): ?array
{
    $label = null;
    $params = [];
    $doc = null;

    if ($analysis->program instanceof Ast\Program) {
        foreach ($analysis->program->items as $item) {
            if ($item instanceof Ast\FunctionDecl && $item->name === $name) {
                $paramLabels = [];
                foreach ($item->params as $i => $p) {
                    $pname = patternParamName($p) ?? ('arg' . ($i + 1));
                    $paramLabels[] = ['label' => $pname];
                }
                if ($paramLabels !== []) {
                    $params = $paramLabels;
                }
                if ($item->type !== null) {
                    $sig = surfaceTypeSignature($item->type);
                    if ($sig !== null) {
                        $label = "{$name} :: {$sig}";
                    }
                } elseif (isset($analysis->declarations[$name]['type'])) {
                    $sig = $analysis->declarations[$name]['type'];
                    if ($sig !== 'function') {
                        $label = "{$name} :: {$sig}";
                    }
                }
                $doc = $item->doc;
                break;
            }
        }
    }

    if ($params === [] && isset($analysis->declarations[$name])) {
        $decl = $analysis->declarations[$name];
        $sig = $decl['type'] ?? $name;
        if ($sig !== 'function') {
            $label = "{$name} :: {$sig}";
        }
        $doc ??= $decl['doc'] ?? null;
        if (\is_string($sig) && str_contains($sig, '->')) {
            $parts = preg_split('/\s*->\s*/', $sig) ?: [];
            array_pop($parts);
            foreach ($parts as $p) {
                $params[] = ['label' => trim($p)];
            }
        }
    }

    // Cross-module: look up DeclInfo
    if ($params === [] && $label === null && $analysis->program instanceof Ast\Program) {
        $ext = $analysis->program->externalFns[$name] ?? null;
        if ($ext !== null) {
            $d = $svc->modules->declForResolved($ext);
            if ($d !== null) {
                if ($d->type) {
                    $label = "{$name} :: {$d->type}";
                    $params = paramsFromSurfaceSignature($d->type);
                }
                $doc ??= $d->doc;
            }
        }
    }

    if ($label === null) {
        // Name only — still useful while typing, but only when the word exists.
        return isset($analysis->declarations[$name]) || $analysis->program instanceof Ast\Program
            ? ['label' => $name, 'parameters' => []]
            : null;
    }
    $sigObj = ['label' => $label, 'parameters' => $params];
    if ($doc) {
        $sigObj['documentation'] = ['kind' => 'markdown', 'value' => $doc];
    }
    return $sigObj;
}

/**
 * Parameter labels from a surface type string `a -> b -> c`: one per arrow
 * segment before the result.
 *
 * @return list<array{label: string}>
 */
function paramsFromSurfaceSignature(string $type): array
{
    if (!str_contains($type, '->')) {
        return [];
    }
    $parts = preg_split('/\s*->\s*/', $type) ?: [];
    array_pop($parts);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = ['label' => $p];
        }
    }
    return $out;
}

/**
 * Pre-parse fallback: text-based guess of the innermost call on the current
 * line. Kept for signature help on syntactically incomplete code where the
 * AST does not cover the cursor.
 */
function signatureHelpHeuristic(AnalysisService $svc, object $analysis, array $comp, array $pos): ?array
{
    checkCancelledPoint();
    $lines = splitLines($analysis->source);
    $text = $lines[$comp['line'] - 1] ?? '';
    $prefix = substr($text, 0, max(0, $comp['col'] - 1));
    if (!preg_match('/([A-Za-z_][A-Za-z0-9_\']*)\s*(?:\(|\s)?$/', $prefix, $m)) {
        return null;
    }
    $name = $m[1];
    $sig = signatureForFunction($svc, $analysis, $name);
    if ($sig === null) {
        return null;
    }
    // Argument count on this line before the cursor.
    $after = '';
    if (preg_match('/' . preg_quote($name, '/') . '\s*(.*)$/', $prefix, $mm)) {
        $after = $mm[1];
    }
    $active = max(0, preg_match_all('/\s+/', trim($after)) ?: 0);
    $paramCount = count($sig['parameters'] ?? []);
    if ($paramCount > 0) {
        $active = min($active, $paramCount - 1);
    } else {
        $active = 0;
    }
    return [
        'signatures' => [$sig],
        'activeSignature' => 0,
        'activeParameter' => $active,
    ];
}

function patternParamName(object $p): ?string
{
    if ($p instanceof Ast\PatVar) {
        return $p->name;
    }
    if ($p instanceof Ast\Variable) {
        return $p->name;
    }
    if (property_exists($p, 'name') && \is_string($p->name ?? null)) {
        return $p->name;
    }
    return null;
}
