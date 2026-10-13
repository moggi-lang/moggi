#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Syntax\Ast;

use function Moggi\Backend\DotNet\Il\ilIsInstructionMnemonic;
use function Moggi\Backend\Jvm\Naming\isJavaKeyword;
use function Moggi\Backend\Php\Naming\isPhpBuiltin;
use function Moggi\Backend\Php\Naming\phpKeywords;
use function Moggi\Paths\canonicalSeparators;
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
 * names are still in `src/`, so the sites that exist today are listed in `baseline()`, each with the
 * reason it is still there. What it refuses is *change*: a name that is not listed fails, and a
 * listed name that is gone fails too, so the list can only shrink as the reasons are dealt with.
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
 *     resolves `:` to `listCons#` is invisible here.
 *   * a name that is a PHP or Java keyword, a name PHP gives its own builtins, or an instruction of
 *     the host instruction set, is host vocabulary. The lists are the compiler's own (`phpKeywords`,
 *     `isPhpBuiltin`, `isJavaKeyword` and the DotNet backend's IL size model) — the machine's
 *     business, not the library's — so adding a keyword, a builtin or an opcode there widens this
 *     exemption by that one word.
 *
 * Both are real widenings, not tidying: `div`, `rem` and `not` are CIL opcodes *and* library
 * declarations, and `abs` is a PHP builtin *and* a library function, so the host's side has to be
 * spelled out somewhere, and a table that names the host's own machine is the boundary the plan
 * calls compiler-side rather than a library name map.
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
        'src/ir/',
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

/**
 * Whether a name is the host's vocabulary rather than the library's.
 *
 * The host's vocabulary is the host's: its keywords, the names it gives its own builtins, and its
 * instruction set. Every answer comes from the compiler's own statements of them (`phpKeywords`,
 * `isPhpBuiltin`, `isJavaKeyword`, and the DotNet backend's IL size model), so the exemption widens
 * by exactly the word someone adds there — never by a name added here.
 */
function isHostVocabulary(string $name): bool
{
    if (\strlen($name) === 1) {
        return true;
    }

    return isset(phpKeywords()[\strtolower($name)])
        || isPhpBuiltin($name)
        || isJavaKeyword($name)
        || ilIsInstructionMnemonic($name);
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
 * The sites that exist today.
 *
 * Each entry carries one line saying why the compiler still names a library declaration there: most
 * are coincidence — an internal key that happens to share a spelling with a library name — and the
 * rest are the compiler tables that keep the library optional. One line, because the list will be
 * read on the way down, not up: an entry that is gone from the source fails the test until it is
 * deleted here too.
 *
 * @return array<string, list<string>>
 */
function baseline(): array
{
    return [
        // comparison primops → host IL (`bool` is the IL type name; the Bool constructors are
        // asked of the kernel key)
        'src/backend/dotnet/codegen.php' => [
            '/=', '<', '<=', '==', '>', '>=', 'bool',
        ],
        // marshalling: the boolean, the transparent containers and the opaque handle — the
        // kernel's own types are keyed by kernel id (`clrKernelTypeName`)
        'src/backend/dotnet/foreign.php' => [
            'Bool', 'Either', 'Handle', 'Maybe',
        ],
        // comparison primops → host JVM ops (the Bool constructors are asked of the kernel key)
        'src/backend/jvm/codegen.php' => [
            '/=', '<', '<=', '==', '>', '>=',
        ],
        // marshalling: the boolean, the transparent containers and the opaque handle — the
        // kernel's own types are keyed by kernel id (`jvmKernelDescriptor`)
        'src/backend/jvm/foreign.php' => [
            'Bool', 'Either', 'Handle', 'Maybe',
        ],
        // the BigInteger helper table (`subtract` is an arithmetic helper name) — host vocabulary
        'src/backend/jvm/runtime_abi.php' => [
            'subtract',
        ],
        // comparison and concat operators → PHP operators
        'src/backend/php/emit_expr.php' => [
            '/=', '<>',
        ],
        // the runtime's own exception class names — host vocabulary
        'src/backend/php/runtime.php' => [
            'ErrorCall', 'HostException',
        ],
        // `all` is a cache-scope key — host vocabulary
        'src/cache/cache.php' => [
            'all',
        ],
        // the `Solo` re-export rule
        'src/modules/exports.php' => [
            'Solo',
        ],
        // the Bool hand-over exclusion
        'src/modules/typecheck_env.php' => [
            'Bool',
        ],
        // `:show` is a REPL verb — host vocabulary
        'src/repl/commands.php' => [
            'show',
        ],
        // `stdout`/`stderr` are output keys — host vocabulary
        'src/repl/eval_runner.php' => [
            'stderr', 'stdout',
        ],
        // the stock-deriving class table
        'src/semantics/deriving/framework.php' => [
            'Bounded', 'Enum', 'Eq', 'Foldable', 'Functor', 'Generic', 'Ord', 'Read', 'Rep', 'Show',
            'Traversable',
        ],
        // the class lists and the Ord methods
        'src/semantics/deriving/newtype.php' => [
            '<', '<=', '>', '>=', 'Foldable', 'Functor', 'Generic', 'Generic1', 'Ord',
            'Traversable', 'max', 'min',
        ],
        // `from`/`to` are TypeArrow keys (also Generic's selectors) — host vocabulary
        'src/semantics/evidence_names.php' => [
            'from', 'to',
        ],
        // host marshalling: the boxing decision reads the library's declarations — a primitive is
        // its `type X = X#` alias, an enum its all-nullary `data` — so no primitive name is
        // carried here and `IOMode` is one of those enumerations. What is left is the set of
        // types the boundary converts and the two host handles
        'src/semantics/foreign.php' => [
            'Either', 'Handle', 'Maybe', 'PHPValue', 'Resource', 'String',
        ],
        // the `Solo` alias for `MkSolo` (the `IO` reservation tests the kernel id)
        'src/semantics/types/declarations.php' => [
            'Solo',
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
// literal, a list that is not all names, a one-character name, a host keyword, a host instruction).
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
$j = match ($op) { 'div' => 1, 'default' => 2 };
$k = $word === 'not';
$l = match ($fn) { 'abs' => 1, 'default' => 2 };
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
        $relative = canonicalSeparators(\substr($file, \strlen($root) + 1));
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
