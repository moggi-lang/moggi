<?php declare(strict_types=1);

/**
 * A compiled tree is only linkable by the compiler that wrote it.
 *
 * Every `IO` entry in a prebuilt library tree defines a `__w_` worker whose name the current
 * compiler generates, so a tree from another ABI has the entries and not the workers the emitted
 * calls name — a runtime failure with no diagnostic. The compiler stamps what it writes
 * (`writeAbiStamp()`), and `setStdlibPhpPath()` / `detectStdlibPhpPath()` refuse a tree whose stamp
 * is missing or names another ABI.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require_once $root . '/src/compiler.php';

use Moggi\IR\Callables;
use Moggi\Modules;
use Moggi\Semantics\Types\TypeError;

use function Moggi\Compiler\runProcess;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$scratch = \sys_get_temp_dir() . '/moggi-abi-stamp-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
\mkdir($scratch, 0777, true);

$remove = static function (string $dir) use (&$remove): void {
    foreach (\scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        \is_dir($path) && ! \is_link($path) ? $remove($path) : @\unlink($path);
    }
    @\rmdir($dir);
};

/** A tree that looks like a compiled stdlib root: the structural marker, and whatever stamp is asked for. */
$tree = static function (string $name, ?string $stamp) use ($scratch): string {
    $dir = $scratch . '/' . $name;
    \mkdir($dir . '/Data', 0777, true);
    \file_put_contents($dir . '/Data/Eq.php', "<?php\n");
    if ($stamp !== null) {
        \file_put_contents($dir . '/' . Modules\ABI_STAMP, $stamp . "\n");
    }

    return $dir;
};

try {
    $current = $tree('current', (string) Callables\ABI_VERSION);
    Modules\setStdlibPhpPath($current);
    $assert(Modules\detectStdlibPhpPath(null) === $current, 'a tree stamped with the current ABI was not accepted');

    $stale = $tree('stale', (string) (Callables\ABI_VERSION + 1));
    $refusedStale = false;
    try {
        Modules\setStdlibPhpPath($stale);
    } catch (TypeError $e) {
        $refusedStale = \str_contains($e->getMessage(), 'ABI v' . (Callables\ABI_VERSION + 1));
    }
    $assert($refusedStale, 'a tree built for another ABI was accepted');

    $unstamped = $tree('unstamped', null);
    $refusedAbsent = false;
    try {
        Modules\setStdlibPhpPath($unstamped);
    } catch (TypeError $e) {
        $refusedAbsent = \str_contains($e->getMessage(), 'no ABI stamp');
    }
    $assert($refusedAbsent, 'a tree with no ABI stamp was accepted');

    // Detection of a sibling `lib/` is the other way a stale tree reaches a compile.
    Modules\setStdlibPhpPath(null);
    $out = $scratch . '/out';
    \mkdir($out, 0777, true);
    \mkdir($out . '/lib/Data', 0777, true);
    \file_put_contents($out . '/lib/Data/Eq.php', "<?php\n");
    \file_put_contents($out . '/lib/' . Modules\ABI_STAMP, (Callables\ABI_VERSION + 1) . "\n");
    $refusedSibling = false;
    try {
        Modules\detectStdlibPhpPath($out . '/App.php');
    } catch (TypeError $e) {
        $refusedSibling = \str_contains($e->getMessage(), 'ABI v');
    }
    $assert($refusedSibling, 'a sibling `lib/` built for another ABI was accepted');

    Modules\setStdlibPhpPath(null);

    // The writer: an unpacked compile stamps the tree it produced.
    \mkdir($scratch . '/app', 0777, true);
    \file_put_contents(
        $scratch . '/app/App.mog',
        "module App (main) where\n\nimport System.IO\n\nmain :: IO ()\nmain = putStrLn \"hi\"\n",
    );
    $compile = runProcess(
        [PHP_BINARY, $root . '/moggi.php', 'compile', $scratch . '/app/App.mog', '-o', $scratch . '/tree', '--backend', 'php', '--unpacked'],
        $scratch,
    );
    $assert(($compile['exitCode'] ?? 1) === 0, 'the unpacked compile failed: ' . \trim(($compile['stdout'] ?? '') . ($compile['stderr'] ?? '')));
    $stamp = $scratch . '/tree/' . Modules\ABI_STAMP;
    $assert(\is_file($stamp), 'the unpacked compile did not stamp the tree it wrote');
    $assert(\trim((string) \file_get_contents($stamp)) === (string) Callables\ABI_VERSION, 'the stamp names another ABI than the compiler emits');

    echo "stdlib ABI stamp tests passed ({$checks} checks)\n";
} finally {
    Modules\setStdlibPhpPath(null);
    $remove($scratch);
}
