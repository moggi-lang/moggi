# Tests

**Where things live, and where a new test goes.** The contract — flags, discovery, naming, goldens,
backends, failure handling, output — is in [docs/development/testing.md](../docs/development/testing.md). Nothing here
restates it.

```bash
runtest                       # php backend, whole suite
runtest --backend all         # php → jvm → dotnet, one aggregate verdict
runtest --group syntax/parser # one group, or a subject path below one
runtest examples/twice        # one case, by logical name or by path
runtest --list                # discover everything, run nothing
```

## Layout

```
tests/
├── suite/       # the harness — not a group, and never a case
│   ├── kinds.php      the kind table (what each golden compares) and every case verdict
│   ├── support/       discovery (the walk), selection, assert, process, workspace,
│   │                  results, report, diagnostics, args, platform, fuzz, ir fixtures
│   ├── runners/       stage (tokens/ast/typed-ast/ir/opt-ir/emit), runtime (exec, repl), standalone
│   └── driver/        run (the loop), parallel (--jobs), list, select
├── syntax/      # lexer, parser
├── semantics/   # typechecker, module graph
├── ir/          # lowering
├── optimize/    # optimizer
├── backend/     # codegen and runtime, per backend
├── lib/         # the standard library, path-mirrored
├── repl/        # REPL transcripts
├── docs/        # mogdoc / moogle
├── lsp/         # the language server
├── cache/       # the compile cache
└── fuzz/        # the frontend fuzzer
```

`examples/` sits beside `tests/` and is one group of its own. `tests/suite/` is the harness: the walk
skips it, so nothing in it is ever a case.

## How a case is found

There is no registry and no marker file. The walk looks at what a directory and its files are:

| It finds | It is |
|----------|-------|
| `<dir>/Main.mog` | one **project** case — the directory is the program, and the walk does not descend into it |
| `Foo.mog` / `Foo.ir.php` / `Foo.script` with a golden beside it | one **fixture** case |
| `Foo.mog` / `Foo.ir.php` with only a `Foo.exec.php` and no `stdout`/`stderr` golden | one fixture case, php-only: the harness asserts in code |
| `*_test.php` | one **script** case, run in its own process |

Which **stage** owns a fixture is the directory it is in; which **golden kinds** it has is up to you.
`tests/suite/kinds.php` says what each kind compares and which of them can differ per backend.

## Where to add a test

| You want to assert | Put it in | Golden |
|--------------------|-----------|--------|
| the lexer | `tests/syntax/lexer/` | `Foo.tokens.expected` |
| the parser accepts it | `tests/syntax/parser/` | `Foo.ast.expected` |
| the parser rejects it | `tests/syntax/parser/` | `Foo.err.expected` |
| the typechecker's result | `tests/semantics/` | `Foo.typed-ast.expected` |
| the typechecker rejects it | `tests/semantics/` | `Foo.err.expected` |
| a module-graph failure — import, export, re-export | `tests/semantics/<case>/` + `Main.mog` | `<Module>.err.expected` |
| how a construct lowers | `tests/ir/` | `Foo.ir.expected` |
| an optimizer transform | `tests/optimize/` | `Foo.opt-ir.expected` |
| generated source | `tests/backend/codegen/` | `Foo.php.emit.expected` |
| runtime behaviour | `tests/backend/runtime/` | `Foo.stdout.expected`, `Foo.stderr.expected` |
| that a host exception normalises | `tests/backend/runtime/` | `Foo.<backend>.stderr.expected` when backends differ |
| the standard library | `tests/lib/` (mirror the path in `lib/`) | any |
| a REPL session | `tests/repl/` | `Foo.script` + `Foo.stdout.expected` |
| mogdoc / moogle | `tests/docs/` | a script |
| the language server | `tests/lsp/` | a script |
| the compile cache | `tests/cache/` | a script |
| that random input cannot crash the frontend | `tests/fuzz/` | a script |
| what a compiled function *returns* (a native throwable, an exported function no `main` reaches) | any group, beside the fixture | `Foo.exec.php`, and no `stdout`/`stderr` golden |
| that an example program still works | `examples/` | `Main.stdout.expected` |

Most cases are **one fixture plus goldens beside it** — add the file, add the golden, done. No
registration anywhere. A `.ir.php` fixture is for a stage that needs IR to exist before it can do
anything (the optimizer, codegen); a `.mog` fixture is used wherever source suffices.

## Naming

The walk checks these as it discovers, so a sloppy name is an error, not a case that quietly never
runs:

| Thing | Style | Example |
|-------|-------|---------|
| case directory | `lower-kebab` | `semantics/constrained-import` |
| fixture (`.mog`, `.ir.php`) | `Title-Kebab` | `Exec-Exception-Report` |
| REPL transcript (`.script`) | `lower-kebab` | `dump-emit` |
| script | `snake_case_test.php` | `noopt_entry_bootstrap_test.php` |

A path that mirrors a module path is exempt: `tests/lib/Data/JSON/` mirrors `lib/Data/JSON/`, and a
project's own `lib/` is a source tree inside the fixture, not a name.

## Not tests

| Path | Role |
|------|------|
| `tests/suite/` | the harness itself |
| `.moggi/test-artifacts/` | scratch trees and the log of every failing case |
