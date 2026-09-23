<?php declare(strict_types=1);

namespace Moggi\LSP;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\Backend\setCompileBackend;
use function Moggi\LSP\Analysis\flushDueAnalyzes;
use function Moggi\LSP\Analysis\getAnalysisService;
use function Moggi\LSP\Analysis\hasDueAnalyzes;
use function Moggi\LSP\Analysis\secondsUntilNextPending;
use function Moggi\LSP\Protocol\readMessageOrTimeout;
use function Moggi\LSP\Protocol\sendError;
use function Moggi\LSP\Protocol\sendNotification;
use function Moggi\LSP\Protocol\sendResult;
use function Moggi\LSP\Protocol\writeMessage;
use function Moggi\LSP\Workspace\handleConfigurationReply;
use function Moggi\LSP\Workspace\handleDidChangeConfiguration;
use function Moggi\LSP\Workspace\handleDidChangeWatchedFiles;

// ---------------------------------------------------------------------------
// Request-scoped context for cooperative cancellation / progress. Handlers run
// synchronously in this loop; the loop installs the current request before
// dispatch and clears it afterwards. Library/analysis drivers read this state
// (see beginSync / syncYield) to checkpoint between work units.
// ---------------------------------------------------------------------------

/** The cancellation registry of the request currently being served. */
function currentCancellations(): ?CancellationRegistry
{
    return $GLOBALS['__moggi_lsp_cancellations'] ?? null;
}

/** The id of the request currently being served (null outside a request). */
function currentRequestId(): int|string|null
{
    return $GLOBALS['__moggi_lsp_request_id'] ?? null;
}

/** @internal */
function setRequestContext(int|string|null $id, CancellationRegistry $cancellations, ?int $token): void
{
    $GLOBALS['__moggi_lsp_request_id'] = $id;
    $GLOBALS['__moggi_lsp_cancellations'] = $cancellations;
    $GLOBALS['__moggi_lsp_progress_token'] = $token;
}

/** @internal */
function clearRequestContext(): void
{
    unset(
        $GLOBALS['__moggi_lsp_request_id'],
        $GLOBALS['__moggi_lsp_cancellations'],
        $GLOBALS['__moggi_lsp_progress_token'],
    );
}

/** Active per-request progress token (null when none/no support). */
function currentProgressToken(): int|string|null
{
    return $GLOBALS['__moggi_lsp_progress_token'] ?? null;
}

/**
 * The server speaks JSON-RPC over stdout, so *nothing* else may ever be written
 * there: a single PHP notice lands between the previous body and the next
 * `Content-Length:` header, the client fails to parse the frame and tears the
 * connection down. PHP's diagnostics therefore go to stderr (visible in the
 * client's log) instead of the protocol channel.
 */
function configureStdioErrors(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', 'stderr');
}

function runServer(array $libDirs = []): int
{
    configureStdioErrors();
    setCompileBackend('php');
    $svc = getAnalysisService($libDirs);
    $shutdownReceived = false;
    $initialized = false;
    $workDoneProgress = false;
    $trace = 'off';
    $cancellations = new CancellationRegistry();

    $handlers = lspMethodHandlers();

    while (true) {
        $timeout = $svc->secondsUntilNextPending();
        // Cap select wait; also wake periodically even without pending work.
        if ($timeout === null) {
            $timeout = 1.0;
        } elseif ($timeout < 0) {
            $timeout = 0.0;
        }

        $malformed = false;
        $msg = readMessageOrTimeout(\STDIN, $timeout, $malformed);
        if ($msg === false) {
            if ($malformed) {
                // Base protocol: answer invalid frames instead of dying; the
                // stream stays positionally in sync for length-prefixed bodies.
                sendNotification('window/showMessage', [
                    'type' => 2,
                    'message' => 'Moggi language server received a malformed JSON-RPC message.',
                ]);
            } else {
                // Idle tick — flush due analyzes, and notice a compiler that
                // moved on without us.
                publishFlushedDiagnostics($svc, $workDoneProgress);
                watchCompilerRevision();
            }
            continue;
        }
        if ($msg === null) {
            break;
        }

        $id = $msg['id'] ?? null;
        $method = $msg['method'] ?? null;
        $params = $msg['params'] ?? [];
        $result = $msg['result'] ?? null;

        // Client replies to server→client requests (no method): configuration
        // pull replies are matched by id prefix; everything else is ignored.
        if ($method === null) {
            handleConfigurationReply($svc, $id, $result);
            continue;
        }

        if ($method === 'exit') {
            return $shutdownReceived ? 0 : 1;
        }

        if ($method === 'initialize') {
            if ($initialized) {
                if ($id !== null) {
                    sendError($id, -32600, 'Invalid Request: server is already initialized');
                }
                continue;
            }
            $initialized = true;
            $workDoneProgress = handleInitialize($svc, $params, $id);
            continue;
        }

        if ($method === 'shutdown') {
            if (!$initialized) {
                if ($id !== null) {
                    sendError($id, -32002, 'Server not initialized');
                }
                continue;
            }
            if ($shutdownReceived) {
                // 3.18: repeated shutdown is invalid — error it.
                if ($id !== null) {
                    sendError($id, -32600, 'Invalid Request: server is already shut down');
                }
                continue;
            }
            $shutdownReceived = handleShutdown($svc, $id);
            continue;
        }

        // 3.18: every request other than initialize/exit received before the
        // initialize request must be answered with ServerNotInitialized;
        // notifications before initialize must be dropped.
        if (!$initialized) {
            if ($id !== null && $method !== 'initialized') {
                sendError($id, -32002, 'Server not initialized');
            }
            continue;
        }

        // 3.18: after shutdown, all requests error with InvalidRequest and all
        // notifications (except exit) are ignored.
        if ($shutdownReceived) {
            if ($id !== null) {
                sendError($id, -32600, 'Invalid Request: server is shut down');
            }
            continue;
        }

        if ($method === '$/cancelRequest') {
            // 3.18: `id` is int|string, or an empty array meaning "cancel all".
            $cancelId = $params['id'] ?? null;
            if (\is_array($cancelId) && $cancelId === []) {
                $cancellations->cancelAll();
            } elseif (\is_int($cancelId) || \is_string($cancelId)) {
                $cancellations->cancel($cancelId);
            }
            continue;
        }

        if ($method === '$/setTrace') {
            $trace = (string) ($params['value'] ?? 'off');
            continue;
        }

        // 3.18: emit $/logTrace when the client asked for trace output.
        if ($trace === 'verbose' || $trace === 'messages') {
            $logTrace = ['message' => "Received request '{$method}'"];
            if ($trace === 'verbose') {
                $logTrace['verbose'] = json_encode($params);
            }
            sendNotification('$/logTrace', $logTrace);
        }

        switch ($method) {
            case 'client/registerCapability':
                handleRegisterCapability($svc, $params, $id);
                continue 2;

            case 'client/unregisterCapability':
                handleUnregisterCapability($svc, $params, $id);
                continue 2;

            case 'initialized':
                handleInitialized($svc);
                publishFlushedDiagnostics($svc, $workDoneProgress);
                continue 2;

            case 'workspace/didChangeWorkspaceFolders':
                publishFlushedDiagnostics($svc, $workDoneProgress);
                continue 2;

            case 'workspace/didChangeConfiguration':
                handleDidChangeConfiguration($svc, $params);
                continue 2;

            case 'workspace/didChangeWatchedFiles':
                handleDidChangeWatchedFiles($svc, $params);
                publishFlushedDiagnostics($svc, $workDoneProgress);
                continue 2;

            case 'textDocument/didOpen':
                handleDidOpen($svc, $params);
                publishFlushedDiagnostics($svc, $workDoneProgress);
                continue 2;

            case 'textDocument/didChange':
                handleDidChange($svc, $params);
                // Opportunistically flush anything already due.
                publishFlushedDiagnostics($svc, $workDoneProgress);
                continue 2;

            case 'textDocument/didSave':
                handleDidSave($svc, $params);
                publishFlushedDiagnostics($svc, $workDoneProgress);
                continue 2;

            case 'textDocument/didClose':
                handleDidClose($svc, $params);
                continue 2;

            default:
                break;
        }

        // 3.18: requests whose method starts with '$/' that the server does
        // not understand must error with MethodNotFound (not silently ignore).
        if (str_starts_with($method, '$/') && !isset($handlers[$method])) {
            if ($id !== null) {
                sendError($id, -32601, "Method not found: $method");
            }
            continue;
        }

        if (isset($handlers[$method])) {
            runHandler($svc, $handlers[$method], $params, $id, $cancellations, $workDoneProgress);
            continue;
        }

        if ($id !== null) {
            sendError($id, -32601, "Method not found: $method");
        }
        publishFlushedDiagnostics($svc, $workDoneProgress);
    }

    return $shutdownReceived ? 0 : 1;
}

/**
 * Dispatch one feature request with per-request progress and cooperative
 * cancellation. Notifications go through publishFlushedDiagnostics as before;
 * requests answer via their handler (analyze happens inside).
 */
function runHandler(
    AnalysisService $svc,
    callable $handler,
    array $params,
    int|string|null $id,
    CancellationRegistry $cancellations,
    bool $clientSupportsProgress,
): void {
    if ($id === null) {
        // Unadvertised-as-request corner: handle without progress/cancel.
        try {
            $handler($svc, $params);
        } catch (\Throwable) {
        }
        publishFlushedDiagnostics($svc, $clientSupportsProgress);
        return;
    }

    $token = beginRequestProgress(
        $clientSupportsProgress,
        workDoneParamsToken($params),
        'Moggi',
    );
    // Pre-existing cancellation for an id the client already gave up on.
    if ($cancellations->isCancelled($id)) {
        $cancellations->clear($id);
        endRequestProgress($token, true);
        sendError($id, -32800, 'Request cancelled');
        return;
    }
    setRequestContext($id, $cancellations, $token);

    try {
        sendResult($id, $handler($svc, $params));
    } catch (RequestCancelledException $e) {
        sendError($id, -32800, $e->getMessage());
        endRequestProgress($token, true);
    } catch (\Throwable $e) {
        sendError($id, -32603, $e->getMessage());
        endRequestProgress($token, false);
    } finally {
        clearRequestContext();
        $cancellations->clear($id);
    }
    publishFlushedDiagnostics($svc, $clientSupportsProgress);
}

/**
 * Flush all debounced analyzes that are due, publishing their diagnostics
 * with work-done progress when the client negotiated it.
 */
function publishFlushedDiagnostics(AnalysisService $svc, bool $workDoneProgress = false): void
{
    static $progressSeq = 0;
    $willWork = $svc->hasDueAnalyzes(false);
    $token = null;
    if ($willWork && $workDoneProgress) {
        $token = 'moggi-tc-' . (++$progressSeq);
        // Fire-and-forget create; do not block the stdio loop on the client reply.
        writeMessage([
            'jsonrpc' => '2.0',
            'id' => 'moggi-wdp-' . $progressSeq,
            'method' => 'window/workDoneProgress/create',
            'params' => ['token' => $token],
        ]);
        sendNotification('$/progress', [
            'token' => $token,
            'value' => [
                'kind' => 'begin',
                'title' => 'Moggi',
                'message' => 'Typechecking…',
                'cancellable' => false,
            ],
        ]);
    }
    if ($willWork) {
        sendNotification('moggi/status', ['state' => 'checking', 'message' => 'Checking…']);
    }
    $due = $svc->flushDueAnalyzes(false);
    foreach ($due as $uri => $analysis) {
        sendNotification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => $analysis->diagnostics,
        ]);
    }
    if ($token !== null) {
        sendNotification('$/progress', [
            'token' => $token,
            'value' => ['kind' => 'end'],
        ]);
    }
    if ($due !== []) {
        sendNotification('moggi/status', ['state' => 'ready', 'message' => 'Ready']);
    }
}
