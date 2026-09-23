<?php declare(strict_types=1);

namespace Moggi\LSP;

use function Moggi\LSP\Protocol\sendNotification;
use function Moggi\LSP\Protocol\writeMessage;

/**
 * Per-request work-done progress (spec `workDoneToken`).
 *
 * Every long-running feature request may carry `params.workDoneToken` (string
 * or integer). When the client negotiated `window.workDoneProgress`, the
 * server creates the progress on the client, reports percentage/message
 * updates and ends it — including a cancelled end state when the request is
 * aborted via `$/cancelRequest`.
 */

/**
 * 3.18 ProgressToken: integer or string. Anything else (arrays, objects,
 * null) is not a token — return null instead of guessing.
 */
function parseProgressToken(mixed $value): int|string|null
{
    if (\is_int($value) && $value >= 0) {
        return $value;
    }
    if (\is_string($value) && $value !== '') {
        return $value;
    }
    return null;
}

/** Extract `workDoneToken` from request params. */
function workDoneParamsToken(array $params): int|string|null
{
    return parseProgressToken($params['workDoneToken'] ?? null);
}

/**
 * Percentage for a report: 0–100, or null when it cannot be computed.
 * Requires a known total; never invents one.
 */
function progressPercent(int $done, int $total): ?int
{
    if ($total <= 0 || $done < 0) {
        return null;
    }
    if ($done >= $total) {
        return 100;
    }
    return (int) floor(($done / $total) * 100);
}

/**
 * Thrown by handlers between work units when the client cancelled the
 * request. The server loop converts it to the spec error `RequestCancelled`
 * (-32800).
 */
final class RequestCancelledException extends \RuntimeException
{
    public function __construct(string $message = 'Request cancelled')
    {
        parent::__construct($message);
    }
}

/**
 * Server-side cancellation registry: `$/cancelRequest` ids → true.
 * Method-level cancellation (spec: `{"id": []}`) clears all pending ids.
 */
final class CancellationRegistry
{
    /** @var array<int|string, true> */
    private array $cancelled = [];

    public function cancel(int|string $id): void
    {
        $this->cancelled[$id] = true;
    }

    /** Spec 3.18: `$/cancelRequest` with `id: []` cancels every in-flight request. */
    public function cancelAll(): void
    {
        $this->cancelled = [];
    }

    public function isCancelled(int|string $id): bool
    {
        return isset($this->cancelled[$id]);
    }

    public function clear(int|string $id): void
    {
        unset($this->cancelled[$id]);
    }

    public function clearAll(): void
    {
        $this->cancelled = [];
    }
}

/**
 * Begin a per-request progress sequence. Returns the client-side token to
 * report on, or null when the client did not negotiate window.workDoneProgress
 * or the request carries no workDoneToken (then the server simply stays quiet —
 * never spamming progress the client did not ask for).
 */
function beginRequestProgress(bool $clientSupportsProgress, int|string|null $token, string $title): int|string|null
{
    if (!$clientSupportsProgress || $token === null) {
        return null;
    }
    writeMessage([
        'jsonrpc' => '2.0',
        'id' => 'moggi-wdp-' . $token,
        'method' => 'window/workDoneProgress/create',
        'params' => ['token' => $token],
    ]);
    sendNotification('$/progress', [
        'token' => $token,
        'value' => ['kind' => 'begin', 'title' => $title, 'percentage' => 0],
    ]);
    return $token;
}

/** Report progress for an active token (no-op when null). */
function reportRequestProgress(int|string|null $token, string $message, ?int $percentage = null): void
{
    if ($token === null) {
        return;
    }
    $value = ['kind' => 'report', 'message' => $message];
    if ($percentage !== null) {
        $value['percentage'] = max(0, min(100, $percentage));
    }
    sendNotification('$/progress', ['token' => $token, 'value' => $value]);
}

/** End a progress sequence, flagging cancellation when aborted (no-op when null). */
function endRequestProgress(int|string|null $token, bool $cancelled = false): void
{
    if ($token === null) {
        return;
    }
    sendNotification('$/progress', [
        'token' => $token,
        'value' => ['kind' => 'end', ...($cancelled ? ['message' => 'cancelled'] : [])],
    ]);
}

/**
 * Cooperative cancellation checkpoint for handlers. Throws
 * RequestCancelledException when the client cancelled this request.
 */
function checkCancelled(CancellationRegistry $cancellations, int|string|null $id): void
{
    if ($id !== null && $cancellations->isCancelled($id)) {
        throw new RequestCancelledException();
    }
}

/**
 * Cancellation checkpoint using the ambient request context installed by the
 * server loop (no-op outside a request, e.g. in unit tests). Handlers call
 * this between work units so `$/cancelRequest` can abort long batches.
 */
function checkCancelledPoint(): void
{
    $cancellations = currentCancellations();
    if ($cancellations !== null) {
        checkCancelled($cancellations, currentRequestId());
    }
}
