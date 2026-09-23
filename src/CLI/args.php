<?php declare(strict_types=1);

namespace Moggi\CLI;

use Moggi\Cache;

use function Moggi\Backend\assertBackend;
use function Moggi\Backend\implementedBackendIds;
use function Moggi\Backend\isBackendImplemented;

function parseBackendValue(?string $value): string
{
    $backend = $value ?? 'php';

    try {
        assertBackend($backend);
        if (!isBackendImplemented($backend)) {
            throw new \InvalidArgumentException(
                "code generation for backend `{$backend}` is not implemented (available: "
                . \implode(', ', implementedBackendIds()) . ')',
            );
        }
    } catch (\InvalidArgumentException $e) {
        \fwrite(STDERR, "error: {$e->getMessage()}\n\n");
        printUsage();
        exit(1);
    }

    return $backend;
}

final class ArgCursor
{
    public function __construct(
        public readonly array $argv,
        public int $index,
    ) {
    }

    public function current(): ?string
    {
        return $this->argv[$this->index] ?? null;
    }

    public function take(): ?string
    {
        $value = $this->current();
        if ($value !== null) {
            ++$this->index;
        }

        return $value;
    }

    public function takeValue(string $flag): string
    {
        $this->take();
        $value = $this->take();
        if ($value === null) {
            cliError("error: {$flag} requires a value");
        }

        return $value;
    }

    public function atHelp(): bool
    {
        $arg = $this->current();
        if ($arg === '--help' || $arg === '-h') {
            printUsage();
            exit(0);
        }

        return false;
    }

    public function takeLibDir(string $flag = '--lib'): string
    {
        $dir = $this->takeValue($flag);
        if (!\is_dir($dir)) {
            cliError("error: {$flag} requires a directory" . ($dir !== '' ? ": {$dir}" : ''));
        }

        return $dir;
    }

    public function takeExistingPath(string $flag): string
    {
        $path = $this->takeValue($flag);
        if (!\is_dir($path) && !\is_file($path)) {
            cliError("error: {$flag} requires a file or directory");
        }

        return $path;
    }

    public function takeNoCache(): void
    {
        Cache\setCacheEnabled(false);
    }
}

/** @return list<string> */
function takeLibDirs(ArgCursor $cursor): array
{
    $dirs = [];
    while (($arg = $cursor->current()) !== null) {
        if ($arg !== '--lib') {
            break;
        }
        $dirs[] = $cursor->takeLibDir();
    }

    return $dirs;
}
