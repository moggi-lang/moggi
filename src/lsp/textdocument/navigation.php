<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;
use Moggi\LSP\Index\DeclInfo;
use Moggi\Syntax\Ast;

use function Moggi\LSP\checkCancelledPoint;
use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Analysis\findNodeAt;
use function Moggi\LSP\Analysis\findNodeChainAt;
use function Moggi\LSP\Index\binderResolved;
use function Moggi\LSP\Index\isBinderResolved;
use function Moggi\LSP\Protocol\codepointColToUtf16;
use function Moggi\LSP\Protocol\locToRange;
use function Moggi\LSP\Protocol\compilerPosToLsp;
use function Moggi\LSP\Protocol\lspPosToCompiler;
use function Moggi\LSP\Protocol\nodeToLspRange;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\LSP\Protocol\utf16Length;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Modules\resolvedSymbol;

function svcDefinition(AnalysisService $svc, string $uri, array $pos): mixed
{
    $targets = resolveNavTargets($svc, $uri, $pos);
    if ($targets === []) {
        return null;
    }
    $result = navigationResult($svc, $targets, 'definition');

    // Single target keeps the plain Location/LocationLink shape the tests
    // (and most clients) rely on; arrays are reserved for multiple results.
    return count($targets) === 1 ? $result[0] : $result;
}

/**
 * Shape the Definition/Declaration/TypeDefinition response. 3.18 allows
 * Location | Location[] | LocationLink[] | null. Clients that advertise
 * `definition.linkSupport` (also honored for declaration/typeDefinition per
 * 3.18) get LocationLink[] with originSelectionRange; the rest get Location[].
 *
 * @param list<array{uri: string, range: array, resolved?: ?string, name?: string, targetSelection?: ?array}> $targets
 */
function navigationResult(AnalysisService $svc, array $targets, string $method): array
{
    $caps = $svc->clientCapabilities;
    $linkSupport = (bool) ($caps['textDocument'][$method]['linkSupport'] ?? false);
    $links = [];
    $locs = [];
    foreach ($targets as $t) {
        if ($linkSupport) {
            $link = [
                'targetUri' => $t['uri'],
                'targetRange' => $t['range'],
                'targetSelectionRange' => $t['targetSelection'] ?? $t['range'],
            ];
            if (isset($t['originRange'])) {
                $link['originSelectionRange'] = $t['originRange'];
            }
            $links[] = $link;
        } else {
            $locs[] = ['uri' => $t['uri'], 'range' => $t['range']];
        }
    }
    return $linkSupport ? $links : $locs;
}

/**
 * Word range at an LSP position (for originSelectionRange), or null.
 */
function originRangeAt(AnalysisService $svc, string $uri, array $pos): ?array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $comp = lspPosToCompiler($analysis->source, $pos);
    $word = wordAt($analysis->source, $comp['line'], $comp['col']);
    if ($word === null || $word === '') {
        return null;
    }
    $lines = splitLines($analysis->source);
    $text = $lines[$comp['line'] - 1] ?? '';
    $idx = max(0, $comp['col'] - 1);
    $left = $idx;
    while ($left > 0 && preg_match('/[A-Za-z0-9_\']/', $text[$left - 1] ?? '')) {
        $left--;
    }
    $right = $idx;
    while ($right < strlen($text) && preg_match('/[A-Za-z0-9_\']/', $text[$right] ?? '')) {
        $right++;
    }
    return [
        'start' => compilerPosToLsp($analysis->source, $comp['line'], $left + 1),
        'end' => compilerPosToLsp($analysis->source, $comp['line'], $right + 1),
    ];
}

/**
 * All navigation targets for the symbol at the position (spec: definition may
 * return several locations). Primary target first; import statements that
 * name the resolved module follow.
 *
 * @return list<array{uri: string, range: array, resolved?: ?string, name?: string, targetSelection?: ?array}>
 */
function resolveNavTargets(AnalysisService $svc, string $uri, array $pos): array
{
    $target = resolveNavTarget($svc, $uri, $pos);
    if ($target === null) {
        return [];
    }
    $target['originRange'] = originRangeAt($svc, $uri, $pos);
    $out = [$target];
    // Secondary target: the import line that brings the symbol's module in
    // (distinct from the declaration; helps "where did this come from?").
    $resolved = $target['resolved'] ?? null;
    if (\is_string($resolved) && str_contains($resolved, '::')) {
        $mod = explode('::', $resolved, 2)[0];
        $analysis = $svc->ensureAnalyzed($uri);
        if ($analysis !== null) {
            foreach ($svc->occurrences->findInUri($uri, $mod) as $occ) {
                if ($occ->kind === 'import') {
                    $out[] = ['uri' => $occ->uri, 'range' => $occ->range];
                    break;
                }
            }
        }
    }
    return $out;
}

function svcTypeDefinition(AnalysisService $svc, string $uri, array $pos): mixed
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    checkCancelledPoint();
    $comp = lspPosToCompiler($analysis->source, $pos);
    $node = findNodeAt($analysis->program, $comp['line'], $comp['col']);
    $typeName = null;
    if ($node !== null && $node->inferredType !== null) {
        $t = $node->inferredType;
        if ($t instanceof Ast\TypeCon) {
            $typeName = $t->name;
        } elseif ($t instanceof Ast\TypeApp && $t->con instanceof Ast\TypeCon) {
            $typeName = $t->con->name;
        }
    }
    // A constructor reference takes you to the type that declares it.
    if ($node instanceof Ast\ConstructorRef) {
        $typeName = $node->name;
    }
    $word = wordAt($analysis->source, $comp['line'], $comp['col']);
    if ($typeName === null && $word !== null && isset($analysis->declarations[$word]['type'])) {
        // Value name: derive the type head from its declared/inferred surface type.
        $surface = (string) $analysis->declarations[$word]['type'];
        if (!in_array($surface, ['function', 'data', 'newtype', 'type', 'class'], true)
            && preg_match("/[A-Z][A-Za-z0-9_']*/", $surface, $m)) {
            $typeName = $m[0];
        }
    }
    $typeName ??= $word;
    if ($typeName === null) {
        return null;
    }
    $module = $analysis->program instanceof Ast\Program ? ($analysis->program->module ?? 'Main') : 'Main';
    // Prefer any module that defines this type name.
    foreach ($svc->modules->defsByResolved as $res => $decl) {
        $parts = parseResolvedSymbol($res);
        if ($parts !== null && $parts['name'] === $typeName && in_array($decl->type, ['data', 'newtype', 'type', 'class'], true)) {
            return navigationResult($svc, [[
                'uri' => $decl->uri,
                'range' => $decl->range,
                'targetSelection' => $decl->selectionRange,
                'originRange' => originRangeAt($svc, $uri, $pos),
            ]], 'typeDefinition')[0];
        }
    }
    // Fallback: find the type declaration by name in the module index
    // (covers imported types like Maybe and constructors like Red).
    $decl = findTypeDeclForName($svc, $module, $typeName);
    if ($decl !== null) {
        return navigationResult($svc, [[
            'uri' => $decl->uri,
            'range' => $decl->range,
            'targetSelection' => $decl->selectionRange,
            'originRange' => originRangeAt($svc, $uri, $pos),
        ]], 'typeDefinition')[0];
    }
    if (isset($analysis->declarations[$typeName])) {
        $d = $analysis->declarations[$typeName];
        if (in_array($d['type'] ?? '', ['data', 'newtype', 'type', 'class'], true)) {
            return navigationResult($svc, [[
                'uri' => $uri,
                'range' => locToRange($d['line'], $d['col'], $d['endCol'], $analysis->source),
                'originRange' => originRangeAt($svc, $uri, $pos),
            ]], 'typeDefinition')[0];
        }
    }
    return null;
}

function svcImplementation(AnalysisService $svc, string $uri, array $pos): mixed
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $comp = lspPosToCompiler($analysis->source, $pos);
    $word = wordAt($analysis->source, $comp['line'], $comp['col']);
    if ($word === null) {
        return null;
    }

    // Resolve the word at the position to the class it refers to, if any.
    $classes = [];
    $onMethodSite = false; // cursor sits exactly on an indexed method sig/def
    // 1. The cursor may sit exactly on an indexed method signature/definition;
    //    its owning class is the one that matters.
    foreach ($svc->modules->classMethodDefs[$word] ?? [] as $def) {
        $r = $def['range'];
        $onStart = $r['start']['line'] === $pos['line'] && $r['start']['character'] <= ($pos['character'] ?? 0);
        $onEnd = $r['end']['line'] === $pos['line'] && $r['end']['character'] >= ($pos['character'] ?? 0);
        if ($onStart && $onEnd) {
            $classes[] = $def['class'];
            $onMethodSite = true;
        }
    }
    // 2. The word may name a class (decl or indexed instances).
    if (!$onMethodSite
        && (isset($svc->modules->classSupers[$word]) || isset($svc->modules->classInstances[$word]))) {
        $classes[] = $word;
    }
    // 2b. Type-directed: method *uses* are tagged with the class the dictionary was selected
    // for, which is exact even when several classes define the same name.
    if (!$onMethodSite) {
        $node = findNodeAt($analysis->program, $comp['line'], $comp['col']);
        if ($node instanceof Ast\EvidenceMethod) {
            $classes = [$node->class];
            $onMethodSite = true;
        } elseif ($node instanceof Ast\Variable) {
            foreach ($analysis->program->items as $item) {
                if (!($item instanceof Ast\FunctionDecl) || !astTreeContains($item, $node)) {
                    continue;
                }
                $em = findEvidenceMethodForNode($item, $node);
                if ($em !== null) {
                    $classes = [$em->class];
                    $onMethodSite = true;
                }
                break;
            }
        }
    }
    // 3. Fall back to the navigation target's resolved symbol, which may carry
    //    the class scope a method use belongs to.
    if (!$onMethodSite) {
        $target = resolveNavTarget($svc, $uri, $pos);
        $resolved = is_array($target) ? ($target['resolved'] ?? null) : null;
        if (is_string($resolved) && str_contains($resolved, '::')) {
            // `Module::Class` scope (or `Module::Class::method` owner) — keep
            // the segment that names a known class.
            $segments = explode('::', $resolved);
            foreach ($segments as $seg) {
                if ($seg !== ''
                    && (isset($svc->modules->classSupers[$seg]) || isset($svc->modules->classInstances[$seg]))) {
                    $classes[] = $seg;
                }
            }
        }
    }

    $locs = [];
    $seen = [];
    $addLoc = static function (string $locUri, array $range) use (&$locs, &$seen): void {
        $key = $locUri . '|' . json_encode($range);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $locs[] = ['uri' => $locUri, 'range' => $range];
        }
    };
    if ($classes !== [] && !$onMethodSite) {
        // Class name: every instance block of that class implements it.
        foreach ($classes as $cls) {
            foreach ($svc->modules->classInstances[$cls] ?? [] as $inst) {
                $addLoc($inst['uri'], $inst['range']);
            }
        }
    }
    // Method name (or use): concrete definitions inside instance blocks, restricted to the
    // owning class when one resolved — never instance heads or class signatures.
    foreach ($svc->modules->classMethodDefs[$word] ?? [] as $def) {
        if ($def['sig'] ?? false) {
            continue;
        }
        if ($classes !== [] && !in_array($def['class'], $classes, true)) {
            continue;
        }
        $addLoc($def['uri'], $def['range']);
    }

    return $locs === [] ? null : $locs;
}

/**
 * The EvidenceMethod whose subtree contains $needle (identity match) — the
 * typechecker's record of which class dictionary a method use dispatched to.
 * Type nodes are not descended into (shared, non-positional).
 */
function findEvidenceMethodForNode(object $root, Ast\AstNode $needle): ?Ast\EvidenceMethod
{
    if ($root instanceof Ast\EvidenceMethod && astTreeContains($root, $needle)) {
        return $root;
    }
    if ($root instanceof Ast\TypeNode) {
        return null;
    }
    foreach (get_object_vars($root) as $prop) {
        if (is_object($prop)) {
            $hit = findEvidenceMethodForNode($prop, $needle);
            if ($hit !== null) {
                return $hit;
            }
        } elseif (is_array($prop)) {
            foreach ($prop as $el) {
                if (is_object($el)) {
                    $hit = findEvidenceMethodForNode($el, $needle);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }
        }
    }
    return null;
}

/** All references (declaration included when $includeDecl) as `Location`s. */
function svcReferences(AnalysisService $svc, string $uri, array $pos, bool $includeDecl): array
{
    $target = resolveNavTarget($svc, $uri, $pos);
    if ($target === null || ($target['resolved'] ?? null) === null) {
        // Fallback: same-uri name
        $analysis = $svc->ensureAnalyzed($uri);
        if ($analysis === null) {
            return [];
        }
        $comp = lspPosToCompiler($analysis->source, $pos);
        $word = wordAt($analysis->source, $comp['line'], $comp['col']);
        if ($word === null) {
            return [];
        }
        $out = [];
        foreach ($svc->occurrences->findInUri($uri, $word) as $occ) {
            if (!$includeDecl && $occ->kind === 'def') {
                continue;
            }
            $out[] = ['uri' => $occ->uri, 'range' => $occ->range];
        }
        return $out;
    }
    $out = [];
    foreach ($svc->occurrences->findByResolved($target['resolved']) as $occ) {
        if (!$includeDecl && $occ->kind === 'def') {
            continue;
        }
        $out[] = ['uri' => $occ->uri, 'range' => $occ->range];
    }
    return $out;
}

function svcDocumentHighlight(AnalysisService $svc, string $uri, array $pos): array
{
    $refs = svcReferences($svc, $uri, $pos, true);
    $out = [];
    foreach ($refs as $ref) {
        if (($ref['uri'] ?? '') !== $uri) {
            continue;
        }
        $out[] = ['range' => $ref['range'], 'kind' => 1];
    }
    return $out;
}

function svcDeclaration(AnalysisService $svc, string $uri, array $pos): mixed
{
    // Same resolution path as go-to-definition: declaration vs definition only
    // differ for local binders, which resolveNavTarget already handles by
    // jumping to the PatVar with the same binderId.
    $targets = resolveNavTargets($svc, $uri, $pos);
    if ($targets === []) {
        return null;
    }
    $result = navigationResult($svc, $targets, 'declaration');

    return count($targets) === 1 ? $result[0] : $result;
}

/**
 * Find a DeclInfo by name in the given module's index entry, including
 * constructor/method children (which are absent from $analysis->declarations).
 */
function findModuleDeclByName(AnalysisService $svc, ?string $module, string $name): ?DeclInfo
{
    $search = static function (array $entry) use ($name): ?DeclInfo {
        foreach ($entry['decls'] as $decl) {
            if ($decl instanceof DeclInfo) {
                if ($decl->name === $name) {
                    return $decl;
                }
                foreach ($decl->children as $c) {
                    if ($c->name === $name) {
                        return $c;
                    }
                }
            }
        }
        return null;
    };
    if ($module !== null && isset($svc->modules->modules[$module])) {
        $hit = $search($svc->modules->modules[$module]);
        if ($hit !== null) {
            return $hit;
        }
    }
    // Last resort: any module in the index.
    foreach ($svc->modules->modules as $entry) {
        $hit = $search($entry);
        if ($hit !== null) {
            return $hit;
        }
    }
    return null;
}

/**
 * Find the type declaration (data/newtype/type/class) for a type name, or for
 * a constructor of that type: `Red` resolves to `data Color = …`.
 */

/**
 * Find the type declaration (data/newtype/type/class) for a type name, or for
 * a constructor of that type: `Red` resolves to `data Color = …`.
 */
function findTypeDeclForName(AnalysisService $svc, ?string $module, string $name): ?DeclInfo
{
    $search = static function (array $entry) use ($name): ?DeclInfo {
        foreach ($entry['decls'] as $decl) {
            if (!($decl instanceof DeclInfo)) {
                continue;
            }
            if (in_array($decl->type, ['data', 'newtype', 'type', 'class'], true) && $decl->name === $name) {
                return $decl;
            }
            if ($decl->type === 'data' || $decl->type === 'newtype') {
                foreach ($decl->children as $c) {
                    if ($c->name === $name) {
                        return $decl;
                    }
                }
            }
        }
        return null;
    };
    if ($module !== null && isset($svc->modules->modules[$module])) {
        $hit = $search($svc->modules->modules[$module]);
        if ($hit !== null) {
            return $hit;
        }
    }
    foreach ($svc->modules->modules as $entry) {
        $hit = $search($entry);
        if ($hit !== null) {
            return $hit;
        }
    }
    return null;
}

/**
 * @return array{uri: string, range: array, resolved: ?string, name: string}|null
 */

/**
 * @return array{uri: string, range: array, resolved: ?string, name: string}|null
 */
function resolveNavTarget(AnalysisService $svc, string $uri, array $pos): ?array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return null;
    }
    $module = $analysis->program instanceof Ast\Program
        ? ($analysis->program->module ?? 'Main')
        : 'Main';
    $comp = lspPosToCompiler($analysis->source, $pos);
    $node = findNodeAt($analysis->program, $comp['line'], $comp['col']);
    $name = null;
    $resolved = null;
    if ($node instanceof Ast\Variable) {
        $name = $node->name;
        if ($node->binderId !== null) {
            $resolved = binderResolved($module, $node->binderId);
            // Local: definition is the PatVar with same binderId
            foreach ($svc->occurrences->findByResolved($resolved) as $occ) {
                if ($occ->kind === 'def') {
                    return [
                        'uri' => $occ->uri,
                        'range' => $occ->range,
                        'resolved' => $resolved,
                        'name' => $name,
                    ];
                }
            }
        }
        $resolved = $node->resolvedOrigin;
    } elseif ($node instanceof Ast\PatVar) {
        $name = $node->name;
        if ($node->binderId !== null) {
            $resolved = binderResolved($module, $node->binderId);
            return [
                'uri' => $uri,
                'range' => nodeToLspRange($analysis->source, $node),
                'resolved' => $resolved,
                'name' => $name,
            ];
        }
    } elseif ($node instanceof Ast\OperatorRef || $node instanceof Ast\ConstructorRef) {
        $name = $node->name;
        $resolved = $node->resolvedOrigin;
    } elseif ($node instanceof Ast\QualifiedRef) {
        $name = $node->name;
        $resolved = $node->backendResolved;
    } elseif ($node instanceof Ast\FunctionDecl) {
        $name = $node->name;
    }
    // Fallback: if node is a literal/expression inside a function, walk up the chain
    // to find the parent FunctionDecl and use its name.
    if ($name === null && $node !== null) {
        $chain = findNodeChainAt($analysis->program, $comp['line'], $comp['col']);
        foreach (array_reverse($chain) as $cand) {
            if ($cand instanceof Ast\FunctionDecl) {
                $name = $cand->name;
                break;
            }
        }
    }
    $name ??= wordAt($analysis->source, $comp['line'], $comp['col']);
    if ($name === null) {
        return null;
    }
    if ($resolved === null && $analysis->program instanceof Ast\Program) {
        $resolved = $analysis->program->externalFns[$name] ?? null;
        if ($resolved === null && isset($analysis->declarations[$name])) {
            $resolved = resolvedSymbol($analysis->program->module ?? 'Main', $name);
        }
    }
    if ($resolved !== null && !isBinderResolved($resolved)) {
        $decl = $svc->modules->declForResolved($resolved);
        if ($decl !== null) {
            return [
                'uri' => $decl->uri,
                'range' => $decl->selectionRange,
                'resolved' => $resolved,
                'name' => $name,
            ];
        }
        foreach ($svc->occurrences->findByResolved($resolved) as $occ) {
            if ($occ->kind === 'def' || $occ->kind === 'type') {
                return [
                    'uri' => $occ->uri,
                    'range' => $occ->range,
                    'resolved' => $resolved,
                    'name' => $name,
                ];
            }
        }
    }
    if (isset($analysis->declarations[$name])) {
        $d = $analysis->declarations[$name];
        return [
            'uri' => $uri,
            'range' => locToRange($d['line'], $d['col'], $d['endCol'], $analysis->source),
            'resolved' => $resolved,
            'name' => $name,
        ];
    }
    // Fallback: constructors / class methods live as children of their parent
    // declaration in the module index, not in $analysis->declarations.
    $mod = $analysis->program instanceof Ast\Program ? ($analysis->program->module ?? 'Main') : 'Main';
    $decl = findModuleDeclByName($svc, $mod, $name);
    if ($decl !== null) {
        return [
            'uri' => $decl->uri,
            'range' => $decl->selectionRange,
            'resolved' => $resolved,
            'name' => $name,
        ];
    }
    return null;
}

function svcLinkedEditing(AnalysisService $svc, string $uri, array $pos): ?array
{
    $target = resolveNavTarget($svc, $uri, $pos);
    $resolved = $target['resolved'] ?? null;
    if ($resolved === null) {
        return null;
    }
    $ranges = [];
    $seen = [];
    foreach ($svc->occurrences->findByResolved($resolved) as $occ) {
        if ($occ->uri !== $uri) {
            continue;
        }
        $k = $occ->range['start']['line'] . ':' . $occ->range['start']['character']
            . '-' . $occ->range['end']['line'] . ':' . $occ->range['end']['character'];
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $ranges[] = $occ->range;
    }
    if ($ranges === [] && isset($target['range'])) {
        $ranges[] = $target['range'];
    }
    return $ranges === [] ? null : ['ranges' => $ranges];
}

function svcDocumentLinks(AnalysisService $svc, string $uri): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $links = [];
    $source = $analysis->source;
    $lines = splitLines($source);
    foreach ($lines as $i => $line) {
        if (preg_match_all('/https?:\/\/[^\s)]+/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $url = $match[0];
                // preg offsets are bytes; LSP character offsets are UTF-16 units.
                $start = codepointColToUtf16($line, $match[1] + 1);
                $end = $start + utf16Length($url);
                $links[] = [
                    'range' => [
                        'start' => ['line' => $i, 'character' => $start],
                        'end' => ['line' => $i, 'character' => $end],
                    ],
                    'target' => $url,
                ];
            }
        }
    }
    return $links;
}

function svcDocumentLinkResolve(AnalysisService $svc, array $params): array
{
    return $params;
}
