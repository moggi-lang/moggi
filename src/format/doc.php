<?php declare(strict_types=1);

namespace Moggi\Format;

/**
 * A document: the shape the printer builds, and the renderer turns into text.
 *
 * The algebra is Wadler's with one addition the fixtures need — a *hard* line, which can never be
 * flattened, so a group that contains one always breaks. Without it a `do` block or a record whose
 * fields carry comments could be squeezed onto one line, which is exactly the churn this formatter
 * exists to avoid.
 *
 * Width is a property of the renderer, not of the tree: the same document renders differently at a
 * different width, and nothing in the printer may measure columns itself.
 *
 * Every node carries the width of its flat form and whether it contains a hard line. Those are
 * facts about the subtree, not about the current column, so computing them once when the document
 * is built turns every later fit check into two integer/boolean reads. Re-walking a subtree for
 * each enclosing group made deeply nested expressions quadratic in their rendered size.
 */
final class Doc
{
    private static ?self $emptyNode = null;
    private static ?self $lineNode = null;
    private static ?self $softlineNode = null;
    private static ?self $softbreakNode = null;

    /**
     * @param 'empty'|'text'|'line'|'softline'|'softbreak'|'concat'|'nest'|'align'|'group' $kind
     * @param list<Doc> $children
     * @param int $indent for `nest`, the columns added; unused otherwise
     * @param int $flatWidth columns this document occupies when no soft line breaks
     * @param bool $hasHardLine whether a `line` makes flattening impossible
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $text = '',
        public readonly array $children = [],
        public readonly int $indent = 0,
        public readonly int $flatWidth = 0,
        public readonly bool $hasHardLine = false,
    ) {
    }

    public static function empty(): self
    {
        return self::$emptyNode ??= new self('empty');
    }

    public static function text(string $text): self
    {
        if ($text === '') {
            return self::empty();
        }

        return new self('text', $text, flatWidth: displayWidth($text));
    }

    /** A newline that no group can flatten. */
    public static function line(): self
    {
        return self::$lineNode ??= new self('line', hasHardLine: true);
    }

    /** A space when the enclosing group is flat, a newline when it breaks. */
    public static function softline(): self
    {
        return self::$softlineNode ??= new self('softline', flatWidth: 1);
    }

    /** Nothing when the group is flat, a newline when it breaks. */
    public static function softbreak(): self
    {
        return self::$softbreakNode ??= new self('softbreak');
    }

    /** Indent everything inside by `$columns`, including the lines it breaks. */
    public static function nest(int $columns, Doc $doc): self
    {
        if ($columns === 0 || $doc->kind === 'empty') {
            return $doc;
        }

        return new self(
            'nest',
            '',
            [$doc],
            $columns,
            $doc->flatWidth,
            $doc->hasHardLine,
        );
    }

    /** Indent what follows to the column this document started at. */
    public static function align(Doc $doc): self
    {
        if ($doc->kind === 'empty') {
            return $doc;
        }

        return new self('align', '', [$doc], flatWidth: $doc->flatWidth, hasHardLine: $doc->hasHardLine);
    }

    /** Try this on one line; break its soft lines if it does not fit. */
    public static function group(Doc $doc): self
    {
        if ($doc->kind === 'empty') {
            return $doc;
        }

        return new self('group', '', [$doc], flatWidth: $doc->flatWidth, hasHardLine: $doc->hasHardLine);
    }

    /**
     * A concatenation of documents.
     *
     * Empty children are dropped and a one-child concatenation is that child, so the common
     * `join` shapes do not leave layers of no-op nodes for the renderer to pop. The flat width
     * and hard-line flag are accumulated here rather than rediscovered by every `fits` call.
     *
     * @param list<Doc> $docs
     */
    public static function concat(array $docs): self
    {
        $children = [];
        $flatWidth = 0;
        $hasHardLine = false;

        foreach ($docs as $child) {
            if ($child->kind === 'empty') {
                continue;
            }

            $children[] = $child;
            $flatWidth += $child->flatWidth;
            $hasHardLine = $hasHardLine || $child->hasHardLine;
        }

        if ($children === []) {
            return self::empty();
        }
        if (\count($children) === 1) {
            return $children[0];
        }

        return new self('concat', '', $children, flatWidth: $flatWidth, hasHardLine: $hasHardLine);
    }
}

/** The canonical width. Every rule about "if it fits" means this many columns or fewer. */
const WIDTH = 100;

/**
 * Columns a string occupies, not bytes.
 *
 * A comment or literal holding `λ` is one column wide and three bytes long; measuring bytes broke
 * such a line early and counted text that is not on the display. Control characters and combining
 * marks are collapsed the way a terminal collapses them.
 */
function displayWidth(string $text): int
{
    return \mb_strwidth($text, 'UTF-8');
}

/**
 * Render a document at `$width` columns, with `$indentUnit` per level of indentation.
 *
 * A group is flattened only when its whole flattened text fits in the columns left on the current
 * line. A hard line anywhere inside it makes that impossible, so the group breaks — which is how a
 * construct that must stay multi-line (a `where`, a record with field comments) stays that way.
 */
function render(Doc $doc, int $width = WIDTH): string
{
    $out = '';
    $column = 0;
    $pads = [];

    /** @var list<array{int, bool, Doc}> $stack indent, flat, node */
    $stack = [[0, false, $doc]];

    while ($stack !== []) {
        [$indent, $flat, $node] = \array_pop($stack);

        switch ($node->kind) {
            case 'empty':
                break;

            case 'text':
                $out .= $node->text;
                $column += $node->flatWidth;
                break;

            case 'concat':
                for ($i = \count($node->children) - 1; $i >= 0; --$i) {
                    $stack[] = [$indent, $flat, $node->children[$i]];
                }
                break;

            case 'nest':
                $stack[] = [$indent + $node->indent, $flat, $node->children[0]];
                break;

            case 'align':
                $stack[] = [$column, $flat, $node->children[0]];
                break;

            case 'group':
                $fits = $flat || fits($node->children[0], $width - $column);
                $stack[] = [$indent, $fits, $node->children[0]];
                break;

            case 'line':
            case 'softline':
            case 'softbreak':
                if ($flat && $node->kind !== 'line') {
                    if ($node->kind === 'softline') {
                        $out .= ' ';
                        ++$column;
                    }
                    break;
                }
                $out = \rtrim($out, " \t") . "\n" . ($pads[$indent] ??= \str_repeat(' ', $indent));
                $column = $indent;
                break;
        }
    }

    return $out;
}

/**
 * Whether `$doc` renders flat within `$remaining` columns.
 *
 * The flat width and hard-line fact are computed once when the document is built. A hard line
 * answers no: the group it sits in has to break, so there is nothing to measure. This used to walk
 * the subtree on every group, which made a chain of nested groups examine the same children at
 * every level; the answer is already a property of the subtree.
 */
function fits(Doc $doc, int $remaining): bool
{
    return $remaining >= 0 && ! $doc->hasHardLine && $doc->flatWidth <= $remaining;
}

/**
 * Columns the first rendered line of `$doc` occupies.
 *
 * A block document starts with a line of its own before its first hard line. Whether it can share a
 * line with the text in front of it depends on that first line, not on the whole document: `fits()`
 * always says no for a hard-line document. A flat subtree contributes its cached width; a group is
 * measured flat, which can only overestimate the first line and so never puts a body on a line that
 * does not fit.
 */
function firstLineWidth(Doc $doc): int
{
    $width = 0;

    /** @var list<Doc> $stack */
    $stack = [$doc];
    while ($stack !== []) {
        $node = \array_pop($stack);
        if (! $node->hasHardLine) {
            $width += $node->flatWidth;

            continue;
        }

        if ($node->kind === 'line') {
            return $width;
        }
        if ($node->kind === 'concat') {
            for ($i = \count($node->children) - 1; $i >= 0; --$i) {
                $stack[] = $node->children[$i];
            }

            continue;
        }

        $stack[] = $node->children[0];
    }

    return $width;
}

/**
 * Render a document known to have no hard line, ignoring the width.
 *
 * This is how the string form of an expression is produced: the document algebra defines the text
 * once, and flattening it gives the token layout that `Doc::text` would otherwise have to repeat.
 * A hard line here means the caller asked for an inline form of a block, which is a printer bug.
 */
function renderFlat(Doc $doc): string
{
    $out = '';

    /** @var list<Doc> $stack */
    $stack = [$doc];

    while ($stack !== []) {
        $node = \array_pop($stack);

        switch ($node->kind) {
            case 'empty':
                break;

            case 'text':
                $out .= $node->text;
                break;

            case 'softline':
                $out .= ' ';
                break;

            case 'softbreak':
                break;

            case 'line':
                throw new \LogicException('a hard line cannot be rendered inline');

            case 'concat':
                for ($i = \count($node->children) - 1; $i >= 0; --$i) {
                    $stack[] = $node->children[$i];
                }
                break;

            case 'nest':
            case 'align':
            case 'group':
                $stack[] = $node->children[0];
                break;
        }
    }

    return $out;
}
