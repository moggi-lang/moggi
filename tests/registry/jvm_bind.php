#!/usr/bin/env php
<?php declare(strict_types=1);

// Bindings Phase 2: `moggi bind jvm:com.acme:hello` reads the fixture jar and
// writes the `Maven.Com.Acme.Hello` module, which then compiles and runs on
// the JVM backend. One targeted test only: the inspector/writer units, the
// golden render, the CLI surface (`--print`, `--out`, the dotnet refusal), the
// round-trip self-check, and the emit-cache discriminator in the bind cache
// key. Needs a JDK (`javac` for the fixture, `javap` for the binder, `java`
// for the run) — resolved from JAVA_HOME, then PATH, then beside `java`.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';
require $root . '/tests/suite/support/assert.php';

use Moggi\Backend\Jvm\JvmBackend;

use function Moggi\Cache\runtimeCacheDir;
use function Moggi\Registry\Bind\bindCacheKey;
use function Moggi\Registry\Bind\inspectJvmTree;
use function Moggi\Registry\Bind\jvmBindModuleName;
use function Moggi\Registry\Bind\parseJvmCoordinate;
use function Moggi\Registry\Bind\renderBinding;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$javac = resolveJdkBinary('javac');
$assert($javac !== null, 'no `javac` found (JAVA_HOME, PATH, or beside `java`) — the JVM binder needs a JDK');
$jar = \dirname((string) $javac) . \DIRECTORY_SEPARATOR . 'jar';
if (\PHP_OS_FAMILY === 'Windows' && ! \is_file($jar) && \is_file($jar . '.exe')) {
    $jar .= '.exe';
}
$assert(\is_file($jar) && \is_executable($jar), "no `jar` beside `{$javac}`");

$work = createTempDir('moggi-jvm-bind');
$fixture = $root . '/tests/registry/bind/fixtures/acme-hello-jvm';
$golden = (string) \file_get_contents($root . '/tests/registry/bind/Maven.Com.Acme.Hello.mog.expected');

// The coordinate shape, and the module it names.
$parts = parseJvmCoordinate('com.acme:hello:1.0.0');
$assert(
    $parts === ['group' => 'com.acme', 'artifact' => 'hello', 'version' => '1.0.0'],
    'coordinate misparsed: ' . \json_encode($parts),
);
$assert(
    jvmBindModuleName('com.acme', 'hello') === 'Maven.Com.Acme.Hello',
    'module is ' . jvmBindModuleName('com.acme', 'hello'),
);
foreach (['hello', 'com.acme:', ':hello', 'a:b:c:d'] as $bad) {
    try {
        parseJvmCoordinate($bad);
        $assert(false, "`{$bad}` parsed as a Maven coordinate");
    } catch (\RuntimeException) {
    }
}

// The fixture tree: sources compiled into a jar under `jvm/`, the discovery
// shape the backend itself scans.
$tree = $work . '/tree';
$compileFixture = static function () use ($javac, $jar, $fixture, $tree, $assert): void {
    foreach (['classes', 'jvm'] as $dir) {
        if (! \is_dir($tree . '/' . $dir) && ! @\mkdir($tree . '/' . $dir, 0777, true)) {
            $assert(false, 'cannot create ' . $tree . '/' . $dir);
        }
    }
    $sources = [];
    $scan = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($fixture . '/src', \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($scan as $file) {
        if ($file->isFile() && \str_ends_with($file->getFilename(), '.java')) {
            $sources[] = $file->getPathname();
        }
    }
    \sort($sources);
    $assert($sources !== [], 'no fixture sources under ' . $fixture . '/src');
    $javacResult = runCompiledProcess(
        [$javac, '-d', $tree . '/classes', ...$sources],
        120,
        \getenv(),
        $fixture,
    );
    $assert(
        ($javacResult['exitCode'] ?? 1) === 0,
        'fixture javac failed: ' . ($javacResult['stderr'] ?? '') . ($javacResult['stdout'] ?? ''),
    );
    $jarResult = runCompiledProcess(
        [$jar, '--create', '--file', $tree . '/jvm/hello-1.0.0.jar', '-C', $tree . '/classes', '.'],
        120,
        \getenv(),
        $fixture,
    );
    $assert(
        ($jarResult['exitCode'] ?? 1) === 0,
        'fixture jar failed: ' . ($jarResult['stderr'] ?? '') . ($jarResult['stdout'] ?? ''),
    );
};
$compileFixture();

// The inspector: the fixture's classes, and only the private field skipped.
$descriptor = inspectJvmTree($tree, 'com.acme', 'hello');
$assert($descriptor['module'] === 'Maven.Com.Acme.Hello', 'module is ' . $descriptor['module']);
$assert(
    \array_column($descriptor['types'], 'moggi') === ['Greeter', 'GreeterService'],
    'types: ' . \json_encode($descriptor['types']),
);
$assert(
    \array_column($descriptor['functions'], 'moggi') === [
        'greeterGreet',
        'greeterGreetWith',
        'greeterLoadGreeting',
        'greeterServe',
        'greeterService',
        'greeterServiceServe',
        'newGreeter',
    ],
    'functions: ' . \json_encode($descriptor['functions']),
);
$assert(
    \array_column($descriptor['consts'], 'moggi') === ['greeterVersion'],
    'consts: ' . \json_encode($descriptor['consts']),
);
$assert(
    $descriptor['skipped'] === [
        ['symbol' => 'com.acme.hello.Greeter.prefix', 'reason' => 'only public static final fields bind'],
    ],
    'skipped: ' . \json_encode($descriptor['skipped']),
);
// The interface carries its prefix (what retires the hardcoded list), and the
// throwing method surfaces as IO while the pure ones stay pure.
$byName = [];
foreach ([...$descriptor['types'], ...$descriptor['functions'], ...$descriptor['consts']] as $entry) {
    $byName[$entry['moggi']] = $entry;
}
$assert(
    ($byName['GreeterService']['path'] ?? '') === 'interface com.acme.hello.GreeterService',
    'interface prefix: ' . \json_encode($byName['GreeterService'] ?? null),
);
$assert(
    ($byName['greeterLoadGreeting']['sig'] ?? '') === 'String -> IO String',
    'throws: ' . ($byName['greeterLoadGreeting']['sig'] ?? ''),
);
$assert(
    ($byName['greeterGreet']['sig'] ?? '') === 'String -> String',
    'pure: ' . ($byName['greeterGreet']['sig'] ?? ''),
);
$assert(
    ($byName['newGreeter']['sig'] ?? '') === 'String -> Greeter',
    'constructor: ' . ($byName['newGreeter']['sig'] ?? ''),
);
// The round-trip self-check fired on nothing: no skip names a mismatch.
foreach ($descriptor['skipped'] as $skip) {
    $assert(! \str_contains($skip['reason'], 'round-trip'), 'round-trip mismatch: ' . \json_encode($skip));
}

// The render is the golden, byte for byte.
$rendered = renderBinding($descriptor);
$assert(normalize($rendered) === normalize($golden), "render differs from golden:\n" . diffText(normalize($golden), normalize($rendered)));

// The bind cache key folds the JVM emit discriminator in.
$key = bindCacheKey('jvm', 'com.acme:hello');
$discriminator = (new JvmBackend())->emitCacheDiscriminator();
$assert(
    $key === runtimeCacheDir() . '/jvm/bind-com-acme-hello-' . ($discriminator !== '' ? $discriminator : 'plain'),
    'cache key is ' . $key,
);
$assert(bindCacheKey('jvm', 'com.acme:hello') === $key, 'cache key is not deterministic');
$assert(bindCacheKey('php', 'com.acme:hello') !== $key, 'cache key ignores the backend');

// The CLI: `--print` renders the golden, and an unknown backend is refused
// (`dotnet:` binds since Phase 3, see dotnet_bind_test.php).
$env = \getenv();
$env['MOGGI_CACHE_DIR'] = $work . '/cache';
seedCacheDir($env['MOGGI_CACHE_DIR']);
$moggi = static fn (array $args, int $timeout = 120): array => runCompiledProcess(
    [PHP_BINARY, $root . '/moggi.php', ...$args],
    $timeout,
    $env,
    $root,
);

$printed = $moggi(['bind', 'jvm:com.acme:hello', '--tree', $tree, '--print']);
$assert(($printed['exitCode'] ?? 1) === 0, 'bind --print failed: ' . ($printed['stderr'] ?? ''));
$assert(normalize((string) ($printed['stdout'] ?? '')) === normalize($golden), "bind --print differs:\n" . diffText(normalize($golden), normalize((string) ($printed['stdout'] ?? ''))));

$refused = $moggi(['bind', 'go:github.com/acme/hello', '--tree', $tree]);
$assert(($refused['exitCode'] ?? 0) !== 0, 'a go: coordinate bound');
$assert(\str_contains((string) ($refused['stderr'] ?? ''), 'only `php:`, `jvm:` and `dotnet:`'), 'refusal says: ' . ($refused['stderr'] ?? ''));

// End to end: bind to a directory, compile a program against it, run it on
// the JVM backend. The tree rides along as a library root, so the verifier
// reads its jars and the artifact merges them — including the interface call,
// which emits `invokeinterface` from the generated prefix alone.
$gen = $work . '/gen';
$written = $moggi(['bind', 'jvm:com.acme:hello', '--tree', $tree, '--out', $gen]);
$assert(($written['exitCode'] ?? 1) === 0, 'bind --out failed: ' . ($written['stderr'] ?? ''));
$assert(
    \is_file($gen . '/Maven/Com/Acme/Hello.mog'),
    'binding not written under the module/file law',
);

\file_put_contents($work . '/Main.mog', <<<'MOG'
module Main where

import Data.Int
import Maven.Com.Acme.Hello (greeterGreet, greeterGreetWith, greeterService, greeterServiceServe, greeterVersion, newGreeter)
import System.IO (putStrLn)

main :: IO ()
main = do
  putStrLn (greeterGreet "Moggi")
  putStrLn (greeterServiceServe (greeterService "Hi") "Moggi")
  putStrLn (greeterGreetWith (newGreeter "Yo") "Mo" (2 :: Int32))
  putStrLn greeterVersion
MOG . "\n");

$run = $moggi(['run', $work . '/Main.mog', '--backend', 'jvm', '--lib', $gen, '--lib', $tree], 300);
$assert(($run['exitCode'] ?? 1) === 0, 'run failed: ' . ($run['stderr'] ?? ''));
$expected = "Hello, Moggi!\nHi: Moggi\nYo, Mo!Yo, Mo!\n1.0.0\n";
$assert(\str_ends_with((string) ($run['stdout'] ?? ''), $expected), 'run printed: ' . ($run['stdout'] ?? '') . "\nstderr: " . ($run['stderr'] ?? ''));

// The retired hardcoded interface list: the stdlib's own `Path`/`Iterable`/
// `Iterator` instance calls must still emit `invokeinterface`, now from the
// declared `interface ` prefixes alone. Read it off the bytecode.
\file_put_contents($work . '/Probe.mog', <<<'MOG'
module Probe where

import System.Directory (listDirectory)
import System.IO (putStrLn)

main :: IO ()
main = do
  entries <- listDirectory "/tmp"
  putStrLn "listed"
MOG . "\n");
$unpacked = $moggi(['compile', $work . '/Probe.mog', '--backend', 'jvm', '-o', $work . '/uout', '--unpacked'], 300);
$assert(($unpacked['exitCode'] ?? 1) === 0, 'probe compile failed: ' . ($unpacked['stderr'] ?? ''));
$dirClass = null;
$walk = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($work . '/uout', \FilesystemIterator::SKIP_DOTS),
);
foreach ($walk as $file) {
    // The pathname carries `\` on Windows; compare `/`-spelled, the way the
    // dotnet bind test already does with `DIRECTORY_SEPARATOR`.
    $spelling = \str_replace('\\', '/', $file->getPathname());
    if ($file->isFile() && \str_ends_with($spelling, 'Directory/JVM.class')) {
        $dirClass = $spelling;

        break;
    }
}
$assert($dirClass !== null, 'no Directory/JVM.class in unpacked output');
$javap = resolveJdkBinary('javap');
$assert($javap !== null, 'no `javap` found');
$uout = \str_replace('\\', '/', $work . '/uout/');
$relative = (string) \substr((string) $dirClass, \strlen($uout));
$dotted = \str_replace('/', '.', (string) \substr($relative, 0, -6));
$disassembled = runCompiledProcess(
    [$javap, '-c', '-p', '-classpath', $work . '/uout', $dotted],
    120,
    \getenv(),
    $root,
);
$assert(($disassembled['exitCode'] ?? 1) === 0, 'javap -c failed: ' . ($disassembled['stderr'] ?? ''));
$bytecode = (string) ($disassembled['stdout'] ?? '');
foreach (['Path.toString', 'Path.getFileName', 'Iterable.iterator', 'Iterator.hasNext'] as $member) {
    $quoted = \preg_quote($member, '/');
    $assert(
        \preg_match('/invokeinterface\s+[#\\d,\\s]+\/\/\s*InterfaceMethod\s+\\S*' . $quoted . '/', $bytecode) === 1,
        "no invokeinterface for {$member}",
    );
    $assert(
        \preg_match('/invokevirtual\s+[#\\d,\\s]+\/\/\s*Method\s+\\S*' . $quoted . '/', $bytecode) !== 1,
        "invokevirtual emitted for interface member {$member}",
    );
}

removeDirectory($work);

echo "jvm bind tests passed ({$checks} checks)\n";
