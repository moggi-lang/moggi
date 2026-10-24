<?php declare(strict_types=1);

// An instance head is kinded under its own context, never under whatever the
// ambient kind table happens to hold from previously registered siblings.
// Below, registering `useK1` pins the ambient `m` to `Type` (via `K1 m`),
// and the instance `K2 m => F2 (T s m)` must still kind `m` as `Type -> Type`
// from its own context. The registration-only interface pass used to read the
// ambient entry instead, so identical sources passed or failed depending on
// which declarations were registered first: a dependency prepared for
// documentation (never fully checked, fresh cache) died with
// "could not unify kind `Type` with `Type -> Type`" while every normal build
// stayed green.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Docs;

$failures = 0;

function assertTrue(bool $value, string $label): void
{
    global $failures;
    if (! $value) {
        ++$failures;
        \fwrite(STDERR, "FAIL {$label}\n");
    }
}

$work = \sys_get_temp_dir() . '/moggi-instance-kinds-' . \getmypid();
$rootDir = $work . '/root';
$depDir = $work . '/deplib';
@\mkdir($rootDir . '/src', 0777, true);
@\mkdir($depDir, 0777, true);
\file_put_contents(
    $rootDir . '/scope.moggi',
    "[package]\nname = scope\nversion = 0.1.0\n\n[lib]\nsource-dirs = src\n",
);
\file_put_contents(
    $rootDir . '/src/Scope.mog',
    "module Scope\n  ( touchDep\n  ) where\n\nimport Dep\n\ntouchDep :: K1 m => m -> m\ntouchDep = useK1\n",
);
\file_put_contents(
    $depDir . '/Dep.mog',
    <<<'MOG'
    {-# LANGUAGE NoImplicitPrelude #-}
    module Dep
      ( K1(..)
      , K2(..)
      , F2(..)
      , T(..)
      , useK1
      ) where

    -- A class with a first-order parameter: `K1 m` says `m :: Type`.
    class K1 m where
      k1 :: m -> m

    -- Classes with higher-order parameters: `K2 f` says `f :: Type -> Type`.
    class K2 f where
      k2 :: f a -> a

    class F2 f where
      f2 :: f a -> f a

    -- Registering this pins the ambient `m` to `Type`. It is registered
    -- before the instance below, which is exactly the order that used to
    -- poison the instance head.
    useK1 :: K1 m => m -> m
    useK1 x = k1 x

    newtype T s m a = MkT (s -> m (s, a))

    instance K2 m => F2 (T s m) where
      f2 x = x
    MOG,
);

$prevCacheDir = \getenv('MOGGI_CACHE_DIR') ?: '';
$cacheDir = $work . '/cache';
@\mkdir($cacheDir, 0777, true);
seedCacheDir($cacheDir);
\putenv('MOGGI_CACHE_DIR=' . $cacheDir);

try {
    $index = Docs\loadOrBuildIndex($rootDir, [$depDir], Docs\docsContext('php', [$depDir]), true);
    assertTrue(isset($index->byModule['Scope']), 'the documented root is indexed');
    assertTrue(! isset($index->byModule['Dep']), 'a dependency module is not indexed');
    echo "instance context kind tests passed\n";
} finally {
    if ($prevCacheDir !== '') {
        \putenv('MOGGI_CACHE_DIR=' . $prevCacheDir);
    } else {
        \putenv('MOGGI_CACHE_DIR');
    }
    @\unlink($rootDir . '/src/Scope.mog');
    @\unlink($rootDir . '/scope.moggi');
    @\unlink($depDir . '/Dep.mog');
    @\rmdir($rootDir . '/src');
    @\rmdir($rootDir);
    @\rmdir($depDir);
}

exit($failures === 0 ? 0 : 1);
