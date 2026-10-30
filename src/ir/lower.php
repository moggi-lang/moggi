<?php declare(strict_types=1);

namespace Moggi\IR;

use Moggi\Modules\CheckedFunction;
use Moggi\Modules\CheckedPattern;
use Moggi\Modules\PatternShape;
use Moggi\Syntax\Ast;

use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\Modules\actionEntryFacts;
use function Moggi\Semantics\Exhaustiveness\trueBindingOrigins;
use function Moggi\Semantics\IoBoundary\functionReturnsIo;
use function Moggi\Patterns\Walk\patternBoundNames;

require_once __DIR__ . '/types.php';
require_once __DIR__ . '/lower_pattern.php';
require_once __DIR__ . '/lower_expr.php';
require_once __DIR__ . '/lower_io.php';

function lower(Ast\Program $program, array $importedData = [], string $sourceFile = ''): Module
{
    $moduleName = $program->module ?? '';
    $interface = $program->interface
        ?? throw new \RuntimeException("lowering `{$moduleName}` needs its module interface");
    $core = $program->core
        ?? throw new \RuntimeException(
            "lowering `{$moduleName}` needs its checked core: a checked module is lowered from its"
            . ' closed product, never from the source tree',
        );

    $state = new LowerState();
    $dataIndex = buildDataIndex($program, $importedData);
    $newtypeIndex = buildNewtypeIndex($program, $importedData);
    $functions = [];
    $data = [];
    $knownFunctions = [];
    $functionArity = [];
    $externalFns = $interface->externalFns();
    $constructorRenames = $program->constructorRenames;

    $localBindings = [];

    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $knownFunctions[$item->name] = true;
            if ($item->signatureOnly) {
                continue;
            }

            $localBindings[$item->name] = true;
            $functionArity[$item->name] = \count($item->params);

            continue;
        }

        if ($item instanceof Ast\DataDecl) {
            foreach ($item->constructors as $ctor) {
                $localBindings[$ctor->name] = true;
                $knownFunctions[$ctor->name] = true;
                $functionArity[$ctor->name] = \count($ctor->fields);
            }
        }
    }

    foreach ($dataIndex as $ctorName => $fields) {
        $knownFunctions[$ctorName] = true;
        if (! isset($localBindings[$ctorName])) {
            $functionArity[$ctorName] = \count($fields);
        }
    }

    $actionReturningEntries = actionEntryFacts($program->items)['actionReturn'] + $interface->actionReturn();
    $workers = workerEntries($program->items) + importedWorkerEntries($interface, $localBindings);
    $workerEntries = executingEntryNames($program->items, $interface->env(), $interface->actionReturn());
    $state->trueBindings = trueBindingOrigins($program->items, $moduleName) + $interface->trueBindings;

    foreach ($interface->externalFnRuntimeArity() as $name => $arity) {
        if (isset($localBindings[$name])) {
            continue;
        }

        $functionArity[$name] = $arity;
    }

    foreach ($program->instanceEvidence as $ev) {
        if (\is_array($ev)) {
            $functionArity[$ev['evidenceName']] = \count($ev['contextParams'] ?? []);
        }
    }

    foreach ($program->items as $item) {
        if ($item instanceof Ast\DataDecl) {
            $data[] = lowerData($item);

            continue;
        }

        if ($item instanceof Ast\FunctionDecl) {
            if ($item->signatureOnly) {
                continue;
            }

            $record = $core->function($item->name)
                ?? throw new \RuntimeException(
                    "lowering `{$moduleName}::{$item->name}` needs its checked-core record:"
                    . ' the check did not publish one for a declaration it lowers',
                );

            \array_push($functions, ...lowerFunction(
                $item,
                $state,
                $record,
                $knownFunctions,
                $functionArity,
                $dataIndex,
                $externalFns,
                $actionReturningEntries,
                $newtypeIndex,
                $moduleName,
                $item->sourceFile ?? $sourceFile,
                $constructorRenames,
                $workers,
                $workerEntries,
            ));
        }
    }

    $functions = [...$functions, ...$state->functions];
    $entry = entryFromFunctions($functions);

    $instanceEvidence = \array_map(
        static function (array|InstanceEvidence $ev): InstanceEvidence {
            if ($ev instanceof InstanceEvidence) {
                return $ev;
            }

            return new InstanceEvidence(
                $ev['evidenceName'],
                $ev['methods'],
                $ev['contextParams'] ?? [],
            );
        },
        $program->instanceEvidence,
    );

    return new Module($functions, $data, $instanceEvidence, $entry, $moduleName, $sourceFile);
}

function lowerData(Ast\DataDecl $decl): DataDecl
{
    return new DataDecl(
        $decl->name,
        \array_map(static fn (Ast\DataParam $p): string => $p->name, $decl->params),
        \array_map(
            static fn (Ast\ConstructorDecl $ctor): DataConstructor => new DataConstructor(
                $ctor->name,
                \array_map(
                    static fn (Ast\CtorField $field): string => $field->name,
                    $ctor->fields,
                ),
            ),
            $decl->constructors,
        ),
        $decl->isNewtype,
    );
}

/** @return array<string, list<string>> */
function buildDataIndex(Ast\Program $program, array $importedData = []): array
{
    $index = [];
    foreach ($importedData as $info) {
        foreach ($info['constructors'] ?? [] as $ctor) {
            $index[$ctor['name']] = $ctor['fields'] ?? [];
        }
    }

    foreach ($program->items as $item) {
        if (! $item instanceof Ast\DataDecl) {
            continue;
        }

        foreach ($item->constructors as $ctor) {
            $index[$ctor->name] = \array_map(
                static fn (Ast\CtorField $field): string => $field->name,
                $ctor->fields,
            );
        }
    }

    return $index;
}

/** @return array<string, true> */
function buildNewtypeIndex(Ast\Program $program, array $importedData = []): array
{
    $index = [];
    foreach ($importedData as $info) {
        if (! ($info['newtype'] ?? false)) {
            continue;
        }

        foreach ($info['constructors'] ?? [] as $ctor) {
            $index[$ctor['name']] = true;
        }
    }

    foreach ($program->items as $item) {
        if (! $item instanceof Ast\DataDecl || ! $item->isNewtype) {
            continue;
        }

        foreach ($item->constructors as $ctor) {
            $index[$ctor->name] = true;
        }
    }

    return $index;
}

/**
 * @param array<string, string> $externalFns
 * @param array<string, true> $actionReturningEntries
 * @param array<string, true> $newtypeIndex
 * @param array<string, string> $workers entry name → the worker this module creates for it
 * @return list<FunctionDecl> the entry, followed by its worker when it has one
 */
function lowerFunction(
    Ast\FunctionDecl $fn,
    LowerState $state,
    CheckedFunction $core,
    array $knownFunctions,
    array $functionArity,
    array $dataIndex,
    array $externalFns = [],
    array $actionReturningEntries = [],
    array $newtypeIndex = [],
    string $moduleName = '',
    string $sourceFile = '',
    array $constructorRenames = [],
    array $workers = [],
    array $executingEntries = [],
): array {
    $state->usedLocals = [];
    $state->nextLocalSuffix = 0;
    $slotNames = functionParamSlots($fn->params, $core->terms);
    $ctx = newCtx($slotNames, $state, $dataIndex, $newtypeIndex);
    $ctx->functionArity = $functionArity;
    $ctx->externalFnNames = \array_keys($externalFns);
    $ctx->actionReturningEntries = $actionReturningEntries;
    $ctx->trueBindings = $state->trueBindings;
    $ctx->moduleName = $moduleName;
    $ctx->sourceFile = $sourceFile;
    $ctx->functionName = $fn->name;
    $ctx->constructorRenames = $constructorRenames;
    $ctx->workers = $workers;
    $ctx->executingEntries = $executingEntries;
    $ctx->evidenceNames = $core->evidenceBinders();
    $ctx->terms = $core->terms;

    foreach (\array_keys($externalFns) as $name) {
        if (\in_array($name, $slotNames, true)) {
            continue;
        }

        $ctx->env[$name] = new FnRef($name);
    }

    foreach (\array_keys($knownFunctions) as $name) {
        if (\in_array($name, $slotNames, true)) {
            continue;
        }

        $ctx->env[$name] = new FnRef($name);
    }

    foreach ($fn->params as $i => $pattern) {
        $term = plannedPattern($pattern, $ctx);
        if ($term->shape === PatternShape::Var) {
            if ($term->binder !== null) {
                $ctx->binders[$term->binder] = new Local($slotNames[$i]);
            }

            continue;
        }

        bindPattern(lowerPatternTerm($term, $ctx), new Local($slotNames[$i]), $ctx);
    }

    if ($fn->body->line > 0) {
        $ctx->stmtSrcLoc = srcLocFromAst($fn->body, $moduleName, $fn->name, $sourceFile);
    }

    $straightLineIo = false;
    $worker = null;
    $actionConvention = $fn->actionConvention;

    if (functionReturnsIo($fn->type)) {
        $ctx->ioActionStore = [];
        $ctx->hasIoRun = false;
        $ctx->hasIoAssignAction = false;

        if ($fn->actionConvention === ActionConvention::ReturnsAction) {
            $workerName = $workers[$fn->name] ?? throw new \RuntimeException(
                "`{$moduleName}::{$fn->name}` returns an action but has no worker allocated",
            );
            [$bodyItems, $result, $bodyHasBox] = lowerActionBody($fn->body, $ctx, false);

            $worker = new FunctionDecl(
                $workerName,
                $slotNames,
                null,
                new Block([...$bodyItems, new Ret($result)]),
                new FunctionShape(
                    kind: FunctionKind::Worker,
                    origin: $fn->name,
                    ioStraightLine: ! $bodyHasBox,
                ),
                srcLocFromAst($fn->body, $moduleName, $fn->name, $sourceFile),
                $ctx->evidenceNames,
            );

            $callDest = freshTemp($ctx);
            $boxDest = freshTemp($ctx);
            $ctx->hasIoAssignAction = true;
            $ctx->items[] = new IoAssignAction(
                $boxDest,
                new Block([new Call($workerName, \array_map(
                    static fn (string $slot): Operand => new Local($slot),
                    $slotNames,
                ), $callDest)]),
                new Temp($callDest),
                srcLocFromAst($fn->body, $moduleName, $fn->name, $sourceFile),
            );
            $ctx->items[] = new Ret(new Temp($boxDest));
        } elseif (! lowerIoFunctionBody($fn->body, $ctx)) {
            throw new \RuntimeException("failed to lower IO body for `{$fn->name}`");
        }

        $straightLineIo = ! $ctx->hasIoRun && ! $ctx->hasIoAssignAction;
    } else {
        $tail = lowerExpr($fn->body, $ctx);
        $ctx->items[] = new Ret($tail);
    }

    $entry = new FunctionDecl(
        $fn->name,
        $slotNames,
        $fn->type,
        new Block($ctx->items),
        new FunctionShape(
            export: $fn->export,
            instanceMethod: $fn->instanceMethod,
            entryKind: $fn->entryKind,
            foreign: $fn->foreign,
            actionConvention: $actionConvention,
            ioStraightLine: $straightLineIo,
        ),
        $fn->body->line > 0
            ? srcLocFromAst($fn->body, $moduleName, $fn->name, $sourceFile)
            : srcLocFromAst($fn, $moduleName, $fn->name, $sourceFile),
        $ctx->evidenceNames,
    );

    return $worker === null ? [$entry] : [$entry, $worker];
}

function srcLocFromAst(Ast\AstNode $node, string $module, string $function, string $file): SrcLoc
{
    return new SrcLoc($module, $function, normalizeDisplayPath($node->sourceFile ?? $file), $node->line, $node->col);
}

/**
 * The IR slot name of each declaration parameter.
 *
 * A variable pattern is its own slot, and the name it gives the slot is the one its term
 * publishes — not the node's spelling, which a scrub, a re-parse or a cache round-trip can leave
 * empty. Anything else (a constructor, a tuple, a wildcard) is a slot of its own that binds
 * nothing by that name and is called `_pN`, N being its position.
 *
 * @param array<int, Ast\AstNode> $patterns
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms the declaration's published terms
 * @return array<int, string>
 */
function functionParamSlots(array $patterns, array $terms): array
{
    $names = [];
    foreach ($patterns as $i => $pattern) {
        $term = $pattern->checkedTerm === null ? null : ($terms[$pattern->checkedTerm] ?? null);
        if ($term instanceof CheckedPattern && $term->shape === PatternShape::Var) {
            $names[] = $term->name
                ?? throw new \RuntimeException("the parameter pattern at {$term->origin} publishes no name");

            continue;
        }

        $names[] = '_p' . $i;
    }

    return $names;
}

/**
 * A pattern, lowered from the term that owns its shape.
 *
 * A pattern is structure — a constructor's arguments, a record's fields, a list's head and tail,
 * a variable's name — and it was the last family whose structure lowering read off the node one
 * level below the term that owned it. The term publishes the shape, the names and the
 * sub-patterns by their own ids, so this function reads the site's `checkedTerm` and nothing
 * else: scrubbing every pattern node's fields leaves the IR identical, which is what
 * `tests/semantics/checked_pattern_terms.php` asserts.
 */
function lowerPattern(Ast\AstNode $pattern, LowerCtx $ctx): Pattern
{
    return lowerPatternTerm(plannedPattern($pattern, $ctx), $ctx);
}

/**
 * The checked term that owns a pattern site's shape, or a refusal.
 *
 * The site is consulted for one thing — the id the record published for it — so a tree that was
 * scrubbed, re-parsed or restored from a cache answers the same way, and a pattern the record
 * does not cover is refused rather than lowered from whatever the node still happens to carry.
 */
function plannedPattern(Ast\AstNode $site, LowerCtx $ctx): CheckedPattern
{
    $term = siteTerm($site, $ctx);

    if ($term instanceof CheckedPattern) {
        return $term;
    }

    throw new \RuntimeException(
        "lowering `{$ctx->moduleName}::{$ctx->functionName}` met a pattern (`" . $site::class
        . "` at line {$site->line}) that its checked-core record does not plan",
    );
}

/** A pattern term by its own id, for a sub-pattern the parent names. */
function patternTermById(int $id, LowerCtx $ctx, CheckedPattern $parent): CheckedPattern
{
    $term = $ctx->terms[$id] ?? null;

    if ($term instanceof CheckedPattern) {
        return $term;
    }

    throw new \RuntimeException(
        "the checked pattern at {$parent->origin} names sub-pattern #{$id}, which its record does"
        . ' not hold as a pattern',
    );
}

function lowerPatternTerm(CheckedPattern $term, LowerCtx $ctx): Pattern
{
    $lower = static fn (int $id): Pattern => lowerPatternTerm(patternTermById($id, $ctx, $term), $ctx);

    return match ($term->shape) {
        PatternShape::Wild => new PatWild(),
        PatternShape::Var => new PatVar(
            $term->name ?? throw new \RuntimeException("the variable pattern at {$term->origin} publishes no name"),
            $term->binder,
        ),
        PatternShape::Lit => new PatLit(
            $term->value ?? throw new \RuntimeException("the literal pattern at {$term->origin} publishes no value"),
        ),
        PatternShape::Char => new PatChar(
            \is_int($term->value)
                ? $term->value
                : throw new \RuntimeException("the character pattern at {$term->origin} publishes no value"),
        ),
        PatternShape::Nil => new PatNil(),
        PatternShape::Cons => new PatCons($lower($term->children[0]), $lower($term->children[1])),
        PatternShape::Con => new PatCon(
            $ctx->constructorRenames[$term->name] ?? ($term->name
                ?? throw new \RuntimeException("the constructor pattern at {$term->origin} publishes no name")),
            \array_map($lower, $term->children),
        ),
        PatternShape::Tuple => new PatTuple(\array_map($lower, $term->children)),
        PatternShape::Record => new PatCon(
            $ctx->constructorRenames[$term->name] ?? ($term->name
                ?? throw new \RuntimeException("the record pattern at {$term->origin} publishes no name")),
            recordPatternArgs($term, $ctx, $lower),
        ),
    };
}

/**
 * A record pattern's sub-patterns in the constructor's declared field order.
 *
 * The field order is the data declaration's, which lowering holds in its context; the fields and
 * the sub-patterns are the term's. A field the pattern left out is a wildcard, exactly as the
 * source means it.
 *
 * @param array<string, list<string>> $fieldOrderByData constructor => its fields, in declared order
 * @param callable(int): Pattern $lower
 * @return list<Pattern>
 */
function recordPatternArgs(CheckedPattern $term, LowerCtx $ctx, callable $lower): array
{
    $name = $term->name ?? throw new \RuntimeException("the record pattern at {$term->origin} publishes no name");
    $order = $ctx->data[$name] ?? throw new \RuntimeException("record pattern `{$name}`: unknown field order");

    $byName = [];
    foreach ($term->fields as $field) {
        $byName[$field['name']] = $field['id'];
    }

    $args = [];
    foreach ($order as $fieldName) {
        $sub = $byName[$fieldName] ?? null;
        $args[] = $sub === null ? new PatWild() : $lower($sub);
    }

    return $args;
}

/**
 * @param array<int, string> $params
 * @param array<string, list<string>> $dataIndex
 * @param array<string, true> $newtypeIndex
 */
function newCtx(
    array $params,
    LowerState $state,
    array $dataIndex = [],
    array $newtypeIndex = [],
    ?LowerCtx $from = null,
): LowerCtx {
    $env = [];
    foreach ($params as $param) {
        $env[$param] = new Local($param);
        $state->usedLocals[$param] = true;
    }

    $ctx = new LowerCtx(
        items: [],
        env: $env,
        state: $state,
        data: $dataIndex,
        newtypes: $newtypeIndex,
    );

    if ($from !== null) {
        $ctx->moduleName = $from->moduleName;
        $ctx->sourceFile = $from->sourceFile;
        $ctx->functionName = $from->functionName;
        $ctx->stmtSrcLoc = $from->stmtSrcLoc;

        $ctx->externalFnNames = $from->externalFnNames;
        $ctx->functionArity = $from->functionArity;
        $ctx->actionReturningEntries = $from->actionReturningEntries;
        $ctx->workers = $from->workers;
        $ctx->executingEntries = $from->executingEntries;
        $ctx->ioActionStore = $from->ioActionStore;
        $ctx->constructorRenames = $from->constructorRenames;
        $ctx->evidenceNames = $from->evidenceNames;
        $ctx->terms = $from->terms;
        $ctx->binders = $from->binders;
    }

    return $ctx;
}

/**
 * Allocate a unique IR local name for a source OccName binder.
 * Prefer the OccName when unused; otherwise OccName__N.
 */
function freshLocalName(LowerCtx $ctx, string $occ): string
{
    if ($occ !== '' && ! isset($ctx->state->usedLocals[$occ])) {
        $ctx->state->usedLocals[$occ] = true;

        return $occ;
    }

    do {
        $name = $occ . '__' . $ctx->state->nextLocalSuffix++;
    } while (isset($ctx->state->usedLocals[$name]));

    $ctx->state->usedLocals[$name] = true;

    return $name;
}

/**
 * The names an expression refers to that its own binders do not bind.
 *
 * Three callers ask this about two different trees. Lowering asks about the
 * elaboration its terms describe; the publication pass asks about the same tree
 * before its terms are complete; and the checker asks about a copied default body
 * that carries no terms at all. Two of those answers are parameters the caller
 * supplies rather than fields this function reads: `$evidenceParameter` for where a
 * dispatched dictionary is named, and `$terms` for the enclosure's published terms.
 *
 * With `$terms` supplied, a `case` or a binding group is walked through its own
 * structure — the arms and the members of {@see CheckedCase} /
 * {@see CheckedLetGroup} — so nothing that lowers ever reads `CaseExpr::$alts` or
 * `Let::$bindings` for structure. The node's own lists are read only for a tree that
 * was never published, which is the checker's copy: a tree with no terms has no
 * structural term to read, and falling back to the node there is honest rather than
 * a second authority, because no lowered compilation takes that path.
 *
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms the enclosure's published terms, when it has them
 * @return array<int, string>
 */
function freeVarsExpr(Ast\AstNode $expr, array $bound, callable $evidenceParameter, array $terms = []): array
{
    $walk = static fn (Ast\AstNode $node, array $names): array
        => freeVarsExpr($node, $names, $evidenceParameter, $terms);

    return match ($expr::class) {
        Ast\Variable::class => isset($bound[$expr->name]) ? [] : [$expr->name],
        Ast\IntegerLit::class,
        Ast\DoubleLit::class,
        Ast\StringLit::class,
        Ast\CharLit::class,
        Ast\ConstructorRef::class,
        Ast\OperatorRef::class,
        Ast\QualifiedRef::class,
        Ast\ExprHole::class => [],
        Ast\TypeAsc::class => $walk($expr->expr, $bound),
        Ast\Apply::class => [...$walk($expr->function, $bound), ...$walk($expr->argument, $bound)],
        Ast\Infix::class => [...$walk($expr->left, $bound), ...$walk($expr->right, $bound)],
        Ast\Tuple::class,
        Ast\ListLit::class => freeVarsInExprs($expr->elements, $bound, $evidenceParameter, $terms),
        Ast\Lambda::class => $walk($expr->body, [...$bound, ...lambdaBoundSet($expr, $terms)]),
        Ast\DoExpr::class => $expr->desugared !== null
            ? $walk($expr->desugared, $bound)
            : throw new \RuntimeException('do expression reached free-variable analysis before desugaring'),
        Ast\Let::class,
        Ast\Where::class => freeVarsInGroupNode($expr, $bound, $evidenceParameter, $terms),
        Ast\CaseExpr::class => freeVarsInCaseNode($expr, $bound, $evidenceParameter, $terms),
        Ast\GuardsExpr::class => freeVarsInGuards(guardsClauses($expr, $terms), $bound, $evidenceParameter, $terms),
        Ast\RecordCon::class => freeVarsInRecordFields(recordFieldValues($expr, $terms), $bound, $evidenceParameter, $terms),
        Ast\RecordUpdate::class => [
            ...$walk(recordReceiver($expr, $terms), $bound),
            ...freeVarsInRecordFields(recordFieldValues($expr, $terms), $bound, $evidenceParameter, $terms),
        ],
        Ast\FieldAccess::class => $walk($expr->object, $bound),
        Ast\IntrinsicCall::class => freeVarsInExprs($expr->args, $bound, $evidenceParameter, $terms),
        Ast\EvidenceRef::class => freeVarsInExprs($expr->context, $bound, $evidenceParameter, $terms),
        Ast\EvidenceMethod::class => (static function () use ($expr, $bound, $evidenceParameter, $terms): array {
            $parameter = $evidenceParameter($expr);

            return [
                ...($parameter === null || isset($bound[$parameter]) ? [] : [$parameter]),
                ...freeVarsInExprs($expr->contextEvidence, $bound, $evidenceParameter, $terms),
            ];
        })(),
        default => freeVarsInChildren($expr, $bound, $evidenceParameter, $terms),
    };
}

/**
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<int, string>
 */
function freeVarsInChildren(Ast\AstNode $expr, array $bound, callable $evidenceParameter, array $terms = []): array
{
    $free = [];
    foreach ($expr->childValues() as $child) {
        if ($child instanceof Ast\AstNode) {
            $free = [...$free, ...freeVarsExpr($child, $bound, $evidenceParameter, $terms)];
        }
    }

    return \array_values(\array_unique($free));
}

/**
 * @param list<array{0: string, 1: Ast\AstNode}> $fields a record's written fields, label and value
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<int, string>
 */
function freeVarsInRecordFields(array $fields, array $bound, callable $evidenceParameter, array $terms = []): array
{
    $free = [];
    foreach ($fields as $field) {
        $free = [...$free, ...freeVarsExpr($field[1], $bound, $evidenceParameter, $terms)];
    }

    return $free;
}

/**
 * @param array<int, Ast\AstNode> $exprs
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<int, string>
 */
function freeVarsInExprs(array $exprs, array $bound, callable $evidenceParameter, array $terms = []): array
{
    $free = [];
    foreach ($exprs as $expr) {
        $free = [...$free, ...freeVarsExpr($expr, $bound, $evidenceParameter, $terms)];
    }

    return \array_values(\array_unique($free));
}

/**
 * A `let`/`where` group's free names, from the term that owns its members and order.
 *
 * The group binds its members over its own bodies before either is scanned, which is
 * why the bound set is built in one pass and read in the next. A group with no
 * published term — a checker copy — is walked from the node instead
 * ({@see freeVarsLet()}).
 *
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<int, string>
 */
function freeVarsInGroupNode(Ast\AstNode $expr, array $bound, callable $evidenceParameter, array $terms): array
{
    $term = $expr->checkedTerm === null ? null : ($terms[$expr->checkedTerm] ?? null);
    if (! $term instanceof \Moggi\Modules\CheckedLetGroup) {
        $node = $expr instanceof Ast\Where ? $expr->expr : $expr->body;

        return freeVarsLet($expr->bindings, $node, $bound, $evidenceParameter, $terms);
    }

    $members = $term->members();
    $groupBound = $bound;
    foreach ($members as $binding) {
        $groupBound = [...$groupBound, ...patternBoundSet($binding->pattern, $terms)];
    }

    $free = [];
    foreach ($members as $binding) {
        $free = [...$free, ...freeVarsExpr($binding->value, $groupBound, $evidenceParameter, $terms)];
    }

    if ($term->body !== null) {
        $free = [...$free, ...freeVarsExpr($term->body, $groupBound, $evidenceParameter, $terms)];
    }

    return \array_values(\array_unique($free));
}

/**
 * A `case`'s free names, from the term that owns its arms.
 *
 * Each clause is scanned under its arm's binders, guards included; an arm with no
 * guarded clauses has exactly one clause whose guard is null.
 *
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<int, string>
 */
function freeVarsInCaseNode(Ast\CaseExpr $expr, array $bound, callable $evidenceParameter, array $terms): array
{
    $term = $expr->checkedTerm === null ? null : ($terms[$expr->checkedTerm] ?? null);
    if (! $term instanceof \Moggi\Modules\CheckedCase) {
        return [
            ...freeVarsExpr($expr->scrutinee, $bound, $evidenceParameter, $terms),
            ...freeVarsInCaseAlts($expr->alts, $bound, $evidenceParameter, $terms),
        ];
    }

    $free = freeVarsExpr($expr->scrutinee, $bound, $evidenceParameter, $terms);
    foreach ($term->arms as $arm) {
        $armBound = [...$bound, ...patternBoundSet($arm->pattern, $terms)];
        foreach ($arm->clauses as $clause) {
            if ($clause['guard'] !== null) {
                $free = [...$free, ...freeVarsExpr($clause['guard'], $armBound, $evidenceParameter, $terms)];
            }
            $free = [...$free, ...freeVarsExpr($clause['body'], $armBound, $evidenceParameter, $terms)];
        }
    }

    return \array_values(\array_unique($free));
}

/**
 * @param array<int, Ast\Binding> $bindings
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<int, string>
 */
function freeVarsLet(array $bindings, Ast\AstNode $body, array $bound, callable $evidenceParameter, array $terms = []): array
{
    $groupBound = $bound;
    foreach ($bindings as $binding) {
        $groupBound = [...$groupBound, ...patternBoundSet($binding->pattern, $terms)];
    }

    $free = [];
    foreach ($bindings as $binding) {
        $free = [...$free, ...freeVarsExpr($binding->value, $groupBound, $evidenceParameter, $terms)];
    }

    return [...$free, ...freeVarsExpr($body, $groupBound, $evidenceParameter, $terms)];
}

/**
 * @param array<int, Ast\Guarded> $clauses
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<int, string>
 */
function freeVarsInGuards(array $clauses, array $bound, callable $evidenceParameter, array $terms = []): array
{
    $free = [];
    foreach ($clauses as $clause) {
        $free = [
            ...$free,
            ...freeVarsExpr($clause->guard, $bound, $evidenceParameter, $terms),
            ...freeVarsExpr($clause->body, $bound, $evidenceParameter, $terms),
        ];
    }

    return \array_values(\array_unique($free));
}

/**
 * @param array<int, Ast\Alt> $alts
 * @param array<string, true> $bound
 * @param callable(Ast\EvidenceMethod): ?string $evidenceParameter
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<int, string>
 */
function freeVarsInCaseAlts(array $alts, array $bound, callable $evidenceParameter, array $terms = []): array
{
    $free = [];
    foreach ($alts as $alt) {
        $free = [...$free, ...freeVarsExpr(
            $alt->body,
            [...$bound, ...patternBoundSet($alt->pattern, $terms)],
            $evidenceParameter,
            $terms,
        )];
    }

    return \array_values(\array_unique($free));
}

/**
 * A record's written fields as (label, value) pairs, from its term when the tree was published.
 *
 * The record term owns which fields were written and in what order; the values stay children of
 * the node and are what free-variable analysis walks. A tree with no terms — the checker's
 * pre-elaboration copy — falls back to the node's own list.
 *
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return list<array{0: string, 1: Ast\AstNode}>
 */
function recordFieldValues(Ast\AstNode $expr, array $terms): array
{
    $term = $expr->checkedTerm === null ? null : ($terms[$expr->checkedTerm] ?? null);
    if ($term instanceof \Moggi\Modules\CheckedRecord) {
        $fields = [];
        foreach ($term->fields as $field) {
            $fields[] = [$field->name, $field->value];
        }

        return $fields;
    }

    $fields = [];
    foreach (\property_exists($expr, 'fields') ? $expr->fields : [] as $field) {
        $fields[] = [$field->name, $field->expr];
    }

    return $fields;
}

/**
 * The record an update reads its unmentioned fields from, from its term when the tree was
 * published.
 *
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 */
function recordReceiver(Ast\AstNode $expr, array $terms): Ast\AstNode
{
    $term = $expr->checkedTerm === null ? null : ($terms[$expr->checkedTerm] ?? null);
    if ($term instanceof \Moggi\Modules\CheckedRecord && $term->receiver !== null) {
        return $term->receiver;
    }

    return $expr->object ?? throw new \RuntimeException(
        'a record update reached free-variable analysis with neither a term nor a receiver',
    );
}

/**
 * A guard chain's clauses, from its term when the tree was published.
 *
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return list<Ast\Guarded>
 */
function guardsClauses(Ast\GuardsExpr $expr, array $terms): array
{
    $term = $expr->checkedTerm === null ? null : ($terms[$expr->checkedTerm] ?? null);

    return $term instanceof \Moggi\Modules\CheckedGuards ? $term->clauses : $expr->clauses;
}

/**
 * The names a pattern subtree binds, from its term when the tree was published.
 *
 * Free-variable analysis asks this for every arm binder and every group member. Answering it
 * from the term keeps the answer the one the producer read off the node, so a scrubbed, re-parsed
 * or cache-restored pattern still binds what it bound. A tree that carries no terms — the
 * checker's pre-elaboration copy — falls back to the walk, which is the only honest answer
 * there.
 *
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<string, true>
 */
function patternBoundSet(Ast\AstNode $pattern, array $terms): array
{
    $term = $pattern->checkedTerm === null ? null : ($terms[$pattern->checkedTerm] ?? null);

    return $term instanceof CheckedPattern ? $term->boundNames() : patternBoundNames($pattern);
}

/**
 * The names a lambda's slots bind, from its term once it has one.
 *
 * Its own capture set is computed while its term is still being built, so there the answer comes
 * from the node — the same answer the published term then carries.
 *
 * @param array<int, \Moggi\Modules\CheckedTerm> $terms
 * @return array<string, true>
 */
function lambdaBoundSet(Ast\Lambda $lambda, array $terms): array
{
    $term = $lambda->checkedTerm === null ? null : ($terms[$lambda->checkedTerm] ?? null);

    return \array_fill_keys(
        $term instanceof \Moggi\Modules\CheckedLambda ? $term->parameterNames : patternParams($lambda->params),
        true,
    );
}

/** @param array<int, Ast\LambdaParam|Ast\AstNode> $patterns @return array<int, string> */
function patternParams(array $patterns): array
{
    $params = [];
    foreach ($patterns as $index => $pattern) {
        $pattern = lambdaParamPattern($pattern);
        if ($pattern instanceof Ast\PatWild) {
            $params[] = '__wild' . $index;

            continue;
        }
        if (! $pattern instanceof Ast\PatVar) {
            throw new \RuntimeException('lambda parameters must be variables for now');
        }
        $params[] = $pattern->name;
    }

    return $params;
}

function lambdaParamPattern(Ast\LambdaParam|Ast\AstNode $param): Ast\AstNode
{
    return $param instanceof Ast\LambdaParam ? $param->pattern : $param;
}

function freshTemp(LowerCtx $ctx): int
{
    return $ctx->state->nextTemp++;
}
