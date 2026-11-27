<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Host;

use Moggi\Backend\VerificationUnavailable;

use function Moggi\Install\installationRoot;

/**
 * The PHP a `foreign php` declaration is verified against.
 *
 * PHP is the one backend whose host is a real process, so "ask the host" means
 * "ask a PHP that will run the emitted program". Two processes can answer, and
 * they are not interchangeable:
 *
 *   - the bundled `runtime/php`, whose extension set the project controls and
 *     whose `php.ini` an extension install writes to;
 *   - the compiler's own process, when that process *is* a real CLI PHP — a
 *     checkout, or `php bin/moggi.phar`.
 *
 * A distribution's `bin/moggi` is a micro SAPI runtime: it has no command line,
 * no `dl()`, and a deliberately minimal extension set that is never the host a
 * project's program runs on. So it never answers for itself — it asks the
 * bundled runtime, and refuses the declaration when there is none.
 *
 * `MOGGI_PHP_HOST` names another PHP (a server's, a CI image's). The answer is
 * then that process's, which is what `moggi runtime php verify --php PATH` reports.
 *
 * The interface is deliberately small: existence, shape and arity of exactly
 * the members a foreign import can name. A probe that is spawned answers one
 * JSON request per line, so a compile asks one process many questions rather
 * than starting one process per symbol.
 */
const HOST_ENV = 'MOGGI_PHP_HOST';

interface PhpHost
{
    /** The host as a diagnostic names it, e.g. `the bundled PHP (8.6.0)`. */
    public function label(): string;

    public function version(): string;

    /** @return list<string> */
    public function extensions(): array;

    /** @return ?array{required: int, total: int, variadic: bool} null when the host has no such function */
    public function functionShape(string $function): ?array;

    /**
     * @return ?array{instantiable: bool, constructor: ?array{required: int, total: int, variadic: bool}}
     *   null when the host has no such class, interface or enum
     */
    public function classShape(string $class): ?array;

    /**
     * @return ?array{static: bool, required: int, total: int, variadic: bool, magic: bool}
     *   null when the host has no such method
     */
    public function methodShape(string $class, string $member): ?array;

    /** Whether `$class::$name` (or the global constant when `$class` is null) is defined. */
    public function hasConstant(?string $class, string $name): bool;

    /**
     * The class's dynamic-call handlers, which make a member name a legal call
     * even though reflection cannot see it.
     *
     * @return array{call: bool, callStatic: bool}
     */
    public function magic(string $class): array;

    public function close(): void;
}

/**
 * The host the compiler should ask, or throw when it has none.
 *
 * Memoized for the process: a compile asks the same host every time, and a
 * spawned probe is worth starting once.
 */
function phpHost(): PhpHost
{
    static $host = null;
    if ($host !== null) {
        return $host;
    }

    $binary = phpHostBinary();
    if ($binary === null) {
        throw new VerificationUnavailable(
            'this installation bundles no PHP, so there is nothing to verify `foreign php` against'
            . ' — use a distribution that bundles PHP, or set ' . HOST_ENV . ' to one',
        );
    }

    $host = phpHostIsCurrentProcess($binary) ? new ReflectionHost() : new ProbeHost($binary);

    return $host;
}

/**
 * The PHP binary the host is, or null when this installation has none.
 *
 * The order is the one that keeps a project's answers stable: an explicit
 * `MOGGI_PHP_HOST`, then the bundled runtime, then the process itself when it is
 * a CLI PHP. A micro SAPI process is never its own host, so a PHP-less variant
 * refuses rather than answering from a runtime no program will run on.
 */
function phpHostBinary(): ?string
{
    $named = \getenv(HOST_ENV);
    if (\is_string($named) && $named !== '') {
        if (! \is_file($named)) {
            throw new VerificationUnavailable(HOST_ENV . " names no PHP binary: {$named}");
        }

        return $named;
    }

    $root = installationRoot();
    if ($root !== null) {
        $bundled = $root . '/runtime/php/' . (\PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'bin/php');
        if (\is_file($bundled)) {
            return $bundled;
        }
    }

    if (\PHP_SAPI === 'cli' && \PHP_BINARY !== '' && \is_file(\PHP_BINARY)) {
        return \PHP_BINARY;
    }

    return null;
}

/** Whether the host is this very process, whose reflection is then exact and free. */
function phpHostIsCurrentProcess(string $binary): bool
{
    if (\PHP_SAPI !== 'cli' || \PHP_BINARY === '') {
        return false;
    }

    $current = \realpath(\PHP_BINARY);
    $candidate = \realpath($binary);

    return $current !== false && $candidate !== false && $current === $candidate;
}

/** The host's extension set, or null when this installation has no PHP to ask. */
function phpHostExtensions(): ?array
{
    try {
        return phpHost()->extensions();
    } catch (VerificationUnavailable) {
        return null;
    }
}

/** The host as a diagnostic names it, or a phrase for "there is no host". */
function phpHostLabel(): string
{
    try {
        return phpHost()->label();
    } catch (VerificationUnavailable) {
        return 'the PHP host';
    }
}

/** A PHP binary named on a command line or an option, as a probe host. */
function phpHostAt(string $binary): PhpHost
{
    if (! \is_file($binary)) {
        throw new VerificationUnavailable("no PHP binary at {$binary}");
    }

    return phpHostIsCurrentProcess($binary) ? new ReflectionHost() : new ProbeHost($binary);
}

/**
 * The compiler's own process, asked through reflection.
 *
 * This is the cheap answer, and the exact one: a checkout verifies against the
 * PHP that will also run the tests, so nothing has to be spawned and no answer
 * is second-hand.
 */
final class ReflectionHost implements PhpHost
{
    public function label(): string
    {
        return 'the PHP this compiler runs on (' . \PHP_VERSION . ')';
    }

    public function version(): string
    {
        return \PHP_VERSION;
    }

    public function extensions(): array
    {
        return \get_loaded_extensions();
    }

    public function functionShape(string $function): ?array
    {
        if (! \function_exists($function)) {
            return null;
        }

        return shapeOf(new \ReflectionFunction($function));
    }

    public function classShape(string $class): ?array
    {
        $reflection = classNameReflection($class);
        if ($reflection === null) {
            return null;
        }

        $constructor = null;
        if ($reflection->hasMethod('__construct')) {
            $constructor = shapeOf($reflection->getMethod('__construct'));
        }

        return [
            'instantiable' => $reflection->isInstantiable(),
            'constructor' => $constructor,
        ];
    }

    public function methodShape(string $class, string $member): ?array
    {
        $reflection = classNameReflection($class);
        if ($reflection === null || ! $reflection->hasMethod($member)) {
            return null;
        }

        $method = $reflection->getMethod($member);

        return [
            'static' => $method->isStatic(),
            'required' => $method->getNumberOfRequiredParameters(),
            'total' => $method->getNumberOfParameters(),
            'variadic' => $method->isVariadic(),
            'magic' => $reflection->hasMethod('__call') || $reflection->hasMethod('__callStatic'),
        ];
    }

    public function hasConstant(?string $class, string $name): bool
    {
        return \defined($class === null ? $name : $class . '::' . $name);
    }

    public function magic(string $class): array
    {
        $reflection = classNameReflection($class);

        return [
            'call' => $reflection !== null && $reflection->hasMethod('__call'),
            'callStatic' => $reflection !== null && $reflection->hasMethod('__callStatic'),
        ];
    }

    public function close(): void
    {
    }
}

/** The host class, interface or enum `$class` names, or null when the host has no such name. */
function classNameReflection(string $class): ?\ReflectionClass
{
    if (\class_exists($class) || \interface_exists($class) || (\function_exists('enum_exists') && \enum_exists($class))) {
        return new \ReflectionClass($class);
    }

    return null;
}

/** @return array{required: int, total: int, variadic: bool} */
function shapeOf(\ReflectionFunctionAbstract $callable): array
{
    return [
        'required' => $callable->getNumberOfRequiredParameters(),
        'total' => $callable->getNumberOfParameters(),
        'variadic' => $callable->isVariadic(),
    ];
}

/**
 * A PHP host behind a long-lived child process.
 *
 * `runtime/php` is the host a distribution verifies against, and the compiler
 * cannot reflect it in-process: it is another binary. Spawning it once per
 * compile and asking over a pipe keeps the check honest without paying a
 * process per symbol — and it is the same binary that will run the program.
 *
 * The bundled `runtime/php` is isolated: it runs with its own `php.ini` and none
 * of the caller's `PHP_INI_SCAN_DIR`, so an extension
 * `moggi runtime php install-ext` installed takes part in verification instead of
 * staying invisible until run time, and a host user's scan directory — which is
 * not this project's configuration — cannot answer for it.
 *
 * Any other host keeps its own configuration. Its `php.ini` and scan
 * directories are exactly what decide which extensions it loads, so overriding
 * them would verify against a PHP no program would ever run on: a Nix or distro
 * build loads every module from the scan directory it sets itself.
 */
final class ProbeHost implements PhpHost
{
    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    private string $logFile;

    private ?string $version = null;

    /** @var list<string>|null */
    private ?array $extensions = null;

    public function __construct(private readonly string $binary)
    {
        $this->logFile = \sys_get_temp_dir() . '/moggi-php-host-' . \getmypid() . '-' . \bin2hex(\random_bytes(4)) . '.log';
    }

    public function label(): string
    {
        $where = $this->bundled() ? 'the bundled PHP' : "the PHP at {$this->binary}";

        return "{$where} ({$this->version()})";
    }

    /** Whether this binary is the installation's own `runtime/php`. */
    private function bundled(): bool
    {
        $root = installationRoot();
        $home = $root === null ? false : \realpath($root . '/runtime/php');
        $binary = \realpath($this->binary);

        return $home !== false && $binary !== false && \str_starts_with($binary, $home . \DIRECTORY_SEPARATOR);
    }

    public function version(): string
    {
        $this->start();

        return (string) ($this->version ?? '');
    }

    public function extensions(): array
    {
        $this->start();

        return $this->extensions ?? [];
    }

    public function functionShape(string $function): ?array
    {
        $reply = $this->ask(['q' => 'function', 'name' => $function]);
        if (($reply['found'] ?? false) !== true) {
            return null;
        }

        return [
            'required' => (int) $reply['required'],
            'total' => (int) $reply['total'],
            'variadic' => (bool) $reply['variadic'],
        ];
    }

    public function classShape(string $class): ?array
    {
        $reply = $this->ask(['q' => 'class', 'name' => $class]);
        if (($reply['found'] ?? false) !== true) {
            return null;
        }

        return [
            'instantiable' => (bool) $reply['instantiable'],
            'constructor' => \is_array($reply['constructor'] ?? null) ? $reply['constructor'] : null,
        ];
    }

    public function methodShape(string $class, string $member): ?array
    {
        $reply = $this->ask(['q' => 'method', 'class' => $class, 'member' => $member]);
        if (($reply['found'] ?? false) !== true) {
            return null;
        }

        return [
            'static' => (bool) $reply['static'],
            'required' => (int) $reply['required'],
            'total' => (int) $reply['total'],
            'variadic' => (bool) $reply['variadic'],
            'magic' => (bool) $reply['magic'],
        ];
    }

    public function hasConstant(?string $class, string $name): bool
    {
        $reply = $this->ask(['q' => 'constant', 'class' => $class, 'name' => $name]);

        return ($reply['defined'] ?? false) === true;
    }

    public function magic(string $class): array
    {
        $reply = $this->ask(['q' => 'magic', 'class' => $class]);

        return [
            'call' => (bool) ($reply['call'] ?? false),
            'callStatic' => (bool) ($reply['callStatic'] ?? false),
        ];
    }

    public function close(): void
    {
        if (\is_resource($this->stdin)) {
            @\fclose($this->stdin);
        }
        $this->stdin = null;
        if (\is_resource($this->stdout)) {
            @\fclose($this->stdout);
        }
        $this->stdout = null;
        if (\is_resource($this->process)) {
            @\proc_terminate($this->process);
            @\proc_close($this->process);
        }
        $this->process = null;
        @\unlink($this->logFile);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function start(): void
    {
        if ($this->process !== null) {
            return;
        }

        if (! \function_exists('proc_open')) {
            throw new \RuntimeException('the PHP host cannot be started: proc_open is disabled');
        }

        $command = [$this->binary];
        $env = \getenv();
        if ($this->bundled()) {
            $ini = $this->iniPath();
            if ($ini !== null) {
                $command[] = '-c';
                $command[] = $ini;
            } else {
                $command[] = '-n';
            }
            $command[] = '-d';
            $command[] = 'extension_dir=' . $this->extensionDir();
            $env['PHP_INI_SCAN_DIR'] = '';
        }
        $command[] = '-d';
        $command[] = 'display_errors=stderr';
        $command[] = '-d';
        $command[] = 'log_errors=0';
        $command[] = '-d';
        $command[] = 'error_reporting=E_ALL';
        $command[] = '-r';
        $command[] = PROBE_SCRIPT;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $this->logFile, 'a'],
        ];
        $process = @\proc_open($command, $descriptors, $pipes, null, $env);
        if (! \is_resource($process)) {
            throw new \RuntimeException("cannot start the PHP host at {$this->binary}");
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];

        $reply = $this->ask(['q' => 'extensions']);
        $this->version = (string) ($reply['version'] ?? '');
        $this->extensions = \array_values(\array_map('strval', (array) ($reply['extensions'] ?? [])));
    }

    /**
     * The `php.ini` beside the host, if it has one: the bundled runtime keeps it
     * in its home directory (the layout `activation` sets `PHPRC` for), and a
     * system PHP usually has none beside the binary, in which case `-n` is right.
     */
    private function iniPath(): ?string
    {
        if (\realpath($this->binary) === false) {
            return null;
        }

        foreach ([\dirname($this->binary), \dirname($this->binary, 2)] as $dir) {
            if (\is_file($dir . '/php.ini')) {
                return $dir . '/php.ini';
            }
        }

        return null;
    }

    /**
     * Where this host's extensions live: `phpHome/ext` for both layouts, since a
     * source build puts the binary in `bin/` and the Windows zip puts it at the
     * top of `phpHome`.
     */
    private function extensionDir(): string
    {
        $home = \PHP_OS_FAMILY === 'Windows' ? \dirname($this->binary) : \dirname($this->binary, 2);

        return $home . '/ext';
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function ask(array $query): array
    {
        if ($this->process === null) {
            $this->start();
        }
        if (! \is_resource($this->stdin) || ! \is_resource($this->stdout)) {
            throw new \RuntimeException("the PHP host at {$this->binary} is not answering");
        }

        $line = \json_encode($query, \JSON_UNESCAPED_SLASHES) . "\n";
        if (@\fwrite($this->stdin, $line) === false || @\fflush($this->stdin) === false) {
            throw new \RuntimeException("the PHP host at {$this->binary} stopped accepting requests");
        }

        $reply = @\fgets($this->stdout);
        if ($reply === false) {
            $log = @\file_get_contents($this->logFile);
            throw new \RuntimeException(
                "the PHP host at {$this->binary} stopped answering"
                . (\is_string($log) && \trim($log) !== '' ? ': ' . \substr(\trim($log), -500) : ''),
            );
        }

        $decoded = \json_decode($reply, true);
        if (! \is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new \RuntimeException("the PHP host at {$this->binary} answered something that is not a probe reply");
        }

        return $decoded;
    }
}

/**
 * The child's side of the probe protocol: one JSON object per line in, one out.
 *
 * Everything is asked of the *loaded* PHP rather than of source: an extension
 * that is installed but broken cannot answer, which is the answer a build needs.
 * A name the host does not know is a verdict (`found: false`), never an error.
 */
const PROBE_SCRIPT = <<<'PHP'
$in = fopen('php://stdin', 'r');
while (($line = fgets($in)) !== false) {
    $request = json_decode($line, true);
    $reply = ['ok' => true];
    if (!is_array($request)) {
        $reply = ['ok' => false];
    } else {
        switch ($request['q'] ?? '') {
            case 'extensions':
                $reply['version'] = PHP_VERSION;
                $reply['extensions'] = get_loaded_extensions();
                $reply['zts'] = PHP_ZTS;
                break;
            case 'function':
                $name = (string) ($request['name'] ?? '');
                if (function_exists($name)) {
                    $r = new ReflectionFunction($name);
                    $reply['found'] = true;
                    $reply['required'] = $r->getNumberOfRequiredParameters();
                    $reply['total'] = $r->getNumberOfParameters();
                    $reply['variadic'] = $r->isVariadic();
                } else {
                    $reply['found'] = false;
                }
                break;
            case 'class':
                $name = (string) ($request['name'] ?? '');
                $found = class_exists($name) || interface_exists($name) || (function_exists('enum_exists') && enum_exists($name));
                $reply['found'] = $found;
                if ($found) {
                    $r = new ReflectionClass($name);
                    $reply['instantiable'] = $r->isInstantiable();
                    $reply['constructor'] = null;
                    if ($r->hasMethod('__construct')) {
                        $c = $r->getMethod('__construct');
                        $reply['constructor'] = [
                            'required' => $c->getNumberOfRequiredParameters(),
                            'total' => $c->getNumberOfParameters(),
                            'variadic' => $c->isVariadic(),
                        ];
                    }
                }
                break;
            case 'method':
                $class = (string) ($request['class'] ?? '');
                $member = (string) ($request['member'] ?? '');
                $found = class_exists($class) || interface_exists($class) || (function_exists('enum_exists') && enum_exists($class));
                $r = $found ? new ReflectionClass($class) : null;
                $reply['found'] = $found && $r->hasMethod($member);
                if ($reply['found']) {
                    $m = $r->getMethod($member);
                    $reply['static'] = $m->isStatic();
                    $reply['required'] = $m->getNumberOfRequiredParameters();
                    $reply['total'] = $m->getNumberOfParameters();
                    $reply['variadic'] = $m->isVariadic();
                    $reply['magic'] = $r->hasMethod('__call') || $r->hasMethod('__callStatic');
                }
                break;
            case 'constant':
                $class = $request['class'] ?? null;
                $name = (string) ($request['name'] ?? '');
                $reply['defined'] = defined($class === null ? $name : $class . '::' . $name);
                break;
            case 'magic':
                $name = (string) ($request['class'] ?? '');
                $found = class_exists($name) || interface_exists($name) || (function_exists('enum_exists') && enum_exists($name));
                $reply['found'] = $found;
                $reply['call'] = $found && method_exists($name, '__call');
                $reply['callStatic'] = $found && method_exists($name, '__callStatic');
                break;
            default:
                $reply = ['ok' => false];
        }
    }
    echo json_encode($reply, JSON_UNESCAPED_SLASHES), "\n";
    fflush(STDOUT);
}
PHP;
