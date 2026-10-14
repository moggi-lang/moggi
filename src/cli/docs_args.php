<?php declare(strict_types=1);

namespace Moggi\CLI;

/**
 * The tree the docs tools index when the caller names none: the standard
 * library this compiler was installed with. In a checkout that is
 * `libraries/base/src` next to the sources; in a packaged installation it is
 * the library inside `bin/moggi.phar`, so `moggi moogle fromMaybe` works from
 * any directory.
 */
function defaultDocsInput(): string
{
    return \Moggi\Modules\bundledStdlibLibPath() ?? 'libraries/base/src';
}
