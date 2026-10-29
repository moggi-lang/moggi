---
title: Documentation
description: One reading path from install to tooling, then the reference and the compiler pages.
---
# Documentation

Read the path in order. Each page ends where the next begins, and each marks the
point where the beginner material stops: a section headed **Advanced** can wait
until you need it. You can stop after any step and still write working programs.

| | Page | After it you can… |
|---|---|---|
| 1 | [quickstart.md](quickstart.md) | install Moggi, run a program, build a `.phar` / `.jar` / `.dll` or a native executable, run the examples |
| 2 | [tour.md](tour.md) | read real code: modules, values, data types, classes, IO, failure, a first host call |
| 3 | [types/data-types.md](types/data-types.md) | model a problem as data and take it apart with patterns |
| 4 | [types/polymorphism.md](types/polymorphism.md) | read a signature, know when inference needs help, fix a type error |
| 5 | [types/classes.md](types/classes.md) and [deriving.md](deriving.md) | write instances, constrain a function, derive `Show`/`Eq`/`Ord` |
| 6 | [effects/io.md](effects/io.md) | write a program that reads input, writes output and keeps its pure parts pure |
| 7 | [repl.md](repl.md) and [stdlib.md](stdlib.md) | try an expression, ask for a type, find a library function |

The [type system](types/index.md) is the same material indexed by concept, with
the pages beyond the path — [kinds](types/kinds.md),
[higher-kinded types](types/higher-kinded-types.md),
[type-level data](types/data-kinds.md), [generic programming](types/generics.md)
— all written for a reader who has finished step 6.

## Reference

| Page | Contents |
|------|----------|
| [language.md](language.md) | complete syntax — every construct, pragma and rule |
| [ffi.md](ffi.md) | calling PHP, the JVM or .NET |
| [host-packages.md](host-packages.md) | using Composer, Maven and NuGet libraries from Moggi |
| [diagnostics.md](diagnostics.md) | compiler messages, uncaught-exception reports, source maps |
| [pipeline.md](pipeline.md) | inspecting a build: tokens, AST, IR, cache, unpacked output |
| [api-docs.md](api-docs.md) | doc comments, `moogle` search by name or type, `mogdoc` HTML and live server |
| [differences](differences-to-haskell.md) | every observable behaviour that deliberately differs from Haskell |
| [lsp.md](lsp.md) | the language server: editor setup, capabilities, limits |
| [env-vars.md](env-vars.md) | the environment variables the compiler, runtimes and tests read |

## Working on the compiler

| Page | Contents |
|------|----------|
| [development/index.md](development/index.md) | setup, build and test commands, repo layout, debugging |
| [development/architecture.md](development/architecture.md) | phases, representation contracts, invariants, backend layout |
| [development/testing.md](development/testing.md) | the suite: commands, discovery, goldens, adding a case |
| [development/optimizations.md](development/optimizations.md) | the IR model, stage order, pass families, adding a pass |
| [development/packaging.md](development/packaging.md) | CI, targets, distributions, runtimes, versions, signing |

## License and copyright

Moggi is released under the [BSD 3-Clause License](https://github.com/moggi-lang/moggi/blob/main/LICENSE).
The full text also ships as `LICENSE` at the root of every distribution; the
licences of the bundled runtimes and of the C libraries `bin/schnorr` links are
listed in `THIRD-PARTY-NOTICES.md` beside it.

Copyright © 2026 Sascha-Oliver Prolic.
