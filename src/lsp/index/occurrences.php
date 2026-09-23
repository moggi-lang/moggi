<?php declare(strict_types=1);

namespace Moggi\LSP\Index;

use Moggi\Syntax\Ast;

use function Moggi\LSP\Protocol\nodeToLspRange;
use function Moggi\Modules\parseResolvedSymbol;
use function Moggi\Modules\resolvedSymbol;

final class SymbolOccurrence
{
    /**
     * @param array{start: array{line:int,character:int}, end: array{line:int,character:int}} $range
     */
    public function __construct(
        public string $uri,
        public array $range,
        public string $kind, // def|use|type|import|call
        public string $name,
        public ?string $resolved = null,
        /** Enclosing function resolved id when this is a call-site use. */
        public ?string $enclosing = null,
    ) {
    }
}

final class CallEdge
{
    /**
     * @param array{start: array{line:int,character:int}, end: array{line:int,character:int}} $range
     */
    public function __construct(
        public string $fromResolved,
        public string $toResolved,
        public string $uri,
        public array $range,
        public string $fromName = '',
        public string $toName = '',
    ) {
    }
}

final class DeclInfo
{
    /**
     * @param array{start: array{line:int,character:int}, end: array{line:int,character:int}} $range
     * @param array{start: array{line:int,character:int}, end: array{line:int,character:int}} $selectionRange
     * @param list<DeclInfo> $children
     */
    public function __construct(
        public string $name,
        public int $kind,
        public string $uri,
        public array $range,
        public array $selectionRange,
        public string $module = '',
        public ?string $type = null,
        public ?string $doc = null,
        public ?string $resolved = null,
        public int $completionKind = 3,
        public array $children = [],
    ) {
    }
}

final class OccurrenceIndex
{
    /** @var array<string, list<SymbolOccurrence>> */
    public array $byResolved = [];

    /** @var array<string, array<string, list<SymbolOccurrence>>> */
    public array $byUriName = [];

    /** @var list<CallEdge> */
    public array $callEdges = [];

    public function add(SymbolOccurrence $occ): void
    {
        if ($occ->resolved !== null && $occ->resolved !== '') {
            $this->byResolved[$occ->resolved][] = $occ;
        }
        $this->byUriName[$occ->uri][$occ->name][] = $occ;
    }

    public function addCall(CallEdge $edge): void
    {
        $this->callEdges[] = $edge;
    }

    public function merge(OccurrenceIndex $other): void
    {
        foreach ($other->byResolved as $list) {
            foreach ($list as $occ) {
                $this->add($occ);
            }
        }
        foreach ($other->callEdges as $edge) {
            $this->addCall($edge);
        }
    }

    /** @return list<SymbolOccurrence> */
    public function findByResolved(string $resolved): array
    {
        return $this->byResolved[$resolved] ?? [];
    }

    /** @return list<SymbolOccurrence> */
    public function findInUri(string $uri, string $name): array
    {
        return $this->byUriName[$uri][$name] ?? [];
    }

    /** @return list<CallEdge> */
    public function incomingCalls(string $toResolved): array
    {
        $out = [];
        foreach ($this->callEdges as $edge) {
            if ($edge->toResolved === $toResolved) {
                $out[] = $edge;
            }
        }
        return $out;
    }

    /** @return list<CallEdge> */
    public function outgoingCalls(string $fromResolved): array
    {
        $out = [];
        foreach ($this->callEdges as $edge) {
            if ($edge->fromResolved === $fromResolved) {
                $out[] = $edge;
            }
        }
        return $out;
    }

    public function clearUri(string $uri): void
    {
        unset($this->byUriName[$uri]);
        foreach ($this->byResolved as $key => $list) {
            $this->byResolved[$key] = array_values(array_filter(
                $list,
                static fn (SymbolOccurrence $o): bool => $o->uri !== $uri,
            ));
            if ($this->byResolved[$key] === []) {
                unset($this->byResolved[$key]);
            }
        }
        $this->callEdges = array_values(array_filter(
            $this->callEdges,
            static fn (CallEdge $e): bool => $e->uri !== $uri,
        ));
    }
}
/**
 * @param array<string, string> $externalFns
 */
function buildOccurrenceIndex(
    string $uri,
    string $source,
    object $program,
    string $moduleName,
    array $externalFns,
): OccurrenceIndex {
    $index = new OccurrenceIndex();
    if (!$program instanceof Ast\Program) {
        return $index;
    }

    $addDef = static function (string $name, object $node, string $kind = 'def') use ($index, $uri, $source, $moduleName): void {
        if (($node->line ?? 0) <= 0) {
            return;
        }
        $index->add(new SymbolOccurrence(
            $uri,
            nodeToLspRange($source, $node),
            $kind,
            $name,
            resolvedSymbol($moduleName, $name),
        ));
    };

    // Token-derived decl locations: the parser leaves top-level decls and constructors
    // unpositioned, so every column-1 occurrence of the name is a defining line.
    $maps = declTokenMaps($source, $uri);
    $fakeDeclPos = static function (string $name, object $item, array $tokenMap) use ($source): object {
        if (($item->line ?? 0) > 0) {
            return $item;
        }
        $tok = $tokenMap[$name] ?? null;
        if ($tok === null) {
            return $item;
        }
        return (object) [
            'line' => $tok->line,
            'col' => $tok->col,
            'endCol' => $tok->col + max(1, mb_strlen($tok->lexeme)) - 1,
            'endLine' => $tok->line,
        ];
    };

    $addDefTokens = static function (string $name, string $kind) use ($index, $uri, $moduleName, $maps): void {
        // A name reached through a data/type/class decl is a type; function
        // decls (and constructors) are plain defs.
        $toks = $maps['defs'][$name] ?? [];
        if ($toks === []) {
            return;
        }
        foreach ($toks as $tok) {
            $index->add(new SymbolOccurrence(
                $uri,
                [
                    'start' => ['line' => $tok->line - 1, 'character' => $tok->col - 1],
                    'end' => ['line' => $tok->line - 1, 'character' => $tok->col - 1 + max(1, mb_strlen($tok->lexeme))],
                ],
                $kind,
                $name,
                resolvedSymbol($moduleName, $name),
            ));
        }
    };

    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $addDefTokens($item->name, 'def');
        } elseif ($item instanceof Ast\DataDecl || $item instanceof Ast\TypeSynonymDecl || $item instanceof Ast\ClassDecl) {
            $addDefTokens($item->name, 'type');
            if ($item instanceof Ast\DataDecl) {
                foreach ($item->constructors as $ctor) {
                    $cf = $fakeDeclPos($ctor->name, (object) [
                        'line' => $ctor->line,
                        'col' => $ctor->col,
                        'endCol' => $ctor->endCol ?: ($ctor->col + max(1, strlen($ctor->name)) - 1),
                        'endLine' => $ctor->endLine ?: $ctor->line,
                    ], $maps['ctors']);
                    $addDef($ctor->name, $cf, 'def');
                }
            }
        }
    }

    $walk = null;
    $enclosing = null;
    $enclosingName = null;
    $walk = static function ($node) use (
        &$walk,
        &$enclosing,
        &$enclosingName,
        $index,
        $uri,
        $source,
        $moduleName,
        $externalFns,
    ): void {
        if (!is_object($node) || $node instanceof Ast\TypeNode) {
            return;
        }

        $prevEnclosing = $enclosing;
        $prevName = $enclosingName;
        if ($node instanceof Ast\FunctionDecl && !$node->signatureOnly) {
            $enclosing = resolvedSymbol($moduleName, $node->name);
            $enclosingName = $node->name;
        }

        $resolved = null;
        $name = null;
        $kind = 'use';
        if ($node instanceof Ast\PatVar) {
            $name = $node->name;
            $kind = 'def';
            if ($node->binderId !== null) {
                $resolved = binderResolved($moduleName, $node->binderId);
            }
        } elseif ($node instanceof Ast\Variable) {
            $name = $node->name;
            if ($node->binderId !== null) {
                $resolved = binderResolved($moduleName, $node->binderId);
            } else {
                $resolved = $node->resolvedOrigin
                    ?? ($externalFns[$name] ?? resolvedSymbol($moduleName, $name));
            }
        } elseif ($node instanceof Ast\OperatorRef) {
            $name = $node->name;
            $resolved = $node->resolvedOrigin
                ?? ($externalFns[$name] ?? resolvedSymbol($moduleName, $name));
        } elseif ($node instanceof Ast\ConstructorRef) {
            $name = $node->name;
            $resolved = $node->resolvedOrigin
                ?? ($externalFns[$name] ?? resolvedSymbol($moduleName, $name));
        } elseif ($node instanceof Ast\QualifiedRef) {
            $name = $node->name;
            $resolved = $node->backendResolved ?? null;
        }
        if ($name !== null && ($node->line ?? 0) > 0) {
            $index->add(new SymbolOccurrence(
                $uri,
                nodeToLspRange($source, $node),
                $kind,
                $name,
                $resolved,
                $enclosing,
            ));
        }

        if ($node instanceof Ast\Apply && $enclosing !== null) {
            $callee = extractCallHead($node->function, $moduleName, $externalFns);
            if ($callee !== null && $callee['resolved'] !== null) {
                $index->addCall(new CallEdge(
                    $enclosing,
                    $callee['resolved'],
                    $uri,
                    nodeToLspRange($source, $node),
                    $enclosingName ?? '',
                    $callee['name'],
                ));
            }
        } elseif ($node instanceof Ast\Infix && $enclosing !== null) {
            $opResolved = $externalFns[$node->operator]
                ?? resolvedSymbol($moduleName, $node->operator);
            $index->addCall(new CallEdge(
                $enclosing,
                $opResolved,
                $uri,
                nodeToLspRange($source, $node),
                $enclosingName ?? '',
                $node->operator,
            ));
        }

        // Skip type ASTs / inferredType: they are huge, may share structure, and
        // are irrelevant for name occurrences (walking them hangs the LSP).
        if ($node instanceof Ast\TypeNode) {
            $enclosing = $prevEnclosing;
            $enclosingName = $prevName;
            return;
        }
        foreach (get_object_vars($node) as $key => $prop) {
            if ($key === 'inferredType') {
                continue;
            }
            if (is_object($prop)) {
                if ($prop instanceof Ast\TypeNode) {
                    continue;
                }
                $walk($prop);
            } elseif (is_array($prop)) {
                foreach ($prop as $el) {
                    if (is_object($el) && !$el instanceof Ast\TypeNode) {
                        $walk($el);
                    }
                }
            }
        }

        $enclosing = $prevEnclosing;
        $enclosingName = $prevName;
    };
    $walk($program);

    return $index;
}

/**
 * @param array<string, string> $externalFns
 * @return array{name: string, resolved: ?string}|null
 */
function extractCallHead(object $fn, string $moduleName, array $externalFns): ?array
{
    while ($fn instanceof Ast\Apply) {
        $fn = $fn->function;
    }
    if ($fn instanceof Ast\Variable) {
        $name = $fn->name;
        $resolved = $fn->binderId !== null
            ? binderResolved($moduleName, $fn->binderId)
            : ($fn->resolvedOrigin ?? ($externalFns[$name] ?? resolvedSymbol($moduleName, $name)));
        return ['name' => $name, 'resolved' => $resolved];
    }
    if ($fn instanceof Ast\OperatorRef) {
        $name = $fn->name;
        return [
            'name' => $name,
            'resolved' => $fn->resolvedOrigin ?? ($externalFns[$name] ?? resolvedSymbol($moduleName, $name)),
        ];
    }
    if ($fn instanceof Ast\ConstructorRef) {
        $name = $fn->name;
        return [
            'name' => $name,
            'resolved' => $fn->resolvedOrigin ?? ($externalFns[$name] ?? resolvedSymbol($moduleName, $name)),
        ];
    }
    if ($fn instanceof Ast\QualifiedRef) {
        return ['name' => $fn->name, 'resolved' => $fn->backendResolved];
    }
    return null;
}
function resolveSymbolParts(?string $resolved): ?array
{
    if ($resolved === null || $resolved === '') {
        return null;
    }
    return parseResolvedSymbol($resolved);
}

/** Stable binder key: module-scoped so OccurrenceIndex merges never collide. */
function binderResolved(string $module, int $binderId): string
{
    return 'binder:' . $module . ':' . $binderId;
}

function isBinderResolved(?string $resolved): bool
{
    return $resolved !== null && str_starts_with($resolved, 'binder:');
}
