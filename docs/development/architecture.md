# Compiler Architecture

This document describes the bootstrap compiler as a set of phases. It is both
the current navigation guide and the target contract for ongoing refactors:
each phase should own one concept, consume one representation, and produce the
next representation without leaking backend details into earlier phases.

## Current Pipeline

Standalone and module codegen share `Moggi\Pipeline\run` in `src/pipeline/`:

```text
source
  -> Lexer\lex
  -> Parser\parse
  -> Types\checkRaw          (CompilePurpose: Executable | Library | Repl)
  -> IoBoundary\validate
  -> StrictIoNormalize\normalize
  -> IR\lower                (Module.entry: EntryPoint{name, kind})
  -> Optimize\optimize
  -> Codegen\emit            (bootstrap only for EntryPointKind::Main)
```

`CompilePurpose` controls entry validation and (with strip) tree-shake roots.
`EntryPointKind` is `Main` (application) or `ReplExpression` (callable eval thunk,
no auto-run bootstrap).

CLI `compile` / `compileFile` and `compilePreparedProject` are thin wrappers over
this driver (`stopAt` cuts stages for `--ast` / `--ir` / `--opt-ir`).

Module compilation starts in `src/modules/modules.php`. It wraps the same semantic
pipeline with project preparation:

```text
input files
  -> module closure discovery
  -> imported fixity collection
  -> dependency sorting
  -> prelude/import/export resolution
  -> type/codegen import context construction
  -> per-module Pipeline::run (from typed AST when already prepared)
```

## Phase Responsibilities

### Lexing

`src/syntax/lexer.php` owns tokenization and lexical diagnostics. It should not know
about parsing, modules, type checking, IR, or backend output.

### Parsing

`src/syntax/parser.php` owns surface grammar, fixity-aware expression parsing, module
headers, imports, declarations, expressions, patterns, and parse diagnostics.

Parser output is raw source AST. Any source-level sugar that remains in this
phase should be purely syntactic. Semantic decisions, symbol resolution,
typeclass elaboration, IO lowering, and backend naming belong later.

### Raw AST

`src/syntax/ast.php` owns raw AST constructors and AST dumping. Raw AST nodes describe
source syntax and module headers. They should not require type information,
symbol origins, evidence dictionaries, backend-resolved names, or IR-only nodes.

### Module Graph And Imports

Module discovery, dependency ordering, prelude injection, and import/export
selection are project-level frontend work. They should produce a semantic import
context: visible value names, type synonyms, data constructors, classes,
instances, and stable symbol origins.

Backend import planning is a separate concern. PHP `require` lines, namespace
`as` names, function `use` lines, output paths, and stdlib PHP remapping should be
computed after semantic resolution and close to code generation.

### Name Resolution

Name resolution should eventually be an explicit pass between parsing and type
checking. Its target output is a resolved AST that uses stable symbol/origin
metadata rather than backend names.

Today, resolution is distributed across parser qualification handling,
`src/modules/modules.php` import contexts, `src/semantics/types.php` environment lookup, and
`src/backend/php/codegen.php` import metadata. Refactors should move this toward a single
resolver-owned contract.

### Type And Kind Checking

`src/semantics/kinds.php` owns kind inference and kind unification.

`src/semantics/types.php` currently owns the full semantic pass: declaration registration,
type inference, unification, class and instance checking, dictionary evidence,
record typing, case exhaustiveness, IO-related typing, and entry-point
validation. The target split is:

- type core: inference, schemes, substitution, unification
- declaration registry: data, type synonyms, classes, and instances
- class/evidence elaboration: constraints and dictionary insertion
- pattern/exhaustiveness semantics
- record semantics
- entry-point validation

The type phase should produce typed AST. Typed AST may carry source types,
inferred types, resolved symbols, exhaustiveness facts, and semantic evidence,
but should not carry PHP names or output-path decisions.

### Monad And Evidence Elaboration

Generic monadic `do` and typeclass dictionaries are semantic elaboration, not
backend work. Evidence insertion should have one owner and one output shape.

`do` always desugars through `Ast\desugarDo()` into `>>=`. IO participates via
ordinary `Functor` / `Applicative` / `Monad` instances in `System.IO` (methods
bottom out at `intrinsic ioPure#` / `intrinsic ioBind#`). There is no ambient
`__io_*` evidence and no explicit state token: an IO action is a value that
performs its effect when it is run, and the sequencing nodes say in what order.

### IO Boundary And Strict IO Lowering

Moggi IO is **strict effect sequencing**, not a state monad and not a RealWorld
token. An IO statement means “perform this effect now, in order.”

`src/semantics/io_boundary.php` validates the pure/effect surface (what may
appear in pure code vs IO-returning functions).
`src/semantics/strict_io_normalize.php` rewrites typed `>>=` / `pure` / `do`
into explicit sequencing nodes (`IoSequence`, `IoBind`, `IoPure`, …).

```text
typed AST (ordinary Monad evidence for IO)
  -> validated effect AST with explicit sequencing
```

IR lowering translates normalized effect nodes mechanically.

### IR Lowering

`src/IR/ir.php` owns the IR representation and AST-to-IR lowering. The target
boundary is:

```text
typed/elaborated AST
  -> backend-neutral IR
```

IR should not need unresolved typeclass evidence, raw `do` nodes, or backend
PHP import decisions. Lowering may still own mechanical transformations such as
lambda lifting, temporary generation, and pattern-to-IR translation until a
dedicated pattern compiler exists.

### Optimization

`src/optimize/optimize.php` owns optimization pass ordering. The individual
`src/optimize/*.php` files own IR-to-IR rewrites.

Every pass should document:

- required input tags and metadata
- produced tags and metadata
- whether it preserves effects
- whether it may remove functions
- whether it is local, interprocedural, or whole-module

Language-semantic decisions should happen before generic IR optimization unless
the pass explicitly documents why it needs semantic metadata.

### Code Generation

Each target implements the `Backend` interface (`src/backend/Backend.php`) and
keeps the same file set, so a concern has the same home on every backend:

| File | Owner of |
| --- | --- |
| `<backend>/<X>Backend.php` | Target id, artifact extension/path, symbol mangling, foreign validation |
| `<backend>/naming.php` | Module → package/type names, symbol mangling |
| `<backend>/codegen.php` | IR → target code |
| `<backend>/foreign.php` | Foreign-import path/descriptor rules |
| `<backend>/dependencies.php` | Library-owned dependency discovery |
| `<backend>/runtime_abi.php` | Generated language runtime (JVM / .NET) |
| `<backend>/frames.php` | Host stack trace → `.mog` frame table |
| `<backend>/package.php` | Build-directory → deployable artifact |
| `src/backend/inspect.php` | Shared `describeEmit` helpers: human-readable dumps of emitted artifacts (PHP declarations, import preamble elided; javap; .NET IL) |

Backend-neutral IR facts live once in `src/backend/ir_meta.php`: constructor
layout/arity, newtype and `Bool` backing, registry conversion, the transitive
lambda-capture closure (`Optimize\Support\buildLambdaMeta` delegates its
fixpoint here), and the throwing-call source location. An emitter must not keep
a private copy — that is how identical helpers drift between targets.

An emitter may decide backend names, constructor layout, match emission
strategy, runtime requires, entry bootstrap, and target syntax. It should not be
the owner of typeclass semantics, name resolution, or IO surface validation. It
consumes already-resolved IR plus backend import metadata.

Target-specific files keep their own names where the toolchain differs
(`jvm/classfile.php`, `dotnet/il.php` + `dotnet/il_size.php`,
`php/emit_expr.php` + `php/emit_match.php`, `php/intrinsics.php` +
`php/io.php`). The PHP backend ships a hand-written `php/runtime.php`, copied into
every build as `_runtime.php` at the root of the generated tree;
the JVM and .NET backends generate their runtime in `runtime_abi.php`.

### Intrinsics And Primitives

Primitive operations have two distinct concerns:

- backend-neutral metadata: type schemes, operator resolution, effects, and
  runtime identity (`src/semantics/intrinsic_registry.php`, synthetic modules
  `Moggi.Internal.Prim` / `Moggi.Internal.IO`)
- PHP backend emission: helper PHP code and emitted call syntax

`IO#` / `ioPure#` / `ioBind#` live in `Moggi.Internal.IO` because IO has special
strict-normalization and lowering rules. General Magichash types and primops
live in `Moggi.Internal.Prim`. A primop's `#`-suffixed MagicHash name is its only
name, and is used verbatim as the IR/backend id.

`src/backend/php/intrinsics.php` currently contains both. Refactors should split the
primitive registry from the PHP intrinsic emitter.

### REPL

`src/repl/` is split by concern. Dependencies only point downward: `commands`
knows the session and the inspection layer, the inspection layer knows the
session, and the session knows neither.

| File | Owner of |
| --- | --- |
| `state.php` | `State`: scratch dir, declarations, loaded closure, memoized contexts |
| `session.php` | Interactive-module assembly, typecheck (`prepareInteractive`), evaluation, `:load` / `:reload` / `:clear` |
| `compile.php` | Prepared module → pipeline artifacts, for `:ir` / `:dump` |
| `inspect.php` | `:ast` / `:ir` / `:emit` fragment dispatch and the stage dumps |
| `commands.php` | The `:` command table, usage text, and diagnostics |
| `eval_runner.php` | Running an interactive entry: in-process PHP include, or a packaged subprocess on JVM / .NET |
| `input.php`, `shadow.php` | Readline/multi-line buffer helpers; GHCi-style rebinding of shadowed declarations |

Two invariants matter when touching this code:

* The PHP backend evaluates by `require`-ing generated modules into the REPL
  process, so exactly **one** copy of `_runtime.php` may be loaded per
  process. The scratch dependency tree forwards to the compiler's copy
  (`EvalRunner\writeDepsRuntimeShim`) rather than shipping its own: two copies are
  a `Cannot redeclare` fatal, because PHP binds top-level functions while
  *compiling* a file, before any guard inside it can run.
* The scratch directory is created by `createSession` and removed by
  `destroySession` when the session ends. It is never an output, and nothing
  outside the process reads it.

## Representation Contracts

The compiler should converge on these phase representations:

| Representation | Owner | Contains | Must not contain |
| --- | --- | --- | --- |
| Raw AST | parser/ast | source syntax, spans, module/import headers | inferred types, evidence, backend names |
| Resolved AST | resolver | raw syntax plus stable symbol origins | PHP require/use lines |
| Typed AST | type checker | inferred types, checked declarations, class facts | PHP names, IR-only control flow |
| Elaborated AST | semantic lowering | evidence, monad elaboration, strict IO sequencing | raw unresolved `do`/evidence nodes |
| IR | IR lowering | functions, blocks, operands, explicit calls/matches | source-only syntax, unresolved types |
| Optimized IR | optimizer | IR plus documented optimization metadata | frontend resolver state |
| Target source | `<backend>/codegen.php` | backend output | compiler-internal metadata |

## Refactoring Rules

When moving code between modules:

1. Preserve behavior with `nix develop -c runtest` after each major slice.
2. Split by compiler concept, not by file size alone.
3. Keep one owner for each semantic concept.
4. Avoid pass-through wrappers that only move complexity elsewhere.
5. Prefer explicit phase contracts over defensive checks deep in later phases.
6. Keep backend metadata out of frontend representations unless documented as a
   temporary bridge.

## CLI

The user-facing command line is intentionally thin:

```text
moggi.php
  -> Moggi\CLI\main          (src/CLI/main.php)
       -> commands/compile|run|repl|cache|mogdoc|moogle
```

| Layer | Location | Responsibility |
|-------|----------|----------------|
| Entry shim | `moggi.php` | `require compiler.php`; `exit(CLI\main($argv))` |
| Dispatch | `src/CLI/main.php` | Subcommand table; typo detection |
| Shared argv | `src/CLI/args.php`, `paths.php`, `usage.php` | Flags, library roots, help |
| Commands | `src/CLI/commands/*.php` | Per-subcommand parse + orchestration |
| Libraries | `pipeline/`, `modules/`, `docs/`, `repl/` | Compile, index, REPL — no argv |

`findMogFiles` and `resolveLibraryDirs` live in `src/CLI/paths.php` and are reused by mogdoc (`src/docs/project.php`). For production doc hosting, run `moggi mogdoc -o` and serve static HTML; `moggi mogdoc serve` uses a dev-only PHP socket server in `src/docs/serve.php`.

