<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TBytes;
use Moggi\Semantics\TypeExpr\TChar;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TDouble;
use Moggi\Semantics\TypeExpr\TInt;
use Moggi\Semantics\TypeExpr\TInt16;
use Moggi\Semantics\TypeExpr\TInt32;
use Moggi\Semantics\TypeExpr\TInt64;
use Moggi\Semantics\TypeExpr\TInt8;
use Moggi\Semantics\TypeExpr\TNatLit;
use Moggi\Semantics\TypeExpr\TPromoted;
use Moggi\Semantics\TypeExpr\TStr;
use Moggi\Semantics\TypeExpr\TStringLit;
use Moggi\Semantics\TypeExpr\TUnit;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\TVarApp;
use Moggi\Semantics\TypeExpr\TWord;
use Moggi\Semantics\TypeExpr\TWord16;
use Moggi\Semantics\TypeExpr\TWord32;
use Moggi\Semantics\TypeExpr\TWord64;
use Moggi\Semantics\TypeExpr\TWord8;
use Moggi\Semantics\TypeExpr\Type;

require_once __DIR__ . '/../type_expr.php';

/**
 * The one place that knows a type node's children and how to rebuild it.
 *
 * Every structural law — free variables, prune, substitute, occurs, normalize —
 * reads children through here, so a head that carries arguments cannot be
 * silently treated as childless by one law and traversed by another. `TPromoted`
 * and `TVarApp` carry arguments exactly like `TCon`, which is the discrepancy
 * this module exists to remove: before it, `typeVars('Pair a)` was empty while
 * `substitute` already descended into the same node.
 *
 * The 22 heads are listed twice on purpose: once in `typeChildren` (what the
 * node owns) and once in `mapTypeChildren` (how to rebuild it). A new head must
 * be added to both; leaving it out raises `UnknownTypeHead` rather than being
 * read as a leaf, and the constructor-completeness test fails until the head has
 * a law case of its own.
 */
final class UnknownTypeHead extends \InvalidArgumentException
{
    public static function for(Type $type): self
    {
        return new self('unknown type head `' . $type::class . '`');
    }
}

/**
 * A type node's direct children, in the order the laws visit them: an arrow's
 * domain then codomain, an application's arguments left to right.
 *
 * @return list<Type>
 */
function typeChildren(Type $type): array
{
    return match ($type::class) {
        TArrow::class => [$type->from, $type->to],
        TVarApp::class, TCon::class, TPromoted::class => $type->args,
        TInt::class, TStr::class, TChar::class, TWord::class, TWord8::class, TWord16::class,
        TWord32::class, TWord64::class, TInt8::class, TInt16::class, TInt32::class, TInt64::class,
        TBytes::class, TDouble::class, TUnit::class, TVar::class, TStringLit::class, TNatLit::class => [],
        default => throw UnknownTypeHead::for($type),
    };
}

/**
 * Rebuild a type with each child replaced by `$map(child)`.
 *
 * Structure-shares: when no child changed, the original node is returned, so
 * callers keep the identity comparisons they already rely on. Applies to the
 * leaves too, which are returned as they are — after their head has been
 * recognised.
 *
 * @param callable(Type): Type $map
 */
function mapTypeChildren(Type $type, callable $map): Type
{
    return match ($type::class) {
        TArrow::class => (static function () use ($type, $map): Type {
            $from = $map($type->from);
            $to = $map($type->to);

            return $from === $type->from && $to === $type->to
                ? $type
                : new TArrow($from, $to);
        })(),
        TVarApp::class => (static function () use ($type, $map): Type {
            $args = [];
            $changed = false;
            foreach ($type->args as $arg) {
                $mapped = $map($arg);
                $args[] = $mapped;
                $changed = $changed || $mapped !== $arg;
            }

            return $changed ? new TVarApp($type->name, $args) : $type;
        })(),
        TCon::class => (static function () use ($type, $map): Type {
            $args = [];
            $changed = false;
            foreach ($type->args as $arg) {
                $mapped = $map($arg);
                $args[] = $mapped;
                $changed = $changed || $mapped !== $arg;
            }

            return $changed ? new TCon($type->name, $args) : $type;
        })(),
        TPromoted::class => (static function () use ($type, $map): Type {
            $args = [];
            $changed = false;
            foreach ($type->args as $arg) {
                $mapped = $map($arg);
                $args[] = $mapped;
                $changed = $changed || $mapped !== $arg;
            }

            return $changed ? new TPromoted($type->name, $args) : $type;
        })(),
        TInt::class, TStr::class, TChar::class, TWord::class, TWord8::class, TWord16::class,
        TWord32::class, TWord64::class, TInt8::class, TInt16::class, TInt32::class, TInt64::class,
        TBytes::class, TDouble::class, TUnit::class, TVar::class, TStringLit::class, TNatLit::class => $type,
        default => throw UnknownTypeHead::for($type),
    };
}
