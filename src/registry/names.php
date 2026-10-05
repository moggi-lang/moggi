<?php declare(strict_types=1);

namespace Moggi\Registry;

/**
 * What a package name is allowed to be.
 *
 * A name is not decoration: it is a lock key, a catalog shard key, a lock file's
 * key, and — on the client — a path segment (`packages/<name>/<digest>`,
 * `packages/<name>.json`). A name that could contain `/` or `..` therefore is not
 * a naming question but a path-traversal question, and the registry is untrusted
 * input. One predicate, used at every boundary the name crosses, keeps the answer
 * the same in all of them.
 *
 * The shape is deliberately small: lowercase ASCII letters, digits, and `.`, `_`
 * or `-` *between* alphanumerics. So the name is portable across case-insensitive
 * filesystems (no two names differ only by case), cannot begin or end with a
 * separator, cannot be `.` or `..`, and has no character a shell, a URL or an INI
 * value would treat specially.
 */

/** `a`, `json`, `data.json`, `my-pkg`, `x_y_z` — but not `.`, `..`, `A`, `a/`, `-a`. */
const PACKAGE_NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/';

/** Whether `$name` is a package name. */
function isPackageName(string $name): bool
{
    return \preg_match(PACKAGE_NAME_PATTERN, $name) === 1;
}

/**
 * Refuse a name that is not a package name, naming where it came from.
 *
 * Throwing rather than sanitizing is the point: a name we did not expect is a
 * name we must not turn into a path, and a caller that can name the source turns
 * "unknown package" into a sentence a human can act on.
 */
function assertPackageName(string $name, string $where): void
{
    if (!isPackageName($name)) {
        throw new \RuntimeException(
            "{$where}: `{$name}` is not a package name — "
            . 'a name is lowercase, starts and ends with a letter or digit, and may contain . _ - only in between',
        );
    }
}

/** The sentence for a name that is not a package name, without throwing. */
function packageNameProblem(string $name): ?string
{
    return isPackageName($name)
        ? null
        : "`{$name}` is not a package name (lowercase, starting and ending with a letter or digit, . _ - only in between)";
}
