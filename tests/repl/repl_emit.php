#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

// --- `:emit` on the PHP backend must show the source, not a file listing. ---
$phpSource = "<?php declare(strict_types=1);\n\nfunction foo() {\n    return 1;\n}\n";
$phpEmit = [
    'Interactive.php' => $phpSource,
    'Interactive.moggi.map' => '{"artifact":"Interactive.php"}',
];
$php = \Moggi\Backend\backendById('php');
$out = $php->describeEmit($phpEmit, []);
$assert(
    $out === "function foo() {\n    return 1;\n}\n",
    'PHP :emit must show the emitted code, without the `<?php` preamble or blank lines',
);
$assert(!str_contains($out, '.moggi.map'), 'PHP :emit must not dump the source map');
$assert(!str_contains($out, '138 bytes'), 'PHP :emit must not print a byte-size listing');

$focused = $php->describeEmit($phpEmit, ['foo']);
$assert(
    $focused === "function foo() {\n    return 1;\n}\n",
    'focused PHP :emit must show just the named function',
);
$assert(!str_contains($focused, '.moggi.map'), 'focused PHP :emit must drop the source map');

// --- `:emit` on the JVM must drop javap's `Compiled from "…"` banner. ---
$javap = "Compiled from \"Interactive.mog\"\npublic class moggi.Interactive {\n}\n";
$assert(
    \Moggi\Backend\Inspect\stripJavapNoise($javap) === "public class moggi.Interactive {\n}\n",
    'stripJavapNoise must remove the per-class banner',
);

// --- `:emit` on .NET must show IL (the .il artifacts), filtered by focus name. ---
$il = ".class public abstract auto ansi sealed beforefieldinit Moggi.Interactive\n"
    . "       extends [System.Runtime]System.Object\n"
    . "{\n"
    . ".method public hidebysig static object 'foo'() cil managed\n"
    . "{\n"
    . "  ldc.i4.1\n"
    . "  ret\n"
    . "}\n"
    . "\n"
    . ".method public hidebysig static object 'bar'() cil managed\n"
    . "{\n"
    . "  ldc.i4.2\n"
    . "  ret\n"
    . "}\n"
    . "\n"
    . "}\n";
$ilEmit = [
    'Moggi/Interactive.il' => $il,
    'Interactive.moggi.map' => '{"artifact":"Interactive.il"}',
];

$dotnet = \Moggi\Backend\backendById('dotnet');
$all = $dotnet->describeEmit($ilEmit, []);
$assert(str_contains($all, "'foo'") && str_contains($all, "'bar'"), 'unfocused .NET :emit must show every method');
$assert(!str_contains($all, '.moggi.map'), '.NET :emit must not dump the source map');

$one = $dotnet->describeEmit($ilEmit, ['foo']);
$assert(str_contains($one, "'foo'"), 'focused .NET :emit must keep the named method');
$assert(!str_contains($one, "'bar'"), 'focused .NET :emit must drop other methods');
$assert(str_contains($one, '.class public abstract'), 'focused .NET :emit must keep the class header');
$assert(!str_contains($one, '.moggi.map'), 'focused .NET :emit must not dump the source map');
$assert(!str_contains($one, "{\n\n"), 'dropping a leading method must not leave a stray blank line');

// A whole-module dump collapses the mechanical import preamble — for anything
// importing Prelude that is hundreds of lines of `require_once` + `use function`
// that javap / .NET IL dumps do not have — into a single note.
$moduleSource = "<?php declare(strict_types=1);\n\nnamespace Interactive;\n\n"
    . "require_once __DIR__ . '/Data/Int.php';\n"
    . "require_once __DIR__ . '/Prelude.php';\n\n"
    . "use function Data\\Int\\add;\n"
    . "use function System\\IO\\PHP\\putStrLn;\n\n"
    . "function foo() {\n    return 1;\n}\n";
$dumped = $php->describeEmit(['Interactive.php' => $moduleSource], []);
$assert(preg_match('/^\s*require_once/m', $dumped) !== 1, 'whole-module PHP dump must elide requires');
$assert(preg_match('/^\s*use function/m', $dumped) !== 1, 'whole-module PHP dump must elide use aliases');
$assert(
    str_contains($dumped, '// imports elided: 2 require_once, 2 use'),
    'whole-module PHP dump must note what it elided',
);
$assert(str_starts_with($dumped, 'namespace Interactive;'), 'the module must dump its namespace, with no file preamble');
$assert(!str_contains($dumped, '<?php'), 'the module dump must drop the `<?php` preamble');
$assert(
    str_starts_with($dumped, "namespace Interactive;\n\n"),
    'only the blank line after the preamble goes; the rest of the layout stays',
);
$assert(str_contains($dumped, 'function foo()'), 'the code must survive the elision');

// A map-only artifact set (nothing to show) stays silent rather than listing files.
$assert($php->describeEmit(['Interactive.moggi.map' => '{}'], []) === '', 'map-only emit must print nothing');

// --- Declaration focus names come from the AST, not the leading keyword. ---
$frag = \Moggi\Syntax\Parser\parseReplFragment('data Per = Per String Int Bool', '<interactive>');
$assert(
    \Moggi\Repl\fragmentDeclaredName($frag) === 'Per',
    'data-declaration focus must be the declared type name',
);
$fnFrag = \Moggi\Syntax\Parser\parseReplFragment('foo = 1', '<interactive>');
$assert(\Moggi\Repl\fragmentDeclaredName($fnFrag) === 'foo', 'binding focus must be the bound name');
$assert(
    \Moggi\Repl\declSourceName('data Per = Per String Int Bool') === 'Per'
        && \Moggi\Repl\declSourceName('foo = 1') === 'foo',
    'committed declaration sources must report their declared name',
);

echo "REPL :emit formatting tests passed\n";
