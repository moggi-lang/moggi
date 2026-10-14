<?php declare(strict_types=1);

namespace Acme\Hello;

final class Greeter
{
    public const VERSION = '1.0.0';

    public static function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }

    public static function greetOrNull(string $name): ?string
    {
        return $name === '' ? null : 'Hello, ' . $name . '!';
    }
}
