<?php declare(strict_types=1);

namespace Moggi\Registry\Bind;

use function Moggi\Backend\Inspect\findJavapBinaryWithSource;
use function Moggi\Compiler\runProcess;

/**
 * The JVM side of `moggi bind`: read a Maven-style tree on disk (jars under
 * a `jvm` directory, the same shape `discoverVendorJvmDirs()` resolves) and
 * describe what it can become as Moggi declarations.
 *
 * The host is asked with `javap -s -p`, one batched invocation per run: it
 * prints exactly what a declaration needs — the descriptor, the
 * class/interface/enum header, and the `throws` clause — so nothing is
 * guessed. `javap` is resolved the way the verifier resolves it (`JAVA_HOME`
 * first, `PATH` last); a missing `javap` is an error naming it, never a
 * stand-down, because an uninspected tree cannot produce honest bindings.
 *
 * Everything out of this file is deterministic: jars are scanned in sorted
 * order, classes are inspected in sorted order, and the descriptor records a
 * `skipped` entry (with a reason) for every member the writer cannot emit.
 *
 * Layering follows the plan's Layer 1 only — raw, host-truthful,
 * receiver-first declarations with no conversion and no opinion. There is no
 * Layer 2 yet, deliberately: the JVM backend cannot codegen `IO (Either …)`
 * (`use Moggi try/catch` instead), so an Either-wrapped declaration would not
 * compile. A throwing method surfaces as `IO`, a pure one as a pure function,
 * and unchecked exceptions propagate to the caller exactly as the
 * hand-written stdlib bindings behave today.
 */

/** Host field descriptors Phase 2 maps, and the Moggi type each becomes. */
const JVM_BIND_PRIMITIVES = [
    'Z' => 'Bool',
    'B' => 'Int8',
    'S' => 'Int16',
    'I' => 'Int32',
    'J' => 'Int',
    'D' => 'Double',
    'C' => 'Char',
    'Ljava/lang/String;' => 'String',
];

/**
 * Split a `group:artifact[:version]` coordinate the way `moggi add` spells a
 * Maven requirement.
 *
 * @return array{group: string, artifact: string, version: ?string}
 */
function parseJvmCoordinate(string $coordinate): array
{
    $parts = explode(':', $coordinate);
    if (\count($parts) < 2 || \count($parts) > 3 || $parts[0] === '' || $parts[1] === '') {
        throw new \RuntimeException(
            "cannot bind `{$coordinate}` — a Maven coordinate is `group:artifact[:version]`",
        );
    }

    return [
        'group' => $parts[0],
        'artifact' => $parts[1],
        'version' => $parts[2] ?? null,
    ];
}

/**
 * The module a bound library lives in: `com.acme:hello` is
 * `Maven.Com.Acme.Hello`. The ecosystem prefix keeps three backends' bindings
 * apart (R7); group dots become nesting so the module/file law resolves the
 * generated module with no new lookup rule.
 */
function jvmBindModuleName(string $group, string $artifact): string
{
    $segments = ['Maven'];
    foreach (explode('.', $group) as $part) {
        $segments[] = bindSegmentToModule($part);
    }
    foreach (explode('.', $artifact) as $part) {
        $segments[] = bindSegmentToModule($part);
    }

    return \implode('.', $segments);
}

/** The class-file prefix only this coordinate's classes live under: `com/acme/`. */
function jvmClassPrefix(string $group): string
{
    return \str_replace('.', '/', $group) . '/';
}

/**
 * Describe the bindable classes of a tree: jars under a `jvm` directory (the discovery
 * shape the backend itself scans), keeping the classes under the coordinate's
 * group prefix.
 *
 * @return array{backend: string, coordinate: string, module: string, jars: list<string>, types: list<array<string, mixed>>, functions: list<array<string, mixed>>, consts: list<array<string, mixed>>, skipped: list<array{symbol: string, reason: string}>}
 */
function inspectJvmTree(string $treeRoot, string $group, string $artifact): array
{
    $root = \rtrim($treeRoot, '/');
    if (!\is_dir($root)) {
        throw new \RuntimeException("cannot bind `{$treeRoot}` — no such directory");
    }

    $jars = [];
    $scan = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    );
    /** @var \SplFileInfo $file */
    foreach ($scan as $file) {
        if (!$file->isFile() || !\str_ends_with($file->getFilename(), '.jar')) {
            continue;
        }
        if (\basename($file->getPath()) !== 'jvm') {
            continue;
        }
        $jars[] = $file->getPathname();
    }
    \sort($jars);
    if ($jars === []) {
        throw new \RuntimeException(
            "cannot bind `{$treeRoot}` — no jars under a `jvm/` directory (a Maven tree vendors them there)",
        );
    }

    $resolved = findJavapBinaryWithSource();
    $javap = $resolved['binary'] ?? null;
    if ($javap === null) {
        throw new \RuntimeException(
            'cannot bind JVM classes without `javap` (a JDK); the full moggi distribution ships one',
        );
    }

    $prefix = jvmClassPrefix($group);
    $classes = [];
    foreach ($jars as $jar) {
        foreach (jvmJarClasses($jar) as $internal) {
            if (!\str_starts_with($internal, $prefix)) {
                continue;
            }
            if (\str_contains($internal, '$')) {
                continue;
            }
            $classes[$internal] = true;
        }
    }
    $classes = \array_keys($classes);
    \sort($classes);
    if ($classes === []) {
        throw new \RuntimeException(
            "cannot bind `{$group}:{$artifact}` — no classes under `{$prefix}` in the tree's jars",
        );
    }

    $dotted = \array_map(
        static fn (string $internal): string => \str_replace('/', '.', $internal),
        $classes,
    );
    $command = [$javap, '-s', '-p', '-classpath', \implode(\PATH_SEPARATOR, $jars), ...$dotted];
    $captured = runProcess($command, null, null, false, 120);
    if (($captured['exitCode'] ?? -1) !== 0) {
        throw new \RuntimeException(
            "`javap` ({$javap}) exited " . ($captured['exitCode'] ?? '?')
            . ' — the tree cannot be inspected: ' . \trim((string) ($captured['stderr'] ?? '')),
        );
    }
    $tables = parseJavapOutput((string) ($captured['stdout'] ?? ''));

    $module = jvmBindModuleName($group, $artifact);
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
    foreach ($classes as $internal) {
        $short = (string) \substr($internal, (int) \strrpos($internal, '/') + 1);
        if (!$claim($short)) {
            $skip(\str_replace('/', '.', $internal), "name collision on `{$short}`");

            continue;
        }
        $table = $tables[\str_replace('/', '.', $internal)] ?? null;
        if ($table === null) {
            $skip(\str_replace('/', '.', $internal), '`javap` printed no such class');

            continue;
        }
        $bound[$internal] = ['moggi' => $short, 'interface' => $table['interface']];
        $types[] = [
            'moggi' => $short,
            'fqn' => \str_replace('/', '.', $internal),
            'path' => ($table['interface'] ? 'interface ' : '') . \str_replace('/', '.', $internal),
            'interface' => $table['interface'],
        ];
    }

    foreach ($bound as $internal => $info) {
        $dottedName = \str_replace('/', '.', $internal);
        $table = $tables[$dottedName] ?? null;
        if ($table === null) {
            continue;
        }
        foreach ($table['methods'] as $method) {
            inspectJvmMethod($dottedName, $info, $method, $table, $bound, $skip, $claim, $functions);
        }
        foreach ($table['fields'] as $field) {
            inspectJvmField($dottedName, $info, $field, $bound, $skip, $claim, $consts);
        }
    }

    $byName = static fn (array $entry): string => (string) $entry['moggi'];
    foreach (['types' => &$types, 'functions' => &$functions, 'consts' => &$consts] as &$list) {
        \usort($list, static fn (array $a, array $b): int => $byName($a) <=> $byName($b));
    }
    unset($list);
    \usort($skipped, static fn (array $a, array $b): int => $a['symbol'] <=> $b['symbol']);

    $coordinate = $group . ':' . $artifact;

    return [
        'backend' => 'jvm',
        'coordinate' => $coordinate,
        'module' => $module,
        'jars' => $jars,
        'types' => $types,
        'functions' => $functions,
        'consts' => $consts,
        'skipped' => $skipped,
    ];
}

/** @param list<array<string, mixed>> $functions @param array<string, array{moggi: string, interface: bool}> $bound */
function inspectJvmMethod(
    string $dotted,
    array $info,
    array $method,
    array $table,
    array $bound,
    callable $skip,
    callable $claim,
    array &$functions,
): void {
    $label = $dotted . '.' . $method['name'];
    if (!$method['public']) {
        $skip($label, 'not public');

        return;
    }
    $parsed = parseJvmMethodDescriptor((string) $method['descriptor']);
    if ($parsed === null) {
        $skip($label, 'unparseable descriptor ' . $method['descriptor']);

        return;
    }

    $isCtor = $method['name'] === $table['simple'] || $method['name'] === '<init>';
    if ($isCtor && $table['abstract']) {
        $skip($label, 'constructors of an abstract class cannot run');

        return;
    }

    $receiver = null;
    $params = $parsed['params'];
    if ($isCtor) {
        $dispatch = 'constructor';
        $result = $info['moggi'];
        $moggi = 'new' . $info['moggi'];
        $path = $dotted . ':__con';
    } elseif ($method['static']) {
        $dispatch = 'static';
        $result = jvmDescriptorMoggiType($parsed['result'], $bound);
        $moggi = \lcfirst($info['moggi']) . \ucfirst($method['name']);
        $path = $dotted . ':' . $method['name'];
    } else {
        $dispatch = 'instance';
        $receiver = $info['moggi'];
        $result = jvmDescriptorMoggiType($parsed['result'], $bound);
        $moggi = \lcfirst($info['moggi']) . \ucfirst($method['name']);
        $path = $dotted . '.' . $method['name'];
    }

    if ($dispatch === 'constructor') {
        $resultWord = $info['moggi'];
    } elseif ($parsed['result'] === 'V') {
        $resultWord = '()';
    } elseif ($result === null) {
        $skip($label, 'result type ' . jvmDescriptorSpelling($parsed['result']));

        return;
    } else {
        $resultWord = $result;
    }

    $paramWords = [];
    if ($receiver !== null) {
        $paramWords[] = $receiver;
    }
    foreach ($params as $param) {
        $word = jvmDescriptorMoggiType($param, $bound);
        if ($word === null) {
            $skip($label, 'parameter type ' . jvmDescriptorSpelling($param));

            return;
        }
        $paramWords[] = $word;
    }

    if (!$claim($moggi)) {
        $skip($label, "name collision on `{$moggi}` (an overload has no Moggi spelling)");

        return;
    }

    $throws = $method['throws'] !== [];
    if ($throws) {
        $paramWords[] = 'IO ' . $resultWord;
    } else {
        $paramWords[] = $resultWord;
    }
    $sig = \implode(' -> ', $paramWords);

    $expect = jvmSigDescriptor($paramWords, $resultWord, $dispatch, $bound);
    if ($expect !== $method['descriptor']) {
        $skip($label, "round-trip mismatch (host {$method['descriptor']}, rebuilt {$expect})");

        return;
    }

    $functions[] = [
        'moggi' => $moggi,
        'path' => $path,
        'dispatch' => $dispatch,
        'sig' => $sig,
        'descriptor' => $method['descriptor'],
        'throws' => $method['throws'],
    ];
}

/** @param list<array<string, mixed>> $consts @param array<string, array{moggi: string, interface: bool}> $bound */
function inspectJvmField(
    string $dotted,
    array $info,
    array $field,
    array $bound,
    callable $skip,
    callable $claim,
    array &$consts,
): void {
    $label = $dotted . '.' . $field['name'];
    if (!$field['public'] || !$field['static'] || !$field['final']) {
        $skip($label, 'only public static final fields bind');

        return;
    }
    $type = jvmDescriptorMoggiType((string) $field['descriptor'], $bound);
    if ($type === null) {
        $skip($label, 'field type ' . jvmDescriptorSpelling((string) $field['descriptor']));

        return;
    }
    $moggi = \lcfirst($info['moggi']) . \ucfirst(\strtolower($field['name']));
    if (!$claim($moggi)) {
        $skip($label, "name collision on `{$moggi}`");

        return;
    }
    $consts[] = [
        'moggi' => $moggi,
        'path' => $dotted . ':' . $field['name'],
        'type' => $type,
    ];
}

/**
 * A host field descriptor as a Moggi type word, or null when Phase 2 cannot
 * spell it. Primitives and `String` map directly; any other class becomes the
 * nominal type the inspector already declared; arrays, type variables and
 * `float` (no Moggi `Float`) are honest omissions.
 *
 * @param array<string, array{moggi: string, interface: bool}> $bound internal name => info
 */
function jvmDescriptorMoggiType(string $field, array $bound): ?string
{
    if (isset(JVM_BIND_PRIMITIVES[$field])) {
        return JVM_BIND_PRIMITIVES[$field];
    }
    if (\preg_match('#^L([A-Za-z0-9_/]+);$#', $field, $match) === 1) {
        $internal = $match[1];
        if (isset($bound[$internal])) {
            return $bound[$internal]['moggi'];
        }

        return null;
    }

    return null;
}

/** What `jvmDescriptorMoggiType` refused, for the `skipped` record. */
function jvmDescriptorSpelling(string $field): string
{
    if ($field === 'F') {
        return 'float (no Moggi Float)';
    }
    if (\str_starts_with($field, '[')) {
        return 'array type ' . $field;
    }
    if (\str_starts_with($field, 'T')) {
        return 'type variable ' . $field;
    }
    if (\preg_match('#^L([A-Za-z0-9_/]+);$#', $field, $match) === 1) {
        return 'class ' . \str_replace('/', '.', $match[1]) . ' (outside the bound set)';
    }

    return 'type ' . $field;
}

/**
 * The host descriptor a generated signature rebuilds to: the emitter's own
 * mapping run backwards. Params drop the instance receiver (implicit on the
 * wire) and constructors end in `V`; the `IO` around a throwing result is a
 * Moggi convention with no host spelling, so it is unwrapped, not mapped.
 *
 * @param list<string> $words the signature's words including the result
 * @param array<string, array{moggi: string, interface: bool}> $bound
 */
function jvmSigDescriptor(array $words, string $resultWord, string $dispatch, array $bound): ?string
{
    $params = $words;
    \array_pop($params);
    if ($dispatch === 'instance') {
        \array_shift($params);
    }
    $out = '(';
    foreach ($params as $word) {
        $field = jvmMoggiWordDescriptor($word, $bound);
        if ($field === null) {
            return null;
        }
        $out .= $field;
    }
    $out .= ')';
    if ($dispatch === 'constructor') {
        return $out . 'V';
    }
    if ($resultWord === '()') {
        return $out . 'V';
    }
    $field = jvmMoggiWordDescriptor($resultWord, $bound);
    if ($field === null) {
        return null;
    }

    return $out . $field;
}

/** @param array<string, array{moggi: string, interface: bool}> $bound */
function jvmMoggiWordDescriptor(string $word, array $bound): ?string
{
    $inverse = \array_flip(JVM_BIND_PRIMITIVES);
    if (isset($inverse[$word])) {
        return $inverse[$word];
    }
    foreach ($bound as $internal => $info) {
        if ($info['moggi'] === $word) {
            return 'L' . $internal . ';';
        }
    }

    return null;
}

/**
 * Split a method descriptor into parameter and result field descriptors.
 *
 * @return ?array{params: list<string>, result: string}
 */
function parseJvmMethodDescriptor(string $descriptor): ?array
{
    if (!\str_starts_with($descriptor, '(')) {
        return null;
    }
    $params = [];
    $i = 1;
    $n = \strlen($descriptor);
    while ($i < $n && $descriptor[$i] !== ')') {
        $field = jvmReadFieldDescriptor($descriptor, $i);
        if ($field === null) {
            return null;
        }
        $params[] = $field;
    }
    if ($i >= $n || $descriptor[$i] !== ')') {
        return null;
    }
    ++$i;
    $result = jvmReadFieldDescriptor($descriptor, $i);
    if ($result === null || $i !== $n) {
        return null;
    }

    return ['params' => $params, 'result' => $result];
}

/** Read one field descriptor at `$i`, advancing past it, or null. */
function jvmReadFieldDescriptor(string $descriptor, int &$i): ?string
{
    $start = $i;
    $n = \strlen($descriptor);
    while ($i < $n && $descriptor[$i] === '[') {
        ++$i;
    }
    if ($i >= $n) {
        return null;
    }
    $char = $descriptor[$i];
    if ($char === 'L') {
        $end = \strpos($descriptor, ';', $i);
        if ($end === false) {
            return null;
        }
        $i = $end + 1;

        return \substr($descriptor, $start, $i - $start);
    }
    if (\str_contains('ZBSIJFDCV', $char)) {
        ++$i;

        return \substr($descriptor, $start, $i - $start);
    }

    return null;
}

/**
 * Read `javap -s -p` output for many classes at once: each member declaration
 * line is followed by its `descriptor:` line, and each class opens with a
 * `Compiled from` line and a header ending in `{`.
 *
 * @return array<string, array{simple: string, interface: bool, abstract: bool, methods: list<array{name: string, descriptor: string, public: bool, static: bool, throws: list<string>}>, fields: list<array{name: string, descriptor: string, public: bool, static: bool, final: bool}>}>
 */
function parseJavapOutput(string $output): array
{
    $tables = [];
    $current = null;
    $declaration = null;

    foreach (\preg_split('/\R/', $output) ?: [] as $line) {
        $trimmed = \trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (\str_starts_with($trimmed, 'Compiled from')) {
            $current = null;
            $declaration = null;

            continue;
        }
        if (\preg_match('/^descriptor:\s*(\S+)\s*$/', $trimmed, $match) === 1) {
            if ($current !== null && $declaration !== null) {
                jvmAddMember($tables[$current], $declaration, $match[1]);
                $declaration = null;
            }

            continue;
        }
        if (\str_ends_with($trimmed, '{')) {
            $header = \substr($trimmed, 0, -1);
            if (\preg_match('/(?:class|interface|enum|record)\s+([A-Za-z_$][\w$.]*)/', $header, $match) === 1) {
                $current = $match[1];
                $tables[$current] = [
                    'simple' => (string) \substr($current, (int) \strrpos($current, '.') + 1),
                    'interface' => \preg_match('/\binterface\b/', $header) === 1,
                    'abstract' => \preg_match('/\babstract\b/', $header) === 1,
                    'methods' => [],
                    'fields' => [],
                ];
            } else {
                $current = null;
            }
            $declaration = null;

            continue;
        }
        $declaration = $trimmed;
    }

    return $tables;
}

/**
 * @param array{simple: string, interface: bool, abstract: bool, methods: list<array{name: string, descriptor: string, public: bool, static: bool, throws: list<string>}>, fields: list<array{name: string, descriptor: string, public: bool, static: bool, final: bool}>} $table
 */
function jvmAddMember(array &$table, string $declaration, string $descriptor): void
{
    if (\str_contains($declaration, '{}')) {
        return;
    }
    $public = \str_starts_with($declaration, 'public ');
    $isStatic = \preg_match('/\bstatic\b/', $declaration) === 1;

    if (\str_contains($declaration, '(')) {
        if (\preg_match_all('/\b([A-Za-z_$][\w$]*)\s*\(/', $declaration, $matches) === 0) {
            return;
        }
        $name = (string) \end($matches[1]);
        $throws = [];
        $body = (string) \preg_replace('/\s*;\s*$/', '', $declaration);
        if (\preg_match('/\bthrows\b\s+(.+)$/', $body, $throwMatch) === 1) {
            foreach (explode(',', $throwMatch[1]) as $thrown) {
                $thrown = \trim($thrown);
                if ($thrown !== '') {
                    $throws[] = $thrown;
                }
            }
        }
        $table['methods'][] = [
            'name' => $name,
            'descriptor' => $descriptor,
            'public' => $public,
            'static' => $isStatic,
            'throws' => $throws,
        ];

        return;
    }

    $head = \preg_replace('/\s*=.*$/s', ';', $declaration) ?? $declaration;
    if (\preg_match('/([A-Za-z_$][\w$]*)\s*;\s*$/', $head, $match) !== 1) {
        return;
    }
    $table['fields'][] = [
        'name' => $match[1],
        'descriptor' => $descriptor,
        'public' => $public,
        'static' => $isStatic,
        'final' => \preg_match('/\bfinal\b/', $declaration) === 1,
    ];
}

/** The class-file entries of a jar as internal names (`com/acme/Foo`), sorted. */
function jvmJarClasses(string $jar): array
{
    $zip = new \ZipArchive();
    if ($zip->open($jar) !== true) {
        throw new \RuntimeException("cannot bind `{$jar}` — the jar cannot be read");
    }
    try {
        $classes = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = \str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || !\str_ends_with($name, '.class') || \str_starts_with($name, 'META-INF/')) {
                continue;
            }
            $classes[] = \substr($name, 0, -6);
        }
    } finally {
        $zip->close();
    }
    \sort($classes);

    return $classes;
}
