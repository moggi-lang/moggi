<?php declare(strict_types=1);

/**
 * Discovery: the walk. A case is identified by its files, never by a registry:
 *
 *   `Foo.mog` / `Foo.ir.php` / `Foo.script` + goldens beside it   one case per input
 *   `<dir>/Main.mog`                                              one project case (not descended into)
 *   `*_test.php`                                                  one script case, in its own process
 *
 * The goldens decide the case's backends: unqualified ones claim every backend, qualified ones only
 * their own.
 */

final class TestCase
{
    /**
     * @param list<array{base: string, kind: string, backend: ?string, path: string}> $goldens
     * @param list<string> $files every path this case is made of: its input, its goldens, its
     *                             `.exec.php` — what a path selector is matched against
     * @param list<string> $backends declared by a script case; empty = derived from the goldens
     */
    public function __construct(
        public string $name,
        public string $group,
        public string $input,
        public array $goldens = [],
        public array $files = [],
        public array $backends = [],
        public bool $project = false,
        public bool $scriptCase = false,
    ) {
    }

    /** The input without its extension: the stem the case's own goldens hang off. */
    public function base(): string
    {
        $ext = inputExtension($this->input);

        return $ext === null ? $this->input : \substr($this->input, 0, -\strlen($ext));
    }

    /**
     * The golden of one module's kind: the backend's own file if there is one, the shared file otherwise.
     */
    public function goldenBeside(string $base, string $kind, string $backend): ?string
    {
        $shared = null;
        foreach ($this->goldens as $golden) {
            if ($golden['kind'] !== $kind || $golden['base'] !== $base) {
                continue;
            }
            if ($golden['backend'] === $backend) {
                return $golden['path'];
            }
            if ($golden['backend'] === null) {
                $shared = $golden['path'];
            }
        }

        return $shared;
    }

    /**
     * Every golden of this kind that decides this case on `$backend`, one per input: a qualified golden
     * replaces the shared one it qualifies, as `goldenBeside` does.
     *
     * @return list<string> paths
     */
    public function goldensOfKind(string $kind, string $backend): array
    {
        $forInput = [];
        foreach ($this->goldens as $golden) {
            if ($golden['kind'] !== $kind) {
                continue;
            }
            if ($golden['backend'] === $backend) {
                $forInput[$golden['base']] = $golden['path'];
            } elseif ($golden['backend'] === null) {
                $forInput[$golden['base']] ??= $golden['path'];
            }
        }

        return \array_values($forInput);
    }

    public function runsOn(string $backend): bool
    {
        return \in_array($backend, $this->caseBackends(), true);
    }

    /** The backends a case is about: declared by a script, otherwise named by its goldens. */
    public function caseBackends(): array
    {
        if ($this->backends !== []) {
            return $this->backends;
        }
        if ($this->scriptCase) {
            return knownTestBackends();
        }

        return $this->namedBackends();
    }

    /**
     * One unqualified golden claims *every* backend, so it opens the case up; only an all-qualified case
     * is restricted.
     *
     * @return list<string>
     */
    private function namedBackends(): array
    {
        $named = [];
        foreach ($this->goldens as $golden) {
            if ($golden['backend'] === null) {
                return knownTestBackends();
            }
            $named[$golden['backend']] = true;
        }

        return $named === [] ? knownTestBackends() : \array_keys($named);
    }
}

function testsRoot(): string
{
    return MOGGI_PROJECT_ROOT . '/tests';
}

/** The trees a case can live in: the tests themselves, and the example programs. */
function testTreeRoots(): array
{
    return [testsRoot(), MOGGI_PROJECT_ROOT . '/examples'];
}

function projectRootPath(): string
{
    return MOGGI_PROJECT_ROOT;
}

/** The input extension a fixture is named by, or null when the file is not an input. */
function inputExtension(string $path): ?string
{
    foreach (['.ir.php', '.mog', '.script'] as $ext) {
        if (\str_ends_with($path, $ext)) {
            return $ext;
        }
    }

    return null;
}

/**
 * The name a case is printed and selected by: its path under the tree it lives in, without the input
 * extension and without the `_test` of a script. Example programs keep their tree in the name
 * (`examples/twice`).
 */
function logicalTestName(string $path, string $root): string
{
    $name = relativeCaseName($path, $root);
    $label = treeLabel($root);

    return $label === '' ? $name : $label . '/' . $name;
}

/** The group a case belongs to: the tree's own label, or the first path segment under `tests/`. */
function groupOf(string $path, string $root): string
{
    $label = treeLabel($root);
    if ($label !== '') {
        return $label;
    }

    $name = relativeCaseName($path, $root);
    $slash = \strpos($name, '/');

    return $slash === false ? $name : \substr($name, 0, $slash);
}

/** `examples` for the example programs, empty for the test tree itself. */
function treeLabel(string $root): string
{
    return $root === MOGGI_PROJECT_ROOT . '/examples' ? 'examples' : '';
}

function relativeCaseName(string $path, string $root): string
{
    $relative = \str_starts_with($path, $root . '/') ? \substr($path, \strlen($root) + 1) : $path;

    return \preg_replace('/_test$/', '', \preg_replace('/\.(ir\.php|mog|script|php)$/', '', $relative) ?? $relative) ?? $relative;
}

/** Every golden beside `$base`, in name order. */
function goldensBeside(string $base): array
{
    $goldens = [];
    $kinds = testTreeKinds();
    foreach (\glob($base . '.*.expected') ?: [] as $path) {
        $tail = \substr($path, \strlen($base) + 1, -\strlen('.expected'));
        $parts = \explode('.', $tail);
        $backend = null;
        $kind = $tail;
        if (\count($parts) === 2 && \in_array($parts[0], knownTestBackends(), true)) {
            $backend = $parts[0];
            $kind = $parts[1];
        }
        if (!isset($kinds[$kind])) {
            throw new \RuntimeException("unknown golden kind `{$kind}`: {$path}");
        }
        $goldens[] = ['base' => $base, 'kind' => $kind, 'backend' => $backend, 'path' => $path];
    }

    return $goldens;
}

/** @return list<TestCase> */
function discoverTestCases(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cases = [];
    foreach (testTreeRoots() as $root) {
        collectCases($root, $root, $cases);
    }
    assertTreeFilesAreClaimed($cases);
    \usort($cases, static fn (TestCase $a, TestCase $b): int => [$a->group, $a->name] <=> [$b->group, $b->name]);

    return $cache = $cases;
}

/**
 * The inputs that are a script case's support rather than cases of their own: a file a `*_test.php`
 * opens by path, or a module tree it compiles whole.
 *
 * A case and a support fixture look the same on disk — a `.mog` with no golden — so the difference is
 * stated here rather than guessed from a name. A directory that holds cases of its own is never
 * listed whole: `optimize/` names the one file a script reads from it.
 *
 * @return list<string> paths relative to `tests/`, a file or a directory
 */
function supportInputPaths(): array
{
    return [
        'lsp',                              // the LSP scripts open these by path
        'docs/fixture',                     // a module tree mogdoc indexes whole
        'docs/generator/fixture-app',
        'docs/generator/fixture-extra',
        'optimize/noopt-entry-bootstrap.mog', // read by noopt_entry_bootstrap_test.php
    ];
}

/** Whether `$path` is one of the inputs `supportInputPaths()` names — the file itself, or under it. */
function isSupportInput(string $path): bool
{
    $prefix = testsRoot() . '/';
    if (!\str_starts_with($path, $prefix)) {
        return false;
    }

    $relative = \substr($path, \strlen($prefix));
    foreach (supportInputPaths() as $support) {
        if ($relative === $support || \str_starts_with($relative, $support . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * The tree's two dead ends, refused as the cases are discovered.
 *
 * A case exists because a golden claims it, so a fixture beside no golden is not a test that fails —
 * it is a test that never ran, which is how a new case passes by not existing. The walk is the only
 * place that knows both halves of that, and the same walk will not let a golden outlive its input.
 *
 * A fixture a script case opens by path is the one input that is claimed by no golden, and it says
 * so in `supportInputPaths()`.
 *
 * @param list<TestCase> $cases
 */
function assertTreeFilesAreClaimed(array $cases): void
{
    $inputs = [];
    $goldens = [];
    foreach ($cases as $case) {
        $inputs[$case->input] = true;
        foreach ($case->goldens as $golden) {
            $goldens[$golden['path']] = true;
        }
    }

    walkCaseTree(static function (string $path) use ($inputs, $goldens): void {
        if (\str_ends_with($path, '.expected')) {
            if (!isset($goldens[$path])) {
                throw new \RuntimeException('a golden nothing reads: ' . testRelativePath($path));
            }

            return;
        }

        if (inputExtension($path) === null || isset($inputs[$path]) || isSupportInput($path)) {
            return;
        }

        throw new \RuntimeException(
            'a fixture no golden runs: ' . testRelativePath($path)
            . ' — add the golden beside it, or name it in supportInputPaths()',
        );
    });
}

/** Every file of the tree that could be a case; a project directory is one case, so it is not entered. */
function walkCaseTree(callable $visit): void
{
    foreach (testTreeRoots() as $root) {
        walkCaseDir($root, $visit);
    }
}

function walkCaseDir(string $dir, callable $visit): void
{
    if (\is_file($dir . '/Main.mog') || $dir === MOGGI_PROJECT_ROOT . '/tests/suite') {
        return;
    }

    foreach (\scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (\is_dir($path)) {
            walkCaseDir($path, $visit);
            continue;
        }
        $visit($path);
    }
}

/**
 * The naming rules of the tree, checked as cases are discovered: a sloppy name is how a fixture stops
 * being a case without anybody noticing, so the walk refuses one instead of ignoring the file.
 *
 *   directories that are a case      lower-kebab        semantics/constrained-import
 *   a `.mog`/`.ir.php` that is a case  Title-Kebab       Exec-Exception-Report
 *   a `.script` (a REPL transcript)    lower-kebab        dump-emit
 *   a script case                      snake_case_test    noopt_entry_bootstrap_test.php
 *
 * A path that mirrors a module path is exempt (`tests/lib/Data/ByteString/`, a project's `lib/`), as is
 * `examples/`: those are paths inside a source tree, not names.
 */
function caseNamingProblem(TestCase $case, string $root): ?string
{
    if (\str_starts_with($case->input, MOGGI_PROJECT_ROOT . '/examples/')) {
        return null;
    }

    if ($case->scriptCase) {
        return \preg_match('/^[a-z0-9]+(_[a-z0-9]+)*_test\.php$/', \basename($case->input)) === 1
            ? null
            : "script case must be snake_case_test.php: " . testRelativePath($case->input);
    }

    if ($case->project) {
        $dir = \basename(\dirname($case->input));

        return \preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $dir) === 1
            ? null
            : 'a case directory must be lower-kebab: ' . testRelativePath(\dirname($case->input));
    }

    $base = \basename($case->base());
    if (\str_ends_with($case->input, '.script')) {
        return \preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $base) === 1
            ? null
            : 'a repl transcript must be lower-kebab: ' . testRelativePath($case->input);
    }

    return \preg_match('/^[A-Z][A-Za-z0-9]*(-[A-Za-z0-9]+)*$/', $base) === 1
        ? null
        : 'a case fixture must be Title-Kebab: ' . testRelativePath($case->input);
}

/** Append a case, refusing one whose name breaks the tree's naming rules. */
function pushCase(array &$cases, ?TestCase $case, string $root): void
{
    if ($case === null) {
        return;
    }
    $problem = caseNamingProblem($case, $root);
    if ($problem !== null) {
        throw new \RuntimeException($problem);
    }
    $cases[] = $case;
}

/** @param list<TestCase> $cases */
function collectCases(string $dir, string $root, array &$cases): void
{
    if (\is_file($dir . '/Main.mog')) {
        pushCase($cases, projectCase($dir, $root), $root);

        return;
    }
    if ($dir === MOGGI_PROJECT_ROOT . '/tests/suite') {
        return;
    }

    $entries = \scandir($dir) ?: [];
    \sort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (\is_dir($path)) {
            collectCases($path, $root, $cases);
            continue;
        }
        if (\str_ends_with($entry, '_test.php')) {
            pushCase($cases, scriptCase($path, $root), $root);
            continue;
        }
        pushCase($cases, fixtureCase($path, $root), $root);
    }
}

function fixtureCase(string $path, string $root): ?TestCase
{
    $ext = inputExtension($path);
    if ($ext === null) {
        return null;
    }
    $base = \substr($path, 0, -\strlen($ext));
    $goldens = goldensBeside($base);
    if ($goldens === [] && !\is_file($base . '.exec.php')) {
        return null;
    }

    $files = [$path => true, $base . '.exec.php' => true, $base . '.stdin' => true];
    foreach ($goldens as $golden) {
        $files[$golden['path']] = true;
    }

    $execOnly = $goldens === [] && \is_file($base . '.exec.php');

    return new TestCase(
        name: logicalTestName($path, $root),
        group: groupOf($path, $root),
        input: $path,
        goldens: $goldens,
        files: \array_keys($files),
        backends: $execOnly ? ['php'] : [],
    );
}

function projectCase(string $dir, string $root): TestCase
{
    $goldens = [];
    $input = $dir . '/Main.mog';
    foreach (findProjectModules($dir) as $module) {
        foreach (goldensBeside(\substr($module, 0, -4)) as $golden) {
            $goldens[] = $golden;
        }
    }

    return new TestCase(
        name: logicalTestName($dir, $root),
        group: groupOf($dir, $root),
        input: $input,
        goldens: $goldens,
        files: [$dir],
        project: true,
    );
}

function scriptCase(string $path, string $root): TestCase
{
    return new TestCase(
        name: logicalTestName($path, $root),
        group: groupOf($path, $root),
        input: $path,
        files: [$path],
        scriptCase: true,
    );
}

/** @return list<string> every `.mog` of a project, entry included */
function findProjectModules(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'mog') {
            $files[] = $file->getPathname();
        }
    }
    \sort($files);

    return $files;
}
