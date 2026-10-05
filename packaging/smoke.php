<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/runtimes.php';

use function Moggi\Compiler\executableName;
use function Moggi\Compiler\findExecutable;
use function Moggi\Compiler\targetExecutableName;

/**
 * Verify an assembled distribution by running it.
 *
 * Everything the assembler claims about a stage is only a claim until the stage
 * is executed: `bin/moggi` is run, the bundled host tools are run through their
 * own `bin/` entries, the examples are compiled and run on every backend the
 * variant bundles, and — under `--native-smoke` — a real native executable is
 * built with each shipped toolchain. The assembler stages files; this file runs
 * them, and fails loudly when a stage would not work for a user.
 */

/**
 * `--native-smoke` also builds one real native executable with each bundled
 * toolchain the variant ships (GraalVM's `native-image`, .NET's Native AOT), so
 * the archives a user gets are shown to produce natives, not only managed
 * artifacts. It is opt-in: it is minutes of work and needs a native linker, and
 * Windows reaches both toolchains through MSVC, which the check skips a host
 * without. The .NET executable is run in invariant-globalization mode so it does
 * not depend on a system ICU the build host is not required to have.
 *
 * @param list<string> $runtimes
 * @return list<string> what was checked, for the report
 */
function smokeTest(string $stage, string $target, array $runtimes, string $work, bool $nativeSmoke = false): array
{
    $compiler = $stage . '/bin/' . targetExecutableName('moggi', $target);
    if (!\is_file($compiler)) {
        throw new \RuntimeException("no compiler at {$compiler}");
    }

    removeTree($work);
    if (!\mkdir($work, 0777, true) && !\is_dir($work)) {
        throw new \RuntimeException("cannot create {$work}");
    }

    $env = \array_diff_key(\getenv(), ['MOGGI_ROOT' => '']);

    $checked = [];
    [$code, $stdout, $stderr] = runProcess([$compiler, 'version'], $work, $env);
    if ($code !== 0 || !\str_contains($stdout, 'compiler:')) {
        throw new \RuntimeException("`moggi version` failed ({$code}): " . \trim($stdout . ' ' . $stderr));
    }
    $checked[] = 'version';

    $schnorr = $stage . '/bin/' . targetExecutableName('schnorr', $target);
    [$code, $stdout, $stderr] = runProcess([$schnorr, 'generate'], $work, $env);
    if ($code !== 0 || !\str_starts_with(\trim($stdout), 'nsec1')) {
        throw new \RuntimeException(
            "the bundled schnorr CLI failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
        );
    }
    $checked[] = 'schnorr';

    if (\in_array('composer', $runtimes, true)) {
        $composer = $stage . '/runtime/composer/bin/' . (\str_starts_with($target, 'windows-') ? 'composer.bat' : 'composer');
        [$code, $stdout, $stderr] = runProcess([$composer, '--version'], $work, $env);
        if ($code !== 0 || !\str_contains($stdout, 'Composer version')) {
            throw new \RuntimeException(
                "the bundled composer failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
            );
        }
        $checked[] = 'composer';
    }

    if (\in_array('maven', $runtimes, true)) {
        $mavenEnv = $env;
        if (\is_dir($stage . '/runtime/jvm')) {
            $mavenEnv['JAVA_HOME'] = $stage . '/runtime/jvm';
        }
        $mvn = $stage . '/runtime/maven/bin/' . (\str_starts_with($target, 'windows-') ? 'mvn.cmd' : 'mvn');
        [$code, $stdout, $stderr] = runProcess([$mvn, '-version'], $work, $mavenEnv);
        if ($code !== 0 || !\str_contains($stdout, 'Apache Maven')) {
            throw new \RuntimeException(
                "the bundled maven failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
            );
        }
        $checked[] = 'maven';
    }

    $example = $stage . '/examples/factorial';
    foreach (['php', 'jvm', 'dotnet'] as $backend) {
        if ($backend !== 'php' && !\in_array($backend, $runtimes, true)) {
            continue;
        }
        $args = [$compiler, 'run', $example];
        if ($backend !== 'php') {
            $args[] = '--backend';
            $args[] = $backend;
        }
        [$code, $stdout, $stderr] = runProcess($args, $work, $env);
        if ($code !== 0 || !\str_contains($stdout, '3628800')) {
            throw new \RuntimeException(
                "running examples/factorial on {$backend} failed ({$code})\n"
                . \substr(\trim($stdout . "\n" . $stderr), -2000),
            );
        }
        $checked[] = 'run ' . $backend;
    }

    $artifact = $work . '/factorial.phar';
    [$code, $stdout, $stderr] = runProcess([$compiler, 'compile', $example, '-o', $artifact], $work, $env);
    if ($code !== 0 || !\is_file($artifact)) {
        throw new \RuntimeException(
            "packaging a PHAR failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
        );
    }

    [$code, $stdout, $stderr] = runProcess([\PHP_BINARY, $artifact], $work, $env);
    if ($code !== 0 || !\str_contains($stdout, '3628800')) {
        throw new \RuntimeException(
            "running the packaged PHAR failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
        );
    }
    $checked[] = 'compile phar';

    if (\in_array('php-native', $runtimes, true)) {
        $artifact = $work . '/factorial-native.phar';
        [$code, $stdout, $stderr] = runProcess(
            [$compiler, 'compile', $example, '--native', '-o', $artifact],
            $work,
            $env,
        );
        $binary = $work . '/' . executableName('factorial-native');
        if ($code !== 0 || !\is_file($binary)) {
            throw new \RuntimeException(
                "building a native executable failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
            );
        }

        [$code, $stdout, $stderr] = runProcess([$binary], $work, $env);
        if ($code !== 0 || !\str_contains($stdout, '3628800')) {
            throw new \RuntimeException(
                "running the native executable failed ({$code})\n" . \substr(\trim($stdout . "\n" . $stderr), -2000),
            );
        }
        $checked[] = 'compile native';
    }

    if ($nativeSmoke) {
        foreach (['jvm' => 'graalvm', 'dotnet' => 'dotnet'] as $backend => $runtime) {
            if (!\in_array($runtime, $runtimes, true)) {
                continue;
            }
            $blocker = nativeSmokeBlocker($target, $backend);
            if ($blocker !== null) {
                $checked[] = 'compile native ' . $backend . ' (skipped: ' . $blocker . ')';

                continue;
            }

            $artifact = $work . '/factorial-' . $backend . '-native';
            [$code, $stdout, $stderr] = runProcess(
                [$compiler, 'compile', $example, '--backend', $backend, '--native', '-o', $artifact],
                $work,
                $env,
            );
            $binary = $work . '/' . executableName('factorial-' . $backend . '-native');
            if ($code !== 0 || !\is_file($binary)) {
                throw new \RuntimeException(
                    "building a native {$backend} executable failed ({$code})\n"
                    . \substr(\trim($stdout . "\n" . $stderr), -2000),
                );
            }

            $runEnv = $backend === 'dotnet'
                ? ['DOTNET_SYSTEM_GLOBALIZATION_INVARIANT' => '1'] + $env
                : $env;
            [$code, $stdout, $stderr] = runProcess([$binary], $work, $runEnv);
            if ($code !== 0 || !\str_contains($stdout, '3628800')) {
                throw new \RuntimeException(
                    "running the native {$backend} executable failed ({$code})\n"
                    . \substr(\trim($stdout . "\n" . $stderr), -2000),
                );
            }
            $checked[] = 'compile native ' . $backend;
        }
    }

    removeTree($work);

    return $checked;
}

/**
 * The platform C compiler a native build hands its result to, or null.
 *
 * GraalVM's `native-image` and .NET's Native AOT both put the executable
 * together with one: Clang or GCC on Linux and macOS, and MSVC's `cl.exe` on
 * Windows.
 */
function nativeLinker(string $target): ?string
{
    $candidates = \str_starts_with($target, 'windows-') ? ['cl'] : ['clang', 'cc', 'gcc'];
    foreach ($candidates as $candidate) {
        if (findExecutable($candidate) !== null) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Why a native build of this backend would fail on this build host, or null
 * when it can go ahead — the reason the smoke reports instead of failing.
 *
 * Two things stand in the way, and neither is a property of the archives being
 * assembled. The first is the platform C compiler both toolchains link with.
 * The second is zlib's development files, which GraalVM's `native-image` asks
 * the Linux linker for and which a host that builds software does not
 * necessarily have — GraalVM documents them as a prerequisite, and a host
 * without them fails the link rather than the build. Asking the compiler to
 * resolve `-lz` before anything is built keeps a missing prerequisite from
 * turning a local assembly into a linker error.
 */
function nativeSmokeBlocker(string $target, string $backend): ?string
{
    $linker = nativeLinker($target);
    if ($linker === null) {
        return 'no native linker';
    }
    if ($backend === 'jvm' && \str_starts_with($target, 'linux-') && !linkerResolvesZlib($linker)) {
        return 'no zlib development files';
    }

    return null;
}

/** Whether the compiler can resolve `-lz`, which is what native-image needs it to do. */
function linkerResolvesZlib(string $linker): bool
{
    $dir = \sys_get_temp_dir() . '/moggi-zlib-probe-' . \getmypid();
    if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
        return false;
    }

    $source = $dir . '/probe.c';
    \file_put_contents($source, "int main(void) { return 0; }\n");
    [$code] = runProcess([$linker, $source, '-lz', '-o', $dir . '/probe']);
    removeTree($dir);

    return $code === 0;
}
