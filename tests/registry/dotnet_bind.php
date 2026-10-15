#!/usr/bin/env php
<?php declare(strict_types=1);

// Bindings Phase 3: `moggi bind dotnet:Acme.Hello` reads the fixture assembly
// and writes the `Nuget.Acme.Hello` module plus the versioned sidecar, which
// then compiles and runs on the .NET backend. One targeted test only: the
// inspector/writer units, the golden render, the golden sidecar, the CLI
// surface (`--print`, `--json`, `--out`), the round-trip self-check, the
// emit-cache discriminator in the bind cache key, and the run itself —
// including the optional-parameter tail the emitter pads from the sidecar.
// Needs a .NET 8 SDK (`DOTNET_ROOT` or `PATH`): `csc` builds the fixture
// without restore or network, the CLR inspector reads it the way the
// verifier does, and `dotnet build` assembles the program.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/assert.php';

use Moggi\Backend\DotNet\DotNetBackend;

use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Backend\DotNet\Verify\ensureInspectorBinary;
use function Moggi\Backend\DotNet\Verify\findDotNetSdkDir;
use function Moggi\Backend\DotNet\Verify\findFrameworkDir;
use function Moggi\Cache\runtimeCacheDir;
use function Moggi\Registry\Bind\bindCacheKey;
use function Moggi\Registry\Bind\dotNetBindModuleName;
use function Moggi\Registry\Bind\inspectDotNetTree;
use function Moggi\Registry\Bind\parseDotNetCoordinate;
use function Moggi\Registry\Bind\renderBinding;
use function Moggi\Registry\prettyJson;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$dotnet = resolveDotnetExecutable();
$assert($dotnet !== null, 'no `dotnet` found (DOTNET_ROOT or PATH) — the .NET binder needs a .NET 8 SDK');
$sdkDir = findDotNetSdkDir($dotnet);
$assert($sdkDir !== null, 'no .NET 8 SDK found — the fixture builds with its Roslyn');
$csc = $sdkDir . DIRECTORY_SEPARATOR . 'Roslyn' . DIRECTORY_SEPARATOR . 'bincore' . DIRECTORY_SEPARATOR . 'csc.dll';
$assert(\is_file($csc), 'no Roslyn `csc.dll` in ' . $sdkDir);
$frameworkDir = findFrameworkDir($dotnet);
$assert($frameworkDir !== null && \is_dir($frameworkDir), 'no .NET 8 shared framework found');
$inspector = ensureInspectorBinary($dotnet);
$assert($inspector !== null, 'the CLR inspector could not be built');

$work = createTempDir('moggi-dotnet-bind');
$fixture = $root . '/tests/registry/bind/fixtures/acme-hello-dotnet';
$golden = (string) \file_get_contents($root . '/tests/registry/bind/Nuget.Acme.Hello.mog.expected');
$goldenSidecar = (string) \file_get_contents($root . '/tests/registry/bind/Nuget.Acme.Hello.sidecar.json.expected');

// The coordinate shape, and the module it names.
$parts = parseDotNetCoordinate('Acme.Hello:1.0.0');
$assert(
    $parts === ['id' => 'Acme.Hello', 'version' => '1.0.0'],
    'coordinate misparsed: ' . \json_encode($parts),
);
$assert(
    parseDotNetCoordinate('Acme.Hello') === ['id' => 'Acme.Hello', 'version' => null],
    'a versionless coordinate must parse with a null version',
);
$assert(
    dotNetBindModuleName('Acme.Hello') === 'Nuget.Acme.Hello',
    'module is ' . dotNetBindModuleName('Acme.Hello'),
);
foreach (['', 'a:b:c', ':1.0', 'Acme.Hello:'] as $bad) {
    try {
        parseDotNetCoordinate($bad);
        $assert(false, "`{$bad}` parsed as a NuGet coordinate");
    } catch (\RuntimeException) {
    }
}

// The fixture tree: sources compiled by the SDK's own Roslyn — no project, no
// restore, no network — into `dotnet/Acme.Hello.dll`, the discovery shape the
// backend itself scans.
$tree = $work . '/tree';
$buildEnv = \getenv();
$buildEnv['DOTNET_ROOT'] = \dirname((string) $dotnet);
$buildEnv['DOTNET_NOLOGO'] = '1';
$buildEnv['DOTNET_CLI_TELEMETRY_OPTOUT'] = '1';
$buildEnv['DOTNET_SKIP_FIRST_TIME_EXPERIENCE'] = '1';
if (! \is_dir($tree . '/dotnet') && ! @\mkdir($tree . '/dotnet', 0777, true)) {
    $assert(false, 'cannot create ' . $tree . '/dotnet');
}
$sources = [];
$scan = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($fixture . '/src', \FilesystemIterator::SKIP_DOTS),
);
foreach ($scan as $file) {
    if ($file->isFile() && \str_ends_with($file->getFilename(), '.cs')) {
        $sources[] = $file->getPathname();
    }
}
\sort($sources);
$assert($sources !== [], 'no fixture sources under ' . $fixture . '/src');
$cscResult = runCompiledProcess(
    [
        $dotnet, $csc, '-nologo', '-deterministic', '-target:library', '-nostdlib', '-noconfig',
        '-reference:' . $frameworkDir . DIRECTORY_SEPARATOR . 'System.Private.CoreLib.dll',
        '-reference:' . $frameworkDir . DIRECTORY_SEPARATOR . 'System.Runtime.dll',
        '-out:' . $tree . '/dotnet/Acme.Hello.dll',
        ...$sources,
    ],
    300,
    $buildEnv,
    $fixture,
);
$assert(
    ($cscResult['exitCode'] ?? 1) === 0 && \is_file($tree . '/dotnet/Acme.Hello.dll'),
    'fixture csc failed: ' . ($cscResult['stderr'] ?? '') . ($cscResult['stdout'] ?? ''),
);

// The inspector: the fixture's two types, the seven functions (including the
// optional-tail declaration and the nominal-parameter round-trip), and the two
// consts — a readonly field through `ldsfld`, a static property through its
// getter.
$descriptor = inspectDotNetTree($tree, 'Acme.Hello', '1.0.0');
$assert($descriptor['module'] === 'Nuget.Acme.Hello', 'module is ' . $descriptor['module']);
$assert(
    \array_column($descriptor['types'], 'moggi') === ['Greeter', 'Greeting'],
    'types: ' . \json_encode($descriptor['types']),
);
$assert(
    \array_column($descriptor['functions'], 'moggi') === [
        'greeterCreate',
        'greeterDescribe',
        'greeterGreet',
        'greeterGreetWith',
        'greeterGreetWithOptional',
        'greetingFormat',
        'newGreeter',
    ],
    'functions: ' . \json_encode($descriptor['functions']),
);
$assert(
    \array_column($descriptor['consts'], 'moggi') === ['greeterTag', 'greeterVersion'],
    'consts: ' . \json_encode($descriptor['consts']),
);
$byName = [];
foreach ([...$descriptor['types'], ...$descriptor['functions'], ...$descriptor['consts']] as $entry) {
    $byName[$entry['moggi']] = $entry;
}
$assert(
    ($byName['greeterGreetWithOptional']['sig'] ?? '') === 'Greeter -> String -> String',
    'the optional method declares the required prefix: ' . ($byName['greeterGreetWithOptional']['sig'] ?? ''),
);
$assert(
    ($byName['greeterGreetWithOptional']['optionalTail'] ?? 0) === 1,
    'the optional tail is recorded for the sidecar',
);
$assert(
    ($byName['greeterDescribe']['sig'] ?? '') === 'Greeter -> String',
    'a nominal parameter survives: ' . ($byName['greeterDescribe']['sig'] ?? ''),
);
$assert(
    ($byName['newGreeter']['sig'] ?? '') === 'String -> Greeter',
    'constructor: ' . ($byName['newGreeter']['sig'] ?? ''),
);
// The coverage report: overload, unrepresentable parameter, C# const,
// property plumbing and inherited members each name their reason — and the
// round-trip self-check fired on nothing.
$skipReasons = [];
foreach ($descriptor['skipped'] as $skip) {
    $skipReasons[$skip['symbol'] . "\0" . $skip['reason']] = true;
    $assert($skip['reason'] !== '', 'a skip with no reason: ' . $skip['symbol']);
}
foreach ([
    "Acme.Hello.Greeter.Greet\0name collision on `greeterGreet` (an overload has no single Moggi spelling)",
    "Acme.Hello.Greeter.GreetLevel\0parameter type byte (no exact Moggi counterpart)",
    "Acme.Hello.Greeter:VersionMajor\0a C# const has no runtime field to load (write the value by hand)",
    "Acme.Hello.Greeter.get_Tag\0property plumbing and operators need hand-written bindings",
    "Acme.Hello.Greeter.ToString\0inherited from System.Object (only a type's own members bind)",
] as $expected) {
    $assert(isset($skipReasons[$expected]), 'no skip for ' . \str_replace("\0", ': ', $expected));
}
foreach ($descriptor['skipped'] as $skip) {
    $assert(! \str_contains($skip['reason'], 'round-trip'), 'round-trip mismatch: ' . \json_encode($skip));
}
$assert(\count($descriptor['skipped']) === 23, 'skipped: ' . \count($descriptor['skipped']));

// The sidecar is the point: value-typeness per type, the full IL list and the
// optional tail for the one member whose declaration cannot spell it, the
// bound version, and the digest of the dll it was read from.
$sidecar = $descriptor['sidecar'];
$assert(($sidecar['format'] ?? 0) === 1, 'sidecar format: ' . \json_encode($sidecar['format'] ?? null));
$assembly = $sidecar['assemblies']['Acme.Hello'] ?? null;
$assert(\is_array($assembly), 'no Acme.Hello sidecar entry');
$assert(
    ($assembly['types']['Acme.Hello.Greeter']['valueType'] ?? null) === false
    && ($assembly['types']['Acme.Hello.Greeting']['valueType'] ?? null) === true,
    'sidecar types: ' . \json_encode($assembly['types'] ?? null),
);
$assert(
    ($assembly['signatures']['greeterGreetWithOptional'] ?? null) === ['params' => ['string', 'int32'], 'optionalTail' => 1],
    'sidecar signatures: ' . \json_encode($assembly['signatures'] ?? null),
);
$assert(($assembly['version'] ?? null) === '1.0.0', 'sidecar version: ' . \json_encode($assembly['version'] ?? null));
$assert(
    \is_string($assembly['digest'] ?? null) && \str_starts_with($assembly['digest'], 'sha256:'),
    'sidecar digest: ' . \json_encode($assembly['digest'] ?? null),
);
$assert(
    \is_string($assembly['generated-by'] ?? null) && \str_starts_with($assembly['generated-by'], 'moggi bind '),
    'sidecar generated-by: ' . \json_encode($assembly['generated-by'] ?? null),
);

// The render is the golden, byte for byte — and the written sidecar is its
// golden once the per-build provenance (digest, generator version) is
// normalized.
$rendered = renderBinding($descriptor);
$assert(normalize($rendered) === normalize($golden), "render differs from golden:\n" . diffText(normalize($golden), normalize($rendered)));
$writtenSidecar = prettyJson($sidecar) . "\n";
$normalizedSidecar = (string) \preg_replace(
    ['/^(\s*"digest": "sha256:)[0-9a-f]+(")/m', '/^(\s*"generated-by": "moggi bind ).+(")/m'],
    ['$1<digest>$2', '$1<version>$2'],
    $writtenSidecar,
);
$assert(normalize($normalizedSidecar) === normalize($goldenSidecar), "sidecar differs from golden:\n" . diffText(normalize($goldenSidecar), normalize($normalizedSidecar)));

// The bind cache key folds the .NET emit discriminator in.
$key = bindCacheKey('dotnet', 'Acme.Hello:1.0.0');
$discriminator = (new DotNetBackend())->emitCacheDiscriminator();
$assert(
    $key === runtimeCacheDir() . '/dotnet/bind-acme-hello-1-0-0-' . ($discriminator !== '' ? $discriminator : 'plain'),
    'cache key is ' . $key,
);
$assert(bindCacheKey('dotnet', 'Acme.Hello:1.0.0') === $key, 'cache key is not deterministic');
$assert(bindCacheKey('php', 'Acme.Hello:1.0.0') !== $key, 'cache key ignores the backend');

// The CLI: `--print` renders the golden, `--json` carries the sidecar, `--out`
// writes the module and the sidecar beside it, and an unknown backend is refused.
$env = \getenv();
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
$env['DOTNET_ROOT'] = \dirname((string) $dotnet);
seedCacheDir($env['MOGGI_CACHE_DIR']);
$moggi = static fn (array $args, int $timeout = 300): array => runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', ...$args],
    $timeout,
    $env,
    $root,
);

$printed = $moggi(['bind', 'dotnet:Acme.Hello:1.0.0', '--tree', $tree, '--print']);
$assert(($printed['exitCode'] ?? 1) === 0, 'bind --print failed: ' . ($printed['stderr'] ?? ''));
$assert(normalize((string) ($printed['stdout'] ?? '')) === normalize($golden), "bind --print differs:\n" . diffText(normalize($golden), normalize((string) ($printed['stdout'] ?? ''))));

$asJson = $moggi(['bind', 'dotnet:Acme.Hello:1.0.0', '--tree', $tree, '--json']);
$assert(($asJson['exitCode'] ?? 1) === 0, 'bind --json failed: ' . ($asJson['stderr'] ?? ''));
$decoded = \json_decode((string) ($asJson['stdout'] ?? ''), true);
$assert(\is_array($decoded) && ($decoded['module'] ?? '') === 'Nuget.Acme.Hello', 'bind --json module');
$assert(
    ($decoded['sidecar']['assemblies']['Acme.Hello']['signatures']['greeterGreetWithOptional']['optionalTail'] ?? 0) === 1,
    'bind --json carries the sidecar',
);

$gen = $work . '/gen';
$written = $moggi(['bind', 'dotnet:Acme.Hello:1.0.0', '--tree', $tree, '--out', $gen]);
$assert(($written['exitCode'] ?? 1) === 0, 'bind --out failed: ' . ($written['stderr'] ?? ''));
$assert(
    \is_file($gen . '/Nuget/Acme/Hello.mog'),
    'binding not written under the module/file law',
);
$assert(
    \is_file($gen . '/Nuget/Acme/dotnet/dependencies.json'),
    'sidecar not written beside the module',
);
$assert(
    normalize((string) \file_get_contents($gen . '/Nuget/Acme/Hello.mog')) === normalize($golden),
    'written module differs from the golden',
);

$refused = $moggi(['bind', 'go:github.com/acme/hello', '--tree', $tree]);
$assert(($refused['exitCode'] ?? 0) !== 0, 'a go: coordinate bound');
$assert(\str_contains((string) ($refused['stderr'] ?? ''), 'only `php:`, `jvm:` and `dotnet:`'), 'refusal says: ' . ($refused['stderr'] ?? ''));

// End to end: compile a program against the binding and run it on the .NET
// backend. The tree rides along as a library root, so the verifier reads its
// assembly, the sidecar scopes the calls and pads the optional tail, and the
// packager stages the dll beside the app.
\file_put_contents($work . '/Main.mog', <<<'MOG'
module Main where

import Nuget.Acme.Hello (greeterDescribe, greeterGreet, greeterGreetWithOptional, greeterTag, greeterVersion, greetingFormat, newGreeter)
import System.IO (putStrLn)

main :: IO ()
main = do
  putStrLn (greeterGreet "Moggi")
  putStrLn (greeterGreetWithOptional (newGreeter "Yo") "Mo")
  putStrLn (greeterDescribe (newGreeter "Ac"))
  putStrLn (greetingFormat greeterTag)
  putStrLn greeterVersion
MOG . "\n");

$run = $moggi(['run', $work . '/Main.mog', '--backend', 'dotnet', '--lib', $gen, '--lib', $tree], 600);
$assert(($run['exitCode'] ?? 1) === 0, 'run failed: ' . ($run['stderr'] ?? ''));
$expected = "Hello, Moggi!\n\ngreeter(Ac)\n[hello]\n1.0.0\n";
$assert(\str_ends_with((string) ($run['stdout'] ?? ''), $expected), 'run printed: ' . ($run['stdout'] ?? '') . "\nstderr: " . ($run['stderr'] ?? ''));

// The emitted optional call is the full one: the sidecar's IL list names
// `(string, int32)` and the unpassed tail is padded with a zero.
$unpacked = $moggi(['compile', $work . '/Main.mog', '--backend', 'dotnet', '-o', $work . '/uout', '--unpacked', '--lib', $gen, '--lib', $tree], 600);
$assert(($unpacked['exitCode'] ?? 1) === 0, 'unpacked compile failed: ' . ($unpacked['stderr'] ?? ''));
$helloIl = null;
$walk = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($work . '/uout', \FilesystemIterator::SKIP_DOTS),
);
foreach ($walk as $file) {
    if ($file->isFile() && \str_ends_with($file->getPathname(), 'Nuget' . DIRECTORY_SEPARATOR . 'Acme' . DIRECTORY_SEPARATOR . 'Hello.il')) {
        $helloIl = (string) \file_get_contents($file->getPathname());

        break;
    }
}
$assert($helloIl !== null, 'no Nuget/Acme/Hello.il in unpacked output');
$assert(
    \str_contains($helloIl, 'GreetWithOptional(string, int32)'),
    'the optional call names the full IL list',
);
$assert(
    \str_contains($helloIl, 'ldc.i4.0'),
    'the unpassed tail is padded with a zero',
);

removeDirectory($work);

echo "dotnet bind tests passed ({$checks} checks)\n";
