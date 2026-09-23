<?php declare(strict_types=1);

namespace Moggi\Foreign;

/** @return 'global'|'constructor'|'static'|'instance'|'intrinsic' */
function foreignPathDispatch(string $path): string
{
    if (!str_contains($path, ':') && !str_contains($path, '.')) {
        return 'global';
    }

    if (str_contains($path, ':')) {
        $member = substr($path, strrpos($path, ':') + 1);

        return match ($member) {
            '__con' => 'constructor',
            '__cast', '__default', '__null' => 'intrinsic',
            default => 'static',
        };
    }

    return 'instance';
}

/**
 * Split a host path into its class and member parts.
 *
 * A run of `:` is one separator, so PHP's own `Class::method` spelling lands on
 * the same class/member as `Class:method`. `Class.member` is the instance form.
 *
 * @return ?array{0: string, 1: string} [classPath, member], or null for a bare host name
 */
function splitForeignPathMember(string $path): ?array
{
    $colon = strrpos($path, ':');
    if ($colon !== false) {
        $end = $colon;
        while ($end > 0 && $path[$end - 1] === ':') {
            $end--;
        }

        return [substr($path, 0, $end), substr($path, $colon + 1)];
    }

    $dot = strrpos($path, '.');
    if ($dot === false) {
        return null;
    }

    return [substr($path, 0, $dot), substr($path, $dot + 1)];
}

/**
 * @return array{classPath: ?string, member: ?string, dispatch: string, path: string}
 */
function parseForeignPath(string $path): array
{
    $dispatch = foreignPathDispatch($path);
    $parts = splitForeignPathMember($path);

    if ($parts === null) {
        return ['classPath' => null, 'member' => null, 'dispatch' => 'global', 'path' => $path];
    }

    [$classPath, $member] = $parts;

    return ['classPath' => $classPath, 'member' => $member, 'dispatch' => $dispatch, 'path' => $path];
}

/** Map dotted class path to PHP FQN with backslashes. */
function phpClassFqn(string $classPath): string
{
    return \str_replace('.', '\\', $classPath);
}
