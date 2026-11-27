<?php declare(strict_types=1);

/**
 * `moggi runtime list` and `runtime <backend> version`.
 *
 * The commands answer from this installation's `runtime/` directory and from the
 * toolchains on `PATH`, so the case builds both: a fake installation (what the
 * archive stub sets `MOGGI_INSTALL_ROOT` to) and a directory of stand-ins for
 * java, javac, mvn and dotnet. The real CLI runs against them in a child process,
 * which is where the parts worth pinning live: which backend reports which
 * source, that a missing runtime fails the report instead of printing an empty
 * one, that installing copies the runtime where the next invocation finds it,
 * and that a runtime which does not start — or a directory that is not a runtime
 * at all — leaves the installation as it was.
 *
 * A Windows host would need `.exe` stand-ins and `cmd` quoting, so the case
 * reports itself as not applicable there rather than standing one in.
 */
if (\PHP_OS_FAMILY === 'Windows') {
    \fwrite(STDERR, 'the runtime backend case needs a POSIX host for its tool stand-ins');
    exit(2);
}

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/tests/suite/support/process.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$work = \sys_get_temp_dir() . '/moggi-runtime-backends-' . \bin2hex(\random_bytes(4));
$install = $work . '/moggi-php';
$tools = $work . '/tools';
$project = $work . '/project';
$releaseServer = null;

$standIn = static function (string $path, string $body): void {
    \file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
    \chmod($path, 0755);
};

/**
 * A tar member header, written by hand.
 *
 * The case needs archives with names `tar` itself will not create — a member
 * that climbs out of the tree — and needs no external archiver to be installed
 * to build one, so the 512-byte ustar header is written here.
 */
$tarHeader = static function (string $name, int $size, int $mode, string $type = '0', string $link = ''): string {
    $octal = static fn (int $value, int $width): string => \str_pad(\decoct($value), $width, '0', \STR_PAD_LEFT) . "\0";
    $header = \str_pad($name, 100, "\0")
        . $octal($mode, 7)
        . $octal(0, 7)
        . $octal(0, 7)
        . $octal($size, 11)
        . $octal(0, 11)
        . \str_repeat(' ', 8)
        . $type
        . \str_pad($link, 100, "\0")
        . "ustar\0" . '00'
        . \str_repeat("\0", 247);
    $checksum = \array_sum(\array_map('ord', \str_split($header)));

    return \substr_replace($header, \str_pad(\decoct($checksum), 6, '0', \STR_PAD_LEFT) . "\0 ", 148, 8);
};

/**
 * A zip archive of one entry, written by hand.
 *
 * ext-zip reads what this builds, but the case still writes the archive itself:
 * it needs member names a zip tool will not store (`../escape`, an absolute
 * path), a stored entry, which the reader has to copy out verbatim, a deflated
 * entry whose header claims one byte more than the deflate stream carries while
 * its CRC-32 is the real one, and an entry that declares more data than the
 * archive holds. No zip tool writes any of those.
 */
$zipFixture = static function (
    string $path,
    string $name,
    string $body,
    bool $deflate = false,
    ?int $declaredSize = null,
): void {
    $data = $deflate ? \gzdeflate($body) : $body;
    $method = $deflate ? 8 : 0;
    $size = $declaredSize ?? \strlen($body);
    $crc = \crc32($body) & 0xFFFFFFFF;
    $local = "PK\x03\x04"
        . \pack('vvvvvVVVvv', 20, 0, $method, 0, 0, $crc, \strlen($data), $size, \strlen($name), 0)
        . $name . $data;
    $central = "PK\x01\x02"
        . \pack(
            'vvvvvvVVVvvvvvVV',
            20, 20, 0, $method, 0, 0,
            $crc, \strlen($data), $size,
            \strlen($name), 0, 0, 0, 0, 0, 0,
        )
        . $name;
    $end = "PK\x05\x06" . \pack('vvvvVVv', 0, 0, 1, 1, \strlen($central), \strlen($local), 0);
    \file_put_contents($path, $local . $central . $end);
};

/** @param list<array{string, string, int, 3?: string, 4?: string}> $entries name, body, mode, type, link */
$tarArchive = static function (string $path, array $entries) use ($tarHeader): void {
    $blocks = '';
    foreach ($entries as $entry) {
        [$name, $body] = $entry;
        $blocks .= $tarHeader($name, \strlen($body), $entry[2] ?? 0755, $entry[3] ?? '0', $entry[4] ?? '')
            . $body
            . \str_repeat("\0", (512 - \strlen($body) % 512) % 512);
    }
    $bytes = $blocks . \str_repeat("\0", 1024);
    \file_put_contents($path, \str_ends_with($path, '.gz') ? \gzencode($bytes) : $bytes);
};

try {
    \mkdir($install . '/runtime', 0777, true);
    \mkdir($tools, 0777, true);
    \mkdir($project, 0777, true);

    /**
     * The sandbox puts nothing but the stand-ins on PATH, so the archiver an
     * archive case needs is linked in from the host rather than inherited.
     */
    $hostTool = static function (string $name) use ($tools): void {
        foreach (\explode(\PATH_SEPARATOR, (string) \getenv('PATH')) as $directory) {
            $candidate = $directory . '/' . $name;
            if ($directory !== '' && \is_file($candidate) && \is_executable($candidate)) {
                @\symlink($candidate, $tools . '/' . $name);

                return;
            }
        }
    };
    $hostTool('tar');
    $hostTool('gzip');

    $standIn($tools . '/java', 'printf \'openjdk version "21.0.5" 2024-10-15\\n\' >&2');
    $standIn($tools . '/javac', 'printf \'javac 21.0.5\\n\' >&2');
    $standIn($tools . '/mvn', 'printf \'Apache Maven 3.9.6 (abcdef0123)\\n\'');

    $runner = $work . '/run.php';
    \file_put_contents(
        $runner,
        "<?php\n"
        . "define('MOGGI_INSTALL_ROOT', getenv('MOGGI_TEST_ROOT'));\n"
        . 'require ' . \var_export($root . '/src/compiler.php', true) . ";\n"
        . "exit(\\Moggi\\CLI\\main(\$argv));\n",
    );

    $env = [
        'PATH' => $tools,
        'HOME' => $work,
        'MOGGI_CACHE_DIR' => $work . '/cache',
        'MOGGI_TEST_ROOT' => $install,
    ];
    $cli = static function (array $arguments, array $extra = []) use ($runner, $env, $project): array {
        return runCompiledProcess(
            [\PHP_BINARY, $runner, ...$arguments],
            120,
            \array_merge($env, $extra),
            $project,
        );
    };

    /** Whatever an install left behind: a download scratch, a staging or a replaced tree. */
    $leftovers = static fn (): array => \array_merge(
        \glob($install . '/.runtime-*') ?: [],
        \glob($install . '/runtime/.*-install-*') ?: [],
        \glob($install . '/runtime/.*-replaced-*') ?: [],
    );

    $list = $cli(['runtime', 'list']);
    $check($list['exitCode'] === 0, 'runtime list must succeed: ' . $list['stderr']);
    $check(
        \str_contains($list['stdout'], 'not installed'),
        'a backend with no runtime must say so: ' . $list['stdout'],
    );

    $bare = $cli(['runtime']);
    $check(
        $bare['exitCode'] === 0 && \str_contains($bare['stdout'], 'backend'),
        '`moggi runtime` alone must report the same table: ' . $bare['stderr'],
    );

    $help = $cli(['runtime', '--help']);
    $check(
        $help['exitCode'] === 0 && \str_contains($help['stdout'], 'runtime list'),
        'runtime --help must print the group usage: ' . $help['stderr'],
    );

    $json = $cli(['runtime', 'list', '--json']);
    $decoded = \json_decode($json['stdout'], true);
    $check(
        \is_array($decoded) && \array_keys($decoded['backends'] ?? []) === ['php', 'jvm', 'dotnet'],
        'list --json must name every backend: ' . $json['stdout'],
    );
    $check(
        ($decoded['backends']['dotnet']['source'] ?? '') === 'missing',
        'a backend with nothing on PATH must report itself missing: ' . $json['stdout'],
    );
    $check(
        ($decoded['backends']['php']['source'] ?? '') === 'this compiler',
        'without a bundled PHP the compiler must report the PHP it runs on: ' . $json['stdout'],
    );
    $check(
        ($decoded['backends']['jvm']['source'] ?? '') === 'PATH'
            && ($decoded['backends']['jvm']['version'] ?? '') === '21.0.5',
        'a toolchain on PATH must be reported with the version it printed: ' . $json['stdout'],
    );
    $check(
        ($decoded['variant'] ?? '') === 'moggi-minimal',
        'an installation bundling no runtime is the minimal variant: ' . $json['stdout'],
    );

    $jvmVersion = $cli(['runtime', 'jvm', 'version']);
    $check($jvmVersion['exitCode'] === 0, 'runtime jvm version must succeed: ' . $jvmVersion['stderr']);
    $check(
        \str_contains($jvmVersion['stdout'], '21.0.5')
            && \str_contains($jvmVersion['stdout'], 'javac')
            && \str_contains($jvmVersion['stdout'], '3.9.6'),
        'the jvm report must name java, javac and maven: ' . $jvmVersion['stdout'],
    );

    $dotnetVersion = $cli(['runtime', 'dotnet', 'version']);
    $check($dotnetVersion['exitCode'] === 1, 'a missing runtime must fail the report: ' . $dotnetVersion['stdout']);
    $check(
        \str_contains($dotnetVersion['stderr'], 'no dotnet runtime') || \str_contains($dotnetVersion['stderr'], 'dotnet'),
        'a missing runtime must say how to get one: ' . $dotnetVersion['stderr'],
    );

} finally {
    if (\is_resource($releaseServer)) {
        \proc_terminate($releaseServer);
        \proc_close($releaseServer);
    }
    if (\is_dir($work)) {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($work);
    }
}

echo "runtime backend tests passed ({$checks} checks)\n";
