<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Foreign;

use function Moggi\Foreign\parseForeignPath;
use Moggi\IR;

/** @return array{classPath: ?string, hostMember: ?string, dispatch: string, path?: string} */
function foreignPathInfo(IR\ForeignCall $foreign): array
{
    if (isset($foreign->dispatch)) {
        return [
            'classPath' => $foreign->classPath ?? null,
            'hostMember' => $foreign->member ?? null,
            'dispatch' => $foreign->dispatch,
        ];
    }

    return parseForeignPath($foreign->path);
}

/** @param list<string> $argExprs */
