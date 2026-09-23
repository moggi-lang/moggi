<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Backend\DotNet\Foreign\registerDotNetForeignType;
use function Moggi\Backend\Jvm\Foreign\registerJvmForeignType;
use function Moggi\Backend\compileBackend;
use function Moggi\Semantics\IntrinsicRegistry\isMagicHashName;
use function Moggi\Semantics\TypeExpr\scheme;

require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/constraints.php';
require_once __DIR__ . '/infer.php';

function standalonePrimitiveTypeSynonyms(): array
{
    // Public IO synonym lives in System.IO.Base (re-exported by System.IO) and is
    // never ambient-bootstrapped.
    $rhs = static fn (string $prim): array => synonymEntry(new Ast\TypeCon($prim));

    return [
        'Int' => $rhs('Int#'),
        'Char' => $rhs('Char#'),
        'Word' => $rhs('Word#'),
        'Word8' => $rhs('Word8#'),
        'Word16' => $rhs('Word16#'),
        'Word32' => $rhs('Word32#'),
        'Word64' => $rhs('Word64#'),
        'Int8' => $rhs('Int8#'),
        'Int16' => $rhs('Int16#'),
        'Int32' => $rhs('Int32#'),
        'Int64' => $rhs('Int64#'),
        'String' => $rhs('String#'),
        'ByteString' => $rhs('Bytes#'),
        'Double' => $rhs('Double#'),
        'Integer' => $rhs('Integer#'),
        'Natural' => $rhs('Natural#'),
    ];
}

/** @param list<string> $params @return array{params: list<string>, rhs: Ast\TypeNode} */
function synonymEntry(Ast\TypeNode $rhs, array $params = [], ?Type $body = null): array
{
    return ['params' => $params, 'rhs' => $rhs, 'body' => $body];
}

function applyPrimitiveTypeSynonymBootstrap(TypeCheckState $state, array $definedTypeSynonyms): void
{
    foreach (standalonePrimitiveTypeSynonyms() as $name => $type) {
        if (isset($state->typeSynonyms[$name]) || isset($definedTypeSynonyms[$name])) {
            continue;
        }

        // Magichash RHS resolves during synonym expansion even without a Prim
        // import, so public names like `Int` stay usable in standalone scripts.
        // Writing `Int#` directly still requires importing Moggi.Internal.Prim.
        $state->typeSynonyms[$name] = $type;
    }
}

function applyPrimitiveDataBootstrap(TypeCheckState $state): void
{
    if (!isset($state->data['Bool'])) {
        $state->data['Bool'] = [
            'params' => [],
            'paramKinds' => [],
            'result' => new TCon('Bool'),
            'constructors' => [
                'False' => ['name' => 'False', 'fields' => [], 'fieldTypes' => []],
                'True' => ['name' => 'True', 'fields' => [], 'fieldTypes' => []],
            ],
        ];
    }

    // Bool is wired in: `if` desugars to a case on `True`/`False`, so the
    // constructors have to resolve in a module that never names Data.Bool -- and
    // they stay wired in when the type itself arrived another way (from a
    // module that imports `Data.Bool`).
    $state->env['True'] ??= scheme(new TCon('Bool'), []);
    $state->env['False'] ??= scheme(new TCon('Bool'), []);

    // DataKinds: `'True` / `'False` are usable as promoted types even though
    // Bool is bootstrapped rather than going through registerData.
    foreach (['False', 'True'] as $ctorName) {
        $state->promoted[$ctorName] ??= ['data' => 'Bool'];
    }
}

function registerData(TypeCheckState $state, Ast\DataDecl $decl): void
{
    if (isMagicHashName($decl->name)
        || $decl->name === 'IO'
    ) {
        throw typeFail(
            $state,
            "`{$decl->name}` is a compiler primitive and cannot be redefined",
            $decl,
        );
    }

    foreach ($decl->constructors as $ctor) {
        if (isMagicHashName($ctor->name)) {
            throw typeFail(
                $state,
                "constructor `{$ctor->name}` uses the reserved `#` suffix (compiler intrinsics only)",
                $decl,
            );
        }
    }

    if (isset($state->data[$decl->name])) {
        throw typeFail($state, "duplicate data type `{$decl->name}`", $decl);
    }

    $params = \array_map(static fn (Ast\DataParam $p): string => $p->name, $decl->params);
    $paramTypes = \array_map(static fn (string $p): Type => new TVar($p), $params);
    $result = new TCon($decl->name, $paramTypes);

    $state->data[$decl->name] = [
        'params' => $params,
        'result' => $result,
        'constructors' => [],
        'newtype' => $decl->isNewtype,
        'decl' => $decl,
    ];

    try {
        $dataKind = Kinds\inferDataKind($state, $decl);
    } catch (\InvalidArgumentException $e) {
        throw typeFail($state, "kind error in data type `{$decl->name}`: " . $e->getMessage(), $decl);
    }
    Kinds\registerTypeKind($state, $decl->name, $dataKind);
    $paramKinds = \array_combine(
        $params,
        Kinds\peelParamKinds($dataKind, count($params)),
    ) ?: [];
    $state->data[$decl->name]['paramKinds'] = $paramKinds;

    $kindScope = Kinds\pushKindScope($state);
    $state->varKinds = [...$state->varKinds, ...$paramKinds];
    try {
        foreach ($decl->constructors as $ctor) {
            if (constructorNameExists($state, $ctor->name)) {
                throw typeFail($state, "duplicate data constructor `{$ctor->name}`", $ctor);
            }

            validateConstructorFieldTypeVars($state, $ctor, $params);

            $fieldTypes = [];
            $fieldNames = [];
            foreach ($ctor->fields as $field) {
                $fieldType = astType($state, $field->type);
                Kinds\assertKind(
                    $state,
                    $fieldType,
                    new Kinds\KType(),
                    $state->varKinds,
                    $field->type,
                );
                $fieldTypes[] = $fieldType;
                $fieldNames[] = $field->name;
            }

            $state->data[$decl->name]['constructors'][$ctor->name] = [
                'name' => $ctor->name,
                'fields' => $fieldNames,
                'fieldTypes' => $fieldTypes,
            ];

            $ctorType = $result;
            foreach (array_reverse($fieldTypes) as $fieldType) {
                $ctorType = new TArrow($fieldType, $ctorType);
            }

            $bound = $params;
            $state->env[$ctor->name] = scheme($ctorType, $bound);

            // DataKinds: register promotable constructors (nullary, or fields
            // whose types are themselves datatypes usable as kinds).
            registerPromotedConstructor($state, $decl->name, $ctor, $fieldTypes);
        }

        // `Solo a = MkSolo a` is a constructor alias: both names construct and
        // the same tagged value. Solo is a constructor alias of MkSolo.
        if ($decl->name === 'Solo'
            && isset($state->data['Solo']['constructors']['MkSolo'])
            && !isset($state->data['Solo']['constructors']['Solo'])
        ) {
            registerConstructorAlias($state, 'Solo', 'MkSolo', 'Solo');
        }
    } finally {
        Kinds\restoreKindScope($state, $kindScope);
    }
}

/**
 * Register `$alias` as another name for `$canonical` on `$dataName`.
 * Patterns and construction both resolve; exhaustiveness uses the canonical tag.
 */
function registerConstructorAlias(
    TypeCheckState $state,
    string $dataName,
    string $canonical,
    string $alias,
): void {
    $ctor = $state->data[$dataName]['constructors'][$canonical]
        ?? throw typeFail($state, "cannot alias unknown constructor `{$canonical}`");
    if (constructorNameExists($state, $alias)) {
        throw typeFail($state, "duplicate data constructor `{$alias}`");
    }

    $state->data[$dataName]['constructors'][$alias] = [
        'name' => $alias,
        'fields' => $ctor['fields'],
        'fieldTypes' => $ctor['fieldTypes'],
        'aliasOf' => $canonical,
    ];
    $state->env[$alias] = $state->env[$canonical];
    $state->constructorRenames[$alias] = $canonical;
}

/**
 * @param list<Type> $fieldTypes
 */
function registerPromotedConstructor(
    TypeCheckState $state,
    string $dataName,
    Ast\ConstructorDecl $ctor,
    array $fieldTypes,
): void {
    $argKinds = [];
    foreach ($ctor->fields as $i => $field) {
        $argKind = promotableFieldKind($state, $field->type);
        if ($argKind === null) {
            return;
        }
        $argKinds[] = $argKind;
    }

    $entry = ['data' => $dataName];
    if ($argKinds !== []) {
        $entry['argKinds'] = $argKinds;
    }
    $state->promoted[$ctor->name] = $entry;
}

/**
 * Kind of a constructor field when that constructor is used as a promoted type
 * constructor. Only plain datatype names (e.g. `Associativity`) promote for now.
 */
function promotableFieldKind(TypeCheckState $state, Ast\TypeNode $type): ?Kinds\Kind
{
    if (!$type instanceof Ast\TypeCon) {
        return null;
    }

    if (!isset($state->data[$type->name])) {
        return null;
    }

    return new Kinds\KCon($type->name);
}

function constructorNameExists(TypeCheckState $state, string $name): bool
{
    if (isset($state->env[$name])) {
        return true;
    }

    foreach ($state->data as $data) {
        if (isset($data['constructors'][$name])) {
            return true;
        }
    }

    return false;
}

function validateConstructorFieldTypeVars(TypeCheckState $state, Ast\ConstructorDecl $ctor, array $params): void
{
    $bound = \array_fill_keys($params, true);
    foreach ($ctor->fields as $field) {
        foreach (typeAstVars($field->type) as $name => $_) {
            if (!isset($bound[$name])) {
                throw typeFail($state, "unbound type variable `{$name}` in constructor field", $field->type);
            }
        }
    }
}

function registerTypeSynonym(TypeCheckState $state, Ast\TypeSynonymDecl $decl): void
{
    $name = $decl->name;

    if (isMagicHashName($name)) {
        throw typeFail(
            $state,
            "type synonym `{$name}` uses the reserved `#` suffix (compiler intrinsics only)",
            $decl,
        );
    }

    if (isset($state->typeSynonyms[$name])) {
        throw typeFail($state, "duplicate type synonym `{$name}`", $decl);
    }

    if (isset($state->data[$name])) {
        throw typeFail($state, "type synonym `{$name}` conflicts with data type", $decl);
    }

    // Elaborate RHS under the synonym parameters so free vars are kind-bound.
    $kindScope = Kinds\pushKindScope($state);
    foreach ($decl->params as $param) {
        $state->varKinds[$param] = new Kinds\KType();
    }
    $body = null;
    try {
        $body = astType($state, $decl->type);
    } finally {
        Kinds\restoreKindScope($state, $kindScope);
    }
    // The elaborated body is kept beside the RHS: a module that imports the
    // synonym must expand it the way its own module resolved it, not by
    // re-resolving the RHS names in the importing module's scope.
    $state->typeSynonyms[$name] = synonymEntry($decl->type, $decl->params, $body);
}

function registerClass(TypeCheckState $state, Ast\ClassDecl $decl, string $module = ''): void
{
    $name = $decl->name;

    if (isset($state->classes[$name])) {
        throw typeFail($state, "duplicate class `{$name}`", $decl);
    }

    try {
        $inferredKinds = Kinds\inferClassParamKinds($state, $decl);
    } catch (\InvalidArgumentException $e) {
        throw typeFail($state, "kind error in class `{$name}`: " . $e->getMessage(), $decl);
    }
    $params = [];
    foreach ($decl->params as $param) {
        $params[] = [
            'name' => $param->name,
            'kind' => $param->kind,
            'resolvedKind' => $inferredKinds[$param->name],
        ];
    }

    $paramKinds = [];
    foreach ($params as $param) {
        $paramKinds[$param['name']] = Kinds\classParamKind($state, $param, $decl);
    }
    $kindScope = Kinds\pushKindScope($state);
    $state->varKinds = [...$state->varKinds, ...$paramKinds];

    try {
        $associatedTypes = registerClassAssociatedTypes($state, $decl, $paramKinds);

        $methods = [];
        foreach ($decl->methods as $method) {
            $methodName = $method->name;
            if (isset($methods[$methodName])) {
                throw typeFail($state, \sprintf("duplicate method `%s` in class `%s`", $methodName, $name), $decl);
            }

            [$constraintAsts, $bodyTypeAst] = splitTypeAst($method->type);
            $methodUserConstraints = $constraintAsts !== []
                ? parseConstraints($state, $constraintAsts, $decl)
                : [];
            applyConstraintVarKinds($state, $methodUserConstraints);
            $methodType = astType($state, $bodyTypeAst);
            Kinds\assertKind($state, $methodType, new Kinds\KType(), $state->varKinds, $method->type);
            foreach (array_reverse($methodUserConstraints) as $constraint) {
                $methodType = new TArrow(new TCon('__Dict_' . $constraint->class), $methodType);
            }

            $methods[$methodName] = [
                'type' => $methodType,
                'userConstraints' => $methodUserConstraints,
                'defaultParams' => $method->params,
                'defaultBody' => $method->body,
            ];
            $owner = $state->classMethodOwners[$methodName] ?? null;
            if ($owner !== null && $owner !== $name) {
                $state->classMethodAmbiguities[$methodName] = true;
            } else {
                $state->classMethodOwners[$methodName] = $name;
            }
        }
    } finally {
        Kinds\restoreKindScope($state, $kindScope);
    }

    $state->classes[$name] = [
        'params' => $params,
        'methods' => $methods,
        'superclasses' => $decl->superclasses,
        'associatedTypes' => $associatedTypes,
        // The `{-# MINIMAL … #-}` alternatives, or `[]` for the default reading
        // (every method without a default has to be implemented).
        'minimalGroups' => $decl->minimalGroups,
        // A default body is re-checked at every instance site, in the instance's
        // module; `module` names the scope it must be resolved in.
        'module' => $module,
    ];

    // The polymorphic scheme of every method, as `Class(..)` exports them, so an instance
    // body resolves a method of its own class through the class.
    $state->classes[$name]['methodSchemes'] = classMethodExportSchemes($state, $decl);
}

/**
 * The polymorphic class-method schemes of a class and, transitively, of its
 * superclasses: the methods an instance's body sees for its own class.
 *
 * @return array<string, Scheme>
 */
function ownClassMethodSchemes(TypeCheckState $state, string $className): array
{
    $schemes = [];
    $seen = [];
    $pending = [$className];
    while ($pending !== []) {
        $current = \array_pop($pending);
        if (isset($seen[$current])) {
            continue;
        }
        $seen[$current] = true;
        $info = $state->classes[$current] ?? null;
        if ($info === null) {
            continue;
        }
        foreach ($info['methodSchemes'] ?? [] as $methodName => $scheme) {
            $schemes[$methodName] ??= $scheme;
        }
        foreach ($info['superclasses'] as $super) {
            if ($super instanceof Ast\TypeApp && $super->con instanceof Ast\TypeCon) {
                $pending[] = $super->con->name;
            }
        }
    }

    return $schemes;
}

/**
 * Register associated type families for a class.
 * ClassId ≈ class name in-scope (see `$state->associatedFamilies`).
 *
 * @param array<string, Kinds\Kind> $paramKinds
 * @return array<string, array{params: list<string>, resultKind: Kinds\Kind, kind: Kinds\Kind, class: string}>
 */
function registerClassAssociatedTypes(TypeCheckState $state, Ast\ClassDecl $decl, array $paramKinds): array
{
    $associatedTypes = [];
    foreach ($decl->associatedTypes as $assoc) {
        $famName = $assoc->name;
        if (isset($associatedTypes[$famName])) {
            throw typeFail($state, "duplicate associated type `{$famName}` in class `{$decl->name}`", $assoc);
        }
        if (isset($state->associatedFamilies[$famName])) {
            $other = $state->associatedFamilies[$famName]['class'];
            throw typeFail(
                $state,
                "associated type `{$famName}` already declared in class `{$other}`",
                $assoc,
            );
        }
        if (isset($state->data[$famName]) || isset($state->typeSynonyms[$famName])) {
            throw typeFail($state, "associated type `{$famName}` conflicts with an existing type", $assoc);
        }

        foreach ($assoc->params as $paramName) {
            if (!isset($paramKinds[$paramName])) {
                // Family binders that are not class params default to Type.
                $state->varKinds[$paramName] = new Kinds\KType();
            }
        }

        $resultKind = $assoc->resultKind !== null
            ? Kinds\astKind($state, $assoc->resultKind, $assoc)
            : new Kinds\KType();

        $fullKind = $resultKind;
        for ($i = \count($assoc->params) - 1; $i >= 0; --$i) {
            $p = $assoc->params[$i];
            $argKind = $paramKinds[$p] ?? $state->varKinds[$p] ?? new Kinds\KType();
            $fullKind = new Kinds\KArrow($argKind, $fullKind);
        }

        $entry = [
            'params' => $assoc->params,
            'resultKind' => $resultKind,
            'kind' => $fullKind,
            'class' => $decl->name,
        ];
        $associatedTypes[$famName] = $entry;
        installAssociatedFamily($state, $famName, $entry);
    }

    return $associatedTypes;
}

/**
 * @param array{params: list<string>, resultKind: Kinds\Kind, kind: Kinds\Kind, class: string} $entry
 */
function installAssociatedFamily(TypeCheckState $state, string $famName, array $entry): void
{
    $state->associatedFamilies[$famName] = $entry;
    Kinds\registerTypeKind($state, $famName, $entry['kind']);
    $state->declaredTypeNames[$famName] = true;
}

/** Rebuild associated-family index + kinds from `$state->classes` (imports). */
function syncAssociatedFamiliesFromClasses(TypeCheckState $state): void
{
    foreach ($state->classes as $className => $info) {
        foreach ($info['associatedTypes'] ?? [] as $famName => $entry) {
            if (!isset($entry['class'])) {
                $entry['class'] = $className;
            }
            installAssociatedFamily($state, $famName, $entry);
        }
    }
}

/**
 * Index associated type equations from project/program instance records.
 *
 * @param list<array{class: string, module?: string, associatedEquations?: array<string, array{lhsArgs: list<Ast\TypeNode>, rhs: Ast\TypeNode}>}> $instances
 */
function indexAssociatedEquations(TypeCheckState $state, array $instances): void
{
    $state->associatedEquations = [];
    foreach ($instances as $instance) {
        $className = $instance['class'];
        $module = $instance['module'] ?? '';
        foreach ($instance['associatedEquations'] ?? [] as $famName => $eq) {
            $state->associatedEquations[$className][$famName][] = [
                'lhsArgs' => $eq['lhsArgs'],
                'rhs' => $eq['rhs'],
                'module' => $module,
            ];
        }
    }
}

/**
 * @return array<string, array{lhsArgs: list<Ast\TypeNode>, rhs: Ast\TypeNode}>
 */
function associatedEquationsMapFromDecl(Ast\InstanceDecl $decl): array
{
    $map = [];
    foreach ($decl->associatedEquations as $eq) {
        $map[$eq->name] = [
            'lhsArgs' => $eq->lhsArgs,
            'rhs' => $eq->rhs,
        ];
    }

    return $map;
}

function classMethodExportSchemes(TypeCheckState $state, Ast\ClassDecl $classDecl): array
{
    $className = $classDecl->name;
    $classInfo = $state->classes[$className] ?? null;
    if ($classInfo === null) {
        return [];
    }

    $classConstraints = [
        new Ast\PendingConstraint(
            $className,
            \array_map(
                static fn (array $param): Type => new TVar($param['name']),
                $classInfo['params'],
            ),
        ),
    ];

    applyConstraintVarKinds($state, $classConstraints);

    $savedVarKinds = $state->varKinds;
    foreach ($classInfo['params'] as $param) {
        $state->varKinds[$param['name']] = Kinds\classParamKind($state, $param, $classDecl);
    }

    $schemes = [];
    foreach ($classInfo['methods'] as $methodName => $methodInfo) {
        $methodLocalConstraints = $methodInfo['userConstraints'] ?? [];
        // The scheme keeps the constraints *as written*: expanding them here would make the
        // call site expand them a second time, under a second root identity.
        $freshened = freshenTypeWithConstraints($state, $methodInfo['type'], [
            ...$methodLocalConstraints,
            ...$classConstraints,
        ]);
        $methodType = peelDictArrows(
            $freshened['type'],
            count($methodLocalConstraints),
        );
        $bound = schemeBoundVars($methodType, [], $freshened['constraints']);
        $schemes[$methodName] = scheme(
            prune($state, $methodType),
            $bound,
            $freshened['constraints'],
            count(expandConstraintsWithSuperclasses($state, $freshened['constraints'])),
        )->asClassMethod($className);
    }

    $state->varKinds = $savedVarKinds;

    return $schemes;
}

function registerClassMethodExportSchemes(TypeCheckState $state, Ast\ClassDecl $classDecl): void
{
    foreach (classMethodExportSchemes($state, $classDecl) as $methodName => $scheme) {
        // Instance methods may already have marked `$methodName` in
        // `instanceMethodNames`; still install the polymorphic class-method
        // scheme so bare uses quantify a fresh constraint.
        $existing = $state->env[$methodName] ?? null;
        if ($existing?->classMethod && $existing->class !== $scheme->class) {
            unset($state->env[$methodName]);
            $state->classMethodAmbiguities[$methodName] = true;
            continue;
        }

        if (isset($state->classMethodAmbiguities[$methodName])) {
            continue;
        }

        $state->env[$methodName] = $scheme;
    }
}

function registerInstanceSchemes(TypeCheckState $state, Ast\AstNode $decl): void
{
    $registration = instanceMethodSchemes($state, $decl);
    foreach ($registration['schemes'] as $methodName => $scheme) {
        $state->instanceMethodNames[$methodName] = true;
        // Keep class-method schemes in env for `Class(..)` exports. Instance
        // methods are still recorded for IR/evidence and uniquified later.
        $existing = $state->env[$methodName] ?? null;
        if ($existing?->classMethod || isset($state->localDeclNames[$methodName])) {
            continue;
        }
        $state->env[$methodName] = $scheme;
    }
}

/**
 * Give a signature-less declaration a scheme before any body is checked, so a
 * call between two declarations of the same module (mutually recursive ones
 * especially) has something to type against.
 *
 * The placeholder replaces whatever the name meant before -- an imported scheme
 * of the same spelling in particular: `drop n s = length s` in a module that
 * also imports `Data.List.length` must call the module's own `length`.
 */
function registerInferredFunctionPlaceholder(TypeCheckState $state, Ast\ClassDecl|Ast\FunctionDecl $fn): void
{
    if (isMagicHashName($fn->name)) {
        throw typeFail(
            $state,
            "function `{$fn->name}` uses the reserved `#` suffix (compiler intrinsics only)",
            $fn instanceof Ast\AstNode ? $fn : null,
        );
    }

    $fnType = freshType($state);
    foreach (array_reverse($fn->params) as $_) {
        $fnType = new TArrow(freshType($state), $fnType);
    }

    // A restricted declaration (`x = e`, no signature) is settled at module end: mark it
    // before any body is inferred so a probe cannot abstract its variable.
    if ($fn instanceof Ast\FunctionDecl && isRestrictedDeclaration($fn)) {
        foreach (\array_keys(quantifiedVars($state, $fnType)) as $var) {
            $state->restrictedVars[$var] = true;
        }
    }

    $state->env[$fn->name] = scheme($fnType, [], []);
}

/**
 * Register the scheme a declaration's *inferred* signature spells, before its
 * body is checked. A call to this declaration from a sibling then learns its
 * dictionaries, which is what a mutually recursive pair needs: whichever of the
 * two is checked first must already see the other's dictionary parameters.
 */
function registerInferredSignatureScheme(TypeCheckState $state, Ast\FunctionDecl $fn): void
{
    $signature = $fn->inferredSignatureType;
    if ($signature === null) {
        return;
    }

    $kindScope = Kinds\pushKindScope($state);
    try {
        [$constraintAsts, $bodyTypeAst] = splitTypeAst($signature);
        $userConstraints = parseConstraints($state, $constraintAsts, $fn);
        $constraints = expandConstraintsWithSuperclasses($state, $userConstraints);
        applyConstraintVarKinds($state, $constraints);
        $userFnType = astType($state, $bodyTypeAst);
    } finally {
        Kinds\restoreKindScope($state, $kindScope);
    }

    $state->env[$fn->name] = scheme(
        $userFnType,
        schemeBoundVars($userFnType, $state->env, $userConstraints),
        $userConstraints,
        count($constraints),
    );
}

function registerFunctionScheme(TypeCheckState $state, Ast\FunctionDecl $fn): void
{
    if (isMagicHashName($fn->name)) {
        throw typeFail(
            $state,
            "function `{$fn->name}` uses the reserved `#` suffix (compiler intrinsics only)",
            $fn,
        );
    }

    if (!Ast\hasDeclaredSignature($fn)) {
        throw typeFail($state, "exported function `{$fn->name}` needs a type signature", $fn);
    }

    if (isset($state->instanceMethodNames[$fn->name])) {
        unset($state->env[$fn->name]);
    }

    $state->subst = [];
    [$constraintAsts, $bodyTypeAst] = splitTypeAst($fn->type);
    $userConstraints = parseConstraints($state, $constraintAsts, $fn);
    $constraints = expandConstraintsWithSuperclasses($state, $userConstraints);
    applyConstraintVarKinds($state, $constraints);
    $userFnType = astType($state, $bodyTypeAst);
    $fnType = $userFnType;
    foreach (array_reverse($userConstraints) as $constraint) {
        $fnType = new TArrow(new TCon('__Dict_' . $constraint->class), $fnType);
    }
    $expected = $fnType;
    $paramCount = count($fn->params) + count($userConstraints);
    for ($i = 0; $i < $paramCount; ++$i) {
        if (!$expected instanceof TArrow) {
            throw typeFail($state, "function `{$fn->name}` has too many parameters", $fn);
        }
        $expected = $expected->to;
    }

    $state->env[$fn->name] = scheme(
        $userFnType,
        schemeBoundVars($userFnType, $state->env, $userConstraints),
        $userConstraints,
        count($constraints),
    );
    $wrapper = trivialIntrinsicWrapper($fn);
    if ($wrapper !== null) {
        $state->intrinsicWrappers[$fn->name] = $wrapper;
    }
}

function registerForeignType(TypeCheckState $state, Ast\ForeignTypeDecl $decl): void
{
    if (isset($state->data[$decl->name])) {
        throw typeFail($state, "duplicate type `{$decl->name}`", $decl);
    }

    if (isset($state->typeSynonyms[$decl->name])) {
        throw typeFail($state, "foreign type `{$decl->name}` conflicts with type synonym", $decl);
    }

    if ($decl->hostType === '') {
        throw typeFail($state, 'foreign type host type must be a non-empty string', $decl);
    }

    $state->data[$decl->name] = [
        'params' => [],
        'paramKinds' => [],
        'result' => new TCon($decl->name),
        'constructors' => [],
        'newtype' => false,
        'foreign' => ['backend' => $decl->backend, 'hostType' => $decl->hostType],
    ];

    Kinds\registerTypeKind($state, $decl->name, new Kinds\KType());

    if ($decl->backend === compileBackend()) {
        match ($decl->backend) {
            'jvm' => registerJvmForeignType($decl->name, $decl->hostType),
            'dotnet' => registerDotNetForeignType($decl->name, $decl->hostType),
            default => null,
        };
    }
}
