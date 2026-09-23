<?php declare(strict_types=1);

namespace Moggi\CLI;

use function Moggi\CLI\printUsage;

/**
 * @param array{inputPath: string, libDirs: list<string>, rebuild: bool, noCache: bool} $shared
 */
function takeDocsSharedFlag(ArgCursor $cursor, array &$shared): bool
{
    $arg = $cursor->current();
    if ($arg === null) {
        return false;
    }
    if ($arg === '--root') {
        $shared['inputPath'] = $cursor->takeExistingPath('--root');
        return true;
    }
    if ($arg === '--lib') {
        $shared['libDirs'][] = $cursor->takeLibDir();
        return true;
    }
    if ($arg === '--rebuild') {
        $cursor->take();
        $shared['rebuild'] = true;
        return true;
    }
    if ($arg === '--no-cache') {
        $cursor->take();
        $shared['noCache'] = true;
        return true;
    }

    return false;
}

/**
 * The tree the docs tools index when the caller names none: the standard
 * library this compiler was installed with. In a checkout that is `lib/` next
 * to the sources; in a packaged installation it is the library inside
 * `bin/moggi.phar`, so `moggi moogle fromMaybe` works from any directory.
 */
function defaultDocsInput(): string
{
    return \Moggi\Modules\bundledStdlibLibPath() ?? 'lib';
}

/**
 * Shared flags for mogdoc and moogle.
 *
 * @return array{inputPath: string, libDirs: list<string>, rebuild: bool, noCache: bool}
 */
function parseDocsToolOptions(ArgCursor $cursor, ?string $defaultInput = null): array
{
    $shared = [
        'inputPath' => $defaultInput ?? defaultDocsInput(),
        'libDirs' => [],
        'rebuild' => false,
        'noCache' => false,
    ];

    while (takeDocsSharedFlag($cursor, $shared)) {
    }

    return $shared;
}

function takeDocsPort(ArgCursor $cursor, string $flag = '--port'): int
{
    $raw = $cursor->takeValue($flag);
    if (!preg_match('/^\d+$/', $raw) === 1) {
        cliError("error: {$flag} requires a port number between 1 and 65535");
    }
    $port = (int) $raw;
    if ($port < 1 || $port > 65535) {
        cliError("error: {$flag} requires a port number between 1 and 65535");
    }

    return $port;
}

function unknownDocsOption(string $tool, string $arg): never
{
    \fwrite(STDERR, "error: unknown {$tool} option {$arg}\n\n");
    printUsage();
    exit(1);
}
