<?php declare(strict_types=1);

namespace Moggi\Docs;

use function Moggi\Modules\canonicalPath;

/**
 * Resolve a static file under $docRoot for $requestPath.
 *
 * @return string|null Absolute path to serve, null to fall through to search, '' for 404
 */
function resolveStaticDocPath(string $docRoot, string $requestPath): ?string
{
    $staticPath = $requestPath === '/' ? '/index.html' : $requestPath;
    if ($staticPath === '/' || str_starts_with($staticPath, '/?')) {
        return null;
    }
    if (!str_contains(basename($staticPath), '.')) {
        return null;
    }
    // The file is resolved below, which follows symlinks, so the root has to be resolved the
    // same way before the two can be compared: macOS reaches its temporary directory through
    // `/var`, a symlink to `/private/var`.
    $root = canonicalPath($docRoot);
    $file = $root . $staticPath;
    $resolved = realpath($file);
    if ($resolved !== false && \is_file($resolved) && pathIsUnderDocRoot($resolved, $root)) {
        // The caller's spelling of the root, not the resolved one: everything it does with the
        // answer compares against the root it passed in.
        return rtrim(str_replace('\\', '/', $docRoot), '/') . $staticPath;
    }

    return '';
}

/**
 * Dev-only HTTP server: single-threaded PHP stream_socket_server.
 *
 * For production, run `moggi mogdoc lib -o public/api-doc` and serve the
 * generated directory as static files (nginx, Apache, S3, GitHub Pages, etc.).
 */
function serveDocs(DocIndex $index, int $port, ?string $docDir = null): int
{
    $addr = "127.0.0.1:{$port}";
    $socket = @stream_socket_server("tcp://{$addr}", $errno, $errstr);
    if ($socket === false) {
        \fwrite(STDERR, "error: cannot listen on {$addr}: {$errstr}\n");

        return 1;
    }

    $docRoot = $docDir !== null ? realpath($docDir) : null;
    \fwrite(STDOUT, "docs serving on http://{$addr}/\n");
    \fwrite(STDOUT, "docs: dev server only — for production, deploy static HTML from mogdoc -o\n");
    if ($docRoot !== false && $docRoot !== null) {
        \fwrite(STDOUT, "docs: static pages from {$docRoot}\n");
    }

    while (($conn = @stream_socket_accept($socket, -1)) !== false) {
        $req = '';
        while (!str_contains($req, "\r\n\r\n")) {
            $chunk = fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $req .= $chunk;
        }

        $path = '/';
        if (preg_match('/GET ([^\s?]+)/', $req, $pathMatch) === 1) {
            $path = urldecode($pathMatch[1]);
        }

        $q = '';
        if (preg_match('/GET [^\s?]+\?([^ \r]*)/', $req, $qm) === 1) {
            parse_str($qm[1], $params);
            $q = (string) ($params['q'] ?? '');
        }

        if ($docRoot !== false && $docRoot !== null) {
            $resolved = resolveStaticDocPath($docRoot, $path);
            if ($resolved === '') {
                $notFound = "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain; charset=utf-8\r\n"
                    . "Content-Length: 9\r\n\r\nNot Found";
                fwrite($conn, $notFound);
                fclose($conn);
                continue;
            }
            if ($resolved !== null) {
                $body = (string) file_get_contents($resolved);
                $ctype = match (true) {
                    str_ends_with($resolved, '.json') => 'application/json',
                    str_ends_with($resolved, '.css') => 'text/css',
                    str_ends_with($resolved, '.js') => 'application/javascript',
                    default => 'text/html',
                };
                $response = "HTTP/1.1 200 OK\r\nContent-Type: {$ctype}; charset=utf-8\r\n"
                    . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
                fwrite($conn, $response);
                fclose($conn);
                continue;
            }
        }

        $hits = search($index, $q, 30);
        $body = renderSearchPage($index, $q, $hits, $docRoot !== false ? $docRoot : null);
        $response = "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
        fwrite($conn, $response);
        fclose($conn);
    }

    fclose($socket);

    return 0;
}

/** @param list<SearchHit> $hits */
function renderSearchPage(DocIndex $index, string $query, array $hits, ?string $docRoot = null): string
{
    $qEsc = escapeHtml($query);
    $action = $docRoot !== null ? '/index.html' : '/';
    $form = '<h1 class="page-title">Moggi Index</h1>';
    $form .= '<p class="meta">' . count($index->byModule) . ' modules, ' . count($index->search) . ' entries</p>';
    $form .= '<div class="docs-search"><form method="get" action="' . escapeHtml($action) . '">';
    $form .= '<input name="q" type="search" value="' . $qEsc
        . '" size="60" autofocus> <button type="submit">Search</button></form></div>';

    $results = '<ul class="moogle-results">';
    foreach ($hits as $hit) {
        $e = $hit->entity;
        $sig = escapeHtml($e->signature ?? $e->kind . ' ' . $e->name);
        $href = $e->href;
        if ($docRoot !== null && !str_starts_with($href, 'http')) {
            $href = '/' . ltrim($href, '/');
        }
        $results .= '<li><a href="' . escapeHtml($href) . '"><code>'
            . escapeHtml($e->module . '.' . $e->name) . '</code></a><br>'
            . '<span class="sig">' . $sig . '</span>';
        if ($e->doc !== null && $e->doc !== '') {
            $snippet = docSnippet($e->doc, 120);
            $results .= '<br><span class="doc">' . escapeHtml($snippet) . '</span>';
        }
        $results .= '</li>';
    }
    $results .= '</ul>';

    if ($hits === [] && $query !== '') {
        $results .= '<p class="meta">No results.</p>';
    }

    return docsPageShell('Moggi Index', $form . $results);
}

function pathIsUnderDocRoot(string $path, string $root): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');

    return $path === $root || str_starts_with($path, $root . '/');
}
