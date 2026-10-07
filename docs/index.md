---
title: Documentation
description: Read the first four pages in order, then use the reference.
---

# Documentation

Read the first four pages in order. Each one is short, and each ends where the
next begins — you can stop after any of them and have a working language.

| | Page | After it you can… |
|---|---|---|
| 1 | [quickstart.md](quickstart.md) | install Moggi, run a program, build a `.phar` / `.jar` / `.dll`, run the examples |
| 2 | [tour.md](tour.md) | read and write real code: modules, data types, pattern matching, records, type classes, IO, failure, and a first host call |
| 3 | [repl.md](repl.md) | try any expression interactively, ask for a type, and look at the stages the compiler runs |
| 4 | [stdlib.md](stdlib.md) | find the module or function you need — `moogle` by name or by type, `mogdoc` for browsable HTML |

Then write something. The programs in `examples/` are meant to be read and
edited; `moggi run examples/twice` and change a line.

## Reference

| Page | Contents |
|------|----------|
| [language.md](language.md) | complete syntax — every construct, pragma and rule |
| [ffi.md](ffi.md) | calling PHP, the JVM or .NET |
| [deriving.md](deriving.md) | every deriving strategy |
| [diagnostics.md](diagnostics.md) | the error catalogue, uncaught-exception reports, source maps |
| [pipeline.md](pipeline.md) | what the compiler does with your file, stage by stage |
| [differences-to-haskell.md](differences-to-haskell.md) | everything observable that differs from Haskell |
| [lsp.md](lsp.md) | the language server: editor setup, capabilities, limits |
| [env-vars.md](env-vars.md) | the environment variables the compiler, runtimes and tests read |

## Working on the compiler

| Page | Contents |
|------|----------|
| [architecture.md](development/architecture.md) | phase responsibilities, representation contracts |
| [compiler.md](development/compiler.md) | bootstrap compiler layout, the backend file set, CLI, mogdoc and moogle |
| [design.md](development/design.md) | design decisions: the FFI boundary, primitives, intrinsics, the numeric tower |
| [optimizations.md](development/optimizations.md) | the IR passes, pass order, and the flags that inspect them |
| [testing.md](development/testing.md) | the test suite: groups, kinds of comparison, adding a case |
| [packaging.md](development/packaging.md) | CI: workflows, targets, distributions, release channels |
| [development.md](development/development.md) | Nix dev shell, project layout, building, debugging |

The longer index of the same pages is [README.md](README.md).
