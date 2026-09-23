<?php declare(strict_types=1);

namespace Moggi\IR;

function bindPattern(Pattern $pattern, Operand $value, LowerCtx $ctx): void
{
    match ($pattern::class) {
        PatWild::class => null,
        PatVar::class => (function () use ($pattern, $value, $ctx): void {
            $occ = $pattern->name;
            $irName = freshLocalName($ctx, $occ);
            $pattern->name = $irName;
            $ctx->items[] = new Let($irName, $value);
            $ctx->env[$occ] = new Local($irName);
        })(),
        PatLit::class => null,
        PatChar::class => null,
        PatCon::class => bindConPattern($pattern, $value, $ctx),
        PatTuple::class => bindTuplePattern($pattern, $value, $ctx),
        PatNil::class => null,
        PatCons::class => bindListConsPattern($pattern, $value, $ctx),
        default => throw new \RuntimeException('unsupported pattern `' . $pattern::class . '` in IR lowering'),
    };
}

function bindListConsPattern(PatCons $pattern, Operand $value, LowerCtx $ctx): void
{
    bindPattern($pattern->head, new Intrinsic('listHead#', [$value]), $ctx);
    bindPattern($pattern->tail, new Intrinsic('listTail#', [$value]), $ctx);
}

function bindConPattern(PatCon $pattern, Operand $value, LowerCtx $ctx): void
{
    if (isset($ctx->newtypes[$pattern->name])) {
        // Newtype: identity unwrap — the value is the single field.
        bindPattern($pattern->args[0], $value, $ctx);

        return;
    }

    foreach ($pattern->args as $i => $arg) {
        bindPattern($arg, lowerCall('__field' . $i, [$value], $ctx), $ctx);
    }
}

function bindTuplePattern(PatTuple $pattern, Operand $value, LowerCtx $ctx): void
{
    foreach ($pattern->elements as $i => $element) {
        bindPattern($element, lowerCall('__tuple_field' . $i, [$value], $ctx), $ctx);
    }
}

/**
 * Drop erased newtype constructors from a pattern tree so MatchArm patterns
 * agree with runtime tags and with constructor trees built by case-fold
 * (newtype construction is already identity in lowerCall).
 *
 * @param array<string, true> $newtypes
 */
function eraseNewtypesInPattern(Pattern $pattern, array $newtypes): Pattern
{
    if ($pattern instanceof PatCon) {
        $args = \array_map(
            static fn (Pattern $arg): Pattern => eraseNewtypesInPattern($arg, $newtypes),
            $pattern->args,
        );
        if (isset($newtypes[$pattern->name]) && count($args) === 1) {
            return $args[0];
        }

        return new PatCon($pattern->name, $args);
    }

    if ($pattern instanceof PatTuple) {
        return new PatTuple(\array_map(
            static fn (Pattern $el): Pattern => eraseNewtypesInPattern($el, $newtypes),
            $pattern->elements,
        ));
    }

    if ($pattern instanceof PatCons) {
        return new PatCons(
            eraseNewtypesInPattern($pattern->head, $newtypes),
            eraseNewtypesInPattern($pattern->tail, $newtypes),
        );
    }

    return $pattern;
}
