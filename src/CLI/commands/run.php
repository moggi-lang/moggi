<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use function Moggi\CLI\parseBackendValue;
use function Moggi\CLI\printUsage;

/**
 * `moggi run` — compile and execute, forwarding the program its own arguments.
 *
 * A `--` ends the compiler's options: everything after it belongs to the app, so
 * `moggi run app -- --flag for-the-app` hands `--flag for-the-app` to the program
 * rather than reading it as more compiler flags.
 */
function runUsage(): string
{
    return <<<HELP
    usage:
      moggi run <source.mog|input-dir> [options] [-- <app args>]

    Compile the input and run it. Arguments after `--` go to the program itself.

    options:
      --backend B      compile target: php, jvm, dotnet (default: php)
      -o PATH          keep the built tree here instead of a temp directory
      --lib PATH       extra module search root. Repeatable.
      --native         build a native executable and run that
      --no-opt         skip optimizations when generating code
      --no-strip       keep bindings unreachable from `main`
      --no-cache       bypass the on-disk compile cache for this run
      -h, --help       show this help
    HELP;
}

/** @param list<string> $argv */
function runRun(array $argv): int
{
    $spec = new CommandSpec('run', runUsage(), [
        ['name' => 'noOpt'],
        ['name' => 'native'],
        ['name' => 'noStrip'],
        ['name' => 'strip'],
        ['name' => 'noCache'],
        ['name' => 'backend', 'value' => true],
        ['name' => 'lib', 'value' => true, 'repeat' => true],
    ], positionals: 1, passthrough: true);

    if (wantsHelp($argv)) {
        echo commandHelp($spec);

        return 0;
    }

    try {
        $options = parseArgs($argv, $spec);
        requireDirectories($options['lib'], '--lib');
    } catch (\InvalidArgumentException $error) {
        return commandError($spec, $error);
    }

    $input = $options['positionals'][0] ?? null;
    if ($input === null) {
        \fwrite(STDERR, "error: run requires <source.mog|input-dir>\n\n");
        printUsage();

        return 1;
    }

    if (!\is_dir($input) && !\is_file($input)) {
        \fwrite(STDERR, "error: not a file or directory: {$input}\n");

        return 1;
    }

    $backend = parseBackendValue($options['backend']);
    $native = (bool) $options['native'];
    $keepOutput = $options['output'] !== null;
    $outputDir = $options['output'] ?? (sys_get_temp_dir() . '/moggi-run-' . getmypid());

    $built = compileIntoRoot(
        $input,
        $outputDir,
        !$options['noOpt'],
        $backend,
        !$options['noStrip'],
        $options['lib'],
        $native,
        $backend === 'php' && !$native,
    );

    if ($built['exitCode'] !== 0 || $built['outputRoot'] === null) {
        if (!$keepOutput) {
            removeOutputTree($outputDir);
        }

        return $built['exitCode'] !== 0 ? $built['exitCode'] : 1;
    }

    $code = executeBuiltApp(
        $built['outputRoot'],
        $built['backend'],
        $built['entryRelative'],
        $options['rest'],
        $native,
    );

    if (!$keepOutput) {
        removeOutputTree($built['outputRoot']);
    }

    return $code;
}
