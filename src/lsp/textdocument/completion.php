<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\Syntax\Ast;

use function Moggi\Docs\search;
use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Analysis\findNodeChainAt;
use function Moggi\LSP\Index\declForResolved;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\Modules\resolvedSymbol;

function svcCompletion(AnalysisService $svc, string $uri, array $pos): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    $items = keywordCompletionItems();
    $prefix = '';
    $nearCase = false;
    if ($analysis !== null) {
        $comp = lspPosToCompiler($analysis->source, $pos);
        $lines = splitLines($analysis->source);
        $line = $lines[$comp['line'] - 1] ?? '';
        $before = substr($line, 0, max(0, $comp['col'] - 1));
        if (preg_match('/([A-Za-z_][A-Za-z0-9_\']*)$/', $before, $m)) {
            $prefix = $m[1];
        }
        $beforePrefix = rtrim(substr($before, 0, strlen($before) - strlen($prefix)));
        $module = $analysis->program instanceof Ast\Program ? ($analysis->program->module ?? 'Main') : 'Main';

        // ---- Import context: complete known module paths.
        if (preg_match('/\bimport\s+(?:qualified\s+)?(?:([A-Za-z_][A-Za-z0-9_.]*)\s+as\s+)?([A-Za-z0-9_.]*)$/', $beforePrefix . $prefix, $im)) {
            $typed = $im[2] !== '' ? $im[2] : ($im[1] ?? '');
            $seenMods = [];
            foreach ($svc->modules->modules as $modName => $mod) {
                $seenMods[$modName] = true;
                $items[] = [
                    'label' => $modName,
                    'kind' => 9,
                    'detail' => 'module',
                    'insertText' => $modName,
                    'sortText' => completionSortText($typed, $modName, 0),
                    'documentation' => ['kind' => 'markdown', 'value' => "Module `{$modName}` — " . count($mod['decls']) . " declarations."],
                ];
            }
            foreach ($svc->libDirs as $dir) {
                foreach (glob(rtrim($dir, '/') . '/*/*.mog') ?: [] as $file) {
                    $rel = basename(dirname($file)) . '/' . basename($file, '.mog');
                    if (isset($seenMods[$rel])) {
                        continue;
                    }
                    $seenMods[$rel] = true;
                    $items[] = [
                        'label' => $rel,
                        'kind' => 9,
                        'detail' => 'module (library)',
                        'insertText' => $rel,
                        'sortText' => completionSortText($typed, $rel, 1),
                    ];
                }
            }

            return ['isIncomplete' => false, 'items' => $items];
        }

        // ---- Member context: completing after `.` on a typed receiver.
        if (str_ends_with(rtrim($beforePrefix), '.') || preg_match('/\.\s*[A-Za-z0-9_\']*$/', $before)) {
            $memberItems = memberCompletionItems($svc, $analysis, $comp['line'], $comp['col'], $prefix);
            if ($memberItems !== null) {
                return ['isIncomplete' => false, 'items' => $memberItems];
            }
            // Unknown receiver type: fall through to plain identifier completion.
        }

        // ---- Record-label context: completing a field name inside `{ … }`.
        $recordItems = recordLabelItemsAtCursor($svc, $analysis, $comp, $prefix);
        if ($recordItems !== null) {
            return ['isIncomplete' => false, 'items' => $recordItems];
        }

        // Boost constructors when completing after `case`/`of`/`->` patterns.
        $window = implode("\n", array_slice($lines, max(0, $comp['line'] - 4), 5));
        $nearCase = (bool) preg_match('/\b(case|of)\b/', $window);
        foreach ($analysis->declarations as $name => $decl) {
            $docParts = [];
            if (!empty($decl['type']) && !in_array($decl['type'], ['function', 'data', 'newtype', 'type', 'class'], true)) {
                $docParts[] = "```moggi\n{$name} :: {$decl['type']}\n```";
            }
            if (!empty($decl['doc'])) {
                $docParts[] = $decl['doc'];
            }
            $item = [
                'label' => $name,
                'kind' => $decl['completionKind'] ?? 3,
                'detail' => $decl['type'] ?? '',
                'insertText' => $name,
                'sortText' => completionSortText($prefix, $name, 0),
                'data' => ['uri' => $uri, 'name' => $name, 'module' => $module],
            ];
            if ($docParts !== []) {
                $item['documentation'] = ['kind' => 'markdown', 'value' => implode("\n\n", $docParts)];
            }
            $items[] = $item;
        }
        foreach ($analysis->imports as $path => $alias) {
            $label = $alias ?? $path;
            $items[] = [
                'label' => $label,
                'kind' => 9,
                'detail' => 'import ' . $path,
                'insertText' => $label,
                'sortText' => completionSortText($prefix, $label, 1),
                'documentation' => [
                    'kind' => 'markdown',
                    'value' => "Imported module `{$path}`",
                ],
            ];
        }
        // Moogle-ranked cross-module suggestions (use index if already warm)
        $ranked = [];
        if ($prefix !== '' && $svc->docIndex !== null) {
            try {
                foreach (search($svc->docIndex, $prefix, 30) as $hit) {
                    $ranked[$hit->entity->module . '::' . $hit->entity->name] = $hit->score;
                }
            } catch (\Throwable) {
            }
        }
        $extra = 0;
        foreach ($svc->modules->modules as $mod) {
            if (($mod['uri'] ?? '') === $uri || $extra >= 80) {
                continue;
            }
            foreach ($mod['decls'] as $decl) {
                if ($extra >= 80) {
                    break;
                }
                $key = $decl->module . '::' . $decl->name;
                $scoreBoost = isset($ranked[$key]) ? (int) (1000 - min(999, $ranked[$key] * 10)) : 50;
                if ($nearCase && $decl->completionKind === 4) {
                    $scoreBoost = max(0, $scoreBoost - 20); // constructors first near case/of
                }
                $item = [
                    'label' => $decl->name,
                    'kind' => $decl->completionKind,
                    'detail' => trim(($decl->type ?? '') . ' — ' . $decl->module),
                    'insertText' => $decl->name,
                    'sortText' => completionSortText($prefix, $decl->name, $scoreBoost),
                    'commitCharacters' => [' ', '(', '.'],
                    'data' => [
                        'uri' => $uri,
                        'name' => $decl->name,
                        'module' => $decl->module,
                        'resolved' => $decl->resolved,
                    ],
                    'documentation' => [
                        'kind' => 'markdown',
                        'value' => trim(($decl->doc ?? '') . "\n\nfrom `" . $decl->module . '`'),
                    ],
                ];
                if (str_starts_with($decl->name, '<')) {
                    $item['labelDetails'] = ['description' => $decl->module];
                }
                $edits = buildAutoImportEdits($analysis->source, $uri, $decl->module);
                if ($edits !== []) {
                    $item['additionalTextEdits'] = $edits;
                }
                $items[] = $item;
                $extra++;
            }
        }
    }
    return ['isIncomplete' => false, 'items' => $items];
}

/**
 * Member completion after `.`: exact record fields for a typed receiver
 * (LSP 3.18 `CompletionItemKind.Field` with `insertTextMode: asIs`), falling
 * back to nullary constructor names when the receiver type is known but has
 * no fields. Returns null when the receiver's type is unknown so the caller
 * can offer plain identifier completion instead.
 *
 * Type resolution works in two layers:
 *  1. When the whole module typechecks, the receiver expression carries an
 *     `inferredType` (e.g. `Person { … } . ` on the AST).
 *  2. When the module has type errors (the normal mid-edit state — the
 *     checker aborts before stamping types), the receiver's top-level binder
 *     is looked up and its type head is taken from its written signature
 *     (`alice :: Person`) or its `Person { … }` construction in the parse
 *     tree, which needs no successful typecheck.
 *
 * @return ?list<array<string, mixed>>
 */
function memberCompletionItems(AnalysisService $svc, object $analysis, int $line, int $cursorCol, string $prefix): ?array
{
    if (!$analysis->program instanceof Ast\Program) {
        return null;
    }
    // The dot sits immediately before the typed prefix (whitespace tolerated).
    $src = splitLines($analysis->source);
    $lineText = $src[$line - 1] ?? '';
    $dotAt = $cursorCol - strlen($prefix) - strlen(rtrim(substr($lineText, 0, max(0, $cursorCol - strlen($prefix) - 1)), " \t")) - 1;
    $scan = $cursorCol - strlen($prefix) - 1;
    while ($scan >= 1 && (substr($lineText, $scan - 1, 1) === ' ' || substr($lineText, $scan - 1, 1) === "\t")) {
        $scan--;
    }
    $dotAt = ($scan >= 1 && substr($lineText, $scan - 1, 1) === '.') ? $scan : 0;
    if ($dotAt < 1) {
        return null;
    }
    // Receiver expression: the token(s) before the dot on this line.
    $head = trim(substr($lineText, 0, $dotAt - 1));
    if (preg_match('/([A-Za-z_][A-Za-z0-9_\']*)$/', $head, $m)) {
        $receiverName = $m[1];
    } else {
        return null;
    }

    $typeHead = null;
    // Layer 1: covering FieldAccess node with receiver type (fully-checked file).
    $chain = findNodeChainAt($analysis->program, $line, $dotAt - 1);
    foreach ($chain as $node) {
        if ($node instanceof Ast\FieldAccess) {
            $recvType = $node->object->inferredType ?? null;
            if ($recvType instanceof Ast\TypeNode) {
                $typeHead = typeHeadName($recvType);
            }
            break;
        }
    }
    // Layer 2: binder signature / parse-tree construction (error recovery).
    if ($typeHead === null) {
        $typeHead = binderTypeHead($analysis->program, $receiverName);
    }
    if ($typeHead === null) {
        return null;
    }
    $fields = fieldItemsForType($svc, $analysis, $typeHead);
    if ($fields !== []) {
        return $fields;
    }
    // Fall back to nullary constructors of the receiver type.
    $items = [];
    foreach ($svc->project->checked ?? [] as $prog) {
        if (!$prog instanceof Ast\Program) {
            continue;
        }
        foreach ($prog->items as $item) {
            if ($item instanceof Ast\DataDecl && $item->name === $typeHead) {
                foreach ($item->constructors as $ctor) {
                    if ($ctor->fields === []) {
                        $items[] = [
                            'label' => $ctor->name,
                            'kind' => 4,
                            'detail' => "{$typeHead} constructor",
                            'insertText' => $ctor->name,
                            'insertTextMode' => 1,
                            'sortText' => '10-' . $ctor->name,
                            'preselect' => $items === [],
                        ];
                    }
                }
            }
        }
    }
    return $items !== [] ? $items : null;
}

/**
 * Record-field completion items for a record type head — or a constructor
 * name, since a construction writes the constructor. `$exclude` drops labels
 * the caller already sees written.
 *
 * @param array<string, true> $exclude
 * @return list<array<string, mixed>>
 */
function fieldItemsForType(AnalysisService $svc, object $analysis, string $typeHead, array $exclude = []): array
{
    return fieldCompletionItems(recordFieldEntries($svc, $analysis, $typeHead), $typeHead, $analysis, $exclude);
}

/**
 * Completion items for a record's fields, keyed by label.
 *
 * @param array<string, array{type: ?string, ctor: string}> $entries
 * @param array<string, true> $exclude labels already written
 * @return list<array<string, mixed>>
 */
function fieldCompletionItems(array $entries, string $typeName, object $analysis, array $exclude = []): array
{
    $items = [];
    $module = $analysis->program instanceof Ast\Program ? ($analysis->program->module ?? 'Main') : 'Main';
    foreach ($entries as $label => $entry) {
        if (isset($exclude[$label])) {
            continue;
        }
        $items[] = [
            'label' => $label,
            'kind' => 5,
            'detail' => "field of {$typeName}"
                . ($entry['type'] !== null && $entry['type'] !== '' ? " :: {$entry['type']}" : ''),
            'insertText' => $label,
            'insertTextMode' => 1,
            'sortText' => '00-' . $label,
            'preselect' => $items === [],
            'data' => ['uri' => $analysis->uri ?? '', 'name' => $label, 'module' => $module],
        ];
    }

    return $items;
}

/**
 * Completion inside a record's braces — the labels of `Person { … }`, or of
 * the receiver's type in `p { … }`. Detected from the text before the cursor:
 * a record half written is usually not yet a parse tree, so there is no node to
 * ask.
 *
 * @return ?list<array<string, mixed>>
 */
function recordLabelItemsAtCursor(AnalysisService $svc, object $analysis, array $comp, string $prefix): ?array
{
    $open = openRecordBraceAt($analysis->source, $comp['line'], $comp['col'] - \strlen($prefix));
    if ($open === null) {
        return null;
    }
    $head = recordHeadBefore($analysis->source, $open);
    if ($head === null) {
        return null;
    }
    $record = recordFieldsForHead($svc, $analysis, $head, $open);
    if ($record === null) {
        return null;
    }
    $written = labelsWrittenIn($analysis->source, $open, $comp['line'], $comp['col']);

    return fieldCompletionItems($record['fields'], $record['name'], $analysis, $written) ?: null;
}

/**
 * Byte offset of the `{` the cursor is inside, or null when it is not inside
 * one. Braces in strings, comments and `{-# … #-}` pragmas do not count.
 */
function openRecordBraceAt(string $source, int $line, int $col): ?int
{
    $limit = offsetOf($source, $line, $col);
    $open = [];
    $i = 0;
    while ($i < $limit) {
        $c = $source[$i];
        if ($c === '"' || $c === "'") {
            $i = skipQuoted($source, $i, $c);
            continue;
        }
        if ($c === '-' && ($source[$i + 1] ?? '') === '-') {
            $nl = strpos($source, "\n", $i);
            $i = $nl === false ? $limit : $nl + 1;
            continue;
        }
        if ($c === '{' && ($source[$i + 1] ?? '') === '-') {
            $i = skipBlockComment($source, $i);
            continue;
        }
        if ($c === '{') {
            $open[] = $i;
            $i++;
            continue;
        }
        if ($c === '}' && $open !== []) {
            array_pop($open);
        }
        $i++;
    }

    return $open === [] ? null : $open[array_key_last($open)];
}

/** Byte offset of a 1-based (line, col) compiler position. */
function offsetOf(string $source, int $line, int $col): int
{
    $offset = 0;
    $current = 1;
    while ($current < $line) {
        $nl = strpos($source, "\n", $offset);
        if ($nl === false) {
            return \strlen($source);
        }
        $offset = $nl + 1;
        $current++;
    }

    return min(\strlen($source), $offset + max(0, $col - 1));
}

/** Offset just past a string/char literal opened at $at. */
function skipQuoted(string $source, int $at, string $quote): int
{
    $i = $at + 1;
    $len = \strlen($source);
    while ($i < $len) {
        if ($source[$i] === '\\') {
            $i += 2;
            continue;
        }
        if ($source[$i] === $quote) {
            return $i + 1;
        }
        $i++;
    }

    return $len;
}

/** Offset just past a `{- … -}` comment opened at $at (nesting allowed). */
function skipBlockComment(string $source, int $at): int
{
    $depth = 0;
    $i = $at;
    $len = \strlen($source);
    while ($i < $len) {
        $two = substr($source, $i, 2);
        if ($two === '{-') {
            $depth++;
            $i += 2;
            continue;
        }
        if ($two === '-}') {
            $depth--;
            $i += 2;
            if ($depth === 0) {
                return $i;
            }
            continue;
        }
        $i++;
    }

    return $len;
}

/**
 * The name written in front of a record's `{` — a constructor (`Person {`) or
 * the receiver of an update (`p {`). Null when the brace is preceded by the end
 * of an expression (`(address p) {`), which names no record.
 */
function recordHeadBefore(string $source, int $open): ?string
{
    $before = rtrim(substr($source, 0, $open));
    if ($before === '' || !preg_match('/([A-Za-z_][A-Za-z0-9_\']*)$/', $before, $m)) {
        return null;
    }
    $prev = substr($before, 0, \strlen($before) - \strlen($m[1]));
    $prevChar = $prev === '' ? '' : $prev[\strlen($prev) - 1];
    if ($prevChar === ')' || $prevChar === ']' || $prevChar === '"') {
        return null;
    }

    return $m[1];
}

/**
 * Labels already written inside the braces at `$open`, so completion does not
 * offer a field twice.
 *
 * @return array<string, true>
 */
function labelsWrittenIn(string $source, int $open, int $line, int $col): array
{
    $upto = substr($source, $open, max(0, offsetOf($source, $line, $col) - $open));
    preg_match_all('/([A-Za-z_][A-Za-z0-9_\']*)\s*=/', $upto, $m);
    $labels = [];
    foreach ($m[1] as $label) {
        $labels[$label] = true;
    }

    return $labels;
}

function completionSortText(string $prefix, string $name, int $tier): string
{
    $pl = strtolower($prefix);
    $nl = strtolower($name);
    $rank = 4;
    if ($pl !== '' && $nl === $pl) {
        $rank = 0;
    } elseif ($pl !== '' && str_starts_with($nl, $pl)) {
        $rank = 1;
    } elseif ($pl !== '' && str_contains($nl, $pl)) {
        $rank = 2;
    } elseif ($pl !== '') {
        // Camel-hump / subsequence soft match
        $pi = 0;
        $plen = strlen($pl);
        for ($i = 0, $n = strlen($nl); $i < $n && $pi < $plen; $i++) {
            if ($nl[$i] === $pl[$pi]) {
                $pi++;
            }
        }
        if ($pi === $plen) {
            $rank = 3;
        }
    }
    return sprintf('%02d-%03d-%s', $rank, min(999, $tier), $nl);
}

/**
 * Resolve documentation / type detail for a completion item.
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */

/**
 * Resolve documentation / type detail for a completion item.
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function svcCompletionResolve(AnalysisService $svc, array $item): array
{
    $data = $item['data'] ?? [];
    $name = (string) ($data['name'] ?? $item['label'] ?? '');
    $module = (string) ($data['module'] ?? '');
    $resolved = $data['resolved'] ?? null;
    if ($resolved === null && $module !== '' && $name !== '') {
        $resolved = resolvedSymbol($module, $name);
    }
    $parts = [];
    if ($resolved !== null && \is_string($resolved)) {
        $decl = $svc->modules->declForResolved($resolved);
        if ($decl !== null) {
            if ($decl->type) {
                $item['detail'] = $decl->type . ($decl->module !== '' ? ' — ' . $decl->module : '');
                $parts[] = "```moggi\n{$decl->name} :: {$decl->type}\n```";
            }
            if ($decl->doc) {
                $parts[] = $decl->doc;
            }
        }
    }
    if ($name !== '' && $svc->docIndex !== null) {
        try {
            foreach (search($svc->docIndex, $name, 5) as $hit) {
                $e = $hit->entity;
                if ($e->name !== $name) {
                    continue;
                }
                if ($module !== '' && $e->module !== $module) {
                    continue;
                }
                if ($e->signature !== '' && ($item['detail'] ?? '') === '') {
                    $item['detail'] = $e->signature;
                }
                if ($e->doc !== '' && !in_array($e->doc, $parts, true)) {
                    $parts[] = $e->doc;
                }
                break;
            }
        } catch (\Throwable) {
        }
    }
    if ($parts !== []) {
        $item['documentation'] = ['kind' => 'markdown', 'value' => implode("\n\n", $parts)];
    }
    return $item;
}

/** @return list<array{range: array, newText: string}> */
function buildAutoImportEdits(string $source, string $uri, string $module): array
{
    if ($module === '' || $module === 'Main') {
        return [];
    }
    if (preg_match('/^import\s+(qualified\s+)?' . preg_quote($module, '/') . '\b/m', $source)) {
        return [];
    }
    $lines = splitLines($source);
    $insertLine = 0;
    foreach ($lines as $i => $line) {
        if (preg_match('/^module\b/', $line) || preg_match('/^import\b/', $line)) {
            $insertLine = $i + 1;
        }
    }
    $range = [
        'start' => ['line' => $insertLine, 'character' => 0],
        'end' => ['line' => $insertLine, 'character' => 0],
    ];
    return [['range' => $range, 'newText' => "import {$module}\n"]];
}

/**
 * Keyword / snippet catalog for completion and hover enrichment.
 *
 * @return list<array{
 *   label: string,
 *   kind: int,
 *   detail: string,
 *   documentation: string,
 *   insertText: string,
 *   insertTextFormat?: int
 * }>
 */
function keywordCompletionItems(): array
{
    // CompletionItemKind: Keyword=14, Snippet=15
    $kw = 14;
    $snip = 15;
    $fmt = 2; // InsertTextFormat.Snippet

    return [
        [
            'label' => 'BACKEND',
            'kind' => $snip,
            'detail' => 'backend implementations',
            'documentation' => "Name the module that implements a facade for each backend.\n\n```moggi\n{-# BACKEND\n  php    = Data.Char.PHP\n  jvm    = Data.Char.JVM\n  dotnet = Data.Char.DotNet\n#-}\n```",
            'insertText' => "{-# BACKEND\n  php = \${1:Data.Char.PHP}\n  jvm = \${2:Data.Char.JVM}\n  dotnet = \${3:Data.Char.DotNet}\n#-}",
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'module',
            'kind' => $kw,
            'detail' => 'module header',
            'documentation' => "Declare a module and its export list.\n\n```moggi\nmodule Data.Foo (Foo(..), bar) where\n```",
            'insertText' => 'module',
        ],
        [
            'label' => 'import',
            'kind' => $snip,
            'detail' => 'import a module',
            'documentation' => "Bring another module into scope.\n\n```moggi\nimport Data.Maybe\nimport qualified Data.List as L\n```",
            'insertText' => 'import ${1:Data.Maybe}',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'qualified',
            'kind' => $kw,
            'detail' => 'qualified import',
            'documentation' => "Require a module prefix when referring to imports.\n\n```moggi\nimport qualified Data.List as L\n```",
            'insertText' => 'qualified',
        ],
        [
            'label' => 'data',
            'kind' => $snip,
            'detail' => 'algebraic data type',
            'documentation' => "Define an algebraic data type with one or more constructors.\n\n```moggi\ndata Maybe a = Nothing | Just a\n```",
            'insertText' => "data \${1:Name} \${2:a} =\n  \${3:Ctor} \${4:a}",
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'newtype',
            'kind' => $snip,
            'detail' => 'zero-cost wrapper type',
            'documentation' => "A single-constructor wrapper with no runtime cost.\n\n```moggi\nnewtype Identity a = Identity a\n```",
            'insertText' => 'newtype ${1:Name} ${2:a} = ${1:Name} ${2:a}',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'type',
            'kind' => $snip,
            'detail' => 'type synonym',
            'documentation' => "Give a name to an existing type.\n\n```moggi\ntype String = [Char]\n```",
            'insertText' => 'type ${1:Name} = ${2:Int}',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'class',
            'kind' => $snip,
            'detail' => 'type class',
            'documentation' => "Declare a type class and its methods.\n\n```moggi\nclass Eq a where\n  (==) :: a -> a -> Bool\n```",
            'insertText' => "class \${1:Name} \${2:a} where\n  \${3:method} :: \${4:a} -> \${5:a}",
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'instance',
            'kind' => $snip,
            'detail' => 'type class instance',
            'documentation' => "Provide implementations for a class.\n\n```moggi\ninstance Eq Bool where\n  True  == True  = True\n  False == False = True\n  _     == _     = False\n```",
            'insertText' => "instance \${1:Eq} \${2:T} where\n  \${3:method} = \${4:undefined}",
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'where',
            'kind' => $kw,
            'detail' => 'local bindings / module body',
            'documentation' => "Introduce a block of local definitions or the body of a module/class/instance.",
            'insertText' => 'where',
        ],
        [
            'label' => 'let',
            'kind' => $snip,
            'detail' => 'let binding',
            'documentation' => "Bind local names.\n\n```moggi\nlet x = 1\n    y = 2\nin x + y\n```",
            'insertText' => "let \${1:x} = \${2:expr}\nin \${3:x}",
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'in',
            'kind' => $kw,
            'detail' => 'let … in',
            'documentation' => "Closes a `let` binding group and introduces the result expression.",
            'insertText' => 'in',
        ],
        [
            'label' => 'case',
            'kind' => $snip,
            'detail' => 'pattern match',
            'documentation' => "Match a value against patterns.\n\n```moggi\ncase m of\n  Nothing -> 0\n  Just x  -> x\n```",
            'insertText' => "case \${1:scrutinee} of\n  \${2:Pat} -> \${3:expr}",
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'of',
            'kind' => $kw,
            'detail' => 'case … of',
            'documentation' => "Introduces the alternatives of a `case` expression.",
            'insertText' => 'of',
        ],
        [
            'label' => 'if',
            'kind' => $snip,
            'detail' => 'conditional',
            'documentation' => "Boolean conditional.\n\n```moggi\nif p then x else y\n```",
            'insertText' => 'if ${1:cond} then ${2:thenExpr} else ${3:elseExpr}',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'then',
            'kind' => $kw,
            'detail' => 'if … then … else',
            'documentation' => "The true branch of an `if` expression.",
            'insertText' => 'then',
        ],
        [
            'label' => 'else',
            'kind' => $kw,
            'detail' => 'if … then … else',
            'documentation' => "The false branch of an `if` expression.",
            'insertText' => 'else',
        ],
        [
            'label' => 'do',
            'kind' => $snip,
            'detail' => 'do-notation',
            'documentation' => "Sequence monadic / IO actions.\n\n```moggi\nmain :: IO ()\nmain = do\n  putStrLn \"hello\"\n  pure ()\n```",
            'insertText' => "do\n  \${1:action}\n  \${2:pure ()}",
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'foreign',
            'kind' => $snip,
            'detail' => 'foreign import',
            'documentation' => "Import a host-platform primitive.\n\n```moggi\nforeign import php \"strlen\" strlen :: String -> Int\n```",
            'insertText' => 'foreign import ${1:php} "${2:name}" ${3:mogName} :: ${4:Type}',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'pub',
            'kind' => $kw,
            'detail' => 'export modifier',
            'documentation' => "Mark a top-level declaration as exported from the module.",
            'insertText' => 'pub',
        ],
        [
            'label' => 'abstract',
            'kind' => $kw,
            'detail' => 'abstract data',
            'documentation' => "Hide constructors of a data type from importers.",
            'insertText' => 'abstract',
        ],
        [
            'label' => 'deriving',
            'kind' => $snip,
            'detail' => 'derive instances',
            'documentation' => "Ask the compiler to generate class instances.\n\n```moggi\ndata Color = Red | Green deriving (Eq, Show)\n```",
            'insertText' => 'deriving (${1:Eq})',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'infix',
            'kind' => $snip,
            'detail' => 'infix declaration',
            'documentation' => "Declare fixity for an operator.\n\n```moggi\ninfix 5 ==\ninfixl 6 +\ninfixr 9 .\n```",
            'insertText' => 'infix ${1:5} ${2:op}',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'infixl',
            'kind' => $snip,
            'detail' => 'left-associative infix',
            'documentation' => "Left-associative operator fixity (e.g. `+`, `*`).",
            'insertText' => 'infixl ${1:6} ${2:op}',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'infixr',
            'kind' => $snip,
            'detail' => 'right-associative infix',
            'documentation' => "Right-associative operator fixity (e.g. `.`, `:`).",
            'insertText' => 'infixr ${1:9} ${2:op}',
            'insertTextFormat' => $fmt,
        ],
        [
            'label' => 'default',
            'kind' => $kw,
            'detail' => 'default types',
            'documentation' => "Choose default types for ambiguous numeric / overloaded literals.",
            'insertText' => 'default',
        ],
        [
            'label' => 'as',
            'kind' => $kw,
            'detail' => 'import alias',
            'documentation' => "Alias an imported module.\n\n```moggi\nimport qualified Data.List as L\n```",
            'insertText' => 'as',
        ],
        [
            'label' => 'hiding',
            'kind' => $kw,
            'detail' => 'import hiding',
            'documentation' => "Import a module while excluding named entities.",
            'insertText' => 'hiding',
        ],
    ];
}
