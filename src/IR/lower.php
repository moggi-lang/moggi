<?php declare(strict_types=1);

namespace Moggi\IR;

use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\Semantics\IoBoundary\functionReturnsIo;

require_once __DIR__ . '/types.php';
require_once __DIR__ . '/lower_pattern.php';
require_once __DIR__ . '/lower_expr.php';
require_once __DIR__ . '/lower_io.php';

use Moggi\Syntax\Ast;

use function Moggi\Patterns\Walk\patternBoundNames;

function lower(Ast\Program $program, array $importedData = [], string $sourceFile = ''): Module
{
    $state = newLowerState();
    $dataIndex = buildDataIndex($program, $importedData);
    $newtypeIndex = buildNewtypeIndex($program, $importedData);
    $functions = [];
    $data = [];
    $knownFunctions = [];
    $functionArity = [];
    $externalFns = $program->externalFns;
    $moduleName = $program->module ?? '';
    $constructorRenames = $program->constructorRenames;

    // What this module binds itself, which the bare-name tables for other modules must never
    // overwrite (`null x = x + 1` is a call, not an under-applied `Foldable.null`).
    $localBindings = [];

    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $knownFunctions[$item->name] = true;
            // A signature without a body is a promise the body lives elsewhere, so its arity is the
            // import table's to say — an empty parameter list is not a definition.
            if ($item->signatureOnly) {
                continue;
            }

            $localBindings[$item->name] = true;
            $functionArity[$item->name] = count($item->params);
            continue;
        }

        if ($item instanceof Ast\DataDecl) {
            foreach ($item->constructors as $ctor) {
                $localBindings[$ctor->name] = true;
                $knownFunctions[$ctor->name] = true;
                $functionArity[$ctor->name] = count($ctor->fields);
            }
        }
    }

    // Constructors are first-class and curried; without arity, unsaturated apps
    // lower to CallValue and survive as runtime __partial/__apply.
    foreach ($dataIndex as $ctorName => $fields) {
        $knownFunctions[$ctorName] = true;
        if (! isset($localBindings[$ctorName])) {
            $functionArity[$ctorName] = count($fields);
        }
    }

    $ioActionReturnFns = ioActionReturnFnNames($program->items) + $program->externalActionReturnFns;

    foreach ($program->externalFnRuntimeArity as $name => $arity) {
        if (isset($localBindings[$name])) {
            continue;
        }

        $functionArity[$name] = $arity;
    }

    foreach ($program->instanceEvidence as $ev) {
        if (\is_array($ev)) {
            $functionArity[$ev['evidenceName']] = count($ev['contextParams'] ?? []);
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

            $functions[] = lowerFunction(
                $item,
                $state,
                $knownFunctions,
                $functionArity,
                $dataIndex,
                $externalFns,
                $ioActionReturnFns,
                $newtypeIndex,
                $moduleName,
                $sourceFile,
                $constructorRenames,
            );
        }

        // Bare top-level expressions are type-checked only; they do not become functions.
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

function newLowerState(): LowerState
{
    return new LowerState();
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
        if (!$item instanceof Ast\DataDecl) {
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
        if (!($info['newtype'] ?? false)) {
            continue;
        }

        foreach ($info['constructors'] ?? [] as $ctor) {
            $index[$ctor['name']] = true;
        }
    }

    foreach ($program->items as $item) {
        if (!$item instanceof Ast\DataDecl || !$item->isNewtype) {
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
 * @param array<string, true> $ioActionReturnFns
 * @param array<string, true> $newtypeIndex
 */
function lowerFunction(
    Ast\FunctionDecl $fn,
    LowerState $state,
    array $knownFunctions,
    array $functionArity,
    array $dataIndex,
    array $externalFns = [],
    array $ioActionReturnFns = [],
    array $newtypeIndex = [],
    string $moduleName = '',
    string $sourceFile = '',
    array $constructorRenames = [],
): FunctionDecl {
    // Fresh local-name supply per top-level function (lambdas share this state).
    $state->usedLocals = [];
    $state->nextLocalSuffix = 0;
    $slotNames = functionParamSlots($fn->params);
    $ctx = newCtx($slotNames, $state, $dataIndex, $newtypeIndex);
    $ctx->functionArity = $functionArity;
    $ctx->externalFnNames = \array_keys($externalFns);
    $ctx->ioActionReturnFns = $ioActionReturnFns;
    $ctx->moduleName = $moduleName;
    $ctx->sourceFile = $sourceFile;
    $ctx->functionName = $fn->name;
    $ctx->constructorRenames = $constructorRenames;
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
        if ($pattern instanceof Ast\PatVar && $pattern->name === $slotNames[$i]) {
            continue;
        }

        bindPattern(lowerPattern($pattern, $ctx->constructorRenames, $dataIndex), new Local($slotNames[$i]), $ctx);
    }

    // The function body is the first source statement, so nested calls in it
    // are frames of this location until a statement sets its own.
    if ($fn->body->line > 0) {
        $ctx->stmtSrcLoc = srcLocFromAst($fn->body, $moduleName, $fn->name, $sourceFile);
    }

    $straightLineIo = false;
    $ioBodyKind = $fn->ioBodyKind;
    if (functionReturnsIo($fn->type)) {
        $ctx->ioActionStore = [];
        $ctx->hasIoRun = false;
        $ctx->hasIoAssignAction = false;
        $bodyKind = $fn->ioBodyKind;

        if ($bodyKind === IoBodyKind::ActionReturn) {
            $operand = lowerIoActionToBox($fn->body, $ctx);
            $ctx->items[] = new Ret($operand);
            $ioBodyKind = IoBodyKind::ActionReturn;
        } else {
            if (!lowerIoFunctionBody($fn->body, $ctx)) {
                throw new \RuntimeException("failed to lower IO body for `{$fn->name}`");
            }
        }

        $straightLineIo = !$ctx->hasIoRun && !$ctx->hasIoAssignAction;
    } else {
        $tail = lowerExpr($fn->body, $ctx);
        $ctx->items[] = new Ret($tail);
    }

    if ($fn->ioBodyKind === IoBodyKind::ActionReturn) {
        $ioBodyKind = IoBodyKind::ActionReturn;
    }

    return new FunctionDecl(
        $fn->name,
        $slotNames,
        $fn->type,
        new Block($ctx->items),
        $fn->export,
        $fn->instanceMethod,
        $fn->entryKind,
        ioEffect: false,
        ioStraightLine: $straightLineIo,
        foreign: $fn->foreign,
        ioBodyKind: $ioBodyKind,
        // The location a backend records for a function — its debug line and its frame in an
        // exception report — describes the code that runs, so it comes from the body. The
        // declaration's own span (its signature) is for diagnostics.
        srcLoc: $fn->body->line > 0
            ? srcLocFromAst($fn->body, $moduleName, $fn->name, $sourceFile)
            : srcLocFromAst($fn, $moduleName, $fn->name, $sourceFile),
    );
}

function srcLocFromAst(Ast\AstNode $node, string $module, string $function, string $file): SrcLoc
{
    return new SrcLoc($module, $function, normalizeDisplayPath($file), $node->line, $node->col);
}

/** @param array<int, Ast\AstNode> $patterns @return array<int, string> */
function functionParamSlots(array $patterns): array
{
    $names = [];
    foreach ($patterns as $i => $pattern) {
        if ($pattern instanceof Ast\PatVar) {
            $names[] = $pattern->name;
            continue;
        }

        $names[] = '_p' . $i;
    }

    return $names;
}

/**
 * @param array<string, string> $constructorRenames
 * @param array<string, list<string>> $fieldOrderByData constructor => its fields, in declared order
 */
function lowerPattern(
    Ast\AstNode $pattern,
    array $constructorRenames = [],
    array $fieldOrderByData = [],
): Pattern {
    $lower = static fn (Ast\AstNode $p): Pattern => lowerPattern($p, $constructorRenames, $fieldOrderByData);

    return match ($pattern::class) {
        Ast\PatWild::class => new PatWild(),
        Ast\PatVar::class => new PatVar($pattern->name),
        Ast\PatLit::class => new PatLit($pattern->value),
        Ast\PatChar::class => new PatChar($pattern->value),
        Ast\PatNil::class => new PatNil(),
        Ast\PatCons::class => new PatCons(
            $lower($pattern->head),
            $lower($pattern->tail),
        ),
        Ast\PatCon::class => new PatCon(
            $constructorRenames[$pattern->name] ?? $pattern->name,
            \array_map($lower, $pattern->args),
        ),
        Ast\PatTuple::class => new PatTuple(
            \array_map($lower, $pattern->elements),
        ),
        // A record pattern is a constructor pattern: the fields the source named, positionally,
        // with a wildcard for every field it did not. The IR has no record pattern, so every
        // backend reads a constructor's fields by position and nothing re-derives the order.
        Ast\PatRecord::class => new PatCon(
            $constructorRenames[$pattern->name] ?? $pattern->name,
            recordPatternArgs($pattern, $fieldOrderByData, $lower),
        ),
        default => throw new \RuntimeException('unsupported pattern in IR lowering'),
    };
}

/**
 * A record pattern's sub-patterns in the constructor's declared field order.
 *
 * @param array<string, list<string>> $fieldOrderByData
 * @param callable(Ast\AstNode): Pattern $lower
 * @return list<Pattern>
 */
function recordPatternArgs(Ast\AstNode $pattern, array $fieldOrderByData, callable $lower): array
{
    $order = $fieldOrderByData[$pattern->name]
        ?? throw new \RuntimeException("record pattern `{$pattern->name}`: unknown field order");

    $byName = [];
    foreach ($pattern->fields as $field) {
        $byName[$field->name] = $field->pattern;
    }

    $args = [];
    foreach ($order as $fieldName) {
        // A field the pattern does not name is not read.
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

    // Nested scopes must keep their enclosing module/function identity, or their SrcLocs lower
    // with an empty identity and source-map symbols come out `<unknown>`.
    if ($from !== null) {
        $ctx->moduleName = $from->moduleName;
        $ctx->sourceFile = $from->sourceFile;
        $ctx->functionName = $from->functionName;
        $ctx->stmtSrcLoc = $from->stmtSrcLoc;

        // Lookup tables describing the *program*, not the scope: a nested
        // context (case arm, action box) must answer the same questions about a
        // callee, or it lowers the call differently from its enclosing scope.
        $ctx->externalFnNames = $from->externalFnNames;
        $ctx->functionArity = $from->functionArity;
        $ctx->ioActionReturnFns = $from->ioActionReturnFns;
        $ctx->ioActionStore = $from->ioActionStore;
        $ctx->constructorRenames = $from->constructorRenames;
    }

    return $ctx;
}

/**
 * Allocate a unique IR local name for a source OccName binder.
 * Prefer the OccName when unused; otherwise OccName__N.
 */
function freshLocalName(LowerCtx $ctx, string $occ): string
{
    if ($occ !== '' && !isset($ctx->state->usedLocals[$occ])) {
        $ctx->state->usedLocals[$occ] = true;

        return $occ;
    }

    do {
        $name = $occ . '__' . $ctx->state->nextLocalSuffix++;
    } while (isset($ctx->state->usedLocals[$name]));

    $ctx->state->usedLocals[$name] = true;

    return $name;
}

/** @param array<string, true> $bound @return array<int, string> */
function freeVarsExpr(Ast\AstNode $expr, array $bound): array
{
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
        Ast\TypeAsc::class => freeVarsExpr($expr->expr, $bound),
        Ast\Apply::class => [...freeVarsExpr($expr->function, $bound), ...freeVarsExpr($expr->argument, $bound)],
        Ast\Infix::class => [...freeVarsExpr($expr->left, $bound), ...freeVarsExpr($expr->right, $bound)],
        Ast\Tuple::class,
        Ast\ListLit::class => freeVarsInExprs($expr->elements, $bound),
        Ast\Lambda::class => freeVarsExpr($expr->body, [
            ...$bound,
            ...array_fill_keys(patternParams($expr->params), true),
        ]),
        Ast\DoExpr::class => $expr->desugared !== null
            ? freeVarsExpr($expr->desugared, $bound)
            : throw new \RuntimeException('do expression reached free-variable analysis before desugaring'),
        Ast\Let::class => freeVarsLet($expr->bindings, $expr->body, $bound),
        Ast\Where::class => freeVarsLet($expr->bindings, $expr->expr, $bound),
        Ast\CaseExpr::class => [
            ...freeVarsExpr($expr->scrutinee, $bound),
            ...freeVarsInCaseAlts($expr->alts, $bound),
        ],
        Ast\GuardsExpr::class => freeVarsInGuards($expr->clauses, $bound),
        Ast\RecordCon::class => freeVarsInRecordFields($expr->fields, $bound),
        Ast\RecordUpdate::class => [
            ...freeVarsExpr($expr->object, $bound),
            ...freeVarsInRecordFields($expr->fields, $bound),
        ],
        Ast\FieldAccess::class => freeVarsExpr($expr->object, $bound),
        Ast\IntrinsicCall::class => freeVarsInExprs($expr->args, $bound),
        Ast\EvidenceRef::class => freeVarsInExprs($expr->context, $bound),
        Ast\EvidenceMethod::class => [
            ...(isset($bound[$expr->evidence]) ? [] : [$expr->evidence]),
            ...freeVarsInExprs($expr->contextEvidence, $bound),
        ],
        // Every binder-carrying form is listed above, so anything else holds
        // plain sub-expressions: recurse into them rather than listing the node
        // types, since a node that carries a value somewhere must not drop the
        // variables it holds.
        default => freeVarsInChildren($expr, $bound),
    };
}

function freeVarsInChildren(Ast\AstNode $expr, array $bound): array
{
    $free = [];
    foreach ($expr->childValues() as $child) {
        if ($child instanceof Ast\AstNode) {
            $free = [...$free, ...freeVarsExpr($child, $bound)];
        }
    }

    return array_values(array_unique($free));
}

/** @param list<Ast\RecordField> $fields @param array<string, true> $bound @return array<int, string> */
function freeVarsInRecordFields(array $fields, array $bound): array
{
    $free = [];
    foreach ($fields as $field) {
        $free = [...$free, ...freeVarsExpr($field->expr, $bound)];
    }

    return $free;
}

/** @param array<int, Ast\AstNode> $exprs @param array<string, true> $bound @return array<int, string> */
function freeVarsInExprs(array $exprs, array $bound): array
{
    $free = [];
    foreach ($exprs as $expr) {
        $free = [...$free, ...freeVarsExpr($expr, $bound)];
    }

    return array_values(array_unique($free));
}

/** @param array<int, Ast\Binding> $bindings @param array<string, true> $bound @return array<int, string> */
function freeVarsLet(array $bindings, Ast\AstNode $body, array $bound): array
{
    $groupBound = $bound;
    foreach ($bindings as $binding) {
        $groupBound = [...$groupBound, ...patternBoundNames($binding->pattern)];
    }

    $free = [];
    foreach ($bindings as $binding) {
        $free = [...$free, ...freeVarsExpr($binding->value, $groupBound)];
    }

    return [...$free, ...freeVarsExpr($body, $groupBound)];
}

/** @param array<int, Ast\Guarded> $clauses @param array<string, true> $bound @return array<int, string> */
function freeVarsInGuards(array $clauses, array $bound): array
{
    $free = [];
    foreach ($clauses as $clause) {
        $free = [...$free, ...freeVarsExpr($clause->guard, $bound), ...freeVarsExpr($clause->body, $bound)];
    }

    return array_values(array_unique($free));
}

/** @param array<int, Ast\Alt> $alts @param array<string, true> $bound @return array<int, string> */
function freeVarsInCaseAlts(array $alts, array $bound): array
{
    $free = [];
    foreach ($alts as $alt) {
        $free = [...$free, ...freeVarsExpr($alt->body, [...$bound, ...patternBoundNames($alt->pattern)])];
    }

    return array_values(array_unique($free));
}

/** @param array<int, Ast\LambdaParam|Ast\AstNode> $patterns @return array<int, string> */
function patternParams(array $patterns): array
{
    $params = [];
    foreach ($patterns as $index => $pattern) {
        $pattern = lambdaParamPattern($pattern);
        if ($pattern instanceof Ast\PatWild) {
            // `\_ -> e`: the argument is unused, but the IR parameter still needs a
            // name. Positional, so this is the same name here and in the free-variable
            // analysis that also calls this.
            $params[] = '__wild' . $index;
            continue;
        }
        if (!$pattern instanceof Ast\PatVar) {
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
