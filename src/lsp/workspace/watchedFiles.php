<?php declare(strict_types=1);

namespace Moggi\LSP\Workspace;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Protocol\uriToPath;
use function Moggi\Modules\clearPrepareProjectCaches;

/**
 * Watched files changed: invalidate the paths and drop the preparation memo generation.
 *
 * The per-path invalidation is exact for the file that moved, but the prepared-project and
 * checked-module memos are keyed by the closure they were built from, and a watched file (a
 * `.mog`, a manifest, a build input) can change an import or an instance world without being
 * the file a request names. Dropping the generation here is the explicit reset: the next
 * analysis prepares from the files as they are now, not from a closure a previous generation
 * validated. It is done once per notification, not per change, because the memo is shared.
 */
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

    if ($invalidated !== []) {
        clearPrepareProjectCaches();
    }

    return $invalidated;
}
