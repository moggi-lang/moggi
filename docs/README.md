# Documentation

Read the first four pages in order. Each one is short, and each ends where the
next begins — you can stop after any of them and have a working language.

## Start here

| | Page | After it you can… |
|---|---|---|
| 1 | [quickstart.md](quickstart.md) | install Moggi, run a program, build a `.phar` / `.jar` / `.dll`, run the examples |
| 2 | [tour.md](tour.md) | read and write real code: modules, data types, pattern matching, records, type classes, IO, failure, and a first host call |
| 3 | [repl.md](repl.md) | try any expression interactively, ask for a type, and look at the stages the compiler runs |
| 4 | [stdlib.md](stdlib.md) | find the module or function you need — `moogle` by name or by type, `mogdoc` for browsable HTML |

Then write something. The programs in `examples/` are meant to be read and
edited; `moggi run examples/twice` and change a line.

## Going deeper

Reference, in the order the questions usually come up:

| Page | Contents |
|------|----------|
| [language.md](language.md) | **complete syntax** — every construct, pragma and rule, when the tour is not enough |
| [ffi.md](ffi.md) | calling PHP, the JVM or .NET: `foreign` declarations, host paths, signature rules, result conversions, `HostException`, and PHP as the one special case |
| [deriving.md](deriving.md) | every deriving strategy (stock, newtype, anyclass, via) and what each one generates |
| [diagnostics.md](diagnostics.md) | the error catalogue — every compile error with what it means — plus uncaught-exception reports, source maps and per-backend trace precision |
| [pipeline.md](pipeline.md) | what the compiler does with your file, lexer → parser → typed AST → IR → opt IR → whole-program pass → artifact |
| [differences-to-haskell.md](differences-to-haskell.md) | everything observable that differs from Haskell, whose names Moggi's library follows. Only interesting if you know it |
| [lsp.md](lsp.md) | the language server: editor setup, capabilities, analysis, limits |
| [env-vars.md](env-vars.md) | the environment variables the compiler, the runtimes and the test suite read |

## Working on the compiler

| Page | Contents |
|------|----------|
| [architecture.md](development/architecture.md) | phase responsibilities, representation contracts, refactoring boundaries |
| [compiler.md](development/compiler.md) | bootstrap compiler layout, the backend file set, CLI, mogdoc and moogle |
| [design.md](development/design.md) | design decisions: the FFI boundary, primitives, intrinsics, the numeric tower, names |
| [optimizations.md](development/optimizations.md) | the IR passes, pass order, and the flags that inspect them |
| [testing.md](development/testing.md) | the test suite: groups, kinds of comparison, adding a case |
| [packaging.md](development/packaging.md) | CI: workflows, targets, the five distributions, runtimes and the portability floor, version identity, release channels and verification |
| [development.md](development/development.md) | Nix dev shell, project layout, building, debugging |

## Project layout

| Path | Purpose |
|------|---------|
| `moggi.php` | CLI entry point |
| `src/` | The compiler (PHP): `syntax/`, `semantics/`, `modules/`, `ir/`, `optimize/`, `backend/`, `cli/`, `lsp/`, `repl/`, `docs/` |
| `lib/` | The standard library, written in Moggi |
| `tests/` | Compiler test suite (`runtest`) |
| `src/backend/php/runtime.php` | The hand-written PHP runtime, copied into each build as `_runtime.php` |
| `examples/` | Small programs to run and read |
| `.moggi/` | Compile cache (safe to delete; `moggi cache info` describes it) |
