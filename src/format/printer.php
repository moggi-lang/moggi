<?php declare(strict_types=1);

namespace Moggi\Format;

use Moggi\Syntax\Ast;
use function Moggi\Format\render;
use function Moggi\Syntax\Parser\standardFixity;
use function Moggi\Syntax\isConstructorName;

/**
 * The fixity the parens around a nested operator chain depend on.
 *
 * The tree records grouping but not fixity, so `(x : xs) : ys` and `x : (xs : ys)` are distinguishable
 * only by association, which the operator decides, and `(a <$ b) $< c` from `a <$ (b $< c)` only by
 * precedence. `printProgram` fills this from the module's own `infix*` lines and the fixities its
 * imports bring into scope; `:` is the language's cons. An operator neither declares nor imports has
 * no fixity this printer can know, so it answers `null` and the caller keeps the parentheses the
 * source had.
 */
final class Operators
{
    /** @var array<string, string> */
    private static array $assoc = [];

    /** @var array<string, int> */
    private static array $precedence = [];

    /**
     * @param list<array{operators: list<string>, assoc: string, precedence: int}> $declarations
     * @param array<string, array{assoc: string, prec: int}> $imported
     */
    public static function begin(array $declarations, array $imported = []): void
    {
        self::$assoc = [];
        self::$precedence = [];
        foreach ($imported as $operator => $info) {
            self::$assoc[$operator] = match ($info['assoc'] ?? 'infixl') {
                'r', 'infixr' => 'infixr',
                'n', 'infix' => 'infix',
                default => 'infixl',
            };
            self::$precedence[$operator] = (int) ($info['precedence'] ?? $info['prec'] ?? 9);
        }
        foreach ($declarations as $declaration) {
            $assoc = match ($declaration['assoc']) {
                'r', 'infixr' => 'infixr',
                'n', 'infix' => 'infix',
                default => 'infixl',
            };
            foreach ($declaration['operators'] as $operator) {
                self::$assoc[$operator] = $assoc;
                self::$precedence[$operator] = (int) ($declaration['precedence'] ?? 9);
            }
        }
    }

    /**
     * The precedence the module declares, or the language's own.
     *
     * `null` means unknown, as for associativity: a chain under an operator the module never
     * declares keeps the parentheses the source had.
     */
    public static function precedence(?string $operator): ?int
    {
        if ($operator === null) {
            return null;
        }

        if (isset(self::$precedence[$operator])) {
            return self::$precedence[$operator];
        }
        if ($operator === ':') {
            return 5;
        }

        $standard = standardFixity($operator);

        return $standard['precedence'] ?? null;
    }

    /**
     * The associativity the module declares, or `null` when it declares none.
     *
     * `null` is not a detail. An operator neither declared nor imported may still come from a module
     * the formatter cannot resolve, and assuming the parser's default rewrote `(a >>> b) >>> c` as
     * `a >>> b >>> c`, which re-parses the other way under an imported `infixr` — a formatting change
     * that silently changes the program.
     */
    public static function assoc(?string $operator): ?string
    {
        if ($operator === null) {
            return null;
        }

        if (isset(self::$assoc[$operator])) {
            return self::$assoc[$operator];
        }
        if ($operator === ':') {
            return 'infixr';
        }

        $standard = standardFixity($operator);

        return $standard['assoc'] ?? null;
    }
}

/**
 * The printer: a parsed program, laid out canonically.
 *
 * Every function here either returns a document or refuses. There is no fallback to "print it as
 * written": the source text is gone by this point, so a node this file does not know how to lay out
 * is a `FormatError` and the caller leaves the file alone.
 *
 * Two shapes cannot be reproduced from this tree and are refused rather than guessed: a `case` that
 * the parser built from `if`/`then`/`else`, and the `case` it builds by merging a function's
 * clauses. Printing either as a `case` would rewrite the source's structure, which is not formatting.
 *
 * Imports come first, so fixity declarations follow them: the parser refuses an `import` after a
 * fixity line, and printing the two sections the other way round made its own output invalid.
 */
function printProgram(Ast\Program $program, array $importedFixity = []): string
{
    Operators::begin($program->fixityDecls, $importedFixity);
    $sections = [];

    $pragmas = [];
    foreach (\array_keys($program->language) as $flag) {
        $pragmas[] = Doc::text('{-# LANGUAGE ' . $flag . ' #-}');
    }
    if ($program->backendMap !== []) {
        $block = [Doc::text('{-# BACKEND')];
        foreach ($program->backendMap as $backend => $module) {
            $block[] = Doc::text('  ' . $backend . ' = ' . $module);
        }
        $block[] = Doc::text('#-}');
        $pragmas[] = joinLines($block);
    }
    if ($pragmas !== []) {
        $sections[] = render(joinLines($pragmas));
    }

    $sections[] = render(printHeader($program));

    if ($program->imports !== []) {
        $imports = [];
        foreach ($program->imports as $import) {
            $doc = $import->doc ?? null;
            $imports[] = render(\is_string($doc) && \trim($doc) !== ''
                ? joinLines([docComment($doc), printImport($import)])
                : printImport($import));
        }
        $sections[] = \implode("\n", $imports);
    }

    if ($program->fixityDecls !== []) {
        $lines = [];
        foreach ($program->fixityDecls as $decl) {
            $keyword = match ($decl['assoc']) {
                'l', 'infixl' => 'infixl',
                'r', 'infixr' => 'infixr',
                default => 'infix',
            };
            $operators = [];
            foreach ($decl['operators'] as $operator) {
                $operators[] = infixOperator($operator);
            }
            $lines[] = $keyword . ' ' . $decl['precedence'] . ' ' . \implode(', ', $operators);
        }
        $sections[] = \implode("\n", $lines);
    }

    if ($program->items !== []) {
        $decls = [];
        $count = \count($program->items);
        foreach ($program->items as $index => $item) {
            $next = $index + 1 < $count ? $program->items[$index + 1] : null;
            Trivia::at($next?->line ?? PHP_INT_MAX);

            $rendered = '';
            foreach (commentLines(Trivia::takeBefore($item->line, $item->col), 0) as $line) {
                $rendered .= $line . "\n";
            }

            $declaration = render(printDeclaration($item));
            $trailing = Trivia::takeTrailing($item->line);
            if ($trailing !== null) {
                $declaration = appendToFirstLine($declaration, $trailing['text']);
            }
            $rendered .= $declaration;
            foreach (commentLines(Trivia::takeAfter((int) Trivia::$boundary), 0) as $line) {
                $rendered .= "\n" . $line;
            }

            $decls[] = $rendered;
        }

        $sections[] = \implode("\n\n", $decls);
    }

    return \implode("\n\n", $sections) . "\n";
}

/** @param list<Doc> $docs */
function joinLines(array $docs): Doc
{
    $parts = [];
    foreach ($docs as $index => $child) {
        if ($index !== 0) {
            $parts[] = Doc::line();
        }
        $parts[] = $child;
    }

    return Doc::concat($parts);
}

/** @param list<Doc> $docs */
function joinDocs(array $docs): Doc
{
    return Doc::concat($docs);
}

/**
 * An application as its head and arguments, in source order.
 *
 * The tree nests applications to the left, so walking it gives the arguments back to front. This
 * collects them once and reverses the list; `array_unshift` in the walk moved every earlier
 * argument for each new one, which is quadratic for a long application.
 *
 * @return array{Ast\AstNode, list<Ast\AstNode>}
 */
function splitApply(Ast\Apply $apply): array
{
    $args = [];
    $head = $apply;
    while ($head instanceof Ast\Apply) {
        $args[] = $head->argument;
        $head = $head->function;
    }

    return [$head, \array_reverse($args)];
}

function text(string $string): Doc
{
    return Doc::text($string);
}

/**
 * The file header comments, the module doc and the header line; pragmas are printed above.
 *
 * Export items carry their own line and column, so a comment written between them is printed above
 * the item it precedes — the `-- * Methods` section headers of a long export list stay where they
 * were instead of being refused. A comment there costs the flat layout: `module M (a, b) where` has
 * no line a comment could sit on.
 *
 * The program's position is the `module` keyword's, which is what tells the two apart: a comment
 * written above it is a file header and is printed above everything, while only the comments between
 * the keyword and an export belong to that export. Without the bound the first export's `takeBefore`
 * reached back to the top of the file and a copyright header ended up inside `module M ( … )`.
 */
function printHeader(Ast\Program $program): Doc
{
    $docs = [];

    $header = $program->line > 0
        ? commentLines(Trivia::takeBefore($program->line, $program->col), 0)
        : [];
    if ($header !== []) {
        $docs[] = joinLines(\array_map(text(...), $header));
        $docs[] = Doc::line();
    }

    if ($program->moduleDoc !== null) {
        $docs[] = docComment($program->moduleDoc);
        $docs[] = Doc::line();
    }

    if ($program->module === null) {
        return joinDocs($docs);
    }

    $name = $program->module;
    $exports = $program->exports ?? [];

    if ($exports === []) {
        return joinDocs([...$docs, Doc::text('module ' . $name . ' where')]);
    }

    $items = [];
    $commented = false;
    foreach ($exports as $export) {
        $comments = isset($export['line'], $export['col'])
            ? Trivia::takeBefore((int) $export['line'], (int) $export['col'])
            : [];
        $commented = $commented || $comments !== [];
        $items[] = ['comments' => $comments, 'text' => exportItem($export)];
    }

    $names = [];
    foreach ($items as $item) {
        $names[] = $item['text'];
    }
    $flat = 'module ' . $name . ' (' . \implode(', ', $names) . ') where';
    if (! $commented && \count($items) <= 2 && displayWidth($flat) <= WIDTH) {
        return joinDocs([...$docs, Doc::text($flat)]);
    }

    $lines = [];
    foreach ($items as $index => $item) {
        if ($item['comments'] === []) {
            $lines[] = Doc::text(($index === 0 ? '( ' : ', ') . $item['text']);

            continue;
        }
        foreach (commentLines($item['comments'], 0) as $which => $comment) {
            $lines[] = Doc::text($index === 0 && $which === 0 ? '( ' . $comment : '  ' . $comment);
        }
        $lines[] = Doc::text(($index === 0 ? '  ' : ', ') . $item['text']);
    }
    $body = Doc::concat([joinLines($lines), Doc::line(), Doc::text(') where')]);

    return joinDocs([...$docs, Doc::text('module ' . $name), Doc::nest(2, Doc::concat([Doc::line(), $body]))]);
}

/** @param array<string, mixed> $export */
function exportItem(array $export): string
{
    $tag = $export['tag'] ?? 'value';
    if ($tag === 'module') {
        return 'module ' . \implode('.', $export['path'] ?? []);
    }

    $name = printedName((string) ($export['name'] ?? ''));
    $children = $export['children'] ?? null;
    $item = $children === null
        ? $name
        : (($children['mode'] ?? 'every') === 'every'
            ? $name . '(..)'
            : $name . '(' . \implode(', ', \array_map(printedName(...), $children['names'] ?? [])) . ')');

    $namespace = $export['namespace'] ?? null;

    return \is_string($namespace) && $namespace !== '' ? $namespace . ' ' . $item : $item;
}

/**
 * One import declaration.
 *
 * `import M (a, b)` stays on one line while it fits; otherwise the head keeps `import M` (or
 * `import M hiding`) and the items follow one per line with leading commas. The 100th column is
 * inclusive: a 100-column import is flat, a 101-column one breaks.
 *
 * A symbolic member is parenthesized here too: `import X ((<>))`.
 *
 * `import M ()` is not `import M`: the empty list imports the module's instances and nothing else,
 * and the parser tells the two apart by the import's `named` kind. Printing the bare head for an
 * empty list would quietly widen the import to every name the module exports.
 */
function printImport(Ast\ImportDecl $import): Doc
{
    $head = 'import ' . \implode('.', $import->path);

    $list = null;
    if ($import->items !== []) {
        $list = $import->items;
    } elseif ($import->hiding !== []) {
        $head .= ' hiding';
        $list = $import->hiding;
    } elseif ($import->kind === 'named') {
        $head .= ' ()';
    }

    $items = [];
    foreach ($list ?? [] as $item) {
        $items[] = \is_string($item) ? printedName($item) : importItem($item);
    }

    $suffix = '';
    if ($import->qualifiedOnly) {
        $suffix .= ' qualified';
    }
    if ($import->asName !== null) {
        $suffix .= ' as ' . $import->asName;
    }

    if ($items === []) {
        return Doc::text($head . $suffix);
    }

    $flat = $head . ' (' . \implode(', ', $items) . ')' . $suffix;
    if (displayWidth($flat) <= WIDTH) {
        return Doc::text($flat);
    }

    $bodyParts = [Doc::text('( ' . $items[0])];
    for ($index = 1, $count = \count($items); $index < $count; ++$index) {
        $bodyParts[] = Doc::line();
        $bodyParts[] = Doc::text(', ' . $items[$index]);
    }
    $bodyParts[] = Doc::line();
    $bodyParts[] = Doc::text(')');

    return Doc::concat([
        Doc::text($head),
        Doc::nest(2, Doc::concat([Doc::line(), Doc::concat($bodyParts), Doc::text($suffix)])),
    ]);
}

/** No space before a class's method sublist: `Maybe(Just, Nothing)`. */
function importItem(Ast\ImportItem $item): string
{
    $name = ($item->pattern ? 'pattern ' : '') . printedName($item->name);
    $alias = $item->asName === null ? '' : ' as ' . printedName($item->asName);
    $methods = $item->methods;
    if ($methods === null) {
        return $name . $alias;
    }

    $printed = $methods === 'every'
        ? $name . '(..)'
        : $name . '(' . \implode(', ', \array_map(printedName(...), $methods)) . ')';

    return $printed . $alias;
}

/** One top-level declaration. */
function printDeclaration(Ast\AstNode $item): Doc
{
    $body = printDeclarationBody($item);
    $doc = $item->doc ?? null;
    if (! \is_string($doc) || \trim($doc) === '') {
        return $body;
    }

    if ($item->docIsTrailing && $item instanceof Ast\DataDecl) {
        $docs = [];
        foreach (\explode("\n", \trim($doc)) as $line) {
            $docs[] = \rtrim('  -- ^ ' . $line);
        }

        return joinLines([$body, ...\array_map(text(...), $docs)]);
    }

    return joinLines([docComment($doc), $body]);
}

/** Append a trailing comment at the end of a construct's first line. */
function appendToFirstLine(string $text, string $comment): string
{
    $newline = \strpos($text, "\n");
    if ($newline === false) {
        return $text . ' ' . $comment;
    }

    return \substr($text, 0, $newline) . ' ' . $comment . \substr($text, $newline);
}

/** A doc comment as source: `-- |` on the first line, `--` on the rest. */
function docComment(string $doc): Doc
{
    $lines = \explode("\n", \trim($doc));
    $docs = [\rtrim('-- | ' . \array_shift($lines))];
    foreach ($lines as $line) {
        $docs[] = \rtrim('-- ' . $line);
    }

    return joinLines(\array_map(text(...), $docs));
}

function printDeclarationBody(Ast\AstNode $item): Doc
{
    if ($item instanceof Ast\FunctionDecl) {
        return printFunction($item);
    }
    if ($item instanceof Ast\DataDecl) {
        return printData($item);
    }
    if ($item instanceof Ast\ForeignImportDecl) {
        return Doc::text(
            'foreign ' . $item->backend . ' ' . $item->kind . ' ' . $item->name
            . ' ' . literalString($item->path) . ' :: ' . printType($item->type),
        );
    }
    if ($item instanceof Ast\ForeignTypeDecl) {
        return Doc::text(
            'foreign ' . $item->backend . ' type ' . $item->name . ' ' . literalString($item->hostType),
        );
    }
    if ($item instanceof Ast\TypeSynonymDecl) {
        if ($item->type === null) {
            return printTypeFamily($item);
        }
        if (\count($item->params) === 2 && isSymbolicName($item->name)) {
            return Doc::text('type ' . $item->params[0] . ' ' . $item->name . ' ' . $item->params[1]
                . ' = ' . printType($item->type));
        }

        $params = $item->params === [] ? '' : ' ' . \implode(' ', $item->params);

        return Doc::text('type ' . $item->name . $params . ' = ' . printType($item->type));
    }
    if ($item instanceof Ast\PatternSynonymDecl) {
        return printPatternSynonym($item);
    }
    if ($item instanceof Ast\ClassDecl) {
        return printClass($item);
    }
    if ($item instanceof Ast\InstanceDecl) {
        return printInstance($item);
    }
    if ($item instanceof Ast\StandaloneDerivingDecl) {
        return printStandaloneDeriving($item);
    }

    throw new FormatError('the printer does not handle ' . \get_class($item) . ' yet');
}

/**
 * A closed type family: its head, then one equation per line, two columns in.
 *
 * The equations are what a family is: `type` carries no expansion, so the `where` block is the
 * only body it has. A family with none is a bare head, which is also how a declaration written
 * without equations comes back.
 */
function printTypeFamily(Ast\TypeSynonymDecl $family): Doc
{
    $params = [];
    foreach ($family->familyParams as $param) {
        $params[] = $param->kind instanceof Ast\KindInfer
            ? $param->name
            : '(' . $param->name . ' :: ' . printKind($param->kind) . ')';
    }

    $infix = \count($params) === 2 && isSymbolicName($family->name);
    $head = $infix
        ? 'type family ' . $params[0] . ' ' . $family->name . ' ' . $params[1]
        : 'type family ' . printedName($family->name) . ($params === [] ? '' : ' ' . \implode(' ', $params));
    if ($family->familyResultKind !== null) {
        $head .= ' :: ' . printKind($family->familyResultKind);
    }

    if ($family->familyEquations === []) {
        return Doc::text($head);
    }

    $lines = [];
    foreach ($family->familyEquations as $equation) {
        $lines[] = Doc::text(printType($equation['lhs']) . ' = ' . printType($equation['rhs']));
    }

    return Doc::concat([
        Doc::text($head . ' where'),
        Doc::nest(2, Doc::concat([Doc::line(), joinLines($lines)])),
    ]);
}

/**
 * A GADT declaration: `where` instead of `=`, and one `C :: type` per line.
 *
 * A constructor that has a signature was written in the GADT form, which has no `=` and no `|`:
 * `data T = C :: T` does not parse, and the layout is the only thing that tells the two apart.
 *
 * @param list<string> $clauses
 */
function printGadtData(Ast\DataDecl $data, string $head, array $clauses): Doc
{
    $lines = [];
    $last = \count($data->constructors) - 1;
    foreach ($data->constructors as $index => $constructor) {
        foreach (commentLines(Trivia::takeBefore($constructor->line, $constructor->col), 2) as $line) {
            $lines[] = Doc::text($line);
        }
        $constructorDoc = $constructor->doc ?? null;
        if (\is_string($constructorDoc) && \trim($constructorDoc) !== '') {
            foreach (\explode("\n", \trim($constructorDoc)) as $line => $text) {
                $lines[] = Doc::text(\rtrim(($line === 0 ? '-- | ' : '-- ') . $text));
            }
        }
        $lines[] = Doc::text(printedName($constructor->name) . ' :: ' . printType($constructor->signature));

        $nextLine = $index < $last ? $data->constructors[$index + 1]->line : Trivia::$boundary;
        foreach (commentLines(Trivia::takeAfter((int) $nextLine), 2) as $line) {
            $lines[] = Doc::text($line);
        }
    }

    $parts = [
        Doc::text($head . ' where'),
        Doc::nest(2, Doc::concat([Doc::line(), joinLines($lines)])),
    ];
    foreach ($clauses as $clause) {
        $parts[] = Doc::line();
        $parts[] = Doc::text('  ' . $clause);
    }

    return Doc::concat($parts);
}

/** A signature and the binding it belongs to: no blank line between them. */
function printFunction(Ast\FunctionDecl $function): Doc
{
    $docs = [];
    $name = printedName($function->name);
    $infix = $function->infixDefinition ? $function->name : null;

    if ($function->type !== null) {
        $docs[] = signature($name, $function->type);
    }

    if ($function->signatureOnly) {
        return joinDocs($docs);
    }

    if ($function->clauses !== null && \count($function->clauses) > 1) {
        $clauses = [];
        foreach ($function->clauses as $clause) {
            $clauses[] = binding($name, $clause['params'], $clause['body'], $infix);
        }
        $docs[] = joinLines($clauses);

        return joinLines($docs);
    }

    $docs[] = binding($name, $function->params, $function->body, $infix);

    return joinLines($docs);
}

/**
 * A symbolic name is a definition only with its parentheses: `(<|) x xs = …`.
 *
 * A name ending in `#` is an ordinary identifier to the lexer (`coerce#` is one token), so wrapping
 * it turned an import item into a parenthesized operator and the printed text did not parse.
 */
function printedName(string $name): string
{
    if (\preg_match('/^[A-Za-z_][A-Za-z0-9_\x27]*#?$/', $name) === 1) {
        return $name;
    }

    return '(' . $name . ')';
}

/** `name :: type`, broken the way a long signature breaks. */
function signature(string $name, Ast\TypeNode $type): Doc
{
    $flat = $name . ' :: ' . printType($type);
    if (displayWidth($flat) <= WIDTH && ! typeHasDocs($type)) {
        return Doc::text($flat);
    }

    return breakSignature($name, $type);
}

/**
 * A signature too wide for one line: the name, then `::` and each arrow segment on its own line,
 * with a `forall … .` and the context above the `=>`, aligned under the `:: `.
 *
 * The type follows the `::` on the same line when there is nothing above it, and hangs under
 * the `:: ` column when a `forall` or a context does. Every line after the name belongs to the
 * `nest(2)` below, so the whole block keeps its shape wherever the signature is printed — a
 * class member is as aligned as a top-level binding.
 */
function breakSignature(string $name, Ast\TypeNode $type): Doc
{
    $forall = null;
    $node = $type;
    if ($node instanceof Ast\TypeForall) {
        $forall = $node;
        $node = $node->body;
    }

    $constraints = [];
    if ($node instanceof Ast\TypeConstrained) {
        $constraints = $node->constraints;
        $node = $node->body;
    }

    $segments = [];
    while ($node instanceof Ast\TypeArrow) {
        $segments[] = $node->from;
        $node = $node->to;
    }
    $segments[] = $node;

    if (\count($segments) < 2) {
        return Doc::text($name . ' :: ' . printType($type));
    }

    $context = $constraints === [] ? null : printedContext($constraints);

    $lines = [];
    if ($forall !== null) {
        $lines[] = ':: forall ' . printedTypeBinders($forall) . '.';
        if ($context !== null) {
            $lines[] = '   ' . $context;
        }
    } elseif ($context !== null) {
        $lines[] = ':: ' . $context;
    }

    $first = \array_shift($segments);
    if ($context !== null) {
        $lines[] = '=> ' . printedTypeSegment($first);
    } else {
        $lines[] = ($lines === [] ? ':: ' : '   ') . printedTypeSegment($first);
    }
    \array_push($lines, ...printedTypeDocs($first));
    foreach ($segments as $segment) {
        $lines[] = '-> ' . printedTypeSegment($segment);
        \array_push($lines, ...printedTypeDocs($segment));
    }

    $tail = [];
    foreach ($lines as $line) {
        $tail[] = Doc::line();
        $tail[] = Doc::text($line);
    }

    return Doc::concat([Doc::text($name), Doc::nest(2, Doc::concat($tail))]);
}

/**
 * One arrow segment of a broken signature, in the parentheses it needs.
 *
 * A segment that is itself an arrow has to keep them: `(Int -> ReadS a) -> …` printed bare
 * re-parses as two segments, so the same signature broke differently on the next save — and the
 * two layouts were not even the same type once the arrow was counted in a different place. A
 * `forall` and a context bind as far to the right as they can, so they swallow every segment
 * after them unless they are parenthesized too.
 */
function printedTypeSegment(Ast\TypeNode $segment): string
{
    $printed = printType($segment);

    return $segment instanceof Ast\TypeArrow || $segment instanceof Ast\TypeForall
        || $segment instanceof Ast\TypeConstrained
        ? '(' . $printed . ')'
        : $printed;
}

/**
 * The `-- ^` doc lines that follow one arrow segment of a broken signature.
 *
 * The parser hangs the doc on the segment it follows, so it is printed under that segment and in
 * front of the arrow that ends it — where it was written, and where the parser reads it back.
 * `-- ^` opens it and a plain `--` continues it, the same spelling a leading doc uses, and the
 * three spaces line the comment up under the segment text, past the `::`, `=>` or `->` marker.
 *
 * @return list<string>
 */
function printedTypeDocs(Ast\TypeNode $segment): array
{
    $doc = $segment->doc;
    if ($doc === null || \trim($doc) === '') {
        return [];
    }

    $lines = [];
    foreach (\explode("\n", \trim($doc)) as $index => $line) {
        $lines[] = \rtrim('   ' . ($index === 0 ? '-- ^ ' : '-- ') . \trim($line));
    }

    return $lines;
}

/**
 * Whether any node of a type carries a `-- ^` doc, so the signature cannot stay on one line.
 *
 * A doc inside a type marks where an argument is documented, and one printed in the middle of a
 * flat signature would comment out the rest of the line.
 */
function typeHasDocs(Ast\TypeNode $type): bool
{
    if (\is_string($type->doc) && \trim($type->doc) !== '') {
        return true;
    }
    if ($type instanceof Ast\TypeArrow) {
        return typeHasDocs($type->from) || typeHasDocs($type->to);
    }
    if ($type instanceof Ast\TypeApp) {
        if (typeHasDocs($type->con)) {
            return true;
        }
        foreach ($type->args as $argument) {
            if (typeHasDocs($argument)) {
                return true;
            }
        }

        return false;
    }
    if ($type instanceof Ast\TypeConstrained) {
        foreach ($type->constraints as $constraint) {
            if (typeHasDocs($constraint)) {
                return true;
            }
        }

        return typeHasDocs($type->body);
    }
    if ($type instanceof Ast\TypeForall) {
        return typeHasDocs($type->body);
    }

    return false;
}

/**
 * A definition head: the name and its parameters, wrapped when the head is wider than the page.
 *
 * The parameter list is the part of a head that can break without changing the binding. Later
 * parameters continue four columns in, the shape a hand-written head already uses, and the `=`
 * stays on the last parameter's line.
 *
 * @param list<string> $printed
 */
function headDoc(string $name, array $printed, ?string $infix = null): Doc
{
    if ($infix !== null && \count($printed) === 2) {
        return Doc::text($printed[0] . ' ' . infixOperator($infix) . ' ' . $printed[1]);
    }
    if ($printed === []) {
        return Doc::text($name);
    }

    $flat = $name . ' ' . \implode(' ', $printed);

    if (\count($printed) === 1 || displayWidth($flat) + 2 <= WIDTH - 2) {
        return Doc::text($flat);
    }

    $lines = [];
    $current = $name;
    $last = \count($printed) - 1;
    foreach ($printed as $index => $param) {
        $suffix = $index === $last ? 2 : 0;
        $budget = $lines === [] ? WIDTH - 2 : WIDTH - 6;
        $candidate = $current . ' ' . $param;
        if ($current !== $name && displayWidth($candidate) + $suffix > $budget) {
            $lines[] = $current;
            $current = $param;
            continue;
        }
        $current = $candidate;
    }
    $lines[] = $current;

    $rest = [];
    foreach (\array_slice($lines, 1) as $line) {
        $rest[] = Doc::line();
        $rest[] = Doc::text($line);
    }

    return Doc::concat([Doc::text($lines[0]), Doc::nest(4, Doc::concat($rest))]);
}

/**
 * `name p1 p2 = body`, with the body's own block (`where`, guards, `case`) below it.
 *
 * `$infix` is the unparenthesized operator name when the source defined it between its two
 * operands (`xs <+> ys = …`); the parameters then keep their order around the operator.
 */
function binding(string $name, array $params, Ast\AstNode $body, ?string $infix = null): Doc
{
    $printed = [];
    foreach ($params as $param) {
        $printed[] = printParam($param);
    }

    $head = headDoc($name, $printed, $infix);

    $comp = $body->listComp();
    if ($comp !== null) {
        return listCompBindingDoc($head, $comp);
    }

    if ($body instanceof Ast\Where) {
        return Doc::concat([
            binding($name, $params, $body->expr, $infix),
            Doc::line(),
            Doc::text('  where'),
            Doc::nest(4, Doc::concat([Doc::line(), printBindings($body->bindings)])),
        ]);
    }

    if ($body instanceof Ast\GuardsExpr) {
        return printGuards($head, $body);
    }

    if ($body instanceof Ast\CaseExpr) {
        return $body->ifParts !== null ? printIfBinding($head, $body) : printCase($head, $body);
    }

    if ($body instanceof Ast\Let && (containsBlock($body) || \count($body->bindings) > 1)) {
        return Doc::concat([$head, Doc::text(' ='), Doc::nest(2, Doc::concat([Doc::line(), letDoc($body)]))]);
    }

    if ($body instanceof Ast\DoExpr) {
        return printDo($head, $body);
    }

    if (containsBlock($body)) {
        return Doc::concat([$head, Doc::text(' = '), expressionDoc($body)]);
    }

    if (! $head->hasHardLine) {
        $inline = renderFlat($head) . ' = ' . printExpression($body);
        if (displayWidth($inline) <= WIDTH) {
            return Doc::text($inline);
        }
    }

    return printBrokenBody($head, $body);
}

/** A body too wide for its line: the expression starts on the next line, two columns in. */
function printBrokenBody(Doc $head, Ast\AstNode $body): Doc
{
    return Doc::concat([
        $head,
        Doc::text(' ='),
        Doc::nest(2, Doc::concat([Doc::line(), brokenBodyDoc($body, 2)])),
    ]);
}

/**
 * A wide body, laid out by the shape it has.
 *
 * Every wide body needs a place to break, and the shape decides where it is: a chain at its
 * operators, an application at its arguments, a `let` at its `in`, a bracketed literal at its
 * commas. `$indent` is the column the body starts at, because whether its first line fits depends
 * on it. One atom has no such place: it is printed on a line of its own even past the width,
 * because splitting a name or a literal would not be the same token. A body with no valid break
 * point at all is printed as one long line for now: the width is a best-effort target, never a
 * reason to refuse a file or to split a token.
 *
 * After the first break every argument takes a line of its own instead of packing again. Packing
 * would reflow the whole body when one argument grew, and the canonical layout is there to stop
 * exactly that rewriting.
 */
function brokenBodyDoc(Ast\AstNode $body, int $indent): Doc
{
    $comp = $body->listComp();
    if ($comp !== null) {
        return comprehensionDoc($comp, $indent);
    }
    if (containsBlock($body)) {
        return expressionDoc($body);
    }

    return brokenExpressionDoc($body, $indent);
}

/**
 * The deep form of one expression: the same place to break as `brokenBodyDoc`, at any depth.
 *
 * An argument or operand used to be printed as one string even after the application or chain broke,
 * so a long parenthesized application inside an argument had no break point at all and produced a
 * line hundreds of columns wide. Recursing here gives every nested bracket, argument and operator
 * the layout its own shape calls for.
 */
function brokenExpressionDoc(Ast\AstNode $expression, int $indent): Doc
{
    if (containsBlock($expression)) {
        return expressionDoc($expression);
    }

    $printed = measureInline($expression);
    if ($indent + displayWidth($printed) <= WIDTH) {
        return Doc::text($printed);
    }

    return brokenNodeDoc($expression, $indent);
}

/** `brokenExpressionDoc` for a construct that is already known not to fit. */
function brokenNodeDoc(Ast\AstNode $expression, int $indent): Doc
{
    $comp = $expression->listComp();
    if ($comp !== null) {
        return comprehensionDoc($comp, $indent);
    }
    if (containsBlock($expression)) {
        return expressionDocNode($expression);
    }
    if ($expression instanceof Ast\Apply && $expression->enumForm === null) {
        return brokenApplyDoc($expression, $indent);
    }
    if ($expression instanceof Ast\Infix) {
        return chainDoc($expression, $indent);
    }
    if ($expression instanceof Ast\Let) {
        return brokenLetDoc($expression, $indent);
    }
    if ($expression instanceof Ast\Tuple) {
        return brokenSequenceDoc('(', ',', ')', $expression->elements, $indent);
    }
    if ($expression instanceof Ast\ListLit) {
        return brokenSequenceDoc('[', ',', ']', $expression->elements, $indent);
    }
    if ($expression instanceof Ast\RecordCon) {
        return brokenRecordDoc($expression);
    }
    if ($expression instanceof Ast\TypeAsc) {
        $inner = $expression->expr;
        $needsParens = ! isAtomicExpression($inner);
        $doc = brokenExpressionDoc($inner, $indent + ($needsParens ? 1 : 0));
        if ($needsParens) {
            $doc = Doc::concat([Doc::text('('), $doc, Doc::text(')')]);
        }
        $type = ' :: ' . printType($expression->type);
        if ($indent + displayWidth($type) + 12 <= WIDTH) {
            return Doc::concat([$doc, Doc::text($type)]);
        }

        return Doc::concat([$doc, Doc::nest(2, Doc::concat([
            Doc::line(),
            breakSignature('', $expression->type),
        ]))]);
    }
    if ($expression instanceof Ast\Lambda && ! $expression->section) {
        $params = [];
        foreach ($expression->params as $param) {
            $params[] = printParam($param->pattern ?? $param);
        }
        $head = '\\' . \implode(' ', $params) . ' ->';

        return Doc::concat([
            Doc::text($head),
            Doc::nest(2, Doc::concat([Doc::line(), brokenBodyDoc($expression->body, $indent + 2)])),
        ]);
    }
    if ($expression instanceof Ast\FieldAccess && ! isAtomicExpression($expression->object)) {
        return Doc::concat([brokenExpressionDoc($expression->object, $indent), Doc::text('.' . $expression->field)]);
    }
    if (isAtomicExpression($expression) || $expression instanceof Ast\Lambda) {
        return Doc::text(measureInline($expression));
    }

    return inlineExpressionDoc($expression);
}

/**
 * A wide application: the head and as many arguments as fit, then one argument per line, two
 * columns in.
 *
 * The head keeps the parentheses an application head needs (`(\x -> x) 1`), exactly as the inline
 * printer prints it, so the two cannot disagree about an argument's text.
 */
function brokenApplyDoc(Ast\Apply $apply, int $indent): Doc
{
    [$head, $args] = splitApply($apply);

    $headText = measureInline($head);
    if (! isAtomicExpression($head)) {
        $headText = '(' . $headText . ')';
    }

    $doc = Doc::text($headText);
    $width = $indent + displayWidth($headText);
    $broken = false;
    foreach ($args as $argument) {
        $printed = containsBlock($argument) ? '' : measureInline($argument);
        if (! $broken && ! containsBlock($argument) && $width + 1 + displayWidth($printed) <= WIDTH) {
            $doc = Doc::concat([$doc, Doc::text(' '), argumentInlineExpressionDoc($argument)]);
            $width += 1 + displayWidth($printed);

            continue;
        }

        $broken = true;
        $doc = Doc::concat([
            $doc,
            Doc::nest(2, Doc::concat([Doc::line(), brokenArgumentDoc($argument, $indent + 2)])),
        ]);
    }

    return $doc;
}

/** An argument in a broken application: parentheses where required, breaking inside them. */
function brokenArgumentDoc(Ast\AstNode $argument, int $indent): Doc
{
    $inline = Trivia::takeInline($argument->line, $argument->col);
    $needsParens = argumentNeedsParens($argument);
    $printed = containsBlock($argument) ? '' : measureInline($argument);
    $doc = ! containsBlock($argument) && $indent + displayWidth($printed) + ($needsParens ? 2 : 0) <= WIDTH
        ? Doc::text($printed)
        : brokenNodeDoc($argument, $indent + ($needsParens ? 1 : 0));
    if ($needsParens) {
        $doc = Doc::concat([Doc::text('('), $doc, Doc::text(')')]);
    }

    return $inline === null ? $doc : Doc::concat([Doc::text($inline['text'] . ' '), $doc]);
}

/**
 * A wide single-binding `let`: the value breaks under its own `=`, and `in` returns to the `let`'s
 * column. Any other binding shape goes through `letDoc`, which already lays those out.
 */
function brokenLetDoc(Ast\Let $let, int $indent): Doc
{
    if (\count($let->bindings) !== 1 || ! $let->bindings[0] instanceof Ast\Binding
        || ! $let->bindings[0]->pattern instanceof Ast\PatVar
        || $let->bindings[0]->functionBinding) {
        return letDoc($let);
    }

    $binding = $let->bindings[0];
    $head = 'let ' . $binding->pattern->name . ' =';
    $onlyLine = $head . ' ' . printExpression($binding->value);
    $doc = $indent + displayWidth($onlyLine) <= WIDTH
        ? Doc::text($onlyLine)
        : Doc::concat([
            Doc::text($head),
            Doc::nest(2, Doc::concat([Doc::line(), brokenBodyDoc($binding->value, $indent + 2)])),
        ]);

    $inLine = 'in ' . printExpression($let->body);
    if ($indent + displayWidth($inLine) <= WIDTH) {
        return Doc::concat([$doc, Doc::line(), Doc::text($inLine)]);
    }

    return Doc::concat([
        $doc,
        Doc::line(),
        Doc::text('in'),
        Doc::nest(2, Doc::concat([Doc::line(), brokenBodyDoc($let->body, $indent + 2)])),
    ]);
}

/**
 * A wide bracketed literal: one element per line, the separator leading, the closer on a line of
 * its own — the shape the module header's export list already uses.
 *
 * @param list<Ast\AstNode> $items
 */
function brokenSequenceDoc(string $open, string $separator, string $close, array $items, int $indent): Doc
{
    $parts = [Doc::text($open . ' '), brokenExpressionDoc($items[0], $indent + 2)];
    for ($index = 1, $count = \count($items); $index < $count; ++$index) {
        $parts[] = Doc::line();
        $parts[] = Doc::text($separator . ' ');
        $parts[] = brokenExpressionDoc($items[$index], $indent + 2);
    }
    $parts[] = Doc::line();
    $parts[] = Doc::text($close);

    return Doc::concat($parts);
}

/** A wide record: the constructor alone, its fields one per line under a leading `{`, `,` or `}`. */
function brokenRecordDoc(Ast\RecordCon $record): Doc
{
    $parts = [Doc::text($record->name)];
    foreach ($record->fields as $index => $field) {
        $parts[] = Doc::line();
        $parts[] = Doc::text(($index === 0 ? '  { ' : '  , ') . $field->name . ' = ');
        $parts[] = brokenExpressionDoc($field->expr, 6 + displayWidth($field->name));
    }
    $parts[] = Doc::line();
    $parts[] = Doc::text('  }');

    return Doc::concat($parts);
}

/**
 * A broken operator chain: the first operand, then one line per segment with a leading operator.
 *
 * Each segment hangs two columns past the first operand, which is the shape the fixtures pin for a
 * body, a `case` alternative and a guard alike. A chain whose source already broke after its
 * operators takes `sourceChainDoc` instead, so the term structure the author wrote survives.
 */
function chainDoc(Ast\Infix $chain, int $indent, bool $hanging = false): Doc
{
    if (chainHasSourceBreaks($chain)) {
        return sourceChainDoc($chain, $indent);
    }

    [$operator, $operands] = flattenChain($chain);
    $parts = [brokenOperandDoc($operands[0], $operator, true, $indent, true)];
    for ($index = 1, $count = \count($operands); $index < $count; ++$index) {
        $segment = Doc::concat([
            Doc::line(),
            Doc::text(infixOperator($operator) . ' '),
            brokenOperandDoc($operands[$index], $operator, false, $indent + 2, true),
        ]);
        $parts[] = $hanging ? $segment : Doc::nest(2, $segment);
    }

    return Doc::concat($parts);
}

/**
 * Whether the source broke this chain, or a chain nested in it, across lines.
 *
 * A chain the author already broke keeps its breaks: reflowing it into one operator per line
 * destroys the term structure the nesting was written to show. A sum of products reads as one
 * term per line, not one operator per line.
 */
function chainHasSourceBreaks(Ast\Infix $chain): bool
{
    [, $operands, $breaks] = flattenChain($chain);
    if (\in_array(true, $breaks, true)) {
        return true;
    }
    foreach ($operands as $operand) {
        if ($operand instanceof Ast\Infix && chainHasSourceBreaks($operand)) {
            return true;
        }
    }

    return false;
}

/**
 * A chain the source already breaks: the operator ends the line and the next operand starts at the
 * chain's own column, not past a staircase of indents.
 *
 * A parenthesized operand that begins on a later line keeps its opening parenthesis on the
 * operator's line, so a nested sum of products reads `term + factor * (` and then the next level,
 * exactly the shape it was written in.
 */
function sourceChainDoc(Ast\Infix $chain, int $indent): Doc
{
    [$operator, $operands, $breaks] = flattenChain($chain);
    $parts = [brokenOperandDoc($operands[0], $operator, true, $indent, true)];
    for ($index = 1, $count = \count($operands); $index < $count; ++$index) {
        $operand = $operands[$index];
        $operatorText = infixOperator($operator);
        if (! $breaks[$index - 1]) {
            $parts[] = Doc::text(' ' . $operatorText . ' ');
            $parts[] = brokenOperandDoc($operand, $operator, false, $indent, true);
            continue;
        }

        $parts[] = Doc::text(' ' . $operatorText);
        $needsParens = ($operand instanceof Ast\Infix && ! keepsGroupingWithoutParens($operand, $operator, false))
            || $operand instanceof Ast\TypeAsc || $operand instanceof Ast\Lambda || containsBlock($operand);
        if ($needsParens) {
            $parts[] = Doc::text(' (');
            $inner = $operand instanceof Ast\Infix
                ? chainDoc($operand, $indent, true)
                : expressionDoc($operand);
            $parts[] = Doc::concat([Doc::line(), $inner, Doc::text(')')]);
            continue;
        }

        $parts[] = Doc::concat([Doc::line(), brokenOperandDoc($operand, $operator, false, $indent, true)]);
    }

    return Doc::concat($parts);
}

/** One operand of a broken chain, with the parentheses its grouping needs. */
function brokenOperandDoc(Ast\AstNode $operand, ?string $parentOperator, bool $left, int $indent, bool $hanging = false): Doc
{
    $needsParens = ($operand instanceof Ast\Infix && ! keepsGroupingWithoutParens($operand, $parentOperator, $left))
        || $operand instanceof Ast\TypeAsc || $operand instanceof Ast\Lambda || containsBlock($operand);

    if ($hanging && $operand instanceof Ast\Infix) {
        $doc = chainDoc($operand, $indent, true);

        return $needsParens ? Doc::concat([Doc::text('('), $doc, Doc::text(')')]) : $doc;
    }

    $doc = brokenExpressionDoc($operand, $indent + ($needsParens ? 1 : 0));

    if ($needsParens) {
        $doc = Doc::concat([Doc::text('('), $doc, Doc::text(')')]);
    }

    return $doc;
}

/**
 * One operator chain, as `[operands]`, flattened on the side its associativity nests.
 *
 * `a + b + c` parses as `(a + b) + c`, and `a ++ b ++ c` as `a ++ (b ++ c)`, so the printer has to
 * flatten the left or the right spine respectively to lay the pieces out one per line. An operator
 * whose associativity nothing declares may have either shape in the source, so it is left nested
 * exactly as the tree wrote it; flattening it would turn explicit parentheses into a different
 * parse. A non-associative operator has only its two operands and never flattens.
 *
 * The third element says, for the operator between consecutive operands, whether the source put
 * its right operand on a later line than the operator. That distinguishes the author's
 * operator-at-line-end layout from the printer's leading-operator layout, which must not be read
 * back as one.
 *
 * @return array{string, list<Ast\AstNode>, list<bool>}
 */
function flattenChain(Ast\Infix $chain): array
{
    $operator = $chain->operator;
    $assoc = Operators::assoc($operator);

    if ($assoc === null || $assoc === 'infix') {
        return [$operator, [$chain->left, $chain->right], [$chain->breakAfterOperator]];
    }

    $operands = [];
    $breaks = [];
    $node = $chain;
    if ($assoc === 'infixr') {
        while ($node instanceof Ast\Infix && $node->operator === $operator) {
            $operands[] = $node->left;
            $breaks[] = $node->breakAfterOperator;
            $node = $node->right;
        }
        $operands[] = $node;

        return [$operator, $operands, $breaks];
    }

    while ($node instanceof Ast\Infix && $node->operator === $operator) {
        $operands[] = $node->right;
        $breaks[] = $node->breakAfterOperator;
        $node = $node->left;
    }
    $operands[] = $node;

    return [$operator, \array_reverse($operands), \array_reverse($breaks)];
}

/** Guards hang under the binding at two columns. */
function printGuards(Doc $head, Ast\GuardsExpr $guards): Doc
{
    $parts = [$head];
    foreach ($guards->clauses as $clause) {
        if (! $clause instanceof Ast\Guarded) {
            throw new FormatError('the printer does not handle ' . \get_class($clause) . ' in a guard block yet');
        }
        $condition = printExpression($clause->guard);
        if (! containsBlock($clause->body)) {
            $inline = '  | ' . $condition . ' = ' . printExpression($clause->body);
            if (displayWidth($inline) <= WIDTH) {
                $parts[] = Doc::line();
                $parts[] = Doc::text($inline);
                continue;
            }
        }

        $parts[] = Doc::line();
        $parts[] = Doc::text('  | ' . $condition . ' =');
        if (containsBlock($clause->body)) {
            $parts[] = Doc::nest(4, Doc::concat([Doc::line(), expressionDoc($clause->body)]));
            continue;
        }

        $parts[] = Doc::nest(4, Doc::concat([Doc::line(), brokenBodyDoc($clause->body, 4)]));
    }

    return Doc::concat($parts);
}

/** `head = if c then a else b`, inline while it fits. */
function printIfBinding(Doc $head, Ast\CaseExpr $if): Doc
{
    $parts = $if->ifParts;
    if (! $head->hasHardLine
        && ! containsBlock($parts['cond'])
        && ! containsBlock($parts['then'])
        && ! containsBlock($parts['else'])) {
        $flat = renderFlat($head) . ' = ' . printIfInline($if);
        if (displayWidth($flat) <= WIDTH) {
            return Doc::text($flat);
        }
    }

    return Doc::concat([$head, Doc::text(' ='), Doc::nest(2, Doc::concat([Doc::line(), ifExpression($if)]))]);
}

/** `if c then a else b` on one line, with a nested `if` staying inline too. */
function printIfInline(Ast\CaseExpr $if): string
{
    return renderFlat(inlineIfDoc($if, true));
}

/** The inline `if` as a document, so its branches share the one expression layout. */
function inlineIfDoc(Ast\CaseExpr $if, bool $comments): Doc
{
    return Doc::concat([
        Doc::text('if '),
        inlineChildDoc($if->ifParts['cond'], $comments),
        Doc::text(' then '),
        inlineBranchDoc($if->ifParts['then'], $comments),
        Doc::text(' else '),
        inlineBranchDoc($if->ifParts['else'], $comments),
    ]);
}

function inlineBranchDoc(Ast\AstNode $branch, bool $comments): Doc
{
    return $branch instanceof Ast\CaseExpr && $branch->ifParts !== null
        ? inlineIfDoc($branch, $comments)
        : inlineChildDoc($branch, $comments);
}

/** One branch of a broken `if`: a nested `if` keeps its own layout, anything else is an expression. */
function branchDoc(Ast\AstNode $branch): Doc
{
    if ($branch instanceof Ast\CaseExpr && $branch->ifParts !== null && ! containsBlock($branch)) {
        return Doc::text(printIfInline($branch));
    }

    return expressionDoc($branch);
}

/** One branch of an inline `if`: a nested `if` prints inline, everything else as an expression. */
function printBranch(Ast\AstNode $branch): string
{
    return renderFlat(inlineBranchDoc($branch, true));
}

/**
 * A broken `if`: `then` and `else` hang two columns below it, and an `else if` continues at that
 * column with its own branches two deeper again.
 */
function ifExpression(Ast\CaseExpr $if): Doc
{
    $doc = Doc::text('if ' . printExpression($if->ifParts['cond']));
    $doc = Doc::concat([
        $doc,
        Doc::nest(2, Doc::concat([Doc::line(), Doc::concat([Doc::text('then '), branchDoc($if->ifParts['then'])])])),
    ]);

    $else = $if->ifParts['else'];
    $elseDoc = $else instanceof Ast\CaseExpr && $else->ifParts !== null
        ? Doc::concat([Doc::text('else '), ifExpression($else)])
        : Doc::concat([Doc::text('else '), branchDoc($else)]);

    return Doc::concat([$doc, Doc::nest(2, Doc::concat([Doc::line(), $elseDoc]))]);
}

/** Whether a `let` has exactly one binding to a plain name, the shape that prints on one line. */
function isSingleVariableBinding(Ast\Let $let): bool
{
    return \count($let->bindings) === 1 && $let->bindings[0] instanceof Ast\Binding
        && $let->bindings[0]->pattern instanceof Ast\PatVar;
}

/** `expr where …`, four columns under the `where`. */
function whereExpressionDoc(Ast\Where $where): Doc
{
    return Doc::concat([
        expressionDoc($where->expr),
        Doc::line(),
        Doc::text('  where'),
        Doc::nest(4, Doc::concat([Doc::line(), printBindings($where->bindings)])),
    ]);
}

/** Multi-binding `let`: the bindings align under the first name, `in` returns to the `let`. */
function letDoc(Ast\Let $let): Doc
{
    $docs = [];
    foreach ($let->bindings as $index => $binding) {
        if (! $binding instanceof Ast\Binding) {
            throw new FormatError('the printer does not handle this kind of `let` binding yet');
        }
        if ($index !== 0) {
            $docs[] = Doc::line();
        }
        if ($binding->functionBinding) {
            $docs[] = Doc::text($index === 0 ? 'let ' : '    ');
            $docs[] = Doc::align(printBinding($binding));

            continue;
        }
        $docs[] = Doc::text(($index === 0 ? 'let ' : '    ') . printParam($binding->pattern) . ' = ');
        $docs[] = expressionDoc($binding->value);
    }
    $docs[] = Doc::line();
    $docs[] = Doc::text('in ');
    $docs[] = expressionDoc($let->body);

    return Doc::align(Doc::concat($docs));
}

/** `head = case x of` with one alternative per line at two columns. */
function printCase(Doc $head, Ast\CaseExpr $case): Doc
{
    return Doc::concat([$head, Doc::text(' = '), caseExpressionDoc($case)]);
}

/**
 * `case x of` as an expression: the alternatives sit two columns under it, so it nests anywhere a
 * document does — an alternative's body, an argument, a `do` statement.
 */
function caseExpressionDoc(Ast\CaseExpr $case): Doc
{
    $alts = [];
    foreach ($case->alts as $alt) {
        foreach (commentLines(Trivia::takeBefore($alt->pattern->line, $alt->pattern->col), 0) as $line) {
            $alts[] = Doc::line();
            $alts[] = Doc::text($line);
        }
        $pattern = printPattern($alt->pattern);
        $trailing = Trivia::takeTrailing($alt->pattern->line);
        $suffix = $trailing === null ? '' : ' ' . $trailing['text'];

        $block = containsBlock($alt->body);
        if (! $block) {
            $inline = $pattern . ' -> ' . printExpression($alt->body) . $suffix;
            if (displayWidth($inline) + 2 <= WIDTH) {
                $alts[] = Doc::line();
                $alts[] = Doc::text($inline);
                continue;
            }

            $alts[] = Doc::line();
            $alts[] = Doc::text($pattern . ' ->' . $suffix);
            $alts[] = Doc::nest(2, Doc::concat([Doc::line(), altBodyDoc($alt->body)]));
            continue;
        }

        $body = altBodyDoc($alt->body);
        $head = $pattern . ' -> ';
        if ($suffix === '' && displayWidth($head) + 2 + firstLineWidth($body) <= WIDTH) {
            $alts[] = Doc::line();
            $alts[] = Doc::concat([Doc::text($head), $body]);
            continue;
        }

        $alts[] = Doc::line();
        $alts[] = Doc::text($pattern . ' ->' . $suffix);
        $alts[] = Doc::nest(2, Doc::concat([Doc::line(), $body]));
    }

    $head = containsBlock($case->scrutinee)
        ? Doc::concat([Doc::text('case ('), expressionDoc($case->scrutinee), Doc::text(') of')])
        : Doc::text('case ' . printExpression($case->scrutinee) . ' of');

    return Doc::concat([$head, Doc::nest(2, Doc::concat($alts))]);
}

/** An alternative's body in the broken form: a body's own layout, two columns deeper. */
function altBodyDoc(Ast\AstNode $body): Doc
{
    return brokenBodyDoc($body, 4);
}

/** `head = do` with one statement per line at two columns. */
function printDo(Doc $head, Ast\DoExpr $do): Doc
{
    return Doc::concat([$head, Doc::text(' = '), doExpressionDoc($do)]);
}

/** `do` as an expression: the statements sit two columns under it. */
function doExpressionDoc(Ast\DoExpr $do): Doc
{
    $body = [];
    foreach ($do->stmts as $stmt) {
        $limit = ($stmt->line ?? 0) > 0 ? (int) $stmt->line : Trivia::$boundary;
        foreach (commentLines(Trivia::takeBefore($limit, 1), 0) as $line) {
            $body[] = Doc::line();
            $body[] = Doc::text($line);
        }
        $trailing = Trivia::takeTrailing($stmt->line ?? 0);
        $suffix = $trailing === null ? '' : ' ' . $trailing['text'];

        if ($stmt instanceof Ast\DoLet && doLetHasBlock($stmt)) {
            foreach ($stmt->bindings as $binding) {
                $body[] = Doc::line();
                $body[] = Doc::text('let ' . printPattern($binding->pattern) . ' = ');
                $body[] = expressionDoc($binding->expr);
            }

            continue;
        }

        $body[] = Doc::line();
        $body[] = statementDoc($stmt, 2);
        $body[] = Doc::text($suffix);
    }

    return Doc::concat([Doc::text('do'), Doc::nest(2, Doc::concat($body))]);
}

function doLetHasBlock(Ast\DoLet $let): bool
{
    foreach ($let->bindings as $binding) {
        if (containsBlock($binding->expr)) {
            return true;
        }
    }

    return false;
}

function statementDoc(Ast\AstNode $stmt, int $indent): Doc
{
    if ($stmt instanceof Ast\DoBind) {
        $prefix = printPattern($stmt->pattern) . ' <- ';
        if (! containsBlock($stmt->expr)) {
            $inline = $prefix . measureInline($stmt->expr);
            if ($indent + displayWidth($inline) <= WIDTH) {
                return Doc::concat([Doc::text($prefix), expressionDoc($stmt->expr)]);
            }
        }

        return Doc::concat([
            Doc::text($prefix),
            Doc::nest(2, Doc::concat([Doc::line(), brokenBodyDoc($stmt->expr, $indent + 2)])),
        ]);
    }
    if ($stmt instanceof Ast\DoLet) {
        foreach ($stmt->bindings as $binding) {
            if (containsBlock($binding->expr)) {
                throw new FormatError('a `let` in a `do` block whose value is a block is not supported yet');
            }
        }
        $bindings = [];
        foreach ($stmt->bindings as $binding) {
            $bindings[] = printPattern($binding->pattern) . ' = ' . printExpression($binding->expr);
        }

        return Doc::text('let ' . \implode('; ', $bindings));
    }
    if ($stmt instanceof Ast\DoExprStmt) {
        if (! containsBlock($stmt->expr)) {
            $flat = measureInline($stmt->expr);
            if ($indent + displayWidth($flat) <= WIDTH) {
                return expressionDoc($stmt->expr);
            }
        }

        return brokenBodyDoc($stmt->expr, $indent);
    }

    throw new FormatError('the printer does not handle the statement ' . \get_class($stmt) . ' yet');
}

/**
 * Bindings of a `where` or `let`, one per line, each under the comments written above it.
 *
 * The comments have to be taken *before* the binding's body is printed: a body that starts with a
 * `case` or a `do` collects every comment before its first alternative, and a comment written above
 * the binding itself would be swallowed into the body — printed in one place, then somewhere else
 * on the next save.
 *
 * @param array<int, Ast\AstNode> $bindings
 */
function printBindings(array $bindings): Doc
{
    $docs = [];
    foreach ($bindings as $binding) {
        foreach (commentLines(Trivia::takeBefore($binding->line, $binding->col), 0) as $line) {
            $docs[] = Doc::text($line);
        }
        $docs[] = printBinding($binding);
    }

    return joinLines($docs);
}

function printBinding(Ast\AstNode $binding): Doc
{
    if (! $binding instanceof Ast\Binding) {
        throw new FormatError('the printer does not handle the binding ' . \get_class($binding) . ' yet');
    }

    $pattern = $binding->pattern;
    if ($pattern instanceof Ast\PatVar) {
        $value = $binding->value;
        if ($binding->functionBinding && $value instanceof Ast\TypeAsc && $value->expr instanceof Ast\Lambda
            && $value->expr->params !== []) {
            return joinLines([
                signature($pattern->name, $value->type),
                binding($pattern->name, $value->expr->params, $value->expr->body),
            ]);
        }
        if ($binding->functionBinding && $value instanceof Ast\Lambda && $value->params !== []) {
            return binding($pattern->name, $value->params, $value->body);
        }

        return binding($pattern->name, [], $value);
    }

    return binding(printParam($pattern), [], $binding->value);
}

/** A `data`/`newtype` declaration: inline while it fits, otherwise one constructor per line. */
function printData(Ast\DataDecl $data): Doc
{
    $params = [];
    foreach ($data->params as $param) {
        $params[] = $param->kind instanceof Ast\KindInfer
            ? $param->name
            : '(' . $param->name . ' :: ' . printKind($param->kind) . ')';
    }

    $head = ($data->isNewtype ? 'newtype ' : 'data ') . printedName($data->name)
        . ($params === [] ? '' : ' ' . \implode(' ', $params));

    $clauses = derivingClause($data);
    $named = $clauses === [] ? '' : ' ' . \implode(' ', $clauses);

    if ($data->constructors === []) {
        $parts = [Doc::text($head)];
        foreach ($clauses as $clause) {
            $parts[] = Doc::line();
            $parts[] = Doc::text('  ' . $clause);
        }

        return Doc::concat($parts);
    }

    $gadt = $data->constructors !== [];
    foreach ($data->constructors as $constructor) {
        if (! $constructor->isGadt()) {
            $gadt = false;

            break;
        }
    }
    if ($gadt) {
        return printGadtData($data, $head, $clauses);
    }

    $paramNames = [];
    foreach ($data->params as $param) {
        $paramNames[] = $param->name;
    }
    $constructors = [];
    foreach ($data->constructors as $constructor) {
        $constructors[] = printConstructor($constructor, $paramNames);
    }

    $flat = $head . ' = ' . \implode(' | ', $constructors);
    $singleRecord = \count($data->constructors) === 1 && $data->constructors[0]->isRecord();

    $lastConstructor = $data->constructors === [] ? 0 : $data->constructors[\array_key_last($data->constructors)]->line;
    if (Trivia::hasBefore($lastConstructor)) {
        $flat = '';
    }

    foreach ($data->constructors as $constructor) {
        if (\is_string($constructor->doc ?? null) && \trim($constructor->doc) !== '') {
            $flat = '';
        }
        foreach ($constructor->fields as $field) {
            if (($field->doc ?? null) !== null) {
                $flat = '';
            }
        }
    }

    if ($data->docIsTrailing) {
        $flat = '';
    }

    if (displayWidth($flat . $named) <= WIDTH && $flat !== '') {
        $doc = Doc::text($flat);
    } elseif ($singleRecord) {
        $parts = [Doc::text($head . ' = ' . $data->constructors[0]->name)];
        foreach ($data->constructors[0]->fields as $index => $field) {
            $marker = $index === 0 ? '  { ' : '  , ';
            $body = '    ' . $field->name . ' :: ' . printType($field->type);
            $docText = \is_string($field->doc ?? null) ? \trim($field->doc) : '';
            if ($docText === '') {
                $parts[] = Doc::line();
                $parts[] = Doc::text($marker . \ltrim($body));

                continue;
            }

            foreach (\explode("\n", $docText) as $line => $text) {
                $parts[] = Doc::line();
                $parts[] = Doc::text(\rtrim(($line === 0 ? $marker . '-- | ' : '    -- ') . $text));
            }
            $parts[] = Doc::line();
            $parts[] = Doc::text($body);
        }
        $parts[] = Doc::line();
        $parts[] = Doc::text('  }');
        $doc = Doc::concat($parts);
    } else {
        $ctorLines = [];
        $lastCtor = \count($constructors) - 1;
        foreach ($constructors as $index => $constructor) {
            $ctor = $data->constructors[$index];
            foreach (commentLines(Trivia::takeBefore($ctor->line, $ctor->col), 2) as $line) {
                $ctorLines[] = Doc::text($line);
            }
            $ctorDoc = $ctor->doc ?? null;
            $marker = $index === 0 ? '= ' : '| ';
            if (! \is_string($ctorDoc) || \trim($ctorDoc) === '') {
                $ctorLines[] = Doc::text($marker . $constructor);
            } else {
                foreach (\explode("\n", \trim($ctorDoc)) as $line => $text) {
                    $ctorLines[] = Doc::text(\rtrim(($line === 0 ? $marker . '-- | ' : '  -- ') . $text));
                }
                $ctorLines[] = Doc::text('  ' . $constructor);
            }

            $nextLine = $index < $lastCtor ? $data->constructors[$index + 1]->line : Trivia::$boundary;
            foreach (commentLines(Trivia::takeAfter((int) $nextLine), 2) as $line) {
                $ctorLines[] = Doc::text($line);
            }
        }
        $doc = Doc::concat([Doc::text($head), Doc::nest(2, Doc::concat([Doc::line(), joinLines($ctorLines)]))]);
    }

    if ($clauses === []) {
        return $doc;
    }

    $parts = [$doc];
    foreach ($clauses as $clause) {
        $parts[] = Doc::line();
        $parts[] = Doc::text('  ' . $clause);
    }

    return Doc::concat($parts);
}

/**
 * A data declaration's deriving clauses, one string per clause, in source order.
 *
 * The parser reads a strategy in exactly one place: between `deriving` and the parenthesis, never
 * inside it. Classes that share one are therefore grouped back into a single clause — `deriving
 * anyclass (ToJSON, FromJSON)` next to `deriving (Generic, Eq)` — and a group that is `newtype` on
 * a `newtype` declaration leaves the word out, because that is the parser's own default.
 *
 * @return list<string>
 */
function derivingClause(Ast\DataDecl $data): array
{
    $clauses = [];
    $current = null;
    $via = '';
    $names = [];
    $flush = static function () use (&$clauses, &$current, &$via, &$names): void {
        if ($current === null) {
            return;
        }
        $word = $current === null || $current === '' ? '' : $current . ' ';
        $clauses[] = 'deriving ' . $word . $via . '(' . \implode(', ', $names) . ')';
        $names = [];
        $via = '';
    };

    foreach ($data->derivingClasses as $ref) {
        $strategy = $ref->strategy ?? '';
        if ($strategy === 'via' && $ref->viaType === null) {
            throw new FormatError('a `via` deriving clause with no type is not supported');
        }
        $viaText = $strategy === 'via' ? 'via (' . printType($ref->viaType) . ') ' : '';
        if ($current !== null && ($strategy !== $current || $viaText !== $via)) {
            $flush();
        }
        $current = $strategy;
        $via = $viaText;
        $names[] = $ref->name;
    }
    $flush();

    return $clauses;
}

/**
 * A entry of a deriving clause, with the strategy that makes the choice explicit.
 *
 * `anyclass` and `via` are not decorations: dropping `anyclass` from `anyclass ToJSON` asks the
 * compiler to derive the class the ordinary way, which is a different instance — and for a class
 * that has no stock derivation, a compile error where the source compiled.
 */
function printedDeriving(Ast\DerivingClassRef $ref): string
{
    $prefix = match ($ref->strategy) {
        'stock' => 'stock ',
        'newtype' => 'newtype ',
        'anyclass' => 'anyclass ',
        'via' => $ref->viaType === null
            ? throw new FormatError('a `via` deriving clause with no type is not supported')
            : 'via ' . printType($ref->viaType) . ' ',
        default => '',
    };

    return $prefix . $ref->name;
}

/**
 * One constructor, on one line: a record stays inline while it fits.
 *
 * A field whose printed form is several tokens has to be parenthesized — `C (MVar a)` is one field
 * where `C MVar a` is two — while a list or tuple is already delimited. A symbolic constructor is
 * written between its fields (`a :| [a]`), because in front of them it does not parse at all.
 */
function printConstructor(Ast\ConstructorDecl $constructor, array $params = []): string
{
    if ($constructor->signature !== null) {
        return printExistentialConstructor($constructor, $params);
    }

    if ($constructor->isRecord()) {
        $fields = [];
        foreach ($constructor->fields as $field) {
            $fields[] = $field->name . ' :: ' . printType($field->type);
        }

        return $constructor->name . ' { ' . \implode(', ', $fields) . ' }';
    }

    $fields = [];
    foreach ($constructor->fields as $field) {
        $printed = printType($field->type);
        if (typeFieldNeedsParens($field->type, $printed)) {
            $printed = '(' . $printed . ')';
        }
        $fields[] = $printed;
    }

    if (isSymbolicName($constructor->name)) {
        if (\count($fields) !== 2) {
            throw new FormatError('an operator constructor with fewer than two fields is not supported yet');
        }

        return $fields[0] . ' ' . $constructor->name . ' ' . $fields[1];
    }

    return $constructor->name . ($fields === [] ? '' : ' ' . \implode(' ', $fields));
}

/**
 * A constructor of an ordinary declaration that carries a `forall`.
 *
 * `data MarkupM a = ... | forall b. Append (MarkupM b) (MarkupM a) | ...` is not a GADT: the
 * declaration still uses `=` and `|`, and a `where` block cannot be mixed with them. The parser
 * reads such a constructor's fields as the argument types of its signature, the last arrow being
 * the result, so printing the arrows before the tail as fields gives the source back. Printing them
 * as a signature would leave the declaration with a `forall` where the layout expects a name.
 */
function printExistentialConstructor(Ast\ConstructorDecl $constructor, array $params = []): string
{
    $signature = $constructor->signature;
    $forall = '';
    if ($signature instanceof Ast\TypeForall) {
        $binders = [];
        foreach ($signature->binders as $index => $binder) {
            if (\in_array($binder, $params, true)) {
                continue;
            }
            $binders[] = printedTypeBinder($binder, $signature->kinds[$index] ?? null);
        }
        if ($binders !== []) {
            $forall = 'forall ' . \implode(' ', $binders) . '. ';
        }
        $signature = $signature->body;
    }

    $fields = [];
    while ($signature instanceof Ast\TypeArrow) {
        $printed = printType($signature->from);
        $fields[] = typeFieldNeedsParens($signature->from, $printed) ? '(' . $printed . ')' : $printed;
        $signature = $signature->to;
    }

    return $forall . printedName($constructor->name) . ($fields === [] ? '' : ' ' . \implode(' ', $fields));
}

/** Whether a constructor field's type needs parentheses to stay one field. */
function typeFieldNeedsParens(Ast\TypeNode $type, string $printed): bool
{
    if ($type instanceof Ast\TypeArrow || $type instanceof Ast\TypeConstrained || $type instanceof Ast\TypeForall) {
        return true;
    }
    if (! $type instanceof Ast\TypeApp || $type->tupleSugar) {
        return false;
    }
    if ($type->con instanceof Ast\TypeCon && ($type->con->name === '[]' || $type->con->name === 'List')
        && \count($type->args) === 1) {
        return false;
    }

    return \str_contains($printed, ' ');
}

/**
 * Whether an instance head needs parentheses to stay one type argument.
 *
 * `instance Typeable ((a :: Type) :~: (b :: Type))` is a class applied to the infix type; printed
 * without the outer pair it reads as an infix application of `Typeable (a :: Type)`, which is a
 * different head — and a type operator the parser then finds where it wants a type.
 */
function needsParensAsInstanceHead(Ast\TypeNode $head): bool
{
    if ($head instanceof Ast\TypeArrow || $head instanceof Ast\TypeForall || $head instanceof Ast\TypeConstrained) {
        return true;
    }
    if (! $head instanceof Ast\TypeApp || $head->tupleSugar) {
        return false;
    }
    if ($head->con instanceof Ast\TypeCon && ($head->con->name === '[]' || $head->con->name === 'List')
        && \count($head->args) === 1) {
        return false;
    }

    return true;
}

/** `class C f where` with a signature line per method, and a default's body below it. */
function printClass(Ast\ClassDecl $class): Doc
{
    $head = 'class';
    if ($class->superclasses !== []) {
        $context = \array_map(contextEntry(...), $class->superclasses);
        $head .= ' ' . (\count($context) === 1 ? $context[0] : '(' . \implode(', ', $context) . ')') . ' =>';
    }
    $head .= ' ' . $class->name;
    foreach ($class->params as $param) {
        $head .= $param->kind instanceof Ast\KindInfer
            ? ' ' . $param->name
            : ' (' . $param->name . ' :: ' . printKind($param->kind) . ')';
    }
    $members = [];
    foreach ($class->associatedTypes as $associated) {
        $params = $associated->params === [] ? '' : ' ' . \implode(' ', $associated->params);
        $kind = $associated->resultKind === null ? '' : ' :: ' . printKind($associated->resultKind);
        $members[] = Doc::text('type ' . $associated->name . $params . $kind);
    }
    $typeMembers = \count($members);
    $methodEnds = [];

    foreach ($class->methods as $method) {
        if (! $method instanceof Ast\ClassMethodSig) {
            throw new FormatError('the printer does not handle a class member that is not a method');
        }
        if (\is_string($method->doc) && \trim($method->doc) !== '') {
            $members[] = docComment($method->doc);
        }
        $members[] = signature(printedName($method->name), $method->type);
        if ($method->body !== null) {
            $members[] = binding(
                printedName($method->name),
                $method->params,
                $method->body,
                $method->infixDefinition ? $method->name : null,
            );
        }
        $methodEnds[] = \count($members);
    }

    if ($class->minimalPragma !== null) {
        $after = $class->minimalPragmaAfter;
        $index = $after['methods'] > 0
            ? $methodEnds[\min($after['methods'], \count($methodEnds)) - 1]
            : \min($after['types'], $typeMembers);
        \array_splice($members, $index, 0, [Doc::text('{-# ' . $class->minimalPragma . ' #-}')]);
    }

    $parts = [Doc::text($head . ' where')];
    foreach ($members as $member) {
        $parts[] = Doc::line();
        $parts[] = indented(2, $member);
    }

    return Doc::concat($parts);
}

/**
 * One or more constraints as a context, in the parentheses the parser needs.
 *
 * A single constraint needs none: `FromJSON a => …` is the same context with or without a pair,
 * and the bare form is what was written. Every context entry that cannot stand bare — a `forall`,
 * a nested context, an arrow or an infix constraint — keeps its parentheses in `contextEntry()`.
 *
 * @param list<Ast\TypeNode> $constraints
 */
function printedContext(array $constraints): string
{
    if (\count($constraints) === 1) {
        return contextEntry($constraints[0]);
    }

    return '(' . \implode(', ', \array_map(printType(...), $constraints)) . ')';
}

/**
 * A constraint in a context.
 *
 * A context that is itself a `forall`, a nested context or an arrow has to keep its parentheses, or
 * the parser reads it as several constraints: `class (forall a. C p a) => D p` is one. An infix
 * constraint does too: `a ~~ a => …` does not parse, because `~~` only continues a type inside
 * parentheses. A class application needs none, so `FromJSON a => …` stays bare.
 */
function contextEntry(Ast\TypeNode $constraint): string
{
    $printed = printType($constraint);

    $parenthesized = $constraint instanceof Ast\TypeForall || $constraint instanceof Ast\TypeConstrained
        || $constraint instanceof Ast\TypeArrow
        || ($constraint instanceof Ast\TypeApp && $constraint->con instanceof Ast\TypeCon
            && isSymbolicName($constraint->con->name));

    return $parenthesized ? '(' . $printed . ')' : $printed;
}

/** `instance C (T a) where`, its context inline. */
function printInstance(Ast\InstanceDecl $instance): Doc
{
    $head = 'instance';
    if ($instance->constraints !== []) {
        $context = \array_map(contextEntry(...), $instance->constraints);
        $head .= ' ' . (\count($context) === 1 ? $context[0] : '(' . \implode(', ', $context) . ')') . ' =>';
    }

    $argument = printType($instance->head);
    $bare = \str_starts_with($argument, '__InstanceHead ');
    if ($bare) {
        $argument = \substr($argument, \strlen('__InstanceHead '));
    }
    if (! $bare && needsParensAsInstanceHead($instance->head)) {
        $argument = '(' . $argument . ')';
    }

    $parts = [Doc::text($head . ' ' . $instance->class . ' ' . $argument . ' where')];

    foreach ($instance->associatedEquations as $equation) {
        $args = [];
        foreach ($equation->lhsArgs as $argument) {
            $printed = printType($argument);
            $args[] = \str_contains($printed, ' ') && ! \str_starts_with($printed, '(') && ! \str_starts_with($printed, '[')
                ? '(' . $printed . ')'
                : $printed;
        }
        $parts[] = Doc::line();
        $parts[] = indented(2, Doc::text(
            'type ' . $equation->name . ($args === [] ? '' : ' ' . \implode(' ', $args)) . ' = ' . printType($equation->rhs),
        ));
    }

    foreach ($instance->methods as $method) {
        $member = printFunction($method);
        if (\is_string($method->doc) && \trim($method->doc) !== '') {
            $member = joinLines([docComment($method->doc), $member]);
        }
        $parts[] = Doc::line();
        $parts[] = indented(2, $member);
    }

    return Doc::concat($parts);
}

/**
 * `deriving instance Eq a => Eq (Foo a)`, with its strategy in front of the class.
 *
 * The head follows an `instance`'s rules, because the parser reads the two the same way: one
 * constraint needs no parentheses, two must share one pair, and a head printed as several tokens
 * needs its own.
 */
function printStandaloneDeriving(Ast\StandaloneDerivingDecl $decl): Doc
{
    $head = 'deriving instance';
    if ($decl->constraints !== []) {
        $context = \array_map(contextEntry(...), $decl->constraints);
        $head .= ' ' . (\count($context) === 1 ? $context[0] : '(' . \implode(', ', $context) . ')') . ' =>';
    }

    $strategy = match ($decl->strategy) {
        'stock' => 'stock ',
        'newtype' => 'newtype ',
        'anyclass' => 'anyclass ',
        'via' => $decl->viaType === null
            ? throw new FormatError('a `via` deriving clause with no type is not supported')
            : 'via ' . printType($decl->viaType) . ' ',
        default => '',
    };

    $argument = printType($decl->head);
    if (\str_contains($argument, ' ') && ! \str_starts_with($argument, '(') && ! \str_starts_with($argument, '[')) {
        $argument = '(' . $argument . ')';
    }

    return Doc::text($head . ' ' . $strategy . $decl->className . ' ' . $argument);
}

function printPatternSynonym(Ast\PatternSynonymDecl $synonym): Doc
{
    if ($synonym->signature !== null) {
        return Doc::text('pattern ' . $synonym->name . ' :: ' . printType($synonym->signature));
    }

    if ($synonym->matcher === null) {
        throw new FormatError('a pattern synonym with no matcher is not supported');
    }

    $params = $synonym->params === [] ? '' : ' ' . \implode(' ', $synonym->params);
    $arrow = $synonym->bidirectional ? ' = ' : ' <- ';
    $doc = Doc::text('pattern ' . $synonym->name . $params . $arrow . printPattern($synonym->matcher));

    if ($synonym->builder !== null) {
        $builder = $synonym->builder;
        $args = [];
        $body = $builder;
        if ($builder instanceof Ast\Lambda) {
            foreach ($builder->params as $param) {
                $args[] = printPattern($param->pattern ?? $param);
            }
            $body = $builder->body;
        }
        $doc = Doc::concat([
            $doc,
            Doc::line(),
            Doc::text('  where'),
            Doc::nest(4, Doc::concat([
                Doc::line(),
                Doc::text($synonym->name . ($args === [] ? '' : ' ' . \implode(' ', $args)) . ' = ' . printExpression($body)),
            ])),
        ]);
    }

    return $doc;
}

function printType(Ast\TypeNode $type): string
{
    $inline = Trivia::takeInline($type->line, $type->col);
    $printed = printTypeNode($type);

    return $inline === null ? $printed : $inline['text'] . ' ' . $printed;
}

function printTypeNode(Ast\TypeNode $type): string
{
    if ($type instanceof Ast\TypeCon) {
        return printedName($type->name);
    }
    if ($type instanceof Ast\TypeVar) {
        return $type->name;
    }
    if ($type instanceof Ast\TypeUnit) {
        return '()';
    }
    if ($type instanceof Ast\TypeQualified) {
        return $type->module . '.' . $type->name;
    }
    if ($type instanceof Ast\TypeArrow) {
        $from = printType($type->from);
        if ($type->from instanceof Ast\TypeArrow || $type->from instanceof Ast\TypeForall
            || $type->from instanceof Ast\TypeConstrained) {
            $from = '(' . $from . ')';
        }

        return $from . ' -> ' . printType($type->to);
    }
    if ($type instanceof Ast\TypeApp) {
        if ($type->tupleSugar) {
            return '(' . \implode(', ', \array_map(printType(...), $type->args)) . ')';
        }
        if ($type->con instanceof Ast\TypeCon && ($type->con->name === '[]' || $type->con->name === 'List')
            && \count($type->args) === 1) {
            return '[' . printType($type->args[0]) . ']';
        }
        if ($type->con instanceof Ast\TypeCon && isSymbolicName($type->con->name)) {
            if (\count($type->args) === 2) {
                return printTypeOperand($type->args[0]) . ' ' . $type->con->name . ' ' . printTypeOperand($type->args[1]);
            }
            if (\count($type->args) > 2) {
                $head = '(' . printTypeOperand($type->args[0]) . ' ' . $type->con->name . ' '
                    . printTypeOperand($type->args[1]) . ')';
                foreach (\array_slice($type->args, 2) as $argument) {
                    $head .= ' ' . printTypeArgument($argument);
                }

                return $head;
            }

            $head = '(' . $type->con->name . ')';
            foreach ($type->args as $argument) {
                $head .= ' ' . printType($argument);
            }

            return $head;
        }
        $args = [];
        foreach ($type->args as $argument) {
            $args[] = printTypeArgument($argument);
        }
        $head = printType($type->con);

        return $args === [] ? $head : $head . ' ' . \implode(' ', $args);
    }
    if ($type instanceof Ast\TypeConstrained) {
        return printedContext($type->constraints) . ' => ' . printType($type->body);
    }
    if ($type instanceof Ast\TypeForall) {
        return 'forall ' . printedTypeBinders($type) . '. ' . printType($type->body);
    }
    if ($type instanceof Ast\TypeStringLit) {
        return literalString($type->value);
    }
    if ($type instanceof Ast\TypeNatLit) {
        return $type->digits;
    }
    if ($type instanceof Ast\TypePromoted) {
        return "'" . $type->name;
    }
    if ($type instanceof Ast\TypeKindAnnot) {
        return '(' . $type->name . ' :: ' . printKind($type->kind) . ')';
    }

    throw new FormatError('the printer does not handle the type ' . \get_class($type) . ' yet');
}

/**
 * A `forall` binder as source: a kind annotation keeps its parentheses.
 *
 * `forall (a :: Type) (b :: Type). a -> b` is a different type from `forall a b. a -> b` to the
 * pairwise checker, and dropping the annotation printed the second where the source wrote the
 * first.
 */
function printedTypeBinder(string $binder, ?Ast\KindNode $kind): string
{
    return $kind === null ? $binder : '(' . $binder . ' :: ' . printKind($kind) . ')';
}

/** The binders of a `forall`, space-separated, kinds included. */
function printedTypeBinders(Ast\TypeForall $forall): string
{
    $printed = [];
    foreach ($forall->binders as $index => $binder) {
        $printed[] = printedTypeBinder($binder, $forall->kinds[$index] ?? null);
    }

    return \implode(' ', $printed);
}

/** A kind as source: `Type`, `Color`, `Type -> Type -> Type`. */
function printKind(Ast\KindNode $kind): string
{
    if ($kind instanceof Ast\KindCon) {
        return $kind->name;
    }
    if ($kind instanceof Ast\KindType) {
        return 'Type';
    }
    if ($kind instanceof Ast\KindArrow) {
        $from = printKind($kind->from);

        return ($kind->from instanceof Ast\KindArrow ? '(' . $from . ')' : $from) . ' -> ' . printKind($kind->to);
    }

    throw new FormatError('the printer does not handle the kind ' . \get_class($kind) . ' yet');
}

/**
 * An argument of a type application, in the parentheses it needs.
 *
 * Type application binds tighter than every arrow and constraint, so `Maybe (Either a b)` and
 * `Maybe (a -> b)` printed bare re-associate into types nobody wrote.
 */
function printTypeArgument(Ast\TypeNode $type): string
{
    $printed = printType($type);

    if ($type instanceof Ast\TypeArrow || $type instanceof Ast\TypeForall || $type instanceof Ast\TypeConstrained) {
        return '(' . $printed . ')';
    }
    if ($type instanceof Ast\TypeApp) {
        if ($type->tupleSugar) {
            return $printed;
        }
        if ($type->con instanceof Ast\TypeCon && ($type->con->name === '[]' || $type->con->name === 'List')
            && \count($type->args) === 1) {
            return $printed;
        }

        return '(' . $printed . ')';
    }

    return $printed;
}

/** An operand of an infix type application: another infix keeps its parentheses. */
function printTypeOperand(Ast\TypeNode $type): string
{
    $printed = printType($type);

    return $type instanceof Ast\TypeApp && $type->con instanceof Ast\TypeCon
        && isSymbolicName($type->con->name)
        ? '(' . $printed . ')'
        : $printed;
}

function indented(int $columns, Doc $doc): Doc
{
    return Doc::concat([Doc::text(\str_repeat(' ', $columns)), Doc::nest($columns, $doc)]);
}

/**
 * Why a qualified name comes back between backticks.
 *
 * `I.eqTypeRep` on its own reads as a field access on the constructor `I`, so the parser only
 * builds a qualified reference for the infix spelling `a `I.eqTypeRep` b` — and that spelling
 * arrives as an application of the reference to its two operands. Printing it back as an
 * application would turn the operator into a field read; the backticks are the only form that
 * parses to the same tree.
 */

/** A binding's parameter: a constructor with arguments keeps its parentheses. */
function printParam(Ast\AstNode $pattern): string
{
    $inner = $pattern instanceof Ast\LambdaParam && $pattern->pattern !== null ? $pattern->pattern : $pattern;
    $printed = printPattern($inner);

    $needsParens = ($inner instanceof Ast\PatCon && $inner->args !== []) || $inner instanceof Ast\PatCons;

    return $needsParens ? '(' . $printed . ')' : $printed;
}

function printPattern(Ast\AstNode $pattern): string
{
    if ($pattern instanceof Ast\LambdaParam && $pattern->pattern !== null) {
        $pattern = $pattern->pattern;
    }

    if ($pattern instanceof Ast\PatVar) {
        return $pattern->name;
    }
    if ($pattern instanceof Ast\PatWild) {
        return '_';
    }
    if ($pattern instanceof Ast\PatLit) {
        if (\is_string($pattern->value)) {
            return literalString($pattern->value);
        }

        return $pattern->digits ?? (string) $pattern->value;
    }
    if ($pattern instanceof Ast\PatChar) {
        return literalChar($pattern->value);
    }
    if ($pattern instanceof Ast\PatNil) {
        return '[]';
    }
    if ($pattern instanceof Ast\PatCons) {
        $head = printPattern($pattern->head);
        if ($pattern->head instanceof Ast\PatCons || $pattern->head instanceof Ast\PatView) {
            $head = '(' . $head . ')';
        }

        return $head . ' : ' . printPattern($pattern->tail);
    }
    if ($pattern instanceof Ast\PatCon) {
        if (isSymbolicName($pattern->name) && \count($pattern->args) === 2) {
            return patternOperand($pattern->args[0]) . ' ' . $pattern->name . ' ' . patternOperand($pattern->args[1]);
        }

        $args = [];
        foreach ($pattern->args as $argument) {
            $args[] = patternArgument($argument);
        }

        return $pattern->name . ($args === [] ? '' : ' ' . \implode(' ', $args));
    }
    if ($pattern instanceof Ast\PatTuple) {
        return '(' . \implode(', ', \array_map(printPattern(...), $pattern->elements)) . ')';
    }
    if ($pattern instanceof Ast\PatRecord) {
        $fields = [];
        foreach ($pattern->fields as $field) {
            $fields[] = $field->name . ' = ' . printPattern($field->pattern);
        }

        return $pattern->name . ' { ' . \implode(', ', $fields) . ' }';
    }
    if ($pattern instanceof Ast\PatView) {
        return '(' . printExpression($pattern->expression) . ' -> ' . printPattern($pattern->pattern) . ')';
    }

    throw new FormatError('the printer does not handle the pattern ' . \get_class($pattern) . ' yet');
}

/**
 * An infix operator as source: a named one takes backticks, a symbolic one does not.
 *
 * Printing `` `compare` `` without them produced `v1.versionBranch compare v2.versionBranch`, an
 * application of `compare` to one argument — the text re-parsed as something else entirely.
 */
function infixOperator(string $operator): string
{
    return isSymbolicName($operator) ? $operator : '`' . $operator . '`';
}

/**
 * Whether a name is an operator rather than an identifier or a qualified name.
 *
 * Operators carry no letters, digits or underscores; a qualified constructor (`Type.Reflection.Fun`)
 * does, and is a name like any other.
 */
function isSymbolicName(string $name): bool
{
    return \preg_match('/[A-Za-z0-9_]/', $name) !== 1;
}

/**
 * One argument of a constructor pattern, parenthesized where a bare one would re-associate.
 *
 * `Left (ErrorCall message)` is a constructor applied to a constructor pattern; printed bare it is
 * `Left ErrorCall message`, which the parser reads back as `Left` applied to two atoms — a
 * different pattern, and on a `case` alternative a different program.
 */
function patternArgument(Ast\AstNode $pattern): string
{
    $printed = printPattern($pattern);

    return isAtomicPattern($pattern) ? $printed : '(' . $printed . ')';
}

/** Whether a pattern is one atom, so it can stand as an argument unchanged. */
function isAtomicPattern(Ast\AstNode $pattern): bool
{
    return $pattern instanceof Ast\PatVar || $pattern instanceof Ast\PatWild || $pattern instanceof Ast\PatLit
        || $pattern instanceof Ast\PatChar || $pattern instanceof Ast\PatNil || $pattern instanceof Ast\PatTuple
        || $pattern instanceof Ast\PatRecord || ($pattern instanceof Ast\PatCon && $pattern->args === []);
}

/**
 * An operand of an infix constructor pattern: a nested infix keeps its parentheses.
 */
function patternOperand(Ast\AstNode $pattern): string
{
    $printed = printPattern($pattern);

    return isAtomicPattern($pattern) ? $printed : '(' . $printed . ')';
}

/**
 * Whether printing `$value` needs a block on a line of its own.
 *
 * `case` and `do` are the two: wherever one appears, the single-line printer has no way to say it,
 * so the caller has to build a document instead of asking for a string. The walk visits the same
 * children the checker does, so sugar that repeats a subtree (`Apply::$enumForm`) is not counted.
 */
function containsBlock(mixed $value): bool
{
    /** @var null|\WeakMap<object, bool> $cache */
    static $cache = null;
    $cache ??= new \WeakMap();

    if (\is_array($value)) {
        foreach ($value as $item) {
            if (containsBlock($item)) {
                return true;
            }
        }

        return false;
    }
    if (! $value instanceof Ast\AstWalkable) {
        return false;
    }
    if (isset($cache[$value])) {
        return $cache[$value];
    }

    $result = $value instanceof Ast\CaseExpr || $value instanceof Ast\DoExpr || $value instanceof Ast\Where
        || ($value instanceof Ast\Let && ! isSingleVariableBinding($value));
    if (! $result) {
        foreach ($value->childValues() as $child) {
            if (containsBlock($child)) {
                $result = true;

                break;
            }
        }
    }
    $cache[$value] = $result;

    return $result;
}

/**
 * The expression as a document, blocks included.
 *
 * An expression with no block prints exactly as `printExpression` always printed it; only one that
 * carries a `case` or `do` is laid out by `expressionDocNode`, so the formatting of every other file
 * is unchanged.
 */
function expressionDoc(Ast\AstNode $expression): Doc
{
    $inline = Trivia::takeInline($expression->line, $expression->col);
    $doc = containsBlock($expression) ? expressionDocNode($expression) : inlineDoc($expression, true);

    return $inline === null ? $doc : Doc::concat([Doc::text($inline['text'] . ' '), $doc]);
}

function expressionDocNode(Ast\AstNode $expression): Doc
{
    $comp = $expression->listComp();
    if ($comp !== null) {
        return listCompDoc($comp);
    }
    if ($expression instanceof Ast\CaseExpr) {
        return $expression->ifParts === null
            ? caseExpressionDoc($expression)
            : ifExpression($expression);
    }
    if ($expression instanceof Ast\DoExpr) {
        return doExpressionDoc($expression);
    }
    if ($expression instanceof Ast\Apply && $expression->enumForm === null) {
        return applyDoc($expression);
    }
    if ($expression instanceof Ast\Lambda && ! $expression->section) {
        $params = [];
        foreach ($expression->params as $param) {
            $params[] = printParam($param->pattern ?? $param);
        }
        $head = '\\' . \implode(' ', $params) . ' ->';
        if (containsBlock($expression->body)) {
            return Doc::concat([
                Doc::text($head),
                Doc::nest(2, Doc::concat([Doc::line(), expressionDoc($expression->body)])),
            ]);
        }

        return Doc::concat([Doc::text($head . ' '), expressionDoc($expression->body)]);
    }
    if ($expression instanceof Ast\Let) {
        return letDoc($expression);
    }
    if ($expression instanceof Ast\Where) {
        return whereExpressionDoc($expression);
    }
    if ($expression instanceof Ast\Infix) {
        return Doc::concat([
            infixOperandDoc($expression->left, $expression->operator, true),
            Doc::text(' ' . infixOperator($expression->operator) . ' '),
            infixOperandDoc($expression->right, $expression->operator, false),
        ]);
    }
    if ($expression instanceof Ast\Tuple || $expression instanceof Ast\ListLit) {
        $close = $expression instanceof Ast\Tuple ? ')' : ']';
        $open = $expression instanceof Ast\Tuple ? '(' : '[';
        if (containsBlock($expression) || displayWidth(measureInline($expression)) > WIDTH) {
            return brokenSequenceDoc($open, ',', $close, $expression->elements, 0);
        }

        return sequenceDoc($open, ', ', $close, $expression->elements);
    }
    if ($expression instanceof Ast\RecordCon) {
        $fields = [];
        foreach ($expression->fields as $index => $field) {
            $fields[] = Doc::text(($index === 0 ? '' : ', ') . $field->name . ' = ');
            $fields[] = expressionDoc($field->expr);
        }

        return Doc::concat([Doc::text($expression->name . ' { '), ...$fields, Doc::text(' }')]);
    }
    if ($expression instanceof Ast\FieldAccess) {
        return Doc::concat([expressionDoc($expression->object), Doc::text('.' . $expression->field)]);
    }
    if ($expression instanceof Ast\RecordUpdate) {
        $object = printExpression($expression->object);
        $fields = [];
        foreach ($expression->fields as $index => $field) {
            $fields[] = Doc::text(($index === 0 ? '' : ', ') . $field->name . ' = ');
            $fields[] = expressionDoc($field->expr);
        }

        return Doc::concat([Doc::text(($expression->object instanceof Ast\Variable ? $object : '(' . $object . ')') . ' { '), ...$fields, Doc::text(' }')]);
    }
    if ($expression instanceof Ast\TypeAsc) {
        $inner = $expression->expr;
        $printedType = ' :: ' . printType($expression->type);
        if (isAtomicExpression($inner)) {
            return Doc::concat([expressionDoc($inner), Doc::text($printedType)]);
        }

        return Doc::concat([Doc::text('('), expressionDoc($inner), Doc::text(')' . $printedType)]);
    }

    return inlineDoc($expression, true);
}

/** @param list<Ast\AstNode> $items */
function sequenceDoc(string $open, string $separator, string $close, array $items): Doc
{
    $parts = [Doc::text($open)];
    foreach ($items as $index => $item) {
        if ($index !== 0) {
            $parts[] = Doc::text($separator);
        }
        $parts[] = expressionDoc($item);
    }
    $parts[] = Doc::text($close);

    return Doc::concat($parts);
}

/**
 * A list comprehension on one line: `[e | p <- xs, guard]`.
 *
 * The parts are the ones the parser kept on the desugared node, so a comprehension comes back as a
 * comprehension instead of the `concatMap` tree it means.
 *
 * @param array{expr: Ast\AstNode, qualifiers: list<Ast\AstNode>} $comp
 */
function listCompInlineDoc(array $comp, bool $comments): Doc
{
    $parts = [Doc::text('['), comprehensionPartDoc($comp['expr'], $comments)];
    foreach ($comp['qualifiers'] as $index => $qualifier) {
        $prefix = $index === 0 ? ' | ' : ', ';
        if ($qualifier instanceof Ast\QualGen) {
            $prefix .= printPattern($qualifier->pattern) . ' <- ';
        }
        $parts[] = Doc::text($prefix);
        $parts[] = comprehensionPartDoc($qualifier->expr, $comments);
    }
    $parts[] = Doc::text(']');

    return Doc::concat($parts);
}

/** One element or qualifier expression, keeping a block's own layout. */
function comprehensionPartDoc(Ast\AstNode $expression, bool $comments): Doc
{
    return $comments && containsBlock($expression)
        ? expressionDoc($expression)
        : inlineChildDoc($expression, $comments);
}

/**
 * A body that is a comprehension: one line when it fits, otherwise the parts one per line.
 *
 * The qualifier list is what breaks, not the desugared `concatMap` application, so a wide
 * comprehension still comes back as a comprehension.
 *
 * @param array{expr: Ast\AstNode, qualifiers: list<Ast\AstNode>} $comp
 */
function listCompBindingDoc(Doc $head, array $comp): Doc
{
    if (! $head->hasHardLine && ! comprehensionHeaderComments($comp)) {
        $inline = renderFlat($head) . ' = ' . renderFlat(listCompInlineDoc($comp, false));
        if (displayWidth($inline) <= WIDTH) {
            return Doc::text($inline);
        }
    }

    return Doc::concat([
        $head,
        Doc::text(' ='),
        Doc::nest(2, Doc::concat([
            Doc::line(),
            comprehensionDoc($comp, 2),
        ])),
    ]);
}

/**
 * The same comprehension for the document printer, whose qualifiers may carry a block.
 *
 * @param array{expr: Ast\AstNode, qualifiers: list<Ast\AstNode>} $comp
 */
function listCompDoc(array $comp): Doc
{
    if (comprehensionHeaderComments($comp)) {
        return brokenListCompDoc($comp);
    }

    return listCompInlineDoc($comp, true);
}

/** The broken or inline form a comprehension needs at this position. */
function comprehensionDoc(array $comp, int $indent): Doc
{
    if (comprehensionHeaderComments($comp) || ! containsBlock($comp)) {
        return brokenListCompDoc($comp);
    }

    return listCompDoc($comp);
}

/**
 * A comprehension laid out over several lines: the element, then one qualifier per line under the
 * leading `|`/`,`, and the closing bracket back at the opening column.
 *
 * This is the shape a wide bracketed literal already takes. Without it a wide comprehension had no
 * break opportunity at all: it was printed as one line however long the qualifier list grew, while
 * the docblock promised the list was what breaks.
 *
 * @param array{expr: Ast\AstNode, qualifiers: list<Ast\AstNode>} $comp
 */
function brokenListCompDoc(array $comp): Doc
{
    $element = $comp['expr'];
    $before = Trivia::takeBefore($element->line, $element->col);
    $firstQualifier = $comp['qualifiers'][0] ?? null;
    $elementEnd = $firstQualifier !== null
        ? comprehensionTrailingLimit($element->line, $firstQualifier)
        : PHP_INT_MAX;
    $trailing = Trivia::takeTrailingBefore($element->line, $elementEnd);

    $parts = [];
    if ($before === []) {
        $parts[] = Doc::text('[ ');
    } else {
        $parts[] = Doc::text('[ ' . $before[0]['text']);
        for ($commentIndex = 1, $commentCount = \count($before); $commentIndex < $commentCount; ++$commentIndex) {
            $parts[] = Doc::line();
            $parts[] = Doc::text('  ' . $before[$commentIndex]['text']);
        }
        $parts[] = Doc::line();
        $parts[] = Doc::text('  ');
    }
    $parts[] = expressionDoc($element);
    $parts[] = trailingDoc($trailing, containsBlock($element));

    foreach ($comp['qualifiers'] as $index => $qualifier) {
        [$beforeLine, $beforeCol] = comprehensionQualifierStart($qualifier);
        $before = Trivia::takeBefore($beforeLine, $beforeCol);
        $next = $comp['qualifiers'][$index + 1] ?? null;
        $line = comprehensionQualifierLine($qualifier);
        $limit = $next === null ? PHP_INT_MAX : comprehensionTrailingLimit($line, $next);
        $trailing = Trivia::takeTrailingBefore($line, $limit);
        $lead = $index === 0 ? '| ' : ', ';
        $prefix = $qualifier instanceof Ast\QualGen ? printPattern($qualifier->pattern) . ' <- ' : '';

        $parts[] = Doc::line();
        if ($before === []) {
            $parts[] = Doc::text($lead . $prefix);
        } else {
            $parts[] = Doc::text($lead . $before[0]['text']);
            for ($commentIndex = 1, $commentCount = \count($before); $commentIndex < $commentCount; ++$commentIndex) {
                $parts[] = Doc::line();
                $parts[] = Doc::text('  ' . $before[$commentIndex]['text']);
            }
            $parts[] = Doc::line();
            $parts[] = Doc::text('  ' . $prefix);
        }
        $parts[] = expressionDoc($qualifier->expr);
        $parts[] = trailingDoc($trailing, containsBlock($qualifier->expr));
    }

    $parts[] = Doc::line();
    $parts[] = Doc::text(']');

    return Doc::concat($parts);
}

/**
 * Whether a comment belongs on the element or a qualifier line of a comprehension.
 *
 * It looks without taking: the width decision has to be made before the broken form starts
 * consuming, or the comment would be gone by the time the inline form was chosen.
 *
 * @param array{expr: Ast\AstNode, qualifiers: list<Ast\AstNode>} $comp
 */
function comprehensionHeaderComments(array $comp): bool
{
    $lines = [$comp['expr']->line];
    foreach ($comp['qualifiers'] as $qualifier) {
        [$startLine] = comprehensionQualifierStart($qualifier);
        $lines[] = $startLine;
        $lines[] = comprehensionQualifierLine($qualifier);
    }

    foreach ($lines as $line) {
        if (Trivia::hasCommentOnLine($line)) {
            return true;
        }
    }

    return false;
}

/**
 * Where a qualifier's written form starts, and the line its expression starts on.
 *
 * A qualifier node itself is synthesized without a position; its pattern and its expression still
 * carry the source's own, and those are what a comment sitting before or after the qualifier is
 * placed against.
 *
 * @return array{int, int}
 */
function comprehensionQualifierStart(Ast\AstNode $qualifier): array
{
    if ($qualifier instanceof Ast\QualGen) {
        return [$qualifier->pattern->line, $qualifier->pattern->col];
    }
    if ($qualifier instanceof Ast\QualGuard) {
        return [$qualifier->expr->line, $qualifier->expr->col];
    }

    return [$qualifier->line, $qualifier->col];
}

function comprehensionTrailingLimit(int $line, Ast\AstNode $next): int
{
    [$nextLine, $nextCol] = comprehensionQualifierStart($next);

    return $nextLine === $line ? $nextCol : PHP_INT_MAX;
}

function comprehensionQualifierLine(Ast\AstNode $qualifier): int
{
    if ($qualifier instanceof Ast\QualGen) {
        return $qualifier->expr->line;
    }
    if ($qualifier instanceof Ast\QualGuard) {
        return $qualifier->expr->line;
    }

    return $qualifier->line;
}

/** @param null|array{text: string, line: int, col: int, endLine: int, after: bool} $trailing */
function trailingDoc(?array $trailing, bool $block): Doc
{
    if ($trailing === null) {
        return Doc::empty();
    }

    return $block
        ? Doc::concat([Doc::line(), Doc::text('  ' . $trailing['text'])])
        : Doc::text(' ' . $trailing['text']);
}

/** An application whose argument or function carries a block. */
function applyDoc(Ast\Apply $apply): Doc
{
    [$head, $args] = splitApply($apply);

    $parts = [];
    if (containsBlock($head) || ! isAtomicExpression($head)) {
        $parts[] = Doc::text('(');
        $parts[] = expressionDoc($head);
        $parts[] = Doc::text(')');
    } else {
        $parts[] = expressionDoc($head);
    }
    foreach ($args as $argument) {
        $parts[] = Doc::text(' ');
        $parts[] = argumentDoc($argument);
    }

    return Doc::concat($parts);
}

/** An argument in an application built as a document: a block needs its parentheses. */
function argumentDoc(Ast\AstNode $argument): Doc
{
    if (! containsBlock($argument)) {
        return Doc::text(printArgument($argument));
    }

    return Doc::concat([Doc::text('('), expressionDoc($argument), Doc::text(')')]);
}

function printExpression(Ast\AstNode $expression): string
{
    return renderFlat(inlineExpressionDoc($expression));
}

/** One expression's inline document, with the comments that belong to it and its children. */
function inlineExpressionDoc(Ast\AstNode $expression): Doc
{
    $inline = Trivia::takeInline($expression->line, $expression->col);
    $doc = inlineDoc($expression, true);

    return $inline === null ? $doc : Doc::concat([Doc::text($inline['text'] . ' '), $doc]);
}

/** The text an expression would occupy with no comments taken and no width considered. */
function measureInline(Ast\AstNode $expression): string
{
    /** @var null|\WeakMap<object, string> $cache */
    static $cache = null;
    $cache ??= new \WeakMap();
    if (isset($cache[$expression])) {
        return $cache[$expression];
    }

    $text = renderFlat(inlineDoc($expression, false));
    $cache[$expression] = $text;

    return $text;
}

/** A child of an inline document: comments included when the caller wants them consumed. */
function inlineChildDoc(Ast\AstNode $expression, bool $comments): Doc
{
    return $comments ? inlineExpressionDoc($expression) : inlineDoc($expression, false);
}

/**
 * The single-line shape of an expression.
 *
 * This is the one place the token layout is written: `printExpression` flattens this document, and
 * the wide layouts reuse it for every part that still fits. `$comments` says whether the children's
 * own inline comments are taken; a width measurement passes false, so asking how wide a node would
 * be cannot consume a comment that the real print still has to place.
 */
function inlineDoc(Ast\AstNode $expression, bool $comments = true): Doc
{
    $comp = $expression->listComp();
    if ($comp !== null) {
        return listCompInlineDoc($comp, $comments);
    }
    if ($expression instanceof Ast\IntegerLit) {
        return Doc::text($expression->digits ?? (string) $expression->value);
    }
    if ($expression instanceof Ast\Variable) {
        return Doc::text($expression->name);
    }
    if ($expression instanceof Ast\ConstructorRef) {
        return Doc::text($expression->name);
    }
    if ($expression instanceof Ast\OperatorRef) {
        return Doc::text('(' . $expression->name . ')');
    }
    if ($expression instanceof Ast\StringLit) {
        return Doc::text(literalString($expression->value));
    }
    if ($expression instanceof Ast\CharLit) {
        return Doc::text(literalChar($expression->value));
    }
    if ($expression instanceof Ast\DoubleLit) {
        return Doc::text($expression->lexeme ?? \number_format($expression->value, 1, '.', ''));
    }
    if ($expression instanceof Ast\ExprHole) {
        return Doc::text('_');
    }
    if ($expression instanceof Ast\QualifiedRef) {
        return Doc::text($expression->module . '.' . $expression->name);
    }
    if ($expression instanceof Ast\Apply && $expression->enumForm !== null) {
        $form = $expression->enumForm;
        $parts = [Doc::text('['), inlineChildDoc($form['from'], $comments)];
        if ($form['kind'] === 'then-to') {
            $parts[] = Doc::text(', ');
            $parts[] = inlineChildDoc($form['then'], $comments);
        }
        $parts[] = Doc::text(' .. ');
        $parts[] = inlineChildDoc($form['to'], $comments);
        $parts[] = Doc::text(']');

        return Doc::concat($parts);
    }
    if ($expression instanceof Ast\Apply) {
        [$head, $args] = splitApply($expression);
        if ($head instanceof Ast\QualifiedRef && ! isConstructorName($head->name) && \count($args) >= 2) {
            $parts = [argumentInlineDoc(\array_shift($args), $comments), Doc::text(' `' . $head->module . '.' . $head->name . '`')];
            foreach ($args as $argument) {
                $parts[] = Doc::text(' ');
                $parts[] = argumentInlineDoc($argument, $comments);
            }

            return Doc::concat($parts);
        }
        $headDoc = inlineChildDoc($head, $comments);
        if (! isAtomicExpression($head)) {
            $headDoc = Doc::concat([Doc::text('('), $headDoc, Doc::text(')')]);
        }
        $parts = [$headDoc];
        foreach ($args as $argument) {
            $parts[] = Doc::text(' ');
            $parts[] = argumentInlineDoc($argument, $comments);
        }

        return Doc::concat($parts);
    }
    if ($expression instanceof Ast\Infix) {
        return Doc::concat([
            inlineOperandDoc($expression->left, $expression->operator, true, $comments),
            Doc::text(' ' . infixOperator($expression->operator) . ' '),
            inlineOperandDoc($expression->right, $expression->operator, false, $comments),
        ]);
    }
    if ($expression instanceof Ast\Tuple) {
        return inlineSequenceDoc('(', ', ', ')', $expression->elements, $comments);
    }
    if ($expression instanceof Ast\ListLit) {
        return inlineSequenceDoc('[', ', ', ']', $expression->elements, $comments);
    }
    if ($expression instanceof Ast\Lambda) {
        if ($expression->section) {
            return sectionInlineDoc($expression->body, $comments);
        }
        $params = [];
        foreach ($expression->params as $param) {
            $params[] = printParam($param->pattern ?? $param);
        }

        return Doc::concat([
            Doc::text('\\' . \implode(' ', $params) . ' -> '),
            inlineChildDoc($expression->body, $comments),
        ]);
    }
    if ($expression instanceof Ast\RecordCon) {
        $parts = [Doc::text($expression->name . ' { ')];
        foreach ($expression->fields as $index => $field) {
            if ($index !== 0) {
                $parts[] = Doc::text(', ');
            }
            $parts[] = Doc::text($field->name . ' = ');
            $parts[] = inlineChildDoc($field->expr, $comments);
        }
        $parts[] = Doc::text(' }');

        return Doc::concat($parts);
    }
    if ($expression instanceof Ast\FieldAccess) {
        return Doc::concat([inlineChildDoc($expression->object, $comments), Doc::text('.' . $expression->field)]);
    }
    if ($expression instanceof Ast\RecordUpdate) {
        $parts = [];
        if ($expression->object instanceof Ast\Variable) {
            $parts[] = inlineChildDoc($expression->object, $comments);
        } else {
            $parts[] = Doc::text('(');
            $parts[] = inlineChildDoc($expression->object, $comments);
            $parts[] = Doc::text(')');
        }
        $parts[] = Doc::text(' { ');
        foreach ($expression->fields as $index => $field) {
            if ($index !== 0) {
                $parts[] = Doc::text(', ');
            }
            $parts[] = Doc::text($field->name . ' = ');
            $parts[] = inlineChildDoc($field->expr, $comments);
        }
        $parts[] = Doc::text(' }');

        return Doc::concat($parts);
    }
    if ($expression instanceof Ast\TypeAsc) {
        $inner = inlineChildDoc($expression->expr, $comments);
        if (! isAtomicExpression($expression->expr)) {
            $inner = Doc::concat([Doc::text('('), $inner, Doc::text(')')]);
        }

        return Doc::concat([$inner, Doc::text(' :: ' . printType($expression->type))]);
    }
    if ($expression instanceof Ast\CaseExpr && $expression->ifParts !== null) {
        return inlineIfDoc($expression, $comments);
    }
    if ($expression instanceof Ast\Let) {
        if (isSingleVariableBinding($expression)) {
            $one = $expression->bindings[0];

            return Doc::concat([
                Doc::text('let ' . $one->pattern->name . ' = '),
                inlineChildDoc($one->value, $comments),
                Doc::text(' in '),
                inlineChildDoc($expression->body, $comments),
            ]);
        }

        throw new FormatError('a multi-binding let inside an expression needs its block form');
    }

    throw new FormatError('the printer does not handle the expression ' . \get_class($expression) . ' yet');
}

/** @param list<Ast\AstNode> $items */
function inlineSequenceDoc(string $open, string $separator, string $close, array $items, bool $comments): Doc
{
    $parts = [Doc::text($open)];
    foreach ($items as $index => $item) {
        if ($index !== 0) {
            $parts[] = Doc::text($separator);
        }
        $parts[] = inlineChildDoc($item, $comments);
    }
    $parts[] = Doc::text($close);

    return Doc::concat($parts);
}

/**
 * Whether an operand nested in the same operator re-parses to the same shape without parentheses.
 *
 * A left-associative operator nests to the left and a right-associative one to the right, so the
 * operand on that side is exactly what the parser builds unparenthesized; the other side is a
 * different tree and keeps its parentheses. A non-associative operator needs them on both sides,
 * and so does an operator whose fixity the module never declares: guessing the default there was
 * what turned `(a >>> b) >>> c` into `a >>> b >>> c`, which an imported `infixr` reads back the
 * other way round.
 */
function keepsGroupingWithoutParens(Ast\Infix $operand, ?string $parentOperator, bool $left): bool
{
    if ($operand->operator === $parentOperator) {
        $assoc = Operators::assoc($parentOperator);

        return $assoc !== null && ($left ? $assoc === 'infixl' : $assoc === 'infixr');
    }

    $parentPrecedence = Operators::precedence($parentOperator);
    $operandPrecedence = Operators::precedence($operand->operator);
    if ($parentPrecedence === null || $operandPrecedence === null) {
        return false;
    }
    if ($operandPrecedence !== $parentPrecedence) {
        return $operandPrecedence > $parentPrecedence;
    }

    $assoc = Operators::assoc($parentOperator);
    $operandAssoc = Operators::assoc($operand->operator);
    if ($assoc === null || $operandAssoc === null || $assoc === 'infix' || $operandAssoc === 'infix') {
        return false;
    }

    return $left ? $assoc === 'infixl' : $assoc === 'infixr';
}

/** Whether `$expression` is one atom, so it can always stand as an operand unchanged. */
function isAtomicExpression(Ast\AstNode $expression): bool
{
    if ($expression->listComp() !== null) {
        return true;
    }
    if ($expression instanceof Ast\Apply && $expression->enumForm !== null) {
        return true;
    }

    return $expression instanceof Ast\Variable || $expression instanceof Ast\ConstructorRef
        || $expression instanceof Ast\QualifiedRef || $expression instanceof Ast\OperatorRef
        || $expression instanceof Ast\IntegerLit || $expression instanceof Ast\DoubleLit
        || $expression instanceof Ast\StringLit || $expression instanceof Ast\CharLit
        || $expression instanceof Ast\ExprHole || $expression instanceof Ast\Tuple
        || $expression instanceof Ast\ListLit || $expression instanceof Ast\RecordCon
        || $expression instanceof Ast\FieldAccess;
}

/**
 * An operand of an infix, parenthesized where dropping the grouping would change the parse.
 *
 * An operand binds tighter than the operator around it and needs no parentheses. At the same
 * precedence the parent's associativity decides the nesting side: `a + b + c` parses back to
 * `(a + b) + c`, while `a + (b + c)` does not. An operand that binds looser, or whose fixity is
 * unknown, keeps its parentheses: that is what the source meant.
 */
function infixOperand(Ast\AstNode $operand, ?string $parentOperator = null, bool $left = false): string
{
    return renderFlat(inlineOperandDoc($operand, $parentOperator, $left, true));
}

/** The inline operand rule, shared by `printExpression` and every document layout. */
function inlineOperandDoc(Ast\AstNode $operand, ?string $parentOperator, bool $left, bool $comments): Doc
{
    $doc = inlineChildDoc($operand, $comments);
    if ($operand instanceof Ast\Infix) {
        return keepsGroupingWithoutParens($operand, $parentOperator, $left)
            ? $doc
            : Doc::concat([Doc::text('('), $doc, Doc::text(')')]);
    }
    if ($operand instanceof Ast\TypeAsc || $operand instanceof Ast\Lambda) {
        return Doc::concat([Doc::text('('), $doc, Doc::text(')')]);
    }

    return $doc;
}

/** The same rule for the document printer. */
function infixOperandDoc(Ast\AstNode $operand, ?string $parentOperator, bool $left): Doc
{
    if (containsBlock($operand) || $operand instanceof Ast\TypeAsc || $operand instanceof Ast\Lambda
        || ($operand instanceof Ast\Infix && ! keepsGroupingWithoutParens($operand, $parentOperator, $left))) {
        return Doc::concat([Doc::text('('), expressionDoc($operand), Doc::text(')')]);
    }

    return expressionDoc($operand);
}

/**
 * An argument in an application: parenthesized only where it has to be.
 *
 * A negative literal is the case that is not obvious: `f x -2.35` re-parses as `(f x) - 2.35`, so a
 * bare one changes the program. An operator section already carries its own parentheses, and an
 * atom needs none.
 */
function printArgument(Ast\AstNode $argument): string
{
    return renderFlat(argumentInlineExpressionDoc($argument));
}

function argumentInlineExpressionDoc(Ast\AstNode $argument): Doc
{
    $inline = Trivia::takeInline($argument->line, $argument->col);
    $doc = argumentInlineDoc($argument, true);

    return $inline === null ? $doc : Doc::concat([Doc::text($inline['text'] . ' '), $doc]);
}

/** Whether an application argument has to keep parentheses to stay one argument. */
function argumentNeedsParens(Ast\AstNode $argument): bool
{
    if (($argument instanceof Ast\DoubleLit || $argument instanceof Ast\IntegerLit)
        && \str_starts_with(measureInline($argument), '-')) {
        return true;
    }
    if ($argument instanceof Ast\Lambda && $argument->section) {
        return false;
    }
    if ($argument instanceof Ast\OperatorRef) {
        return false;
    }
    if ($argument->listComp() !== null) {
        return false;
    }

    return ! ($argument instanceof Ast\Variable || $argument instanceof Ast\ConstructorRef
        || $argument instanceof Ast\IntegerLit || $argument instanceof Ast\DoubleLit
        || $argument instanceof Ast\StringLit || $argument instanceof Ast\CharLit
        || $argument instanceof Ast\Tuple || $argument instanceof Ast\ListLit
        || $argument instanceof Ast\RecordCon || $argument instanceof Ast\ExprHole);
}

function argumentInlineDoc(Ast\AstNode $argument, bool $comments): Doc
{
    $doc = inlineChildDoc($argument, $comments);

    return argumentNeedsParens($argument)
        ? Doc::concat([Doc::text('('), $doc, Doc::text(')')])
        : $doc;
}

/** An operator section, rebuilt from the lambda the parser splices in. */
function printSection(Ast\AstNode $body): string
{
    return renderFlat(sectionInlineDoc($body, true));
}

/** An operator section, rebuilt from the lambda the parser splices in. */
function sectionInlineDoc(Ast\AstNode $body, bool $comments): Doc
{
    if ($body instanceof Ast\Infix) {
        $leftIsSplice = $body->left instanceof Ast\Variable && $body->left->name === '__section';
        $rightIsSplice = $body->right instanceof Ast\Variable && $body->right->name === '__section';

        if ($leftIsSplice && ! $rightIsSplice) {
            return Doc::concat([
                Doc::text('(' . infixOperator($body->operator) . ' '),
                inlineChildDoc($body->right, $comments),
                Doc::text(')'),
            ]);
        }
        if ($rightIsSplice && ! $leftIsSplice) {
            return Doc::concat([
                Doc::text('('),
                inlineChildDoc($body->left, $comments),
                Doc::text(' ' . infixOperator($body->operator) . ')'),
            ]);
        }
    }

    throw new FormatError('an operator section this printer cannot rebuild is not supported yet');
}

/** A string literal as source: `json_encode` escapes exactly what the lexer accepts. */
function literalString(string $value): string
{
    $encoded = \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new FormatError('a string literal that does not encode');
    }

    return $encoded;
}

function literalChar(int $value): string
{
    $char = \mb_chr($value, 'UTF-8');
    if ($char === false) {
        throw new FormatError('a character literal that is not a scalar');
    }

    return match ($char) {
        "'" => "'\\''",
        '\\' => "'\\\\'",
        "\n" => "'\\n'",
        "\t" => "'\\t'",
        "\r" => "'\\r'",
        default => "'" . $char . "'",
    };
}
