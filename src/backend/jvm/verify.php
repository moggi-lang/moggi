<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Verify;

use function Moggi\Backend\Inspect\findJavapBinary;
use function Moggi\Backend\Inspect\findJavapBinaryWithSource;

use function Moggi\Backend\Jvm\Dependencies\discoverVendorJvmDirs;

use Moggi\Backend\VerificationUnavailable;

use function Moggi\Compiler\runProcess;
use function Moggi\Paths\globPaths;

use Moggi\Syntax\Ast;

/**
 * Verify a `foreign jvm` declaration against the host class it names.
 *
 * A `foreign import` is an axiom: the compiler emits the member call it is told to emit, so a
 * mistyped member, a wrong arity or a wrong type is a runtime `NoSuchMethodError`/`ClassCastException`
 * rather than a diagnostic. The host can be asked, though — `javap -s` prints exactly what the
 * compiler needs to compare against (the member list and its descriptors), and it is bundled with
 * every JDK, so the check needs nothing the JVM backend does not already have.
 *
 * The comparison is against the descriptor the compiler is about to emit (`$nativeSig`), not a
 * re-inference: whatever the declaration would put on the wire is what the host is asked about, so
 * this can never disagree with codegen.
 *
 * When the class cannot be read the declaration is left alone — with one deliberate exception. A
 * missing `javap` is an error naming it, never a stand-down: verification that cannot keep its
 * promise must refuse the declaration, not wave it through. A carried third-party tree of jars is
 * conclusive rather than comforting, because those jars are on `javap`'s classpath already; only
 * vendor `.java` sources (which the backend compiles and `javap` never sees) make "not found"
 * inconclusive, and then the declaration is accepted with a warning that says so.
 *
 * @param 'function'|'const'|'type' $kind
 * @param array{dispatch?: string, classPath?: ?string, hostMember?: ?string} $pathInfo
 * @param ?string $nativeSig the descriptor the emitter will use (from `foreignNativeSig`)
 */
function verifyForeignImportAgainstHost(
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

    if ($nativeSig === null || $nativeSig === '') {
        return null;
    }

    $classPath = (string) ($pathInfo['classPath'] ?? '');
    if ($classPath === '') {
        return null;
    }

    $resolved = findJavapBinaryWithSource();
    warnOnceWhenPathJavap($resolved);

    $class = javapClassTable($classPath, $resolved['binary']);
    if ($class === null) {
        return null;
    }

    if (! $class['found']) {
        if (carriedVendorJavaPresent()) {
            warnOnceUnverifiedVendorJava($name, $classPath);

            return null;
        }

        return "jvm class `{$classPath}` was not found on the compile classpath";
    }

    $member = (string) ($pathInfo['hostMember'] ?? '');
    if ($member === '__con') {
        $member = '<init>';
    }

    if ($kind === 'const') {
        return verifyForeignField($class, $classPath, $member, $nativeSig);
    }

    return verifyForeignMethod($class, $classPath, $member, $nativeSig);
}

/**
 * The host class as `javap -s -p` describes it.
 *
 * @return ?array{
 *     found: bool,
 *     simple: string,
 *     methods: array<string, list<array{descriptor: string, public: bool}>>,
 *     fields: array<string, array{descriptor: string, public: bool}>
 * }
 */
/**
 * Warn once per process when verification answers from `PATH` instead of the build JDK.
 *
 * @param array{binary: ?string, source: ?string} $resolved
 */
function warnOnceWhenPathJavap(array $resolved): void
{
    static $warned = false;
    if ($warned || ($resolved['source'] ?? null) !== 'PATH' || ($resolved['binary'] ?? null) === null) {
        return;
    }
    $warned = true;
    \fwrite(
        STDERR,
        'warning: verifying `foreign jvm` declarations with `javap` from PATH'
        . " ({$resolved['binary']}), not the build JDK — set JAVA_HOME to the JDK the build compiles with\n",
    );
}

/** Warn once per class when vendor `.java` sources make "not found" inconclusive. */
function warnOnceUnverifiedVendorJava(string $name, string $classPath): void
{
    static $warned = [];
    if (isset($warned[$classPath])) {
        return;
    }
    $warned[$classPath] = true;
    \fwrite(
        STDERR,
        "warning: foreign jvm `{$name}` is not verified: the tree carries Java sources\n",
    );
}

function javapClassTable(string $classPath, ?string $javapBinary = null): ?array
{
    if ($javapBinary === null) {
        $javapBinary = findJavapBinary();
    }
    if ($javapBinary === null) {
        throw new VerificationUnavailable(
            'verifying a `foreign jvm` declaration needs `javap` (a JDK); the full moggi distribution ships one',
        );
    }

    static $tables = [];
    $key = $javapBinary . "\0" . $classPath;
    if (\array_key_exists($key, $tables)) {
        return $tables[$key];
    }

    $command = [$javapBinary, '-s', '-p'];
    $classpath = verificationClasspath();
    if ($classpath !== '') {
        $command[] = '-classpath';
        $command[] = $classpath;
    }
    $command[] = $classPath;

    $captured = runProcess($command, null, null, false, 30);
    $output = (string) ($captured['stdout'] ?? '') . (string) ($captured['stderr'] ?? '');
    if (javapCouldNotReadClass($output)) {
        return $tables[$key] = ['found' => false, 'simple' => '', 'methods' => [], 'fields' => []];
    }
    if (($captured['exitCode'] ?? -1) !== 0) {
        \fwrite(
            STDERR,
            "warning: `javap` ({$javapBinary}) exited " . ($captured['exitCode'] ?? '?')
            . " for `{$classPath}` and said nothing readable — the declaration is not verified\n",
        );

        return $tables[$key] = null;
    }

    $table = parseJavapTable($output);
    if ($table['simple'] === '' && $table['methods'] === [] && $table['fields'] === []) {
        \fwrite(
            STDERR,
            "warning: `javap` ({$javapBinary}) printed no class for `{$classPath}`"
            . ' — the declaration is not verified' . "\n",
        );

        return $tables[$key] = null;
    }
    $table['found'] = true;

    return $tables[$key] = $table;
}

/**
 * `javap` reports a class it cannot find or read as an `Error:` (or `Warning:`) line, with a
 * non-zero exit status. Measured, not assumed: `javap` exits 1 for a missing class, so an exit-code
 * guard that fires before the output is examined turns the verdict into a shrug.
 */
function javapCouldNotReadClass(string $output): bool
{
    return \preg_match('/^\s*Error:/m', $output) === 1
        || \preg_match('/^\s*Warning: Binary file .* contains /m', $output) === 1;
}

/**
 * Read `javap -s -p` output: each member declaration is followed by the `descriptor:` line that
 * belongs to it, which is the pairing the whole file exists for.
 *
 * @return array{found: bool, simple: string, methods: array<string, list<array{descriptor: string, public: bool}>>, fields: array<string, array{descriptor: string, public: bool}>}
 */
function parseJavapTable(string $output): array
{
    $methods = [];
    $fields = [];
    $simple = '';
    $declaration = null;

    foreach (\preg_split('/\R/', $output) ?: [] as $line) {
        $trimmed = \trim($line);
        if ($trimmed === '') {
            continue;
        }

        if (\preg_match('/^descriptor:\s*(\S+)\s*$/', $trimmed, $match) === 1) {
            if ($declaration !== null) {
                addJavapMember($methods, $fields, $declaration, $match[1]);
                $declaration = null;
            }

            continue;
        }

        if (\str_ends_with($trimmed, '{')) {
            $header = \substr($trimmed, 0, -1);
            $simple = javapHeaderSimpleName($header);
            $declaration = null;

            continue;
        }

        $declaration = $trimmed;
    }

    return ['found' => false, 'simple' => $simple, 'methods' => $methods, 'fields' => $fields];
}

/** The simple name of the class a `javap` header line declares. */
function javapHeaderSimpleName(string $header): string
{
    if (\preg_match('/(?:class|interface|enum|record)\s+([A-Za-z_$][\w$.]*)/', $header, $match) === 1) {
        $name = $match[1];
        $dot = \strrpos($name, '.');

        return $dot === false ? $name : \substr($name, $dot + 1);
    }

    return '';
}

/**
 * @param array<string, list<array{descriptor: string, public: bool}>> $methods
 * @param array<string, array{descriptor: string, public: bool}> $fields
 */
function addJavapMember(array &$methods, array &$fields, string $declaration, string $descriptor): void
{
    if (\str_contains($declaration, '{}')) {
        return;
    }

    $public = \str_starts_with($declaration, 'public ');

    if (\str_contains($declaration, '(')) {
        if (\preg_match_all('/\b([A-Za-z_$][\w$]*)\s*\(/', $declaration, $matches) === 0) {
            return;
        }
        $name = (string) \end($matches[1]);
        $methods[$name][] = ['descriptor' => $descriptor, 'public' => $public];

        return;
    }

    $head = \preg_replace('/\s*=.*$/s', ';', $declaration) ?? $declaration;
    if (\preg_match('/([A-Za-z_$][\w$]*)\s*;\s*$/', $head, $match) !== 1) {
        return;
    }

    $fields[$match[1]] = ['descriptor' => $descriptor, 'public' => $public];
}

/**
 * @param array{found: bool, simple: string, methods: array<string, list<array{descriptor: string, public: bool}>>, fields: array<string, array{descriptor: string, public: bool}>} $class
 */
function verifyForeignField(array $class, string $classPath, string $member, string $expected): ?string
{
    if ($member === '') {
        return null;
    }

    $field = $class['fields'][$member] ?? null;
    if ($field === null) {
        return isset($class['methods'][$member])
            ? "jvm member `{$classPath}.{$member}` is a method; declare it with `function`, not `const`"
            : "jvm class `{$classPath}` has no field `{$member}`";
    }

    if (! $field['public']) {
        return "jvm field `{$classPath}.{$member}` is not public";
    }

    if ($field['descriptor'] !== $expected) {
        return "jvm field `{$classPath}.{$member}` has descriptor `{$field['descriptor']}`,"
            . " but this foreign import declares `{$expected}`";
    }

    return null;
}

/**
 * @param array{found: bool, simple: string, methods: array<string, list<array{descriptor: string, public: bool}>>, fields: array<string, array{descriptor: string, public: bool}>} $class
 */
function verifyForeignMethod(array $class, string $classPath, string $member, string $expected): ?string
{
    if ($member === '') {
        return null;
    }

    $isConstructor = $member === '<init>';
    $lookup = $isConstructor ? $class['simple'] : $member;
    $candidates = $class['methods'][$lookup] ?? [];

    if ($candidates === []) {
        if ($isConstructor) {
            return "jvm class `{$classPath}` has no constructor matching `{$expected}`";
        }

        return isset($class['fields'][$member])
            ? "jvm member `{$classPath}.{$member}` is a field; declare it with `const`, not `function`"
            : "jvm class `{$classPath}` has no member `{$member}`";
    }

    foreach ($candidates as $candidate) {
        if ($candidate['descriptor'] !== $expected) {
            continue;
        }

        return $candidate['public']
            ? null
            : "jvm member `{$classPath}.{$member}` is not public";
    }

    $subject = $isConstructor
        ? "jvm class `{$classPath}` has no constructor matching `{$expected}`"
        : "jvm member `{$classPath}.{$member}` has no signature matching `{$expected}`";

    return $subject . '; ' . describeHostSignatures($candidates, descriptorParameterCount($expected));
}

/**
 * What the host's overloads look like, in the shape that stays true across JDK
 * versions: the same-arity candidates when there are any (the wrong-type case),
 * otherwise the range of arities (the wrong-arity case). The full list is both
 * unreadable and unstable — `javap -p` prints bridges too, and `StringBuilder.append`
 * has twenty of them.
 *
 * @param list<array{descriptor: string, public: bool}> $candidates
 */
function describeHostSignatures(array $candidates, int $expectedParameters): string
{
    $sameArity = \array_values(\array_filter(
        $candidates,
        static fn (array $candidate): bool => descriptorParameterCount($candidate['descriptor']) === $expectedParameters,
    ));

    if ($sameArity !== []) {
        $shown = \array_slice($sameArity, 0, 4);
        $list = \implode(', ', \array_map(
            static fn (array $candidate): string => "`{$candidate['descriptor']}`",
            $shown,
        ));
        if (\count($sameArity) > \count($shown)) {
            $list .= ', …';
        }

        return 'the host declares ' . $list;
    }

    $counts = [];
    foreach ($candidates as $candidate) {
        $counts[descriptorParameterCount($candidate['descriptor'])] = true;
    }
    $counts = \array_keys($counts);
    \sort($counts);

    if (\count($counts) === 1) {
        $only = $counts[0];

        return "the host takes {$only} " . ($only === 1 ? 'argument' : 'arguments')
            . " and this foreign import declares {$expectedParameters}";
    }

    return 'the host\'s overloads take between ' . $counts[0] . ' and ' . \end($counts) . ' arguments';
}

/** How many arguments a JVM method descriptor declares. */
function descriptorParameterCount(string $descriptor): int
{
    $open = \strpos($descriptor, '(');
    $close = \strrpos($descriptor, ')');
    if ($open === false || $close === false || $close < $open) {
        return 0;
    }

    $count = 0;
    $i = $open + 1;
    $length = \strlen($descriptor);
    while ($i < $close) {
        $char = $descriptor[$i];
        if ($char === '[') {
            ++$i;

            continue;
        }
        if ($char === 'L') {
            $semicolon = \strpos($descriptor, ';', $i);
            $i = $semicolon === false ? $length : $semicolon + 1;
            ++$count;

            continue;
        }
        ++$i;
        ++$count;
    }

    return $count;
}

/** The jars a `jvm/` library directory carries, as one `-classpath` for `javap`. */
function verificationClasspath(): string
{
    static $classpath = null;
    if ($classpath !== null) {
        return $classpath;
    }

    $jars = [];
    foreach (discoverVendorJvmDirs() as $dir) {
        foreach (globPaths($dir . '/*.jar') as $jar) {
            $jars[$jar] = true;
        }
    }

    return $classpath = \implode(\PATH_SEPARATOR, \array_keys($jars));
}

/**
 * Whether a carried tree holds vendor `.java` sources `javap` cannot see.
 *
 * Carried jars are on `javap`'s classpath already, so only sources make "not found"
 * inconclusive: the backend compiles them and they never appear on the classpath.
 */
function carriedVendorJavaPresent(): bool
{
    static $present = null;
    if ($present !== null) {
        return $present;
    }

    $present = false;
    foreach (discoverVendorJvmDirs() as $dir) {
        if (globPaths($dir . '/*.java') !== []) {
            $present = true;

            break;
        }
    }

    return $present;
}
