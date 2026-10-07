<?php declare(strict_types=1);

namespace Moggi\CLI\Commands;

use Moggi\Cache;

/**
 * One command's command line, declared in one place.
 *
 * Every command used to hand-roll the same three steps —
 * scan argv for `-h`, parse its own flags, print its own usage on a mistake — with
 * its own copy of each, and the one shared parser they did have grew a
 * `$booleans`/`$valued` pair per call site. Declaring the interface here instead
 * lets `parseArgs()`, `wantsHelp()` and `commandError()` be the only
 * implementations of those steps, so a command cannot accept a flag its help does
 * not name or name one it does not accept. `$help` stays authored prose: help
 * text should read like writing, not like an option table.
 */
final class CommandSpec
{
    /**
     * @param string $name the subcommand word, as `main()` spells it
     * @param string $help the command's help, without the trailing newline
     * @param list<array{name: string, value?: bool, repeat?: bool}> $options
     *   the flags the command accepts; `value: true` means `--name VALUE`, a
     *   missing `value` means a boolean, and `repeat: true` collects a list. The
     *   name is the key it lands under, so a kebab flag a command reads as
     *   `nsec-file` is declared `nsec-file`.
     * @param bool $verb whether the command takes a leading verb
     *   (`moggi takeover request …`), read from the word before the positionals.
     * @param int $positionals how many positionals the command accepts.
     * @param bool $passthrough whether `--` hands the rest of the line over
     *   verbatim (a program's own arguments), collected under `rest`.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly array $options,
        public readonly bool $verb = false,
        public readonly int $positionals = 1,
        public readonly bool $passthrough = false,
    ) {
    }
}

/**
 * Parse a command line against a command's spec.
 *
 * The one implementation of the rules every command shares: `--flag`,
 * `--flag value` and `--flag=value`; the `-r`/`-o` short spellings and the kebab
 * forms that have a camel key; positionals in the order written, at most one path.
 * A word without a leading `-` is never a flag, so a package named `json` is a name
 * rather than `--json`. Anything unrecognised is an error rather than ignored,
 * because a mistyped `--registy` resolving against the wrong registry is exactly
 * the mistake that should not be quiet.
 *
 * `$start` is where the arguments begin: 2 for `moggi show …`, 3 for a command
 * that takes a verb (`moggi takeover request …`), whose verb then arrives under
 * `verb`.
 *
 * @param list<string> $argv
 * @return array<string, mixed>
 */
function parseArgs(array $argv, CommandSpec $spec, int $start = 2): array
{
    $booleans = [];
    $valued = [];
    $repeatable = [];
    foreach ($spec->options as $option) {
        if (($option['value'] ?? false) === true) {
            $valued[] = $option['name'];
            if (($option['repeat'] ?? false) === true) {
                $repeatable[] = $option['name'];
            }
        } else {
            $booleans[] = $option['name'];
        }
    }

    $options = [
        'path' => '.',
        'registry' => (\getenv('MOGGI_REGISTRY') ?: DEFAULT_REGISTRY),
        'output' => null,
        'noCache' => false,
        'positionals' => [],
        'rest' => [],
    ];
    foreach ($booleans as $flag) {
        $options[$flag] = false;
    }
    foreach ($valued as $flag) {
        $options[$flag] = \in_array($flag, $repeatable, true) ? [] : null;
    }
    if ($spec->verb) {
        $options['verb'] = $argv[$start - 1] ?? '';
    }

    $positional = [];
    for ($i = $start, $n = \count($argv); $i < $n; $i++) {
        $argument = $argv[$i];
        if ($spec->passthrough && $argument === '--') {
            $options['rest'] = \array_slice($argv, $i + 1);
            break;
        }
        $flag = \str_starts_with($argument, '-');
        $value = null;
        if ($flag && \str_contains($argument, '=')) {
            [$argument, $value] = \explode('=', $argument, 2);
        }
        $name = \ltrim($argument, '-');
        $short = match ($name) {
            'r' => 'registry',
            'o' => 'output',
            'no-cache' => 'noCache',
            'no-docs' => 'noDocs',
            'dry-run' => 'dryRun',
            'allow-bad' => 'allowBad',
            default => $name,
        };

        if ($flag && ($short === 'registry' || $short === 'output' || \in_array($short, $valued, true))) {
            $value ??= $argv[++$i] ?? throw new \InvalidArgumentException("{$argument} needs a value");
            if (\in_array($short, $repeatable, true)) {
                $options[$short][] = $value;
            } else {
                $options[$short] = $value;
            }
            continue;
        }
        if ($flag && \in_array($short, $booleans, true)) {
            $options[$short] = true;
            continue;
        }
        if ($flag) {
            throw new \InvalidArgumentException("unknown option `{$argument}`");
        }
        $positional[] = $argument;
    }

    if (\count($positional) > $spec->positionals) {
        throw new \InvalidArgumentException('expected at most '
            . ($spec->positionals === 1 ? 'one' : (string) $spec->positionals)
            . ' path' . ($spec->positionals === 1 ? '' : 's') . ', got: ' . \implode(' ', $positional));
    }
    $options['positionals'] = $positional;
    if ($positional !== []) {
        $options['path'] = $positional[0];
    }

    if ($options['noCache']) {
        Cache\setCacheEnabled(false);
    }

    return $options;
}

/**
 * Whether the command line asks for help, wherever the `-h` sits.
 *
 * @param list<string> $argv
 */
function wantsHelp(array $argv): bool
{
    foreach (\array_slice($argv, 2) as $argument) {
        if ($argument === 'help' || $argument === '--help' || $argument === '-h') {
            return true;
        }
    }

    return false;
}

/** A command's help, ready to print. */
function commandHelp(CommandSpec $spec): string
{
    return $spec->help . "\n";
}

/**
 * Every path a repeatable directory flag collected must be a directory.
 *
 * The parser cannot know that `--lib` names a directory and `--backend` names an
 * enum, so the command says so once, here, and a bad `--lib` fails the same way
 * wherever it is used.
 *
 * @param list<string> $dirs
 */
function requireDirectories(array $dirs, string $flag): void
{
    foreach ($dirs as $dir) {
        if (!\is_dir($dir)) {
            throw new \InvalidArgumentException("{$flag} requires a directory" . ($dir !== '' ? ": {$dir}" : ''));
        }
    }
}

/**
 * Report a malformed command line the one way every command does: the reason and
 * the help, on stderr, with a failing status.
 */
function commandError(CommandSpec $spec, \InvalidArgumentException $error): int
{
    \fwrite(STDERR, 'error: ' . $error->getMessage() . "\n\n" . $spec->help . "\n");

    return 1;
}
