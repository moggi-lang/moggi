<?php declare(strict_types=1);

namespace Moggi\Dist;

require_once __DIR__ . '/manifest.php';

/**
 * Rewrite the release-dependent parts of the website's `index.html` from a
 * release tag, so publishing a release is a command and not a hand edit of 25
 * links.
 *
 *   php packaging/website.php --tag 0.1.0-alpha [--file ../website/index.html]
 *                             [--check] [--upstream https://github.com/moggi-lang/moggi]
 *
 * The page names the release in several places and they are all rewritten
 * together: the `RELEASE` constant, the rows between the `archives:begin` and
 * `archives:end` markers, the archive count in the summary, and the default
 * download link and command. The archive names come from `archiveName`, the same
 * function the assembler uses, and the variants and targets come from
 * `dist/runtimes.json`, so the page cannot name an archive the build does not
 * produce. `--check` reports an out-of-date page without writing it.
 */

function websiteUsage(): int
{
    \fwrite(STDOUT, "usage: php packaging/website.php --tag <release> [--file <index.html>] [--check] [--upstream <url>]\n");

    return 0;
}

/** @return array{tag: string, file: string, check: bool, upstream: string} */
function parseWebsiteArgv(array $argv): array
{
    $tag = '';
    $file = \dirname(__DIR__) . '/../website/index.html';
    $check = false;
    $upstream = 'https://github.com/moggi-lang/moggi';

    for ($i = 1; $i < \count($argv); ++$i) {
        $arg = $argv[$i];
        if ($arg === '--tag') {
            $tag = (string) ($argv[++$i] ?? '');
        } elseif ($arg === '--file') {
            $file = (string) ($argv[++$i] ?? '');
        } elseif ($arg === '--upstream') {
            $upstream = \rtrim((string) ($argv[++$i] ?? ''), '/');
        } elseif ($arg === '--check') {
            $check = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            exit(websiteUsage());
        } else {
            \fwrite(STDERR, "error: unknown option {$arg}\n");

            exit(1);
        }
    }

    return ['tag' => $tag, 'file' => $file, 'check' => $check, 'upstream' => $upstream];
}

/**
 * The platform options the page already lists, in order: `target => label`.
 *
 * The page owns the wording, so a generated table reads exactly like a hand one.
 *
 * @return array<string, string>
 */
function platformLabels(string $html): array
{
    if (\preg_match('/<select id="platform">(.*?)<\/select>/s', $html, $select) !== 1) {
        throw new \RuntimeException('the page has no platform select to read the labels from');
    }
    \preg_match_all('/<option value="([^"]+)">([^<]+)<\/option>/', $select[1], $matches, \PREG_SET_ORDER);
    $labels = [];
    foreach ($matches as $match) {
        $labels[$match[1]] = $match[2];
    }
    if ($labels === []) {
        throw new \RuntimeException('the platform select lists no options');
    }

    return $labels;
}

/**
 * The `archiveName()` helper names the file; this is the `<tr>` that links it.
 *
 * @param array<string, string> $labels
 */
function archiveRow(string $variant, string $target, string $label, string $tag, string $upstream): string
{
    $name = archiveName($variant, $tag, $target);

    return "  <tr><td><code>{$variant}</code></td><td>{$label}</td>"
        . "<td><a href=\"{$upstream}/releases/download/{$tag}/{$name}\">{$name}</a></td></tr>";
}

/** One row per variant and target, blank line between variants, matching the page's layout. */
function archivesTable(array $config, string $tag, string $upstream, array $labels): string
{
    $groups = [];
    foreach (\array_keys($config['variants']) as $variant) {
        $rows = [];
        foreach ($labels as $target => $label) {
            $rows[] = archiveRow((string) $variant, (string) $target, $label, $tag, $upstream);
        }
        $groups[] = \implode("\n", $rows);
    }

    return \implode("\n\n", $groups);
}

/**
 * Replace the single match of `$pattern`, or fail.
 *
 * `preg_replace_callback` is used even for a literal replacement so `$` and `\`
 * in the replacement text (the download command's, especially) are never read as
 * backreferences.
 *
 * @param callable(array<int, string>): string|string $replacement
 */
function replaceOnce(string $html, string $pattern, callable|string $replacement): string
{
    $count = 0;
    $result = \preg_replace_callback(
        $pattern,
        static fn (array $match): string => \is_callable($replacement) ? $replacement($match) : $replacement,
        $html,
        -1,
        $count,
    );
    if (!\is_string($result) || $count !== 1) {
        throw new \RuntimeException("the page did not match exactly once: {$pattern}");
    }

    return $result;
}

/**
 * The whole rewrite, pure so `--check` and a write share it.
 *
 * @param array<string, string> $labels
 */
function rewriteWebsite(string $html, array $config, string $tag, string $upstream, array $labels): string
{
    $configTargets = \array_keys($config['targets']);
    $pageTargets = \array_keys($labels);
    \sort($configTargets);
    \sort($pageTargets);
    if ($configTargets !== $pageTargets) {
        throw new \RuntimeException(
            'the page and dist/runtimes.json list different platforms — page: '
            . \implode(', ', $pageTargets) . '; config: ' . \implode(', ', $configTargets),
        );
    }

    $count = \count($labels) * \count($config['variants']);
    $variant = (string) \array_key_first($config['variants']);
    $target = (string) \array_key_first($labels);
    $name = archiveName($variant, $tag, $target);
    $url = "{$upstream}/releases/download/{$tag}/{$name}";
    $steps = \str_starts_with($target, 'windows-')
        ? "\$ curl.exe -LO {$url}\n\$ Expand-Archive {$name} -DestinationPath .\n\$ cd {$variant}\n\$ .\\bin\\moggi.exe version"
        : "\$ curl -LO {$url}\n\$ tar -xzf {$name}\n\$ cd {$variant} && ./bin/moggi version";

    $html = replaceOnce(
        $html,
        "/  <!-- archives:begin -->\n.*?  <!-- archives:end -->/s",
        "  <!-- archives:begin -->\n" . archivesTable($config, $tag, $upstream, $labels) . "\n  <!-- archives:end -->",
    );
    $html = replaceOnce($html, '/<summary>All \d+ archives<\/summary>/', "<summary>All {$count} archives</summary>");
    $html = replaceOnce($html, "/var RELEASE = '[^']*';/", "var RELEASE = '{$tag}';");
    $html = replaceOnce(
        $html,
        '/<a id="download-link" href="[^"]*">Download <span id="download-name">[^<]*<\/span><\/a>/',
        "<a id=\"download-link\" href=\"{$url}\">Download <span id=\"download-name\">{$name}</span></a>",
    );

    return replaceOnce(
        $html,
        '/(<code id="download-steps">).*?(<\/code>)/s',
        static fn (array $match): string => $match[1] . \htmlspecialchars($steps, \ENT_NOQUOTES) . $match[2],
    );
}

function main(array $argv): int
{
    $options = parseWebsiteArgv($argv);
    if ($options['tag'] === '') {
        \fwrite(STDERR, "error: --tag is required\n");

        return 2;
    }
    if (!\is_file($options['file'])) {
        throw new \RuntimeException("no such page: {$options['file']}");
    }

    $html = (string) \file_get_contents($options['file']);
    $config = loadRuntimeConfig();
    $rewritten = rewriteWebsite($html, $config, $options['tag'], $options['upstream'], platformLabels($html));

    if ($rewritten === $html) {
        \fwrite(STDOUT, "website already names {$options['tag']}\n");

        return 0;
    }
    if ($options['check']) {
        \fwrite(STDERR, "website does not name {$options['tag']} — run without --check to rewrite it\n");

        return 1;
    }

    \file_put_contents($options['file'], $rewritten);
    \fwrite(STDOUT, "website rewritten for {$options['tag']}\n");

    return 0;
}

if (\realpath($argv[0] ?? '') === \realpath(__FILE__)) {
    try {
        exit(main($argv));
    } catch (\Throwable $e) {
        \fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        exit(1);
    }
}
