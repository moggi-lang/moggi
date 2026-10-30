<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TChar;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\TInt16;
use Moggi\Semantics\TypeExpr\TInt32;
use Moggi\Semantics\TypeExpr\TInt64;
use Moggi\Semantics\TypeExpr\TInt8;
use Moggi\Semantics\TypeExpr\TStr;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TWord;
use Moggi\Semantics\TypeExpr\TWord16;
use Moggi\Semantics\TypeExpr\TWord32;
use Moggi\Semantics\TypeExpr\TWord64;
use Moggi\Semantics\TypeExpr\TWord8;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;
use function Moggi\Patterns\Walk\patternDuplicateBinder;
use function Moggi\Patterns\Walk\patternVariableNames;
use function Moggi\Patterns\Walk\patternVariableNamesOrdered;
use function Moggi\Semantics\IntrinsicRegistry\shortCircuitPrimopId;
use function Moggi\Semantics\Kernel\literalRole;
use function Moggi\Semantics\TypeExpr\scheme;
use function Moggi\Semantics\TypeExpr\schemeFreeTypeVars;

/**
 * Whether the literal's type compares with a host primitive, i.e. the pattern
 * test the lowering emits (`$x === 0`) is already the literal's own.
 */
function literalPatternHostPrimitive(Type $type): bool
{
    $type = canonicalPrimitiveType($type);

    return $type instanceof TInt
        || $type instanceof TInt8
        || $type instanceof TInt16
        || $type instanceof TInt32
        || $type instanceof TInt64
        || $type instanceof TWord
        || $type instanceof TWord8
        || $type instanceof TWord16
        || $type instanceof TWord32
        || $type instanceof TWord64;
}
/**
 * A numeric literal in a pattern is an overloaded literal, not an `Int` pattern:
 * `f 0 = "zero"` has to mean `Integral i => i -> String` and test `i`'s own zero,
 * so the same clause works at every `Integral` instance. base spells that out by
 * hand as an equality against the literal; this is the same thing.
 *
 * At a host primitive (`Int`, `Int64`, `Word8`, `String`, …) the pattern test the
 * lowering already emits is the literal's own, so the literal stays in the
 * pattern and nothing changes for it.
 *
 * @param array<string, Scheme> $env
 * @return array<string, Scheme>
 */
function overloadedLiteralPatterns(TypeCheckState $state, Ast\Alt $alt, array $env): array
{
    $tests = [];
    $alt->pattern = overloadedLiteralPatternNode($state, $alt->pattern, $tests, $env);
    if ($tests === []) {
        return $env;
    }

    $at = $alt->pattern;
    $alt->body = new Ast\GuardsExpr(
        [new Ast\Guarded(literalPatternCondition($tests), $alt->body, $at->line, $at->col, $at->endCol)],
        $at->line,
        $at->col,
        $at->endCol,
    );

    return $env;
}

/**
 * Whether a numeric literal pattern is an *overloaded* literal.
 *
 * `f 0 = …` means what it means in `base`: the pattern is `0` at the pattern's
 * own type, tested with that type's `==`, so the clause works at every `Num`
 * instance and its type is whatever the use sites pin it to. A pattern at a
 * machine type keeps the host comparison instead (see
 * {@see literalPatternHostPrimitive}).
 */
function literalPatternIsOverloaded(TypeCheckState $state, Type $type): bool
{
    return classWithRole($state, literalRole()) !== null && ! literalPatternHostPrimitive($type);
}

function overloadedLiteralPatternNode(TypeCheckState $state, Ast\AstNode $pattern, array &$tests, array &$env): Ast\AstNode
{
    if ($pattern instanceof Ast\PatLit && \is_int($pattern->value)) {
        $type = prune($state, $pattern->inferredInternalType ?? throw typeFail(
            $state,
            'literal pattern has no inferred type',
            $pattern,
        ));
        if (! literalPatternIsOverloaded($state, $type)) {
            return $pattern;
        }

        $binder = new Ast\PatVar('__litpat', $pattern->line, $pattern->col, $pattern->endCol);
        $binder->binderId = ++$state->nextBinderId;
        $name = '__litpat' . $binder->binderId;
        $binder->name = $name;
        $binder->inferredInternalType = $type;
        $binder->inferredType = internalTypeToAst($type, $state->subst);
        $state->registerBinder($name, $binder->binderId);
        $env[binderKey($binder->binderId)] = scheme($type, []);
        $literal = new Ast\IntegerLit($pattern->value, $pattern->line, $pattern->col, $pattern->endCol);
        $literal->digits = $pattern->digits;
        $tests[] = new Ast\Infix(
            '==',
            new Ast\Variable($name, $pattern->line, $pattern->col, $pattern->endCol),
            $literal,
            $pattern->line,
            $pattern->col,
            $pattern->endCol,
        );

        return $binder;
    }

    if ($pattern instanceof Ast\PatCons) {
        $pattern->head = overloadedLiteralPatternNode($state, $pattern->head, $tests, $env);
        $pattern->tail = overloadedLiteralPatternNode($state, $pattern->tail, $tests, $env);

        return $pattern;
    }

    if ($pattern instanceof Ast\PatCon) {
        foreach ($pattern->args as $i => $arg) {
            $pattern->args[$i] = overloadedLiteralPatternNode($state, $arg, $tests, $env);
        }

        return $pattern;
    }

    if ($pattern instanceof Ast\PatTuple) {
        foreach ($pattern->elements as $i => $element) {
            $pattern->elements[$i] = overloadedLiteralPatternNode($state, $element, $tests, $env);
        }

        return $pattern;
    }

    if ($pattern instanceof Ast\PatRecord) {
        foreach ($pattern->fields as $field) {
            $field->pattern = overloadedLiteralPatternNode($state, $field->pattern, $tests, $env);
        }

        return $pattern;
    }

    return $pattern;
}

/**
 * The condition all of an alternative's literal tests have to satisfy.
 *
 * The conjunction is `boolAnd#` rather than `&&` on purpose: the node is built
 * here, where the module's `&&` need not be in scope.
 *
 * @param list<Ast\Infix> $tests
 */
function literalPatternCondition(array $tests): Ast\AstNode
{
    $condition = $tests[0];
    foreach (\array_slice($tests, 1) as $test) {
        $condition = new Ast\Infix('&&', $condition, $test, $test->line, $test->col, $test->endCol);
        $condition->compilerIntrinsic = shortCircuitPrimopId('bool-and');
    }

    return $condition;
}

/**
 * @param array<int, Ast\Binding> $bindings
 */
function assertBindingGroupLinear(TypeCheckState $state, array $bindings): void
{
    $seen = [];
    foreach ($bindings as $binding) {
        assertPatternLinear($state, $binding->pattern);
        foreach (patternVariableNamesOrdered($binding->pattern) as $name) {
            if (isset($seen[$name])) {
                throw typeFail(
                    $state,
                    "duplicate binding `{$name}` in the same group",
                    $binding->pattern,
                );
            }
            $seen[$name] = true;
        }
    }
}

/** @param array<int, Ast\AstNode> $params */
function assertParamListLinear(TypeCheckState $state, array $params): void
{
    $seen = [];
    foreach ($params as $param) {
        assertPatternLinear($state, $param);
        foreach (patternVariableNamesOrdered($param) as $name) {
            if (isset($seen[$name])) {
                throw typeFail($state, "duplicate binding `{$name}` in the same group", $param);
            }
            $seen[$name] = true;
        }
    }
}

function assertPatternLinear(TypeCheckState $state, Ast\AstNode $pattern): void
{
    $dup = patternDuplicateBinder($pattern);
    if ($dup !== null) {
        throw typeFail($state, "conflicting definitions for `{$dup[0]}`", $dup[1]);
    }
}

/**
 * Allocate the identity of every variable a pattern binds, and make it the spelling's meaning in the
 * current frame — before anything is checked against those bindings.
 *
 * A recursive group has to refer to its own members before their bodies exist, so the identities are
 * allocated here rather than by {@see bindPattern} on the way out. Declaring them first is what a
 * name-keyed pre-binding cannot do: a member's RHS then resolves its own name (or a sibling's) to the
 * group's binder, not to whatever same-spelling binding happens to be in scope outside it.
 *
 * Binding the site later reuses the identity (`bindPattern` allocates only when there is none), so a
 * binding site has one identity however many times it is visited.
 *
 * @return array<string, int> spelling => identity, in left-to-right source order
 */
function declarePatternBinders(TypeCheckState $state, Ast\AstNode $pattern, array &$env): array
{
    $declared = [];
    $walk = static function (Ast\AstNode $pattern) use (&$walk, $state, &$env, &$declared): void {
        match ($pattern::class) {
            Ast\PatWild::class,
            Ast\PatLit::class,
            Ast\PatChar::class,
            Ast\PatNil::class => null,
            Ast\PatVar::class => (static function () use ($pattern, $state, &$env, &$declared): void {
                $pattern->binderId ??= ++$state->nextBinderId;
                $state->registerBinder($pattern->name, $pattern->binderId);
                $entry = scheme(freshType($state), []);
                $entry->binderId = $pattern->binderId;
                $env[binderKey($pattern->binderId)] = $entry;
                $declared[$pattern->name] = $pattern->binderId;
            })(),
            Ast\PatCon::class => (static function () use ($pattern, $walk): void {
                foreach ($pattern->args as $arg) {
                    $walk($arg);
                }
            })(),
            Ast\PatRecord::class => (static function () use ($pattern, $walk): void {
                foreach ($pattern->fields as $field) {
                    $walk($field->pattern);
                }
            })(),
            Ast\PatTuple::class => (static function () use ($pattern, $walk): void {
                foreach ($pattern->elements as $element) {
                    $walk($element);
                }
            })(),
            Ast\PatCons::class => (static function () use ($pattern, $walk): void {
                $walk($pattern->head);
                $walk($pattern->tail);
            })(),
            default => throw typeFail($state, 'unsupported pattern ' . $pattern::class),
        };
    };
    $walk($pattern);

    return $declared;
}

function bindPattern(TypeCheckState $state, Ast\AstNode $pattern, Type $expected, array $env, bool $generalize = false, array $constraints = []): array
{
    return match ($pattern::class) {
        Ast\PatWild::class => [$expected, $env],
        Ast\PatVar::class => (static function () use ($state, $pattern, $expected, $env, $generalize, $constraints): array {
            $pattern->binderId ??= ++$state->nextBinderId;
            $state->registerBinder($pattern->name, $pattern->binderId);
            [$type, $env] = bindVar($state, $pattern->binderId, $pattern->name, $expected, $env, $generalize, $constraints);
            if (isset($env[binderKey($pattern->binderId)])) {
                $env[binderKey($pattern->binderId)]->binderId = $pattern->binderId;
            }
            $pattern->inferredInternalType = prune($state, $type);
            $pattern->inferredType = internalTypeToAst(prune($state, $type), $state->subst);

            return [$type, $env];
        })(),
        Ast\PatLit::class => (static function () use ($state, $pattern, $expected, $env): array {
            if (\is_string($pattern->value)) {
                unify($state, $expected, new TStr());
            }
            $pattern->inferredInternalType = prune($state, $expected);
            $pattern->inferredType = internalTypeToAst(prune($state, $expected), $state->subst);

            return [$expected, $env];
        })(),
        Ast\PatChar::class => (static function () use ($state, $expected, $env): array {
            unify($state, $expected, new TChar());

            return [$expected, $env];
        })(),
        Ast\PatCon::class => bindConPattern($state, $pattern, $expected, $env, $generalize, $constraints),
        Ast\PatRecord::class => bindRecordPattern($state, $pattern, $expected, $env, $generalize, $constraints),
        Ast\PatTuple::class => bindTuplePattern($state, $pattern, $expected, $env, $generalize, $constraints),
        Ast\PatNil::class => bindNilPattern($state, $expected, $env),
        Ast\PatCons::class => bindConsPattern($state, $pattern, $expected, $env, $generalize, $constraints),
        default => throw typeFail($state, 'unsupported pattern ' . $pattern::class),
    };
}

/**
 * Bind one variable to the environment **under the identity the checker allocated for it**.
 *
 * `$name` is carried only for the diagnostics a scheme does not hold; storage is keyed by the
 * binder, so binding a second variable of the same spelling adds an entry instead of replacing the
 * first one. Reachability is decided once, by the scope frame (`$state->registerBinder()`), not by
 * which write to a name-keyed map happened last.
 *
 * @param array<int|string, Scheme> $env
 * @return array{0: Type, 1: array<int|string, Scheme>}
 */
function bindVar(TypeCheckState $state, int $binder, string $name, Type $type, array $env, bool $generalize, array $constraints = []): array
{
    if ($generalize) {
        $type = prune($state, $type);
        $constraints = refreshConstraintArgs($state, $constraints);
        $env[binderKey($binder)] = scheme($type, schemeBoundVars($type, $env, $constraints), $constraints);
    } else {
        $env[binderKey($binder)] = scheme(prune($state, $type), []);
    }

    return [$type, $env];
}

function generalizePatternBindings(TypeCheckState $state, Ast\AstNode $pattern, array $env, array $constraints = []): array
{
    $monomorphicEnv = pruneEnvSchemes($state, $env);
    foreach (patternVariableNames($pattern) as $name) {
        $binder = $state->resolveBinder($name);
        if ($binder === null || ! isset($monomorphicEnv[binderKey($binder)])) {
            continue;
        }

        $type = prune($state, $monomorphicEnv[binderKey($binder)]->type);
        $schemeEnv = $monomorphicEnv;
        unset($schemeEnv[binderKey($binder)]);
        $refreshedConstraints = refreshConstraintArgs($state, $constraints);
        $newScheme = scheme($type, schemeBoundVars($type, $schemeEnv, $refreshedConstraints), $refreshedConstraints);
        $newScheme->binderId = $monomorphicEnv[binderKey($binder)]->binderId
            ?? $env[binderKey($binder)]->binderId
            ?? null;
        $env[binderKey($binder)] = $newScheme;
    }

    return $env;
}

function pruneEnvSchemes(TypeCheckState $state, array $env): array
{
    foreach ($env as $name => $entry) {
        $type = prune($state, $entry->type);
        $constraints = refreshConstraintArgs($state, $entry->constraints);

        $env[$name] = $entry->withPruned(
            $type,
            $constraints,
            schemeFreeTypeVars($type, $entry->bound, $constraints),
            refreshConstraintArgs($state, $entry->dicts),
        );
    }

    return $env;
}

function bindConPattern(TypeCheckState $state, Ast\PatCon $pattern, Type $expected, array $env, bool $generalize, array $constraints = []): array
{
    $ctorScheme = $state->env[$pattern->name] ?? throw typeFail($state, "unknown constructor {$pattern->name}", $pattern);
    $ctorType = instantiate($state, $ctorScheme);
    $result = $ctorType;

    foreach ($pattern->args as $arg) {
        if (! $result instanceof TArrow) {
            throw typeFail($state, "constructor {$pattern->name} applied to too many arguments", $pattern);
        }
        [$argType, $env] = bindPattern($state, $arg, $result->from, $env, generalize: false, constraints: $constraints);
        $result = $result->to;
    }

    unify($state, $expected, $result);
    if ($generalize) {
        $env = generalizePatternBindings($state, $pattern, $env, $constraints);
    }

    return [$expected, $env];
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindNilPattern(TypeCheckState $state, Type $expected, array $env): array
{
    unify($state, $expected, new TCon('List', [freshType($state)]));

    return [$expected, $env];
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindConsPattern(TypeCheckState $state, Ast\PatCons $pattern, Type $expected, array $env, bool $generalize, array $constraints = []): array
{
    $elemType = freshType($state);
    unify($state, $expected, new TCon('List', [$elemType]));
    [$_, $env] = bindPattern($state, $pattern->head, $elemType, $env, generalize: false, constraints: $constraints);
    [$_, $env] = bindPattern($state, $pattern->tail, new TCon('List', [$elemType]), $env, generalize: false, constraints: $constraints);

    if ($generalize) {
        $env = generalizePatternBindings($state, $pattern, $env, $constraints);
    }

    return [$expected, $env];
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindTuplePattern(TypeCheckState $state, Ast\PatTuple $pattern, Type $expected, array $env, bool $generalize, array $constraints = []): array
{
    $parts = [];
    foreach ($pattern->elements as $element) {
        $part = freshType($state);
        [$_, $env] = bindPattern($state, $element, $part, $env, generalize: false, constraints: $constraints);
        $parts[] = $part;
    }

    $tupleType = \count($parts) === 0 ? new TUnit() : new TCon('Tuple' . \count($parts), $parts);
    unify($state, $expected, $tupleType);
    if ($generalize) {
        $env = generalizePatternBindings($state, $pattern, $env, $constraints);
    }

    return [$expected, $env];
}

/**
 * @param array<string, Scheme> $env
 * @return array{0: Type, 1: array<string, Scheme>}
 */

function bindRecordPattern(TypeCheckState $state, Ast\PatRecord $pattern, Type $expected, array $env, bool $generalize, array $constraints = []): array
{
    $meta = lookupConstructorMeta($state, $pattern->name)
        ?? throw typeFail($state, "unknown constructor {$pattern->name}", $pattern);
    $subpatterns = recordPatternFields($state, $pattern, $meta);

    $ctorScheme = $state->env[$pattern->name] ?? throw typeFail($state, "unknown constructor {$pattern->name}", $pattern);
    $ctorType = instantiate($state, $ctorScheme);
    $result = $ctorType;

    foreach ($subpatterns as $subpattern) {
        if (! $result instanceof TArrow) {
            throw typeFail($state, "constructor {$pattern->name} applied to too many arguments", $pattern);
        }
        [$_, $env] = bindPattern($state, $subpattern, $result->from, $env, generalize: false, constraints: $constraints);
        $result = $result->to;
    }

    unify($state, $expected, $result);
    if ($generalize) {
        $env = generalizePatternBindings($state, $pattern, $env, $constraints);
    }

    return [$expected, $env];
}

/**
 * @param array{dataName: string, name: string, fields: array<int, string>, fieldTypes: array<int, array<string, mixed>>} $meta
 * @return list<Ast\AstNode> sub-patterns in constructor field order
 */
function recordPatternFields(TypeCheckState $state, Ast\PatRecord $pattern, array $meta): array
{
    $byName = [];
    foreach ($pattern->fields as $field) {
        if (isset($byName[$field->name])) {
            throw typeFail($state, "duplicate field `{$field->name}` in pattern", $pattern);
        }
        $byName[$field->name] = $field->pattern;
    }

    $subpatterns = [];
    foreach ($meta['fields'] as $fieldName) {
        $subpatterns[] = $byName[$fieldName] ?? new Ast\PatWild($pattern->line, $pattern->col, $pattern->endCol);
    }

    foreach (\array_keys($byName) as $extra) {
        if (! \in_array($extra, $meta['fields'], true)) {
            throw typeFail($state, "unknown field `{$extra}` in record pattern", $pattern);
        }
    }

    return $subpatterns;
}

/**
 * @param array{dataName: string, name: string, fields: array<int, string>, fieldTypes: array<int, array<string, mixed>>} $meta
 * @return array<string, array<string, mixed>>
 */
