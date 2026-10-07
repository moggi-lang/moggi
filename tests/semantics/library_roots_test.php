#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Semantics\Types\TypeError;

use function Moggi\Modules\libraryModuleIndex;
use function Moggi\Modules\projectSourceClosure;
use function Moggi\Paths\canonicalSeparators;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$work = \sys_get_temp_dir() . '/moggi-library-roots-' . \bin2hex(\random_bytes(6));
$dirs = ['a' => $work . '/a', 'b' => $work . '/b'];
foreach ($dirs as $dir) {
    \mkdir($dir, 0777, true);
}
$write = static function (string $dir, string $module): void {
    \file_put_contents($dir . '/' . $module . '.mog', "module {$module} where\n");
};

try {
    $write($dirs['a'], 'Alpha');
    $write($dirs['b'], 'Alpha');

    $threw = false;
    try {
        libraryModuleIndex([$dirs['a'], $dirs['b']]);
    } catch (TypeError $error) {
        $threw = true;
        $assert(
            \str_contains($error->getMessage(), 'duplicate module `Alpha`'),
            'the refusal names the duplicated module, got: ' . $error->getMessage(),
        );
        // The message spells both roots the way every artifact path is spelled,
        // so the expectation is canonicalised too rather than host-native.
        $roots = \array_map(canonicalSeparators(...), $dirs);
        $assert(
            \str_contains($error->getMessage(), $roots['a']) && \str_contains($error->getMessage(), $roots['b']),
            'the refusal names both roots, got: ' . $error->getMessage(),
        );
    }
    $assert($threw, 'two roots offering one module must be refused');

    $threw = false;
    try {
        projectSourceClosure([], [$dirs['a'], $dirs['b']]);
    } catch (TypeError $error) {
        $threw = \str_contains($error->getMessage(), 'duplicate module `Alpha`');
    }
    $assert($threw, 'projectSourceClosure must refuse the clash');

    \unlink($dirs['b'] . '/Alpha.mog');
    $write($dirs['b'], 'Beta');
    $index = libraryModuleIndex([$dirs['a'], $dirs['b']]);
    $assert(isset($index['Alpha'], $index['Beta']), 'distinct roots each contribute their modules');

    $index = libraryModuleIndex([$dirs['a'], $dirs['a']]);
    $assert(\count($index) === 1 && isset($index['Alpha']), 'a root repeated in the list is deduplicated, not refused');

    echo "library root tests passed\n";
} finally {
    foreach ($dirs as $dir) {
        foreach (\glob($dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($dir);
    }
    \rmdir($work);
}
