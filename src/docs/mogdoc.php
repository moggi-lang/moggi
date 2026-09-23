<?php declare(strict_types=1);

namespace Moggi\Docs;

function generateMogdoc(DocIndex $index, string $outputDir): void
{
    if (!\is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !\is_dir($outputDir)) {
        throw new \RuntimeException("cannot create output directory {$outputDir}");
    }

    writeSourcePages($index, $outputDir);

    $searchJson = json_encode(searchIndexToJson($index), JSON_UNESCAPED_UNICODE);
    if ($searchJson === false) {
        throw new \RuntimeException('cannot encode search index');
    }
    if (file_put_contents($outputDir . '/search-index.json', $searchJson) === false) {
        throw new \RuntimeException('cannot write search-index.json');
    }

    $moogleJs = emitMoogleJs();
    if (file_put_contents($outputDir . '/moogle.js', $moogleJs) === false) {
        throw new \RuntimeException('cannot write moogle.js');
    }

    $linkTargets = buildLinkTargets($index);
    $moduleNames = array_keys($index->byModule);
    sort($moduleNames);

    $indexBody = '<h1 class="page-title">Moggi Index</h1>';
    $indexBody .= '<p class="meta">' . count($index->byModule) . ' modules, '
        . count($index->search) . ' searchable entries</p>';
    $indexBody .= '<ul class="module-list">';
    foreach ($moduleNames as $moduleName) {
        $file = modulePageName($moduleName);
        $indexBody .= '<li><a href="' . escapeHtml($file) . '">'
            . escapeHtml($moduleName) . '</a></li>';
    }
    $indexBody .= '</ul>';

    writeHtmlFile($outputDir . '/index.html', 'Moggi Index', $indexBody, true, false, true);

    $written = 0;
    foreach ($index->byModule as $moduleName => $entities) {
        $body = renderModulePage($moduleName, $entities, $linkTargets, $index);
        writeHtmlFile(
            $outputDir . '/' . modulePageName($moduleName),
            $moduleName,
            $body,
            true,
            true,
        );
        ++$written;
        if ($written % 25 === 0) {
            \fwrite(STDERR, "mogdoc: wrote {$written}/" . count($index->byModule) . " pages\n");
        }
    }
}

/** @return array<string, string> */
function buildLinkTargets(DocIndex $index): array
{
    $targets = [];
    $bareCounts = [];
    foreach ($index->all as $entity) {
        if ($entity->kind === 'module' || !entityHrefMatchesModule($entity)) {
            continue;
        }
        $bareCounts[$entity->name] = ($bareCounts[$entity->name] ?? 0) + 1;
    }

    foreach ($index->all as $entity) {
        if ($entity->kind === 'module' || !entityHrefMatchesModule($entity)) {
            continue;
        }
        $qualified = $entity->module . '.' . $entity->name;
        $targets[$qualified] = $entity->href;
        if (($bareCounts[$entity->name] ?? 0) === 1) {
            $targets[$entity->name] = $entity->href;
        }
        if ($entity->parentName !== null && $entity->declKind === 'ctor') {
            $targets[$entity->module . '.' . $entity->parentName . '.' . $entity->name] = $entity->href;
        }
    }

    return $targets;
}

/** @param list<DocEntity> $entities */
function renderModulePage(string $moduleName, array $entities, array $linkTargets, DocIndex $index): string
{
    $html = '<h1 class="page-title" id="' . escapeHtml(anchorId($moduleName, $moduleName)) . '">'
        . escapeHtml($moduleName) . '</h1>';

    $heads = [];
    /** @var array<string, list<DocEntity>> $childrenByParent */
    $childrenByParent = [];

    foreach ($entities as $entity) {
        if ($entity->kind === 'module') {
            if ($entity->doc !== null && $entity->doc !== '') {
                $html .= renderDocMarkup($entity->doc, $linkTargets);
            }
            $html .= renderBackendNav($moduleName, $index);
            continue;
        }

        if ($entity->parentName !== null) {
            $childrenByParent[$entity->parentName][] = $entity;
            continue;
        }

        $heads[] = $entity;
    }

    $currentSection = null;
    foreach ($heads as $entity) {
        if ($entity->section !== null && $entity->section !== $currentSection) {
            $currentSection = $entity->section;
            $html .= '<h2 class="section">' . escapeHtml($currentSection) . '</h2>';
        }

        $defaultSection = defaultSectionLabel($entity);
        if ($defaultSection !== null && $defaultSection !== $currentSection) {
            $currentSection = $defaultSection;
            $html .= '<h2 class="section">' . escapeHtml($defaultSection) . '</h2>';
        }

        $html .= renderDeclGroup($entity, $childrenByParent[$entity->name] ?? [], $linkTargets);
    }

    return $html;
}

/** @param list<DocEntity> $children */
function renderDeclGroup(DocEntity $head, array $children, array $linkTargets): string
{
    $id = anchorId($head->module, $head->name);
    $kindLabel = declKindLabel($head);
    $signatureHead = usesSignatureHead($head);
    $isClass = ($head->declKind ?? '') === 'class';
    $isDataHead = in_array($head->declKind ?? '', ['data', 'newtype'], true);
    $html = '<div class="decl" id="' . escapeHtml($id) . '">';
    if ($signatureHead && $head->signature !== null) {
        $html .= '<div class="decl-head decl-signature-head"><pre class="signature">'
            . escapeHtml($head->signature) . '</pre>'
            . renderFixityDecl($head)
            . renderSourceLink($head)
            . '</div>';
    } else {
        $html .= '<div class="decl-head"><span class="decl-head-main"><span class="kind">'
            . escapeHtml($kindLabel) . '</span> ';
        $html .= '<code>' . escapeHtml($head->name) . '</code></span>';
        $html .= renderSourceLink($head) . '</div>';
    }
    $html .= '<div class="decl-body">';
    if ($head->signature !== null && !$signatureHead) {
        $html .= '<pre class="signature">' . escapeHtml($head->signature) . '</pre>';
    }
    if ($head->doc !== null && $head->doc !== '') {
        $html .= '<div class="doc">' . renderDocMarkup($head->doc, $linkTargets) . '</div>';
    }

    $ctors = [];
    $instances = [];
    $methods = [];
    foreach ($children as $child) {
        match ($child->declKind ?? '') {
            'ctor' => $ctors[] = $child,
            'instance' => $instances[] = $child,
            default => $methods[] = $child,
        };
    }

    if ($ctors !== []) {
        $html .= '<h4 class="subsection">Constructors</h4>';
        $html .= renderCtorTable($ctors, $linkTargets);
    }
    if ($instances !== []) {
        $html .= '<h4 class="subsection">Instances</h4>';
        $html .= renderInstanceList($instances, $linkTargets);
    }
    if ($methods !== []) {
        $sectionTitle = $isClass ? 'Methods' : 'Details';
        if ($sectionTitle === 'Details' && $methods === $children) {
            $sectionTitle = null;
        }
        if ($sectionTitle !== null && !$isDataHead) {
            $html .= '<h4 class="subsection">' . escapeHtml($sectionTitle) . '</h4>';
        }
        $html .= renderSubDeclList($methods, $linkTargets, $isClass);
    }

    if ($head->aliases !== []) {
        $aliasLinks = [];
        foreach ($head->aliases as $aliasModule) {
            $aliasLinks[] = '<a href="' . escapeHtml(modulePageName($aliasModule)) . '">'
                . escapeHtml($aliasModule) . '</a>';
        }
        $html .= '<p class="aliases">Also exported from: ' . implode(', ', $aliasLinks) . '</p>';
    }
    $html .= '</div></div>';

    return $html;
}

/** @param list<DocEntity> $ctors */
function renderCtorTable(array $ctors, array $linkTargets): string
{
    $html = '<table class="ctor-table"><tbody>';
    foreach ($ctors as $child) {
        $childId = entityAnchorId($child->module, $child->name, $child->declKind, $child->parentName);
        $html .= '<tr id="' . escapeHtml($childId) . '"><td class="ctor-name"><code>'
            . escapeHtml($child->name) . '</code></td><td class="ctor-doc">';
        if ($child->doc !== null && $child->doc !== '') {
            $html .= '<div class="doc">' . renderDocMarkup($child->doc, $linkTargets) . '</div>';
        }
        $html .= '</td></tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

/** @param list<DocEntity> $instances */
function renderInstanceList(array $instances, array $linkTargets): string
{
    $html = '<ul class="instance-list">';
    foreach ($instances as $child) {
        $childId = anchorId($child->module, $child->name);
        $html .= '<li id="' . escapeHtml($childId) . '">';
        if ($child->signature !== null) {
            $html .= '<code class="instance-sig">' . escapeHtml($child->signature) . '</code>';
        } else {
            $html .= '<code>' . escapeHtml($child->name) . '</code>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';

    return $html;
}

/** @param list<DocEntity> $children */
function renderSubDeclList(array $children, array $linkTargets, bool $hideLabels): string
{
    $html = '<ul class="sub-decls">';
    foreach ($children as $child) {
        $childId = anchorId($child->module, $child->name);
        $subLabel = declKindLabel($child);
        $html .= '<li id="' . escapeHtml($childId) . '">';
        if (!$hideLabels && $subLabel !== 'method') {
            $html .= '<span class="sub-label">' . escapeHtml($subLabel) . '</span>';
        }
        if ($child->signature !== null) {
            $html .= '<div class="sub-decl-signature-row"><pre class="signature">'
                . escapeHtml($child->signature) . '</pre>'
                . renderFixityDecl($child)
                . renderSourceLink($child)
                . '</div>';
        } else {
            $html .= '<div class="sub-decl-head"><code>' . escapeHtml($child->name) . '</code>'
                . renderSourceLink($child)
                . '</div>';
        }
        if ($child->doc !== null && $child->doc !== '') {
            $html .= '<div class="doc">' . renderDocMarkup($child->doc, $linkTargets) . '</div>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';

    return $html;
}

function renderSourceLink(DocEntity $entity): string
{
    if ($entity->sourcePath === null || $entity->sourceLine === null || $entity->sourceLine <= 0) {
        return '';
    }

    $href = sourcePageHref($entity->sourcePath, $entity->sourceLine);

    return '<span class="source-link"><a href="' . escapeHtml($href) . '"># Source</a></span>';
}

function renderFixityDecl(DocEntity $entity): string
{
    if ($entity->fixity === null || $entity->fixity === '') {
        return '';
    }

    return '<p class="fixity-decl">' . escapeHtml($entity->fixity . ' ' . $entity->name) . '</p>';
}

function usesSignatureHead(DocEntity $head): bool
{
    if ($head->signature === null) {
        return false;
    }

    return match ($head->declKind ?? '') {
        'class', 'data', 'newtype', 'synonym', 'function', 'foreign', 'primop' => true,
        default => $head->kind === 'value' || $head->kind === 'type',
    };
}

function defaultSectionLabel(DocEntity $entity): ?string
{
    if ($entity->section !== null || $entity->parentName !== null || $entity->kind === 'module') {
        return null;
    }

    return match ($entity->declKind ?? $entity->kind) {
        'data', 'newtype', 'synonym', 'primtype' => 'Data types',
        'class' => 'Classes',
        'function', 'foreign', 'primop' => 'Operations',
        default => null,
    };
}

function sourcePageName(string $relativePath): string
{
    return 'src/' . str_replace(['/', '\\', ' '], '-', $relativePath) . '.html';
}

function sourcePageHref(string $relativePath, int $line): string
{
    return sourcePageName($relativePath) . '#L' . $line;
}

function writeSourcePages(DocIndex $index, string $outputDir): void
{
    $paths = [];
    foreach ($index->all as $entity) {
        if ($entity->sourcePath !== null && $entity->sourcePath !== '') {
            $paths[$entity->sourcePath] = true;
        }
    }

    if ($paths === []) {
        return;
    }

    $srcDir = $outputDir . '/src';
    if (!\is_dir($srcDir) && !mkdir($srcDir, 0777, true) && !\is_dir($srcDir)) {
        throw new \RuntimeException("cannot create source directory {$srcDir}");
    }

    foreach (array_keys($paths) as $relativePath) {
        $absolute = $index->rootPrefix !== ''
            ? rtrim($index->rootPrefix, '/\\') . '/' . $relativePath
            : $relativePath;
        if (!\is_file($absolute)) {
            continue;
        }
        $source = file_get_contents($absolute);
        if (!\is_string($source)) {
            continue;
        }
        $page = sourcePageName($relativePath);
        writeHtmlFile(
            $outputDir . '/' . $page,
            basename($relativePath) . ' · source',
            renderSourcePage($relativePath, $source),
            false,
        );
    }
}

function renderSourcePage(string $relativePath, string $source): string
{
    $html = '<h1 class="page-title">' . escapeHtml($relativePath) . '</h1>';
    $html .= '<pre class="source-listing">';
    $lineNo = 1;
    foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
        $id = 'L' . $lineNo;
        $html .= '<span class="src-line" id="' . $id . '">'
            . '<span class="line-no">' . $lineNo . '</span>'
            . escapeHtml($line) . "\n</span>";
        ++$lineNo;
    }
    $html .= '</pre>';

    return $html;
}

function declKindLabel(DocEntity $entity): string
{
    return match ($entity->declKind ?? $entity->kind) {
        'data' => 'data',
        'newtype' => 'newtype',
        'synonym' => 'type synonym',
        'primtype' => 'primitive type',
        'class' => 'class',
        'ctor' => 'constructor',
        'method' => 'method',
        'instance' => 'instance',
        'function' => 'operation',
        'primop' => 'primop',
        'foreign' => 'foreign import',
        'value' => 'operation',
        'type' => 'type',
        default => $entity->kind,
    };
}

function renderBackendNav(string $moduleName, DocIndex $index): string
{
    $facadeMap = $index->facadeBackends[$moduleName] ?? null;
    if ($facadeMap === null) {
        return '';
    }

    $labels = [];
    foreach (backendDisplayOrder() as $backend) {
        if (!isset($facadeMap[$backend])) {
            continue;
        }
        $labels[] = escapeHtml(backendLabel($backend));
    }

    if ($labels === []) {
        return '';
    }

    return '<div class="backends"><strong>Backends:</strong> ' . implode(' · ', $labels) . '</div>';
}

function renderMogdocSearchBox(string $initialQuery, bool $autofocus = false): string
{
    $q = escapeHtml($initialQuery);
    $focus = $autofocus ? ' autofocus' : '';

    return <<<HTML
<div class="docs-search">
  <form action="index.html" method="get">
    <input name="q" type="search" placeholder="Search names or types…" value="{$q}" autocomplete="off"{$focus}>
    <button type="submit">Search</button>
  </form>
  <ul id="mogdoc-hits"></ul>
</div>
HTML;
}

function mogdocSearchScript(): string
{
    return <<<'HTML'
<script src="moogle.js"></script>
HTML;
}

function writeHtmlFile(string $path, string $title, string $body, bool $withSearch, bool $homeLink = false, bool $searchAutofocus = false): void
{
    $search = $withSearch ? renderMogdocSearchBox('', $searchAutofocus) : '';
    $script = $withSearch ? mogdocSearchScript() : '';
    $html = docsPageShell($title, $search . $body, '', $script, $homeLink);

    if (file_put_contents($path, $html) === false) {
        throw new \RuntimeException("cannot write {$path}");
    }
}
