<?php

declare(strict_types=1);

namespace Moggi\CLI;

use function Moggi\Backend\implementedBackendIds;

function printUsage(): void
{
    $backends = \implode(', ', implementedBackendIds());
    $help = <<<HELP
moggi — compile and run .mog source

usage:
    moggi compile <input-dir|source.mog> [-o <artifact>] [--unpacked] [options]
    moggi run <source.mog|input-dir> [options]
    moggi repl [options]
    moggi cache <info|clear>
    moggi version [--json]
    moggi mogdoc <input-dir> -o <output-dir> [--lib PATH]… [--rebuild] [--no-cache]
    moggi mogdoc serve [--port N] [-o DIR] [--root PATH] [--lib PATH]… [--rebuild] [--no-cache]
    moggi moogle <query> [--root PATH] [--lib PATH]… [--json] [--rebuild] [--no-cache]
    moggi lsp      Language Server Protocol server (stdin/stdout)

commands:
    compile      compile a directory (or one file) to a single deployable
                 artifact (php: .phar, jvm: .jar, dotnet: .dll). Without -o,
                 the artifact is named after the entry source file
                 style). With --unpacked, -o names an output directory that
                 keeps the whole generated tree for inspection
    run          compile then execute (php / jvm / dotnet, depending on
                 --backend); the PHP backend skips packaging entirely
    repl         interactive REPL
    cache        manage the on-disk compile cache (info | clear)
    version      print compiler and stdlib versions, the compiler source
                 fingerprint, and host toolchain versions
    mogdoc       generate HTML documentation
    moogle       search the API by name or type
    lsp          start the Language Server Protocol server

options:
    --backend B     compile target: {$backends} (default: php)
    --unpacked      compile: keep the generated files and directory structure
                    on disk instead of packaging (PHP: no PHAR; JVM/.NET:
                    no jar/dll) — useful for inspecting the output
    --native        compile/run: native executable (GraalVM native-image for
                    jvm; `dotnet publish -p:PublishAot=true` for dotnet)
    -o PATH         compile: the artifact file to produce (e.g. app.phar,
                    app.jar); with --unpacked it names the output directory
                    instead. For the print modes below it names the output file.
    --tokens        print the token stream
    --ast           print the parsed AST
    --typed-ast     print the AST after type checking
    --ir            print IR before optimization
    --opt-ir        print optimized IR
    --no-opt        skip optimizations when generating code
    --no-strip      compile/run: keep bindings unreachable from `main` (stripping
                    unreachable Moggi bindings is on by default for executables)
    --json          version: machine-readable output
    --no-cache      bypass the on-disk compile cache for this run
    --lib PATH      extra module search root (app / third-party library).
                    Repeatable. The compiler stdlib (`lib/` next to moggi) is
                    used automatically; --lib is only needed for additional roots.
    --lib-php PATH  precompiled stdlib PHP tree (e.g. from an earlier
                    `moggi compile lib -o out/lib`) for single-file compiles
    --script PATH   repl: run commands from a file (non-interactive; for tests)
    -h, --help      show this help

examples:
    moggi run examples/twice
    moggi run examples/twice --backend php
    moggi run examples/factorial -- --flag for-the-app
    moggi compile examples/twice                 # -> Main.phar
    moggi compile examples/twice --opt-ir        # optimized IR to stdout
    moggi compile examples/twice -o /tmp/twice.phar
    moggi compile examples/twice -o /tmp/out --unpacked --backend php
    moggi compile app/src -o app.jar --backend jvm --lib vendor/foo
    moggi compile lib -o out/lib --unpacked --backend php
    moggi mogdoc lib -o out/doc
    moggi mogdoc serve --port 8080
    moggi moogle "Maybe a"
    moggi moogle -- "a -> a"

HELP;

    \fwrite(STDERR, preg_replace('/^    /m', '', trim($help, "\n")) . "\n");
}

/** @return list<string> */
function knownSubcommands(): array
{
    return ['compile', 'run', 'repl', 'cache', 'mogdoc', 'moogle', 'version'];
}

function rejectUnknownSubcommand(string $command): never
{
    $message = "error: unknown command `{$command}`";
    $best = null;
    $bestDistance = PHP_INT_MAX;
    foreach (knownSubcommands() as $known) {
        $distance = levenshtein($command, $known);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $best = $known;
        }
    }
    if ($best !== null && $bestDistance > 0 && $bestDistance <= 2) {
        $message .= " (did you mean `{$best}`?)";
    }
    \fwrite(STDERR, $message . "\n\n");
    printUsage();
    exit(1);
}

function cliError(string $message): never
{
    \fwrite(STDERR, $message . "\n\n");
    printUsage();
    exit(1);
}
