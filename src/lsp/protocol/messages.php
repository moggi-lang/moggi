<?php declare(strict_types=1);

namespace Moggi\LSP\Protocol;

function sendNotification(string $method, array $params = []): void
{
    writeMessage(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
}

/**
 * @param int|string|null $id
 * @param mixed $result
 */
function sendResult($id, $result): void
{
    writeMessage(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

/**
 * @param int|string|null $id
 */
function sendError($id, int $code, string $message, $data = null): void
{
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $err['data'] = $data;
    }
    writeMessage(['jsonrpc' => '2.0', 'id' => $id, 'error' => $err]);
}
