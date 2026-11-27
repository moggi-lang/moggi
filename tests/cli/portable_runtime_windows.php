<?php declare(strict_types=1);

/**
 * `runtime php build` for Windows: the copy, its ini, and its launcher.
 *
 * A Windows runtime is a directory of ordinary files, so the tree `build` writes
 * is inspected for real on any host. What a host without Windows cannot do is
 * run `cmd.exe`, so the launcher is checked twice over: its text has to quote
 * every path it builds and never change the caller's directory, and the command
 * line that text produces is run against a stand-in `php.exe` to show that a
 * copy below spaces, parentheses, `&`, `%` and `!` reaches PHP unmangled, with
 * the caller's argument boundaries intact.
 *
 * The copy is built into such a path on purpose: the probe that decides which
 * extensions to enable runs from there too, so a path the builder or the
 * launcher mishandled would show up as a missing extension, not just as a
 * string that differs.
 *
 * The copy is inspected on any host, but `php.exe` has to be started, and this
 * case stands one in with a shell script: a Windows host cannot run that, so it
 * reports the case as not applicable rather than failing on a harness it cannot
 * start. Real Windows coverage is `package.yml`, which runs `runtime php build`
 * on the packaged runtime, and the manual check in docs/ffi.md.
 */
if (\PHP_OS_FAMILY === 'Windows') {
    \fwrite(STDERR, 'the portable Windows case needs a POSIX host for its php.exe stand-in');
    exit(2);
}

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Backend\Php\Portable\makePortableWindowsTree;
use function Moggi\Backend\Php\Portable\mirrorPortableRuntime;
use function Moggi\Backend\Php\Store\manifest;
use function Moggi\Backend\Php\Store\removeSourceTree;
use function Moggi\Compiler\runProcess;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$same = static function ($expected, $actual, string $message) use ($assert): void {
    $assert(
        $expected === $actual,
        $message . "\n  expected: " . \var_export($expected, true) . "\n  actual:   " . \var_export($actual, true),
    );
};

$checks = 0;
$check = static function (bool $condition, string $message) use ($assert, &$checks): void {
    ++$checks;
    $assert($condition, $message);
};

/**
 * A stand-in for a Windows PHP, for a host that cannot run one.
 *
 * The builder asks `-n` which modules exist, so that is the module question.
 * Any other invocation is the launcher's, and the answer is what PHP received:
 * every argument, the binary's own path, and PHPRC. Tabs separate value from
 * label because the values themselves contain spaces and shell metacharacters.
 */
$standIn = <<<'SH'
#!/bin/sh
if [ "$1" = "-n" ]; then
    modules="Core,date"
    for argument in "$@"; do
        case "$argument" in
            extension=php_good.dll) modules="$modules,good" ;;
        esac
    done
    printf '%s' "$modules"
    exit 0
fi
for argument in "$@"; do
    printf 'ARG\t%s\n' "$argument"
done
printf 'PHP\t%s\n' "$0"
printf 'PHPRC\t%s\n' "$PHPRC"
SH;

$work = \sys_get_temp_dir() . '/moggi-portable-windows-' . \bin2hex(\random_bytes(4));
$copy = $work . '/win rt (x) & %TEMP% !bang!/php copy';

try {
    $home = $work . '/home';
    \mkdir($home . '/ext', 0777, true);
    \file_put_contents($home . '/php.exe', $standIn);
    \chmod($home . '/php.exe', 0755);
    foreach (['php_good.dll', 'php_needsoracle.dll', 'php_compiledin.dll'] as $dll) {
        \file_put_contents($home . '/ext/' . $dll, '');
    }
    \file_put_contents($home . '/php.ini', \implode("\n", [
        '; Configuration of the PHP runtime bundled with Moggi.',
        'extension_dir="${PHPRC}/ext"',
        'extension=php_compiledin.dll',
        'zend_extension=opcache.dll',
        'memory_limit=128M',
        '',
    ]));
    \file_put_contents($home . '/extensions.json', \json_encode([
        'runtime' => 'php',
        'php' => '8.6.0',
        'abi' => ['version' => '8.6.0', 'zts' => true],
        'extensions' => ['Core', 'date', 'good'],
        'installed' => [],
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");

    $unavailable = makePortableWindowsTree($home, $copy, '8.6.0', true);

    $check(\is_file($copy . '/php.exe'), 'the copy must carry php.exe');
    $ini = (string) \file_get_contents($copy . '/php.ini');
    $check(
        \str_contains($ini, 'memory_limit=128M') && \str_contains($ini, 'zend_extension=opcache.dll'),
        'the copy\'s ini must keep the settings it was not written to change',
    );
    $check(! \str_contains($ini, '${PHPRC}'), 'the copy must not resolve extension_dir through PHPRC');
    $check(
        \str_contains($ini, 'extension_dir="ext"'),
        'the copy\'s ini must name ext relative to itself',
    );
    $check(
        \str_contains($ini, 'extension=php_good.dll'),
        'an extension this PHP can load must be enabled: ' . $ini,
    );
    $check(
        ! \str_contains($ini, 'php_needsoracle.dll'),
        'an extension whose own dependencies are missing must not be enabled: ' . $ini,
    );
    $check(
        ! \str_contains($ini, 'php_compiledin.dll'),
        'a module compiled into the binary must not be enabled twice: ' . $ini,
    );
    $same(
        ['php_compiledin.dll', 'php_needsoracle.dll'],
        $unavailable,
        'the DLLs left out must be reported, so a missing extension is not silent',
    );

    $record = \json_decode((string) \file_get_contents($copy . '/extensions.json'), true);
    $check(
        ($record['php'] ?? '') === '8.6.0' && ($record['abi']['zts'] ?? null) === true,
        'the copy must record the PHP version and ABI it was prepared from',
    );
    $same(
        ['php_compiledin.dll', 'php_needsoracle.dll'],
        $record['unavailable'] ?? [],
        'the copy must record the DLLs it left out, so a reused build reports them too',
    );
    $same(
        ['php_compiledin.dll', 'php_needsoracle.dll'],
        manifest($copy)['unavailable'],
        'reading the copy back must report the same skipped DLLs a reuse prints',
    );

    $launcher = (string) \file_get_contents($copy . '/bin/php.cmd');
    $check(
        \str_contains($launcher, "setlocal DisableDelayedExpansion\r\n"),
        'the launcher must disable delayed expansion, so a `!` in the path stays a character',
    );
    $check(
        \str_contains($launcher, "for %%i in (\"%~dp0..\") do set \"php_home=%%~fi\"\r\n"),
        'the launcher must canonicalise the copy path instead of handing PHP a `..` segment',
    );
    $check(
        \str_contains($launcher, "if \"%php_home:~-1%\"==\"\\\" set \"php_home=%php_home:~0,-1%\"\r\n"),
        'the launcher must strip a trailing separator from a copy that sits at a drive root',
    );
    $invocation = '"%php_home%\php.exe" -d "extension_dir=%php_home%\ext" %*';
    $check(
        \str_contains($launcher, $invocation . "\r\n"),
        'every path the launcher builds must be quoted, and `%*` must pass the arguments through: ' . $launcher,
    );
    $check(
        \preg_match('/^\s*(?:cd|pushd|chdir)\b/mi', $launcher) !== 1,
        'the launcher must not change the caller\'s directory: ' . $launcher,
    );

    $php = $copy . '/php.exe';
    $extensions = $copy . '/ext';
    $windowsCopy = \str_replace('/', '\\', $copy);
    $expanded = \str_replace('%php_home%', $windowsCopy, $invocation);
    $check(
        $expanded === '"' . $windowsCopy . '\\php.exe" -d "extension_dir=' . $windowsCopy . '\\ext" %*',
        'expanding the launcher\'s line must name the copy and its own ext directory: ' . $expanded,
    );

    $callerArguments = [
        'app.phar',
        '--flag=a b',
        'a&b',
        '(paren)',
        '!bang!',
        '100% real',
        'dir with space/file.phar',
    ];
    $result = runProcess(
        [$php, '-d', 'extension_dir=' . $extensions, ...$callerArguments],
        $work,
        ['PHPRC' => $copy, 'PHP_INI_SCAN_DIR' => ''],
    );
    $check(
        $result['exitCode'] === 0,
        'the launcher\'s command line must reach the runtime: ' . \trim($result['stdout'] . "\n" . $result['stderr']),
    );

    $seen = ['ARG' => [], 'PHP' => '', 'PHPRC' => ''];
    foreach (\explode("\n", \trim($result['stdout'])) as $line) {
        [$key, $value] = \array_pad(\explode("\t", $line, 2), 2, '');
        if ($key === 'ARG') {
            $seen['ARG'][] = $value;
        } elseif (\array_key_exists($key, $seen)) {
            $seen[$key] = $value;
        }
    }
    $same($php, $seen['PHP'], 'the binary must be reached at the copy\'s own path, `&`, `%` and `!` included');
    $same(
        $copy,
        $seen['PHPRC'],
        'PHPRC must be the copy path exactly, with nothing expanded, split or truncated',
    );
    $same(
        ['-d', 'extension_dir=' . $extensions, ...$callerArguments],
        $seen['ARG'],
        'argv must arrive with the same elements, in order, with the caller\'s boundaries kept',
    );

    $mirrored = mirrorPortableRuntime($copy, $work . '/out dir (x) & %TEMP% !bang!/', true);
    $check(
        $mirrored === $work . '/out dir (x) & %TEMP% !bang!',
        'a trailing separator on --out must not become part of the path: ' . $mirrored,
    );
    $check(
        \is_file($mirrored . '/php.exe') && \is_file($mirrored . '/bin/php.cmd'),
        'the mirrored copy must be the whole tree',
    );

    $cwd = $work . '/cwd';
    \mkdir($cwd, 0777, true);
    $previous = (string) \getcwd();
    \chdir($cwd);
    try {
        foreach (
            [
                [$cwd, 'the directory it runs in'],
                [$copy, 'the runtime being copied'],
                ['/', 'a filesystem root'],
                ['', 'an empty path'],
            ] as [$target, $what]
        ) {
            $refused = false;
            try {
                mirrorPortableRuntime($copy, $target, true);
            } catch (\RuntimeException) {
                $refused = true;
            }
            $check($refused, "--out must refuse {$what}: what is there would be deleted");
        }
        $check(
            \is_dir($cwd) && \is_dir($copy),
            'a refused --out must leave every directory it named alone',
        );
    } finally {
        \chdir($previous);
    }
} finally {
    removeSourceTree($work);
}

echo "portable Windows runtime tests passed ({$checks} checks)\n";
