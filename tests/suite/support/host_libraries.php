<?php declare(strict_types=1);

/**
 * Scaffolding for the `cli/host_libraries_*` cases: each drives the real CLI for one host package
 * manager. They are separate cases so the toolchain a leg needs — javac and jar, ilasm and the .NET
 * SDK — is paid for only by the backend that case is about.
 */

/** The checkout these cases drive, found from this file rather than from the caller's cwd. */
function hostLibrariesRoot(): string
{
    $root = __DIR__;
    while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
        $root = \dirname($root);
    }

    return $root;
}

require_once hostLibrariesRoot() . '/src/compiler.php';
require_once __DIR__ . '/process.php';
require_once __DIR__ . '/workspace.php';

/**
 * The program the gate cases compile: one `foreign php` call, so attempting a compile never needs a
 * target toolchain, and a refusal that is not the tree's is never mistaken for one that is.
 */
function hostLibrariesForeignProgram(): string
{
    return "module Main where\n\nimport Data.String\nimport System.IO\n\n"
        . "foreign php function greet \"Acme\\\\Hello\\\\greet\" :: String -> String\n\n"
        . "main :: IO ()\nmain = putStrLn (greet \"composer\")\n";
}

final class HostLibraryCase
{
    /** The case's workspace: fixtures, artifacts and its own cache dir. */
    public readonly string $work;

    /** @var array<string, string> */
    public array $env;

    public int $checks = 0;

    /** @var list<string> */
    private array $skipped = [];

    public function __construct(public readonly string $root)
    {
        $this->work = createTempDir('moggi-host-libraries');
        $this->env = \getenv();
        $this->env['MOGGI_CACHE_DIR'] = $this->work . '/cache';
        seedCacheDir($this->env['MOGGI_CACHE_DIR']);
    }

    /** The Composer fixture: a descriptor and the `vendor/` a resolution leaves beside it. */
    public function composerFixture(): string
    {
        return $this->root . '/tests/registry/bind/fixtures/acme-hello-php';
    }

    /** One `moggi compile`, from the project root, against this case's cache dir. */
    public function compile(array $arguments, ?array $env = null): array
    {
        return $this->run([\PHP_BINARY, $this->root . '/moggi.php', 'compile', ...$arguments], 120, $env, $this->root);
    }

    /** Any other entry point — `install`, `build` — run in the project it belongs to. */
    public function cli(array $arguments, string $cwd, array $env, int $timeoutSeconds = 300): array
    {
        return $this->run([\PHP_BINARY, $this->root . '/moggi.php', ...$arguments], $timeoutSeconds, $env, $cwd);
    }

    public function run(array $command, int $timeoutSeconds = 120, ?array $env = null, ?string $cwd = null): array
    {
        return runCompiledProcess($command, $timeoutSeconds, $env ?? $this->env, $cwd ?? $this->work);
    }

    public function assert(bool $condition, string $message): void
    {
        ++$this->checks;
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }

    /** Record a leg this machine cannot build; `finish()` turns it into the case's skip reason. */
    public function skipLeg(string $reason): void
    {
        $this->skipped[] = $reason;
    }

    public function finish(string $caseName): void
    {
        if ($this->skipped !== []) {
            \fwrite(STDERR, \implode('; ', $this->skipped) . "\n");
            exit(2);
        }

        \printf("%s: %d checks passed\n", $caseName, $this->checks);
    }

    public function cleanup(): void
    {
        removeDirectory($this->work);
    }

    public function copyTree(string $from, string $to): void
    {
        \mkdir($to, 0777, true);
        foreach (\scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $from . '/' . $entry;
            \is_dir($source) ? $this->copyTree($source, $to . '/' . $entry) : \copy($source, $to . '/' . $entry);
        }
    }

    /**
     * Write a stub resolver a `PATH` lookup finds on either platform: this PHP runs the script on
     * Windows through a `.bat` launcher (`findExecutable` resolves the extension, `runProcess` starts
     * the batch file through `cmd`), and a `#!/usr/bin/env php` launcher elsewhere. A bash stub is
     * invisible to a Windows lookup, which reaches the real resolver instead.
     */
    public function stubResolver(string $directory, string $name, string $body): void
    {
        \mkdir($directory, 0777, true);
        $script = $directory . '/' . $name . '-stub.php';
        \file_put_contents($script, "<?php declare(strict_types=1);\n\n" . $body);
        if (\PHP_OS_FAMILY === 'Windows') {
            \file_put_contents($directory . '/' . $name . '.bat', "@echo off\r\n\"" . \PHP_BINARY . "\" \"" . $script . "\" %*\r\n");

            return;
        }
        \file_put_contents($directory . '/' . $name, "#!/usr/bin/env php\n" . \file_get_contents($script));
        \chmod($directory . '/' . $name, 0755);
    }

    /** Put `$directory` first on the child's `PATH`, spelled as that platform's environment has it. */
    public function withPath(array $env, string $directory): array
    {
        foreach (\array_keys($env) as $key) {
            if (\strcasecmp((string) $key, 'PATH') === 0) {
                unset($env[$key]);
            }
        }
        $env['PATH'] = $directory . \PATH_SEPARATOR . (string) \getenv('PATH');

        return $env;
    }
}
