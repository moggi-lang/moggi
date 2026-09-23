<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Analysis\findNodeAt;
use function Moggi\LSP\Index\binderResolved;
use function Moggi\LSP\Index\declTokenMaps;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\Modules\resolvedSymbol;

function svcMoniker(AnalysisService $svc, string $uri, array $pos): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $comp = lspPosToCompiler($analysis->source, $pos);
    $node = findNodeAt($analysis->program, $comp['line'], $comp['col']);
    $name = null;
    $resolved = null;
    if ($node === null) {
        // Declaration-name positions have no positioned AST node (the parser
        // leaves top-level decls unpositioned); fall back to the shared token
        // map, same as occurrences and folding.
        $maps = declTokenMaps($analysis->source, $uri);
        foreach ($maps['names'] as $tok) {
            if ($tok->line === $comp['line']) {
                $name = $tok->lexeme;
                $resolved = resolvedSymbol($analysis->program->module ?? 'Main', $name);
                break;
            }
        }
    }
    if ($node !== null && $node instanceof Ast\Variable) {
        $name = $node->name;
        $resolved = $node->resolvedOrigin;
    } elseif ($node !== null && $node instanceof Ast\PatVar) {
        $name = $node->name;
        $resolved = $node->binderId !== null
            ? binderResolved($analysis->program->module ?? 'Main', $node->binderId)
            : null;
    } elseif ($node !== null && $node instanceof Ast\FunctionDecl) {
        $name = $node->name;
        $resolved = resolvedSymbol($analysis->program->module ?? 'Main', $node->name);
    } elseif ($node !== null && $node instanceof Ast\ConstructorRef || $node instanceof Ast\OperatorRef) {
        $name = $node->name;
        $resolved = $node->resolvedOrigin;
    } elseif ($node !== null && $node instanceof Ast\QualifiedRef) {
        $name = $node->name;
        $resolved = $node->backendResolved;
    }
    if ($resolved === null) {
        return [];
    }
    $parts = explode('::', $resolved);
    $module = $parts[0] ?? '';
    return [
        [
            'scheme' => 'moggi',
            'identifier' => $resolved,
            'unique' => 'scheme',
            'kind' => 'export',
        ],
    ];
}

/**
 * Pull diagnostics (LSP 3.17+ textDocument/diagnostic).
 */
