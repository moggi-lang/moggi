<?php declare(strict_types=1);

namespace Moggi\LSP\Workspace;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\invalidatePath;
use function Moggi\LSP\Protocol\uriToPath;

function handleDidChangeWatchedFiles(AnalysisService $svc, array $params): array
{
    $invalidated = [];
    foreach ($params['changes'] ?? [] as $change) {
        $u = $change['uri'] ?? '';
        if ($u !== '') {
            $svc->invalidatePath(uriToPath($u));
            $invalidated[] = $u;
        }
    }
    return $invalidated;
}
