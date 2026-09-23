<?php declare(strict_types=1);

/**
 * Helpers for the distribution tests (`tests/distribution/`).
 *
 * Both of them have to find a C compiler and to control `PATH` precisely, and
 * both need that without invoking a shell: what they assert is what the launcher
 * and the assembler do, and a shell in the middle would blur it.
 */

/** Resolve an executable the way a shell would: an explicit path, or a name on `PATH`. */
function distributionFindExecutable(string $name): ?string
{
    if (\str_contains($name, '/') || \str_contains($name, '\\')) {
        return \is_file($name) ? $name : null;
    }

    $suffixes = \PHP_OS_FAMILY === 'Windows' ? ['', '.exe', '.cmd', '.bat'] : [''];
    foreach (\explode(\PATH_SEPARATOR, (string) \getenv('PATH')) as $directory) {
        if ($directory === '') {
            continue;
        }
        foreach ($suffixes as $suffix) {
            $candidate = $directory . \DIRECTORY_SEPARATOR . $name . $suffix;
            if (\is_file($candidate) && (\PHP_OS_FAMILY === 'Windows' || \is_executable($candidate))) {
                return $candidate;
            }
        }
    }

    return null;
}

/** The compiler a distribution is built with: Clang, or the best available stand-in. */
function distributionCompiler(): ?string
{
    foreach (['clang', 'cc', 'gcc'] as $candidate) {
        $found = distributionFindExecutable($candidate);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/** A `PATH` holding PHP and nothing else, so "the backend is missing" means it. */
function distributionPhpOnlyPath(): string
{
    return \dirname(\PHP_BINARY);
}

/**
 * The host environment with `$overrides` applied.
 *
 * Windows spells a variable's name case-insensitively and calls this one `Path`, so an override keyed
 * `PATH` would sit beside the host's own entry and leave the child free to read either one.
 *
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function distributionEnvironment(array $overrides): array
{
    $names = \array_map('strtoupper', \array_keys($overrides));
    $environment = [];
    foreach (\getenv() ?: [] as $name => $value) {
        if (!\in_array(\strtoupper((string) $name), $names, true)) {
            $environment[$name] = $value;
        }
    }

    return $overrides + $environment;
}
