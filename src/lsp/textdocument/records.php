<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\Docs\surfaceTypeSignature;
use function Moggi\LSP\Analysis\findNodeChainAt;

/**
 * Record knowledge shared by completion and hover: which `data` declaration a
 * field label belongs to, which fields that record has, and which record type
 * an expression is standing in for.
 *
 * A field name in Moggi belongs to its record, not to the module (there are no
 * selector functions), so every answer here has to start from a record type and
 * then look a label up inside it.
 */

/**
 * The current document's parse tree first, then every checked module — the
 * working file wins, so an edit being made answers from what the reader wrote.
 *
 * @return list<Ast\Program>
 */
function recordPrograms(AnalysisService $svc, object $analysis): array
{
    $programs = [];
    if (($analysis->program ?? null) instanceof Ast\Program) {
        $programs[] = $analysis->program;
    }
    foreach ($svc->project->checked ?? [] as $prog) {
        if ($prog instanceof Ast\Program) {
            $programs[] = $prog;
        }
    }

    return $programs;
}

/**
 * The `data` declaration that declares a record, given either the type name
 * (`Person { … }` writes the type's only constructor) or a constructor name.
 * The record constructor is the one that has named fields.
 *
 * @return ?array{decl: Ast\DataDecl, ctor: Ast\ConstructorDecl, module: string, program: Ast\Program}
 */
function recordDeclFor(AnalysisService $svc, object $analysis, string $name): ?array
{
    foreach (recordPrograms($svc, $analysis) as $prog) {
        foreach ($prog->items as $item) {
            if (!$item instanceof Ast\DataDecl) {
                continue;
            }
            $ctor = recordCtorOf($item, $name);
            if ($ctor === null) {
                continue;
            }

            return ['decl' => $item, 'ctor' => $ctor, 'module' => $prog->module ?? 'Main', 'program' => $prog];
        }
    }

    return null;
}

/** The record constructor of `$decl` that `$name` names, as a type or a constructor. */
function recordCtorOf(Ast\DataDecl $decl, string $name): ?Ast\ConstructorDecl
{
    foreach ($decl->constructors as $ctor) {
        if (($ctor->name === $name || $decl->name === $name) && $ctor->isRecord()) {
            return $ctor;
        }
    }

    return null;
}

/**
 * Named fields of a record, keyed by label in declaration order. Accepts the
 * type name or a constructor name, like `recordDeclFor`.
 *
 * @return array<string, array{type: ?string, ctor: string}>
 */
function recordFieldEntries(AnalysisService $svc, object $analysis, string $name): array
{
    $record = recordDeclFor($svc, $analysis, $name);
    if ($record === null) {
        return [];
    }
    $entries = [];
    foreach ($record['decl']->constructors as $ctor) {
        foreach ($ctor->fields as $field) {
            if ($field->name === '' || isset($entries[$field->name])) {
                continue;
            }
            $entries[$field->name] = [
                'type' => surfaceTypeSignature($field->type),
                'ctor' => $ctor->name,
            ];
        }
    }

    return $entries;
}

/**
 * Fields of the record a name refers to, for completion at a brace: a
 * constructor name, a record type, or the receiver of an update. Read from the
 * parsed declaration when the buffer parses, and from the source text when it
 * does not — a record half written is not yet a parse tree, and that is exactly
 * when the labels are wanted.
 *
 * @return ?array{name: string, fields: array<string, array{type: ?string, ctor: string}>}
 */
function recordFieldsForHead(AnalysisService $svc, object $analysis, string $head, int $brace): ?array
{
    $entries = recordFieldEntries($svc, $analysis, $head);
    if ($entries !== []) {
        return ['name' => $head, 'fields' => $entries];
    }
    $program = ($analysis->program ?? null) instanceof Ast\Program ? $analysis->program : null;
    $typeHead = $program !== null ? binderTypeHead($program, $head) : null;
    $fromBinder = $typeHead !== null ? recordFieldEntries($svc, $analysis, $typeHead) : [];
    if ($fromBinder !== []) {
        return ['name' => (string) $typeHead, 'fields' => $fromBinder];
    }

    // Unparsed buffer: `Person { … }` names the constructor, `p { … }` the
    // receiver whose type is read off the enclosing signature.
    $fields = recordFieldsFromText($analysis->source, $head);
    if ($fields !== []) {
        return ['name' => $head, 'fields' => $fields];
    }
    $typeHead = binderTypeHeadFromText($analysis->source, $head, $brace);
    if ($typeHead === null) {
        return null;
    }
    $fields = recordFieldsFromText($analysis->source, $typeHead);

    return $fields === [] ? null : ['name' => $typeHead, 'fields' => $fields];
}

/**
 * Record fields read straight from the source text: every `Head { label ::
 * Type, … }` block whose head is `$name`. The mid-edit path — the parse tree
 * has no declarations at all while a brace is open.
 *
 * @return array<string, array{type: ?string, ctor: string}>
 */
function recordFieldsFromText(string $source, string $name): array
{
    $entries = [];
    foreach (braceBlocks($source) as [$head, $body]) {
        if ($head !== $name) {
            continue;
        }
        foreach (splitTopLevel($body, ',') as $part) {
            if (!preg_match('/^[\s]*([A-Za-z_][A-Za-z0-9_\']*)[\s]*::[\s]*(.+)$/s', $part, $m)) {
                continue;
            }
            $label = $m[1];
            if (isset($entries[$label])) {
                continue;
            }
            $entries[$label] = ['type' => collapseSpace($m[2]), 'ctor' => $name];
        }
    }

    return $entries;
}

/**
 * Every brace group in the source as `[head, body]`, where the head is the name
 * written in front of the `{` (`Address` in `Address { … }`), if any. Strings,
 * comments and `{-# … #-}` pragmas are skipped.
 *
 * @return list<array{string, string}>
 */
function braceBlocks(string $source): array
{
    $blocks = [];
    $open = [];
    $len = \strlen($source);
    for ($i = 0; $i < $len; $i++) {
        $c = $source[$i];
        if ($c === '"' || $c === "'") {
            $i = skipQuoted($source, $i, $c) - 1;
            continue;
        }
        if ($c === '-' && ($source[$i + 1] ?? '') === '-') {
            $nl = strpos($source, "\n", $i);
            $i = ($nl === false ? $len : $nl) - 1;
            continue;
        }
        if ($c === '{' && ($source[$i + 1] ?? '') === '-') {
            $i = skipBlockComment($source, $i) - 1;
            continue;
        }
        if ($c === '{') {
            $open[] = $i;
            continue;
        }
        if ($c === '}' && $open !== []) {
            $at = (int) array_pop($open);
            $head = recordHeadBefore($source, $at);
            if ($head !== null) {
                $blocks[] = [$head, substr($source, $at + 1, $i - $at - 1)];
            }
        }
    }

    return $blocks;
}

/**
 * Type head of a parameter read from the source: the signature of the binding
 * whose parameters include it, indexed by the parameter's position — the whole
 * `Person -> Person` chain for a `where`-bound helper, since its signature is
 * the enclosing function's. Null when no signature reaches the binder, which is
 * the normal case for a lambda.
 */
function binderTypeHeadFromText(string $source, string $binder, int $limit): ?string
{
    $params = null;
    $head = null;
    foreach (explode("\n", substr($source, 0, $limit)) as $line) {
        if (!preg_match('/^[ \t]*([A-Za-z_][A-Za-z0-9_\']*)((?:\s+(?:[A-Za-z_][A-Za-z0-9_\']*|_|\([^()]*\)|\[[^\]]*\]))*)\s*=/', $line, $m)) {
            continue;
        }
        $candidates = $m[2] === '' ? [] : (preg_split('/\s+/', trim($m[2])) ?: []);
        if (!in_array($binder, $candidates, true) || reservedBindingHead($m[1])) {
            continue;
        }
        $head = $m[1];
        $params = $candidates;
    }
    if ($head === null || $params === null) {
        return null;
    }
    $signature = signatureOf($source, $head) ?? signatureOf($source, enclosingTopLevelHead($source, $limit));
    if ($signature === null) {
        return null;
    }
    $domains = splitTopLevel(preg_replace('/^.*?=>/', '', $signature) ?? $signature, '->');
    $position = array_search($binder, $params, true);
    $domain = $domains[\is_int($position) ? $position : 0] ?? null;
    if ($domain === null) {
        return null;
    }

    return preg_match('/^\s*([A-Z][A-Za-z0-9_.]*)/', $domain, $m) ? $m[1] : null;
}

/** A keyword that looks like a binding (`let p = …`) but names no function. */
function reservedBindingHead(string $name): bool
{
    return in_array($name, ['let', 'where', 'do', 'in', 'case', 'of', 'if', 'then', 'else', 'module', 'import', 'data', 'newtype', 'type', 'class', 'instance', 'foreign'], true);
}

/** The written type of a binding, from its `name :: Type` line. */
function signatureOf(string $source, ?string $name): ?string
{
    if ($name === null || $name === '') {
        return null;
    }
    if (preg_match('/^[ \t]*' . preg_quote($name, '/') . '[ \t]*::[ \t]*(.+)$/m', $source, $m)) {
        return trim($m[1]);
    }

    return null;
}

/** Name of the nearest top-level declaration above a position. */
function enclosingTopLevelHead(string $source, int $limit): ?string
{
    $head = null;
    foreach (explode("\n", substr($source, 0, $limit)) as $line) {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_\']*)\b/', $line, $m)) {
            $head = $m[1];
        }
    }

    return $head;
}

/**
 * Split text on a separator at nesting depth 0 (braces, brackets, parens and
 * strings are skipped).
 *
 * @return list<string>
 */
function splitTopLevel(string $text, string $separator): array
{
    $parts = [];
    $current = '';
    $depth = 0;
    $len = \strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        if ($c === '"' || $c === "'") {
            $end = skipQuoted($text, $i, $c);
            $current .= substr($text, $i, $end - $i);
            $i = $end - 1;
            continue;
        }
        if (str_contains('{([', $c)) {
            $depth++;
        } elseif (str_contains('})]', $c)) {
            $depth--;
        } elseif ($depth === 0 && substr($text, $i, \strlen($separator)) === $separator) {
            $parts[] = $current;
            $current = '';
            $i += \strlen($separator) - 1;
            continue;
        }
        $current .= $c;
    }
    $parts[] = $current;

    return $parts;
}

/** Whitespace runs in a written type collapse to single spaces. */
function collapseSpace(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/**
 * Record a field label was written in: the enclosing construction, update or
 * record pattern. Read off the covering AST chain, so the answer is the syntax
 * the cursor sits in rather than a name lookup that homonymous records would
 * make ambiguous.
 *
 * @return ?string the record's type-or-constructor name
 */
function recordFieldOwner(Ast\Program $program, int $line, int $col): ?string
{
    $chain = array_reverse(findNodeChainAt($program, $line, $col));
    foreach ($chain as $node) {
        if ($node instanceof Ast\RecordCon || $node instanceof Ast\PatRecord) {
            return $node->name;
        }
        if ($node instanceof Ast\RecordUpdate) {
            return $node->constructor ?? recordTypeHeadOf($program, $node->object);
        }
        if ($node instanceof Ast\DataDecl) {
            return $node->name;
        }
    }

    return null;
}

/**
 * Record type head of a record-valued expression: its inferred type when the
 * module checked, else the binder's written signature or the constructor its
 * definition body builds (which needs no successful typecheck).
 */
function recordTypeHeadOf(Ast\Program $program, Ast\AstNode $expr): ?string
{
    if ($expr->inferredType instanceof Ast\TypeNode) {
        $head = typeHeadName($expr->inferredType);
        if ($head !== null) {
            return $head;
        }
    }

    return $expr instanceof Ast\Variable ? binderTypeHead($program, $expr->name) : null;
}

/**
 * Type head of a top-level binder, from its written signature or the
 * constructor of its `Ctor { … }` / `Ctor` definition body. Works without a
 * successful typecheck (parse tree only).
 */
function binderTypeHead(Ast\Program $program, string $name): ?string
{
    foreach ($program->items as $item) {
        if (!$item instanceof Ast\FunctionDecl || $item->name !== $name) {
            continue;
        }
        if ($item->type instanceof Ast\TypeNode) {
            $head = typeHeadName($item->type);
            if ($head !== null) {
                return $head;
            }
        }

        return bodyTypeHead($item->body);
    }

    return null;
}

/** Type head of a definition body: `Ctor { … }` → Ctor, `C` → C, `p { … }` → the receiver's. */
function bodyTypeHead(Ast\AstNode $node): ?string
{
    if ($node instanceof Ast\RecordUpdate) {
        return bodyTypeHead($node->object);
    }
    if ($node instanceof Ast\RecordCon) {
        return $node->name;
    }
    if ($node instanceof Ast\ConstructorRef) {
        return $node->name;
    }
    if ($node instanceof Ast\TypeAsc) {
        return typeHeadName($node->type) ?? bodyTypeHead($node->expr);
    }
    if ($node instanceof Ast\Apply) {
        return bodyTypeHead($node->function);
    }
    if ($node instanceof Ast\Where) {
        return bodyTypeHead($node->expr);
    }

    return null;
}

/** The head constructor name of a type (unwraps applications). */
function typeHeadName(Ast\TypeNode $type): ?string
{
    $t = $type;
    while ($t instanceof Ast\TypeApp) {
        $t = $t->con;
    }

    return $t instanceof Ast\TypeCon ? $t->name : null;
}
