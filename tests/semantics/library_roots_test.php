#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Semantics\Types\TypeError;

use function Moggi\Modules\libraryModuleScan;
use function Moggi\Modules\projectSourceClosure;
use function Moggi\Paths\canonicalPath;
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

    // Two roots may both hold the name: an installed tree can carry two packages
    // that each ship a module of the same name, and no index can decide between
    // them. The scan keeps the first as the name's entry and records the rest.
    [$paths, $duplicates] = libraryModuleScan([$dirs['a'], $dirs['b']]);
    $assert(
        ($paths['Alpha'] ?? null) === $dirs['a'] . '/Alpha.mog',
        'the first root owns the name',
    );
    $assert(
        ($duplicates['Alpha'] ?? []) === [$dirs['b'] . '/Alpha.mog'],
        'the second root is recorded as the other file the name could mean',
    );

    // A program that reaches the name is ambiguous: the compiler would have to pick
    // one of the two files, and nothing can tell it which.
    $threw = false;
    try {
        projectSourceClosure([$dirs['a'] . '/Alpha.mog'], [$dirs['a'], $dirs['b']]);
    } catch (TypeError $error) {
        $threw = true;
        $assert(
            \str_contains($error->getMessage(), 'module `Alpha` is offered by two roots'),
            'the refusal names the module and how many roots offer it, got: ' . $error->getMessage(),
        );
        // The message spells both files the way every artifact path is spelled,
        // so the expectation is canonicalised too rather than host-native.
        $assert(
            \str_contains($error->getMessage(), canonicalSeparators($dirs['a'] . '/Alpha.mog'))
            && \str_contains($error->getMessage(), canonicalSeparators($dirs['b'] . '/Alpha.mog')),
            'the refusal names both files, got: ' . $error->getMessage(),
        );
    }
    $assert($threw, 'a program that reaches a doubly offered name must be refused');

    // A name the program never imports stays unimported, which is what lets a tree
    // carry the two packages at all.
    $write($dirs['a'], 'Other');
    [$files] = projectSourceClosure([$dirs['a'] . '/Other.mog'], [$dirs['a'], $dirs['b']]);
    $assert(
        \count($files) === 1 && canonicalPath($files[0]) === canonicalPath($dirs['a'] . '/Other.mog'),
        'a name no import reaches is not resolved, so nothing clashes',
    );

    \unlink($dirs['b'] . '/Alpha.mog');
    $write($dirs['b'], 'Beta');
    [$paths, $duplicates] = libraryModuleScan([$dirs['a'], $dirs['b']]);
    $assert(
        isset($paths['Alpha'], $paths['Beta']) && $duplicates === [],
        'distinct roots each contribute their own modules, with nothing duplicated',
    );

    [$paths, $duplicates] = libraryModuleScan([$dirs['a'], $dirs['a']]);
    $assert(
        \count($paths) === 2 && isset($paths['Alpha']) && $duplicates === [],
        'a root repeated in the list offers the same file, which is one root and not a clash',
    );

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
