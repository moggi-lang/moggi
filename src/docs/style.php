<?php declare(strict_types=1);

namespace Moggi\Docs;

/** Classic late-90s programming-language manual aesthetic. */
function docsStylesheet(): string
{
    return <<<'CSS'
body {
  margin: 0;
  padding: 0;
  background: #fffff0;
  color: #000;
  font-family: "Times New Roman", Times, serif;
  font-size: 16px;
  line-height: 1.45;
}
a { color: #0000cc; }
a:visited { color: #551a8b; }
a:hover { color: #cc0000; }
.docs-wrap {
  max-width: 52rem;
  margin: 0 auto;
  padding: 1rem 1.25rem 2.5rem;
}
.docs-banner {
  border: 2px solid #000;
  background: #e8e8d0;
  padding: 0.5rem 0.75rem;
  margin-bottom: 1rem;
}
.docs-banner h1 {
  margin: 0;
  font-size: 1.35rem;
  font-weight: bold;
}
.docs-banner .tagline {
  margin: 0.15rem 0 0;
  font-size: 0.9rem;
  color: #333;
}
.docs-banner h1 a {
  color: inherit;
  text-decoration: none;
}
.docs-banner h1 a:hover {
  color: #0000cc;
}
.docs-home {
  margin: 0 0 0.75rem;
  font-size: 0.95rem;
}
.docs-search input[type="search"],
.docs-search input[type="text"] {
  width: 100%;
  max-width: 36rem;
  font-family: "Courier New", Courier, monospace;
  font-size: 0.95rem;
  padding: 0.2rem 0.35rem;
  border: 2px inset #ccc;
  background: #fff;
  box-sizing: border-box;
}
.docs-search button {
  font-family: inherit;
  font-size: 0.9rem;
  margin-top: 0.35rem;
  padding: 0.15rem 0.6rem;
}
#mogdoc-hits, .moogle-results {
  list-style: none;
  padding: 0;
  margin: 0.75rem 0 0;
}
#mogdoc-hits li, .moogle-results li {
  margin: 0.55rem 0;
  padding: 0.25rem 0;
  border-bottom: 1px dotted #aaa;
}
#mogdoc-hits .sig, .moogle-results .sig {
  font-family: "Courier New", Courier, monospace;
  font-size: 0.88rem;
  color: #222;
}
h1.page-title {
  font-size: 1.5rem;
  border-bottom: 2px solid #000;
  padding-bottom: 0.2rem;
  margin: 0 0 0.75rem;
}
h2.section {
  margin: 1.75rem 0 0.5rem;
  font-size: 1.1rem;
  color: #000080;
  border-bottom: 1px solid #666;
}
.decl {
  margin: 1.25rem 0 1.5rem;
  padding: 0;
}
.decl-head {
  font-family: "Courier New", Courier, monospace;
  font-size: 0.95rem;
  font-weight: bold;
  background: #f0f0e0;
  border: 1px solid #999;
  padding: 0.35rem 0.5rem;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}
.decl-head-main {
  min-width: 0;
}
.decl-head.decl-signature-head .signature {
  flex: 1;
  min-width: 0;
}
.decl-head .signature {
  margin: 0;
  border: none;
  background: transparent;
  padding: 0;
  font-weight: bold;
}
.decl-head .kind {
  color: #000080;
  font-weight: bold;
}
.decl-body {
  border: 1px solid #bbb;
  border-top: none;
  padding: 0.5rem 0.75rem;
  background: #fff;
}
.decl-body .doc { margin-top: 0.5rem; }
.sub-decls {
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
}
.sub-decls li {
  margin: 0.35rem 0;
  padding-left: 1rem;
  border-left: 3px solid #ccc;
}
.sub-decls .sub-label {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #666;
  margin-right: 0.35rem;
}
.sub-label::after {
  content: '·';
  margin-left: 0.25rem;
  text-transform: none;
  letter-spacing: normal;
}
.sub-decls pre.signature {
  margin: 0.15rem 0 0;
}
.sub-decl-head,
.sub-decl-signature-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}
.sub-decl-signature-row .signature {
  flex: 1;
  min-width: 0;
  margin: 0;
}
.fixity-decl {
  margin: 0.25rem 0 0;
  color: var(--muted);
  font-family: var(--mono);
  font-size: 0.9rem;
}
.subsection {
  margin: 0.75rem 0 0.35rem;
  font-size: 0.95rem;
  font-weight: bold;
  color: #000080;
}
.ctor-table {
  width: 100%;
  border-collapse: collapse;
  margin: 0.25rem 0 0.5rem;
}
.ctor-table td {
  vertical-align: top;
  padding: 0.15rem 0.35rem 0.15rem 0;
}
.ctor-table .ctor-name {
  white-space: nowrap;
  width: 1%;
}
.instance-list {
  margin: 0.25rem 0 0.5rem;
  padding-left: 1.25rem;
}
.instance-list li {
  margin: 0.2rem 0;
}
.source-link {
  flex-shrink: 0;
  margin: 0;
  font-size: 0.85rem;
  font-weight: normal;
  text-align: right;
  white-space: nowrap;
}
.source-link a {
  font-family: "Courier New", Courier, monospace;
  color: #000080;
  text-decoration: none;
}
.source-link a:hover {
  text-decoration: underline;
}
.source-listing {
  font-family: "Courier New", Courier, monospace;
  font-size: 0.85rem;
  background: #f8f8f0;
  border: 1px solid #bbb;
  padding: 0.5rem;
  overflow-x: auto;
}
.source-listing .line-no {
  display: inline-block;
  width: 3rem;
  color: #888;
  user-select: none;
  text-align: right;
  margin-right: 0.75rem;
}
.source-listing .src-line:target {
  background: #ffffcc;
}
pre.signature {
  margin: 0.35rem 0;
  padding: 0.35rem 0.5rem;
  background: #f8f8f0;
  border: 1px solid #ccc;
  font-family: "Courier New", Courier, monospace;
  font-size: 0.9rem;
  overflow-x: auto;
}
code, .mono {
  font-family: "Courier New", Courier, monospace;
  font-size: 0.92em;
}
.meta, .aliases, .backends {
  color: #444;
  font-size: 0.9rem;
  margin: 0.5rem 0;
}
ul.module-list {
  columns: 2;
  column-gap: 2rem;
  padding-left: 1.25rem;
}
ul.module-list li { break-inside: avoid; }
.docs-footer {
  margin-top: 2rem;
  padding-top: 0.5rem;
  border-top: 1px solid #999;
  font-size: 0.85rem;
  color: #555;
}
CSS;
}

function docsPageShell(
    string $title,
    string $body,
    string $extraHead = '',
    string $extraScript = '',
    bool $homeLink = false,
): string {
    $css = docsStylesheet();
    $year = date('Y');
    $titleEsc = escapeHtml($title);
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
<div class="docs-wrap">
  <div class="docs-banner">
    <h1><a href="index.html">Moggi API Documentation</a></h1>
    <p class="tagline">Search by name and type, or browse modules below</p>
  </div>
  {$home}
  {$body}
  <div class="docs-footer">Generated by mogdoc · {$year}</div>
</div>
{$extraScript}
</body>
</html>
HTML;
}
