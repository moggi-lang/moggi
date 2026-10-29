<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;


use Moggi\Semantics\Kernel;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Backend\DotNet\Foreign\registerDotNetForeignType;
use function Moggi\Backend\Jvm\Foreign\registerJvmForeignType;
use function Moggi\IR\Projection\parse as projectionParse;
use function Moggi\IR\Projection\tupleArity as projectionTupleArity;
use function Moggi\Semantics\IntrinsicRegistry\isMagicHashName;
use function Moggi\Semantics\StBoundary\isTransparentPrimopWrapper;
use function Moggi\Semantics\TypeExpr\scheme;

require_once __DIR__ . '/type_core.php';
require_once __DIR__ . '/constraints.php';
require_once __DIR__ . '/infer.php';
require_once __DIR__ . '/../st_boundary.php';

/**
 * A type synonym's declaration, with its kind.
 *
 * The kind travels with the entry because a module that imports the synonym
 * cannot recompute it: `type IO = IO#` expands to the machine type `IO`, whose
 * kind is the kernel's, and the importer of `System.IO.Base` (which names only
 * the synonym) has no `IO#` of its own to ask.
 *
 * @param list<string> $params
 * @return array{params: list<string>, rhs: Ast\TypeNode, body: ?Type, kind: ?Kinds\Kind}
 */
function synonymEntry(
    Ast\TypeNode $rhs,
    array $params = [],
    ?Type $body = null,
    ?Kinds\Kind $kind = null,
): array {
    return ['params' => $params, 'rhs' => $rhs, 'body' => $body, 'kind' => $kind];
}

/**
 * Put an imported type synonym's kind in the kind environment.
 *
 * {@see registerTypeSynonym} records the kind where the synonym is declared;
 * this is how it reaches a module that only imports the name.
 *
 * @param array<string, mixed> $info a `typeSynonyms` entry
 * @return bool true when this call was what put the kind there
 */
function installTypeSynonymKind(TypeCheckState $state, string $name, array $info): bool
{
    $kind = $info['kind'] ?? null;
    if (! $kind instanceof Kinds\Kind || isset($state->kindEnv[Kinds\canonicalTypeConName($name)])) {
        return false;
    }

    Kinds\registerTypeKind($state, $name, $kind);

    return true;
}

/**
 * The `__InstanceHead` marker is the synthetic type constructor that carries a
 * multi-parameter instance head (`instance Both Int Bool`). User type
 * constructors are lexed as upper-case `ConId`s, so a name starting with `_` can
 * never be spelled in source; this rejects it at registration anyway, so the
 * marker cannot be shadowed if an AST is built programmatically.
 */
function assertNotInstanceHeadMarkerName(
    TypeCheckState $state,
    string $name,
    Ast\AstNode $at,
    string $what,
): void {
    if ($name === Ast\instanceHeadMarker()) {
        throw typeFail(
            $state,
            "`{$name}` is reserved for the compiler and cannot name a {$what}",
            $at,
        );
    }
}

function registerData(TypeCheckState $state, Ast\DataDecl $decl): void
{
    assertNotInstanceHeadMarkerName($state, $decl->name, $decl, 'data type');

    if (isMagicHashName($decl->name)
        || $decl->name === Kinds\canonicalTypeConName('IO#')
        || $decl->name === Kinds\canonicalTypeConName('IOArray#')
        || $decl->name === Kinds\canonicalTypeConName('ST#')
        || $decl->name === Kinds\canonicalTypeConName('STRef#')
        || $decl->name === Kinds\canonicalTypeConName('STArray#')
        || $decl->name === Kinds\canonicalTypeConName('Array#')
        || $decl->name === Kinds\canonicalTypeConName('RealWorld#')
    ) {
        if (! isTransparentPrimopWrapper($decl)) {
            throw typeFail(
                $state,
                "`{$decl->name}` is a compiler primitive and cannot be redefined",
                $decl,
            );
        }
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

    if (isset($state->typeSynonyms[$decl->name])) {
        throw typeFail($state, "data type `{$decl->name}` conflicts with type synonym", $decl);
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
        Kinds\peelParamKinds($dataKind, \count($params)),
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
            foreach (\array_reverse($fieldTypes) as $fieldType) {
                $ctorType = new TArrow($fieldType, $ctorType);
            }

            $bound = $params;
            $state->env[$ctor->name] = scheme($ctorType, $bound);

            registerPromotedConstructor($state, $decl->name, $ctor, $fieldTypes);
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
    if (! $type instanceof Ast\TypeCon) {
        return null;
    }

    if (! isset($state->data[$type->name])) {
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
            if (! isset($bound[$name])) {
                throw typeFail($state, "unbound type variable `{$name}` in constructor field", $field->type);
            }
        }
    }
}

function registerTypeSynonym(TypeCheckState $state, Ast\TypeSynonymDecl $decl): void
{
    $name = $decl->name;

    assertNotInstanceHeadMarkerName($state, $name, $decl, 'type synonym');

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

    $kindScope = Kinds\pushKindScope($state);
    foreach ($decl->params as $param) {
        $state->varKinds[$param] = new Kinds\KType();
    }
    $body = null;
    $kind = null;

    try {
        $body = astType($state, $decl->type);
        $kind = Kinds\kindOfType($state, $body);
        foreach (\array_reverse($decl->params) as $param) {
            $kind = new Kinds\KArrow($state->varKinds[$param], $kind);
        }
    } finally {
        Kinds\restoreKindScope($state, $kindScope);
    }

    Kinds\registerTypeKind($state, $name, $kind);

    $state->typeSynonyms[$name] = synonymEntry($decl->type, $decl->params, $body, $kind);
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
                throw typeFail($state, \sprintf('duplicate method `%s` in class `%s`', $methodName, $name), $decl);
            }
            assertNotGeneratedName($state, $methodName, $decl, 'method');

            [$constraintAsts, $bodyTypeAst] = splitTypeAst($method->type);
            $methodUserConstraints = $constraintAsts !== []
                ? parseConstraints($state, $constraintAsts, $decl)
                : [];
            applyConstraintVarKinds($state, $methodUserConstraints);
            $methodType = astType($state, $bodyTypeAst);
            Kinds\assertKind($state, $methodType, new Kinds\KType(), $state->varKinds, $method->type);
            foreach (\array_reverse($methodUserConstraints) as $constraint) {
                $methodType = new TArrow(new TCon('__Dict_' . $constraint->class), $methodType);
            }

            $constraintArgTypes = [];
            foreach ($methodUserConstraints as $constraint) {
                foreach ($constraint->args as $argument) {
                    $constraintArgTypes[] = $argument;
                }
            }

            $methods[$methodName] = [
                'type' => $methodType,
                'userConstraints' => $methodUserConstraints,
                'kinds' => Kinds\declarationParameterKinds($state, $methodType, $constraintArgTypes),
                'defaultParams' => $method->params,
                'defaultBody' => $method->body,
                'line' => $method->body?->line ?? 0,
                'col' => $method->body?->col ?? 0,
                'endCol' => $method->body?->endCol ?? 0,
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
        'minimalGroups' => $decl->minimalGroups,
        'roles' => impliedRoles($methods, $decl->params),
        'module' => $module,
    ];
    $state->classParamKindsCache = null;
    $state->roleMethodsCache = null;

    $state->classes[$name]['methodSchemes'] = classMethodExportSchemes($state, $decl);
}

/**
 * Which of a class's methods imply which language role, read off the declared signatures.
 *
 * A role is the language's, not the library's: the method that *is* the bind is the one whose type says
 * so — `m a -> (a -> m b) -> m b` — and that is the whole statement, so no declaration has to carry a
 * marker, no stage spells an operator, and the library stays free to name its methods what it likes.
 * The roles the language mandates are {@see \Moggi\Semantics\Kernel}'s keys.
 *
 * Only the shapes' shape is fixed here, and only for a single-parameter class: a role has to be
 * unambiguous to be a role.
 *
 * @param array<string, array{type: Type}> $methods the class's registered methods
 * @param list<Ast\ClassParam> $params the class's own parameters
 * @return array<string, list<string>> role => the methods that imply it
 */
function impliedRoles(array $methods, array $params): array
{
    if (\count($params) !== 1) {
        return [];
    }

    $roles = [];
    foreach ($methods as $methodName => $info) {
        $role = impliedRole($methodName, $info['type'], $params[0]->name);
        if ($role !== null) {
            $roles[$role][] = $methodName;
        }
    }

    return $roles;
}

/**
 * The role one method's signature implies, or null when it implies none.
 *
 * The bind takes the class's own type applied to a variable and a function from that variable into the
 * same application: `m a -> (a -> m b) -> m b`. The unit takes a variable and produces the class's type
 * applied to it: `a -> f a`. The literal conversion takes the kernel's integer and produces the class's
 * own parameter: `Integer# -> a`.
 */
function impliedRole(string $method, Type $type, string $param): ?string
{
    if ($method === '' || ! $type instanceof TArrow) {
        return null;
    }

    $applied = static fn (?Type $t): ?string => $t instanceof TVarApp
        && $t->name === $param
        && \count($t->args) === 1
        && $t->args[0] instanceof TVar
        ? $t->args[0]->name
        : null;

    if ($type->from instanceof TVar
        && $applied($type->to) === $type->from->name
    ) {
        return Kernel\pureRole();
    }

    if ($type->from instanceof TCon
        && $type->from->name === Kinds\canonicalTypeConName('Integer#')
        && $type->to instanceof TVar
        && $type->to->name === $param
    ) {
        return Kernel\literalRole();
    }

    $outer = $applied($type->from);
    if ($outer === null || ! $type->to instanceof TArrow || ! $type->to->from instanceof TArrow) {
        return null;
    }
    $continuation = $type->to->from;
    if (! $continuation->from instanceof TVar || $continuation->from->name !== $outer) {
        return null;
    }
    $inner = $applied($continuation->to);
    if ($inner === null || $inner === $outer || $applied($type->to->to) !== $inner) {
        return null;
    }

    return Kernel\bindRole();
}

/**
 * The method this class's declaration implies a language role with, or null when the role is not its.
 *
 * @see \Moggi\Semantics\Kernel for the roles the language mandates
 */
function classMethodRole(TypeCheckState $state, string $class, string $method): ?string
{
    foreach ($state->classes[$class]['roles'] ?? [] as $role => $methods) {
        if (\in_array($method, $methods, true)) {
            return $role;
        }
    }

    return null;
}

/**
 * The method a language role is fulfilled by, asked of the classes in scope.
 *
 * The reader asks for the role and never for a spelling; a role nothing implies is `null`.
 */
function classMethodWithRole(TypeCheckState $state, string $role): ?string
{
    return roleEntry($state, $role)['method'] ?? null;
}

/**
 * The class whose declaration implies a language role, or null when no class in scope implies it.
 *
 * A literal's constraint is a class, not a method: the role that names the method a literal is converted
 * with names the class that owns the conversion.
 */
function classWithRole(TypeCheckState $state, string $role): ?string
{
    return roleEntry($state, $role)['class'] ?? null;
}

/**
 * The class and method a role is implied by in the classes in scope, or nothing when undeclared.
 *
 * The table is built once per class table ({@see TypeCheckState::$roleMethodsCache}, the shape
 * {@see classParamKindsCache} already had). The classes are consulted in the table's own order, which is
 * dependency order, so the library — the earliest dependency of everything — is what a role resolves to,
 * and a declaration of the project's own cannot quietly take a language role over. A first implication
 * decides, and every later one is ignored.
 *
 * @return array{class?: string, method?: string}
 */
function roleEntry(TypeCheckState $state, string $role): array
{
    if ($state->roleMethodsCache === null) {
        $methods = [];
        $classes = [];
        foreach ($state->classes as $className => $info) {
            foreach ($info['roles'] ?? [] as $id => $roleMethods) {
                foreach ($roleMethods as $method) {
                    if (! isset($classes[$id])) {
                        $methods[$id] = $method;
                        $classes[$id] = $className;
                    }
                }
            }
        }
        $state->roleMethodsCache = ['methods' => $methods, 'classes' => $classes];
    }

    if (! isset($state->roleMethodsCache['classes'][$role])) {
        return [];
    }

    return [
        'class' => $state->roleMethodsCache['classes'][$role],
        'method' => $state->roleMethodsCache['methods'][$role],
    ];
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
            if (! isset($paramKinds[$paramName])) {
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
            if (! isset($entry['class'])) {
                $entry['class'] = $className;
            }
            installAssociatedFamily($state, $famName, $entry);
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
        $freshened = freshenTypeWithConstraints($state, $methodInfo['type'], [
            ...$methodLocalConstraints,
            ...$classConstraints,
        ]);
        $methodType = peelDictArrows(
            $freshened['type'],
            \count($methodLocalConstraints),
        );
        $bound = schemeBoundVars($methodType, [], $freshened['constraints']);
        $methodDicts = expandConstraintsWithSuperclasses($state, $freshened['constraints']);
        $schemes[$methodName] = scheme(
            prune($state, $methodType),
            $bound,
            $freshened['constraints'],
            \count($methodDicts),
            $methodDicts,
        )->asClassMethod($className);
    }

    $state->varKinds = $savedVarKinds;

    return $schemes;
}

function registerClassMethodExportSchemes(TypeCheckState $state, Ast\ClassDecl $classDecl): void
{
    foreach (classMethodExportSchemes($state, $classDecl) as $methodName => $scheme) {
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

/**
 * Give a signature-less declaration a scheme before any body is checked, so a
 * call between two declarations of the same module (mutually recursive ones
 * especially) has something to type against.
 *
 * The placeholder replaces whatever the name meant before -- an imported scheme
 * of the same spelling in particular: `drop n s = length s` in a module that
 * also imports `Data.List.length` must call the module's own `length`.
 */
/**
 * A declaration may not enter a namespace the compiler generates names into.
 *
 * Two kinds of spelling are written by the compiler rather than by a program. `IR\Callables\workerName()`
 * spells an action entry's worker `__w_<entry>`, and lowering spells a record field read `__fieldN`, a
 * tuple field read `__tuple_fieldN` and a tuple construction `__tupleN`. Each is read back by its
 * spelling where the declaration that wrote it is not at hand — the optimizer for a worker, the three
 * emitters for a projection — so a source declaration in one of them would give that spelling two
 * meanings. Refusing the name here is what keeps the reading exact.
 *
 * The projection spellings accepted are exactly `IR\Projection\parse()`'s and `tupleArity()`'s own, the
 * two functions every reader already asks, so the reservation cannot drift from what is read.
 *
 * A class method is a declaration too: an instance implements it, but the name is written once, in
 * the class, so that is where it is refused.
 */
function assertNotGeneratedName(
    TypeCheckState $state,
    string $name,
    Ast\AstNode $at,
    string $declarationKind = 'function',
): void {
    $prefix = Ast\generatedWorkerPrefix();
    if (\str_starts_with($name, $prefix)) {
        throw typeFail(
            $state,
            "{$declarationKind} `{$name}` is in the compiler's generated worker namespace (`{$prefix}`), which no declaration may name",
            $at,
        );
    }

    if (projectionParse($name) !== null || projectionTupleArity($name) !== null) {
        throw typeFail(
            $state,
            "{$declarationKind} `{$name}` is in the compiler's generated projection namespace (`__fieldN`, `__tuple_fieldN`, `__tupleN`), which no declaration may name",
            $at,
        );
    }
}

function registerInferredFunctionPlaceholder(TypeCheckState $state, Ast\ClassDecl|Ast\FunctionDecl $fn): void
{
    if (isMagicHashName($fn->name)) {
        throw typeFail(
            $state,
            "function `{$fn->name}` uses the reserved `#` suffix (compiler intrinsics only)",
            $fn instanceof Ast\AstNode ? $fn : null,
        );
    }

    if ($fn instanceof Ast\FunctionDecl) {
        assertNotGeneratedName($state, $fn->name, $fn);
    }

    $fnType = freshType($state);
    foreach (\array_reverse($fn->params) as $_) {
        $fnType = new TArrow(freshType($state), $fnType);
    }

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
 *
 * The intrinsic wrapper goes with the scheme: a declaration that is a thin
 * binding over a `#` primop has to publish that in the interface, because an
 * importer rewrites its uses to the primop and never checks the wrapper's body.
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
        \count($constraints),
        $constraints,
    );

    $wrapper = trivialIntrinsicWrapper($fn);
    if ($wrapper !== null) {
        $state->intrinsicWrappers[$fn->name] = $wrapper;
    }
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

    assertNotGeneratedName($state, $fn->name, $fn);

    if (! Ast\hasDeclaredSignature($fn)) {
        throw typeFail($state, "exported function `{$fn->name}` needs a type signature", $fn);
    }

    if (isset($state->instanceMethodNames[$fn->name])) {
        unset($state->env[$fn->name]);
    }

    $state->subst = [];
    $kindScope = Kinds\pushKindScope($state);

    try {
        [$constraintAsts, $bodyTypeAst] = splitTypeAst($fn->type);
        $userConstraints = parseConstraints($state, $constraintAsts, $fn);
        $constraints = expandConstraintsWithSuperclasses($state, $userConstraints);
        applyConstraintVarKinds($state, $constraints);
        $userFnType = astType($state, $bodyTypeAst);
    } finally {
        Kinds\restoreKindScope($state, $kindScope);
    }
    $fnType = $userFnType;
    foreach (\array_reverse($userConstraints) as $constraint) {
        $fnType = new TArrow(new TCon('__Dict_' . $constraint->class), $fnType);
    }
    $expected = $fnType;
    $paramCount = \count($fn->params) + \count($userConstraints);
    for ($i = 0; $i < $paramCount; ++$i) {
        if (! $expected instanceof TArrow) {
            throw typeFail($state, "function `{$fn->name}` has too many parameters", $fn);
        }
        $expected = $expected->to;
    }

    $state->env[$fn->name] = scheme(
        $userFnType,
        schemeBoundVars($userFnType, $state->env, $userConstraints),
        $userConstraints,
        \count($constraints),
        $constraints,
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

    if ($decl->backend === $state->compileBackend) {
        match ($decl->backend) {
            'jvm' => registerJvmForeignType($decl->name, $decl->hostType),
            'dotnet' => registerDotNetForeignType($decl->name, $decl->hostType),
            default => null,
        };
    }
}
