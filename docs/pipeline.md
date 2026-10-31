---
title: "Inspecting a build"
description: The flags and cache that show what the compiler did with your program, and what ends up in an artifact.
---
# Inspecting a build

The compiler turns a `.mog` program into an artifact without asking you to
configure stages. When something surprises you, these are the ways to look
inside — every one is a flag on `moggi compile` or a command in `moggi repl`.

```bash
moggi compile hello.mog --tokens        # the token stream
moggi compile hello.mog --ast           # the parsed tree
moggi compile hello.mog --typed-ast     # after type checking and elaboration
moggi compile hello.mog --ir            # before optimization
moggi compile hello.mog --opt-ir        # after optimization
```

Each flag stops after that stage and prints it to stdout; none of them writes an
artifact. The same stages are available one expression at a time in the REPL
(`:ast`, `:typed-ast`, `:ir`, `:opt-ir`, `:emit`, `:emit-opt`), and for a whole
session module with `:dump` ([repl.md](repl.md)).

## What the stages are for

You do not need the stage names to use the language, but each answers a
different question:

| When you want to know | Look at |
|---|---|
| whether the lexer split the source the way you read it | `--tokens` |
| whether an operator or layout choice parsed as you intended | `--ast` |
| what type inference decided, and where dictionaries were inserted | `--typed-ast` |
| whether the program is already reduced before the target backend sees it | `--ir`, `--opt-ir` |
| what host code a construct becomes | `:emit` in the REPL |

`--no-opt` disables optimization entirely, which is the first thing to try when
a program behaves correctly with a simpler compile and wrongly with the default.
`--no-strip` keeps bindings that `main` cannot reach, which is what you want when
inspecting a library build rather than an executable.

The stages themselves, the passes and the source layout behind them are
compiler-internals material:
[development/architecture.md](development/architecture.md) and
[development/optimizations.md](development/optimizations.md).

## The generated tree

`--unpacked` keeps the whole generated program instead of packing it into one
artifact:

```bash
moggi compile hello.mog -o out --unpacked
```

What lands in `out/` differs by target:

| Target | Generated tree |
|---|---|
| `php` | one `.php` per module, plus `_runtime.php` at the root |
| `jvm` | `.class` files per module, plus the emitted runtime |
| `dotnet` | `.il` per module and an `.ilproj`, assembled by the SDK |

On Windows a classfile whose name is a reserved device name — the runtime's `moggi/rt/Con`, or a
module called `Con` — cannot live in the tree, because `Con.class` names the console there. Those
classes are written to `moggi-classes.jar` beside the tree instead; add it to the classpath when
running an unpacked build (`java -cp "out;out/moggi-classes.jar" moggi.Main`).

This is the fastest way to see what a construct became, and it is also how a
library is built when the compiled tree, not an executable, is the output
([stdlib.md](stdlib.md)).

## Failures point back at `.mog`

Every build records how a location in the artifact corresponds to a location in
the source: the PHP backend as a map beside each module, loaded only when a
trace is printed; the JVM and .NET backends as a frames table baked into the
build. That is what makes an uncaught exception print
`at Main.boom (Main.mog:5:8)` instead of a PHP file and line, with per-backend
precision documented in [diagnostics.md](diagnostics.md).

## The compile cache

Compiled modules, optimized IR and whole builds are cached under `.moggi/` in
the working directory. The cache is keyed by a fingerprint of the compiler's own
sources, so upgrading the compiler invalidates what it derived and keeps what it
merely downloaded.

```bash
moggi cache info            # the fingerprint and each area's size
moggi cache clear           # drop everything
moggi cache clear compile   # drop one area
```

`--no-cache` bypasses the cache for one run, and `MOGGI_NO_CACHE=1` does the same
for a whole shell ([env-vars.md](env-vars.md) lists the areas and variables).

## When a build is slow

The first compile of a program pays for the library modules it imports; later
ones reuse cached entries. If `moggi cache info` shows a different fingerprint
than the compiler you expect, something rebuilt the compiler sources and
invalidated the cache. If a build is slow with a warm cache, the likely causes
are a large generated tree (`--unpacked` on a big program), `--no-cache`, or a
target that has to invoke its host toolchain (`--native`, or the .NET assembler
fallback).
