---
title: "Working on the compiler"
description: Setup, build and test commands, repo layout, and where each part of the compiler is documented.
---
# Working on the compiler

This section is for changing Moggi itself. To *use* the language, start at
[quickstart.md](../quickstart.md); a release carries every runtime it needs, and
none of what follows is needed for that.

The compiler is PHP and needs 8.5.12 or newer (`MINIMUM_PHP` in
`src/compiler.php`); it compiles Moggi to PHP, the JVM and .NET. Everything here
assumes Linux or macOS with Nix; without Nix, `php moggi.php …` and
`php test.php …` run the same two entry points with whatever is installed, and
the jvm and .NET backends then need a JDK and the .NET 8 SDK on `PATH`.

## Setup

```bash
curl -L https://nixos.org/nix/install | sh -s -- --daemon
# log out and back in, or: newgrp nix-users
nix develop
```

The shell pins **PHP 8.5.12** (8.5.7 and older carry a security issue), the
**.NET 8 SDK**, **GraalVM CE** (Java 25 plus `native-image`), Composer and
Maven, and puts three wrappers on `PATH`:

| Command | Runs |
|---|---|
| `moggi` | `php moggi.php` |
| `runtest` | `php test.php` |
| `runalltest` | `php test-all.php` |

With [direnv](https://direnv.net/), `direnv allow` loads the same shell from
`nix/shell.nix` (package list in `nix/devshell.nix`, shared with `flake.nix`).

## Build and run

```bash
moggi compile examples/twice                  # -> Main.phar (entry file's name)
moggi compile examples/twice -o app.phar
moggi compile examples/twice -o out --unpacked   # generated tree
moggi run examples/twice                      # compile + run
moggi run examples/twice --backend jvm
moggi compile examples/twice --native         # standalone executable
```

`moggi compile` accepts a file or a directory; a directory keeps its layout
under `-o`. Without `-o` the artifact is named after the entry source file —
`hello.mog` → `hello.phar`, a directory whose entry is `Main.mog` → `Main.phar`
(`.jar` on the JVM, `.dll` on .NET). Inspect stages with `--tokens`, `--ast`, `--typed-ast`, `--ir`,
`--opt-ir`; `--no-opt` compiles without the optimizer. The user-facing account
of those flags is [pipeline.md](../pipeline.md).

## Test

```bash
runtest                          # compiler suite, php backend
runtest --backend all            # php, jvm, dotnet; one verdict
runtest --group semantics        # one group or subject
runtest backend/codegen/Int-Arithmetic   # one case
runtest --list --json            # discover without running
runalltest --backend all         # compiler + every library suite
```

`runtest` is `test.php`; `runalltest` is `test-all.php`, which runs the compiler
suite and then the `base`, `json`, `containers`, `time` and `filepath` library
suites. [testing.md](testing.md) is the contract: goldens, backend selection,
adding a case, and the native smoke.

## Debugging a hang

Sample the running process instead of bisecting. [phpspy](https://github.com/adsr/phpspy)
dumps PHP stacks of a live pid:

```bash
git clone https://github.com/adsr/phpspy.git /tmp/phpspy   # outside the tree
cd /tmp/phpspy && make
./phpspy --limit=1000 --pid=$(pgrep -n php) > /tmp/php.traces
```

It needs ptrace permission on the target (`sudo` or a permissive
`kernel.yama.ptrace_scope`); without it the trace is empty, which is not
evidence of a bug. Reproduce with a direct `php moggi.php compile` first, then
sample that pid.

## Layout

| Path | What lives there |
|---|---|
| `moggi.php` | CLI shim; all behaviour lives under `src/` |
| `src/cli/` | argv parsing, dispatch, one file per subcommand |
| `src/syntax/` | lexer, parser, AST, desugaring |
| `src/semantics/` | kinds, types, classes/evidence, IO boundary, intrinsics |
| `src/modules/` | project graph, imports/exports, evidence plan, linking |
| `src/ir/` | IR types, lowering, visitors, projection |
| `src/optimize/` | IR passes and their driver |
| `src/backend/` | PHP / JVM / .NET emitters, runtimes, packaging |
| `src/docs/`, `src/repl/`, `src/lsp/` | mogdoc/moogle, interactive session, language server |
| `libraries/` | the standard library, in Moggi |
| `tests/` | suite, goldens, fixtures |
| `examples/` | programs that are cases by existing |
| `packaging/`, `dist/` | distribution assembly and pinned runtimes |
| `docs/` | this documentation |

## Where to read next

* [architecture.md](architecture.md) — phases, representation contracts, the
  invariants a change must preserve.
* [testing.md](testing.md) — how a change is judged.
* [optimizations.md](optimizations.md) — the IR model, pass order, adding a pass.
* [packaging.md](packaging.md) — distributions, releases, version identity.

A first change usually follows the same path: find the phase that owns the
concept in [architecture.md](architecture.md), add a fixture under `tests/`,
run its group with `runtest --group …`, and inspect the produced stage with the
matching `--ast` / `--ir` / `--opt-ir` flag before touching a golden.
