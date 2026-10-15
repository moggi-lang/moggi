#!/usr/bin/env php
<?php declare(strict_types=1);

// `moggi add` — the descriptor half of Sky's one command, and the half that needs
// no host toolchain, no binding generator and no registry.
//
// One verb, two tables: a bare name is a package of ours (`[dependencies]`), a
// `php:`/`jvm:`/`dotnet:` prefix is a host package in that ecosystem's vocabulary.
// The spelling decides, and *only* the spelling does — a name that is not a package
// name is refused with the prefix it was probably missing, because a silent guess
// between four tables is the expensive kind of convenience.
//
// The writer's whole contract is that a descriptor it produces is a descriptor the
// compiler's own reader accepts, so every edit here is checked twice: through
// `descriptorProblems()` (the format), and by reading the result back through
// `readDescriptor()` and asking the layer that consumes the table
// (`hostToolCoordinates()` for a host one), which is what `moggi build` asks.
//
// The CLI half runs with `--print`, which is offline by construction: `--print`
// fetches nothing, writes nothing, and — because the version a registry dependency
// records is the registry's answer — wants `--version`.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/process.php';
require $root . '/tests/suite/support/workspace.php';

use function Moggi\Registry\Bind\bindModuleName;
use function Moggi\Registry\Bind\facadeModuleName;
use function Moggi\Registry\Bind\renderFacade;
use function Moggi\Registry\descriptorAdditionProblems;
use function Moggi\Registry\descriptorProblems;
use function Moggi\Registry\descriptorWithCoordinate;
use function Moggi\Registry\descriptorWithFfiBinding;
use function Moggi\Registry\hostToolCoordinates;
use function Moggi\Registry\parseAddCoordinate;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\withAddConstraint;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$parse = static function (string $argument, ?string $version = null): array {
    try {
        return ['ok', parseAddCoordinate($argument, $version)];
    } catch (\RuntimeException $error) {
        return ['error', $error->getMessage()];
    }
};

$refuses = static function (string $argument, string $needle, ?string $version = null) use ($parse, $assert): void {
    [$status, $value] = $parse($argument, $version);
    $assert($status === 'error', "`{$argument}` is refused");
    $assert(\str_contains((string) $value, $needle), "`{$argument}` is refused for the right reason, said: {$value}");
};

$work = createTempDir('moggi-add');
$author = "[author]\nname = Test\nemail = test@example.com\n"
    . "npub = npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm\n";

try {
    // --- a bare name is a package of ours -------------------------------------

    [$status, $ours] = $parse('json');
    $assert($status === 'ok', 'a bare name parses');
    $assert($ours['kind'] === 'registry' && $ours['section'] === 'dependencies', 'a bare name is a `[dependencies]` entry');
    $assert($ours['key'] === 'json' && $ours['package'] === 'json', 'the package name is the key');
    $assert($ours['deferred'] === true, 'a name added without a constraint lets the registry decide it');

    [$status, $pinned] = $parse('json', '0.4');
    $assert($status === 'ok' && $pinned['value'] === '0.4' && $pinned['requires'] === '0.4', '--version is the constraint for a package of ours');
    $assert($pinned['deferred'] === false && $pinned['coordinate'] === 'json = 0.4', 'a constrained dependency carries it');

    [$status, $explicit] = $parse('moggi:json');
    $assert($status === 'ok' && $explicit['kind'] === 'registry', '`moggi:` says "ours" explicitly');

    // A name that would also read as a host coordinate stays ours. `newtonsoft.json` is a legal
    // package name *and* a plausible NuGet id, and the spelling decides the table — so it is a
    // `[dependencies]` entry, never a guessed `[dotnet.nuget]` one. This is the case a shape rule
    // would have split on a capital letter.
    [$status, $shaped] = $parse('newtonsoft.json', '13.0.3');
    $assert($status === 'ok' && $shaped['kind'] === 'registry', 'a bare id is a package of ours, whatever it looks like');
    $assert($shaped['section'] === 'dependencies' && $shaped['value'] === '13.0.3', 'a bare id goes to [dependencies] with its constraint: ' . \json_encode($shaped));

    [$status, $floor] = $parse('data.json', '>=1.0');
    $assert($status === 'ok' && $floor['value'] === '>=1.0', 'a comparison is a constraint, not just a bare version');

    $filled = withAddConstraint($ours, '0.4');
    $assert($filled['value'] === '0.4' && $filled['deferred'] === false, 'the registry answer fills the deferred constraint in');
    $assert($filled['coordinate'] === 'json = 0.4' && $filled['section'] === 'dependencies', 'filling it in keeps the table it belongs to');

    // --- a prefix is somebody else's ecosystem --------------------------------

    [$status, $jvm] = $parse('jvm:com.google.code.gson:gson:2.11.0');
    $assert($status === 'ok' && $jvm['kind'] === 'host', 'a jvm coordinate is a host package');
    $assert($jvm['section'] === 'jvm.maven' && $jvm['key'] === 'com.google.code.gson:gson', 'a jvm coordinate keys [jvm.maven] by group:artifact');
    $assert($jvm['value'] === '2.11.0' && $jvm['coordinate'] === 'com.google.code.gson:gson:2.11.0', 'a jvm coordinate carries its version');
    $assert($jvm['list'] === false && $jvm['deferred'] === false, 'a host coordinate is its own key, never deferred');

    [$status, $php] = $parse('php:monolog/monolog:^3.0');
    $assert($status === 'ok', 'a php coordinate parses');
    $assert($php['section'] === 'php' && $php['key'] === 'composer' && $php['list'] === true, 'a php coordinate is an entry of the `composer` list');
    $assert($php['package'] === 'monolog/monolog' && $php['requires'] === '^3.0', 'a php coordinate keeps its package and its constraint apart');

    [$status, $dotnet] = $parse('dotnet:Newtonsoft.Json');
    $assert($status === 'ok' && $dotnet['value'] === '*' && $dotnet['entry'] === 'Newtonsoft.Json', 'a NuGet package with no version floats, and is written as the id');

    [$status, $withVersion] = $parse('dotnet:System.Text.Json', '8.0.0');
    $assert($status === 'ok' && $withVersion['coordinate'] === 'System.Text.Json:8.0.0', '--version supplies the version the coordinate left out');

    // --- what is refused, and with which hint ---------------------------------

    $refuses('monolog/monolog', 'for a Composer package say `php:monolog/monolog`');
    $refuses('foo:bar', 'say `jvm:foo:bar`, `php:foo:bar` or `dotnet:foo:bar`');
    $refuses('Foo', 'is not a package name');
    // A host-shaped name with no `/` and no `:` leaves nothing to read an ecosystem from, so the
    // refusal names the rule rather than guessing a table — `dotnet:` is the fix, and `add` says so.
    $refuses('Newtonsoft.Json', 'a bare name is a package of ours; a host package is spelled with its ecosystem');
    $refuses('json', 'is not a version or a comparison', 'not-a-version');
    $refuses('jvm:com.example:lib', 'mvn wants one');
    $refuses('jvm:com.example:lib:1.0', 'drop --version', '2.0');
    $refuses('php:monolog/monolog:^3.0', 'drop --version', '^4.0');
    $refuses('dotnet:Newtonsoft.Json:', 'write `Newtonsoft.Json`');
    $refuses('jvm:a:b:c:d', 'is not a Maven coordinate');
    $refuses('php:notapackage', 'is not a Composer package');
    $refuses('php:monolog/monolog:^3.0,^4.0', 'no `,`, `;`, `#` or newline');

    // --- the edit itself, on a descriptor with comments and four tables -------

    $project = $work . '/project';
    \mkdir($project . '/src', 0777, true);
    $descriptorPath = $project . '/app.moggi';
    $original = "[package]\nname = app\nversion = 0.1.0\n\n"
        . $author
        . "\n[lib]\nsource-dirs = src\n"
        . "\n; the PHP half is Composer's\n[php]\nversion = 8.5\ncomposer = existing/dep:^1.0 ; keep the comment\n"
        . "\n[jvm.maven]\norg.jetbrains:annotations = 24.1.0\n"
        . "\n[dotnet.nuget]\nSystem.Text.Json = 8.0.0\n"
        . "\n[dependencies]\nbase = >=0.2.0\n";
    \file_put_contents($descriptorPath, $original);
    $assert(descriptorProblems($descriptorPath) === [], 'the fixture descriptor is valid');

    $coordinate = static fn (string $argument, ?string $version = null): array => parseAddCoordinate($argument, $version);
    $apply = static function (array $edit) use ($descriptorPath): array {
        return descriptorWithCoordinate((string) \file_get_contents($descriptorPath), $edit, $descriptorPath);
    };

    // ours: appended to the existing [dependencies] table, above the next header
    $oursApplied = $apply($coordinate('json', '0.4'));
    $assert($oursApplied['status'] === 'added' && $oursApplied['sectionCreated'] === false, 'a dependency is added to the existing table: ' . $oursApplied['detail']);
    $assert(\str_contains($oursApplied['text'], "\nbase = >=0.2.0\njson = 0.4\n"), 'the dependency joins the table it belongs to, got: ' . \json_encode($oursApplied['text']));
    $assert(descriptorAdditionProblems($oursApplied['text'], $descriptorPath) === [], 'the edited descriptor is one the parser accepts');

    // ours: the same requirement again is a no-op, byte for byte
    $again = descriptorWithCoordinate($oursApplied['text'], $coordinate('json', '0.4'), $descriptorPath);
    $assert($again['status'] === 'present' && $again['text'] === $oursApplied['text'], 'an already-declared dependency writes nothing');

    // ours: the same package at another constraint is a conflict, not a rewrite
    $conflict = descriptorWithCoordinate($oursApplied['text'], $coordinate('json', '>=0.5'), $descriptorPath);
    $assert($conflict['status'] === 'conflict' && $conflict['text'] === $oursApplied['text'], 'another constraint for the same package is a conflict that leaves the text alone');
    $assert(\str_contains($conflict['detail'], '`json` is already declared as `0.4`'), 'the conflict names what is declared: ' . $conflict['detail']);
    \file_put_contents($descriptorPath, $oursApplied['text']);

    // php: appended to the existing list, comment intact
    $phpApplied = $apply($coordinate('php:monolog/monolog:^3.0'));
    $assert($phpApplied['status'] === 'added', 'a new Composer package is added: ' . $phpApplied['detail']);
    $assert(
        \str_contains($phpApplied['text'], "\ncomposer = existing/dep:^1.0, monolog/monolog:^3.0 ; keep the comment\n"),
        'the Composer list grows by one entry and keeps the comment on its line, got: '
        . (\preg_match('/^composer =.*$/m', $phpApplied['text'], $m) === 1 ? $m[0] : 'no composer line'),
    );

    // jvm: a new key joins its own table, above the next section header
    $jvmApplied = $apply($coordinate('jvm:com.google.code.gson:gson:2.11.0'));
    $assert($jvmApplied['status'] === 'added' && $jvmApplied['sectionCreated'] === false, 'a jvm key is added to the existing table');
    $jvmBody = \substr($jvmApplied['text'], (int) \strpos($jvmApplied['text'], '[jvm.maven]'));
    $assert(
        \strpos($jvmBody, 'com.google.code.gson:gson = 2.11.0') < \strpos($jvmBody, '[dotnet.nuget]'),
        'the new key is written inside [jvm.maven], before the next header',
    );

    // a table the descriptor does not have yet is created at the end
    $bare = $work . '/bare';
    \mkdir($bare, 0777, true);
    $barePath = $bare . '/app.moggi';
    $bareText = "[package]\nname = app\nversion = 0.1.0\n\n" . $author;
    \file_put_contents($barePath, $bareText);
    $created = descriptorWithCoordinate($bareText, $coordinate('php:monolog/monolog'), $barePath);
    $assert($created['sectionCreated'] === true, 'a missing section is created');
    $assert(
        \str_ends_with($created['text'], "[php]\ncomposer = monolog/monolog\n"),
        'the created section holds the coordinate, got: ' . \json_encode($created['text']),
    );
    $createdDependencies = descriptorWithCoordinate($bareText, $coordinate('json', '0.4'), $barePath);
    $assert(
        \str_ends_with($createdDependencies['text'], "[dependencies]\njson = 0.4\n"),
        'a package of ours creates [dependencies] when the package has none, got: ' . \json_encode($createdDependencies['text']),
    );

    // --- the reader and the host-tool layer read back what was written -------

    \file_put_contents($descriptorPath, $jvmApplied['text']);
    $read = readDescriptor($descriptorPath);
    $assert(
        ($read['dependencies']['json'] ?? null) === '0.4',
        'readDescriptor sees the dependency: ' . \json_encode($read['dependencies']),
    );
    $assert(
        \in_array('com.google.code.gson:gson:2.11.0', $read['jvm']['maven'], true),
        'readDescriptor sees the jvm coordinate: ' . \implode(', ', $read['jvm']['maven']),
    );
    $assert(
        hostToolCoordinates($read, 'jvm') === ['org.jetbrains:annotations:24.1.0', 'com.google.code.gson:gson:2.11.0'],
        'the host-tool layer reads the jvm table in descriptor order: ' . \implode(', ', hostToolCoordinates($read, 'jvm')),
    );

    // --- the CLI through --print: the edit is shown and nothing is written ----

    $run = static fn (string ...$arguments): array => runCompiledProcess(
        [\PHP_BINARY, $root . '/moggi.php', ...$arguments],
        60,
    );

    $printed = $run('add', '--print', '--version', '0.4', '--root', $bare, 'json');
    $assert($printed['exitCode'] === 0, 'a printed add of a package of ours succeeds: ' . $printed['stderr'] . $printed['stdout']);
    $assert(\str_contains($printed['stdout'], '+ [dependencies]'), 'the printed edit shows the table it would create: ' . $printed['stdout']);
    $assert(\str_contains($printed['stdout'], '+ json = 0.4'), 'the printed edit shows the line it would write: ' . $printed['stdout']);
    $assert(\str_contains($printed['stdout'], 'nothing written (--print)'), 'the printed edit says nothing was written');
    $assert((string) \file_get_contents($barePath) === $bareText, 'the printed edit wrote nothing');

    $bundledPrint = $run('add', '--print', '--root', $bare, 'json');
    $assert($bundledPrint['exitCode'] === 0, 'a printed add of a bundled package fills the line from the shipped copy: ' . $bundledPrint['stderr']);
    $assert(\str_contains($bundledPrint['stdout'], '+ json = '), 'the printed edit shows the filled line: ' . $bundledPrint['stdout']);
    $assert((string) \file_get_contents($barePath) === $bareText, 'the printed bundled edit wrote nothing');

    $deferred = $run('add', '--print', '--root', $bare, 'unshipped');
    $assert($deferred['exitCode'] === 1, 'a printed add with no version fails when nothing local can answer it');
    $assert(\str_contains($deferred['stderr'], 'will not ask a registry'), 'the refusal says why: ' . $deferred['stderr']);

    $hostPrinted = $run('add', '--print', '--root', $project, 'jvm:org.slf4j:slf4j-api:2.0.16');
    $assert($hostPrinted['exitCode'] === 0 && \str_contains($hostPrinted['stdout'], '+ org.slf4j:slf4j-api = 2.0.16'), 'a printed host add still works: ' . $hostPrinted['stdout']);
    $assert((string) \file_get_contents($descriptorPath) === $jvmApplied['text'], 'the printed host edit wrote nothing');

    // A host coordinate belongs to the backend it names, so one the package does not
    // declare is refused rather than recorded: `[jvm.maven]` in a PHP-only package is
    // a dependency no build of it would ever resolve.
    $phpOnly = $work . '/php-only';
    \mkdir($phpOnly, 0777, true);
    $phpOnlyPath = $phpOnly . '/app.moggi';
    $phpOnlyText = "[package]\nname = app\nversion = 0.1.0\nbackends = php\n\n" . $author;
    \file_put_contents($phpOnlyPath, $phpOnlyText);
    $undeclared = $run('add', '--print', '--root', $phpOnly, 'jvm:com.google.code.gson:gson:2.11.0');
    $assert($undeclared['exitCode'] === 1, 'a coordinate for an undeclared backend fails the command: ' . $undeclared['stdout']);
    $assert(\str_contains($undeclared['stderr'], 'does not declare the `jvm` backend'), 'the refusal names the backend: ' . $undeclared['stderr']);
    $assert((string) \file_get_contents($phpOnlyPath) === $phpOnlyText, 'a refused coordinate writes nothing');

    // The same package takes a coordinate for the backend it does declare.
    $declaredBackend = $run('add', '--print', '--root', $phpOnly, 'php:monolog/monolog:^3.0');
    $assert($declaredBackend['exitCode'] === 0, 'a coordinate for a declared backend prints: ' . $declaredBackend['stderr'] . $declaredBackend['stdout']);
    $assert(\str_contains($declaredBackend['stdout'], '+ composer = monolog/monolog:^3.0'), 'and the printed edit is the Composer entry: ' . $declaredBackend['stdout']);
    $assert((string) \file_get_contents($phpOnlyPath) === $phpOnlyText, 'the printed edit for the declared backend wrote nothing');

    $hint = $run('add', '--print', '--root', $bare, 'monolog/monolog');
    $assert($hint['exitCode'] === 1, 'a bare vendor/package fails the command');
    $assert(\str_contains($hint['stderr'], 'php:monolog/monolog'), 'the refusal names the prefix: ' . $hint['stderr']);

    // The same rule through the command itself: a NuGet-shaped bare id is resolved as a package of
    // ours and never lands in `[dotnet.nuget]`, and a name that is not a package name at all is
    // refused with the prefix rather than guessed into that table.
    $nugetShaped = $run('add', '--print', '--version', '13.0.3', '--root', $project, 'newtonsoft.json');
    $assert($nugetShaped['exitCode'] === 0, 'a NuGet-shaped bare id prints: ' . $nugetShaped['stderr']);
    $assert(\str_contains($nugetShaped['stdout'], 'registry newtonsoft.json = 13.0.3'), 'a bare id resolves as a package of ours: ' . $nugetShaped['stdout']);
    $assert(! \str_contains($nugetShaped['stdout'], 'dotnet.nuget'), 'and it is not written as a NuGet coordinate: ' . $nugetShaped['stdout']);

    $nugetId = $run('add', '--print', '--root', $bare, 'Newtonsoft.Json');
    $assert($nugetId['exitCode'] === 1, 'a bare NuGet id fails the command rather than being guessed');
    $assert(\str_contains($nugetId['stderr'], 'a bare name is a package of ours'), 'the refusal states the rule: ' . $nugetId['stderr']);
    $assert((string) \file_get_contents($barePath) === $bareText, 'a refused bare id writes nothing');

    $noName = $run('add', '--print', '--root', $bare);
    $assert($noName['exitCode'] === 1 && \str_contains($noName['stderr'], 'needs a name'), 'add with no name is refused');

    $unknownFlag = $run('add', '--printt', '--root', $bare, 'json');
    $assert($unknownFlag['exitCode'] === 1 && \str_contains($unknownFlag['stderr'], 'unknown option'), 'a mistyped flag is refused rather than ignored');

    $json = $run('add', '--print', '--json', '--version', '0.4', '--root', $project, 'json');
    $assert($json['exitCode'] === 0, 'a printed add with --json succeeds: ' . $json['stderr']);
    $decoded = \json_decode($json['stdout'], true);
    $assert(\is_array($decoded) && ($decoded['section'] ?? null) === 'dependencies', 'the json report names the table: ' . $json['stdout']);
    $assert(($decoded['written'] ?? null) === false && ($decoded['status'] ?? null) === 'present', 'the json report says nothing was written');

    $help = $run('add', '--help');
    $assert($help['exitCode'] === 0 && \str_contains($help['stdout'], 'moggi add <name>'), 'add has help for both halves');
    $assert(\str_contains($help['stdout'], '--bind'), 'the help names the binding flags');

    // --- --bind/--facade: the plan, the [ffi] entry, the skeleton ------------
    //
    // `--print` plans the binding without fetching: the module names are a
    // function of the coordinate alone, so no host tool runs here.

    $bindPlan = $run('add', '--print', '--bind', '--root', $phpOnly, 'php:monolog/monolog:^3.0');
    $assert($bindPlan['exitCode'] === 0, 'a printed bind plans: ' . $bindPlan['stderr'] . $bindPlan['stdout']);
    $assert(\str_contains($bindPlan['stdout'], 'Composer.Monolog.Monolog'), 'the plan names the bound module: ' . $bindPlan['stdout']);
    $assert(\str_contains($bindPlan['stdout'], 'php:monolog/monolog'), 'the plan names the [ffi] entry: ' . $bindPlan['stdout']);
    $assert((string) \file_get_contents($phpOnlyPath) === $phpOnlyText, 'the planned bind wrote nothing');

    $facadePlan = $run('add', '--print', '--bind', '--facade', '--root', $phpOnly, 'php:monolog/monolog:^3.0');
    $assert($facadePlan['exitCode'] === 0, 'a printed facade plans: ' . $facadePlan['stderr'] . $facadePlan['stdout']);
    $assert(\str_contains($facadePlan['stdout'], 'Monolog.Monolog'), 'the plan names the facade module: ' . $facadePlan['stdout']);
    $assert((string) \file_get_contents($phpOnlyPath) === $phpOnlyText, 'the planned facade wrote nothing');

    $facadeAlone = $run('add', '--facade', '--root', $phpOnly, 'php:monolog/monolog:^3.0');
    $assert($facadeAlone['exitCode'] === 1, 'a facade without a bind fails the command');
    $assert(\str_contains($facadeAlone['stderr'], 'needs `--bind`'), 'the refusal says what it needs: ' . $facadeAlone['stderr']);

    $registryBind = $run('add', '--print', '--version', '0.4', '--bind', '--root', $project, 'json');
    $assert($registryBind['exitCode'] === 1, 'a package of ours does not bind');
    $assert(\str_contains($registryBind['stderr'], 'only a host coordinate binds'), 'the refusal says why: ' . $registryBind['stderr']);

    // The [ffi] entry is appended, deduplicated exactly, and still a descriptor.
    $ffiBase = "[package]\nname = app\nversion = 0.1.0\n\n" . $author;
    $ffiCreated = descriptorWithFfiBinding($ffiBase, 'php:acme/hello');
    $assert($ffiCreated['status'] === 'added' && $ffiCreated['sectionCreated'] === true, 'the first binding creates [ffi]');
    $assert(\str_contains($ffiCreated['text'], "[ffi]\nbindings = php:acme/hello\n"), 'the entry is the backend:package pair, got: ' . \json_encode($ffiCreated['text']));

    $ffiAgain = descriptorWithFfiBinding($ffiCreated['text'], 'php:acme/hello');
    $assert($ffiAgain['status'] === 'present' && $ffiAgain['text'] === $ffiCreated['text'], 'the same binding again writes nothing');

    $ffiSecond = descriptorWithFfiBinding($ffiCreated['text'], 'jvm:com.acme:hello');
    $assert($ffiSecond['status'] === 'added', 'a second backend appends');
    $assert(\str_contains($ffiSecond['text'], 'bindings = php:acme/hello, jvm:com.acme:hello'), 'the list stays one line, got: ' . \json_encode($ffiSecond['text']));
    $assert(descriptorAdditionProblems($ffiSecond['text'], $descriptorPath) === [], 'the edited descriptor is one the parser accepts');

    // The module names are pure functions of the coordinate.
    $assert(bindModuleName('php', 'acme/hello') === 'Composer.Acme.Hello', 'the php module name');
    $assert(bindModuleName('jvm', 'com.acme:hello') === 'Maven.Com.Acme.Hello', 'the jvm module name');
    $assert(bindModuleName('dotnet', 'Acme.Hello') === 'Nuget.Acme.Hello', 'the dotnet module name');
    $assert(facadeModuleName('Maven.Com.Acme.Hello') === 'Com.Acme.Hello', 'the facade drops the ecosystem prefix');

    try {
        bindModuleName('ruby', 'acme/hello');
        $assert(false, 'an unbindable backend is refused');
    } catch (\RuntimeException $error) {
        $assert(\str_contains($error->getMessage(), 'only `php:`, `jvm:` and `dotnet:`'), 'the refusal names the backends that bind');
    }

    // The skeleton faces the bound module: pragma, exports, qualified aliases.
    $skeletonFrom = [
        'backend' => 'php',
        'module' => 'Composer.Acme.Hello',
        'coordinate' => 'acme/hello',
        'types' => [['moggi' => 'Greeter', 'fqn' => 'Acme\\Hello\\Greeter', 'path' => 'Acme.Hello.Greeter']],
        'functions' => [['moggi' => 'greet', 'path' => 'Acme\\Hello\\greet', 'sig' => 'Greeter -> String']],
        'consts' => [['moggi' => 'version', 'path' => 'Acme\\Hello\\VERSION', 'type' => 'String']],
        'skipped' => [],
    ];
    $skeleton = renderFacade($skeletonFrom, 'Com.Acme.Hello');
    $assert(\str_contains($skeleton, '{-# BACKEND'), 'the skeleton opens the backend pragma');
    $assert(\str_contains($skeleton, 'php = Composer.Acme.Hello'), 'the pragma names the bound backend');
    $assert(\str_contains($skeleton, 'module Com.Acme.Hello'), 'the skeleton is the portable name');
    $assert(\str_contains($skeleton, 'import Composer.Acme.Hello (Greeter)'), 'types are imported, never re-declared');
    $assert(\str_contains($skeleton, 'import Composer.Acme.Hello qualified as Bound'), 'bodies delegate through an alias');
    $assert(\str_contains($skeleton, 'greet :: Greeter -> String') && \str_contains($skeleton, 'greet = Bound.greet'), 'functions keep their signatures and delegate');
    $assert(\str_contains($skeleton, 'version :: String') && \str_contains($skeleton, 'version = Bound.version'), 'consts delegate too');
    $assert(\str_contains($skeleton, 'import Data.String'), 'the signatures\u2019 imports are there');

    // --- the registry half, against a local signed registry -------------------
    //
    // The catalog is signed metadata, so this needs the checkout's own `schnorr`;
    // without it the checks are reported as skipped rather than faked. What it
    // proves is everything `add` does *itself*: load and verify the catalog, refuse
    // a name the registry does not list before touching the descriptor, turn
    // "newest release" into the constraint the descriptor records, and write the
    // lock through the same resolver `update` uses. The fetch that follows is
    // `install`'s, and a metadata-only registry cannot serve a blob — which is
    // asserted too, because "the record is written and `install` is the retry" is
    // the behaviour a failed fetch has to leave behind.

    $schnorrBinary = \getenv('MOGGI_SCHNORR');
    if (! \is_string($schnorrBinary) || $schnorrBinary === '') {
        $schnorrBinary = $root . '/schnorr/schnorr';
    }
    if (! \is_file($schnorrBinary) || ! \is_executable($schnorrBinary)) {
        $schnorrBinary = $root . '/schnorr/build/schnorr';
    }

    if (! \is_file($schnorrBinary) || ! \is_executable($schnorrBinary)) {
        echo "add tests passed ({$checks} checks; registry half skipped — no schnorr CLI, set MOGGI_SCHNORR)\n";

        return;
    }

    $schnorr = static function (array $arguments, ?string $stdin = null) use ($schnorrBinary): string {
        $result = runCompiledProcess([$schnorrBinary, ...$arguments], 60, null, null, $stdin);
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException('schnorr: ' . (\trim($result['stderr']) !== '' ? \trim($result['stderr']) : 'exited ' . $result['exitCode']));
        }

        return \trim($result['stdout']);
    };

    $registry = $work . '/registry';
    $registryProject = $work . '/registry-project';
    \mkdir($registryProject, 0777, true);
    \file_put_contents($registryProject . '/app.moggi', "[package]\nname = app\nversion = 0.1.0\n\n" . $author);

    [$nsec, $npub] = \preg_split('/\s+/', $schnorr(['generate'])) ?: ['', ''];
    $assert(\str_starts_with($nsec, 'nsec'), 'the schnorr CLI generated a keypair');

    $entry = static fn (string $npub, array $versions): array => [
        'owner' => $npub,
        'authors' => [$npub],
        'latest' => \array_key_last($versions),
        'description' => 'test package',
        'digest' => 'sha256:' . \str_repeat('a', 64),
        'versions' => $versions,
    ];

    $writeRegistry = static function (string $dir, string $npub, string $nsec, array $packages) use ($schnorr): void {
        \mkdir($dir . '/catalog', 0777, true);
        $prefix = \substr((string) \array_key_first($packages), 0, 2);
        $bytes = \json_encode(['format' => 1, 'prefix' => $prefix, 'packages' => $packages], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
        \file_put_contents($dir . '/catalog/' . $prefix . '.json', $bytes);
        $root = [
            'format' => 1,
            'generated' => \gmdate('Y-m-d\TH:i:s\Z'),
            'version' => 1,
            'expires' => \gmdate('Y-m-d\TH:i:s\Z', \time() + 3600),
            'registry_npub' => $npub,
            'admins' => [$npub],
            'maintainers' => [$npub],
            'shards' => [$prefix => 'sha256:' . \hash('sha256', $bytes)],
        ];
        $index = \json_encode($root, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
        \file_put_contents($dir . '/index.json', $index);
        \file_put_contents($dir . '/index.json.sig', $schnorr(['sign', \hash('sha256', $index)], $nsec) . "\n");
    };

    // `json` travels with the compiler, so a bare `add json` answers from the
    // shipped copy and never reaches this registry: the newest-release path is
    // exercised through `widgets`, which nothing bundles.
    $writeRegistry($registry, (string) $npub, (string) $nsec, [
        'widgets' => $entry((string) $npub, ['0.3.0' => ['deps' => []], '0.4.1' => ['deps' => []]]),
    ]);

    $env = \getenv();
    $env['MOGGI_SCHNORR'] = $schnorrBinary;
    $env['MOGGI_CACHE_DIR'] = $work . '/cache';
    $env['MOGGI_USER_CACHE'] = $work . '/user-cache';
    unset($env['MOGGI_REGISTRY_NPUB'], $env['MOGGI_REGISTRY']);

    $cli = static fn (string ...$arguments): array => runCompiledProcess(
        [\PHP_BINARY, $root . '/moggi.php', ...$arguments],
        120,
        $env,
        $registryProject,
    );

    // A name the registry does not list never reaches the descriptor.
    $unlisted = $cli('add', '--registry', $registry, '--no-cache', 'nope');
    $assert($unlisted['exitCode'] === 1, 'an unlisted package fails the command: ' . $unlisted['stdout'] . $unlisted['stderr']);
    $assert(\str_contains($unlisted['stderr'], 'lists no package `nope`'), 'the refusal says which name: ' . $unlisted['stderr']);
    $assert(! \str_contains((string) \file_get_contents($registryProject . '/app.moggi'), 'dependencies'), 'an unlisted package writes nothing');

    // The newest release decides the constraint, and the lock is written by the resolver.
    $added = $cli('add', '--registry', $registry, '--no-cache', 'widgets');
    $descriptorText = (string) \file_get_contents($registryProject . '/app.moggi');
    $assert(
        \str_contains($descriptorText, "[dependencies]\nwidgets = 0.4\n"),
        'the newest release (0.4.1) becomes the constraint 0.4, got: ' . \json_encode($descriptorText),
    );
    $lock = \json_decode((string) @\file_get_contents($registryProject . '/moggi.lock'), true);
    $assert(($lock['packages']['widgets']['version'] ?? null) === '0.4.1', 'the lock pins the release the constraint chose: ' . \json_encode($lock['packages'] ?? null));
    $assert($added['exitCode'] === 1, 'a registry that serves no blob fails the command (the fetch is `install`\'s): ' . $added['stdout']);
    $assert(\str_contains($added['stdout'], 'refused'), 'the failed package is reported: ' . $added['stdout']);

    // Adding the same dependency again is a no-op on the descriptor, and re-resolves.
    $againCli = $cli('add', '--registry', $registry, '--no-cache', 'widgets', '--version', '0.4');
    $assert(\str_contains($againCli['stdout'], 'already declared'), 'an already-declared dependency is reported as such: ' . $againCli['stdout']);
    $assert((string) \file_get_contents($registryProject . '/app.moggi') === $descriptorText, 're-adding writes nothing');

    echo "add tests passed ({$checks} checks)\n";
} finally {
    removeDirectory($work);
}
