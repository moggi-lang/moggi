# Tests

One entry point: `test.php`, wrapped by the dev shell as `runtest`. There is no test registry — the
tree decides what runs, and `tests/suite/` decides how. Where a test *goes* is
[tests/README.md](../README.md); this file is the contract of running them.

## Run

```bash
runtest                        # php backend (default), whole suite
runtest --backend all          # php → jvm → dotnet, one aggregate verdict
runtest --group syntax/parser  # one subject, or any group: --group semantics
runtest examples/twice         # one case, by logical name or by path
runtest --list                 # discover everything, run nothing
runtest --native --backend jvm # also build and run the native-executable smoke
```

| Flag | Meaning |
|------|---------|
| `--backend php\|jvm\|dotnet` | Repeatable; `all` runs php, then jvm, then dotnet, with one aggregate verdict. Default `php`. |
| `--group <name>` | Group or a subject path below one, repeatable (`semantics`, `syntax/parser`, `backend/runtime`). |
| `<path\|name>...` | A path (file or directory subtree) or a logical case name. Repeatable, and freely mixed with `--group`. |
| `--list` | Discover the selection, print the case names grouped as the report groups them, run nothing. |
| `--json` | One machine-readable document instead of the report (`--list --json` prints the same names plus group/backends). |
| `--log <path>` | Also write the human report to a file. |
| `--stop-on-failure` | Stop at the first failure (default: keep going). Never pooled automatically; with an explicit `--jobs` it warns that dispatched work finishes. |
| `--progress`, `--no-progress` | Force the live per-group block on (`--progress`) or off (`--no-progress`). Default: on when stderr is a terminal. |
| `--jobs N`, `-j N` | Run N worker processes. Identical counts, ordering and report. |
| `--native` | Also build one example natively and run it. See below. |
| `--help`, `-h` | Usage. |

A selector that matches nothing is an error (exit 2), never an empty selection — a typo must not
select nothing, or worse, everything.

## What a run prints

One report, and no verbosity dial in front of it:

* a **block on stderr** during the run and at its end — one bar per group, repainted in place, so a
  long group is legible instead of silent. It is *not* erased when the run finishes: the block is the
  report, and the recap and the summary print under it. stdout carries the recap, the summary and the
  final verdict only, so `--json`, `--log` and redirection are unaffected;
* a recap re-listing **every** non-passing case with its full diagnostic (nothing truncated), its
  repro line (`runtest --backend php backend/codegen/Opt-Lambda`) and its log path;
* a summary line per backend: `73 passed, 1 failed  (backend: php, 7.6s)`.

`--no-progress` is the same report without the block: every case is named as it finishes
(`OK backend/codegen/Opt-Lambda 153ms`), printed the moment it is known — in a `--jobs N` run that is
when its worker reports it, not when the pool drains — and each backend closes with its group rows
(`optimize   24 passed`) before the recap and the summary. A group row carries counts, not a time:
the only time a group has is the sum of its cases, and under `--jobs N` those cases overlap, so the
sum can exceed the whole run. Per-case times are on the case lines, a group's wall clock is in the
live block, and the run's own time is the summary line's. In a run that covers several
backends the streamed lines name theirs (`… 153ms  jvm`) and the `===== JVM =====` banners are left
out, since a pool's three backends arrive interleaved and a banner over the first block would name
the wrong one; the per-backend summary still says which backend it closes. A case that is about other
backends is counted as `n/a` in its group's row and gets no case line either way — there is nothing
this run could say about it — and is left out of the backend summary.

Under `--jobs N` the first line states the worker count. Every failing case's message is written to
`.moggi/test-artifacts/logs/<group>-<case>.log`, which is the file the recap names. `--log` always
gets the `--no-progress` form, so a log never carries terminal escapes.

## Backends

`php`, `jvm` and `dotnet`. Which backend a case runs on is decided by its **goldens**, never by a
list somewhere:

* a golden that names no backend (`Foo.stdout.expected`) is a claim about *every* backend, so the
  case runs on all of them;
* a case whose goldens all name backends (`Foo.jvm.stderr.expected`, `Foo.dotnet.stderr.expected`)
  runs only on those. One qualified golden among shared ones restricts nothing — the case still runs
  everywhere, and the qualified golden is the one compared on its backend;
* a fixture whose *source* is about one backend has to say so: `foreign php function …` is a type
  error on jvm, so such a golden is `Foo.php.stdout.expected`. The same goes for `err`/`typed-ast`
  goldens of a php-only source — those kinds are pre-codegen but their text still names the compile
  backend (`foreign import backend \`dotnet\` does not match compile backend \`php\``).

For a project, each golden is named after the module it describes, and the one for the backend being
compiled wins: `lib/Platform.jvm.err.expected` is compared on jvm even when the shared
`lib/Platform.err.expected` is there for php.

A case that is not about the selected backend is still selected and reported as **not applicable**
with its reason (`case is about jvm, dotnet`) — never silently dropped, and never called a skip: a
skip would claim something was deferred. A **skip** is the other case: the case applies to this
backend but the machine could not run it (a missing toolchain).

`--backend all` keeps going when one backend fails, reports each backend separately, adds an
`ALL BACKENDS:` line, and exits 1 if any of them failed.

## The native smoke (`--native`)

One example (`examples/twice`) is additionally built into a native executable — GraalVM
`native-image` on jvm, .NET Native AOT on dotnet — and run, comparing stdout with the same golden the
managed run uses. It is a **mode of that example's case, not a case of its own**: the example already
compiles and runs on every backend, and the flag only adds "…and also as an executable".

It is opt-in because the build takes minutes. If the toolchain is not installed, the case is reported
as skipped with its name (`GraalVM native-image not available`); a build or run that fails once the
toolchain is present is a real failure. php has no native toolchain, so the flag is rejected unless a
backend that has one is selected.

## Discovery and naming

A case is identified by its files — `<dir>/Main.mog` is a project, `Foo.mog` with a golden beside it
is a fixture, `*_test.php` is a script. The name a case prints and is selected by is its path without
the input extension (`backend/codegen/Alias`). Names are Kebab-Cased, directories included, and the
examples keep their tree (`examples/twice`); `tests/README.md` lists which kind of test goes where.

## The kinds

A golden is `<base>[.<backend>].<kind>.expected`, beside the input it belongs to (`Foo.mog`,
`Foo.ir.php`, `Foo.script`, or a project module `Module.mog`). The kind says what is compared:

| Kind | Compares |
|------|----------|
| `tokens` | the lexer's dump |
| `ast` | the parse tree |
| `typed-ast` | the tree after typechecking |
| `ir` | the lowered IR |
| `opt-ir` | the optimized IR |
| `emit` | generated source |
| `stdout`, `stderr` | what the program prints, and its uncaught report |
| `err` | that the compiler *rejects* the input: message, source location, caret |

The first five and `err` are produced before codegen, so no backend can change them and a
backend-qualified one would only repeat the shared file. `emit`, `stdout` and `stderr` can differ per
backend and are qualified when they do. A golden whose kind nothing produces is an error at
discovery, not a case that never runs.

A verdict is a golden: there is no `Foo.exec.php` any more (`Exec-Exception-HostCause`'s was the
last, and it became a `stderr` golden). Asserting in the host language from the harness is not a
verdict kind — whatever a case needs to say, it says as output we can compare on all three backends.

A fixture is compiled exactly as a build compiles it: a file without a `module` header is module
`Main`, so the implicit `Prelude` is in scope and a fixture that redeclares `Maybe`, `Bool` or a
class the Prelude exports is rejected as a duplicate. A fixture that needs a Prelude-free
environment says so with `{-# LANGUAGE NoImplicitPrelude #-}` — that is a claim about the fixture,
never a way to keep a golden green.

## Parallelism

`--jobs N` splits the selection into N shards, runs each in a worker process, and merges the results
in selection order, so every name, count and verdict in the report is the serial one (durations are
wall clock, and in a pooled run they are the run's, not the case's). Without the flag, a selection
large enough to give every worker more than one case uses one worker per **physical CPU** (6 on a
6-core/12-thread machine — a case is a whole compile, so a core each), clamped to the cgroup CPU
quota or cpuset of the process, and to what free memory can hold at 512 MB per worker; a smaller
selection keeps to this process, where launching a worker costs more than the cases it would split.
The terminal decides only whether the progress block can be drawn, never how the cases run, so a
redirected or piped run is pooled by the same rule. The first line says which was chosen, and a
requested worker count the selection cannot fill says so instead of claiming a worker nobody
launched. `--stop-on-failure` is never given an automatic pool, and with an explicit `--jobs` it
warns that work already dispatched runs to completion.

## Environment

The suite reads exactly six variables, all optional:

| Variable | Default | Effect |
|----------|---------|--------|
| `MOGGI_CACHE_DIR` | pinned to `<repo>/.moggi` | Where the compile cache lives. The suite pins it *only* when it is unset, so a test run never writes into your working directory's cache and an explicit choice wins. |
| `MOGGI_NO_CACHE` | unset (cache on) | Inherited: disables the cache for the compiler the cases invoke. |
| `MOGGI_FUZZ_DIR` | `<cache>/fuzz-failures` | Where the frontend fuzzer keeps generated inputs and minimised reproducers. |
| `MOGGI_TEST_STANDALONE_TIMEOUT` | `300` | Watchdog seconds for one script case before it is killed and reported as `timeout`. |
| `MOGGI_TEST_CPU_COUNT` | probed | Pin the machine's CPU count, so a run that asserts a worker count gets a machine of exactly that size. |
| `MOGGI_TEST_FORCE_TTY` | unset | `1` makes stderr count as a terminal, so the progress block is on without allocating a pty. |
| `MOGGI_TEST_COLUMNS` | `COLUMNS`, the terminal, 80 | Progress-block width. |
| `MOGGI_TEST_LINES` | `LINES`, the terminal, 24 | Progress-block height; a block taller than this folds its earliest rows instead of scrolling. |
| `NUMBER_OF_PROCESSORS` | probed | Window's CPU count, used only to size an automatic `--jobs`. |
| `JAVA_HOME` | probed | Locating `java` and `native-image` for jvm cases. |

`MOGGI_PROJECT_ROOT` is a PHP constant the harness defines, not an environment variable. Everything
else the compiler itself reads is in [env-vars.md](../env-vars.md).

## Manual inspection

```bash
runtest --list --json | jq '.tests[] | select(.group == "backend")'
moggi compile tests/backend/runtime/Exec-Exception-Report.mog --opt-ir
moggi compile examples/twice --backend jvm -o /tmp/twice.jar
```

`moggi compile <file> --tokens|--ast|--typed-ast|--ir|--opt-ir` prints exactly what a golden of that
kind holds, and `--unpacked -o DIR` keeps the generated tree to read the `emit` output — that is how
a golden is produced and reviewed by hand. The flags and the goldens share one producer (the CLI and
the suite both call `compileFile($file, $stage)`), so the two cannot disagree. Two kinds are not
reproducible from a single file: an `opt-ir` golden of a multi-module project (those are compiled as
a project — see `tests/semantics/modules`).

The language server's own tests are described in [lsp.md](../lsp.md).
