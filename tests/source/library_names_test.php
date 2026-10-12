#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Syntax\Ast;

use function Moggi\Backend\Jvm\Naming\isJavaKeyword;
use function Moggi\Backend\Php\Naming\phpKeywords;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

/**
 * The compiler's stages may not name a library declaration.
 *
 * `lib/` owns the language: names, types, classes, methods, instances. `src/` owns the machine: the
 * stages, the IR, the hosts it emits. A stage that *spells out* a name `lib/` already declares is
 * answering a question the library answers — the operator tables that named `==`/`eq`, the primop
 * lists, the class names the deriving backends switch on — and it is how the library becomes optional:
 * rename `Data.Map.member` in `lib/` and a compiler copy of the word keeps compiling.
 *
 * This test is the guard on that rule, and it is a ratchet rather than a clean bill: hundreds of such
 * names are still in `src/`, so the sites that exist today are listed in `baseline()` with the hack
 * each belongs to and the step that removes it. What it refuses is *change*: a name that is not
 * listed fails, and a listed name that is gone fails too, so the list can only shrink as the steps
 * land.
 *
 * Three contexts count, because those are the shapes a name map has:
 *
 *   `'Bool' => …` / `match ($n) { 'Bool' => … }`   a name used as a key
 *   `$x === 'Bool'`, `'Bool' !== $x`               a name compared
 *   `['<>', 'id']`                                 two or more names in one list
 *
 * A bare string is not a site: `error("unknown constructor True")` names nothing.
 *
 * Out of scope by construction: `src/CLI`, `src/lsp`, `src/docs`, `src/registry`, `src/debug` and the
 * loose root files are interfaces to the host world (commands, protocol, archives), not stages, and
 * their word lists are the host's — `pack`/`show`/`size` are CLI verbs. Two more exemptions, both
 * stated rather than hidden:
 *
 *   * one-character names are the language's own punctuation: the lexer recognises the tokens it
 *     lexes, the parser the operators it parses, every backend writes its own path separators. This
 *     is the rule's one blind spot, and it has a name: `:` is the list cons, so the fallback that
 *     resolves `:` to `listCons#` (H4) is invisible here and P3 is what has to remove it.
 *   * a name that is a PHP or Java keyword is host vocabulary. The lists are the compiler's own
 *     (`phpKeywords`, `isJavaKeyword`), which the plan's boundary already names as the machine's
 *     business — so adding a keyword there widens this exemption by that one word.
 */

/**
 * Every name `lib/` declares: types, constructors, synonyms, classes and their methods, associated
 * types, values, foreign types and imports.
 *
 * Declared, not exported: a compiler stage that names a library's private helper is naming the
 * library just the same, and the front end can see the declaration whatever the export list says.
 *
 * @return array<string, true>
 */
function libraryDeclarations(string $root): array
{
    $names = [];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/lib', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'mog') {
            $files[] = $file->getPathname();
        }
    }
    \sort($files);

    foreach ($files as $file) {
        $source = \file_get_contents($file);
        if ($source === false) {
            throw new \RuntimeException("cannot read {$file}");
        }
        // Syntax only: a name is declared by the parser's answer, and no stage of the test needs the
        // module to type check (a library module is not independently checkable in every case).
        $program = parse(lex($source, $file), $source, $file);
        foreach ($program->items as $item) {
            if ($item instanceof Ast\DataDecl) {
                $names[$item->name] = true;
                foreach ($item->constructors as $constructor) {
                    $names[$constructor->name] = true;
                }
            } elseif ($item instanceof Ast\TypeSynonymDecl) {
                $names[$item->name] = true;
            } elseif ($item instanceof Ast\ClassDecl) {
                $names[$item->name] = true;
                foreach ($item->methods as $method) {
                    $names[$method->name] = true;
                }
                foreach ($item->associatedTypes as $associated) {
                    $names[$associated->name] = true;
                }
            } elseif ($item instanceof Ast\FunctionDecl
                || $item instanceof Ast\ForeignImportDecl
                || $item instanceof Ast\ForeignTypeDecl
            ) {
                $names[$item->name] = true;
            }
        }
    }

    return $names;
}

/**
 * The compiler's stages: everything under `src/` that is not an interface to the host world.
 *
 * @return list<string> path prefixes, relative to the project root
 */
function stageRoots(): array
{
    return [
        'src/syntax/',
        'src/semantics/',
        'src/patterns/',
        'src/IR/',
        'src/optimize/',
        'src/backend/',
        'src/modules/',
        'src/pipeline/',
        'src/repl/',
        'src/cache/',
        'src/errors/',
        'src/foreign/',
    ];
}

/** Whether a name is the host's vocabulary rather than the library's. */
function isHostVocabulary(string $name): bool
{
    if (\strlen($name) === 1) {
        return true;
    }

    return isset(phpKeywords()[\strtolower($name)]) || isJavaKeyword($name);
}

/**
 * The named sites in one source: a library name used as a key, compared, or in a name list.
 *
 * @param array<string, true> $declarations
 * @return array<string, list<int>> name => the lines it appears on
 */
function namedSites(string $source, array $declarations): array
{
    $tokens = [];
    foreach (\token_get_all($source) as $token) {
        if (\is_array($token)) {
            if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $tokens[] = [
                'text' => $token[1],
                'line' => $token[2],
                'literal' => $token[0] === T_CONSTANT_ENCAPSED_STRING,
            ];
            continue;
        }

        $tokens[] = [
            'text' => $token,
            'line' => $tokens === [] ? 1 : $tokens[\count($tokens) - 1]['line'],
            'literal' => false,
        ];
    }

    $sites = [];
    $record = static function (string $name, int $line) use (&$sites): void {
        $sites[$name][] = $line;
    };

    $list = [];
    foreach ($tokens as $index => $token) {
        if ($token['literal']) {
            $value = literalValue($token['text']);
            $list[] = $value;

            if (isset($declarations[$value]) && !isHostVocabulary($value)) {
                $previous = $tokens[$index - 1]['text'] ?? '';
                $next = $tokens[$index + 1]['text'] ?? '';
                if ($next === '=>'
                    || \in_array($previous, ['===', '!==', '==', '!='], true)
                    || \in_array($next, ['===', '!==', '==', '!='], true)
                ) {
                    $record($value, $token['line']);
                }
            }

            continue;
        }

        // A list is a run of two or more literals that are *all* library names: one name between
        // quotes is a message, several are the table this rule is about. The list is judged by its
        // elements alone — `['void', 'when', …]` is the library's IO wrappers even though two of those
        // words are also host keywords — because a whole run of declarations is the evidence, where a
        // lone key or comparison needs the name to be one the host cannot mean.
        if ($token['text'] === ']') {
            if (\count($list) >= 2
                && !\in_array(false, \array_map(
                    static fn (string $name): bool => isset($declarations[$name]),
                    $list,
                ), true)
            ) {
                foreach ($list as $name) {
                    $record($name, $token['line']);
                }
            }
            $list = [];
            continue;
        }

        if ($token['text'] !== ',' && $token['text'] !== '[') {
            $list = [];
        }
    }

    return $sites;
}

/** The value of a PHP single- or double-quoted literal token. */
function literalValue(string $token): string
{
    $inner = \substr($token, 1, -1);

    return $token[0] === "'"
        ? \str_replace(['\\\\', "\\'"], ['\\', "'"], $inner)
        : \stripcslashes($inner);
}

/**
 * The sites that exist today, and the hack each belongs to.
 *
 * Each entry names the hack it belongs to and the step that removes it (`H*` is the hack, `P*` the
 * step). The reason is one line because the list will be read on the way down, not up: an entry that
 * is gone from the source fails the test until it is deleted here too.
 *
 * @return array<string, list<string>>
 */
function baseline(): array
{
    return [
        // the guard desugaring recognises `otherwise` — H5, P2
        'src/IR/lower_expr.php' => [
            'otherwise',
        ],
        // the IO wrappers lowering knows by name — H2, P4.4
        'src/IR/lower_io.php' => [
            'for_', 'sequenceA_', 'traverse_', 'unless', 'void', 'when',
        ],
        // comparison and Bool primops → host IL (`bool` is the IL type name) — H2/H7, P4.4/P5
        'src/backend/dotnet/codegen.php' => [
            '/=', '<', '<=', '==', '>', '>=', 'False', 'True', 'bool',
        ],
        // marshalling: library type → CLR type (`member` is a foreign-path kind) — H7, P5
        'src/backend/dotnet/foreign.php' => [
            'Bool', 'ByteString', 'Char', 'Double', 'Either', 'Handle', 'Int', 'Int16', 'Int32',
            'Int64', 'Int8', 'Integer', 'Maybe', 'member',
        ],
        // IL opcodes, not library functions — host vocabulary
        'src/backend/dotnet/il_size.php' => [
            'div', 'not', 'rem',
        ],
        // `isBoolBackedData`: the bool representation is the type named `Bool` — H7, P5
        'src/backend/ir_meta.php' => [
            'Bool',
        ],
        // comparison and Bool primops → host JVM ops — H2/H7, P4.4/P5
        'src/backend/jvm/codegen.php' => [
            '/=', '<', '<=', '==', '>', '>=', 'False', 'True',
        ],
        // marshalling: library type → JVM type — H7, P5
        'src/backend/jvm/foreign.php' => [
            'Bool', 'ByteString', 'Char', 'Double', 'Either', 'Handle', 'Int', 'Int16', 'Int32',
            'Int64', 'Int8', 'Integer', 'Maybe', 'String', 'member',
        ],
        // the BigInteger helper table (`subtract` is an arithmetic helper name) — host vocabulary
        'src/backend/jvm/runtime_abi.php' => [
            'subtract',
        ],
        // Bool constructors → PHP booleans (`moduleName`/`stdout` are output keys) — H7, P5
        'src/backend/php/codegen.php' => [
            'False', 'True', 'moduleName', 'stdout',
        ],
        // comparison and concat operators → PHP operators — H2, P4.4
        'src/backend/php/emit_expr.php' => [
            '/=', '<>',
        ],
        // marshalling (`member` is a foreign-path kind, `either` an inspect key) — H7, P5
        'src/backend/php/foreign.php' => [
            'either', 'member',
        ],
        // `abs` is a PHP builtin, not the library's `abs` — host vocabulary
        'src/backend/php/naming.php' => [
            'abs',
        ],
        // the runtime's own exception class names — host vocabulary
        'src/backend/php/runtime.php' => [
            'ErrorCall', 'HostException',
        ],
        // `all` is a cache-scope key — host vocabulary
        'src/cache/cache.php' => [
            'all',
        ],
        // the `Solo` re-export rule; `all`/`some` are export-list modes — H6/H4, P4.2
        'src/modules/exports.php' => [
            'Solo', 'all', 'some',
        ],
        // Bool constructor names while importing — H7, P5
        'src/modules/imports.php' => [
            'False', 'True',
        ],
        // `index` is the module-index cache key — host vocabulary
        'src/modules/library.php' => [
            'index',
        ],
        // `checked`/`moduleName` are internal keys (also Generic selector names) — host vocabulary
        'src/modules/modules.php' => [
            'checked', 'moduleName',
        ],
        // the Bool hand-over exclusion; `head` is a key — H4, P4.2
        'src/modules/typecheck_env.php' => [
            'Bool', 'head',
        ],
        // the foldable names `<>`/`id` — H2, P4.4
        'src/optimize/fold_intrinsic.php' => [
            '<>', 'id',
        ],
        // `id x` collapses because the optimizer knows the library's `id` — H5, P2
        'src/optimize/partial.php' => [
            'id',
        ],
        // Bool constructors among the optimizer's constants — H7, P5
        'src/optimize/support.php' => [
            'False', 'True',
        ],
        // `moduleName` is an artifact key — host vocabulary
        'src/pipeline/pipeline.php' => [
            'moduleName',
        ],
        // `:show` is a REPL verb — host vocabulary
        'src/repl/commands.php' => [
            'show',
        ],
        // `moduleName` is an artifact key — host vocabulary
        'src/repl/compile.php' => [
            'moduleName',
        ],
        // output and state keys (`stdout`/`stderr`/`checked`/`moduleName`); `filter` is a stream key
        // — host vocabulary
        'src/repl/eval_runner.php' => [
            'checked', 'filter', 'moduleName', 'stderr', 'stdout',
        ],
        // `checked` is a state key — host vocabulary
        'src/repl/session.php' => [
            'checked',
        ],
        // the stock-deriving class table (`head` is the instance head) — H6, P6
        'src/semantics/deriving/framework.php' => [
            'Bounded', 'Enum', 'Eq', 'Foldable', 'Functor', 'Generic', 'Ord', 'Read', 'Rep', 'Show',
            'Traversable', 'head',
        ],
        // the class lists and the Ord methods — H6, P6
        'src/semantics/deriving/newtype.php' => [
            '<', '<=', '>', '>=', 'Foldable', 'Functor', 'Generic', 'Generic1', 'Ord',
            'Traversable', 'max', 'min',
        ],
        // `from`/`to` are TypeArrow keys (also Generic's selectors) — host vocabulary
        'src/semantics/evidence_names.php' => [
            'from', 'to',
        ],
        // `all` is a coverage mode — host vocabulary
        'src/semantics/exhaustiveness.php' => [
            'all',
        ],
        // host marshalling tables — H7, P5
        'src/semantics/foreign.php' => [
            'Bool', 'ByteString', 'Char', 'Double', 'Either', 'Handle', 'IO', 'IOMode', 'Int',
            'Int16', 'Int32', 'Int64', 'Int8', 'Integer', 'Maybe', 'Ordering', 'PHPValue',
            'Resource', 'String', 'Word16', 'Word32', 'Word64', 'Word8',
        ],
        // the machine's own fixities (`:` is the one public operator Prim owns) —
        // `prec` is a fixity-record key, host vocabulary like `src/syntax/fixity.php`
        'src/semantics/intrinsic_registry.php' => [
            'prec',
        ],
        // the IO boundary's own names — H2, P4.4
        'src/semantics/io_boundary.php' => [
            '>>=', 'IO', 'SomeException',
        ],
        // `head` is the instance-head key — host vocabulary
        'src/semantics/registry.php' => [
            'head',
        ],
        // divergence analysis recognises the library's `error`/`undefined` — H5, P2
        'src/semantics/types.php' => [
            'error', 'undefined',
        ],
        // `head` is the instance-head key — host vocabulary
        'src/semantics/types/constraints.php' => [
            'head',
        ],
        // the `IO` and `Solo` bootstraps — H4, blocked on P4.2
        'src/semantics/types/declarations.php' => [
            'IO', 'Solo',
        ],
        // the primitive representation table, `otherwise`, and `group`/`index`/`span` keys
        // — H1/H5, P4.2/P2
        'src/semantics/types/infer.php' => [
            'IO', 'Int', 'Int16', 'Int32', 'Int64', 'Int8', 'Word', 'Word16',
            'Word32', 'Word64', 'Word8', 'group', 'index', 'otherwise', 'span',
        ],
        // `head` is the instance-head key — host vocabulary
        'src/semantics/types/instances.php' => [
            'head',
        ],
        // `fromInteger` at a constrained literal needs `Integer` — H5, P2
        'src/semantics/types/literals.php' => [
            'Integer',
        ],
        // the primitive type → representation table — H1, P4.2
        'src/semantics/types/type_core.php' => [
            'ByteString', 'Char', 'Double', 'Int', 'Int16', 'Int32', 'Int64', 'Int8', 'String',
            'Word', 'Word16', 'Word32', 'Word64', 'Word8',
        ],
        // `prec` is a fixity-record key (also a Generic selector) — host vocabulary
        'src/syntax/fixity.php' => [
            'prec',
        ],
        // `prec` is a fixity-record key — host vocabulary
        'src/syntax/parse_expr.php' => [
            'prec',
        ],
        // `prec` is a fixity-record key — host vocabulary
        'src/syntax/parse_pattern.php' => [
            'prec',
        ],
        // `prec`/`span` are declaration keys — host vocabulary
        'src/syntax/parser.php' => [
            'prec', 'span',
        ],
        // `member` is a foreign-path kind — host vocabulary
        'src/foreign/path.php' => [
            'member',
        ],
    ];
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$declarations = libraryDeclarations($root);
$assert($declarations !== [], 'no library declarations were found; the scan would pass vacuously');
foreach (['Bool', 'Int', 'map', 'filter', 'id', '<>'] as $name) {
    $assert(isset($declarations[$name]), "`{$name}` should be a library declaration");
}

// The detector itself: a name used as a key, compared, in a list, and none of the look-alikes (a bare
// literal, a list that is not all names, a one-character name, a host keyword).
$probe = <<<'PHP'
<?php
$a = $name === 'Bool';
$b = match ($op) { 'Int' => 1, 'default' => 2 };
$c = ['map', 'filter'];
$d = 'Int';
$e = ['map', 'NoSuchLibraryName'];
$f = [':', 'Int'];
$g = $word === 'class';
$h = $method === '==';
$i = $op === ':';
PHP;
$probed = namedSites($probe, $declarations);
$expectedProbe = ['==', 'Bool', 'Int', 'filter', 'map'];
\ksort($probed);
$assert(
    \array_keys($probed) === $expectedProbe,
    'the detector is wrong: expected [' . \implode(', ', $expectedProbe) . '], found ['
        . \implode(', ', \array_keys($probed)) . ']',
);

$found = [];
foreach (stageRoots() as $stageRoot) {
    $directory = $root . '/' . $stageRoot;
    if (! \is_dir($directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );
    $files = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    \sort($files);

    foreach ($files as $file) {
        $source = \file_get_contents($file);
        if ($source === false) {
            throw new \RuntimeException("cannot read {$file}");
        }
        $sites = namedSites($source, $declarations);
        if ($sites === []) {
            continue;
        }
        $relative = \substr($file, \strlen($root) + 1);
        \ksort($sites);
        $found[$relative] = \array_keys($sites);
    }
}
\ksort($found);

$baseline = baseline();
\ksort($baseline);

/** @return list<string> the names in $a that $b does not have */
$missing = static fn (array $a, array $b): array => \array_values(\array_diff($a, $b));

$problems = [];
foreach ($found as $file => $names) {
    $listed = $baseline[$file] ?? [];
    foreach ($missing($names, $listed) as $name) {
        $problems[] = "names the library declaration `{$name}` and is not in the baseline: {$file}";
    }
    foreach ($missing($listed, $names) as $name) {
        $problems[] = "no longer names `{$name}`: remove it from the baseline in {$file}";
    }
}
foreach (\array_diff_key($baseline, $found) as $file => $names) {
    $problems[] = 'no longer names anything; remove the file from the baseline: ' . $file;
}

if ($problems !== []) {
    \fwrite(STDERR, "compiler names the library:\n\n");
    foreach ($problems as $problem) {
        \fwrite(STDERR, '  ' . $problem . "\n");
    }
    \fwrite(STDERR, "\nA new site is a derivation to delete, not an entry to add: the name belongs in\n");
    \fwrite(STDERR, "`lib/` and the compiler should read the declaration.\n");
    \fwrite(STDERR, "An obsolete entry is a step that landed: delete it, so the baseline can only shrink.\n");
    exit(1);
}

$sites = 0;
foreach ($found as $names) {
    $sites += \count($names);
}

echo 'compiler names the library: ' . $sites . ' baseline site(s) in ' . \count($found) . ' file(s), none new' . "\n";
