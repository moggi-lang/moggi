# The compiler, stage by stage

You give Moggi a `.mog` file; you get a `.phar`, a `.jar` or a `.dll`. This
document is the whole road between those two points — what each stage consumes
and produces, where it lives in the source, and which flag shows it to you.

```text
    hello.mog  (source text)
        │
        │  lexer                       src/syntax/lexer.php
        ▼
    tokens                             ·  moggi compile hello.mog --tokens
        │
        │  parser (fixity-aware)       src/syntax/parser.php
        ▼
    AST  (the shape you wrote)         ·  --ast
        │
        │  kind checking               src/semantics/kinds.php
        │  type inference + classes     src/semantics/types/**
        │  evidence (dictionary) insertion
        │  IO surface validation        src/semantics/io_boundary.php
        │  strict IO sequencing         src/semantics/strict_io_normalize.php
        ▼
    typed AST  (every decision made)   ·  --typed-ast
        │
        │  IR lowering                  src/IR/**
        ▼
    IR  (backend-neutral, explicit calls/matches/temps)
        │                              ·  --ir
        │  optimizer, per module       src/optimize/**, src/optimize/optimize.php
        ▼
    optimized IR                       ·  --opt-ir
        │
        │  whole-program pass           src/optimize/specialize.php
        │    · specialize across module boundaries
        │    · drop what `main` cannot reach (unless --no-strip)
        ▼
    optimized program
        │
        │  code generation              src/backend/<target>/codegen.php
        ▼
    target code    php: .php per module      jvm: .class files      dotnet: .il + .ilproj
        │
        │  packaging                    src/backend/<target>/package.php
        ▼
    hello.phar  ·  hello.jar  ·  moggi-app.dll      (or --unpacked, or --native)
```

## The stages

| Stage | Input → output | Owner | See it with |
|---|---|---|---|
| Lexing | source → tokens | `src/syntax/lexer.php` | `--tokens` |
| Parsing | tokens → AST | `src/syntax/parser.php`, `src/syntax/ast*.php` | `--ast` |
| Kind checking | AST → kinded type constructors | `src/semantics/kinds.php` | (inside type checking) |
| Type checking | AST → typed AST | `src/semantics/types/**` | `--typed-ast` |
| Effect validation | typed AST → validated effects | `src/semantics/io_boundary.php` | — |
| Strict IO lowering | typed AST → explicit sequencing nodes | `src/semantics/strict_io_normalize.php` | — |
| IR lowering | typed AST → IR | `src/IR/**` | `--ir` |
| Optimization | IR → IR | `src/optimize/**` | `--opt-ir` |
| Whole-program pass | modules → optimized modules | `src/optimize/specialize.php` | — |
| Code generation | IR → target code | `src/backend/<target>/codegen.php` | `:emit` in the REPL |
| Packaging | generated tree → artifact | `src/backend/<target>/package.php` | — |

The driver for a single module is `Moggi\Pipeline\run`
(`src/pipeline/pipeline.php`); its `stopAt` parameter is what the print flags
use to cut the pipeline early. Everything above `--opt-ir` is **backend-neutral**
— the same tokens, AST, typed AST and IR come out regardless of the target.

## The project layer

A program is a *set* of modules, so the work starts one level up, in
`src/modules/modules.php`:

```text
input files
  → module closure discovery        (which files are in the program)
  → fixity collection               (operators from imports, before parsing bodies)
  → dependency ordering
  → import / export resolution      (what each module can see, under what alias)
  → import contexts                 (values, types, constructors, classes, evidence,
                                     and the symbol origins the emitters need)
  → per-module pipeline, up to the optimized IR   (memoized in the compile cache)
  → specializeAcrossModules         (one whole-program pass over all modules)
  → treeShakeModules                (only what `main` reaches, unless --no-strip)
  → per-module code generation      (with global evidence and arity maps)
  → packaging
```

Two facts make this layer worth knowing about:

* **Instances are project-wide.** An `instance` is visible everywhere, not
  because a module imported it but because instances are global in Moggi. The
  whole-program pass relies on that to specialize a call the module that
  declared it cannot see.
* **Only what `main` reaches is kept** in an executable build. A library build
  (`CompilePurpose::Library`) keeps everything, which is why `moggi compile lib`
  produces the full standard library.

## What the optimizer does

Passes live in `src/optimize/`, ordered by `src/optimize/optimize.php`. They are
grouped by how much of the program they need to see:

| Group | Files | Examples |
|---|---|---|
| Local / block | `local.php`, `case_fold.php`, `tco.php` | fold a `match` on a known constructor, eliminate tail recursion, fold a single-use call result |
| Global (per module) | `global.php`, `effects.php`, `dict.php`, `intrinsic.php` | copy propagation, dead-code elimination, dictionary-call specialization, intrinsic wrappers |
| Partial application | `partial.php` | collapse `partial`/`call_value` chains, bind lambda captures |
| Interprocedural | `interproc.php` | inline functions, fuse applied lambdas |
| IO | `io_specialize.php` | fold IO action boxes, straight-line IO |
| Whole program | `specialize.php` | specialize across module boundaries |

`--no-opt` skips all of it (useful when a miscompile is suspected).
[optimizations.md](development/optimizations.md) has the per-pass detail.

## Code generation and packaging

Each target implements the same `Backend` interface (`src/backend/Backend.php`)
and keeps the same file set, so a concern has one home per target
([architecture.md](development/architecture.md) lists the files). What lands on disk differs:

| Target | Generated tree | Artifact |
|---|---|---|
| `php` | one `.php` per module, plus `_runtime.php` at the root of the tree | `.phar` (or the bare tree with `--unpacked`) |
| `jvm` | `.class` files per module, plus the emitted runtime | `.jar` (or a native binary with `--native`, via GraalVM `native-image`) |
| `dotnet` | `.il` per module and an `.ilproj`, assembled by the SDK | `moggi-app.dll` (or a native binary with `--native`, via `dotnet publish -p:PublishAot=true`) |

The PHP runtime is a hand-written file (`src/backend/php/runtime.php`) copied
into each build as `_runtime.php`; the JVM and .NET runtimes are generated per
build (`runtime_abi.php`).

## Failures come back as your code

Generation also records **how a location in the artifact corresponds to a
location in `.mog`** — the PHP backend as a `.moggi.map` next to each module
(loaded only when a trace is actually printed), the JVM and .NET backends as a
frames table baked into the build (JVM line numbers, .NET IL offsets). That is
what lets an uncaught exception print `at Main.boom (Main.mog:5:8)` instead of a
PHP file and line. [diagnostics.md](diagnostics.md) documents the report format
and the per-backend precision.

## The compile cache

Everything expensive is memoized under `.moggi/` — parsed and checked modules,
per-module IR, and whole builds. The cache is keyed by a fingerprint of the
compiler sources and the host runtime, so upgrading either invalidates it
automatically; `moggi cache info` shows the fingerprint, `moggi cache clear`
drops the tree, and `--no-cache` / `MOGGI_NO_CACHE=1` bypass it for one run.
[env-vars.md](env-vars.md) lists the switches.

## Reading the pipeline in practice

```bash
moggi compile hello.mog --tokens        # did the lexer see what I think it did?
moggi compile hello.mog --ast           # is the parse tree the shape I wrote?
moggi compile hello.mog --typed-ast     # what did inference decide?
moggi compile hello.mog --ir            # what does it compile to before optimization?
moggi compile hello.mog --opt-ir        # …and after?
moggi compile hello.mog -o out --unpacked   # the generated program, one file per module
```

The same stages are available interactively (`:ast`, `:typed-ast`, `:ir`,
`:ir-opt`, `:emit` in `moggi repl`), and the compiler's own code is laid out to
match the pipeline: `src/syntax/` (front end), `src/semantics/` (types, kinds,
classes, intrinsics), `src/modules/` (project graph and imports), `src/IR/`,
`src/optimize/`, `src/backend/` (targets), `src/CLI/` (argv only).
