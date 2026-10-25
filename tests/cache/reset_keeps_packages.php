<?php declare(strict_types=1);

/**
 * Compiler changes invalidate and rebuild only the compiler's cache area.
 * Installed packages, fetched catalogs, and runtime artifacts share the
 * root but must survive resets: losing a blob costs a fetch; losing an
 * install forces `moggi install` before `moggi build` can run.
 *
 * `moggi cache clear` can also clear individual areas, so each boundary
 * must preserve the data owned by the next.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/cache/cache.php';
require $root . '/tests/suite/support/workspace.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$work = createTempDir('moggi-reset');
$cache = $work . '/.moggi';
\putenv('MOGGI_CACHE_DIR=' . $cache);

$lay = static function (string $path, string $content) use ($cache): void {
    \mkdir(\dirname($cache . '/' . $path), 0777, true);
    \file_put_contents($cache . '/' . $path, $content);
};

try {
    $lay('fingerprint', 'the-old-compiler');
    $lay('compile/artifacts/index.blob', 'compiler artifact');
    $lay('compile/php/Modules/Json.mogc', 'a cache entry');
    $lay('docs/index.html', 'the served site');
    $lay('catalog/registry-abcd1234/index.json', '{"format":1}');
    $lay('packages/json/8aef472b/src/Json.mog', 'module Json where');
    $lay('runtime/jackson/jackson-core.jar', 'a host tool fetched jar');

    \Moggi\Cache\resetCacheTree($cache, 'the-new-compiler');

    $assert(! \file_exists($cache . '/compile/artifacts/index.blob'), 'a reset must take the compiler artifacts');
    $assert(! \file_exists($cache . '/compile/php/Modules/Json.mogc'), 'a reset must take the cache entries');
    $assert(! \file_exists($cache . '/docs/index.html'), 'a reset must take the served site');
    $assert(
        (string) \file_get_contents($cache . '/fingerprint') === 'the-new-compiler',
        'a reset must stamp the new fingerprint',
    );

    $assert(\is_file($cache . '/packages/json/8aef472b/src/Json.mog'), 'a reset must keep installed packages');
    $assert(\is_file($cache . '/catalog/registry-abcd1234/index.json'), 'a reset must keep the fetched catalog');
    $assert(\is_file($cache . '/runtime/jackson/jackson-core.jar'), 'a reset must keep tool-fetched runtime artifacts');

    $lay('compile/php/Modules/Json.mogc', 'a cache entry');
    $lay('docs/index.html', 'the served site');
    $assert(\Moggi\Cache\clearArea('compiler') > 0, 'clearing the compiler area must report what it removed');
    $assert(! \is_dir($cache . '/compile'), 'clearing the compiler area must take the entries');
    $assert(! \is_dir($cache . '/docs'), 'clearing the compiler area must take the served site');
    $assert(\is_file($cache . '/fingerprint'), 'clearing the compiler area must leave the fingerprint');
    $assert(\is_file($cache . '/packages/json/8aef472b/src/Json.mog'), 'clearing the compiler area must keep packages');

    $assert(\Moggi\Cache\clearArea('catalog') > 0, 'clearing the catalog area must report what it removed');
    $assert(! \is_dir($cache . '/catalog'), 'clearing the catalog area must take the fetched metadata');
    $assert(\is_file($cache . '/packages/json/8aef472b/src/Json.mog'), 'clearing the catalog area must keep packages');

    $assert(\Moggi\Cache\clearArea('runtime') > 0, 'clearing the runtime area must report what it removed');
    $assert(! \is_dir($cache . '/runtime'), 'clearing the runtime area must take the tool-fetched artifacts');

    $assert(\Moggi\Cache\clear() > 0, 'clear must report what it removed');
    $assert(! \is_dir($cache . '/packages'), 'clear must remove the installed packages');
} finally {
    \putenv('MOGGI_CACHE_DIR');
    removeDirectory($work);
}

\fwrite(STDOUT, "cache-reset: {$checks} checks passed\n");
