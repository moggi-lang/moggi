---
title: "Testing"
description: How to run the suite, how cases and goldens are discovered, and how to add a case.
---
# Testing

Two entry points, both wrapped by the dev shell:

| Command | Runs |
|---|---|
| `runtest` (`php test.php`) | the compiler suite |
| `runalltest` (`php test-all.php`) | the compiler suite (`--exclude-group base`), then the `base`, `json`, `containers`, `time` and `filepath` library suites, one stage after the other; every stage runs even after a failure, and the run exits 1 if any stage failed |

`test.php` also has a `distribution` group that exercises the packaged variants;
`test-all.php` does not run it.

## Running

```bash
runtest                          # php backend, whole compiler suite
runtest --backend all            # php, jvm, dotnet, one aggregate verdict
runtest --group semantics        # one group or subject path
runtest --group backend/runtime
runtest backend/codegen/Int-Arithmetic   # one case by logical name or path
runtest --exclude-group base
runtest --list --json            # discover the selection, run nothing
runtest --jobs 4                 # N worker processes, same report
runtest --native --backend jvm   # also build and run the native smoke
runalltest --backend all         # compiler + every library suite
php test.php distribution        # packaged-variant tests
```

A selector that matches nothing exits 2: a typo must not silently select
nothing. `--stop-on-failure` stops at the first failure and runs the cases
serially, since workers would keep going past it.

Failures are reprinted in full in the recap, with a repro command; each also
writes a log beside the cache — `<cache>/test/logs/`, which is
`.moggi/test/logs/` unless `MOGGI_CACHE_DIR` says otherwise — one file per
failing case.

## How cases are discovered

The tree decides, not a registry:

* `<dir>/Main.mog` is a project case (the walk does not descend into it);
* `Foo.mog`, `Foo.ir.php` or `Foo.script` with a golden beside it is a case;
  a `.script` is a REPL transcript and an `.ir.php` an IR fixture;
* a `.php` file that is not `.ir.php` is a script case, run in its own process;
* a file under `examples/` is a case by existing — its verdict is that it still
  compiles for the backends its declarations name, so no stdout golden pins a
  demo's text.

A case's name is its path without the input extension (`backend/codegen/Alias`),
Kebab-Cased. Discovery refuses a fixture no golden runs and a golden nothing
reads, because either is a case that never ran. A file a script case opens by
path is the exception, and it must be named in `supportInputPaths()`.

> Added cases belong beside their kind: `tests/syntax`, `tests/semantics`,
> `tests/ir`, `tests/optimize`, `tests/backend/{codegen,runtime}`, `tests/cli`,
> `tests/repl`, `tests/lsp`, `tests/docs`, `tests/registry`, `tests/fuzz`,
> `tests/distribution`, `libraries/*/tests`. `tests/README.md` is the directory
> map.

## Goldens

A golden is `<base>[.<backend>].<kind>.expected` beside its input. The kind
decides what is compared:

| Kind | Compares |
|---|---|
| `tokens` | lexer dump |
| `ast` | parse tree |
| `typed-ast` | tree after type checking |
| `ir` | lowered IR |
| `opt-ir` | optimized IR |
| `emit` | generated target code |
| `stdout`, `stderr` | program output and uncaught reports |
| `err` | the input is rejected: message, location, caret |

`tokens` through `err` are produced before codegen, so no backend changes what
they compute — but a dump can name a backend-bound library (`System.IO.PHP`),
and then its golden is qualified like the others (`Foo.jvm.stdout.expected`).
A script case declares its own backends on a `// backends: jvm` line.

Backend selection follows the goldens: a shared golden is a claim about every
backend; a case whose goldens are all backend-qualified runs only on those. A
`foreign php` fixture is PHP-only by construction, so its goldens must say
`.php`. A case that does not apply to the selected backend is reported **not
applicable** (with the reason), not skipped; a **skip** means the case applies
but the machine lacks the toolchain.

## Adding a case

1. Put the smallest input that shows the behavior under the phase's directory.
2. Produce each golden with the flag that prints it:
   `moggi compile Foo.mog --ast | --typed-ast | --ir | --opt-ir`, or
   `--unpacked -o DIR` to read `emit`. The CLI and the suite call the same
   producer, so they cannot disagree.
3. Run the case, inspect the diff, and write the golden only if the new output
   is the intended one.
4. Run the group, then `runtest`; a golden change elsewhere means the change
   reached a phase you did not intend.

There is no assertion language: the verdict *is* the golden. A fixture is
compiled as a normal build (an implicit `Prelude` is in scope unless the file
says `{-# LANGUAGE NoImplicitPrelude #-}`), so a fixture that redeclares a
Prelude name is rejected — and that is a claim about the fixture, not a way to
keep a golden green.

## Environment and flags

| Variable | Effect |
|---|---|
| `MOGGI_CACHE_DIR` | cache root; pinned to `<repo>/.moggi` only when unset |
| `MOGGI_NO_CACHE` | inherited by the compiler the cases invoke |
| `JAVA_HOME` | locating `java` / `native-image` for jvm cases |
| `MOGGI_MICRO_SFX` | micro PHP runtime for the `--native` php smoke; without it the verdict is a skip |

`--native` builds one example (`examples/twice`) as a native executable — the
micro PHP runtime, GraalVM `native-image`, or .NET Native AOT — and compares its
output with the same program run managed. It is a mode of that example's case,
not a separate case, and it is off by default because the builds take minutes.

The suite's own tests are in `tests/suite/`; the language server's tests are
`tests/lsp/`; the distribution smoke is `test.php distribution`.
