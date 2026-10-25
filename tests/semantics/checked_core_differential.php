<?php declare(strict_types=1);

/**
 * The checked product is the sole owner of what it claims, measured over the
 * standard library rather than one fixture.
 *
 * A record that merely *exists* is not the claim. The claim is that lowering a
 * declaration it covers reads nothing from the mutable source tree — so this
 * case prepares the whole base library, and for every module it
 *
 *   1. requires the split to be complete: each ordinary declaration is either a
 *      record or named in `unowned` with the fact that kept it out, never an
 *      unclassified name that lowering happens to route;
 *   2. requires every record to be closed and self-consistent — no uncommitted
 *      type variable, an evidence vector of exactly the scheme's dictionary
 *      count, a kind for every parameter the scheme binds, and an evidence
 *      vector that still matches the one the other producer reads off the
 *      declaration (`evidenceSlotNames()` of its constraints), so the two
 *      cannot drift apart unnoticed;
 *   3. scrubs every elaborated site of every recorded declaration — the surface
 *      projection on each term, the class, dictionary and instance fields on
 *      each evidence site — lowers the module again, and requires byte-identical
 *      IR.
 *
 * The corpus is the point: a fixture proves the shapes someone thought of, and
 * the library is the acceptance surface the compiler has to keep. The counts it
 * prints are the coverage claim, so a later slice cannot quietly cover less.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\IR;
use Moggi\Modules;
use Moggi\Pipeline\CompilationContext;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Syntax\Ast;

use function Moggi\IR\evidenceSlotNames;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$libraryRoot = $root . '/libraries/base/src';
$files = [];
$walker = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($libraryRoot, \FilesystemIterator::SKIP_DOTS),
);
foreach ($walker as $file) {
    if ($file->isFile() && \str_ends_with($file->getFilename(), '.mog')) {
        $files[] = $file->getPathname();
    }
}
\sort($files);
$assert($files !== [], 'the base library has no modules to check');

/**
 * Replace every elaborated fact lowering could read off the tree.
 *
 * The projector and the evidence sites are scrubbed to values no program would
 * produce, so a lowering path that reads either one emits different IR instead
 * of silently agreeing with itself.
 *
 * @return list<array{0: Ast\AstNode, 1: string}> the sites scrubbed, for the message
 */
$scrub = static function (Ast\AstNode $node): array {
    $touched = [];
    Modules\walkElaboration($node, static function (Ast\AstNode $site) use (&$touched): void {
        if ($site->inferredType !== null) {
            $site->inferredType = new Ast\TypeCon('Scrubbed');
            $touched[] = [$site, 'inferredType'];
        }

        if ($site instanceof Ast\EvidenceMethod) {
            $site->class = 'Scrubbed';
            $site->evidence = 'scrubbed';
            $site->evidenceInstance = null;
            $site->contextEvidence = [];
            $touched[] = [$site, 'evidence'];

            return;
        }

        if ($site instanceof Ast\EvidenceRef) {
            $site->class = 'Scrubbed';
            $site->context = [];
            $touched[] = [$site, 'evidence'];
        }
    });

    return $touched;
};

$context = CompilationContext::for(
    'php',
    CompilePurpose::Library,
    entryModules: [],
    roots: [$libraryRoot],
    sources: $files,
);
$prepared = Modules\prepareProject($files, $libraryRoot, $context);

$records = 0;
$unowned = 0;
$modules = 0;
$scrubbedSites = 0;

foreach ($prepared->checked as $moduleName => $program) {
    $unit = $prepared->units[$moduleName] ?? [];
    if (($unit['synthetic'] ?? false) === true) {
        // The compiler's own units declare no source-level functions; their product is the tree it
        // generated, so there is nothing here for the record to own or to be missing.
        continue;
    }

    $core = $program->core;
    $assert($core !== null, "the checked module `{$moduleName}` published no checked core");

    $path = $unit['path'] ?? $moduleName . '.mog';
    $pristine = IR\Dump\dump(IR\lower($program, $program->interface->data, $path));

    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl) {
            continue;
        }

        if ($item->signatureOnly || $item->foreign || $item->instanceMethod) {
            continue;
        }

        $assert(
            $core->function($item->name) !== null || isset($core->unowned[$item->name]),
            "`{$moduleName}::{$item->name}` is an ordinary declaration the check neither recorded nor"
                . ' left to the existing producer; lowering would have to guess which it is',
        );
    }

    foreach ($core->functions as $name => $record) {
        ++$records;

        $assert(
            $record->scheme->freeTypeVars === [],
            "the record for `{$moduleName}::{$name}` has an uncommitted variable: "
                . \implode(', ', \array_keys($record->scheme->freeTypeVars)),
        );
        $assert(
            \count($record->evidence) === $record->scheme->runtimeConstraintCount,
            "the record for `{$moduleName}::{$name}` publishes " . \count($record->evidence)
                . ' evidence slots for a scheme that takes ' . $record->scheme->runtimeConstraintCount,
        );
        $assert(
            \array_keys($record->parameterKinds) === $record->scheme->bound,
            "the record for `{$moduleName}::{$name}` does not state a kind for every parameter its"
                . ' scheme binds: ' . \json_encode($record->parameterKinds) . ' for '
                . \json_encode($record->scheme->bound),
        );

        $declaration = null;
        foreach ($program->items as $item) {
            if ($item instanceof Ast\FunctionDecl && $item->name === $name) {
                $declaration = $item;
            }
        }
        $assert($declaration !== null, "the record for `{$moduleName}::{$name}` names no declaration");
        $assert(
            $record->evidenceBinders() === evidenceSlotNames($declaration->constraints),
            "the record for `{$moduleName}::{$name}` and the declaration disagree about its evidence"
                . ' vector: record ' . \json_encode($record->evidenceBinders()) . ', constraints '
                . \json_encode(evidenceSlotNames($declaration->constraints)),
        );
    }

    $unowned += \count($core->unowned);

    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $core->function($item->name) === null) {
            continue;
        }

        foreach ($item->params as $param) {
            $scrubbedSites += \count($scrub($param));
        }
        $scrubbedSites += \count($scrub($item->body));
    }

    $scrubbed = IR\Dump\dump(IR\lower($program, $program->interface->data, $path));
    $assert(
        $scrubbed === $pristine,
        "lowering `{$moduleName}` still reads the elaborated tree for a declaration its record covers:"
            . " scrubbing the recorded declarations changed the IR\n" . diffText($pristine, $scrubbed),
    );

    ++$modules;
}

$assert($modules >= 40, "the differential covered only {$modules} modules of the base library");
$assert(
    $records >= 950,
    "the differential covered only {$records} records; every ordinary declaration of the base library"
        . ' is record-owned, so a smaller number means a category was silently lost',
);
$assert(
    $scrubbedSites > 0,
    'the differential scrubbed no site, so it compared a tree it never touched',
);

$covered = $records + $unowned;
$assert(
    $covered >= 950,
    "the differential accounted for only {$covered} ordinary declarations",
);

echo "checked-core differential passed: {$records} records over {$modules} modules,"
    . " {$unowned} declarations left to the existing producer, {$scrubbedSites} sites scrubbed\n";
