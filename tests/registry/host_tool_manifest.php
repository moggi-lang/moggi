#!/usr/bin/env php
<?php declare(strict_types=1);

// Step 2 (audit WS1): coordinates are validated before a tool sees them, and
// the manifests are quote-safe even for input that gets that far. A hostile
// coordinate is refused with its own diagnostic; a hostile *string* handed
// straight to a manifest writer still parses back to itself.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\Registry\FetchLog;

use function Moggi\Registry\downloadPath;
use function Moggi\Registry\fetchBlob;
use function Moggi\Registry\hostToolCoordinateProblem;
use function Moggi\Registry\hostToolCsproj;
use function Moggi\Registry\hostToolManifest;
use function Moggi\Registry\hostToolPom;
use function Moggi\Registry\resolveHostTools;
use function Moggi\Registry\safeDigestKey;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};
$refuses = static function (callable $call, string $needle, string $message) use (&$checks): void {
    ++$checks;
    try {
        $call();
    } catch (\RuntimeException $error) {
        if (!\str_contains($error->getMessage(), $needle)) {
            throw new \RuntimeException("{$message}: refused with an unexpected message: {$error->getMessage()}");
        }

        return;
    }
    throw new \RuntimeException("{$message}: was not refused");
};

$work = createTempDir('moggi-host-manifest');
\putenv('MOGGI_CACHE_DIR=' . $work . '/cache');
\putenv('MOGGI_USER_CACHE=' . $work . '/user-cache');

try {
    // --- the manifest survives a hostile string ------------------------------
    $hostile = 'a"b<c>d&e\'f';
    $csproj = hostToolCsproj([$hostile], 'app');
    $parsed = @\simplexml_load_string($csproj);
    $assert($parsed !== false, 'a hostile csproj must still parse: ' . $csproj);
    $assert(
        (string) $parsed->ItemGroup->PackageReference['Include'] === $hostile,
        'the parsed Include must equal the raw coordinate: ' . $csproj,
    );

    $pom = hostToolPom(["{$hostile}:{$hostile}:{$hostile}"], 'app');
    $parsedPom = @\simplexml_load_string($pom);
    $assert($parsedPom !== false, 'a hostile POM must still parse: ' . $pom);
    $dependency = $parsedPom->dependencies->dependency;
    $assert(
        (string) $dependency->groupId === $hostile
            && (string) $dependency->artifactId === $hostile
            && (string) $dependency->version === $hostile,
        'the parsed POM parts must equal the raw coordinate: ' . $pom,
    );

    // The step-1 pin, fixed: the quote no longer breaks out of the attribute.
    $injected = hostToolCsproj(['evil" Foo="bar:1.0'], 'app');
    $assert(
        \str_contains($injected, 'Include="evil&quot; Foo=&quot;bar"'),
        'a quote must be escaped, not emitted: ' . $injected,
    );
    $parsedInjected = @\simplexml_load_string($injected);
    $assert($parsedInjected !== false, 'the escaped csproj must parse: ' . $injected);
    $assert(
        (string) $parsedInjected->ItemGroup->PackageReference['Include'] === 'evil" Foo="bar',
        'the parsed Include must equal the raw id: ' . $injected,
    );

    // --- coordinates are validated before a tool sees them -------------------
    foreach ([
        ['php', 'monolog/monolog:^3.0'],
        ['php', 'guzzlehttp/guzzle:>=7.0 <8.0'],
        ['php', 'foo/bar'],
        ['jvm', 'org.slf4j:slf4j-api:2.0.16'],
        ['jvm', 'com.fasterxml.jackson.core:jackson-core:2.17.3'],
        ['dotnet', 'Serilog:4.1.0'],
        ['dotnet', 'System.Text.Json:8.0.0'],
        ['dotnet', 'Newtonsoft.Json'],
    ] as [$backend, $coordinate]) {
        $assert(
            hostToolCoordinateProblem($backend, $coordinate) === null,
            "`{$coordinate}` must be a valid [{$backend}] coordinate",
        );
    }

    foreach ([
        // [backend, coordinate, needle in the refusal]
        ['php', '', 'names no coordinate'],
        ['php', 'monolog', 'vendor/package'],
        ['php', 'monolog/monolog/extra:^1.0', 'vendor/package'],
        ['php', 'Monolog/Monolog:^3.0', 'lowercase'],
        ['php', 'monolog/monolog:', 'empty version constraint'],
        ['php', 'ev"il/pkg:1.0', 'must not hold `"'],
        ['php', "a/b:1.0\nX", 'control characters'],
        ['php', '-vendor/pkg:1.0', 'must not start with `-`'],
        ['jvm', 'org.slf4j:slf4j-api', 'group:artifact:version'],
        ['jvm', 'org.slf4j:slf4j-api:', 'group:artifact:version'],
        ['jvm', ':slf4j-api:2.0.16', 'names no package'],
        ['jvm', 'org:art:1.0:extra', 'group:artifact:version'],
        ['jvm', 'or g:art:1.0', 'whitespace'],
        ['jvm', 'org:ar<t:1.0', 'must not hold `<`'],
        ['dotnet', '', 'names no coordinate'],
        ['dotnet', 'a:b:c', 'id[:version]'],
        ['dotnet', 'Serilog:', 'empty version'],
        ['dotnet', '../evil:1.0', 'id[:version]'],
        ['dotnet', 'a&b:1.0', 'must not hold `&`'],
        ['php', "mono\x00log/monolog:1.0", 'control characters'],
    ] as [$backend, $coordinate, $needle]) {
        $problem = hostToolCoordinateProblem($backend, $coordinate);
        $assert(
            $problem !== null && \str_contains($problem, $needle),
            "`{$coordinate}` must be refused mentioning `{$needle}`: " . \var_export($problem, true),
        );
    }

    // A manifest for a hostile coordinate is refused, not written.
    $refuses(
        static fn () => hostToolManifest('dotnet', ['evil" Foo="bar:1.0'], 'app'),
        'whitespace',
        'a hostile coordinate must not reach the manifest',
    );

    // --- no tool is invoked for a malformed coordinate -----------------------
    if (\PHP_OS_FAMILY !== 'Windows') {
        $binDir = $work . '/fake-bin';
        \mkdir($binDir, 0777, true);
        $sentinel = $work . '/mvn-ran';
        \file_put_contents($binDir . '/mvn', "#!/bin/sh\ntouch " . \escapeshellarg($sentinel) . "\necho fake-mvn\n");
        \chmod($binDir . '/mvn', 0755);

        $savedPath = (string) \getenv('PATH');
        \putenv('PATH=' . $binDir . ':' . $savedPath);
        try {
            $refuses(
                static fn () => resolveHostTools('jvm', ['or g:art:1.0'], 'app'),
                'whitespace',
                'a malformed coordinate must be refused with its own diagnostic',
            );
        } finally {
            \putenv('PATH=' . $savedPath);
        }
        $assert(!\file_exists($sentinel), 'the tool must not have run for a malformed coordinate');
    }

    // --- digests are validated where they become paths -----------------------
    $assert(safeDigestKey('sha256:' . \str_repeat('a', 64)) === \str_repeat('a', 64), 'a good digest gives its hex');
    $assert(safeDigestKey('sha256:../../../tmp/x') === null, 'a climbing digest gives nothing');
    $assert(safeDigestKey('md5:' . \str_repeat('a', 32)) === null, 'a foreign scheme gives nothing');
    $assert(safeDigestKey('sha256:xyz') === null, 'a short digest gives nothing');

    $refuses(
        static fn () => downloadPath('sha256:../../../tmp/x'),
        'sha256:<hex>',
        'a hostile digest must not reach the path',
    );
    $assert(
        downloadPath('sha256:' . \str_repeat('b', 64)) === $work . '/user-cache/downloads/' . \str_repeat('b', 64),
        'a good digest still maps into downloads/',
    );

    $fetched = fetchBlob('https://reg.example.org', 'json', '1.0.0', ['blob' => 'sha256:../../../tmp/x'], null, new FetchLog());
    $assert(
        $fetched['path'] === null && \str_contains((string) $fetched['problem'], 'its digest is not sha256:<hex>'),
        'a malformed blob digest is a fetch problem, not a path: ' . \var_export($fetched['problem'], true),
    );
} finally {
    removeDirectory($work);
}

\fwrite(STDOUT, "host-tool-manifest: {$checks} checks passed\n");
