<?php declare(strict_types=1);

/**
 * The IO statement spine is a boundary, and this is the register that keeps it one.
 *
 * Ten construct families lower from checked terms and refuse a site their record does not plan.
 * A statement inside a `do` block is not one of them: the checker decides what each statement *is*
 * (value, sequence, `case`, deferred action, or an expression whose value is the action) and
 * publishes that on the expression; `src/ir/lower_io.php` executes the decision, and the only thing
 * still its own is the deferred lowering a `let`-bound action needs at its use site. The note at the
 * top of that file says so, and names the mechanisms deleted to keep it honest: the `let`-action fold
 * lookahead, the `try`/`catch` recovery, and the post-publication `IoAction` construction.
 *
 * A boundary is only worth the name if it cannot grow quietly, so this case pins six things and fails
 * the moment any of them changes without the boundary being revisited:
 *
 * 1. **The statement classes.** `src/syntax/ast_types.php` declares exactly the pinned `Io*` family,
 *    and exactly those four expression classes carry the published form. A new IO node becomes a
 *    lowering shape whether or not anyone notices; adding one here is the moment to decide it is
 *    still inside the boundary.
 * 2. **The producer and the reader.** The form is computed by `ioStatementForm()` in exactly one
 *    file (`src/semantics/io_escape.php`, the publisher) and read by exactly one function
 *    (`ioStatementFormOf()` in `src/ir/lower_io.php`). A second call site is the second switch this
 *    register exists to prevent.
 * 3. **The readers.** Only `lower_io.php` may name a statement class, plus the two IO entry points
 *    that stay in `lower_expr.php` (`lowerIoValueExpr`'s value wrapper and `plannedCase`'s `IoCase`
 *    arm). Any third file naming `Ast\Io*` fails with the file and the classes it named.
 * 4. **The recovery.** No file under `src/ir/` catches a lowering failure. `validate.php`'s single
 *    `catch` turns a validator exception into a report and is pinned as the one allowed site.
 * 5. **The construction.** Nothing under `src/ir/` builds an `Io*` syntax node: a tree built after
 *    publication was never checked, so lowering reading it would read a tree the checker never saw.
 * 6. **The store.** The identity-keyed write to `LowerCtx::$ioActionStore` happens in `lower_io.php`
 *    and nowhere else, so the deferred-lowering remainder cannot spread while it stands.
 * 7. **The producer's reach.** An `Io*` syntax node is constructed in exactly one file — the IO
 *    normalizer — and that normalizer has exactly one caller. Together with the gate the note states
 *    (a body is normalized only when its declared type returns IO, and that same predicate sends the
 *    body to the spine), this is why no legal program can hand `lowerExpr()` an `Io*` node and hit
 *    its default-arm refusal: the refusal is an assertion for a hand-built tree, not a reachable
 *    shape. A second constructor or a second caller fails here, because either would make that claim
 *    false.
 *
 * Update this file and the note in `lower_io.php` together, in the change that moves the boundary.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

/** Paths on this host, spelled `/` and relative to `src/`, which is how the register pins them. */
$canonical = static fn (string $path): string => \str_replace('\\', '/', $path);
$srcPrefix = $canonical($root) . '/src/';
$relativeToSrc = static fn (string $path): string => \str_starts_with($canonical($path), $srcPrefix)
    ? \substr($canonical($path), \strlen($srcPrefix))
    : $canonical($path);

/** The IO node classes in the AST, and the only file that may read each family beyond them. */
$statementClasses = ['IoAction', 'IoBind', 'IoCase', 'IoExpr', 'IoLet', 'IoLetAction', 'IoPure', 'IoSequence'];
/** The expression classes that carry the published form: the four shapes a statement/body can be. */
$formCarriers = ['IoAction', 'IoCase', 'IoPure', 'IoSequence'];
$readers = [
    // The spine reads every statement class, plus the marker that says a form may be published on
    // the node. It no longer names `IoPure`: the forms decide, not the classes.
    'lower_io.php' => ['IoAction', 'IoBind', 'IoCase', 'IoExpr', 'IoLet', 'IoLetAction', 'IoSequence', 'IoStatementExpression'],
    // A `case` in expression position keeps its own lowering shape here; the boxes it hands out are
    // taken as the expressions they are, so no `IoAction` is named any more.
    'lower_expr.php' => ['IoCase'],
];
/** `catch (` sites under `src/ir/`: the validator's report wrapper, and no lowering recovery. */
$catchSites = ['validate.php'];
/** Every file that mentions the action store at all (declaration, lifecycle, the spine). */
$storeFiles = ['ir/lower.php', 'ir/lower_io.php', 'ir/types.php'];

// 1. The AST's IO family, as declared, and the classes that publish a form.
$ast = (string) \file_get_contents($root . '/src/syntax/ast_types.php');
\preg_match_all('/^final class (Io[A-Za-z]+) extends AstNode/m', $ast, $declared);
\preg_match_all('/^final class (Io[A-Za-z]+) extends AstNode implements IoStatementExpression/m', $ast, $carriers);
$declaredCarriers = $carriers[1];
\sort($declaredCarriers);
$pinnedCarriers = $formCarriers;
\sort($pinnedCarriers);
$assert(
    $declaredCarriers === $pinnedCarriers,
    'the classes publishing a statement form changed: declared [' . \implode(', ', $declaredCarriers)
        . '], pinned [' . \implode(', ', $pinnedCarriers) . '] — the form is one field on the shapes a'
        . ' statement or body can be; a new carrier is a new answer the spine will read',
);
\preg_match_all('/^trait (PublishesIoForm)/m', $ast, $formTraits);
$assert(
    \count($formTraits[1]) === 1,
    'the published-form field is declared ' . \count($formTraits[1]) . ' times — one field, one place',
);
$declaredClasses = $declared[1];
\sort($declaredClasses);
$pinnedClasses = $statementClasses;
\sort($pinnedClasses);
$assert(
    $declaredClasses === $pinnedClasses,
    "the AST's IO statement family changed: declared [" . \implode(', ', $declaredClasses)
        . '], pinned [' . \implode(', ', $pinnedClasses) . '] — decide whether the new class belongs '
        . 'behind the boundary and update this inventory and the note in lower_io.php together',
);

// 2. The readers: which file under `src/ir/` names which IO class.
$actualReaders = [];
$irDir = $root . '/src/ir';
foreach (\glob($irDir . '/*.php') ?: [] as $path) {
    $body = (string) \file_get_contents($path);
    \preg_match_all('/Ast\\\\(Io[A-Za-z]+)/', $body, $matches);
    if ($matches[1] === []) {
        continue;
    }
    $classes = \array_values(\array_unique($matches[1]));
    \sort($classes);
    $actualReaders[\basename($path)] = $classes;
}
\ksort($actualReaders);
$expectedReaders = $readers;
foreach ($expectedReaders as $file => $classes) {
    \sort($expectedReaders[$file]);
}
\ksort($expectedReaders);
$assert(
    $actualReaders === $expectedReaders,
    "an `src/ir/` file reads the IO statement spine and is not in the boundary register:\n  actual:   "
        . \json_encode($actualReaders) . "\n  expected: " . \json_encode($expectedReaders)
        . "\n  a new reader is a new way through the boundary — route it through lower_io.php, or "
        . 'record it here and in the note with its reason',
);

// 2b. The producer and the reader: one call site each, named.
$producers = [];
$readersOfForm = [];
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $body = (string) \file_get_contents($file->getPathname());
    $relative = $relativeToSrc($file->getPathname());
    if (\preg_match_all('/(?<!function )\bioStatementForm\(/', $body) > 0) {
        $producers[] = $relative;
    }
    if (\preg_match_all('/ioStatementFormOf\(/', $body) > 0 && $relative !== 'ir/lower_io.php') {
        $readersOfForm[] = $relative;
    }
}
\sort($producers);
$assert(
    $producers === ['semantics/io_escape.php'],
    'the statement form is computed in [' . \implode(', ', $producers) . '], pinned '
        . '[semantics/io_escape.php] — the classification is published once, where the type and the'
        . ' `let`-bound actions in scope are both known; a second computation is a second answer',
);
$assert(
    $readersOfForm === [],
    'the published form is read outside the spine: [' . \implode(', ', $readersOfForm) . '] — one'
        . ' reader in `lower_io.php` is what makes the answer single; route the reader through it',
);

// 2c. The disposition: one producer, one reader, four kinds, and the walk's only dispatch.
\preg_match('/enum IoStatementKind: string\n\{(.*?)\n\}/s', (string) \file_get_contents($root . '/src/ir/types.php'), $kindBlock);
\preg_match_all('/^    case ([A-Za-z]+) = /m', $kindBlock[1] ?? '', $kindCases);
$kinds = $kindCases[1];
$expectedKinds = ['Let', 'LetAction', 'Bind', 'Expr'];
$assert(
    $kinds === $expectedKinds,
    'the statement disposition changed: declared [' . \implode(', ', $kinds) . '], pinned ['
        . \implode(', ', $expectedKinds) . '] — a new kind is a new thing the walk must do with a'
        . ' statement; add it here and say what it means',
);

\preg_match_all('/IoStatementKind::([A-Za-z]+)/', (string) \file_get_contents($root . '/src/semantics/io_escape.php'), $kindUses);
$assignments = $kindUses[1];
$assert(
    \array_values(\array_unique($assignments)) === $expectedKinds && \count($assignments) === 4,
    'the disposition is assigned [' . \implode(', ', $assignments) . '] in `semantics/io_escape.php`,'
        . ' pinned one per kind [' . \implode(', ', $expectedKinds) . '] — the producer classifies each'
        . ' statement once, where the statement list is walked',
);

$kindWriters = [];
$spine = (string) \file_get_contents($irDir . '/lower_io.php');
$lowerReads = \substr_count($spine, '->ioKind');
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    if (\preg_match('/->ioKind\s*=/', (string) \file_get_contents($file->getPathname())) === 1) {
        $kindWriters[] = $relativeToSrc($file->getPathname());
    }
}
\sort($kindWriters);
$assert(
    $kindWriters === ['semantics/io_escape.php'],
    'the disposition is written in [' . \implode(', ', $kindWriters) . '], pinned'
        . ' [semantics/io_escape.php] — one writer, the pass that knows the statement list',
);
$assert(
    $lowerReads === 1,
    'the spine reads `->ioKind` ' . $lowerReads . ' time(s) — pinned once, inside `statementKind()`,'
        . ' so the question is answered in one place',
);
$assert(
    \preg_match('/match \(\$stmt::class\)/', $spine) === 0
        && \preg_match('/match \(statementKind\(/', $spine) === 1,
    'the statement walk does not dispatch on the published disposition: found '
        . (\preg_match('/match \(\$stmt::class\)/', $spine) === 1 ? '`match ($stmt::class)`' : 'no `statementKind()` match')
        . ' — the disposition is the answer; dispatch on it, or record here why a class has to answer',
);

// 3. The recovery: no lowering path catches its own failure.
$actualCatch = [];
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($irDir, \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    if (\str_contains((string) \file_get_contents($file->getPathname()), 'catch (')) {
        $actualCatch[] = $file->getBasename();
    }
}
\sort($actualCatch);
$expectedCatch = $catchSites;
\sort($expectedCatch);
$assert(
    $actualCatch === $expectedCatch,
    'the `catch (` sites under `src/ir/` changed: found [' . \implode(', ', $actualCatch)
        . '], pinned [' . \implode(', ', $expectedCatch) . '] — a lowering path that catches its own '
        . 'failure emits code chosen by whether an exception happened; refuse the shape instead, or '
        . 'record the recovery here and in the note with what it buys',
);

// 3b. The construction: lowering never builds a syntax node the checker never saw.
$constructed = [];
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($irDir, \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    // AST nodes only: `new IoRun(…)` and friends are IR statements, which lowering of course builds.
    if (\preg_match('/new\\s+\\\\?(?:Moggi\\\\Syntax\\\\)?Ast\\\\Io[A-Za-z]+\\s*\(/', (string) \file_get_contents($file->getPathname())) === 1) {
        $constructed[] = $file->getBasename();
    }
}
\sort($constructed);
$assert(
    $constructed === [],
    'an `src/ir/` file constructs an IO syntax node: [' . \implode(', ', $constructed) . '] — a node'
        . ' built after publication was never checked, so lowering it reads a tree the checker never'
        . ' saw; take the expression the caller already has (`lowerActionValueToBox`) instead',
);

// 3c. The producer's reach: one constructor of an `Io*` node, and one caller of the normalizer.
$astConstructors = [];
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    if (\preg_match('/new\\s+\\\\?(?:Moggi\\\\Syntax\\\\)?Ast\\\\Io[A-Za-z]+\\s*\\(/', (string) \file_get_contents($file->getPathname())) === 1) {
        $astConstructors[] = $relativeToSrc($file->getPathname());
    }
}
\sort($astConstructors);
$assert(
    $astConstructors === ['semantics/strict_io_normalize.php'],
    'an IO syntax node is constructed outside the normalizer: found [' . \implode(', ', $astConstructors)
        . '] — the note\'s claim that the spine cannot be handed one rests on the normalizer being the'
        . ' only producer, so record the new producer here and revise the note',
);

$normalizerCallers = [];
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    // The import and a call, not any mention of the namespace: the boundary note names the normalizer
    // too, and a note is not a caller.
    $body = (string) \file_get_contents($file->getPathname());
    if (\preg_match('/use function Moggi\\\\Semantics\\\\StrictIoNormalize\\\\normalize;/', $body) === 1
        || \preg_match('/\bStrictIoNormalize\\\\normalize\\s*\(/', $body) === 1) {
        $normalizerCallers[] = $relativeToSrc($file->getPathname());
    }
}
\sort($normalizerCallers);
$assert(
    $normalizerCallers === ['semantics/effects.php'],
    'the IO normalizer is reached from [' . \implode(', ', $normalizerCallers) . '], pinned '
        . '[semantics/effects.php] — its single caller is what makes "an IO node exists only where the'
        . ' checker typed the body as returning IO" checkable; a second caller is a second place the'
        . ' gate would have to hold',
);

// 4. The store: one identity-keyed writer, and the lifecycle files that carry it.
$actualStore = [];
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    if (\str_contains((string) \file_get_contents($file->getPathname()), 'ioActionStore')) {
        $actualStore[] = $relativeToSrc($file->getPathname());
    }
}
\sort($actualStore);
$expectedStore = $storeFiles;
\sort($expectedStore);
$assert(
    $actualStore === $expectedStore,
    'the action store is mentioned in an unexpected place: found [' . \implode(', ', $actualStore)
        . '], pinned [' . \implode(', ', $expectedStore) . '] — the store is the boundary\'s deferred-'
        . 'lowering cache, so a new reader or writer widens it; record it here and in the note',
);

// The key itself: the store is keyed by the binder identity the checker allocates (the pattern
// term's `binder`) and read by the use's (`CheckedVariable::$binder`), so a spelling can never be a
// key again. Both expressions are pinned rather than the fact that some index exists.
$keyExpressions = [];
foreach ($expectedStore as $relative) {
    $body = (string) \file_get_contents($root . '/src/' . $relative);
    \preg_match_all('/ioActionStore\[([^\]]*)\]/', $body, $matches);
    foreach ($matches[1] as $key) {
        $keyExpressions[\trim($key)] = true;
    }
}
$keys = \array_keys($keyExpressions);
\sort($keys);
$expectedKeys = ['$binder', '$term->binder'];
\sort($expectedKeys);
$assert(
    $keys === $expectedKeys,
    'the action store is indexed by something other than a binder identity: found ['
        . \implode(', ', $keys) . '], pinned [' . \implode(', ', $expectedKeys) . '] — a name key is '
        . 'the defect this register exists to prevent; a new identity expression is fine, but it has '
        . 'to be recorded here and in the note',
);

$writers = [];
foreach (\glob($irDir . '/*.php') ?: [] as $path) {
    if (\preg_match('/ioActionStore\[\$binder\]\s*=/', (string) \file_get_contents($path)) === 1) {
        $writers[] = \basename($path);
    }
}
$assert(
    $writers === ['lower_io.php'],
    'the identity-keyed action-store write left the spine: [' . \implode(', ', $writers)
        . '] — the store is written where a `do` statement binds an action, and nowhere else',
);

echo 'IO spine boundary tests passed (' . \count($declaredClasses) . ' statement classes, '
    . \count($declaredCarriers) . ' form carriers, 1 form producer, ' . \count($actualReaders)
    . ' readers, ' . \count($actualCatch) . ' catch site, ' . \count($astConstructors)
    . ' node constructor(s) [' . \implode(', ', $astConstructors) . '], '
    . \count($normalizerCallers) . ' normalizer caller, '
    . \count($writers) . ' store writer, keys [' . \implode(', ', $keys) . "])\n";
