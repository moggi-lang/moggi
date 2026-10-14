#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

// `--native` on the PHP backend appends the packaged PHAR to a micro PHP runtime
// (see `src/backend/php/native.php`). Two of its contracts are read by a foreign
// SAPI rather than by this compiler, so no run of the compiler can verify them:
//
//   - the file format `[micro.sfx][INI object][payload]`
//   - the app PHAR's stub closing with the canonical `__HALT_COMPILER();`, which
//     is what makes a micro SAPI register an appended PHAR at all (a PHP CLI
//     accepts any spelling of the token; a micro SAPI does not)
//
// A real runtime is not needed for either: the runtime is opaque bytes here, and
// a PHAR's stub is written by `packagePhar`, so both are asserted directly. The
// end-to-end path — a real runtime, a real program, its stdout — is the
// `--native` example smoke in the suite and the assembler's variant smoke.

require_once $root . '/src/compiler.php';

use function Moggi\Backend\Php\buildPhpNativeExecutable;
use function Moggi\Backend\Php\buildPharStub;
use function Moggi\Backend\Php\combineMicroPhar;
use function Moggi\Backend\Php\microIniObject;
use function Moggi\Backend\Php\microSfxAt;
use function Moggi\Backend\Php\packagePhar;
use function Moggi\Backend\Php\resolveMicroSfx;
use function Moggi\Paths\canonicalSeparators;
use const Moggi\Backend\Php\MICRO_INI_MAGIC;
use const Moggi\Backend\Php\MICRO_SFX_ENV;

$failures = [];
$checks = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
};

/**
 * Path identity rather than path spelling: the test builds its paths with `/`
 * while `microSfxAt` joins with the host's separator, so on Windows the same
 * file has two spellings and only the canonical one compares equal.
 */
$assertSamePath = static function (string $expected, string $actual, string $message) use ($assertSame): void {
    $assertSame(canonicalSeparators($expected), canonicalSeparators($actual), $message);
};

$scratch = \sys_get_temp_dir() . '/moggi-native-' . getmypid() . '-' . bin2hex(random_bytes(4));
\mkdir($scratch, 0777, true);

$previousSfx = \getenv(MICRO_SFX_ENV);
$previousRoot = \getenv('MOGGI_ROOT');

try {
    // 1. The INI injection object: the SAPI reads its configuration from between
    //    the runtime and the program, which is the only place a directive that
    //    cannot change once a process runs (`phar.readonly`) can come from.

    $assertSame('', microIniObject([]), 'no directives must produce no object');

    $directive = 'phar.readonly=0';
    $assertSame(
        MICRO_INI_MAGIC . \pack('N', \strlen($directive)) . $directive,
        microIniObject(['phar.readonly' => 0]),
        'an integer directive must be `name=value` behind the magic and a big-endian length',
    );

    $string = 'date.timezone="UTC"';
    $assertSame(
        MICRO_INI_MAGIC . \pack('N', \strlen($string)) . $string,
        microIniObject(['date.timezone' => 'UTC']),
        'a string directive must be quoted',
    );

    $two = microIniObject(['a' => 1, 'b' => 'x']);
    $assertSame(
        MICRO_INI_MAGIC . \pack('N', \strlen("a=1\nb=\"x\"")) . "a=1\nb=\"x\"",
        $two,
        'directives must be newline-separated, in insertion order',
    );
    $assertSame(\strlen("a=1\nb=\"x\""), \unpack('N', \substr($two, 4, 4))[1], 'the length must count the text, not the object');

    // 2. The concatenation, which is the whole native build.

    $sfx = $scratch . '/micro.sfx';
    $payload = $scratch . '/app.phar';
    \file_put_contents($sfx, 'SFX-BYTES');
    \file_put_contents($payload, 'PAYLOAD');

    $binary = $scratch . '/app';
    combineMicroPhar($sfx, $payload, $binary, ['phar.readonly' => 0]);
    $assertSame(
        'SFX-BYTES' . microIniObject(['phar.readonly' => 0]) . 'PAYLOAD',
        \file_get_contents($binary),
        'the runtime, the INI object and the payload must be concatenated in that order',
    );

    $bare = $scratch . '/app-bare';
    combineMicroPhar($sfx, $payload, $bare);
    $assertSame('SFX-BYTESPAYLOAD', \file_get_contents($bare), 'no directives must add no bytes');
    if (\PHP_OS_FAMILY !== 'Windows') {
        $assertTrue(\is_executable($binary), 'the built executable must be executable');
    }

    foreach ([[$scratch . '/absent', $payload, 'missing micro runtime'], [$sfx, $scratch . '/absent', 'missing program']] as [$from, $to, $wanted]) {
        try {
            combineMicroPhar($from, $to, $scratch . '/never');
            $assertTrue(false, "combineMicroPhar must refuse a missing input ({$wanted})");
        } catch (\RuntimeException $e) {
            $assertTrue(\str_contains($e->getMessage(), $wanted), "the error must say `{$wanted}`: " . $e->getMessage());
        }
    }
    $assertTrue(!\is_file($scratch . '/never'), 'a refused build must write nothing');

    // 3. Resolving the runtime: a file, or a directory holding `micro.sfx`.

    $assertSamePath($sfx, microSfxAt($sfx), 'a file is the runtime');
    $assertSamePath($sfx, microSfxAt($scratch), 'a directory resolves to the micro.sfx inside it');
    $assertSamePath($sfx, microSfxAt($scratch . '/'), 'a trailing separator must not matter');
    $assertSame(null, microSfxAt($scratch . '/nothing'), 'a missing path resolves to nothing');
    $assertSamePath($payload, microSfxAt($payload), 'any named file is the runtime, whatever it is called');
    $bareDir = $scratch . '/no-runtime';
    \mkdir($bareDir, 0777, true);
    $assertSame(null, microSfxAt($bareDir), 'a directory without a micro.sfx resolves to nothing');

    \putenv(MICRO_SFX_ENV . '=' . $scratch);
    $assertSamePath($sfx, resolveMicroSfx(), 'the environment may name a directory');
    \putenv(MICRO_SFX_ENV . '=' . $payload);
    $assertSamePath($payload, resolveMicroSfx(), 'the environment may name a file');
    \putenv(MICRO_SFX_ENV . '=');
    \putenv('MOGGI_ROOT=');
    $assertSame(null, resolveMicroSfx(), 'a checkout with nothing bundled and nothing named has no runtime');

    // 4. The build step: the archive beside the package, the executable beside it.

    $outputRoot = $scratch . '/build';
    \mkdir($outputRoot, 0777, true);
    \copy($payload, $outputRoot . '/moggi-app.phar');

    \putenv(MICRO_SFX_ENV . '=' . $sfx);
    $assertTrue(
        buildPhpNativeExecutable($outputRoot, 'moggi-app.phar', 'moggi-app'),
        'a named runtime must build',
    );
    $assertSame(
        'SFX-BYTESPAYLOAD',
        \file_get_contents($outputRoot . '/moggi-app'),
        'the executable is the runtime with the package appended',
    );
    $assertSame('PAYLOAD', \file_get_contents($outputRoot . '/moggi-app.phar'), 'the package must survive the build');

    $emptyRoot = $scratch . '/empty';
    \mkdir($emptyRoot, 0777, true);
    $assertTrue(
        !buildPhpNativeExecutable($emptyRoot, 'moggi-app.phar', 'moggi-app'),
        'a missing package must fail instead of writing a half build',
    );
    \putenv(MICRO_SFX_ENV . '=');
    $assertTrue(
        !buildPhpNativeExecutable($outputRoot, 'moggi-app.phar', 'moggi-app-2'),
        'a missing runtime must fail',
    );
    $assertTrue(!\is_file($outputRoot . '/moggi-app-2'), 'a failed native build must write nothing');

    // 5. The stub marker. `packagePhar` runs for real (in a second process when
    //    this one may not write archives), so this is the shipped artifact's
    //    bytes, not a template.

    $assertTrue(
        \str_contains(buildPharStub(), '__HALT_COMPILER();'),
        'the stub template must close with the canonical token',
    );
    $assertTrue(
        !\str_contains(buildPharStub(), '__halt_compiler'),
        'the stub template must not spell the token in another case',
    );

    $appRoot = $scratch . '/phar-app';
    \mkdir($appRoot, 0777, true);
    \file_put_contents($appRoot . '/_runtime.php', "<?php\n");
    \file_put_contents(
        $appRoot . '/Main.php',
        "<?php\n\\Moggi\\reportUncaught(\$__moggiUncaught);\n",
    );
    $pharPath = $scratch . '/packaged.phar';
    packagePhar($appRoot, $pharPath, []);

    $raw = (string) \file_get_contents($pharPath);
    $assertTrue(\str_contains($raw, '__HALT_COMPILER();'), 'the packaged stub must carry the canonical token');
    $assertTrue(
        !\str_contains($raw, '__halt_compiler();'),
        'the packaged stub must not carry a lowercase token: a micro runtime registers no such PHAR',
    );
    $assertTrue(\str_ends_with($raw, "\x47\x42\x4d\x42"), 'the packaged PHAR must end with its signature marker');

    $packaged = new \Phar($pharPath);
    $assertTrue(isset($packaged['Main.php']), 'the packaged PHAR must be a readable archive');
    unset($packaged);
} finally {
    \putenv(MICRO_SFX_ENV . '=' . ($previousSfx === false ? '' : $previousSfx));
    \putenv('MOGGI_ROOT=' . ($previousRoot === false ? '' : $previousRoot));
    if (\is_dir($scratch)) {
        $remove = static function (string $dir) use (&$remove): void {
            foreach (\scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (\is_dir($path) && !\is_link($path)) {
                    $remove($path);
                } else {
                    @\unlink($path);
                }
            }
            @\rmdir($dir);
        };
        $remove($scratch);
    }
}

if ($failures !== []) {
    \fwrite(STDERR, 'php native test FAILED: ' . \count($failures) . "\n");
    foreach ($failures as $failure) {
        \fwrite(STDERR, "  - {$failure}\n");
    }

    exit(1);
}

echo "php native tests passed ({$checks} assertions)\n";
