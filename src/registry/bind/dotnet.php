<?php declare(strict_types=1);

namespace Moggi\Registry\Bind;

use const Moggi\Backend\DotNet\Dependencies\DOTNET_SIDECAR_FORMAT;

use function Moggi\Backend\DotNet\dotnetCliEnv;
use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Backend\DotNet\Verify\ensureInspectorBinary;
use function Moggi\Compiler\compilerVersion;
use function Moggi\Compiler\runProcess;

/**
 * The .NET side of `moggi bind`: read a NuGet-style tree on disk (assemblies
 * under a `dotnet` directory, the shape `discoverDotNetLibDirs()` resolves)
 * and describe what it can become as Moggi declarations plus the versioned
 * sidecar entries the emitter needs.
 *
 * The host is asked with the same CLR inspector the verifier builds
 * (`src/backend/dotnet/inspect/Program.cs`, extended additively with `--list`
 * and the `optional` / `literal` / `initOnly` member flags): one `--list` call
 * per assembly for enumeration, one `--type` call per bound type. A missing
 * `dotnet` SDK is an error naming it, never a stand-down, because an
 * uninspected tree cannot produce honest bindings.
 *
 * Everything out of this file is deterministic: assemblies are scanned in
 * sorted order, types and members are inspected in sorted order, and the
 * descriptor records a `skipped` entry (with a reason) for every member the
 * writer cannot emit.
 *
 * The declarations are raw and host-truthful — receiver-first, with no
 * conversion and no opinion. There is deliberately no Either-wrapped
 * variant: the backend cannot codegen `IO (Either …)`
 * (`use Moggi try/catch` instead), so an Either-wrapped declaration would not
 * compile. The CLR declares no throws, so there is not even the JVM's IO
 * signal: every binding is pure, and promoting an effectful call to `IO`
 * belongs to the hand-written overlay, exactly as the hand-written stdlib
 * bindings do today.
 *
 * The sidecar is the point of this binder, not an accessory: a method with
 * optional parameters cannot be spelled by a declaration alone (the emitter
 * pads the tail), so each such member gets a `signatures` entry
 * `{params, optionalTail}` naming the full IL list. Members without an
 * optional tail get no entry — the declaration is the whole truth there, and
 * the verifier checks it against the inspector.
 */

/** CLR type spellings the binder maps, and the Moggi type each becomes. */
const DOTNET_BIND_PRIMITIVES = [
    'void' => '()',
    'bool' => 'Bool',
    'char' => 'Char',
    'int8' => 'Int8',
    'int16' => 'Int16',
    'int32' => 'Int32',
    'int64' => 'Int',
    'float64' => 'Double',
    'string' => 'String',
];

/**
 * Split an `Id[:version]` coordinate the way `moggi add` spells a NuGet
 * requirement.
 *
 * @return array{id: string, version: ?string}
 */
function parseDotNetCoordinate(string $coordinate): array
{
    $parts = \explode(':', $coordinate);
    if (\count($parts) < 1 || \count($parts) > 2 || $parts[0] === '') {
        throw new \RuntimeException(
            "cannot bind `{$coordinate}` — a NuGet coordinate is `Id[:version]`",
        );
    }
    if (isset($parts[1]) && $parts[1] === '') {
        throw new \RuntimeException(
            "cannot bind `{$coordinate}` — a NuGet coordinate is `Id[:version]`",
        );
    }

    return ['id' => $parts[0], 'version' => $parts[1] ?? null];
}

/**
 * The module a bound library lives in: `Acme.Hello` is `Nuget.Acme.Hello`.
 * The ecosystem prefix keeps three backends' bindings apart (R7); id dots
 * become nesting so the module/file law resolves the generated module with no
 * new lookup rule.
 */
function dotNetBindModuleName(string $id): string
{
    $segments = ['Nuget'];
    foreach (\explode('.', $id) as $part) {
        $segments[] = bindSegmentToModule($part);
    }

    return \implode('.', $segments);
}

/**
 * Describe the bindable types of a tree: assemblies under a `dotnet`
 * directory whose simple name matches the package id, keeping the types under
 * the id's namespace.
 *
 * @return array{backend: string, coordinate: string, module: string, assemblies: list<array{file: string, name: string, digest: string}>, types: list<array<string, mixed>>, functions: list<array<string, mixed>>, consts: list<array<string, mixed>>, skipped: list<array{symbol: string, reason: string}>, sidecar: array<string, mixed>}
 */
function inspectDotNetTree(string $treeRoot, string $id, ?string $version): array
{
    $root = \rtrim($treeRoot, '/');
    if (! \is_dir($root)) {
        throw new \RuntimeException("cannot bind `{$treeRoot}` — no such directory");
    }

    $dotnet = resolveDotnetExecutable();
    if ($dotnet === null) {
        throw new \RuntimeException(
            'cannot bind .NET assemblies without `dotnet` (a .NET SDK); the full moggi distribution ships one',
        );
    }
    $inspector = ensureInspectorBinary($dotnet);
    if ($inspector === null) {
        throw new \RuntimeException('cannot bind .NET assemblies — the CLR inspector could not be built');
    }

    $dlls = [];
    $scan = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    );
    /** @var \SplFileInfo $file */
    foreach ($scan as $file) {
        if (! $file->isFile() || \strtolower($file->getExtension()) !== 'dll') {
            continue;
        }
        if (\basename($file->getPath()) !== 'dotnet') {
            continue;
        }
        if (\strcasecmp(\basename($file->getFilename(), '.dll'), $id) !== 0) {
            continue;
        }
        $dlls[] = $file->getPathname();
    }
    \sort($dlls);
    if ($dlls === []) {
        throw new \RuntimeException(
            "cannot bind `{$id}` — no `{$id}.dll` under a `dotnet/` directory (a NuGet tree vendors it there)",
        );
    }

    $assemblies = [];
    foreach ($dlls as $dll) {
        $digest = @\hash_file('sha256', $dll);
        if ($digest === false) {
            throw new \RuntimeException("cannot bind `{$dll}` — the assembly cannot be read");
        }
        $assemblies[] = ['file' => $dll, 'digest' => 'sha256:' . $digest];
    }

    $prefix = $id . '.';
    $listed = [];
    foreach ($dlls as $i => $dll) {
        $listing = runDotNetInspector($dotnet, $inspector, ['--list', $dll]);
        foreach ((array) ($listing['types'] ?? []) as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $listed[$name] = [
                'dll' => $i,
                'valueType' => (bool) ($entry['valueType'] ?? false),
                'interface' => (bool) ($entry['interface'] ?? false),
                'enum' => (bool) ($entry['enum'] ?? false),
                'abstract' => (bool) ($entry['abstract'] ?? false),
            ];
        }
    }
    \uksort($listed, 'strcasecmp');

    $module = dotNetBindModuleName($id);
    $used = [];
    $types = [];
    $functions = [];
    $consts = [];
    $skipped = [];
    $skip = static function (string $symbol, string $reason) use (&$skipped): void {
        $skipped[] = ['symbol' => $symbol, 'reason' => $reason];
    };
    $claim = static function (string $name) use (&$used): bool {
        if (isset($used[$name])) {
            return false;
        }
        $used[$name] = true;

        return true;
    };

    $bound = [];
    foreach ($listed as $name => $info) {
        if (\strcasecmp($name, $id) !== 0 && \stripos($name, $prefix) !== 0) {
            $skip($name, 'outside namespace ' . $id);

            continue;
        }
        $short = dotNetShortName($name);
        if (! $claim($short)) {
            $skip($name, "name collision on `{$short}`");

            continue;
        }
        $table = runDotNetInspector($dotnet, $inspector, ['--refs', ...$dlls, '--type', $name]);
        if (! ($table['found'] ?? false)) {
            $skip($name, 'the inspector could not read it');

            continue;
        }
        $bound[$name] = [
            'moggi' => $short,
            'valueType' => $info['valueType'],
            'abstract' => $info['abstract'],
            'assembly' => (string) ($table['assembly'] ?? ''),
            'forwardedBy' => (array) ($table['forwardedBy'] ?? []),
            'table' => $table,
        ];
        $types[] = [
            'moggi' => $short,
            'fqn' => $name,
            'path' => $name,
            'valueType' => $info['valueType'],
        ];
    }

    $sidecarTypes = [];
    $sidecarSignatures = [];
    foreach ($bound as $name => $info) {
        $assembly = $info['forwardedBy'][0] ?? $info['assembly'];
        if ($assembly === '') {
            $assembly = \basename((string) $dlls[0], '.dll');
        }
        $sidecarTypes[$assembly][$name] = ['valueType' => $info['valueType']];
        inspectDotNetMembers($name, $info, $bound, $skip, $claim, $functions, $consts, $sidecarSignatures);
    }

    $byName = static fn (array $entry): string => (string) $entry['moggi'];
    foreach (['types' => &$types, 'functions' => &$functions, 'consts' => &$consts] as &$list) {
        \usort($list, static fn (array $a, array $b): int => $byName($a) <=> $byName($b));
    }
    unset($list);
    $seenSkips = [];
    $dedupedSkips = [];
    foreach ($skipped as $skip) {
        $key = $skip['symbol'] . "\0" . $skip['reason'];
        if (! isset($seenSkips[$key])) {
            $seenSkips[$key] = true;
            $dedupedSkips[] = $skip;
        }
    }
    $skipped = $dedupedSkips;
    \usort($skipped, static fn (array $a, array $b): int => $a['symbol'] <=> $b['symbol']);

    $sidecar = [
        'format' => DOTNET_SIDECAR_FORMAT,
        'assemblies' => dotNetSidecarAssemblies($assemblies, $sidecarTypes, $sidecarSignatures, $version),
    ];

    return [
        'backend' => 'dotnet',
        'coordinate' => $version === null ? $id : $id . ':' . $version,
        'module' => $module,
        'assemblies' => \array_map(
            static fn (array $a): array => ['name' => \basename($a['file'], '.dll'), 'digest' => $a['digest']],
            $assemblies,
        ),
        'types' => $types,
        'functions' => $functions,
        'consts' => $consts,
        'skipped' => $skipped,
        'sidecar' => $sidecar,
    ];
}

/**
 * @param array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>} $info
 * @param array<string, array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>}> $bound
 * @param list<array<string, mixed>> $functions
 * @param list<array<string, mixed>> $consts
 * @param array<string, array{params: list<string>, optionalTail: int}> $sidecarSignatures
 */
function inspectDotNetMembers(
    string $name,
    array $info,
    array $bound,
    callable $skip,
    callable $claim,
    array &$functions,
    array &$consts,
    array &$sidecarSignatures,
): void {
    $table = $info['table'];
    $short = $info['moggi'];

    $methods = [];
    foreach ((array) ($table['methods'] ?? []) as $methodName => $overloads) {
        foreach ((array) $overloads as $overload) {
            $methods[] = ['name' => (string) $methodName] + (array) $overload;
        }
    }
    \usort($methods, static fn (array $a, array $b): int => [$a['name'], \implode(',', (array) $a['params']), (int) $a['static']]
        <=> [$b['name'], \implode(',', (array) $b['params']), (int) $b['static']]);

    foreach ($methods as $method) {
        inspectDotNetMethod($name, $short, $method, $bound, $skip, $claim, $functions, $sidecarSignatures);
    }

    $ctors = (array) ($table['ctors'] ?? []);
    \usort($ctors, static fn (array $a, array $b): int => \implode(',', (array) $a['params']) <=> \implode(',', (array) $b['params']));
    foreach ($ctors as $ctor) {
        inspectDotNetCtor($name, $short, $info, (array) $ctor, $bound, $skip, $claim, $functions);
    }

    $fields = (array) ($table['fields'] ?? []);
    \uksort($fields, 'strcasecmp');
    foreach ($fields as $fieldName => $field) {
        inspectDotNetField($name, $short, (string) $fieldName, (array) $field, $bound, $skip, $claim, $consts);
    }

    $properties = (array) ($table['properties'] ?? []);
    \uksort($properties, 'strcasecmp');
    foreach ($properties as $propName => $prop) {
        inspectDotNetProperty($name, $short, (string) $propName, (array) $prop, $bound, $skip, $claim, $consts);
    }
}

/**
 * @param array<string, array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>}> $bound
 * @param list<array<string, mixed>> $functions
 * @param array<string, array{params: list<string>, optionalTail: int}> $sidecarSignatures
 */
function inspectDotNetMethod(
    string $dotted,
    string $short,
    array $method,
    array $bound,
    callable $skip,
    callable $claim,
    array &$functions,
    array &$sidecarSignatures,
): void {
    $label = $dotted . '.' . $method['name'];
    $declared = (string) ($method['declared'] ?? $dotted);
    if ($declared !== $dotted) {
        $skip($label, 'inherited from ' . $declared . ' (only a type\'s own members bind)');

        return;
    }
    if (! ($method['public'] ?? false)) {
        $skip($label, 'not public');

        return;
    }
    if (\str_starts_with((string) $method['name'], 'get_')
        || \str_starts_with((string) $method['name'], 'set_')
        || \str_starts_with((string) $method['name'], 'op_')) {
        $skip($label, 'property plumbing and operators need hand-written bindings');

        return;
    }
    $params = (array) ($method['params'] ?? []);
    $optional = (int) ($method['optional'] ?? 0);
    $required = \array_slice($params, 0, \count($params) - $optional);

    $isStatic = (bool) ($method['static'] ?? false);
    $receiver = $isStatic ? null : $short;
    $result = dotNetIlMoggiType((string) ($method['ret'] ?? 'void'), $bound);
    if ($result === null) {
        $skip($label, 'result type ' . dotNetIlSpelling((string) ($method['ret'] ?? 'void')));

        return;
    }
    $paramWords = [];
    if ($receiver !== null) {
        $paramWords[] = $receiver;
    }
    foreach ($required as $param) {
        $word = dotNetIlMoggiType((string) $param, $bound);
        if ($word === null) {
            $skip($label, 'parameter type ' . dotNetIlSpelling((string) $param));

            return;
        }
        $paramWords[] = $word;
    }

    $moggi = \lcfirst($short) . \ucfirst((string) $method['name']);
    if (! $claim($moggi)) {
        $skip($label, "name collision on `{$moggi}` (an overload has no single Moggi spelling)");

        return;
    }

    $paramWords[] = $result;
    $sig = \implode(' -> ', $paramWords);
    $path = $dotted . ($isStatic ? ':' : '.') . $method['name'];

    $expect = dotNetSigIlParams($paramWords, $result, $isStatic ? 'static' : 'instance', $bound);
    $roundTripped = $expect === null ? null : \array_map(
        static fn (string $il): string => dotNetIlMoggiRoundTrip($il),
        $expect,
    );
    $declaredIl = [];
    foreach ($required as $param) {
        $declaredIl[] = dotNetIlMoggiRoundTrip((string) $param);
    }
    if ($roundTripped === null || $roundTripped !== $declaredIl) {
        $skip($label, 'round-trip mismatch (host [' . \implode(', ', $required) . '])');

        return;
    }

    if ($optional > 0) {
        $sidecarSignatures[$moggi] = [
            'params' => $params,
            'optionalTail' => $optional,
        ];
    }

    $functions[] = [
        'moggi' => $moggi,
        'path' => $path,
        'dispatch' => $isStatic ? 'static' : 'instance',
        'sig' => $sig,
        'ilParams' => $required,
        'ilRet' => (string) ($method['ret'] ?? 'void'),
        'optionalTail' => $optional,
    ];
}

/**
 * @param array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>} $info
 * @param array<string, array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>}> $bound
 * @param list<array<string, mixed>> $functions
 */
function inspectDotNetCtor(
    string $dotted,
    string $short,
    array $info,
    array $ctor,
    array $bound,
    callable $skip,
    callable $claim,
    array &$functions,
): void {
    $label = $dotted . '.__con';
    if (! ($ctor['public'] ?? false)) {
        $skip($label, 'not public');

        return;
    }
    if ($info['abstract']) {
        $skip($label, 'constructors of an abstract class cannot run');

        return;
    }
    $params = (array) ($ctor['params'] ?? []);
    if ($params === []) {
        $skip($label, 'a parameterless constructor has no Moggi spelling (valuetypes use __default)');

        return;
    }
    $paramWords = [];
    foreach ($params as $param) {
        $word = dotNetIlMoggiType((string) $param, $bound);
        if ($word === null) {
            $skip($label, 'parameter type ' . dotNetIlSpelling((string) $param));

            return;
        }
        $paramWords[] = $word;
    }
    $moggi = 'new' . $short;
    if (! $claim($moggi)) {
        $skip($label, "name collision on `{$moggi}` (a constructor overload has no single Moggi spelling)");

        return;
    }
    $paramWords[] = $short;

    $functions[] = [
        'moggi' => $moggi,
        'path' => $dotted . ':__con',
        'dispatch' => 'constructor',
        'sig' => \implode(' -> ', $paramWords),
        'ilParams' => $params,
        'ilRet' => 'void',
        'optionalTail' => 0,
    ];
}

/**
 * @param array<string, array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>}> $bound
 * @param list<array<string, mixed>> $consts
 */
function inspectDotNetField(
    string $dotted,
    string $short,
    string $fieldName,
    array $field,
    array $bound,
    callable $skip,
    callable $claim,
    array &$consts,
): void {
    $label = $dotted . ':' . $fieldName;
    if (! ($field['public'] ?? false) || ! ($field['static'] ?? false)) {
        $skip($label, 'only public static fields bind');

        return;
    }
    if (($field['literal'] ?? false)) {
        $skip($label, 'a C# const has no runtime field to load (write the value by hand)');

        return;
    }
    if (! ($field['initOnly'] ?? false)) {
        $skip($label, 'a mutable static has no const spelling');

        return;
    }
    $type = dotNetIlMoggiType((string) ($field['type'] ?? ''), $bound);
    if ($type === null) {
        $skip($label, 'field type ' . dotNetIlSpelling((string) ($field['type'] ?? '')));

        return;
    }
    $moggi = \lcfirst($short) . \ucfirst(\strtolower($fieldName));
    if (! $claim($moggi)) {
        $skip($label, "name collision on `{$moggi}`");

        return;
    }
    $consts[] = [
        'moggi' => $moggi,
        'path' => $dotted . ':' . $fieldName,
        'type' => $type,
    ];
}

/**
 * A static property with a getter reads through a call the emitter asks the
 * host for, so the binder can name it like a field.
 *
 * @param array<string, array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>}> $bound
 * @param list<array<string, mixed>> $consts
 */
function inspectDotNetProperty(
    string $dotted,
    string $short,
    string $propName,
    array $prop,
    array $bound,
    callable $skip,
    callable $claim,
    array &$consts,
): void {
    $label = $dotted . ':' . $propName;
    if (! ($prop['public'] ?? false) || ! ($prop['static'] ?? false) || ! ($prop['hasGetter'] ?? false)) {
        $skip($label, 'only public static readable properties bind');

        return;
    }
    $type = dotNetIlMoggiType((string) ($prop['type'] ?? ''), $bound);
    if ($type === null) {
        $skip($label, 'property type ' . dotNetIlSpelling((string) ($prop['type'] ?? '')));

        return;
    }
    $moggi = \lcfirst($short) . \ucfirst(\strtolower($propName));
    if (! $claim($moggi)) {
        $skip($label, "name collision on `{$moggi}`");

        return;
    }
    $consts[] = [
        'moggi' => $moggi,
        'path' => $dotted . ':' . $propName,
        'type' => $type,
    ];
}

/**
 * A CLR type spelling as a Moggi type word, or null when the binder cannot
 * spell it. Primitives and `string` map directly; any other class becomes the
 * nominal type the inspector already declared. Unsigned integers and `float32`
 * have no exact Moggi counterpart; arrays, pointers, byrefs, generic
 * definitions and type variables are not representable — all honest
 * omissions.
 *
 * @param array<string, array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>}> $bound full CLR name => info
 */
function dotNetIlMoggiType(string $il, array $bound): ?string
{
    if (isset(DOTNET_BIND_PRIMITIVES[$il])) {
        $word = DOTNET_BIND_PRIMITIVES[$il];

        return $word === '()' ? null : $word;
    }
    if (\preg_match('/^(class|valuetype) \[(.+)\](.+)$/', $il, $match) === 1) {
        $name = \str_replace('/', '+', $match[3]);
        foreach ($bound as $full => $info) {
            if ($full === $name || \str_replace('/', '+', $full) === $name) {
                return $info['moggi'];
            }
        }

        return null;
    }

    return null;
}

/** What `dotNetIlMoggiType` refused, for the `skipped` record. */
function dotNetIlSpelling(string $il): string
{
    foreach (['uint8' => 'byte', 'uint16' => 'ushort', 'uint32' => 'uint', 'uint64' => 'ulong'] as $spelling => $name) {
        if ($il === $spelling) {
            return $name . ' (no exact Moggi counterpart)';
        }
    }
    if ($il === 'float32') {
        return 'float (no Moggi Float)';
    }
    if ($il === 'object') {
        return 'object (unrepresentable without reification)';
    }
    if (\str_ends_with($il, '[]') || \str_ends_with($il, '[...]')) {
        return 'array type ' . $il;
    }
    if (\str_ends_with($il, '&') || \str_ends_with($il, '*')) {
        return 'byref/pointer type ' . $il;
    }
    if (\str_starts_with($il, '!')) {
        return 'type variable ' . $il;
    }
    if (\str_contains($il, '`')) {
        return 'generic type ' . $il;
    }
    if (\preg_match('/^(class|valuetype) \[(.+)\](.+)$/', $il, $match) === 1) {
        return 'class ' . $match[3] . ' (outside the bound set)';
    }

    return 'type ' . $il;
}

/**
 * The IL parameter list a generated signature rebuilds to: the emitter's own
 * mapping run backwards over the required prefix (the instance receiver is
 * implicit on the wire). Null when a word has no mapping — itself a reason to
 * omit.
 *
 * @param list<string> $words the signature's words including the result
 * @param array<string, array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>}> $bound
 * @return ?list<string>
 */
function dotNetSigIlParams(array $words, string $result, string $dispatch, array $bound): ?array
{
    $params = $words;
    \array_pop($params);
    if ($dispatch === 'instance') {
        \array_shift($params);
    }
    $out = [];
    foreach ($params as $word) {
        $il = dotNetMoggiWordIl($word, $bound);
        if ($il === null) {
            return null;
        }
        $out[] = $il;
    }

    return $out;
}

/** @param array<string, array{moggi: string, valueType: bool, abstract: bool, assembly: string, forwardedBy: list<string>, table: array<string, mixed>}> $bound */
function dotNetMoggiWordIl(string $word, array $bound): ?string
{
    $inverse = \array_flip(\array_filter(
        DOTNET_BIND_PRIMITIVES,
        static fn (string $moggi): bool => $moggi !== '()',
    ));
    if (isset($inverse[$word])) {
        return $inverse[$word];
    }
    foreach ($bound as $full => $info) {
        if ($info['moggi'] === $word) {
            $assembly = $info['forwardedBy'][0] ?? $info['assembly'];
            $kind = $info['valueType'] ? 'valuetype' : 'class';

            return $kind . ' [' . $assembly . ']' . \str_replace('+', '/', $full);
        }
    }

    return null;
}

/**
 * The host spelling a required IL parameter carries for the round-trip
 * comparison: the emitter qualifies nominals with the sidecar's assembly, so
 * the comparison strips scope the way the verifier does.
 */
function dotNetIlMoggiRoundTrip(string $il): string
{
    if (\preg_match('/^(class|valuetype) \[(.+)\](.+)$/', $il, $match) === 1) {
        return $match[1] . ' ' . $match[3];
    }

    return $il;
}

/** The short name of a CLR type: after the last `.`, then the last `/` or `+`. */
function dotNetShortName(string $full): string
{
    $name = (string) \substr($full, (int) \strrpos('.' . $full, '.'));
    $name = (string) \substr(\str_replace('+', '/', $name) . '/', 0, -1);
    $slash = \strrpos($name, '/');

    return $slash === false ? $name : (string) \substr($name, $slash + 1);
}

/**
 * The `assemblies` map of the sidecar: one entry per defining assembly, with
 * the digest of the dll the types were read from, so a stale sidecar refuses
 * the compile instead of describing a new assembly.
 *
 * @param list<array{file: string, digest: string}> $assemblies
 * @param array<string, array<string, array{valueType: bool}>> $sidecarTypes assembly => type => spec
 * @param array<string, array{params: list<string>, optionalTail: int}> $sidecarSignatures
 */
function dotNetSidecarAssemblies(
    array $assemblies,
    array $sidecarTypes,
    array $sidecarSignatures,
    ?string $version,
): array {
    $byName = [];
    foreach ($assemblies as $assembly) {
        $byName[\basename($assembly['file'], '.dll')] = $assembly['digest'];
    }
    $out = [];
    $names = \array_unique([...\array_keys($sidecarTypes), ...\array_keys($byName)]);
    \sort($names);
    foreach ($names as $assembly) {
        $entry = [];
        if (isset($sidecarTypes[$assembly])) {
            $types = $sidecarTypes[$assembly];
            \uksort($types, 'strcasecmp');
            $entry['types'] = $types;
        }
        if ($assembly !== '' && $assembly === ($names[0] ?? null) && $sidecarSignatures !== []) {
            $signatures = $sidecarSignatures;
            \uksort($signatures, 'strcasecmp');
            $entry['signatures'] = $signatures;
        }
        if ($version !== null) {
            $entry['version'] = $version;
        }
        if (isset($byName[$assembly])) {
            $entry['digest'] = $byName[$assembly];
        }
        $entry['generated-by'] = 'moggi bind ' . (compilerVersion() ?? 'unknown');
        $out[$assembly] = $entry;
    }

    return $out;
}

/** Run the CLR inspector with the given trailing arguments and decode its JSON. */
function runDotNetInspector(string $dotnet, string $inspector, array $args): array
{
    $command = [$dotnet, $inspector, ...$args];
    $captured = runProcess($command, null, dotnetCliEnv(), false, 120);
    if (($captured['exitCode'] ?? -1) !== 0) {
        throw new \RuntimeException(
            'the CLR inspector exited ' . ($captured['exitCode'] ?? '?')
            . ': ' . \trim((string) ($captured['stderr'] ?? '') . "\n" . ($captured['stdout'] ?? '')),
        );
    }
    $decoded = \json_decode((string) ($captured['stdout'] ?? ''), true);
    if (! \is_array($decoded)) {
        throw new \RuntimeException('the CLR inspector printed no JSON document');
    }
    if (isset($decoded['error'])) {
        throw new \RuntimeException('the CLR inspector failed: ' . (string) $decoded['error']);
    }

    return $decoded;
}
