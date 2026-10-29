---
title: "Architecture and invariants"
description: The phases, the representations they exchange, and the rules a change must not break.
---
# Architecture and invariants

The compiler is a pipeline of phases, each owning one representation. A phase
may be incomplete; a leak of one phase's facts into another is the defect this
page exists to prevent.

## The pipeline

```text
source
  → lexer                    tokens
  → parser                   raw AST
  → checking                 typed AST (kinds, schemes, evidence, exhaustiveness)
  → IO/ST validation         checked effect surface
  → normalization            elaborated AST (monad elaboration, explicit sequencing)
  → IR lowering              backend-neutral IR
  → optimization             optimized IR
  → code generation          target source / bytecode
  → packaging                .phar / .jar / .dll
```

Kind inference is not a separate pass over the tree: it runs inside checking
(`src/semantics/kinds.php`) as declarations and signatures are registered.

`Moggi\Pipeline\run` (`src/pipeline/`) is the single-module driver; its
`stopAt` parameter is what `--ast`, `--typed-ast`, `--ir` and `--opt-ir` cut
with. A program is a set of modules, so the project layer
(`src/modules/modules.php`) runs first:

```text
inputs → module closure → fixity collection → dependency order
       → import/export resolution → per-module pipeline (cached)
       → cross-module specialization → reachability/stripping
       → per-module codegen → packaging
```

Instances are global: an instance is visible to every module without an import.
That is why cross-module specialization can rewrite a call the declaring module
cannot see. For an executable build, only what `main` (plus exports and foreign
bindings) can still reach is kept; a library build has no root and keeps
everything.

## Who owns what

| Phase | Owns | Produces | Must not know |
|---|---|---|---|
| Lexing (`src/syntax/lexer.php`) | tokens, lexical diagnostics | token stream | parsing, modules, types |
| Parsing (`src/syntax/parser.php`, `parse_*.php`) | surface grammar, fixity, layout, sugar that is purely syntactic | raw AST | types, evidence, backend names |
| Modules (`src/modules/`) | closure, import/export selection, fixity collection, project preparation | import contexts, checked core | target syntax |
| Kinds (`src/semantics/kinds.php`) | kind inference and unification | kinded constructors | values, codegen |
| Types (`src/semantics/types/`) | declaration registration, inference, classes/evidence, records, exhaustiveness | typed AST | PHP names, IR-only control flow |
| IO boundary / normalization (`io_boundary.php`, `strict_io_normalize.php`) | the pure/effect split and explicit sequencing | elaborated AST | backend emission |
| IR (`src/ir/`) | backend-neutral operations, types, lowering | IR | source-only syntax, unresolved evidence |
| Optimizer (`src/optimize/`) | IR-to-IR rewrites and their ordering | optimized IR | resolver state |
| Backends (`src/backend/`) | names, layouts, emission, runtime, packaging | target code, artifact | type-class semantics, name resolution |

The type checker is the largest phase. When changing it, keep these
sub-concerns separable: type core (inference, schemes, substitution,
unification), declaration registry, class/evidence elaboration, pattern and
exhaustiveness semantics, record semantics, entry-point validation.

## Representation contracts

| Representation | Contains | Must not contain |
|---|---|---|
| Raw AST | source syntax, spans, module/import headers | inferred types, evidence, backend names |
| Typed AST | inferred/declared types, resolved symbols, exhaustiveness facts, evidence | PHP require/use lines, output paths |
| Elaborated AST | evidence insertion, monad elaboration, strict IO sequencing | raw `do` or unresolved evidence |
| IR | functions, blocks, explicit calls/matches/operands | source syntax, unresolved types |
| Optimized IR | IR plus documented pass metadata | frontend state |
| Target source | the backend's own code | compiler-internal metadata |

## Invariants

These are the rules that make the phases separable; each has a fixture or a
type-level enforcement behind it.

* **One owner per fact.** Effects, callable shape, constructor layout, evidence
  plans and generated-name spellings each have exactly one module that answers
  questions about them. A second table or a name-shaped test is a defect.
  `src/backend/ir_meta.php` owns backend-neutral IR facts; `IR\Projection` owns
  which callees are field/tuple projections; `Optimize\Effects\Model` owns what
  an operand may do.
* **No backend facts before codegen.** The typed AST and IR never carry PHP
  function names, import lines or output paths.
* **IO is strict sequencing, not a state token.** `do` desugars through `>>=`;
  IO is an ordinary `Monad` whose methods bottom out at `ioPure#`/`ioBind#`.
  `strict_io_normalize.php` turns the typed form into explicit sequencing
  nodes, and IR lowering translates them mechanically.
* **Evidence has one shape.** Dictionaries are inserted by the type checker and
  consumed by IR; there is no backend-specific evidence protocol.
* **Generated names are reserved.** `__fieldN`, `__tupleN`, evidence names and
  worker names cannot be declared in source; `assertNotGeneratedName()` is the
  single refusal, and the readers share its spelling rules.
* **The REPL loads one runtime.** PHP evaluation `require`s generated modules
  into the process, so exactly one `_runtime.php` may load per process; the
  scratch tree forwards to the compiler's copy.
* **The cache is keyed by the compiler.** A stored product is served only to a
  compiler whose source fingerprint matches; the compiler-owned half of the
  cache is dropped otherwise.
* **Foreign types are exact.** A `foreign` declaration names a specific host
  type or a portable boundary type (`Handle`, `Resource`, primitives, `String`),
  never a generic host object. Distinct foreign types never unify; `Maybe` and
  `Either` cross only as a result, a list only in a trailing position (or
  anywhere on PHP, where an array is the same value), `IO` only outermost in a
  result; and the compiler checks the declaration against the host wherever the
  backend can ask. What it cannot check is behaviour, so a lying signature is
  still a runtime failure.

## Backend layout

Every target implements the same `Backend` contract and uses the same file set;
only the toolchain-specific files differ.

| File | Responsibility |
|---|---|
| `<backend>/<X>Backend.php` | target id, artifact extension/path, mangling, foreign validation |
| `<backend>/naming.php` | module → type/package names, symbol mangling |
| `<backend>/codegen.php` | IR → target code |
| `<backend>/foreign.php` | host path/descriptor rules |
| `<backend>/dependencies.php` | library-owned dependency discovery |
| `<backend>/runtime_abi.php` | generated runtime (JVM/.NET) |
| `<backend>/frames.php` | host frames → `.mog` source locations |
| `<backend>/package.php` | build tree → deployable artifact |
| `src/backend/ir_meta.php` | shared IR facts (constructor layout, backing, throwing call site) |
| `src/backend/inspect.php` | rendering emitted artifacts for `:emit` |

PHP is expression-valued, so its emitters split into `emit_expr.php`,
`emit_match.php`, `intrinsics.php`, `io.php` and `native.php`; JVM and .NET keep
the equivalent code in their `codegen.php` plus `classfile.php` and
`il.php`/`il_size.php`. The PHP runtime is hand-written (`php/runtime.php`,
shipped as `_runtime.php`); JVM and .NET generate theirs per build.

## Changing the compiler

1. Find the owner in the table above; if a fact has two owners, merge them
   first.
2. Add the smallest fixture that fails for the old behaviour, under the group
   that names the phase ([testing.md](testing.md)).
3. Preserve the representation boundary: a semantic decision goes in the
   earliest phase that can make it.
4. If you add a pass, give it a checkpoint, document its effects, and put it in
   the order in [optimizations.md](optimizations.md).
5. Run the affected group, then the full suite. Goldens change only when the
   new output is the intended one.

A semantic decision is only real if something fails when it changes, so each one
has a fixture whose golden shows the lowering. A few that anchor the type
checker and codegen:

| Decision | Fixture |
|---|---|
| `<>` on `String` lowers to `stringAppend#` | `tests/backend/codegen/Tc-Semigroup-String.mog` |
| `1 + 2` lowers to `intAdd#` | `tests/semantics/Tc-Num-Add.mog` |
| a literal stays polymorphic until a use pins it | `tests/semantics/Tc-Constrained-Let.mog` |
| an ambiguous constraint is rejected | `tests/semantics/Bad-Ambiguous-Constraint.mog` |
| list patterns and cons | `tests/backend/codegen/Tc-List.mog` |
| a missing or duplicated instance method is an error | `tests/semantics/Bad-Instance-Method-Unresolved-Constraint.mog` |

The IR optimizer's model and pass order are in
[optimizations.md](optimizations.md); the test contract is in
[testing.md](testing.md). The CLI is a thin shim: `src/cli/main.php` dispatches,
`src/cli/commands/*.php` parse argv and call libraries, and no library reads
argv.
