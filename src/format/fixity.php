<?php declare(strict_types=1);

namespace Moggi\Format;

use Throwable;

use function Moggi\Modules\cachedModuleHeader;
use function Moggi\Modules\computeEffectiveFixityByModule;
use function Moggi\Modules\configuredLibraryRoots;
use function Moggi\Modules\findBoundedModuleRoot;
use function Moggi\Modules\findMogFilesUnder;
use function Moggi\Modules\locateStdlibRoot;
use function Moggi\Modules\syntheticCompilerFixity;
use function Moggi\Modules\syntheticCompilerModuleIndex;
use function Moggi\Modules\stdlibModuleIndex;
use function Moggi\Syntax\Parser\importedFixityForImports;

/**
 * The imported fixity of every file in a formatting selection.
 *
 * A formatter that parses a source without the fixities its imports bring into scope reads every
 * imported operator as left-associative at precedence 9. That can change the tree, and therefore
 * the printed program, without any diagnostic. The compiler already resolves fixity through the
 * import graph; this builds the same map from module headers, without typechecking or preparing.
 *
 * The selected files are read first, then the standard library and every configured library root.
 * A single file also pulls in its bounded module root, so formatting one file still sees the
 * custom operators its neighbours declare. A module that cannot be found contributes nothing and
 * is not an error: the formatter lays out syntactically valid code even when imports are missing.
 *
 * A caller that already has the full import closure can pass `discover: false`; the selected files
 * are then the whole module graph, and no workspace or standard-library roots are scanned again.
 *
 * @param list<string> $files
 * @return array<string, array<string, array{assoc: string, prec: int}>>
 */
function formatImportedFixityByFile(array $files, bool $discover = true): array
{
    if ($files === []) {
        return [];
    }

    /** @var array<string, true> $paths */
    $paths = [];
    /** @var array<string, true> $targets */
    $targets = [];
    $addPath = static function (string $path) use (&$paths): void {
        $paths[$path] = true;
    };

    foreach ($files as $file) {
        $real = \realpath($file) ?: $file;
        $targets[$real] = true;
        $addPath($real);
    }

    if ($discover) {
        foreach (configuredLibraryRoots() as $root) {
            foreach (findMogFilesUnder($root) as $path) {
                $addPath(\realpath($path) ?: $path);
            }
        }

        foreach ($files as $file) {
            $stdlib = locateStdlibRoot($file);
            if ($stdlib !== null) {
                foreach (stdlibModuleIndex($stdlib) as $path) {
                    $addPath(\realpath($path) ?: $path);
                }
            }
        }

        $boundedRoot = findBoundedModuleRoot($files[0]);
        if ($boundedRoot !== null) {
            foreach (findMogFilesUnder($boundedRoot) as $path) {
                $addPath(\realpath($path) ?: $path);
            }
        }
    }

    $ordered = \array_keys($paths);
    \usort($ordered, static function (string $a, string $b) use ($targets): int {
        $aTarget = isset($targets[$a]) ? 0 : 1;
        $bTarget = isset($targets[$b]) ? 0 : 1;

        return $aTarget <=> $bTarget ?: \strcmp($a, $b);
    });

    $localFixity = syntheticCompilerFixity();
    foreach (syntheticCompilerModuleIndex() as $module => $_path) {
        $localFixity[$module] ??= [];
    }

    /** @var array<string, list<\Moggi\Syntax\Ast\ImportDecl>> $importsByModule */
    $importsByModule = [];
    /** @var array<string, string> $modulePath */
    $modulePath = [];
    /** @var array<string, array<string, mixed>> $headers */
    $headers = [];

    foreach ($ordered as $path) {
        try {
            $header = cachedModuleHeader($path);
        } catch (Throwable) {
            continue;
        }

        $summary = [
            'module' => $header['module'] ?? null,
            'localFixity' => $header['localFixity'] ?? [],
            'imports' => $header['imports'] ?? [],
        ];
        $headers[$path] = $summary;
        $module = $summary['module'];
        if (! \is_string($module) || isset($modulePath[$module])) {
            continue;
        }

        $modulePath[$module] = $path;
        $localFixity[$module] = $summary['localFixity'];
        $importsByModule[$module] = $summary['imports'];
    }

    $effective = computeEffectiveFixityByModule(
        \array_map(static fn (array $imports): array => ['imports' => $imports], $importsByModule),
        $localFixity,
    );

    $byFile = [];
    foreach ($files as $file) {
        $real = \realpath($file) ?: $file;
        $header = $headers[$real] ?? null;
        if ($header === null) {
            try {
                $header = cachedModuleHeader($real);
            } catch (Throwable) {
                $header = null;
            }
        }

        $byFile[$file] = $header === null
            ? []
            : importedFixityForImports($header['imports'] ?? [], $effective);
    }

    return $byFile;
}
