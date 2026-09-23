<?php declare(strict_types=1);

namespace Moggi\LSP;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Formatter\svcFormatDocument;
use function Moggi\LSP\Formatter\svcFormatRange;
use function Moggi\LSP\TextDocument\documentChangesWorkspaceEdit;
use function Moggi\LSP\TextDocument\semanticTokensLegend;
use function Moggi\LSP\TextDocument\svcCallHierarchyIncoming;
use function Moggi\LSP\TextDocument\svcCallHierarchyOutgoing;
use function Moggi\LSP\TextDocument\svcCallHierarchyPrepare;
use function Moggi\LSP\TextDocument\svcCodeActionResolve;
use function Moggi\LSP\TextDocument\svcCodeActions;
use function Moggi\LSP\TextDocument\svcCodeLens;
use function Moggi\LSP\TextDocument\svcColorPresentations;
use function Moggi\LSP\TextDocument\svcCompletion;
use function Moggi\LSP\TextDocument\svcCompletionResolve;
use function Moggi\LSP\TextDocument\svcDeclaration;
use function Moggi\LSP\TextDocument\svcDefinition;
use function Moggi\LSP\TextDocument\svcDiagnostic;
use function Moggi\LSP\TextDocument\svcDocumentColors;
use function Moggi\LSP\TextDocument\svcDocumentHighlight;
use function Moggi\LSP\TextDocument\svcDocumentLinkResolve;
use function Moggi\LSP\TextDocument\svcDocumentLinks;
use function Moggi\LSP\TextDocument\svcDocumentSymbols;
use function Moggi\LSP\TextDocument\svcFoldingRanges;
use function Moggi\LSP\TextDocument\svcHover;
use function Moggi\LSP\TextDocument\svcImplementation;
use function Moggi\LSP\TextDocument\svcInlayHintResolve;
use function Moggi\LSP\TextDocument\svcInlayHints;
use function Moggi\LSP\TextDocument\svcInlineValues;
use function Moggi\LSP\TextDocument\svcLinkedEditing;
use function Moggi\LSP\TextDocument\svcMoniker;
use function Moggi\LSP\TextDocument\svcOnTypeFormatting;
use function Moggi\LSP\TextDocument\svcPrepareRename;
use function Moggi\LSP\TextDocument\svcReferences;
use function Moggi\LSP\TextDocument\svcRename;
use function Moggi\LSP\TextDocument\svcSelectionRange;
use function Moggi\LSP\TextDocument\svcSemanticTokens;
use function Moggi\LSP\TextDocument\svcSemanticTokensFullDelta;
use function Moggi\LSP\TextDocument\svcSemanticTokensRange;
use function Moggi\LSP\TextDocument\svcSignatureHelp;
use function Moggi\LSP\TextDocument\svcTypeDefinition;
use function Moggi\LSP\TextDocument\svcTypeHierarchyPrepare;
use function Moggi\LSP\TextDocument\svcTypeHierarchySubtypes;
use function Moggi\LSP\TextDocument\svcTypeHierarchySupertypes;
use function Moggi\LSP\TextDocument\svcWorkspaceDiagnostic;
use function Moggi\LSP\TextDocument\svcWorkspaceSymbol;
use function Moggi\LSP\Workspace\svcWillDeleteFiles;
use function Moggi\LSP\Workspace\svcWillRenameFiles;

const FEATURE_CAPABILITIES = [
    'textDocument/hover' => 'hoverProvider',
    'textDocument/definition' => 'definitionProvider',
    'textDocument/typeDefinition' => 'typeDefinitionProvider',
    'textDocument/implementation' => 'implementationProvider',
    'textDocument/references' => 'referencesProvider',
    'textDocument/documentHighlight' => 'documentHighlightProvider',
    'textDocument/completion' => 'completionProvider',
    'textDocument/signatureHelp' => 'signatureHelpProvider',
    'textDocument/documentSymbol' => 'documentSymbolProvider',
    'workspace/symbol' => 'workspaceSymbolProvider',
    'textDocument/semanticTokens/full' => 'semanticTokensProvider',
    'textDocument/semanticTokens/full/delta' => 'semanticTokensProvider',
    'textDocument/semanticTokens/range' => 'semanticTokensProvider',
    'textDocument/prepareRename' => 'renameProvider',
    'textDocument/rename' => 'renameProvider',
    'textDocument/codeAction' => 'codeActionProvider',
    'textDocument/codeLens' => 'codeLensProvider',
    'textDocument/foldingRange' => 'foldingRangeProvider',
    'textDocument/selectionRange' => 'selectionRangeProvider',
    'textDocument/formatting' => 'documentFormattingProvider',
    'textDocument/rangeFormatting' => 'documentRangeFormattingProvider',
    'textDocument/inlayHint' => 'inlayHintProvider',
    'textDocument/linkedEditingRange' => 'linkedEditingRangeProvider',
    'textDocument/declaration' => 'declarationProvider',
    'textDocument/documentLink' => 'documentLinkProvider',
    'textDocument/inlineValue' => 'inlineValueProvider',
    'textDocument/documentColor' => 'colorProvider',
    'textDocument/colorPresentation' => 'colorProvider',
    'textDocument/onTypeFormatting' => 'documentOnTypeFormattingProvider',
    'textDocument/moniker' => 'monikerProvider',
    'textDocument/diagnostic' => 'diagnosticProvider',
    'workspace/willRenameFiles' => 'workspace.fileOperations.willRename',
    'workspace/willDeleteFiles' => 'workspace.fileOperations.willDelete',
    'textDocument/prepareCallHierarchy' => 'callHierarchyProvider',
    'callHierarchy/incomingCalls' => 'callHierarchyProvider',
    'callHierarchy/outgoingCalls' => 'callHierarchyProvider',
    'textDocument/prepareTypeHierarchy' => 'typeHierarchyProvider',
    'typeHierarchy/supertypes' => 'typeHierarchyProvider',
    'typeHierarchy/subtypes' => 'typeHierarchyProvider',
];

/** Methods answered by dispatch that intentionally have no capability entry. */
const UNADVERTISED_METHODS = [
    'completionItem/resolve', 'codeAction/resolve', 'inlayHint/resolve',
    'documentLink/resolve', 'workspaceSymbol/resolve',
    'workspace/diagnostic', 'workspace/diagnostic/refresh',
    'workspace/executeCommand', 'window/workDoneProgress/create',
];

/** initialize-result serverInfo payload. */
const SERVER_INFO = [
    'name' => 'Moggi Language Server',
    'version' => '0.4.0',
];

/**
 * The initialize-result capabilities. Kept adjacent to lspMethodHandlers()
 * so drift between "what we advertise" and "what we answer" is reviewable.
 */
function serverCapabilities(): array
{
    return [
        // Server is UTF-16 throughout (see protocol/positions.php); echo the
        // encoding explicitly for clients sending positionEncodings.
        'positionEncoding' => 'utf-16',
        'textDocumentSync' => [
            'openClose' => true,
            'change' => 2,
            'save' => ['includeText' => false],
            'willSave' => false,
            'willSaveWaitUntil' => false,
            'dynamicRegistration' => true,
        ],
        'hoverProvider' => ['dynamicRegistration' => true],
        'definitionProvider' => ['dynamicRegistration' => true],
        'typeDefinitionProvider' => ['dynamicRegistration' => true],
        'implementationProvider' => true,
        'referencesProvider' => ['dynamicRegistration' => true],
        'documentHighlightProvider' => ['dynamicRegistration' => true],
        'completionProvider' => [
            'resolveProvider' => true,
            'triggerCharacters' => ['.', '(', ' '],
            'dynamicRegistration' => true,
        ],
        'signatureHelpProvider' => [
            'triggerCharacters' => ['(', ' '],
            'dynamicRegistration' => true,
        ],
        'documentSymbolProvider' => ['hierarchicalDocumentSymbolSupport' => true, 'dynamicRegistration' => true],
        'workspaceSymbolProvider' => ['dynamicRegistration' => true],
        'semanticTokensProvider' => [
            'legend' => semanticTokensLegend(),
            'full' => ['delta' => true],
            'range' => ['delta' => true],
            'dynamicRegistration' => true,
        ],
        'renameProvider' => ['prepareProvider' => true, 'dynamicRegistration' => true],
        'codeActionProvider' => [
            'codeActionKinds' => ['quickfix', 'refactor', 'source'],
            'resolveProvider' => true,
            'dynamicRegistration' => true,
        ],
        'codeLensProvider' => ['resolveProvider' => false, 'dynamicRegistration' => true],
        'foldingRangeProvider' => ['dynamicRegistration' => true],
        'selectionRangeProvider' => ['dynamicRegistration' => true],
        'documentFormattingProvider' => ['dynamicRegistration' => true],
        'documentRangeFormattingProvider' => ['dynamicRegistration' => true],
        'inlayHintProvider' => ['resolveProvider' => true, 'dynamicRegistration' => true],
        'callHierarchyProvider' => ['dynamicRegistration' => true],
        'typeHierarchyProvider' => ['dynamicRegistration' => true],
        'linkedEditingRangeProvider' => ['dynamicRegistration' => true],
        'declarationProvider' => ['dynamicRegistration' => true],
        'documentLinkProvider' => [
            'resolveProvider' => true,
        ],
        'inlineValueProvider' => ['dynamicRegistration' => true],
        // Spec: plain DocumentColorOptions ({}) — no extra payload.
        'colorProvider' => [],
        // Spec: server capability key is documentOnTypeFormattingProvider.
        'documentOnTypeFormattingProvider' => [
            'firstTriggerCharacter' => '{',
            'moreTriggerCharacter' => ['}', ';'],
            'dynamicRegistration' => true,
        ],
        'monikerProvider' => ['dynamicRegistration' => true],
        'diagnosticProvider' => [
            'identifier' => 'moggi-diagnostics',
            'interFileDependencies' => true,
            // Diagnostics reach clients as push (`publishDiagnostics`), so a
            // workspace pull would only duplicate them — while clients that
            // see `true` poll `workspace/diagnostic` on a fixed timer.
            'workspaceDiagnostics' => false,
            'dynamicRegistration' => true,
        ],
        'workspace' => [
            'workspaceFolders' => [
                'supported' => true,
                'changeNotifications' => true,
            ],
            'fileOperations' => [
                'willRename' => [
                    'filters' => [['scheme' => 'file', 'pattern' => ['glob' => '**/*.mog']]],
                ],
                'willDelete' => [
                    'filters' => [['scheme' => 'file', 'pattern' => ['glob' => '**/*.mog']]],
                ],
            ],
        ],
    ];
}

/** LSP params shorthands used by the dispatch closures below. */
function dispatchUri(array $params): string
{
    return (string) ($params['textDocument']['uri'] ?? '');
}

/** @return array{line: int, character: int} */
function dispatchPos(array $params): array
{
    return $params['position'] ?? ['line' => 0, 'character' => 0];
}

/**
 * Every request method the server answers, mapped to its handler.
 * Handler signature: fn(AnalysisService $svc, array $params): mixed.
 *
 * @return array<string, callable(AnalysisService, array): mixed>
 */
function lspMethodHandlers(): array
{
    return [
        'textDocument/hover' => static fn (AnalysisService $svc, array $params): mixed => svcHover($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/definition' => static fn (AnalysisService $svc, array $params): mixed => svcDefinition($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/typeDefinition' => static fn (AnalysisService $svc, array $params): mixed => svcTypeDefinition($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/implementation' => static fn (AnalysisService $svc, array $params): mixed => svcImplementation($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/references' => static fn (AnalysisService $svc, array $params): mixed => svcReferences(
            $svc,
            dispatchUri($params),
            dispatchPos($params),
            (bool) ($params['context']['includeDeclaration'] ?? true),
        ),
        'textDocument/documentHighlight' => static fn (AnalysisService $svc, array $params): mixed => svcDocumentHighlight($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/completion' => static fn (AnalysisService $svc, array $params): mixed => svcCompletion($svc, dispatchUri($params), dispatchPos($params)),
        'completionItem/resolve' => static fn (AnalysisService $svc, array $params): mixed => svcCompletionResolve($svc, $params),
        'textDocument/signatureHelp' => static fn (AnalysisService $svc, array $params): mixed => svcSignatureHelp($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/documentSymbol' => static fn (AnalysisService $svc, array $params): mixed => svcDocumentSymbols($svc, dispatchUri($params)),
        'textDocument/semanticTokens/full' => static fn (AnalysisService $svc, array $params): mixed => svcSemanticTokens($svc, dispatchUri($params)),
        // Only meaningful when the client asked for a delta (previousResultId present);
        // otherwise behave exactly like the full request.
        'textDocument/semanticTokens/full/delta' => static fn (AnalysisService $svc, array $params): mixed => isset($params['previousResultId'])
            ? svcSemanticTokensFullDelta($svc, dispatchUri($params), $params)
            : svcSemanticTokens($svc, dispatchUri($params)),
        'textDocument/semanticTokens/range' => static fn (AnalysisService $svc, array $params): mixed => svcSemanticTokensRange($svc, dispatchUri($params), $params['range'] ?? []),
        'textDocument/prepareRename' => static fn (AnalysisService $svc, array $params): mixed => svcPrepareRename($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/rename' => static fn (AnalysisService $svc, array $params): mixed => svcRename($svc, dispatchUri($params), dispatchPos($params), (string) ($params['newName'] ?? '')),
        'textDocument/codeAction' => static fn (AnalysisService $svc, array $params): mixed => svcCodeActions(
            $svc,
            dispatchUri($params),
            $params['range'] ?? ['start' => dispatchPos($params), 'end' => dispatchPos($params)],
            $params['context'] ?? [],
        ),
        'textDocument/codeLens' => static fn (AnalysisService $svc, array $params): mixed => svcCodeLens($svc, dispatchUri($params)),
        'textDocument/foldingRange' => static fn (AnalysisService $svc, array $params): mixed => svcFoldingRanges($svc, dispatchUri($params)),
        'textDocument/selectionRange' => static fn (AnalysisService $svc, array $params): mixed => svcSelectionRange($svc, dispatchUri($params), $params['positions'] ?? [dispatchPos($params)]),
        'textDocument/formatting' => static fn (AnalysisService $svc, array $params): mixed => svcFormatDocument($svc, dispatchUri($params)),
        'textDocument/rangeFormatting' => static fn (AnalysisService $svc, array $params): mixed => svcFormatRange($svc, dispatchUri($params), $params['range'] ?? []),
        'textDocument/inlayHint' => static fn (AnalysisService $svc, array $params): mixed => svcInlayHints($svc, dispatchUri($params), $params['range'] ?? []),
        'textDocument/linkedEditingRange' => static fn (AnalysisService $svc, array $params): mixed => svcLinkedEditing($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/declaration' => static fn (AnalysisService $svc, array $params): mixed => svcDeclaration($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/documentLink' => static fn (AnalysisService $svc, array $params): mixed => svcDocumentLinks($svc, dispatchUri($params)),
        'documentLink/resolve' => static fn (AnalysisService $svc, array $params): mixed => svcDocumentLinkResolve($svc, $params),
        'textDocument/inlineValue' => static fn (AnalysisService $svc, array $params): mixed => svcInlineValues($svc, dispatchUri($params), $params['range'] ?? []),
        'inlayHint/resolve' => static fn (AnalysisService $svc, array $params): mixed => svcInlayHintResolve($svc, $params),
        'codeAction/resolve' => static fn (AnalysisService $svc, array $params): mixed => svcCodeActionResolve($svc, $params),
        'textDocument/documentColor' => static fn (AnalysisService $svc, array $params): mixed => svcDocumentColors($svc, dispatchUri($params)),
        'textDocument/colorPresentation' => static fn (AnalysisService $svc, array $params): mixed => svcColorPresentations($svc, dispatchUri($params), $params['color'] ?? [], $params['range'] ?? [], $params['context'] ?? []),
        'textDocument/onTypeFormatting' => static fn (AnalysisService $svc, array $params): mixed => svcOnTypeFormatting($svc, dispatchUri($params), $params['position'] ?? dispatchPos($params), $params['ch'] ?? '', $params['options'] ?? []),
        'textDocument/moniker' => static fn (AnalysisService $svc, array $params): mixed => svcMoniker($svc, dispatchUri($params), dispatchPos($params)),
        'textDocument/diagnostic' => static fn (AnalysisService $svc, array $params): mixed => svcDiagnostic($svc, dispatchUri($params), $params),
        'workspace/diagnostic/refresh' => static fn (AnalysisService $svc, array $params): mixed => null,
        // Not advertised (diagnosticProvider.workspaceDiagnostics is false);
        // kept serving so pull clients still get correct reports.
        'workspace/diagnostic' => static fn (AnalysisService $svc, array $params): mixed => svcWorkspaceDiagnostic($svc, $params),
        'workspace/symbol' => static fn (AnalysisService $svc, array $params): mixed => svcWorkspaceSymbol($svc, (string) ($params['query'] ?? '')),
        // 3.17+ workspace symbols carry no resultId → no resolve round-trip;
        // echo the item back so resolvable-capability clients never hang.
        // Spec method name is workspaceSymbol/resolve (no second slash).
        'workspaceSymbol/resolve' => static fn (AnalysisService $svc, array $params): mixed => $params,
        // 3.18 WorkspaceEdit: documentChanges carries versions; the legacy
        // `changes` form is deprecated and never emitted by this server.
        'workspace/willRenameFiles' => static fn (AnalysisService $svc, array $params): mixed => documentChangesWorkspaceEdit(
            $svc,
            svcWillRenameFiles($svc, $params['files'] ?? []),
        ),
        'workspace/willDeleteFiles' => static fn (AnalysisService $svc, array $params): mixed => documentChangesWorkspaceEdit(
            $svc,
            svcWillDeleteFiles($svc, $params['files'] ?? []),
        ),
        'textDocument/prepareCallHierarchy' => static fn (AnalysisService $svc, array $params): mixed => svcCallHierarchyPrepare($svc, dispatchUri($params), dispatchPos($params)),
        'callHierarchy/incomingCalls' => static fn (AnalysisService $svc, array $params): mixed => svcCallHierarchyIncoming($svc, $params['item'] ?? []),
        'callHierarchy/outgoingCalls' => static fn (AnalysisService $svc, array $params): mixed => svcCallHierarchyOutgoing($svc, $params['item'] ?? []),
        'textDocument/prepareTypeHierarchy' => static fn (AnalysisService $svc, array $params): mixed => svcTypeHierarchyPrepare($svc, dispatchUri($params), dispatchPos($params)),
        'typeHierarchy/supertypes' => static fn (AnalysisService $svc, array $params): mixed => svcTypeHierarchySupertypes($svc, $params['item'] ?? []),
        'typeHierarchy/subtypes' => static fn (AnalysisService $svc, array $params): mixed => svcTypeHierarchySubtypes($svc, $params['item'] ?? []),
        'window/workDoneProgress/create' => static fn (AnalysisService $svc, array $params): mixed => null,
        'workspace/executeCommand' => static fn (AnalysisService $svc, array $params): mixed => null,
    ];
}

/**
 * Drift check between the dispatch map and the advertised capabilities.
 *
 * @return list<string> human-readable findings; empty when aligned.
 */
function capabilityDispatchMismatches(): array
{
    $methods = array_keys(lspMethodHandlers());
    $findings = [];

    foreach ($methods as $m) {
        if (!isset(FEATURE_CAPABILITIES[$m]) && !in_array($m, UNADVERTISED_METHODS, true)) {
            $findings[] = "handler without advertised capability: $m";
        }
    }
    foreach (FEATURE_CAPABILITIES as $m => $cap) {
        if (!in_array($m, $methods, true)) {
            $findings[] = "advertised capability '$cap' without handler: $m";
        }
    }
    return $findings;
}
