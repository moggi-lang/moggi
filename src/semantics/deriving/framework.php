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
    if (!isset($state->classes[$ref->name])) {
        throw typeFail(
            $state,
            "anyclass deriving: unknown class `{$ref->name}`",
            $ref,
        );
    }

    $head = dataDeclHeadAst($decl);

    // `deriving anyclass`: a method without a default is not an error there — it gets an
    // `error` body, so the instance is complete and only calling that method fails.
    $methods = [];
    foreach ($state->classes[$ref->name]['methods'] as $methodName => $methodInfo) {
        if (($methodInfo['defaultBody'] ?? null) !== null) {
            continue;
        }
        // `error` is a module-code builtin injected as the class default
        // for every module by the module pipeline; using the intrinsic `error#`
        // directly is not possible outside its owning module.
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
    ) {
    }
}

/**
 * Stock deriving backends (structural ADT codegen).
 *
 * @return array<string, callable(TypeCheckState, Ast\DataDecl, Ast\DerivingClassRef): DerivedInstance>
 */
function stockDeriveBackends(): array
{
    return [
        'Eq' => deriveEq(...),
        'Ord' => deriveOrd(...),
        'Enum' => deriveEnum(...),
        'Bounded' => deriveBounded(...),
        'Show' => deriveShow(...),
        'Read' => deriveRead(...),
        'Functor' => deriveFunctor(...),
        'Foldable' => deriveFoldable(...),
        'Traversable' => deriveTraversable(...),
        'Generic' => deriveGeneric(...),
    ];
}

/**
 * True when the class can be obtained via stock, newtype, anyclass, or via deriving.
 */
function hasDeriveBackend(string $className, ?string $strategy = null): bool
{
    // Explicit `via` delegates to an existing instance of any class.
    if ($strategy === 'via') {
        return true;
    }

    // Explicit `anyclass` is always available: methods come from class
    // defaults, and methods without a default become `error` bodies.
    if ($strategy === 'anyclass') {
        return true;
    }

    if (isset(stockDeriveBackends()[$className])) {
        return true;
    }

    if ($strategy === 'newtype') {
        return !refusesNewtypeDeriving($className);
    }

    // Default strategy for a non-stock class is `anyclass` (the
    // DeriveAnyClass default), which is always available: methods come from the
    // class defaults and the rest get `error` bodies.
    return true;
}

function resolveAndDerive(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): DerivedInstance {
    $strategy = resolveDeriveStrategy($state, $decl, $ref);
    if ($strategy === 'Newtype') {
        return deriveViaNewtype($state, $decl, $ref);
    }
    if ($strategy === 'Anyclass') {
        return deriveAnyClass($state, $decl, $ref);
    }
    if ($strategy === 'Via') {
        return deriveVia($state, $decl, $ref);
    }

    $backends = stockDeriveBackends();
    if (!isset($backends[$ref->name])) {
        throw typeFail(
            $state,
            "no stock deriving backend for class `{$ref->name}`"
                . ($decl->isNewtype ? ' (use `deriving newtype`)' : ''),
            $ref,
        );
    }

    return ($backends[$ref->name])($state, $decl, $ref);
}

/**
 * @return 'Stock'|'Newtype'|'Anyclass'|'Via'
 */
function resolveDeriveStrategy(
    TypeCheckState $state,
    Ast\DataDecl $decl,
    Ast\DerivingClassRef $ref,
): string {
    $explicit = $ref->strategy;
    if ($explicit === 'stock') {
        if (!isset(stockDeriveBackends()[$ref->name])) {
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

    // Default strategy: stock classes use stock, a non-stock class on a newtype uses GND
    // when derivable, everything else uses anyclass.
    if (isset(stockDeriveBackends()[$ref->name])) {
        return 'Stock';
    }
    if ($decl->isNewtype && !refusesNewtypeDeriving($ref->name)) {
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

    // 1–2. Validate: class exists; duplicates; some deriving path exists.
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

        if (!isset($state->classes[$ref->name])) {
            throw typeFail(
                $state,
                "unknown class `{$ref->name}` in deriving clause",
                $ref,
            );
        }
        if (!hasDeriveBackend($ref->name, $ref->strategy)) {
            throw typeFail(
                $state,
                "no deriving backend for class `{$ref->name}`",
                $ref,
            );
        }
    }

    // 3. Generate DerivedInstances (source order, no commit).
    //    Stock Generic also emits Datatype/Constructor/Selector metadata
    //    instances so conName/selName demote Symbol literals to String.
    $derived = [];
    foreach ($decl->derivingClasses as $ref) {
        $item = resolveAndDerive($state, $decl, $ref);
        $derived[] = $item;
        if ($item->className === 'Generic') {
            foreach (deriveGenericMetadataInstances($state, $decl, $ref) as $meta) {
                $derived[] = $meta;
            }
        }
    }

    // 4. Check instance uniqueness vs existing (before any commit).
    foreach ($derived as $item) {
        $inst = derivedInstanceToDecl($item);
        $headType = astType($state, $item->typeHead);
        validateNoDuplicateDerivedInstance($state, $inst, $headType);
    }

    $saved = snapshotInstanceState($state);

    // `$stockDeriving` also covers generalized newtype deriving: both paths
    // synthesize InstanceDecls and need polymorphic class-method dispatch
    // (no ambient monomorphic projections of `==` / `map` / …).
    $state->stockDeriving = true;
    try {
        $functions = [];
        foreach ($derived as $item) {
            $inst = derivedInstanceToDecl($item);
            commitProjectInstance($state, $inst);
            // Identical MetaSel heads (positional "" across Pair/Age) share one
            // Selector dictionary. A prior decl may already have elaborated it;
            // elaborating again emits duplicate `__ev_*` PHP functions.
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
    if (!isset($state->classes[$decl->className])) {
        throw typeFail($state, "unknown class `{$decl->className}`", $decl);
    }

    $targetName = extractTypeConstructorName($decl->head);
    if ($targetName === null || !isset($state->data[$targetName])) {
        throw typeFail(
            $state,
            "standalone deriving: target type `" . Ast\dumpTypeInline($decl->head) .
            "` is not a known data/newtype",
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

    $derived = resolveAndDerive($state, $dataDecl, $ref);
    $inst = derivedInstanceToDecl($derived);

    // Standalone declarations state their own context for stock deriving; `via` and
    // polymorphic `newtype` need the implicit context merged in.
    $constraints = $decl->constraints;
    if ($derived->strategy === 'Via') {
        $constraints = mergeDerivedConstraints($constraints, $derived->constraints);
    } elseif ($derived->strategy === 'Newtype' && $constraints === []) {
        // A polymorphic representation (`newtype Id a = Id a`) needs its
        // constraint; a concrete one (`newtype Age = Age Int`) does not.
        $constraints = mergeDerivedConstraints(
            $constraints,
            array_values(array_filter(
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
            || array_any($type->args, static fn (Ast\TypeNode $arg): bool => typeNodeMentionsVar($arg)),
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

        if (instanceHeadsMatch($state, $headType, $instance['head'])) {
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
            if (instanceHeadsMatch($state, $headType, $instance['head'])) {
                throw typeFail(
                    $state,
                    'duplicate instance for `' . $decl->class . ' ' . typeToString($headType, friendlyTypeVarNames([$headType])) . '`',
                    $decl,
                );
            }
            continue;
        }

        if (instanceHeadsMatch($state, $headType, $instance['head'])) {
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
            && instanceHeadsMatch($state, astType($state, $decl->head), $existing['head'])
        ) {
            installAssociatedEquationsFromDecl($state, $decl, $module);

            return;
        }
    }

    $record = [
        'module' => $module,
        'class' => $decl->class,
        'head' => $decl->head,
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
 * Lightweight project-instance records for data decls with deriving clauses.
 *
 * @return list<array{module: string, class: string, head: Ast\TypeNode, constraints: list<Ast\TypeNode>, associatedEquations: array<string, mixed>}>
 */
function derivedProjectInstanceRecords(Ast\DataDecl $decl, string $moduleName): array
{
    if ($decl->derivingClasses === []) {
        return [];
    }

    $records = [];
    foreach ($decl->derivingClasses as $ref) {
        if (!hasDeriveBackend($ref->name, $ref->strategy)) {
            continue;
        }

        // Via stubs carry `C V` in their context (what the generated methods
        // need). Full validation still runs in processDeriving.
        if ($ref->strategy === 'via') {
            $records[] = [
                'module' => $moduleName,
                'class' => $ref->name,
                'head' => dataDeclHeadAst($decl),
                'constraints' => $ref->viaType !== null
                    ? [new Ast\TypeApp(new Ast\TypeCon($ref->name), [$ref->viaType])]
                    : [],
                'associatedEquations' => [],
                'fromDeriving' => true,
            ];
            continue;
        }

        // Approximate strategy for stubs (full validation runs in processDeriving).
        $strategy = $ref->strategy;
        $useNewtype = $strategy === 'newtype'
            || ($strategy === null
                && $decl->isNewtype
                && !isset(stockDeriveBackends()[$ref->name])
                && !refusesNewtypeDeriving($ref->name));
        $useFunctorialHead = isFunctorialClass($ref->name);
        $head = $useFunctorialHead && $decl->params !== []
            ? functorialHeadAst($decl)
            : dataDeclHeadAst($decl);

        $constraints = projectStubConstraints($decl, $ref->name, $useNewtype);
        $associatedEquations = match ($ref->name) {
            'Generic' => genericProjectAssociatedEquations($decl, $moduleName),
            default => [],
        };
        $records[] = [
            'module' => $moduleName,
            'class' => $ref->name,
            'head' => $head,
            'constraints' => $constraints,
            'associatedEquations' => $associatedEquations,
            'fromDeriving' => true,
        ];
    }

    return $records;
}

/**
 * Lightweight constraints for project-instance stubs.
 *
 * @return list<Ast\TypeNode>
 */
function projectStubConstraints(Ast\DataDecl $decl, string $className, bool $useNewtype): array
{
    if ($useNewtype && $decl->isNewtype && $decl->constructors !== []) {
        $field = $decl->constructors[0]->fields[0]->type ?? null;
        if ($field !== null && isFunctorialClass($className)) {
            // newtype T a = T (f a) ⇒ Functor f
            if ($field instanceof Ast\TypeApp && $field->args !== []) {
                $underArgs = array_slice($field->args, 0, -1);
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

    $param = lastDataParamName($decl);
    return match ($className) {
        'Eq', 'Ord', 'Show' => paramClassConstraints($decl, $className),
        'Functor', 'Foldable', 'Traversable' => $param === null
            ? []
            : functorialContextConstraints($decl, $param, $className),
        default => [],
    };
}

/**
 * Lightweight Rep equation for project-instance stubs (normalize / other modules).
 *
 * @return array<string, array{lhsArgs: list<Ast\TypeNode>, rhs: Ast\TypeNode}>
 */
function genericProjectAssociatedEquations(Ast\DataDecl $decl, string $moduleName): array
{
    $state = newState('');
    $state->currentModule = $moduleName;

    return [
        'Rep' => [
            'lhsArgs' => [dataDeclHeadAst($decl)],
            'rhs' => synthesizeRepType($state, $decl),
        ],
    ];
}
