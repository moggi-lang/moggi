<?php declare(strict_types=1);

/**
 * The checked product is the sole owner of what it claims, measured over the
 * standard library rather than one fixture.
 *
 * A record that merely *exists* is not the claim. The claim is that lowering a
 * declaration it covers reads nothing from the mutable source tree — so this
 * case prepares the whole base library, and for every module it
 *
 *   1. requires the product to be complete: every declaration the module lowers —
 *      an ordinary function, an instance method, a class-default method, a
 *      derived member or a foreign import — is a record, and no declaration is
 *      left for a second producer to serve;
 *   2. requires every record to be self-consistent — no uncommitted type variable
 *      under a written signature, an evidence vector of exactly the scheme's
 *      dictionary count with the names the declaration's own parameters carry, a
 *      kind for every parameter the scheme binds, a scheme that names no variable
 *      its dictionaries do not, the type the declaration is lowered with, the
 *      documented obligation spelling, and an origin that is a real position;
 *   3. scrubs every elaborated site of every recorded declaration — the surface
 *      projection on each term, the class, dictionary and instance fields on
 *      each evidence site — lowers the module again, and requires byte-identical
 *      IR;
 *   4. injects the failures a record can carry — a type the declaration does not
 *      have, every kind flattened to `Type`, an obligation in a variable the
 *      scheme does not bind, an origin nothing is written at — and requires the
 *      same checks that passed every real record to refuse each one, so a record
 *      cannot be counted rather than checked.
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
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr;
use Moggi\Semantics\Types;
use Moggi\Syntax\Ast;

use function Moggi\Modules\constraintKey;
use function Moggi\Semantics\TypeExpr\collectTypeVars;
use function Moggi\Semantics\Types\internalTypeToAst;
use function Moggi\Semantics\Types\typeToString;


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

/**
 * The project's kind facts: the kind each datatype, type synonym, associated
 * family and promoted constructor is declared at, registered by the same imports
 * the checker runs.
 *
 * `Kinds\signatureKindErrors()` is the one place a published kind is held
 * against a declaration's type, and it reads the environment the checker's own
 * kind inference writes ({@see Kinds\declarationParameterKinds()}), so the rule is
 * not restated in a test where it could drift.
 */
$kindFacts = Types\newState('', '');
foreach ($prepared->units as $unit) {
    Modules\mergeExportedTypes($kindFacts, $unit, $prepared->units);
}

$records = 0;
$instanceMethodRecords = 0;
$foreignRecords = 0;
$modules = 0;
$scrubbedSites = 0;

/**
 * Where each prepared unit is written, so an origin can be held against a file.
 *
 * @var array<string, string> $unitPaths
 * @var array<string, int> $unitLines
 */
$unitPaths = [];
$unitLines = [];
foreach ($prepared->units as $unitName => $unit) {
    $sourcePath = $unit['path'] ?? null;
    if (! \is_string($sourcePath) || ! \is_file($sourcePath)) {
        continue;
    }
    $unitPaths[$unitName] = $sourcePath;
    $unitLines[$unitName] = \count(\file($sourcePath));
}

/** The dictionary parameter names the declaration's own parameters carry, in order. */
$slotNames = static function (array $constraints): array {
    $names = [];
    foreach ($constraints as $constraint) {
        $names[] = $constraint->evidence;
    }

    return $names;
};

/**
 * A type as text, blind to the names the checker happened to freshen.
 *
 * The record's scheme and the declaration it was made from are produced by two
 * different code paths — the checker's internal type and the AST the emitter
 * lowers — so the only fair comparison is alpha-equivalence. A `__Dict_` arrow is
 * the declaration's dictionary parameter; the scheme states the user type, so the
 * caller peels those first.
 */
$canonicalType = static function (Ast\TypeNode $node): string {
    $names = [];
    $walk = static function (Ast\TypeNode $n) use (&$walk, &$names): string {
        if ($n instanceof Ast\TypeVar) {
            $names[$n->name] ??= 'v' . \count($names);

            return 'var:' . $names[$n->name];
        }
        if ($n instanceof Ast\TypeCon) {
            return 'con:' . $n->name;
        }
        if ($n instanceof Ast\TypeApp) {
            $args = [];
            foreach ($n->args as $arg) {
                $args[] = $walk($arg);
            }

            return 'app(' . $walk($n->con) . ';' . \implode(',', $args) . ')';
        }
        if ($n instanceof Ast\TypeArrow) {
            return 'arrow(' . $walk($n->from) . ';' . $walk($n->to) . ')';
        }
        if ($n instanceof Ast\TypeUnit) {
            return 'unit';
        }

        return (new \ReflectionClass($n))->getShortName() . ':' . \json_encode(
            \array_filter(
                \get_object_vars($n),
                static fn ($v): bool => ! \is_object($v) && ! \is_array($v),
            ),
        );
    };

    return $walk($node);
};

/**
 * Every fact a record publishes, held against the declaration it describes.
 *
 * The injections at the end of this case run this same closure over records
 * that are wrong in exactly one way, so what it accepts is a contract rather
 * than a comment.
 */
$validateRecord = static function (
    string $moduleName,
    string $name,
    Modules\CheckedFunction $record,
    Ast\FunctionDecl $declaration,
) use ($assert, $slotNames, $canonicalType, $kindFacts, $unitPaths, $unitLines): void {
    $assert(
        $record->scheme->freeTypeVars === [],
        "the record for `{$moduleName}::{$name}` states a scheme with an uncommitted variable: "
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
    $assert(
        $record->evidenceBinders() === $slotNames($declaration->constraints),
        "the record for `{$moduleName}::{$name}` and the declaration disagree about its evidence"
            . ' vector: record ' . \json_encode($record->evidenceBinders()) . ', constraints '
            . \json_encode($slotNames($declaration->constraints)),
    );

    // The origin names a position in a file a tool can open. An inherited class
    // default is written in the class's module, so the origin's module is held
    // against the project's units instead of being assumed to be this one.
    $parts = \explode(':', $record->origin);
    $assert(
        \count($parts) === 3,
        "the record for `{$moduleName}::{$name}` publishes `{$record->origin}` as its origin, which is"
            . ' not a `module:line:col` position',
    );
    [$originModule, $originLine, $originCol] = $parts;
    $assert(
        $originLine > 0 && $originCol > 0,
        "the record for `{$moduleName}::{$name}` publishes `{$record->origin}` as its origin, which is"
            . ' not a position anything is written at',
    );
    $assert(
        isset($unitPaths[$originModule]),
        "the record for `{$moduleName}::{$name}` publishes `{$record->origin}` as its origin, and"
            . " `{$originModule}` is no module of this project",
    );
    $assert(
        $originLine <= $unitLines[$originModule],
        "the record for `{$moduleName}::{$name}` publishes `{$record->origin}` as its origin, past the"
            . ' end of `' . $unitPaths[$originModule] . '`',
    );

    // The scheme and the declaration are two renderings of one settled type: the
    // record's internal type must be the declaration's, alpha-equivalent, once
    // the dictionary parameters the declaration spells out are peeled off.
    $declaredType = $declaration->type;
    for ($peel = \count($record->evidence); $peel > 0; --$peel) {
        if (! $declaredType instanceof Ast\TypeArrow
            || ! $declaredType->from instanceof Ast\TypeCon
            || ! \str_starts_with((string) $declaredType->from->name, '__Dict_')
        ) {
            break;
        }
        $declaredType = $declaredType->to;
    }
    $assert(
        $declaredType !== null
            && $canonicalType(internalTypeToAst($record->scheme->type, [])) === $canonicalType($declaredType),
        "the record for `{$moduleName}::{$name}` states " . typeToString($record->scheme->type)
            . ' and the declaration it describes is lowered with a different type; the record is not'
            . ' the type the emitter lowers',
    );

    // A published kind has to be the kind the declaration's own type uses it at:
    // `Type -> Type -> Type` for a parameter the signature applies once, or `Type`
    // for one it applies at all, contradicts the type whatever else the record
    // says. The rule lives in the checker's kind module, not here.
    foreach (Kinds\signatureKindErrors($kindFacts, $record->scheme->type, $record->parameterKinds) as $error) {
        $assert(false, "the record for `{$moduleName}::{$name}` {$error}");
    }

    // The obligations the record publishes are the scheme's own dictionaries,
    // spelled in the variables the scheme binds. A stale spelling — the instance
    // head's pre-substitution variable — has no reading at all.
    $assert(
        \array_column($record->evidence, 'wanted') === \array_map(constraintKey(...), $record->scheme->dicts),
        "the record for `{$moduleName}::{$name}` publishes the obligations "
            . \json_encode(\array_column($record->evidence, 'wanted'))
            . ' while its scheme takes ' . \json_encode(\array_map(constraintKey(...), $record->scheme->dicts)),
    );
    foreach ($record->scheme->dicts as $dictionary) {
        foreach ($dictionary->args as $argument) {
            foreach (\array_keys(collectTypeVars($argument, [])) as $variable) {
                $assert(
                    \in_array($variable, $record->scheme->bound, true),
                    "the record for `{$moduleName}::{$name}` discharges `" . constraintKey($dictionary)
                        . "`, which names `{$variable}`, but its scheme binds"
                        . ' ' . \json_encode($record->scheme->bound),
                );
            }
        }
    }
};

/** @var array<string, ?array{0: string, 1: string, 2: Modules\CheckedFunction, 3: Ast\FunctionDecl}> $samples */
$samples = ['plain' => null, 'evidence' => null, 'kinded' => null];

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

    $declarations = [];
    foreach ($program->items as $item) {
        if (! $item instanceof Ast\FunctionDecl) {
            continue;
        }

        if ($item->signatureOnly) {
            continue;
        }

        $declarations[$item->name] = $item;
        $assert(
            $core->function($item->name) !== null,
            "`{$moduleName}::{$item->name}` is a declaration the module lowers and the check published no"
                . ' record for it; lowering would have to derive one from the tree',
        );
        if ($item->instanceMethod) {
            ++$instanceMethodRecords;
        }
        if ($item->foreign) {
            ++$foreignRecords;
        }
    }

    foreach ($core->functions as $name => $record) {
        ++$records;

        $declaration = $declarations[$name] ?? null;
        $assert($declaration !== null, "the record for `{$moduleName}::{$name}` names no declaration");

        $validateRecord($moduleName, $name, $record, $declaration);

        $samples['plain'] ??= [$moduleName, $name, $record, $declaration];
        if ($record->evidence !== []) {
            $samples['evidence'] ??= [$moduleName, $name, $record, $declaration];
        }
        if (\array_filter($record->parameterKinds, static fn (string $kind): bool => $kind !== 'Type') !== []) {
            $samples['kinded'] ??= [$moduleName, $name, $record, $declaration];
        }
    }

    foreach ($declarations as $declaration) {
        if ($core->function($declaration->name) === null) {
            continue;
        }

        foreach ($declaration->params as $param) {
            $scrubbedSites += \count($scrub($param));
        }
        $scrubbedSites += \count($scrub($declaration->body));
    }

    $scrubbed = IR\Dump\dump(IR\lower($program, $program->interface->data, $path));
    $assert(
        $scrubbed === $pristine,
        "lowering `{$moduleName}` still reads the elaborated tree for a declaration its record covers:"
            . " scrubbing the recorded declarations changed the IR\n" . diffText($pristine, $scrubbed),
    );

    ++$modules;
}

/**
 * The record with one published fact replaced.
 *
 * @param array{origin?: string, scheme?: TypeExpr\Scheme, evidence?: array, parameterKinds?: array<string, string>} $overrides
 */
$mutate = static fn (Modules\CheckedFunction $record, array $overrides): Modules\CheckedFunction
    => new Modules\CheckedFunction(
        $record->name,
        $overrides['origin'] ?? $record->origin,
        $overrides['scheme'] ?? $record->scheme,
        $overrides['evidence'] ?? $record->evidence,
        $record->residuals,
        $record->terms,
        $record->bodyEvidence,
        $overrides['parameterKinds'] ?? $record->parameterKinds,
    );

$schemeWithType = static fn (TypeExpr\Scheme $scheme, TypeExpr\Type $type): TypeExpr\Scheme
    => new TypeExpr\Scheme(
        $type,
        $scheme->bound,
        $scheme->constraints,
        $scheme->runtimeConstraintCount,
        $scheme->freeTypeVars,
        $scheme->classMethod,
        $scheme->class,
        $scheme->dicts,
    );

$injections = [];
[$moduleName, $name, $record, $declaration] = $samples['plain'] ?? [null, null, null, null];
$assert($record !== null, 'the differential found no record to mutate');

$injections['a type the declaration does not have'] = [
    $moduleName,
    $name,
    $declaration,
    $mutate($record, ['scheme' => $schemeWithType($record->scheme, new TypeExpr\TCon('Int', []))]),
    'different type',
];
$injections['an origin nothing is written at'] = [
    $moduleName,
    $name,
    $declaration,
    $mutate($record, ['origin' => $moduleName . ':0:0']),
    'not a position anything is written at',
];

[$moduleName, $name, $record, $declaration] = $samples['evidence'] ?? [null, null, null, null];
$assert($record !== null, 'the differential found no evidence-bearing record to mutate');
$evidence = $record->evidence;
$evidence[0]['wanted'] = 'Eq(t0)';
$injections['an obligation in a variable the scheme does not bind'] = [
    $moduleName,
    $name,
    $declaration,
    $mutate($record, ['evidence' => $evidence]),
    'while its scheme takes',
];

[$moduleName, $name, $record, $declaration] = $samples['kinded'] ?? [null, null, null, null];
$assert($record !== null, 'the differential found no record that publishes a kind other than Type to mutate');
$injections['every kind flattened to Type'] = [
    $moduleName,
    $name,
    $declaration,
    $mutate($record, ['parameterKinds' => \array_fill_keys(\array_keys($record->parameterKinds), 'Type')]),
    'at kind `Type`',
];
$widened = $record->parameterKinds;
foreach ($widened as $parameter => $kind) {
    if (\str_contains($kind, '->')) {
        $widened[$parameter] .= ' -> Type';

        break;
    }
}
$injections['one arrow too many on a type function'] = [
    $moduleName,
    $name,
    $declaration,
    $mutate($record, ['parameterKinds' => $widened]),
    '-> Type',
];

$refused = 0;
foreach ($injections as $label => [$moduleName, $name, $declaration, $record, $fragment]) {
    $message = null;
    try {
        $validateRecord($moduleName, $name, $record, $declaration);
    } catch (\RuntimeException $error) {
        $message = $error->getMessage();
    }

    $assert(
        $message !== null && \str_contains($message, $fragment),
        "the differential accepted a record with {$label}: " . ($message ?? 'no check refused it'),
    );
    ++$refused;
}

$assert($modules >= 40, "the differential covered only {$modules} modules of the base library");
$assert(
    $records >= 950,
    "the differential covered only {$records} records; every declaration of the base library that is"
        . ' lowered is record-owned, so a smaller number means a category was silently lost',
);
$assert(
    $instanceMethodRecords >= 500,
    "the differential covered only {$instanceMethodRecords} instance methods; the base library declares"
        . ' hundreds of them across its classes, so a smaller number means the category was not walked',
);
$assert(
    $foreignRecords > 0,
    'the differential covered no foreign import, so the category was not walked',
);
$assert(
    $scrubbedSites > 0,
    'the differential scrubbed no site, so it compared a tree it never touched',
);

echo "checked-core differential passed: {$records} records over {$modules} modules"
    . " ({$instanceMethodRecords} instance methods, {$foreignRecords} foreign imports),"
    . " {$scrubbedSites} sites scrubbed,"
    . " {$refused} injected faults refused\n";
