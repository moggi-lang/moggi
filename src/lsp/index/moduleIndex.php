<?php declare(strict_types=1);

namespace Moggi\LSP\Index;

use Moggi\Syntax\Ast;
use Moggi\Syntax\Lexer\TokenKind;
use Moggi\Syntax\Parser\Token;

use function Moggi\Docs\schemeToString;
use function Moggi\Docs\surfaceTypeSignature;
use function Moggi\LSP\Protocol\compilerPosToLsp;
use function Moggi\LSP\Protocol\nodeToLspRange;
use function Moggi\Modules\resolvedSymbol;
use function Moggi\Syntax\Lexer\lex;

final class ModuleIndex
{
    /** @var array<string, array{path: string, uri: string, module: string, decls: list<DeclInfo>, externalFns: array<string, string>}> */
    public array $modules = [];

    /** @var array<string, string> uri → module name */
    public array $uriToModule = [];

    /** @var array<string, string> module → uri */
    public array $moduleToUri = [];

    /** @var array<string, DeclInfo> resolved → primary def */
    public array $defsByResolved = [];

    /** Class name → list of superclass names (unqualified). */
    /** @var array<string, list<string>> */
    public array $classSupers = [];

    /** Class name → list of instance DeclInfo-like entries. */
    /** @var array<string, list<array{uri: string, range: array, name: string, resolved: ?string}>> */
    public array $classInstances = [];

    /** Method name → method signature/definition sites inside class and instance blocks. */
    /** @var array<string, list<array{uri: string, range: array, class: string, sig: bool}>> */
    public array $classMethodDefs = [];

    public function put(
        string $module,
        string $path,
        string $uri,
        array $decls,
        array $externalFns,
    ): void {
        $this->modules[$module] = [
            'path' => $path,
            'uri' => $uri,
            'module' => $module,
            'decls' => $decls,
            'externalFns' => $externalFns,
        ];
        $this->uriToModule[$uri] = $module;
        $this->moduleToUri[$module] = $uri;
        foreach ($decls as $decl) {
            if ($decl->resolved !== null) {
                $this->defsByResolved[$decl->resolved] = $decl;
            }
        }
    }

    public function declForResolved(string $resolved): ?DeclInfo
    {
        return $this->defsByResolved[$resolved] ?? null;
    }
}
/**
 * @return list<DeclInfo>
 */
function buildDeclsFromProgram(string $uri, string $source, Ast\Program $program, string $moduleName): array
{
    $decls = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl) {
            $range = nodeToLspRange($source, $item);
            $sel = [
                'start' => compilerPosToLsp($source, $item->line ?: 1, $item->col ?: 1),
                'end' => compilerPosToLsp(
                    $source,
                    $item->line ?: 1,
                    ($item->col ?: 1) + max(1, strlen($item->name)),
                ),
            ];
            $type = $item->type !== null ? surfaceTypeSignature($item->type) : null;
            if ($type === null && isset($program->exportedInferredSchemes[$item->name])) {
                $type = schemeToString($program->exportedInferredSchemes[$item->name]);
            }
            $decls[] = new DeclInfo(
                $item->name,
                12,
                $uri,
                $range,
                $sel,
                $moduleName,
                $type,
                $item->doc,
                resolvedSymbol($moduleName, $item->name),
                3,
            );
        } elseif ($item instanceof Ast\DataDecl) {
            $children = [];
            foreach ($item->constructors as $ctor) {
                $cr = nodeToLspRange($source, $ctor);
                $children[] = new DeclInfo(
                    $ctor->name,
                    9,
                    $uri,
                    $cr,
                    $cr,
                    $moduleName,
                    null,
                    $ctor->doc,
                    resolvedSymbol($moduleName, $ctor->name),
                    4,
                );
            }
            $range = nodeToLspRange($source, $item);
            $decls[] = new DeclInfo(
                $item->name,
                23,
                $uri,
                $range,
                $range,
                $moduleName,
                $item->isNewtype ? 'newtype' : 'data',
                $item->doc,
                resolvedSymbol($moduleName, $item->name),
                22,
                $children,
            );
        } elseif ($item instanceof Ast\TypeSynonymDecl) {
            $range = nodeToLspRange($source, $item);
            $decls[] = new DeclInfo(
                $item->name,
                5,
                $uri,
                $range,
                $range,
                $moduleName,
                'type',
                $item->doc,
                resolvedSymbol($moduleName, $item->name),
                25,
            );
        } elseif ($item instanceof Ast\ClassDecl) {
            $children = [];
            foreach ($item->methods as $method) {
                $children[] = new DeclInfo(
                    $method->name,
                    6,
                    $uri,
                    $range = ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                    $range,
                    $moduleName,
                    surfaceTypeSignature($method->type),
                    $method->doc,
                    resolvedSymbol($moduleName, $method->name),
                    2,
                );
            }
            $range = nodeToLspRange($source, $item);
            $decls[] = new DeclInfo(
                $item->name,
                11,
                $uri,
                $range,
                $range,
                $moduleName,
                'class',
                $item->doc,
                resolvedSymbol($moduleName, $item->name),
                7,
                $children,
            );
        }
    }
    fixDeclRangesByTokenScan($uri, $source, $decls);
    return $decls;
}
/**
 * Index class supers + instances for type hierarchy.
 * Prefer calling on the *parsed* program: instances are desugared after check.
 */
function indexTypeRelations(ModuleIndex $modules, string $uri, string $source, Ast\Program $program): void
{
    // Drop prior instance entries sourced from this uri (re-analyze).
    foreach ($modules->classInstances as $cls => $list) {
        $modules->classInstances[$cls] = array_values(array_filter(
            $list,
            static fn (array $e): bool => ($e['uri'] ?? '') !== $uri,
        ));
        if ($modules->classInstances[$cls] === []) {
            unset($modules->classInstances[$cls]);
        }
    }
    foreach ($modules->classMethodDefs as $method => $list) {
        $modules->classMethodDefs[$method] = array_values(array_filter(
            $list,
            static fn (array $e): bool => ($e['uri'] ?? '') !== $uri,
        ));
        if ($modules->classMethodDefs[$method] === []) {
            unset($modules->classMethodDefs[$method]);
        }
    }
    foreach (scanClassMethodDefs($uri, $source) as $method => $defs) {
        foreach ($defs as $def) {
            $modules->classMethodDefs[$method][] = $def;
        }
    }

    foreach ($program->items as $item) {
        if ($item instanceof Ast\ClassDecl) {
            $supers = [];
            foreach ($item->superclasses as $sc) {
                $n = typeHeadName($sc);
                if ($n !== null) {
                    $supers[] = $n;
                }
            }
            $modules->classSupers[$item->name] = $supers;
        } elseif ($item instanceof Ast\InstanceDecl) {
            $headStr = typeHeadName($item->head) ?? '?';
            $name = $item->class . ' ' . $headStr;
            $modules->classInstances[$item->class][] = [
                'uri' => $uri,
                'range' => ($item->line ?? 0) > 0
                    ? nodeToLspRange($source, $item)
                    : ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                'name' => $name,
                'resolved' => null,
            ];
        }
    }
}

/**
 * Scan the token stream for class/instance blocks and record the source
 * positions of class-method signatures and instance-method definitions.
 *
 * The parser leaves method nodes unpositioned (line 0), and instance methods
 * are renamed (`__ev_Class_Head_method`) when desugared into the checked AST,
 * so this token-level scan is the only reliable source of method def sites.
 *
 * A block starts at `class`/`instance` (column 1) and ends at the next token
 * in column 1. Inside a block, a VarId/ConId that is the first token on its
 * line (column > 1) is a method signature (class, `sig: true`) or method
 * definition (instance, `sig: false`). The block owner is the first name
 * token of the header, reset across `=>` (instance constraints) and `.`
 * (qualified names).
 *
 * @return array<string, list<array{uri: string, range: array, class: string, sig: bool}>>
 */
function scanClassMethodDefs(string $uri, string $source): array
{
    try {
        $tokens = lex($source, $uri);
    } catch (\Throwable) {
        return [];
    }

    $defs = [];
    $count = count($tokens);
    $block = null; // 'class' | 'instance' | null
    $owner = null; // class name owning the current block
    $isNameTok = static fn (Token $t): bool => $t->kind === TokenKind::VarId || $t->kind === TokenKind::ConId;

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if ($tok->kind === TokenKind::KwClass || $tok->kind === TokenKind::KwInstance) {
            $block = $tok->kind === TokenKind::KwClass ? 'class' : 'instance';
            $owner = null;
            // Header: `class C a where`, `instance (C a, D b) => E x where`,
            // `instance P.C x where`.
            for ($j = $i + 1; $j < $count; $j++) {
                $h = $tokens[$j];
                if ($h->col === 1) {
                    $block = null;
                    $owner = null;
                    $i = $j - 1;
                    continue 2;
                }
                if ($h->kind === TokenKind::KwWhere) {
                    $i = $j;
                    break;
                }
                if ($isNameTok($h)) {
                    $owner ??= $h->lexeme;
                    continue;
                }
                if ($h->kind === TokenKind::Op && ($h->lexeme === '=>' || $h->lexeme === '.')) {
                    $owner = null;
                }
            }
            continue;
        }
        if ($block !== null && $owner !== null && $tok->col > 1 && $isNameTok($tok)) {
            $prev = $tokens[$i - 1] ?? null;
            if ($prev === null || $prev->line < $tok->line) {
                $defs[$tok->lexeme][] = [
                    'uri' => $uri,
                    'range' => tokenNameRange($tok),
                    'class' => $owner,
                    'sig' => $block === 'class',
                ];
            }
        }
        if ($tok->col === 1) {
            $block = null;
            $owner = null;
        }
    }
    return $defs;
}

function typeHeadName(object $t): ?string
{
    if ($t instanceof Ast\TypeCon) {
        return $t->name;
    }
    if ($t instanceof Ast\TypeApp) {
        return typeHeadName($t->con);
    }
    return null;
}
