<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TNatLit;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TStringLit;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\TypeExpr\canonicalNatDigits;

/**
 * Shared type normalization: prune (var/kind subst), expand synonyms, and
 * optionally reduce associated families. Eq solving, Generic derive, and tests
 * should all go through this rather than ad-hoc expanders.
 *
 * @param array<string, true> $expanding synonym names currently being expanded
 * @param bool $reduceFamilies when false, leave associated family apps stuck
 *   (needed during unify / apply so `Rep Foo x` stays nominal opposite `Rep a b`)
 */
function normalizeType(
    TypeCheckState $state,
    Type $type,
    array $expanding = [],
    bool $reduceFamilies = true,
): Type {
    $type = prune($state, $type);

    if ($type instanceof TArrow) {
        $from = normalizeType($state, $type->from, $expanding, $reduceFamilies);
        $to = normalizeType($state, $type->to, $expanding, $reduceFamilies);

        return $from === $type->from && $to === $type->to
            ? $type
            : new TArrow($from, $to);
    }

    if ($type instanceof TPromoted) {
        if ($type->args === []) {
            return $type;
        }

        $args = [];
        $changed = false;
        foreach ($type->args as $arg) {
            $normalized = normalizeType($state, $arg, $expanding, $reduceFamilies);
            $args[] = $normalized;
            if ($normalized !== $arg) {
                $changed = true;
            }
        }

        return $changed ? new TPromoted($type->name, $args) : $type;
    }

    if (!$type instanceof TCon) {
        // TVar / TStringLit / TNatLit / primitives / TUnit — already pruned.
        return $type;
    }

    $args = [];
    $changed = false;
    foreach ($type->args as $arg) {
        $normalized = normalizeType($state, $arg, $expanding, $reduceFamilies);
        $args[] = $normalized;
        if ($normalized !== $arg) {
            $changed = true;
        }
    }
    if ($changed) {
        $type = new TCon($type->name, $args);
    }

    if ($reduceFamilies) {
        $reduced = reduceAssociatedFamily($state, $type);
        if ($reduced !== null) {
            return normalizeType($state, $reduced, $expanding, $reduceFamilies);
        }
    }

    if (!isset($state->typeSynonyms[$type->name])) {
        return $type;
    }

    // Already expanding this synonym: treat the head as nominal. Magichash
    // public aliases (`type List = List#`) collapse back to `TCon('List')`;
    // true cycles are reported while elaborating the RHS (see below).
    if (isset($expanding[$type->name])) {
        return $type;
    }

    $syn = $state->typeSynonyms[$type->name];
    $params = $syn['params'];
    $arity = \count($params);

    // Under-applied parametric synonym: leave the unsaturated head as-is.
    if (\count($args) < $arity) {
        return $type;
    }

    return expandSynonymInNormalizedType($state, $type->name, $syn, $args, $expanding, $reduceFamilies);
}

/**
 * Phase 4: reduce associated type family applications (forward only, non-injective).
 * Returns null when the head is not an associated family or is under-applied /
 * does not yet match any equation (e.g. still polymorphic).
 * Errors on overlapping equations (coherence).
 */
function reduceAssociatedFamily(TypeCheckState $state, TCon $type): ?Type
{
    $family = $state->associatedFamilies[$type->name] ?? null;
    if ($family === null) {
        return null;
    }

    $arity = \count($family['params']);
    $args = $type->args;
    if (\count($args) < $arity) {
        return null;
    }

    $headArgs = \array_slice($args, 0, $arity);
    $remaining = \array_slice($args, $arity);
    $className = $family['class'];
    $equations = $state->associatedEquations[$className][$type->name] ?? [];

    $matches = [];
    foreach ($equations as $eq) {
        $mapping = matchAssociatedEquationArgs($state, $eq['lhsArgs'], $headArgs);
        if ($mapping !== null) {
            $matches[] = ['eq' => $eq, 'mapping' => $mapping];
        }
    }

    if ($matches === []) {
        return null;
    }

    if (\count($matches) > 1) {
        throw typeFail(
            $state,
            "overlapping associated type equations for `{$type->name}`"
                . ' (class `' . $className . '`)',
        );
    }

    $match = $matches[0];
    /** @var array<string, Type> $typeMapping */
    $typeMapping = $match['mapping'];
    $astMapping = [];
    foreach ($typeMapping as $var => $t) {
        $astMapping[$var] = internalTypeToAst($t, $state->subst);
    }

    $rhsAst = substituteTypeAstParams($match['eq']['rhs'], $astMapping);
    $reduced = typeAstToInternalForNormalize($state, $rhsAst, []);

    if ($remaining !== []) {
        $reduced = prune($state, $reduced);
        if (!$reduced instanceof TCon) {
            throw typeFail(
                $state,
                "associated type `{$type->name}` reduced to a non-constructor type that cannot take arguments",
            );
        }
        $reduced = new TCon($reduced->name, [...$reduced->args, ...$remaining]);
    }

    return $reduced;
}

/**
 * Match associated equation LHS args against concrete (normalized) head args.
 * Unlike matchInstanceHeadVars, constructor names are checked (needed for
 * nullary heads like `Bool`).
 *
 * @param list<Ast\TypeNode> $lhsArgs
 * @param list<Type> $targetArgs
 * @return array<string, Type>|null
 */
function matchAssociatedEquationArgs(TypeCheckState $state, array $lhsArgs, array $targetArgs): ?array
{
    if (\count($lhsArgs) !== \count($targetArgs)) {
        return null;
    }

    $mapping = [];
    foreach ($lhsArgs as $i => $lhs) {
        $sub = matchAssociatedTypePattern($state, $lhs, $targetArgs[$i]);
        if ($sub === null) {
            return null;
        }
        foreach ($sub as $name => $bound) {
            if (isset($mapping[$name])) {
                if (typeToString(prune($state, $mapping[$name])) !== typeToString(prune($state, $bound))) {
                    return null;
                }
                continue;
            }
            $mapping[$name] = $bound;
        }
    }

    return $mapping;
}

/**
 * @return array<string, Type>|null
 */
function matchAssociatedTypePattern(TypeCheckState $state, Ast\TypeNode $pattern, Type $target): ?array
{
    $target = prune($state, $target);

    if ($pattern instanceof Ast\TypeVar) {
        return [$pattern->name => $target];
    }

    if ($pattern instanceof Ast\TypeUnit) {
        return $target instanceof TUnit ? [] : null;
    }

    if ($pattern instanceof Ast\TypeCon) {
        if ($target instanceof TCon && $target->name === $pattern->name && $target->args === []) {
            return [];
        }

        return null;
    }

    if ($pattern instanceof Ast\TypeApp) {
        $flat = flattenTypeAppPattern($pattern);
        if ($flat === null) {
            return null;
        }
        [$headName, $argPatterns] = $flat;
        if (!$target instanceof TCon || $target->name !== $headName) {
            return null;
        }
        if (\count($argPatterns) !== \count($target->args)) {
            return null;
        }

        $mapping = [];
        foreach ($argPatterns as $i => $argPat) {
            $sub = matchAssociatedTypePattern($state, $argPat, $target->args[$i]);
            if ($sub === null) {
                return null;
            }
            foreach ($sub as $name => $bound) {
                $mapping[$name] = $bound;
            }
        }

        return $mapping;
    }

    return null;
}

/**
 * @return array{0: string, 1: list<Ast\TypeNode>}|null
 */
function flattenTypeAppPattern(Ast\TypeNode $pattern): ?array
{
    if ($pattern instanceof Ast\TypeCon) {
        return [$pattern->name, []];
    }

    if (!$pattern instanceof Ast\TypeApp) {
        return null;
    }

    // Iterative flatten — see flattenInstanceHeadTypeApp.
    $args = [];
    $type = $pattern;
    while ($type instanceof Ast\TypeApp) {
        for ($i = count($type->args) - 1; $i >= 0; $i--) {
            $args[] = $type->args[$i];
        }
        $type = $type->con;
    }

    if ($type instanceof Ast\TypeCon) {
        return [$type->name, array_reverse($args)];
    }

    return null;
}

/**
 * Expand a fully-applied (or over-applied) synonym, then re-normalize.
 *
 * @param array{params: list<string>, rhs: Ast\TypeNode} $syn
 * @param list<Type> $args
 * @param array<string, true> $expanding
 */
/**
 * The synonym body applied to the arguments its parameters did not consume.
 *
 * @param list<Type> $extra
 */
function appendTypeArgs(TypeCheckState $state, Type $head, array $extra, string $name): Type
{
    if ($extra === []) {
        return $head;
    }
    $head = prune($state, $head);
    if (!$head instanceof TCon) {
        throw typeFail(
            $state,
            "type synonym `{$name}` expanded to a non-constructor type that cannot take arguments",
        );
    }

    return new TCon($head->name, [...$head->args, ...$extra]);
}

function expandSynonymInNormalizedType(
    TypeCheckState $state,
    string $name,
    array $syn,
    array $args,
    array $expanding,
    bool $reduceFamilies = true,
): Type {
    $params = $syn['params'];
    $arity = \count($params);

    // The body was elaborated where the synonym was declared, so the RHS names
    // never need to resolve in the importing module's scope.
    $body = $syn['body'] ?? null;
    if ($body !== null) {
        $bodyMapping = [];
        for ($i = 0; $i < $arity; ++$i) {
            $bodyMapping[$params[$i]] = $args[$i];
        }

        return normalizeType(
            $state,
            appendTypeArgs($state, substitute($body, $bodyMapping), \array_slice($args, $arity), $name),
            [...$expanding, $name => true],
            $reduceFamilies,
        );
    }

    $mapping = [];
    for ($i = 0; $i < $arity; ++$i) {
        $mapping[$params[$i]] = internalTypeToAst($args[$i], $state->subst);
    }

    $rhsExpanding = [...$expanding, $name => true];
    $expandedAst = substituteTypeAstParams($syn['rhs'], $mapping);
    $head = typeAstToInternalForNormalize($state, $expandedAst, $rhsExpanding);
    $remaining = \array_slice($args, $arity);

    if ($remaining !== []) {
        $head = prune($state, $head);
        if (!$head instanceof TCon) {
            throw typeFail(
                $state,
                "type synonym `{$name}` expanded to a non-constructor type that cannot take arguments",
            );
        }
        $head = new TCon($head->name, [...$head->args, ...$remaining]);
    }

    // Re-enter with the synonym guarded so Magichash collapses (List# → List)
    // stay nominal; leftover expand-under-app args normalize recursively.
    return normalizeType($state, $head, $rhsExpanding, $reduceFamilies);
}

/**
 * Lenient AST→internal conversion for synonym RHS during normalize.
 * Resolves MagicHash primitives; expands nested synonyms; detects cycles.
 *
 * @param array<string, true> $expanding
 */
function typeAstToInternalForNormalize(TypeCheckState $state, Ast\TypeNode $type, array $expanding): Type
{
    return match ($type::class) {
        Ast\TypeVar::class => new TVar($type->name),
        Ast\TypeUnit::class => new TUnit(),
        Ast\TypeCon::class => (static function () use ($state, $type, $expanding): Type {
            $prim = magicHashInternalType($type->name);
            if ($prim !== null) {
                return $prim;
            }
            if (isset($state->typeSynonyms[$type->name])) {
                if (isset($expanding[$type->name])) {
                    throw typeFail($state, "cyclic type synonym `{$type->name}`");
                }

                return normalizeType($state, new TCon($type->name), $expanding);
            }

            return new TCon($type->name);
        })(),
        Ast\TypePromoted::class => new TPromoted($type->name),
        Ast\TypeStringLit::class => new TStringLit($type->value),
        Ast\TypeNatLit::class => new TNatLit(
            canonicalNatDigits($type->digits),
        ),
        Ast\TypeApp::class => (static function () use ($state, $type, $expanding): Type {
            if ($type->con instanceof Ast\TypePromoted) {
                $args = [];
                foreach ($type->args as $argAst) {
                    $args[] = typeAstToInternalForNormalize($state, $argAst, $expanding);
                }

                return new TPromoted($type->con->name, $args);
            }

            $headName = match (true) {
                $type->con instanceof Ast\TypeCon,
                $type->con instanceof Ast\TypeVar => $type->con->name,
                default => throw new \InvalidArgumentException(
                    'unexpected synonym RHS type app head: ' . $type->con::class,
                ),
            };
            $args = [];
            foreach ($type->args as $argAst) {
                $args[] = typeAstToInternalForNormalize($state, $argAst, $expanding);
            }
            $prim = magicHashInternalType($headName);
            if ($prim !== null) {
                return $prim;
            }
            if (isset($state->typeSynonyms[$headName])) {
                return normalizeType($state, new TCon($headName, $args), $expanding);
            }

            return new TCon($headName, $args);
        })(),
        Ast\TypeArrow::class => new TArrow(
            typeAstToInternalForNormalize($state, $type->from, $expanding),
            typeAstToInternalForNormalize($state, $type->to, $expanding),
        ),
        Ast\TypeConstrained::class => typeAstToInternalForNormalize($state, $type->body, $expanding),
        default => throw new \InvalidArgumentException(
            'unexpected synonym RHS type node: ' . $type::class,
        ),
    };
}
