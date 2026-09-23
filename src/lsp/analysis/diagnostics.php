<?php declare(strict_types=1);

namespace Moggi\LSP\Analysis;

use Moggi\Modules\PreparedProject;
use Moggi\Syntax\Ast;
use Moggi\Semantics\Types\TypeError;
use Moggi\Syntax\Lexer\LexError;
use Moggi\Syntax\Parser\ParseError;

use function Moggi\LSP\Protocol\compilerToLsp;
use function Moggi\LSP\Protocol\pathToUri;

/**
 * Stable resultId for a diagnostics snapshot (LSP 3.18 pull model): a short
 * SHA-256 of the canonical JSON of the items. Equal diagnostics in equal
 * documents produce equal ids, enabling `{kind: 'unchanged'}` responses.
 */
function resultIdFor(string $uri, array $items): string
{
    $canonical = json_encode([$uri, $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return substr(hash('sha256', $canonical), 0, 16);
}

function errorToDiagnostic(\Throwable $e, string $uri, string $source): array
{
    $line = 1;
    $col = 1;
    $endCol = $col;
    $message = $e->getMessage();
    $code = null;
    $data = null;

    if ($e instanceof LexError || $e instanceof ParseError || $e instanceof TypeError) {
        $line = $e->line();
        $col = $e->col();
        $endCol = $e->endCol();
        if ($endCol < $col) {
            $endCol = $col;
        }
        $message = $e->getMessage();
        if ($e instanceof TypeError) {
            $code = $e->diagnosticCode ?? 'type';
            if ($e->missingPatterns !== null && $e->missingPatterns !== []) {
                $data = ['missingPatterns' => $e->missingPatterns];
            }
            if ($e->holeType !== null && $e->holeType !== '') {
                $data = array_merge(\is_array($data) ? $data : [], ['holeType' => $e->holeType]);
                $code = 'hole';
            } elseif (preg_match('/Found hole with type:\s*`([^`]+)`/', $message, $hm)) {
                $data = array_merge(\is_array($data) ? $data : [], ['holeType' => $hm[1]]);
                $code = 'hole';
            }
            if (str_contains($message, 'non-exhaustive')) {
                $code = 'non-exhaustive';
            }
        } elseif ($e instanceof ParseError) {
            $code = 'parse';
        } else {
            $code = 'lex';
        }
    }

    $diag = [
        'range' => [
            'start' => compilerToLsp($line, $col),
            'end' => compilerToLsp($line, $endCol + 1),
        ],
        // Holes are actionable info, not hard errors (HLS-style).
        'severity' => ($code === 'hole') ? 2 : 1,
        'message' => $message,
        'source' => 'moggi',
        'code' => $code,
    ];
    $href = diagnosticCodeHref($code);
    if ($href !== null) {
        $diag['codeDescription'] = ['href' => $href];
    }
    if ($data !== null) {
        $diag['data'] = $data;
    }
    return $diag;
}

/**
 * Documentation URL for a stable diagnostic code (LSP 3.18
 * `codeDescription.href`); null when the code has no docs page.
 */
function diagnosticCodeHref(?string $code): ?string
{
    $anchors = [
        'non-exhaustive' => 'https://github.com/moggi-lang/moggi/blob/master/docs/language.md',
        'hole' => 'https://github.com/moggi-lang/moggi/blob/master/docs/language.md',
        'type' => 'https://github.com/moggi-lang/moggi/blob/master/docs/language.md',
        'unify' => 'https://github.com/moggi-lang/moggi/blob/master/docs/language.md',
        'parse' => 'https://github.com/moggi-lang/moggi/blob/master/docs/language.md',
        'lex' => 'https://github.com/moggi-lang/moggi/blob/master/docs/language.md',
        'unused-import' => 'https://github.com/moggi-lang/moggi/blob/master/docs/language.md',
    ];

    return $code !== null ? ($anchors[$code] ?? null) : null;
}

/**
 * `textDocument/unused-import`-style hints: one Unnecessary-tagged (spec tag 1)
 * warning per import whose module is referenced nowhere in the file — neither
 * as a qualifier (`M.x`), nor via any resolved symbol from that module, nor via
 * any name matching a declaration of that module (checked against the project's
 * checked programs, so hints stay correct in files that typecheck cleanly; files
 * with type errors get conservative suppression).
 *
 * Both value-level and type-level names count: an import is used when the file
 * names a value, a constructor, a type constructor, or a class of that module —
 * including names that only appear in a signature, a constraint, or an instance
 * head (`instance Eq Foo where …` uses `Data.Eq` just as much as `a == b` does).
 *
 * The usage walk reads both trees: the parsed one still spells out the syntax the
 * checker desugars away (instance heads, class contexts) and the checked one
 * carries the names resolution found (a class method's own signature, the
 * dictionary calls a body was rewritten into). Either alone misses imports the
 * other sees — `instance Eq Foo` is gone from the checked tree, and `Data.Foldable`
 * never mentions `Monoid` in the parse tree. The import list itself always comes
 * from the parsed program, which is what the editor is looking at.
 *
 * @return list<array<string, mixed>>
 */
function unusedImportDiagnostics(
    Ast\Program $program,
    string $uri,
    ?PreparedProject $prepared = null,
    ?Ast\Program $usage = null,
): array {
    if ($program->imports === []) {
        return [];
    }
    $usedQualifiers = [];
    $usedResolvedModules = [];
    collectImportUsage($program, $usedQualifiers, $usedResolvedModules);

    // Names referenced anywhere in the file (values, constructors, types,
    // classes, instance heads), for declaration-name matching below.
    $bareNames = [];
    collectBareNames($program, $bareNames);
    if ($usage !== null && $usage !== $program) {
        collectImportUsage($usage, $usedQualifiers, $usedResolvedModules);
        collectBareNames($usage, $bareNames);
    }

    // Decl names per module from the checked project.
    $declNamesByModule = [];
    if ($prepared !== null) {
        foreach ($prepared->checked as $mName => $prog) {
            if (!$prog instanceof Ast\Program) {
                continue;
            }
            $names = [];
            foreach ($prog->items as $item) {
                if ($item instanceof Ast\FunctionDecl || $item instanceof Ast\DataDecl
                    || $item instanceof Ast\TypeSynonymDecl || $item instanceof Ast\ClassDecl) {
                    $names[$item->name] = true;
                }
                if ($item instanceof Ast\DataDecl) {
                    foreach ($item->constructors as $ctor) {
                        $names[$ctor->name] = true;
                    }
                }
            }
            // An export list names what a module provides, including re-exports: the facade
            // `Data.Monoid` provides `Monoid`, which lives in its internal module.
            foreach ($prog->nameRefs() as $ref) {
                $names[$ref['name']] = true;
            }
            $declNamesByModule[$mName] = $names;
        }
        // Modules with no source AST of their own (the synthesized Prim/IO modules) declare
        // nothing to scan, so take their names from the prepared unit's export tables.
        foreach ($prepared->units as $mName => $unit) {
            if (!\is_array($unit) || ($declNamesByModule[$mName] ?? []) !== []) {
                continue;
            }
            $exports = $unit['exports'] ?? null;
            if (!\is_array($exports)) {
                continue;
            }
            $names = [];
            foreach (['env', 'data', 'typeSynonyms'] as $table) {
                foreach (\array_keys($exports[$table] ?? []) as $exported) {
                    $names[$exported] = true;
                }
            }
            $declNamesByModule[$mName] = $names;
        }
    }

    $source = '';
    $diags = [];
    foreach ($program->imports as $imp) {
        if ($imp->implicit) {
            continue;
        }
        $modulePath = implode('.', $imp->path);
        $alias = $imp->asName ?? ($imp->path[count($imp->path) - 1] ?? $modulePath);
        $used = isset($usedQualifiers[$alias])
            || isset($usedQualifiers[$modulePath])
            || isset($usedResolvedModules[$modulePath]);
        if (!$used && isset($declNamesByModule[$modulePath])) {
            // A bare identifier that names a declaration of the imported module
            // counts as a use (we cannot always tell which import supplied it).
            foreach ($bareNames as $n => $_) {
                if (isset($declNamesByModule[$modulePath][$n])) {
                    $used = true;
                    break;
                }
            }
        }
        if ($used || $imp->line <= 0) {
            continue;
        }
        $diags[] = [
            'range' => [
                'start' => compilerToLsp($imp->line, $imp->col ?: 1),
                'end' => compilerToLsp($imp->line, ($imp->endCol ?: ($imp->col ?: 1)) + 1),
            ],
            'severity' => 2,
            'message' => "Import of `{$modulePath}` is not used",
            'source' => 'moggi',
            'code' => 'unused-import',
            'tags' => [1], // DiagnosticTag.Unnecessary
            'codeDescription' => ['href' => diagnosticCodeHref('unused-import')],
            'data' => ['module' => $modulePath, 'alias' => $alias],
        ];
    }

    return $diags;
}

/**
 * Walk a program collecting (a) every qualifier used in `M.x` references,
 * (b) every module path a resolved symbol (`Module::name`) belongs to, and
 * (c) every module re-exported from the header.
 *
 * Every value the walk visits implements `Ast\AstWalkable`, so the walk asks
 * each one what it refers to and what to visit next instead of asking it what
 * class it is.
 *
 * @param array<string, true> $qualifiers out
 * @param array<string, true> $resolvedModules out
 */
function collectImportUsage(Ast\AstWalkable $root, array &$qualifiers, array &$resolvedModules): void
{
    foreach ($root->nameRefs() as $ref) {
        if ($ref['qualifier'] !== null) {
            $qualifiers[$ref['qualifier']] = true;
        }
    }
    foreach ($root->reExportedModules() as $module) {
        $resolvedModules[$module] = true;
    }
    $module = $root->resolvedModule();
    if ($module !== null) {
        $resolvedModules[$module] = true;
    }
    walkChildren($root, static function (Ast\AstWalkable $child) use (&$qualifiers, &$resolvedModules): void {
        collectImportUsage($child, $qualifiers, $resolvedModules);
    });
}

/**
 * Unqualified names referenced in a program (locals and imported names alike),
 * asked of the values themselves. Used to match against imported modules'
 * declaration names when a resolved-origin signal is unavailable.
 *
 * A qualified reference (`M.x`) already marks its import used through the
 * qualifier, so only bare names are collected here.
 *
 * @param array<string, true> $names out
 */
function collectBareNames(Ast\AstWalkable $root, array &$names): void
{
    foreach ($root->nameRefs() as $ref) {
        if ($ref['qualifier'] === null) {
            $names[$ref['name']] = true;
        }
    }
    walkChildren($root, static function (Ast\AstWalkable $child) use (&$names): void {
        collectBareNames($child, $names);
    });
}

/**
 * Visit every walkable value a value holds, one list level deep (the AST nests
 * nodes in plain arrays such as `list<Alt>` or `list<AstNode>`).
 *
 * @param callable(Ast\AstWalkable): void $visit
 */
function walkChildren(Ast\AstWalkable $root, callable $visit): void
{
    foreach ($root->childValues() as $child) {
        foreach (is_array($child) ? $child : [$child] as $value) {
            if ($value instanceof Ast\AstWalkable) {
                $visit($value);
            }
        }
    }
}

/**
 * Locate top-level declarations in source by scanning tokens.
 *
 * @return array<string, array{line: int, col: int, endCol: int, type: string, name: string, kind: int, doc?: string, completionKind: int}>
 */

function enrichDiagnostic(array $diag, \Throwable $e): array
{
    if ($e instanceof TypeError) {
        if ($e->diagnosticCode !== null) {
            $diag['code'] = $e->diagnosticCode;
        } else {
            $diag['code'] = $diag['code'] ?? 'type';
        }
        if ($e->holeType !== null && $e->holeType !== '') {
            $diag['code'] = 'hole';
            $diag['severity'] = 2;
            $diag['data'] = array_merge(
                \is_array($diag['data'] ?? null) ? $diag['data'] : [],
                ['holeType' => $e->holeType],
            );
            $diag['relatedInformation'] = $diag['relatedInformation'] ?? [[
                'location' => [
                    'uri' => $e->filename !== '' ? pathToUri($e->filename) : '',
                    'range' => $diag['range'],
                ],
                'message' => "expected type: {$e->holeType}",
            ]];
        } elseif (preg_match('/Found hole with type:\s*`([^`]+)`/', $e->getMessage(), $hm)) {
            $diag['code'] = 'hole';
            $diag['severity'] = 2;
            $diag['data'] = array_merge(
                \is_array($diag['data'] ?? null) ? $diag['data'] : [],
                ['holeType' => $hm[1]],
            );
        }
        if ($e->missingPatterns !== null && $e->missingPatterns !== []) {
            $diag['data'] = array_merge(
                \is_array($diag['data'] ?? null) ? $diag['data'] : [],
                ['missingPatterns' => $e->missingPatterns],
            );
            $related = [];
            foreach ($e->missingPatterns as $pat) {
                if ($e->filename === '') {
                    break;
                }
                $related[] = [
                    'location' => [
                        'uri' => pathToUri($e->filename),
                        'range' => $diag['range'],
                    ],
                    'message' => 'missing pattern: ' . $pat,
                ];
            }
            if ($related !== []) {
                $diag['relatedInformation'] = $related;
            }
        }
        if (str_contains($e->getMessage(), 'non-exhaustive')) {
            $diag['code'] = 'non-exhaustive';
        }
        if ($e->relatedLocations !== null && $e->relatedLocations !== []) {
            $related = $diag['relatedInformation'] ?? [];
            foreach ($e->relatedLocations as $loc) {
                $file = (string) ($loc['filename'] ?? $e->filename);
                if ($file === '') {
                    continue;
                }
                $related[] = [
                    'location' => [
                        'uri' => pathToUri($file),
                        'range' => [
                            'start' => compilerToLsp((int) $loc['line'], (int) $loc['col']),
                            'end' => compilerToLsp(
                                (int) $loc['line'],
                                (int) ($loc['endCol'] ?? $loc['col']) + 1,
                            ),
                        ],
                    ],
                    'message' => (string) ($loc['message'] ?? 'related'),
                ];
            }
            $diag['relatedInformation'] = $related;
        }
        if (($diag['code'] ?? '') !== 'hole'
            && (str_contains($e->getMessage(), 'could not unify') || str_contains($e->getMessage(), 'unify'))
        ) {
            $diag['code'] = $diag['code'] ?? 'unify';
            if (($diag['relatedInformation'] ?? []) === [] && $e->filename !== '') {
                $diag['relatedInformation'] = [[
                    'location' => [
                        'uri' => pathToUri($e->filename),
                        'range' => $diag['range'],
                    ],
                    'message' => $e->getMessage(),
                ]];
            }
        }
    }
    return $diag;
}

/** @var AnalysisService|null */
