<?php declare(strict_types=1);

namespace Moggi\Docs;

function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render the doc-comment markup subset: paragraphs, lists, emphasis, links, code.
 *
 * @param array<string, string> $linkTargets module-qualified name => href
 */
function renderDocMarkup(string $text, array $linkTargets = []): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $paragraphs = preg_split("/\n\s*\n/", $text) ?: [];
    $html = [];

    foreach ($paragraphs as $para) {
        $lines = explode("\n", $para);
        if (isBulletList($lines)) {
            $html[] = renderBulletList($lines, $linkTargets);
            continue;
        }

        $html[] = '<p>' . renderInlineMarkup(implode("\n", $lines), $linkTargets) . '</p>';
    }

    return implode("\n", $html);
}

/** @param list<string> $lines */
function isBulletList(array $lines): bool
{
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        return str_starts_with(ltrim($line), '- ');
    }

    return false;
}

/** @param list<string> $lines */
function renderBulletList(array $lines, array $linkTargets): string
{
    $items = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '') {
            continue;
        }
        if (!str_starts_with($trimmed, '- ')) {
            $items[] = '<li>' . renderInlineMarkup($trimmed, $linkTargets) . '</li>';
            continue;
        }
        $items[] = '<li>' . renderInlineMarkup(substr($trimmed, 2), $linkTargets) . '</li>';
    }

    return '<ul>' . implode('', $items) . '</ul>';
}

/** @param array<string, string> $linkTargets */
function renderInlineMarkup(string $text, array $linkTargets): string
{
    $out = '';
    $len = strlen($text);
    $i = 0;

    while ($i < $len) {
        if ($text[$i] === '{' && $i + 1 < $len && $text[$i + 1] === '@') {
            $end = strpos($text, '}', $i + 2);
            if ($end !== false) {
                $inner = substr($text, $i + 2, $end - $i - 2);
                if (str_starts_with($inner, 'code ')) {
                    $inner = substr($inner, 5);
                } else {
                    $inner = ltrim($inner, '@');
                }
                $out .= '<code>' . escapeHtml($inner) . '</code>';
                $i = $end + 1;
                continue;
            }
        }

        if ($text[$i] === '@') {
            $end = strpos($text, '@', $i + 1);
            if ($end !== false) {
                $mono = substr($text, $i + 1, $end - $i - 1);
                $out .= '<code>' . escapeHtml($mono) . '</code>';
                $i = $end + 1;
                continue;
            }
        }

        if ($text[$i] === '"' && ($i === 0 || $text[$i - 1] !== '\\')) {
            $end = strpos($text, '"', $i + 1);
            if ($end !== false) {
                $link = substr($text, $i + 1, $end - $i - 1);
                $href = resolveDocLink($link, $linkTargets);
                if ($href !== null) {
                    $label = str_contains($link, '#') ? explode('#', $link, 2)[1] : $link;
                    $out .= '<a href="' . escapeHtml($href) . '">' . escapeHtml($label) . '</a>';
                } else {
                    $out .= '"' . escapeHtml($link) . '"';
                }
                $i = $end + 1;
                continue;
            }
        }

        if ($text[$i] === '/' && $i + 1 < $len && $text[$i + 1] !== '/') {
            $end = strpos($text, '/', $i + 1);
            if ($end !== false) {
                $inner = substr($text, $i + 1, $end - $i - 1);
                $out .= '<em>' . renderInlineMarkup($inner, $linkTargets) . '</em>';
                $i = $end + 1;
                continue;
            }
        }

        if ($text[$i] === '_' && $i + 1 < $len && $text[$i + 1] === '_') {
            $end = strpos($text, '__', $i + 2);
            if ($end !== false) {
                $inner = substr($text, $i + 2, $end - $i - 2);
                $out .= '<strong>' . renderInlineMarkup($inner, $linkTargets) . '</strong>';
                $i = $end + 2;
                continue;
            }
        }

        if ($text[$i] === '^') {
            $end = strpos($text, '^', $i + 1);
            if ($end !== false) {
                $inner = substr($text, $i + 1, $end - $i - 1);
                $out .= '<sup>' . renderInlineMarkup($inner, $linkTargets) . '</sup>';
                $i = $end + 1;
                continue;
            }
        }

        if ($text[$i] === '\'') {
            $end = strpos($text, '\'', $i + 1);
            if ($end !== false) {
                $ident = substr($text, $i + 1, $end - $i - 1);
                if ($ident !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_.#()]*$/', $ident) === 1) {
                    $href = $linkTargets[$ident]
                        ?? $linkTargets[qualifiedNameFromIdent($ident, $linkTargets)]
                        ?? null;
                    if ($href !== null) {
                        $out .= '<a href="' . escapeHtml($href) . '"><code>'
                            . escapeHtml($ident) . '</code></a>';
                    } else {
                        $out .= '<code>' . escapeHtml($ident) . '</code>';
                    }
                    $i = $end + 1;
                    continue;
                }
            }
        }

        $out .= escapeHtml($text[$i]);
        ++$i;
    }

    return $out;
}

/** @param array<string, string> $linkTargets */
function resolveDocLink(string $link, array $linkTargets): ?string
{
    if (isset($linkTargets[$link])) {
        return $linkTargets[$link];
    }

    if (str_contains($link, '#')) {
        [$module, $anchor] = explode('#', $link, 2);
        $moduleHref = modulePageName($module);
        if (str_contains($anchor, '.')) {
            [$parent, $name] = explode('.', $anchor, 2);
            $id = entityAnchorId($module, $name, 'ctor', $parent);
        } else {
            $id = entityAnchorId($module, $anchor, null, null);
        }

        return $moduleHref . '#' . $id;
    }

    if (str_contains($link, '.')) {
        return modulePageName($link);
    }

    return null;
}

/** @param array<string, string> $linkTargets */
function qualifiedNameFromIdent(string $ident, array $linkTargets): string
{
    $matches = [];
    foreach (array_keys($linkTargets) as $key) {
        if ($key === $ident || str_ends_with($key, '.' . $ident)) {
            $matches[] = $key;
        }
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    return $ident;
}

function anchorId(string $module, string $name): string
{
    $raw = $module . '.' . $name;

    return 'v-' . (preg_replace('/[^A-Za-z0-9._-]+/', '_', $raw) ?? $raw);
}

function entityAnchorId(
    string $module,
    string $name,
    ?string $declKind = null,
    ?string $parentName = null,
): string {
    if ($declKind === 'ctor' && $parentName !== null && $parentName !== '') {
        return anchorId($module, $parentName . '.' . $name);
    }

    return anchorId($module, $name);
}
