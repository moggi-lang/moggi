<?php declare(strict_types=1);

namespace Moggi\Semantics\Deriving;

use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\Types\associatedEquationsMapFromDecl;
use function Moggi\Semantics\Types\astType;
use function Moggi\Semantics\Types\checkInstance;
use function Moggi\Semantics\Types\evidenceFunctionName;
use function Moggi\Semantics\Types\friendlyTypeVarNames;
use function Moggi\Semantics\Types\instanceHeadIndexKeyFromAst;
use function Moggi\Semantics\Types\instanceHeadsMatch;
use function Moggi\Semantics\Types\newState;
use function Moggi\Semantics\Types\typeFail;
use function Moggi\Semantics\Types\typeToString;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/class_facts.php';
require_once __DIR__ . '/eq.php';
require_once __DIR__ . '/ord.php';
require_once __DIR__ . '/enum.php';
require_once __DIR__ . '/bounded.php';
require_once __DIR__ . '/show.php';
require_once __DIR__ . '/read.php';
require_once __DIR__ . '/functor.php';
require_once __DIR__ . '/foldable.php';
require_once __DIR__ . '/traversable.php';
require_once __DIR__ . '/generic.php';
require_once __DIR__ . '/newtype.php';
require_once __DIR__ . '/via.php';

/**
 * Anyclass deriving: builds the instance from the class's default methods.
 * Methods with a default are filled in at the instance site; methods without
 * one get an `error` body (`deriving anyclass`), so the instance is
 * complete and only calling such a method fails.
 */
function deriveAnyClass(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    if (! isset($state->classes[$ref->name])) {
        throw typeFail(
            $state,
            "anyclass deriving: unknown class `{$ref->name}`",
            $ref,
        );
    }

    $head = dataDeclHeadAst($decl);

    $methods = [];
    foreach ($state->classes[$ref->name]['methods'] as $methodName => $methodInfo) {
        if (($methodInfo['defaultBody'] ?? null) !== null) {
            continue;
        }
        $methods[] = methodDecl(
            $methodName,
            [],
            applyVar('error', new Ast\StringLit(
                "no default implementation for class method `{$methodName}` "
                    . "in an anyclass instance for `{$ref->name}`",
            )),
            $ref,
        );
    }

    return new DerivedInstance(
        $ref->name,
        $head,
        [],
        $methods,
        $ref,
        'Anyclass',
    );
}

/**
 * Compiler-internal derived instance before commit into the normal instance pipeline.
 */
final class DerivedInstance
{
    /**
     * @param list<Ast\TypeNode> $constraints
     * @param list<Ast\FunctionDecl> $methods
     * @param list<Ast\AssociatedTypeEquation> $associatedEquations
     * @param 'Stock'|'Newtype'|'Anyclass'|'Via' $strategy
     */
    public function __construct(
        public string $className,
        public Ast\TypeNode $typeHead,
        public array $constraints,
        public array $methods,
        public Ast\AstNode $sourceLocation,
        public string $strategy = 'Stock',
        public array $associatedEquations = [],
        public bool $contributesMetadata = false,
    ) {
    }
}

/**
 * One class the compiler derives structurally, and the whole deriving contract that is particular
 * to that class.
 *
 * The compiler knowing which classes it can derive is not a fact the library states, and it is not
 * one GHC pushes into the library either: `stockSideConditions` in `GHC/Tc/Deriv/Utils.hs` switches
 * on the class's identity and hands back the generator it has for it, and the identities themselves
 * are written down once, in `GHC/Builtin/Names`. This is that one statement for Moggi. Keeping the
 * policy *beside* the generator is the point: the context a project-instance stub carries, the
 * representation instance `Generic` contributes and the generator to run are all read off the
 * class's entry instead of being answered by three more `match`es on its name.
 */
final class StockDerive
{
    /**
     * @param callable(TypeCheckState, Ast\DataDecl, Ast\DerivingClassRef): DerivedInstance $derive
     * @param 'params'|'functorial'|'none' $stubConstraints the context a before-any-typecheck
     *        project-instance record carries: one constraint per data parameter, the functorial
     *        context, or none
     * @param bool $synthesizesRepresentation the generator emits the class's own associated type
     *        family instance and the metadata instances behind it (`Generic`'s `Rep`, and the
     *        `Datatype`/`Constructor`/`Selector` siblings the representation is read with)
     */
    public function __construct(
        public $derive,
        public string $stubConstraints = 'none',
        public bool $synthesizesRepresentation = false,
    ) {
    }
}

/**
 * The classes the compiler derives structurally, one entry each.
 *
 * @return array<string, StockDerive>
 */
function stockDeriveClasses(): array
{
    static $classes = null;

    return $classes ??= [
        'Eq' => new StockDerive(deriveEq(...), 'params'),
        'Ord' => new StockDerive(deriveOrd(...), 'params'),
        'Enum' => new StockDerive(deriveEnum(...)),
        'Bounded' => new StockDerive(deriveBounded(...)),
        'Show' => new StockDerive(deriveShow(...), 'params'),
        'Read' => new StockDerive(deriveRead(...)),
        'Functor' => new StockDerive(deriveFunctor(...), 'functorial'),
        'Foldable' => new StockDerive(deriveFoldable(...), 'functorial'),
        'Traversable' => new StockDerive(deriveTraversable(...), 'functorial'),
        'Generic' => new StockDerive(deriveGeneric(...), synthesizesRepresentation: true),
    ];
}

/** The compiler's entry for a class, or null when it has no structural generator for it. */
function stockDeriveFor(string $className): ?StockDerive
{
    return stockDeriveClasses()[$className] ?? null;
}

/**
 * True when the class can be obtained via stock, newtype, anyclass, or via deriving.
 *
 * The one question it asks about the class itself is newtype deriving's -- an
 * associated type leaves nothing to delegate to -- and `$facts` answers it from
 * whichever view of the class the caller has.
 */
function hasDeriveBackend(string $className, ?string $strategy, ClassDeriveFacts $facts): bool
{
    if ($strategy === 'via') {
        return true;
    }

    if ($strategy === 'anyclass') {
        return true;
    }

    if (stockDeriveFor($className) !== null) {
        return true;
    }

    if ($strategy === 'newtype') {
        return ! $facts->refusesNewtypeDeriving($className);
    }

    return true;
}

function resolveAndDerive(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
    ?array $headArgs = null,
): DerivedInstance {
    $strategy = resolveDeriveStrategy($state, $decl, $ref);
    if ($strategy === 'Newtype') {
        return deriveViaNewtype($state, $decl, $ref);
    }
    if ($strategy === 'Anyclass') {
        return deriveAnyClass($state, $decl, $ref);
    }
    if ($strategy === 'Via') {
        return deriveVia($state, $decl, $ref, $headArgs);
    }

    $stock = stockDeriveFor($ref->name);
    if ($stock === null) {
        throw typeFail(
            $state,
            "no stock deriving backend for class `{$ref->name}`"
                . ($decl->isNewtype ? ' (use `deriving newtype`)' : ''),
            $ref,
        );
    }

    return ($stock->derive)($state, $decl, $ref);
}

/**
 * @return 'Stock'|'Newtype'|'Anyclass'|'Via'
 */
function resolveDeriveStrategy(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): string {
    $facts = ClassDeriveFacts::fromState($state);
    $explicit = $ref->strategy;
    if ($explicit === 'stock') {
        if (stockDeriveFor($ref->name) === null) {
            throw typeFail(
                $state,
                "no stock deriving backend for class `{$ref->name}`",
                $ref,
            );
        }

        return 'Stock';
    }
    if ($explicit === 'newtype') {
        return 'Newtype';
    }
    if ($explicit === 'anyclass') {
        return 'Anyclass';
    }
    if ($explicit === 'via') {
        return 'Via';
    }

    if (stockDeriveFor($ref->name) !== null) {
        return 'Stock';
    }
    if ($decl->isNewtype && ! $facts->refusesNewtypeDeriving($ref->name)) {
        return 'Newtype';
    }

    return 'Anyclass';
}

/**
 * Run the deriving transaction for one data/newtype decl.
 * On any failure, restores instance-related state so nothing escapes.
 *
 * @return list<Ast\FunctionDecl>
 */
function processDeriving(TypeCheckState $state, Ast\DataDecl $decl): array
{
    if ($decl->derivingClasses === []) {
        return [];
    }

    $seenInClause = [];
    foreach ($decl->derivingClasses as $ref) {
        if (isset($seenInClause[$ref->name])) {
            throw typeFail(
                $state,
                "duplicate class `{$ref->name}` in deriving clause",
                $ref,
            );
        }
        $seenInClause[$ref->name] = true;

        if (! isset($state->classes[$ref->name])) {
            throw typeFail(
                $state,
                "unknown class `{$ref->name}` in deriving clause",
                $ref,
            );
        }
        if (! hasDeriveBackend($ref->name, $ref->strategy, ClassDeriveFacts::fromState($state))) {
            throw typeFail(
                $state,
                "no deriving backend for class `{$ref->name}`",
                $ref,
            );
        }
    }

    $derived = [];
    foreach ($decl->derivingClasses as $ref) {
        $item = resolveAndDerive($state, $decl, $ref);
        $derived[] = $item;
        if ($item->contributesMetadata) {
            foreach (deriveGenericMetadataInstances($state, $decl, $ref) as $meta) {
                $derived[] = $meta;
            }
        }
    }

    foreach ($derived as $item) {
        $inst = derivedInstanceToDecl($item);
        $headType = astType($state, $item->typeHead);
        validateNoDuplicateDerivedInstance($state, $inst, $headType);
    }

    $saved = snapshotInstanceState($state);

    $state->stockDeriving = true;

    try {
        $functions = [];
        foreach ($derived as $item) {
            $inst = derivedInstanceToDecl($item);
            commitProjectInstance($state, $inst);
            $evName = evidenceFunctionName($item->className, $item->typeHead);
            if (instanceEvidenceAlreadyElaborated($state, $evName)) {
                continue;
            }
            foreach (checkInstance($state, $inst) as $fn) {
                $functions[] = $fn;
            }
        }

        return $functions;
    } catch (\Throwable $e) {
        restoreInstanceState($state, $saved);

        throw $e;
    } finally {
        $state->stockDeriving = false;
    }
}

/**
 * Process a standalone deriving declaration and return the InstanceDecl.
 *
 * Handles:
 *   deriving instance C a => C (T a)
 *   deriving stock instance C a => C (T a)
 *   deriving newtype instance C T
 *   deriving via (V) instance C T
 */
function processStandaloneDeriving(
    TypeCheckState $state,
    Ast\StandaloneDerivingDecl $decl,
): Ast\InstanceDecl {
    if (! isset($state->classes[$decl->className])) {
        throw typeFail($state, "unknown class `{$decl->className}`", $decl);
    }

    $targetAst = standaloneDerivingTarget($decl->head);
    $targetName = extractTypeConstructorName($targetAst);
    if ($targetName === null || ! isset($state->data[$targetName])) {
        throw typeFail(
            $state,
            'standalone deriving: target type `' . Ast\dumpTypeInline($targetAst) .
            '` is not a known data/newtype',
            $decl,
        );
    }

    $dataDecl = $state->data[$targetName]['decl'] ?? null;
    if ($dataDecl === null) {
        throw typeFail(
            $state,
            "standalone deriving: no declaration AST for `{$targetName}`",
            $decl,
        );
    }

    $ref = new Ast\DerivingClassRef(
        $decl->className,
        $decl->line,
        $decl->col,
        $decl->endCol,
        strategy: $decl->strategy,
        viaType: $decl->viaType,
    );

    $derived = resolveAndDerive($state, $dataDecl, $ref, standaloneDerivingHeadArgs($decl->head));
    $inst = derivedInstanceToDecl($derived);

    $constraints = $decl->constraints;
    if ($derived->strategy === 'Via') {
        $constraints = mergeDerivedConstraints($constraints, $derived->constraints);
    } elseif ($derived->strategy === 'Newtype' && $constraints === []) {
        $constraints = mergeDerivedConstraints(
            $constraints,
            \array_values(\array_filter(
                $derived->constraints,
                static fn (Ast\TypeNode $c): bool => typeNodeMentionsVar($c),
            )),
        );
    }

    $inst = new Ast\InstanceDecl(
        $decl->className,
        $decl->head,
        $inst->methods,
        $constraints,
        $decl->line,
        $decl->col,
        $decl->endCol,
        associatedEquations: $inst->associatedEquations,
    );

    return $inst;
}

function typeNodeMentionsVar(Ast\TypeNode $type): bool
{
    return match ($type::class) {
        Ast\TypeVar::class => true,
        Ast\TypeApp::class => typeNodeMentionsVar($type->con)
            || \array_any($type->args, static fn (Ast\TypeNode $arg): bool => typeNodeMentionsVar($arg)),
        Ast\TypeArrow::class => typeNodeMentionsVar($type->from) || typeNodeMentionsVar($type->to),
        default => false,
    };
}

/**
 * Append `$derived` constraints that the user context did not already state.
 *
 * @param list<Ast\TypeNode> $user
 * @param list<Ast\TypeNode> $derived
 * @return list<Ast\TypeNode>
 */
function mergeDerivedConstraints(array $user, array $derived): array
{
    $seen = [];
    foreach ($user as $constraint) {
        $seen[Ast\dumpTypeInline($constraint)] = true;
    }
    foreach ($derived as $constraint) {
        $key = Ast\dumpTypeInline($constraint);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $user[] = $constraint;
    }

    return $user;
}

/**
 * The data/newtype a standalone deriving clause derives from. For a
 * multi-parameter class the head is the `__InstanceHead` marker, and only its
 * first argument names a type to derive from; the rest are the class
 * parameters.
 */
function standaloneDerivingTarget(Ast\TypeNode $head): Ast\TypeNode
{
    if (Ast\isInstanceHeadMarker($head) && $head->args !== []) {
        return $head->args[0];
    }

    return $head;
}

/**
 * The class arguments of a standalone deriving head. A multi-parameter class
 * stores them under the `__InstanceHead` marker; a single argument is the bare
 * type and a nullary class head is the unit type.
 *
 * @return list<Ast\TypeNode>
 */
function standaloneDerivingHeadArgs(Ast\TypeNode $head): array
{
    if (Ast\isInstanceHeadMarker($head)) {
        return $head->args;
    }

    if ($head instanceof Ast\TypeUnit) {
        return [];
    }

    return [$head];
}

function extractTypeConstructorName(Ast\TypeNode $type): ?string
{
    return match ($type::class) {
        Ast\TypeCon::class => $type->name,
        Ast\TypeApp::class => extractTypeConstructorName($type->con),
        default => null,
    };
}

function derivedInstanceToDecl(DerivedInstance $derived): Ast\InstanceDecl
{
    return new Ast\InstanceDecl(
        $derived->className,
        $derived->typeHead,
        $derived->methods,
        $derived->constraints,
        $derived->sourceLocation->line,
        $derived->sourceLocation->col,
        $derived->sourceLocation->endCol,
        associatedEquations: $derived->associatedEquations,
    );
}

/**
 * Build the surface type head for a data decl: `Foo`, `Foo a`, `Foo a b`, …
 * Uses a single TypeApp with all parameters (not left-nested apps) so associated
 * family matching / Rep equations see `Pair a b` as one constructor application.
 */
function dataDeclHeadAst(Ast\DataDecl $decl): Ast\TypeNode
{
    $head = new Ast\TypeCon($decl->name, $decl->line, $decl->col, $decl->endCol);
    if ($decl->params === []) {
        return $head;
    }

    $args = [];
    foreach ($decl->params as $param) {
        $args[] = new Ast\TypeVar($param->name);
    }

    return new Ast\TypeApp($head, $args);
}

/**
 * @return array{
 *   checkedInstances: list<array{class: string, head: Type, module: string}>,
 *   projectInstancesByClass: array<string, list<array<string, mixed>>>,
 *   projectInstancesByClassHead: array<string, array<string, list<array<string, mixed>>>>,
 *   instanceEvidence: list<array<string, mixed>>,
 *   instanceMethodsPendingUniq: list<array<string, mixed>>,
 *   instanceLookupCache: array<string, bool>,
 *   instanceMappingCache: array<string, mixed>,
 * }
 */
function snapshotInstanceState(TypeCheckState $state): array
{
    return [
        'checkedInstances' => $state->checkedInstances,
        'projectInstancesByClass' => $state->projectInstancesByClass,
        'projectInstancesByClassHead' => $state->projectInstancesByClassHead,
        'instanceEvidence' => $state->instanceEvidence,
        'instanceMethodsPendingUniq' => $state->instanceMethodsPendingUniq,
        'instanceLookupCache' => $state->instanceLookupCache,
        'instanceMappingCache' => $state->instanceMappingCache,
        'associatedEquations' => $state->associatedEquations,
    ];
}

/** @param array<string, mixed> $saved */
function restoreInstanceState(TypeCheckState $state, array $saved): void
{
    $state->checkedInstances = $saved['checkedInstances'];
    $state->projectInstancesByClass = $saved['projectInstancesByClass'];
    $state->projectInstancesByClassHead = $saved['projectInstancesByClassHead'];
    $state->instanceEvidence = $saved['instanceEvidence'];
    $state->instanceMethodsPendingUniq = $saved['instanceMethodsPendingUniq'];
    $state->instanceLookupCache = $saved['instanceLookupCache'];
    $state->instanceMappingCache = $saved['instanceMappingCache'];
    $state->associatedEquations = $saved['associatedEquations'];
}

/**
 * Like validateNoDuplicateInstance, but also rejects a same-module handwritten
 * instance for the same head (project-instance stubs from this deriving clause
 * are ignored via the `fromDeriving` marker).
 */
function validateNoDuplicateDerivedInstance(
    TypeCheckState $state,
    Ast\InstanceDecl $decl,
    Type $headType,
): void {
    foreach ($state->checkedInstances as $instance) {
        if ($instance['class'] !== $decl->class) {
            continue;
        }

        if (instanceHeadsMatch($state, $headType, $instance['instanceHead'])) {
            throw typeFail(
                $state,
                'duplicate instance for `' . $decl->class . ' ' . typeToString($headType, friendlyTypeVarNames([$headType])) . '`',
                $decl,
            );
        }
    }

    foreach ($state->projectInstancesByClass[$decl->class] ?? [] as $instance) {
        if (($instance['fromDeriving'] ?? false) === true) {
            continue;
        }

        if ($instance['module'] === ($state->currentModule ?? '')) {
            if (instanceHeadsMatch($state, $headType, $instance['instanceHead'])) {
                throw typeFail(
                    $state,
                    'duplicate instance for `' . $decl->class . ' ' . typeToString($headType, friendlyTypeVarNames([$headType])) . '`',
                    $decl,
                );
            }

            continue;
        }

        if (instanceHeadsMatch($state, $headType, $instance['instanceHead'])) {
            throw typeFail(
                $state,
                'duplicate instance for `' . $decl->class . ' ' . typeToString($headType, friendlyTypeVarNames([$headType])) . '`',
                $decl,
            );
        }
    }
}

function commitProjectInstance(TypeCheckState $state, Ast\InstanceDecl $decl): void
{
    $module = $state->currentModule ?? '';
    foreach ($state->projectInstancesByClass[$decl->class] ?? [] as $existing) {
        if (($existing['module'] ?? '') === $module
            && instanceHeadsMatch($state, astType($state, $decl->head), $existing['instanceHead'])
        ) {
            installAssociatedEquationsFromDecl($state, $decl, $module);

            return;
        }
    }

    $record = [
        'module' => $module,
        'class' => $decl->class,
        'instanceHead' => $decl->head,
        'constraints' => $decl->constraints,
        'associatedEquations' => associatedEquationsMapFromDecl($decl),
        'fromDeriving' => true,
    ];
    $state->projectInstancesByClass[$decl->class][] = $record;
    $headKey = instanceHeadIndexKeyFromAst($decl->head);
    $state->projectInstancesByClassHead[$decl->class][$headKey][] = $record;
    installAssociatedEquationsFromDecl($state, $decl, $module);
    $state->instanceLookupCache = [];
    $state->instanceMappingCache = [];
}

function instanceEvidenceAlreadyElaborated(TypeCheckState $state, string $evidenceName): bool
{
    foreach ($state->instanceEvidence as $ev) {
        $name = \is_array($ev) ? ($ev['evidenceName'] ?? null) : ($ev->evidenceName ?? null);
        if ($name === $evidenceName) {
            return true;
        }
    }

    return false;
}

function installAssociatedEquationsFromDecl(
    TypeCheckState $state,
    Ast\InstanceDecl $decl,
    string $module,
): void {
    foreach (associatedEquationsMapFromDecl($decl) as $famName => $eq) {
        $existing = $state->associatedEquations[$decl->class][$famName] ?? [];
        $kept = [];
        foreach ($existing as $prev) {
            if (
                ($prev['module'] ?? '') === $module
                && associatedEquationLhsEqual($prev['lhsArgs'] ?? [], $eq['lhsArgs'])
            ) {
                continue;
            }
            $kept[] = $prev;
        }
        $kept[] = [
            'lhsArgs' => $eq['lhsArgs'],
            'rhs' => $eq['rhs'],
            'module' => $module,
        ];
        $state->associatedEquations[$decl->class][$famName] = $kept;
    }
}

/** @param list<Ast\TypeNode> $left @param list<Ast\TypeNode> $right */
function associatedEquationLhsEqual(array $left, array $right): bool
{
    if (\count($left) !== \count($right)) {
        return false;
    }

    foreach ($left as $i => $lhs) {
        if (Ast\dumpTypeInline($lhs) !== Ast\dumpTypeInline($right[$i])) {
            return false;
        }
    }

    return true;
}

/**
 * Lightweight project-instance records for data decls with deriving clauses,
 * including the Datatype / Constructor / Selector instances a `deriving Generic`
 * also contributes.
 *
 * Those metadata instances are what a different module's `GToJSON (M1 D …)`
 * context reaches for, so publishing them for the declaring module lets that
 * obligation be solved wherever the type is used.
 *
 * The phase that calls this has no type check behind it, so `$facts` is what
 * stands in for the class table: the class's declaration says whether it refuses
 * newtype deriving and whether its parameter takes one type argument, which is
 * the same answer a checked class entry would give.
 *
 * @return list<array{module: string, class: string, head: Ast\TypeNode, constraints: list<Ast\TypeNode>, associatedEquations: array<string, mixed>, fromDeriving: true}>
 */
function derivedProjectInstanceRecords(Ast\DataDecl $decl, string $moduleName, ClassDeriveFacts $facts): array
{
    if ($decl->derivingClasses === []) {
        return [];
    }

    $records = [];
    $derivesGeneric = false;
    foreach ($decl->derivingClasses as $ref) {
        if (! hasDeriveBackend($ref->name, $ref->strategy, $facts)) {
            continue;
        }

        $stock = stockDeriveFor($ref->name);
        if ($stock !== null && $stock->synthesizesRepresentation) {
            $derivesGeneric = true;
        }

        if ($ref->strategy === 'via') {
            $records[] = [
                'module' => $moduleName,
                'class' => $ref->name,
                'instanceHead' => dataDeclHeadAst($decl),
                'constraints' => $ref->viaType !== null
                    ? [new Ast\TypeApp(new Ast\TypeCon($ref->name), [$ref->viaType])]
                    : [],
                'associatedEquations' => [],
                'fromDeriving' => true,
            ];

            continue;
        }

        $strategy = $ref->strategy;
        $useNewtype = $strategy === 'newtype'
            || ($strategy === null
                && $decl->isNewtype
                && $stock === null
                && ! $facts->refusesNewtypeDeriving($ref->name));
        $useFunctorialHead = $facts->takesOneTypeArgument($ref->name);
        $head = $useFunctorialHead && $decl->params !== []
            ? functorialHeadAst($decl)
            : dataDeclHeadAst($decl);

        $constraints = projectStubConstraints($decl, $ref->name, $useNewtype, $facts, $stock);
        $associatedEquations = $stock !== null && $stock->synthesizesRepresentation
            ? genericProjectAssociatedEquations($decl, $moduleName, $facts->associatedTypeNames($ref->name))
            : [];
        $records[] = [
            'module' => $moduleName,
            'class' => $ref->name,
            'instanceHead' => $head,
            'constraints' => $constraints,
            'associatedEquations' => $associatedEquations,
            'fromDeriving' => true,
        ];
    }

    if ($derivesGeneric) {
        $records = [...$records, ...genericMetadataProjectInstances($decl, $moduleName)];
    }

    return $records;
}

/**
 * Project-instance records for the Datatype / Constructor / Selector instances a
 * `deriving Generic` also generates.
 *
 * These mirror `deriveGenericMetadataInstances` name for name and dedupe rule for
 * rule: one Datatype per decl, one Constructor per (name, is-record), one Selector
 * per field name — a positional record's fields all share the empty name and so
 * share the one `MetaSel Nothing` instance.
 *
 * @return list<array{module: string, class: string, head: Ast\TypeNode, constraints: list<Ast\TypeNode>, associatedEquations: array<string, mixed>, fromDeriving: true}>
 */
function genericMetadataProjectInstances(Ast\DataDecl $decl, string $moduleName): array
{
    $state = newState('');
    $state->currentModule = $moduleName;

    $records = [[
        'module' => $moduleName,
        'class' => 'Datatype',
        'instanceHead' => metaDataType($state, $decl),
        'constraints' => [],
        'associatedEquations' => [],
        'fromDeriving' => true,
    ]];

    $seenCons = [];
    $seenSels = [];
    foreach ($decl->constructors as $ctor) {
        $consKey = $ctor->name . "\0" . (constructorIsRecord($ctor) ? '1' : '0');
        if (! isset($seenCons[$consKey])) {
            $seenCons[$consKey] = true;
            $records[] = [
                'module' => $moduleName,
                'class' => 'Constructor',
                'instanceHead' => metaConsType($ctor),
                'constraints' => [],
                'associatedEquations' => [],
                'fromDeriving' => true,
            ];
        }

        foreach ($ctor->fields as $field) {
            if (isset($seenSels[$field->name])) {
                continue;
            }
            $seenSels[$field->name] = true;
            $records[] = [
                'module' => $moduleName,
                'class' => 'Selector',
                'instanceHead' => metaSelType($field),
                'constraints' => [],
                'associatedEquations' => [],
                'fromDeriving' => true,
            ];
        }
    }

    return $records;
}

/**
 * Lightweight constraints for project-instance stubs.
 *
 * @return list<Ast\TypeNode>
 */
function projectStubConstraints(
    Ast\DataDecl $decl,
    string $className,
    bool $useNewtype,
    ClassDeriveFacts $facts,
    ?StockDerive $stock,
): array {
    if ($useNewtype && $decl->isNewtype && $decl->constructors !== []) {
        $field = $decl->constructors[0]->fields[0]->type ?? null;
        if ($field !== null && $facts->takesOneTypeArgument($className)) {
            if ($field instanceof Ast\TypeApp && $field->args !== []) {
                $underArgs = \array_slice($field->args, 0, -1);
                $under = $underArgs === []
                    ? $field->con
                    : new Ast\TypeApp($field->con, $underArgs);

                return [new Ast\TypeApp(new Ast\TypeCon($className), [$under])];
            }

            return [];
        }
        if ($field !== null) {
            return [new Ast\TypeApp(new Ast\TypeCon($className), [$field])];
        }

        return [];
    }

    if ($stock === null) {
        return [];
    }

    $param = lastDataParamName($decl);

    return match ($stock->stubConstraints) {
        'params' => paramClassConstraints($decl, $className),
        'functorial' => $param === null
            ? []
            : functorialContextConstraints($decl, $param, $className),
        default => [],
    };
}

/**
 * Lightweight representation equation for project-instance stubs (normalize / other modules).
 *
 * The family names are the class's own associated types, read from its declaration: the class that
 * declares `type Rep a :: Type -> Type` is the one whose stub equation is named `Rep`, so the name
 * is the declaration's rather than the deriving framework's.
 *
 * @param list<string> $families the class's declared associated type names
 * @return array<string, array{lhsArgs: list<Ast\TypeNode>, rhs: Ast\TypeNode}>
 */
function genericProjectAssociatedEquations(Ast\DataDecl $decl, string $moduleName, array $families): array
{
    $state = newState('');
    $state->currentModule = $moduleName;

    $equations = [];
    foreach ($families as $family) {
        $equations[$family] = [
            'lhsArgs' => [dataDeclHeadAst($decl)],
            'rhs' => synthesizeRepType($state, $decl),
        ];
    }

    return $equations;
}
