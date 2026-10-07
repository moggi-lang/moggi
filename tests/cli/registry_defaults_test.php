#!/usr/bin/env php
<?php declare(strict_types=1);

// Reads and writes are two origins: the tree is static and is served from the
// read host, while the API that accepts a signed write answers on its own host.
// The signature binds the host, so a write sent to the read origin is refused
// rather than redirected — which makes the default each verb resolves a
// correctness question, not a convenience.

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\CLI\Commands\CommandSpec;
use function Moggi\CLI\Commands\parseArgs;
use const Moggi\CLI\Commands\DEFAULT_REGISTRY;
use const Moggi\CLI\Commands\DEFAULT_REGISTRY_WRITE;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$readLine = ['moggi', 'show'];
$writeLine = ['moggi', 'publish'];
$read = static fn (): string => (string) parseArgs($readLine, new CommandSpec('show', '', []))['registry'];
$write = static fn (): string => (string) parseArgs($writeLine, new CommandSpec('publish', '', [], write: true))['registry'];

$before = [
    'write' => \getenv('MOGGI_REGISTRY_WRITE'),
    'read' => \getenv('MOGGI_REGISTRY'),
];
try {
    $assert(DEFAULT_REGISTRY_WRITE !== DEFAULT_REGISTRY, 'the two origins must not be one host');

    \putenv('MOGGI_REGISTRY_WRITE');
    \putenv('MOGGI_REGISTRY');
    $assert($read() === DEFAULT_REGISTRY, 'a read falls back to the read origin');
    $assert($write() === DEFAULT_REGISTRY_WRITE, 'a write falls back to the write origin, not the read tree');
    $assert($read() !== $write(), 'the two defaults must differ');

    \putenv('MOGGI_REGISTRY=https://registry.example');
    $assert($read() === 'https://registry.example', 'MOGGI_REGISTRY still selects the read origin');
    $assert(
        $write() === 'https://registry.example',
        'a machine pointed at another registry means both halves, so a write follows MOGGI_REGISTRY',
    );

    \putenv('MOGGI_REGISTRY_WRITE=https://writer.example');
    $assert($write() === 'https://writer.example', 'MOGGI_REGISTRY_WRITE selects the write origin');
    $assert($read() === 'https://registry.example', 'the write variable does not move the read origin');

    \putenv('MOGGI_REGISTRY_WRITE=');
    \putenv('MOGGI_REGISTRY');
    \putenv('MOGGI_REGISTRY_WRITE=https://writer.example');
    $assert($write() === 'https://writer.example', 'an empty write variable falls through to the write default');
    $assert($read() === DEFAULT_REGISTRY, 'an empty write variable does not disturb the read origin');

    echo "registry default tests passed ({$checks} checks)\n";
} finally {
    foreach ($before as $name => $value) {
        \putenv(\sprintf('%s=%s', $name === 'write' ? 'MOGGI_REGISTRY_WRITE' : 'MOGGI_REGISTRY', (string) $value));
    }
}
