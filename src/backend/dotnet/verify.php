<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Verify;

use function Moggi\Backend\DotNet\Dependencies\dotNetVendoredAssemblies;
use function Moggi\Backend\DotNet\dotnetCliEnv;

use function Moggi\Backend\DotNet\dotnetWorkspaceRoot;
use function Moggi\Backend\DotNet\resolveDotnetExecutable;

use Moggi\Backend\VerificationUnavailable;

use function Moggi\Compiler\runProcess;

use Moggi\Syntax\Ast;

/**
 * Verify a `foreign dotnet` declaration against the assemblies the compile references.
 *
 * A `foreign import` is an axiom — the compiler emits the member call it is told to emit — so a
 * mistyped member, a wrong arity or a wrong type would otherwise surface as a runtime
 * `MissingMethodException`. The host can be asked: a small reflection inspector, built on demand
 * from shipped C# source with the SDK's own Roslyn (no project, no restore, no network),
 * describes the named type over the compile's reference set (vendored `dotnet/*.dll` plus the
 * framework for the target TFM), and the emitted signature is compared against it — the JVM
 * shape, including "the class was not found" as an error.
 *
 * The comparison is against the signature the emitter will use (`$nativeSig`), never a
 * re-inference, so this cannot disagree with codegen. Assembly scopes (`[System.Runtime]` vs
 * `[System.Private.CoreLib]`) are ignored by the comparison: the runtime resolves across
 * forwarding, and which scope names the TypeRef is the sidecar's statement, not the member's.
 * What is compared is the member's existence and shape.
 *
 * Missing vs broken, the same split as the JVM verifier: no `dotnet` at all is
 * {@see VerificationUnavailable} — an error naming it, only when a `foreign dotnet` declaration
 * exists — while a tool that breaks trying (inspector build or query failure, unreadable
 * framework list) warns on stderr and leaves the declaration unverified.
 *
 * @param 'function'|'const'|'type' $kind
 * @param array{dispatch?: string, classPath?: ?string, hostMember?: ?string} $pathInfo
 * @param ?string $nativeSig the signature the emitter will use (from `foreignNativeSig`)
 */
function verifyDotNetForeignImport(
    string $kind,
    array $pathInfo,
    ?Ast\TypeNode $type,
    ?string $nativeSig,
    string $name,
): ?string {
    $dispatch = (string) ($pathInfo['dispatch'] ?? '');
    if ($dispatch !== 'static' && $dispatch !== 'instance' && $dispatch !== 'constructor') {
        return null;
    }
    if ($kind === 'type') {
        return null;
    }
    if ($nativeSig === null || $nativeSig === '') {
        return null;
    }
    $classPath = (string) ($pathInfo['classPath'] ?? '');
    if ($classPath === '') {
        return null;
    }

    $dotnet = resolveDotnetExecutable();
    if ($dotnet === null) {
        throw new VerificationUnavailable(
            'verifying a `foreign dotnet` declaration needs `dotnet` (a .NET SDK);'
            . ' the full moggi distribution ships one',
        );
    }

    $inspector = ensureInspectorBinary($dotnet);
    if ($inspector === null) {
        warnOnceInspectorBroken($name, 'could not build the CLR inspector');

        return null;
    }

    $table = inspectDotNetType($dotnet, $inspector, $classPath);
    if ($table === null) {
        warnOnceInspectorBroken($name, "could not read `{$classPath}`");

        return null;
    }
    if (! ($table['found'] ?? false)) {
        return "dotnet class `{$classPath}` was not found in the compile's reference set";
    }

    $member = (string) ($pathInfo['hostMember'] ?? '');

    if ($kind === 'const') {
        return verifyDotNetFieldOrProperty($table, $classPath, $member, $nativeSig);
    }
    if ($dispatch === 'constructor') {
        return verifyDotNetConstructor($table, $classPath, $nativeSig);
    }

    return verifyDotNetMethod($table, $classPath, $member, $nativeSig);
}

/**
 * The inspector binary, built once per SDK into the workspace and reused.
 *
 * The source ships with the compiler; the build is `csc` invoked directly on it (no project, no
 * restore, nothing fetched), with the framework assemblies as references. A failure here is a
 * broken tool, not a missing one — the caller warns and stands down.
 */
function ensureInspectorBinary(string $dotnet): ?string
{
    static $binary = null;
    static $done = false;
    if ($done) {
        return $binary;
    }
    $done = true;

    $sdkDir = findDotNetSdkDir($dotnet);
    $csc = $sdkDir === null ? null : $sdkDir . DIRECTORY_SEPARATOR . 'Roslyn'
        . DIRECTORY_SEPARATOR . 'bincore' . DIRECTORY_SEPARATOR . 'csc.dll';
    $frameworkDir = $sdkDir === null ? null : findFrameworkDir($dotnet);
    if ($csc === null || ! \is_file($csc) || $frameworkDir === null) {
        return $binary = null;
    }

    $sdkVersion = \basename(\rtrim($sdkDir, '/\\'));
    $source = \dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dotnet'
        . DIRECTORY_SEPARATOR . 'inspect' . DIRECTORY_SEPARATOR . 'Program.cs';
    $sourceHash = \is_file($source) ? (\sha1_file($source) ?: 'unknown') : 'missing';
    $outDir = dotnetWorkspaceRoot() . DIRECTORY_SEPARATOR . 'inspect'
        . DIRECTORY_SEPARATOR . $sdkVersion . '-' . $sourceHash;
    if (! \is_dir($outDir) && ! @\mkdir($outDir, 0777, true) && ! \is_dir($outDir)) {
        return $binary = null;
    }
    $dll = $outDir . DIRECTORY_SEPARATOR . 'inspect.dll';
    \file_put_contents(
        $outDir . DIRECTORY_SEPARATOR . 'inspect.runtimeconfig.json',
        "{\n  \"runtimeOptions\": {\n    \"tfm\": \"net8.0\",\n"
        . "    \"framework\": { \"name\": \"Microsoft.NETCore.App\", \"version\": \"8.0.0\" }\n  }\n}\n",
    );
    if (\is_file($dll)) {
        return $binary = $dll;
    }

    if (! \is_file($source)) {
        return $binary = null;
    }
    $tmp = $outDir . DIRECTORY_SEPARATOR . 'inspect.build.dll';
    $command = [
        $dotnet, $csc, '-nologo', '-out:' . $tmp, '-target:exe', '-nostdlib', '-noconfig',
        '-reference:' . $frameworkDir . DIRECTORY_SEPARATOR . 'System.Private.CoreLib.dll',
        '-reference:' . $frameworkDir . DIRECTORY_SEPARATOR . 'System.Runtime.dll',
        '-reference:' . $frameworkDir . DIRECTORY_SEPARATOR . 'System.Console.dll',
        '-reference:' . $frameworkDir . DIRECTORY_SEPARATOR . 'System.Linq.dll',
        '-reference:' . $frameworkDir . DIRECTORY_SEPARATOR . 'System.Text.Json.dll',
        $source,
    ];
    $captured = runProcess($command, null, dotnetCliEnv(), false, 300);
    if (($captured['exitCode'] ?? -1) !== 0 || ! \is_file($tmp)) {
        @\unlink($tmp);

        return $binary = null;
    }
    if (! @\rename($tmp, $dll)) {
        @\unlink($tmp);

        return $binary = null;
    }

    return $binary = $dll;
}

/** Newest 8.x SDK directory from `dotnet --list-sdks`, or null when it says nothing usable. */
function findDotNetSdkDir(string $dotnet): ?string
{
    $captured = runProcess([$dotnet, '--list-sdks'], null, dotnetCliEnv(), false, 60);
    if (($captured['exitCode'] ?? -1) !== 0) {
        return null;
    }
    $best = null;
    foreach (\preg_split('/\R/', (string) ($captured['stdout'] ?? '')) ?: [] as $line) {
        if (\preg_match('/^(\d+)\.(\d+)\.(\d+)\s+\[(.+)\]\s*$/', \trim($line), $m) !== 1) {
            continue;
        }
        if ((int) $m[1] !== 8) {
            continue;
        }
        $dir = \rtrim($m[4], '/\\');
        if ($best === null || \version_compare("{$m[1]}.{$m[2]}.{$m[3]}", $best[0], '>')) {
            $best = ["{$m[1]}.{$m[2]}.{$m[3]}", $dir];
        }
    }
    if ($best === null) {
        return null;
    }

    return $best[1] . DIRECTORY_SEPARATOR . $best[0];
}

/**
 * The shared-framework directory for the target TFM from `dotnet --list-runtimes`.
 *
 * Parsing the list survives layouts where the executable and the framework live in different
 * places (a store path is not a prefix of anything); deriving one from the other does not.
 */
function findFrameworkDir(string $dotnet): ?string
{
    $captured = runProcess([$dotnet, '--list-runtimes'], null, dotnetCliEnv(), false, 60);
    if (($captured['exitCode'] ?? -1) !== 0) {
        return null;
    }
    $best = null;
    foreach (\preg_split('/\R/', (string) ($captured['stdout'] ?? '')) ?: [] as $line) {
        if (\preg_match('/^Microsoft\.NETCore\.App\s+(\d+)\.(\d+)\.(\d+)\s+\[(.+)\]\s*$/', \trim($line), $m) !== 1) {
            continue;
        }
        if ((int) $m[1] !== 8) {
            continue;
        }
        $dir = \rtrim($m[4], '/\\') . DIRECTORY_SEPARATOR . "{$m[1]}.{$m[2]}.{$m[3]}";
        if ($best === null || \version_compare("{$m[1]}.{$m[2]}.{$m[3]}", $best[0], '>')) {
            $best = ["{$m[1]}.{$m[2]}.{$m[3]}", $dir];
        }
    }
    if ($best === null || ! \is_dir($best[1])) {
        return null;
    }

    return $best[1];
}

/**
 * The inspector's answer for one type, cached per process over the reference set.
 *
 * @return ?array{found: bool, methods?: array<string, list<array{params: list<string>, ret: string, static: bool, public: bool}>>, ctors?: list<array{params: list<string>, public: bool}>, fields?: array<string, array{type: string, static: bool, public: bool}>, properties?: array<string, array{type: string, static: bool, public: bool, hasGetter: bool}>}
 */
function inspectDotNetType(string $dotnet, string $inspector, string $classPath): ?array
{
    static $tables = [];
    $refs = dotNetReferenceSet();
    $key = \sha1(\implode("\0", $refs)) . "\0" . $classPath;
    if (\array_key_exists($key, $tables)) {
        return $tables[$key];
    }

    $command = [$dotnet, $inspector, '--refs'];
    foreach ($refs as $ref) {
        $command[] = $ref;
    }
    $command[] = '--type';
    $command[] = $classPath;

    $captured = runProcess($command, null, dotnetCliEnv(), false, 60);
    if (($captured['exitCode'] ?? -1) !== 0) {
        return $tables[$key] = null;
    }
    $table = \json_decode((string) ($captured['stdout'] ?? ''), true);
    if (! \is_array($table)) {
        return $tables[$key] = null;
    }

    return $tables[$key] = $table;
}

/**
 * What the inspector loads: the framework for the target TFM, then every vendored assembly.
 *
 * Vendored after framework, so a carried assembly wins the name — the same tree the packager
 * stages beside the app, which is what the program will actually load.
 */
function dotNetReferenceSet(): array
{
    $refs = [];
    $dotnet = resolveDotnetExecutable();
    if ($dotnet !== null) {
        $frameworkDir = findFrameworkDir($dotnet);
        if ($frameworkDir !== null) {
            $refs[] = $frameworkDir;
        }
    }
    foreach (dotNetVendoredAssemblies() as $dll) {
        $refs[] = $dll;
    }

    return $refs;
}

function warnOnceInspectorBroken(string $name, string $detail): void
{
    static $warned = false;
    if ($warned) {
        return;
    }
    $warned = true;
    \fwrite(
        STDERR,
        "warning: foreign dotnet `{$name}` is not verified against the CLR: {$detail}\n",
    );
}

/**
 * Compare two IL type spellings ignoring the assembly scope (`[System.Runtime]` vs
 * `[System.Private.CoreLib]`): the runtime resolves across forwarding, and the scope is the
 * sidecar's statement, not the member's. Kind, name, nesting and generic shape still compare.
 */
function sameClrTypeIgnoringScope(string $a, string $b): bool
{
    return stripClrAssemblyScopes($a) === stripClrAssemblyScopes($b);
}

function stripClrAssemblyScopes(string $il): string
{
    return (string) \preg_replace('/\[[^\[\]]*\]/', '', $il);
}

/**
 * Split `(params)ret` on top-level commas: generic arguments hold commas of their own
 * (`Nullable`1<valuetype [..].., valuetype [..]..>`), which the naive explode would cut.
 *
 * @return array{params: list<string>, ret: string}
 */
function splitClrSignature(string $sig): array
{
    if ($sig === '' || $sig[0] !== '(') {
        return ['params' => [], 'ret' => $sig];
    }
    $depth = 0;
    $current = '';
    $params = [];
    $len = \strlen($sig);
    $i = 1;
    for (; $i < $len; $i++) {
        $ch = $sig[$i];
        if ($ch === '<') {
            $depth++;
            $current .= $ch;
        } elseif ($ch === '>') {
            $depth--;
            $current .= $ch;
        } elseif ($ch === ',' && $depth === 0) {
            $params[] = $current;
            $current = '';
        } elseif ($ch === ')' && $depth === 0) {
            break;
        } else {
            $current .= $ch;
        }
    }
    if ($current !== '' || $params !== []) {
        $params[] = $current;
    }

    return ['params' => $params, 'ret' => \substr($sig, $i + 1)];
}

/**
 * @param array{methods?: array<string, list<array{params: list<string>, ret: string, static: bool, public: bool}>>, fields?: array<string, mixed>, properties?: array<string, mixed>} $table
 */
function verifyDotNetMethod(array $table, string $classPath, string $member, string $expected): ?string
{
    if ($member === '') {
        return null;
    }
    $candidates = $table['methods'][$member] ?? [];
    if ($candidates === []) {
        $isField = isset($table['fields'][$member]);
        $isProperty = isset($table['properties'][$member]);
        if ($isField || $isProperty) {
            $what = $isField ? 'field' : 'property';

            return "dotnet member `{$classPath}:{$member}` is a {$what};"
                . ' declare it with `const`, not `function`';
        }

        return "dotnet class `{$classPath}` has no member `{$member}`";
    }

    $want = splitClrSignature($expected);
    foreach ($candidates as $candidate) {
        if (! paramsMatchIgnoringScope($candidate['params'] ?? [], $want['params'])) {
            continue;
        }
        if (! sameClrTypeIgnoringScope((string) ($candidate['ret'] ?? ''), $want['ret'])) {
            continue;
        }

        return ($candidate['public'] ?? false)
            ? null
            : "dotnet member `{$classPath}:{$member}` is not public";
    }

    return "dotnet member `{$classPath}:{$member}` has no signature matching `{$expected}`; "
        . describeDotNetSignatures($candidates, \count($want['params']));
}

/** @param array{ctors?: list<array{params: list<string>, public: bool}>} $table */
function verifyDotNetConstructor(array $table, string $classPath, string $expected): ?string
{
    $want = splitClrSignature($expected);
    foreach ($table['ctors'] ?? [] as $candidate) {
        if (! paramsMatchIgnoringScope($candidate['params'] ?? [], $want['params'])) {
            continue;
        }

        return ($candidate['public'] ?? false)
            ? null
            : "dotnet constructor `{$classPath}:.ctor` is not public";
    }

    return "dotnet class `{$classPath}` has no constructor matching `{$expected}`; "
        . describeDotNetSignatures($table['ctors'] ?? [], \count($want['params']));
}

/**
 * Whether a `const` import must be emitted as a getter `call` rather than `ldsfld`.
 *
 * The emitter asks the host instead of a hardcoded list: a property with a getter is a call,
 * anything else (a real field, or a host this process cannot ask) keeps the field load. Never
 * throws — emission must not die on a metadata question the verifier already answered.
 */
function dotNetConstNeedsGetterCall(?string $classPath, ?string $member): bool
{
    try {
        if ($classPath === null || $classPath === '' || $member === null || $member === '') {
            return false;
        }
        $dotnet = resolveDotnetExecutable();
        if ($dotnet === null) {
            return false;
        }
        $inspector = ensureInspectorBinary($dotnet);
        if ($inspector === null) {
            return false;
        }
        $table = inspectDotNetType($dotnet, $inspector, $classPath);
        if ($table === null || ! ($table['found'] ?? false)) {
            return false;
        }
        $property = $table['properties'][$member] ?? null;

        return \is_array($property) && ($property['hasGetter'] ?? false) === true;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * A `const` import names a field whose type the declaration states — or a property read through
 * its getter (`System.Console:Out` is `get_Out()`, not a field).
 *
 * @param array{fields?: array<string, array{type: string, static: bool, public: bool}>, properties?: array<string, array{type: string, static: bool, public: bool, hasGetter: bool}>} $table
 */
function verifyDotNetFieldOrProperty(array $table, string $classPath, string $member, string $expected): ?string
{
    if ($member === '') {
        return null;
    }
    $field = $table['fields'][$member] ?? null;
    if ($field !== null) {
        if (! ($field['public'] ?? false)) {
            return "dotnet field `{$classPath}:{$member}` is not public";
        }
        if (! sameClrTypeIgnoringScope((string) ($field['type'] ?? ''), $expected)) {
            return "dotnet field `{$classPath}:{$member}` has type `{$field['type']}`,"
                . " but this foreign import declares `{$expected}`";
        }

        return null;
    }

    $property = $table['properties'][$member] ?? null;
    if ($property !== null) {
        if (! ($property['hasGetter'] ?? false) || ! ($property['public'] ?? false)) {
            return "dotnet property `{$classPath}:{$member}` is not readable";
        }
        if (! sameClrTypeIgnoringScope((string) ($property['type'] ?? ''), $expected)) {
            return "dotnet property `{$classPath}:{$member}` has type `{$property['type']}`,"
                . " but this foreign import declares `{$expected}`";
        }

        return null;
    }

    return isset($table['methods'][$member])
        ? "dotnet member `{$classPath}:{$member}` is a method;"
            . ' declare it with `function`, not `const`'
        : "dotnet class `{$classPath}` has no field or property `{$member}`";
}

/** @param list<string> $a @param list<string> $b */
function paramsMatchIgnoringScope(array $a, array $b): bool
{
    if (\count($a) !== \count($b)) {
        return false;
    }
    foreach ($a as $i => $param) {
        if (! sameClrTypeIgnoringScope($param, $b[$i])) {
            return false;
        }
    }

    return true;
}

/**
 * What the host's overloads look like: same-arity candidates when there are any (the wrong-type
 * case), otherwise the range of arities (the wrong-arity case) — the JVM shape, since the full
 * list is unreadable and unstable across framework versions.
 *
 * @param list<array{params?: list<string>}> $candidates
 */
function describeDotNetSignatures(array $candidates, int $expectedParameters): string
{
    $sameArity = \array_values(\array_filter(
        $candidates,
        static fn (array $candidate): bool => \count($candidate['params'] ?? []) === $expectedParameters,
    ));

    if ($sameArity !== []) {
        $shown = \array_slice($sameArity, 0, 4);
        $list = \implode(', ', \array_map(
            static fn (array $candidate): string => '(' . \implode(',', $candidate['params'] ?? []) . ')'
                . ($candidate['ret'] ?? ''),
            $shown,
        ));
        $more = \count($sameArity) > 4 ? ', …' : '';

        return "the host declares {$list}{$more}";
    }

    $arities = \array_unique(\array_map(
        static fn (array $candidate): int => \count($candidate['params'] ?? []),
        $candidates,
    ));
    \sort($arities);

    return 'the host declares ' . \count($candidates) . ' overload(s) with arities '
        . \implode(', ', $arities);
}
