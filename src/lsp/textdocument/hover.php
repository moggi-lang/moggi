<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\Docs\classDeclHeader;
use function Moggi\Docs\constructorTypeSignature;
use function Moggi\Docs\dataDeclSignature;
use function Moggi\Docs\docForEntityName;
use function Moggi\Docs\dumpAstTypeForDocs;
use function Moggi\Docs\dumpTypeForDocs;
use function Moggi\Docs\friendlyAstTypeVarNames;
use function Moggi\Docs\nameTypeSignature;
use function Moggi\Docs\schemeToString;
use function Moggi\Docs\surfaceTypeSignature;
use function Moggi\LSP\Analysis\analyzeDirty;
use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Analysis\ensureDocIndex;
use function Moggi\LSP\Analysis\findNodeAt;
use function Moggi\LSP\Analysis\findNodeChainAt;
use function Moggi\LSP\Index\binderResolved;
use function Moggi\LSP\Index\declForResolved;
use function Moggi\LSP\Index\isBinderResolved;
use function Moggi\LSP\Protocol\compilerPosToLsp;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\LSP\Protocol\nodeToLspRange;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\Modules\resolvedSymbol;

function findCtorDecl(AnalysisService $svc, string $module, string $name): ?array
{
    $programs = [];
    $analysisProgram = null;
    if ($svc->project !== null) {
        foreach ($svc->project->checked as $prog) {
            if ($prog instanceof Ast\Program) {
                $programs[] = $prog;
            }
        }
    }
    foreach ($programs as $prog) {
        foreach ($prog->items as $item) {
            if (!($item instanceof Ast\DataDecl)) {
                continue;
            }
            foreach ($item->constructors as $ctor) {
                if ($ctor->name === $name) {
                    $child = findModuleDeclByName($svc, $module, $name);

                    return ['decl' => $item, 'ctor' => $ctor, 'child' => $child];
                }
            }
        }
    }

    return null;
}

/**
 * Full declaration signature for a type/class/constructor word found outside
 * any positioned AST node (e.g. the name inside a multi-line `data` decl).
 * Looks the name up in the module index, then renders from the checked AST.
 */
function dataDeclSignatureIfAvailable(AnalysisService $svc, ?string $module, string $name): ?string
{
    foreach ($svc->project->checked as $prog) {
        if (!($prog instanceof Ast\Program)) {
            continue;
        }
        foreach ($prog->items as $item) {
            if (($item instanceof Ast\DataDecl || $item instanceof Ast\TypeSynonymDecl || $item instanceof Ast\ClassDecl)
                && $item->name === $name) {
                if ($item instanceof Ast\DataDecl) {
                    return dataDeclSignature($item);
                }
                if ($item instanceof Ast\TypeSynonymDecl) {
                    $rhs = surfaceTypeSignature($item->type);

                    return $item->name
                        . ($item->params !== [] ? ' ' . implode(' ', $item->params) : '')
                        . ' = ' . ($rhs ?? '_');
                }

                return classDeclHeader($item);
            }
        }
    }

    return $name;
}

function functionParamTypeSlice(Ast\FunctionDecl $fn, int $index): ?Ast\TypeNode
{
    if ($fn->type === null) {
        return null;
    }

    // Skip leading dict arrows (class constraints) like the signature printer.
    $type = $fn->type;
    while ($type instanceof Ast\TypeArrow
        && $type->from instanceof Ast\TypeCon
        && str_starts_with($type->from->name, '__Dict_')) {
        $type = $type->to;
    }

    for ($i = 0; $i < $index; $i++) {
        if (!$type instanceof Ast\TypeArrow) {
            return null;
        }
        $type = $type->to;
    }

    return $type instanceof Ast\TypeArrow ? $type->from : null;
}

/**
 * Render a sliced parameter type with the *function's* rename map, so its
 * type-variable letters match the function's own hover.
 */
function functionParamSignature(Ast\FunctionDecl $fn, string $name, Ast\TypeNode $slice): string
{
    $rename = friendlyAstTypeVarNames([$fn->type]);

    return $name . ' :: ' . dumpAstTypeForDocs($slice, $rename);
}

/**
 * `name :: Type` for a parameter or local binder node. Parameters prefer the
 * enclosing function's scheme slice (consistent letters); other binders use
 * their own (re-snapshotted) inferred type.
 */
function selfBinderSignature(?Ast\FunctionDecl $fn, Ast\AstNode $binder): ?string
{
    $name = $binder->name ?? null;
    if (!\is_string($name) || $name === '') {
        return null;
    }
    if ($fn !== null && $binder instanceof Ast\PatVar) {
        foreach ($fn->params as $i => $param) {
            if ($param === $binder) {
                $slice = functionParamTypeSlice($fn, (int) $i);
                if ($slice !== null) {
                    return functionParamSignature($fn, $name, $slice);
                }
            }
        }
    }
    if ($binder->inferredType !== null) {
        return nameTypeSignature($name, $binder->inferredType);
    }

    return null;
}

/**
 * Whether the AST subtree rooted at $root contains $needle (identity match).
 * Type nodes are not descended into: they are shared, non-positional.
 */
function astTreeContains(object $root, Ast\AstNode $needle): bool
{
    if ($root === $needle) {
        return true;
    }
    if ($root instanceof Ast\TypeNode) {
        return false;
    }
    foreach (get_object_vars($root) as $prop) {
        if (is_object($prop)) {
            if (astTreeContains($prop, $needle)) {
                return true;
            }
        } elseif (is_array($prop)) {
            foreach ($prop as $elem) {
                if (is_object($elem) && astTreeContains($elem, $needle)) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * The top-level FunctionDecl whose tree contains the given node (identity
 * match). Top-level declarations carry no source positions, so chain-based
 * ancestry lookups can never reach them; binders (parameters, where/let
 * patterns, lambda parameters) and their uses are matched by containment.
 */
function enclosingFunctionOfNode(Ast\Program $program, ?Ast\AstNode $node): ?Ast\FunctionDecl
{
    if ($node === null) {
        return null;
    }
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl && astTreeContains($item, $node)) {
            return $item;
        }
    }

    return null;
}

/**
 * `name :: Type` for the use site of a local binder: parameters slice their
 * type from the enclosing function's scheme (matched by binder identity, so
 * letters match the function hover); other binders fall back to their own
 * inferred type.
 */
function paramSignatureForUse(?Ast\FunctionDecl $fn, Ast\AstNode $use): ?string
{
    $binderId = $use->binderId ?? null;
    $name = $use->name ?? null;
    if (!\is_int($binderId) || !\is_string($name) || $name === '' || $fn === null) {
        return null;
    }
    foreach ($fn->params as $i => $param) {
        if ($param instanceof Ast\PatVar && $param->binderId === $binderId) {
            $slice = functionParamTypeSlice($fn, (int) $i);

            return $slice !== null ? functionParamSignature($fn, $name, $slice) : null;
        }
    }
    if ($use->inferredType !== null) {
        return nameTypeSignature($name, $use->inferredType);
    }

    return null;
}

function svcHover(AnalysisService $svc, string $uri, array $pos): ?array
{
    $svc->analyzeDirty();
    $svc->ensureDocIndex();
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $comp = lspPosToCompiler($analysis->source, $pos);
    $word = wordAt($analysis->source, $comp['line'], $comp['col']);
    $node = findNodeAt($analysis->program, $comp['line'], $comp['col']);
    $parts = [];
    $seen = [];
    $range = null;
    $name = null;
    $resolved = null;

    $push = static function (string $part) use (&$parts, &$seen): void {
        if ($part === '' || isset($seen[$part])) {
            return;
        }
        $seen[$part] = true;
        $parts[] = $part;
    };
    $lastTypeSig = null;
    $pushType = static function (string $sig) use ($push, &$lastTypeSig): void {
        $lastTypeSig = $sig;
        $push("```moggi\n{$sig}\n```");
    };

    $module = $analysis->program instanceof Ast\Program ? ($analysis->program->module ?? 'Main') : 'Main';

    // Identifier tokens span [col, col+len); a 1-char variable hovered at its
    // end column has no covering AST node (end is exclusive). Retry at the
    // token's start so short binders still resolve.
    if ($node === null && $word !== null && $word !== '') {
        $lines = splitLines($analysis->source);
        $text = $lines[$comp['line'] - 1] ?? '';
        $idx = max(0, $comp['col'] - 1);
        $left = $idx;
        while ($left > 0 && preg_match('/[A-Za-z0-9_\']/', $text[$left - 1] ?? '')) {
            $left--;
        }
        if ($left < $idx) {
            $node = findNodeAt($analysis->program, $comp['line'], $left + 1);
        }
    }

    $enclosingFn = $node instanceof Ast\AstNode
        ? enclosingFunctionOfNode($analysis->program, $node)
        : null;

    // ---- A record field label answers for the field, not for a declaration.
    $fieldHover = recordFieldHover($svc, $analysis, $node, $word, $comp['line'], $comp['col'], $uri);
    if ($fieldHover !== null || ($node !== null && !$node instanceof Ast\AstNode)) {
        return $fieldHover;
    }

    if ($node !== null) {
        // ---- Typed holes keep their dedicated presentation.
        if ($node instanceof Ast\ExprHole) {
            $holeType = '';
            if ($node->inferredType !== null) {
                $holeType = dumpTypeForDocs($node->inferredType);
            }
            if ($holeType === '') {
                $holeType = holeTypeFromDiagnostics($analysis->diagnostics, $pos);
            }
            if ($holeType !== '') {
                $pushType("_ :: {$holeType}");
                $push('_typed hole_ — expected type shown above');
                // Show expression context (e.g. "1.2 + _")
                $chain = findNodeChainAt($analysis->program, $comp['line'], $comp['col']);
                $ctxNode = null;
                foreach (array_reverse($chain) as $cand) {
                    if ($cand !== $node && ($cand->line ?? 0) > 0) {
                        $ctxNode = $cand;
                        break;
                    }
                }
                if ($ctxNode !== null) {
                    $ctxRange = nodeToLspRange($analysis->source, $ctxNode);
                    $ctxLineIdx = (int) $ctxRange['start']['line'];
                    $ctxColStart = (int) $ctxRange['start']['character'];
                    $ctxColEnd = (int) $ctxRange['end']['character'];
                    $lines = splitLines($analysis->source);
                    if (isset($lines[$ctxLineIdx])) {
                        $ctxText = substr($lines[$ctxLineIdx], $ctxColStart, $ctxColEnd - $ctxColStart);
                        if ($ctxText !== '' && $ctxText !== '_') {
                            $pushType($ctxText);
                        }
                    }
                }
            } else {
                $push('_typed hole_');
            }
            if (($node->line ?? 0) > 0) {
                $range = nodeToLspRange($analysis->source, $node);
            }
            return [
                'contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $parts)],
                ...(\is_array($range) ? ['range' => $range] : []),
            ];
        }

        // ---- References resolve by name first.
        if ($node instanceof Ast\Variable
            || $node instanceof Ast\OperatorRef
            || $node instanceof Ast\ConstructorRef) {
            $name = $node->name;
            if ($node instanceof Ast\Variable && $node->binderId !== null) {
                // Binder identity wins: resolvedOrigin names the binder's
                // *home* function (`Basic::f`), which is not a navigable def.
                $resolved = binderResolved($module, $node->binderId);
                $push('_local binding_');
            } else {
                $resolved = $node->resolvedOrigin ?? null;
            }
        } elseif ($node instanceof Ast\QualifiedRef) {
            $name = $node->name;
            $resolved = $node->backendResolved;
            $push('qualified `' . $node->module . '.' . $node->name . '`');
        } elseif ($node instanceof Ast\PatVar) {
            $name = $node->name;
            if ($node->binderId !== null) {
                $resolved = binderResolved($module, $node->binderId);
                $push('_local binding_');
                $sig = selfBinderSignature($enclosingFn, $node);
                if ($sig !== null) {
                    $pushType($sig);
                }
            }
        } elseif ($node instanceof Ast\FunctionDecl) {
            $name = $node->name;
            $resolved = resolvedSymbol($module, $node->name);
        } elseif ($node instanceof Ast\DataDecl) {
            $name = $node->name;
            $resolved = resolvedSymbol($module, $node->name);
        } elseif ($node instanceof Ast\ConstructorDecl) {
            // Hovering the constructor name inside its `data` declaration.
            $name = $node->name;
        }
    }

    // ---- Type shown for the hovered identifier.
    $typeSig = null;
    $kindLabel = null;
    $definedIn = null; // [file, 1-based line] for local declaration nodes
    if ($node instanceof Ast\FunctionDecl) {
        // The signature the declaration spells, inferred or written: the
        // checker's own type carries the dictionary arrows it passes around
        // (`__Dict_Num -> t -> t`), which is not what the source says.
        $signature = $node->inferredSignatureType ?? $node->type;
        if ($signature !== null) {
            $typeSig = nameTypeSignature($node->name, $signature);
        }
        if ($typeSig === null
            && $analysis->program instanceof Ast\Program
            && isset($analysis->program->exportedInferredSchemes[$node->name])) {
            $typeSig = $node->name . ' :: '
                . schemeToString($analysis->program->exportedInferredSchemes[$node->name]);
        }
        $kindLabel = 'function';
        if (($node->line ?? 0) > 0) {
            $definedIn = [basename(str_replace('file:///', '', $uri)), $node->line];
        }
    } elseif ($node instanceof Ast\DataDecl) {
        $typeSig = dataDeclSignature($node);
        $kindLabel = $node->isNewtype ? 'newtype' : 'algebraic data type';
        if (($node->line ?? 0) > 0) {
            $definedIn = [basename(str_replace('file:///', '', $uri)), $node->line];
        }
    } elseif ($node instanceof Ast\TypeSynonymDecl) {
        $rhs = surfaceTypeSignature($node->type);
        $typeSig = $node->name
            . ($node->params !== [] ? ' ' . implode(' ', $node->params) : '')
            . ' = ' . ($rhs ?? '_');
        $kindLabel = 'type synonym';
        if (($node->line ?? 0) > 0) {
            $definedIn = [basename(str_replace('file:///', '', $uri)), $node->line];
        }
    } elseif ($node instanceof Ast\ClassDecl) {
        $typeSig = classDeclHeader($node);
        $kindLabel = 'type class';
        if (($node->line ?? 0) > 0) {
            $definedIn = [basename(str_replace('file:///', '', $uri)), $node->line];
        }
    } elseif (($node instanceof Ast\ConstructorRef || $node instanceof Ast\ConstructorDecl) && $name !== null) {
        // Show the constructor's own signature, not the bare result type.
        // Covers both the use site (ConstructorRef) and the name inside the
        // `data` declaration (ConstructorDecl).
        $ctorInfo = findCtorDecl($svc, $module, $name);
        if ($ctorInfo !== null) {
            $typeSig = constructorTypeSignature($ctorInfo['decl'], $ctorInfo['ctor']);
            $kindLabel = 'constructor';
            $ctorRange = $ctorInfo['child']->selectionRange ?? null;
            if ($ctorRange !== null) {
                $definedIn = [basename(str_replace('file:///', '', $ctorInfo['child']->uri)), ((int) ($ctorRange['start']['line'] ?? 0)) + 1];
            } elseif (($ctorInfo['decl']->line ?? 0) > 0) {
                $definedIn = [basename(str_replace('file:///', '', $uri)), $ctorInfo['decl']->line];
            }
        }
    }
    // ---- Parameter / binder / use hovers.
    if ($typeSig === null && $node !== null) {
        if ($node instanceof Ast\PatVar) {
            // Binder positions: their own inferred type (re-snapshotted after
            // checking), else the enclosing function's scheme.
            $typeSig = selfBinderSignature($enclosingFn, $node)
                ?? nameTypeSignature($node->name, $node->inferredType);
        } elseif ($node instanceof Ast\Variable && $node->binderId !== null) {
            // Use of a local binder: for parameters, slice the type out of the
            // enclosing function's scheme so the letters match its hover;
            // otherwise the binder's own type.
            $typeSig = paramSignatureForUse($enclosingFn, $node)
                ?? nameTypeSignature($node->name, $node->inferredType);
        } elseif ($node instanceof Ast\Infix) {
            // Operator application: the expression's type *plus* the operator
            // function's own signature, docs and definition location.
            if ($node->inferredType !== null) {
                $typeSig = nameTypeSignature(null, $node->inferredType);
            }
            $name = $node->operator;
        }
        // Top-level / imported references (Variables without a binderId) fall
        // through to the declaration lookup below so their hover carries the
        // full `name :: type` signature instead of a bare type.
    }
    if ($typeSig === null && $node !== null && $name === null && $node->inferredType !== null) {
        // Plain expression hover (applications, literals, lambdas, …): the type.
        $typeSig = nameTypeSignature(null, $node->inferredType);
    }
    if ($typeSig !== null) {
        $pushType($typeSig);
    }

    // ---- Constraints (class context) from pending evidence.
    if ($node !== null && $node->pendingConstraints !== []) {
        $cs = [];
        foreach ($node->pendingConstraints as $c) {
            if (\is_object($c) && isset($c->class)) {
                $arg = '';
                if (isset($c->args) && \is_array($c->args)) {
                    $argParts = [];
                    foreach ($c->args as $a) {
                        if ($a instanceof Ast\TypeNode) {
                            $argParts[] = dumpTypeForDocs($a);
                        }
                    }
                    $arg = $argParts !== [] ? ' ' . implode(' ', $argParts) : '';
                }
                $cs[] = $c->class . $arg;
            }
        }
        if ($cs !== []) {
            $push('**constraints:** `' . implode('`, `', array_unique($cs)) . '`');
        }
    }

    // ---- Documentation from the node itself (parser attaches mogdoc).
    if ($node !== null && $node->doc) {
        $push($node->doc);
    }

    // ---- Cross-module docs + origin.
    if ($name !== null) {
        $resolved ??= $analysis->program->externalFns[$name]
            ?? (isset($analysis->declarations[$name])
                ? resolvedSymbol($module, $name)
                : null);
    }
    $mogdocDone = false;
    if ($resolved !== null && !isBinderResolved($resolved)) {
        $def = $svc->modules->declForResolved($resolved);
        if ($def !== null) {
            if ($typeSig === null && $def->type !== null
                && !in_array($def->type, ['data', 'newtype', 'type', 'class', 'function'], true)) {
                $pushType(($def->name !== '' ? $def->name . ' :: ' : '') . $def->type);
            }
            if ($def->doc) {
                $push($def->doc);
                $mogdocDone = true;
            }
            $file = basename(str_replace('file:///', '', $def->uri ?? $uri));
            $line = (int) ($def->selectionRange['start']['line']
                ?? $def->range['start']['line']
                ?? 0) + 1;
            $push("**defined in:** {$file}:{$line}");
        } else {
            $push("**origin:** `{$resolved}`");
        }
    }
    if ($name !== null && isset($analysis->declarations[$name])) {
        $decl = $analysis->declarations[$name];
        if ($typeSig === null && !empty($decl['type']) && $decl['type'] !== 'function') {
            $pushType("{$name} :: {$decl['type']}");
        }
        if ($typeSig === null && ($decl['type'] ?? '') === 'function'
            && $resolved !== null && !isBinderResolved($resolved)) {
            $def = $svc->modules->declForResolved($resolved);
            if ($def !== null && $def->type !== null && $def->type !== '') {
                $pushType("{$name} :: {$def->type}");
            }
        }
        if (!empty($decl['doc'])) {
            $push((string) $decl['doc']);
            $mogdocDone = true;
        }
        $label = declKindLabel($decl);
        if ($label !== '' && $kindLabel === null) {
            $kindLabel = $label;
        }
    }
    if ($definedIn !== null) {
        $hasDefinedIn = false;
        foreach ($parts as $p) {
            if (str_starts_with($p, '**defined in:**')) {
                $hasDefinedIn = true;
                break;
            }
        }
        if (!$hasDefinedIn) {
            $push("**defined in:** {$definedIn[0]}:{$definedIn[1]}");
        }
    }
    if ($kindLabel === 'function' && ($lastTypeSig === null || !str_contains($lastTypeSig, '->'))) {
        // A nullary top-level binding is a value, not a function.
        $kindLabel = null;
    }
    if ($kindLabel !== null) {
        $push("_{$kindLabel}_");
    }

    // ---- Moogle docs for stdlib / imported entities without local mogdoc.
    if (!$mogdocDone && $name !== null && $svc->docIndex !== null) {
        $doc = docForEntityName($svc->docIndex, $name, $seen);
        if ($doc !== null) {
            $push($doc);
        }
    }

    // ---- Fallbacks for keyword / word-only positions.
    if ($parts === [] && $word !== null) {
        $mod = $module;
        $decl = findModuleDeclByName($svc, $mod, $word);
        if ($decl !== null) {
            if (in_array($decl->type, ['data', 'newtype'], true)) {
                // A constructor child word on its `data` line: real signature.
                $ctorData = findCtorDecl($svc, $mod, $word);
                if ($ctorData !== null) {
                    $sig = constructorTypeSignature($ctorData['decl'], $ctorData['ctor']);
                    if ($sig !== null) {
                        $pushType($sig);
                    }
                    $kindLabel = 'constructor';
                } else {
                    $pushType(dataDeclSignatureIfAvailable($svc, $mod, $word));
                }
            } elseif ($decl->type !== null && !in_array($decl->type, ['type', 'class'], true)) {
                $pushType("{$decl->name} :: {$decl->type}");
            } else {
                $pushType(dataDeclSignatureIfAvailable($svc, $mod, $word));
            }
            if ($decl->doc) {
                $push($decl->doc);
            }
            $moduleFile = basename(str_replace('file:///', '', $decl->uri));
            $push('**defined in:** ' . $moduleFile . ':'
                . (((int) ($decl->selectionRange['start']['line'] ?? 0)) + 1));
        }
    }
    if ($parts === [] && $node !== null && $node->inferredType !== null) {
        // Last resort: a typed expression whose declaration is not indexed.
        $sig = nameTypeSignature(null, $node->inferredType);
        if ($sig !== null) {
            $pushType($sig);
        }
    }
    if ($parts === []) {
        // Bare `_` may not resolve as a word; still show hole type from diags.
        $holeType = holeTypeFromDiagnostics($analysis->diagnostics ?? [], $pos);
        if ($holeType !== '') {
            return [
                'contents' => [
                    'kind' => 'markdown',
                    'value' => "```moggi\n_ :: {$holeType}\n```\n\n_typed hole_",
                ],
            ];
        }
        return keywordHover($word);
    }
    if ($range === null && $node !== null && ($node->line ?? 0) > 0) {
        $range = nodeToLspRange($analysis->source, $node);
    }
    return [
        'contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $parts)],
        ...(\is_array($range) ? ['range' => $range] : []),
    ];
}

/**
 * Hover on a field label: a projection (`person.name`), a label inside a
 * construction or update (`Person { name = … }`, `p { age = … }`), or a label in
 * a record pattern (`Person { name = n }`).
 *
 * A field name in Moggi belongs to its record rather than to the module, so the
 * answer is the field's own type plus the record that declares it — the same
 * information completion offers at the label.
 *
 * @return ?array{contents: array{kind: string, value: string}, range?: array<string, mixed>}
 */
function recordFieldHover(AnalysisService $svc, object $analysis, ?object $node, ?string $word, int $line, int $col, string $uri): ?array
{
    if (!$analysis->program instanceof Ast\Program) {
        return null;
    }
    $program = $analysis->program;
    $range = null;
    if ($node instanceof Ast\RecordField || $node instanceof Ast\PatField || $node instanceof Ast\CtorField) {
        $field = $node->name;
        $owner = recordFieldOwner($program, $line, $col);
        $range = nodeToLspRange($analysis->source, $node);
    } elseif ($node instanceof Ast\FieldAccess && $word === $node->field && $node->endCol > $node->col) {
        // The projection node spans receiver *and* label; point at the label,
        // whose last character is `endCol` (inclusive).
        $field = $node->field;
        $owner = recordTypeHeadOf($program, $node->object);
        $range = [
            'start' => compilerPosToLsp($analysis->source, $node->line, $node->endCol - \strlen($field) + 1),
            'end' => compilerPosToLsp($analysis->source, $node->line, $node->endCol + 1),
        ];
    } else {
        return null;
    }
    if ($owner === null || $field === '') {
        return null;
    }
    $record = recordDeclFor($svc, $analysis, $owner);
    if ($record === null) {
        return null;
    }
    $fieldDecl = null;
    foreach ($record['decl']->constructors as $ctor) {
        foreach ($ctor->fields as $candidate) {
            if ($candidate->name === $field) {
                $fieldDecl = $candidate;
                break 2;
            }
        }
    }
    if ($fieldDecl === null) {
        return null;
    }

    $parts = [];
    $signature = nameTypeSignature($field, $fieldDecl->type);
    if ($signature !== null) {
        $parts[] = "```moggi\n{$signature}\n```";
    }
    $parts[] = 'field of `' . $record['decl']->name . '`';
    // The field's own line in the open file; the `data` line for an import,
    // which is where the module index knows the record from.
    [$file, $declLine] = $record['program'] === $analysis->program && $fieldDecl->line > 0
        ? [basename(str_replace('file:///', '', $uri)), $fieldDecl->line]
        : recordDeclLocation($svc, $record, $uri);
    $parts[] = "**defined in:** {$file}:{$declLine}";

    return [
        'contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $parts)],
        ...($range !== null ? ['range' => $range] : []),
    ];
}

/**
 * `[file, line]` of a record's `data` declaration: the module index knows the
 * file for an imported record, the AST line is the answer for the open one.
 *
 * @param array{decl: Ast\DataDecl, ctor: Ast\ConstructorDecl, module: string} $record
 * @return array{string, int}
 */
function recordDeclLocation(AnalysisService $svc, array $record, string $uri): array
{
    $info = findModuleDeclByName($svc, $record['module'], $record['decl']->name);
    if ($info !== null) {
        return [
            basename(str_replace('file:///', '', $info->uri)),
            ((int) ($info->selectionRange['start']['line'] ?? 0)) + 1,
        ];
    }

    return [basename(str_replace('file:///', '', $uri)), (int) $record['decl']->line];
}

function keywordHover(?string $word): ?array
{
    if ($word === null || $word === '') {
        return null;
    }
    foreach (keywordCompletionItems() as $item) {
        if ($item['label'] === $word) {
            return [
                'contents' => [
                    'kind' => 'markdown',
                    'value' => '**`' . $word . '`** — ' . $item['detail'] . "\n\n" . $item['documentation'],
                ],
            ];
        }
    }
    return null;
}

function wordAt(string $source, int $line, int $col): ?string
{
    $lines = preg_split("/\r\n|\n|\r/", $source) ?: [];
    $text = $lines[$line - 1] ?? '';
    if ($text === '') {
        return null;
    }
    $idx = max(0, $col - 1);
    if ($idx >= strlen($text)) {
        $idx = strlen($text) - 1;
    }
    if ($idx < 0) {
        return null;
    }
    $left = $idx;
    while ($left > 0 && preg_match('/[A-Za-z0-9_\']/', $text[$left - 1])) {
        $left--;
    }
    $right = $idx;
    while ($right < strlen($text) && preg_match('/[A-Za-z0-9_\']/', $text[$right])) {
        $right++;
    }
    $word = substr($text, $left, $right - $left);
    return $word !== '' ? $word : null;
}
