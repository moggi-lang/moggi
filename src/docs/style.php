<?php declare(strict_types=1);

namespace Moggi\Docs;

/**
 * The stylesheet the API documentation is generated with.
 *
 * Old-school plain HTML, the same look as `moggi-lang.org` and the registry
 * front end: one column, blue underlined links, monospace for signatures,
 * thin-bordered tables. No framework, no dark mode, no rounded corners, and
 * nothing fetched from the network — the whole sheet is inlined into every page
 * by `docsPageShell()`, so a generated tree is a directory of self-contained
 * files.
 *
 * The paper is a warm off-white rather than #fff, because pure white glares on a
 * page you read for a while. The five tones are the same ones the site uses
 * (`website/style.css`): paper #f7f3e8, panel #ece5d5, code #f1ebdd,
 * line #c8bfa9, ink #1c1a16.
 *
 * Class names are load-bearing (the generator emits them); the Main entry point
 * is `docsStylesheet()`. Keep the two in step.
 */
function docsStylesheet(): string
{
    return <<<'CSS'
body {
  margin: 0 auto 3em;
  max-width: 62em;
  padding: 0 1.25em;
  background: #f7f3e8;
  color: #1c1a16;
  font: 15px/1.55 Arial, Helvetica, sans-serif;
}
a { color: #0000cc; }
a:visited { color: #551a8b; }
a:hover { color: #cc0000; }

/* Header: a title, a line of links, a rule. No box. */
.docs-banner {
  border-bottom: 2px solid #000;
  padding: 0.9em 0 0.4em;
  margin-bottom: 1em;
}
.docs-banner h1 {
  margin: 0;
  font-size: 1.5em;
}
.docs-banner h1 a { color: inherit; text-decoration: none; }
.docs-banner h1 a:hover { color: #0000cc; }
.docs-banner .tagline {
  margin: 0.15em 0 0;
  color: #5a5346;
  font-size: 0.9em;
}
.docs-nav {
  margin: 0.3em 0 0;
  font-size: 0.85em;
}
.docs-home {
  margin: 0 0 0.75em;
  font-size: 0.9em;
}

/* Search */
.docs-search input[type="search"],
.docs-search input[type="text"] {
  width: 100%;
  max-width: 34em;
  box-sizing: border-box;
  padding: 0.25em 0.4em;
  border: 1px solid #a89f8a;
  background: #fdfbf4;
  color: #1c1a16;
  font: 15px/1.5 Arial, Helvetica, sans-serif;
}
.docs-search button {
  font: inherit;
  font-size: 0.9em;
  margin-top: 0.4em;
  padding: 0.15em 0.6em;
}
#mogdoc-hits, .moogle-results {
  list-style: none;
  padding: 0;
  margin: 0.75em 0 0;
}
#mogdoc-hits li, .moogle-results li {
  margin: 0.6em 0;
  padding-bottom: 0.4em;
  border-bottom: 1px solid #ded6c4;
}
#mogdoc-hits .sig, .moogle-results .sig {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 0.85em;
  color: #4a4438;
}

/* Headings */
h1.page-title {
  font-size: 1.45em;
  border-bottom: 2px solid #000;
  padding-bottom: 0.15em;
  margin: 0 0 0.7em;
}
h2.section {
  margin: 1.6em 0 0.4em;
  font-size: 1.1em;
  border-bottom: 1px solid #c8bfa9;
}
.subsection {
  margin: 1em 0 0.3em;
  font-size: 0.95em;
}

/* Declarations */
.decl { margin: 1.1em 0 1.4em; }
.decl-head {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 0.9em;
  font-weight: bold;
  background: #ece5d5;
  border: 1px solid #c8bfa9;
  padding: 0.3em 0.5em;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1em;
}
.decl-head-main { min-width: 0; }
.decl-head.decl-signature-head .signature { flex: 1; min-width: 0; }
.decl-head .signature {
  margin: 0;
  border: none;
  background: transparent;
  padding: 0;
  font-weight: bold;
}
.decl-head .kind { font-weight: bold; }
.decl-body {
  border: 1px solid #c8bfa9;
  border-top: none;
  padding: 0.5em 0.75em;
}
.decl-body .doc { margin-top: 0.5em; }

.sub-decls {
  margin: 0.7em 0 0;
  padding: 0;
  list-style: none;
}
.sub-decls li {
  margin: 0.35em 0;
  padding-left: 0.9em;
  border-left: 3px solid #d3cbb8;
}

/* A sub-declaration's own signature row, with its source link at the right. */
.sub-decl-head,
.sub-decl-signature-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1em;
}
.sub-decl-signature-row .signature {
  flex: 1;
  min-width: 0;
  margin: 0;
}

.sub-decls .sub-label {
  font-size: 0.8em;
  text-transform: uppercase;
  color: #5a5346;
  margin-right: 0.3em;
}
.sub-label::after {
  content: ':';
  margin-left: 0.1em;
  text-transform: none;
}
.sub-decls pre.signature { margin: 0.15em 0 0; }

/* A dotted list of constructors, instances and the like. */
.ctor-table {
  width: 100%;
  border-collapse: collapse;
  margin: 0.3em 0 0.5em;
}
.ctor-table td {
  border: 1px solid #c8bfa9;
  padding: 0.2em 0.5em;
  vertical-align: top;
}
.ctor-table .ctor-name { white-space: nowrap; width: 1%; }

.instance-list {
  margin: 0.3em 0 0.5em;
  padding-left: 1.6em;
}
.instance-list li { margin: 0.2em 0; }

.fixity-decl {
  margin: 0.25em 0 0;
  color: #5a5346;
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 0.85em;
}

.source-link {
  flex-shrink: 0;
  margin: 0;
  font-size: 0.85em;
  font-weight: normal;
  text-align: right;
  white-space: nowrap;
}
.source-link a {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  text-decoration: none;
}
.source-link a:hover { text-decoration: underline; }

/* Source and signatures: plain boxes, monospace, nothing else. */
.source-listing, pre.signature {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 0.85em;
  background: #f1ebdd;
  border: 1px solid #c8bfa9;
  padding: 0.5em;
  overflow-x: auto;
}
pre.signature {
  margin: 0.35em 0;
  padding: 0.35em 0.5em;
}
.source-listing .line-no {
  display: inline-block;
  width: 3em;
  color: #7a7364;
  text-align: right;
  margin-right: 0.75em;
  user-select: none;
}
.source-listing .src-line:target { background: #f5ecc0; }

code, .mono {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 0.92em;
}
.meta, .aliases, .backends {
  color: #5a5346;
  font-size: 0.9em;
  margin: 0.5em 0;
}

ul.module-list {
  columns: 2;
  column-gap: 2em;
  padding-left: 1.4em;
}
ul.module-list li { break-inside: avoid; }

.docs-footer {
  margin-top: 2.5em;
  padding-top: 0.5em;
  border-top: 1px solid #c8bfa9;
  color: #5a5346;
  font-size: 0.85em;
}
CSS;
}

/**
 * One generated page: the sheet inlined, the header, the body, the footer.
 *
 * Every page is self-contained, so a generated tree can be opened from disk, a
 * static host or `mogdoc serve` without a single extra request.
 *
 * `$siteTitle` is the banner heading — the package and version the tree
 * documents, so a page names what it is instead of the tool that wrote it.
 */
function docsPageShell(
    string $title,
    string $body,
    string $extraHead = '',
    string $extraScript = '',
    bool $homeLink = false,
    string $siteTitle = '',
): string {
    $css = docsStylesheet();
    $year = date('Y');
    $titleEsc = escapeHtml($title);
    $siteEsc = escapeHtml($siteTitle === '' ? 'Moggi API Documentation' : $siteTitle);
    $home = $homeLink
        ? '<p class="docs-home"><a href="index.html">Moggi Index</a></p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$titleEsc}</title>
  <style>{$css}</style>
  {$extraHead}
</head>
<body>
  <div class="docs-banner">
    <h1><a href="index.html">{$siteEsc}</a></h1>
    <p class="tagline">Search by name and type, or browse modules below</p>
    <p class="docs-nav"><a href="https://moggi-lang.org/">moggi-lang.org</a> | <a href="https://registry.moggi-lang.org/">packages</a> | <a href="https://github.com/moggi-lang/moggi">source</a></p>
  </div>
  {$home}
  {$body}
  <div class="docs-footer">Generated by mogdoc · {$year}</div>
{$extraScript}
</body>
</html>
HTML;
}
