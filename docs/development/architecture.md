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
* **A callee is a spelling; the table says which entry it names.**
  `IR\Callables\table()` publishes one `CallableSig` and one capture vector per
  entry, and every consumer that needs an entry's arity, hidden slots or capture
  layout asks it through `sig()` — which is also the only place that knows a
  `Module::name` spelling for this module means the bare entry. The optimizer's
  partial legalization (`Optimize\Partial`) therefore threads the table, not a
  name-keyed map, and reads a call's layout with `publishedLayout()`. The run
  republishes that table in its facts whenever the function list changes, and the
  inline pass and the partial-return specialisation answer their capture questions
  through `publishedCaptures()`, so an entry that cannot be resolved is reported
  instead of assumed capture-free; a second arity map or a second capture table is
  a defect, not an optimisation. One fact is deliberately separate: DCE and copy
  propagation publish `Optimize\GlobalPass`'s liveness set, which follows every
  reference kind (`FnRef`, `Partial`, call callee) because those are the locals a
  bound capture prefix reads, where the ABI vector follows value references only.
* **The checked core is a decision overlay, not a structural tree.** Terms in
  `src/modules/checked_term.php` own the facts inference decides at a site — its
  binder identities, its evidence, the arity an application still wants, a
  `case`'s arms — and lowering reads them where a construct has one. The
  structural children a term names are still AST nodes, so the source tree
  remains the structure: lowering dispatches on AST shape for any construct
  without a term, and free-variable/capture analysis walks the tree. Migrating a
  construct means publishing its decisions as terms, not moving its structure;
  claiming a closed core before structure moves would be false.
* **One capture order: the binder's.** `IR\Callables\table()` publishes each
  entry's `captures` in the order the binder produced them (body order), and a
  call prepends exactly that vector (`Optimize\Partial::publishedLayout`). It is
  not re-sorted into an `E`-first order: for a lifted callable `E` and `C` are a
  split of that one vector, so reordering it would bind the wrong locals.
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
| `<backend>/codegen.php` | `emitModule`: module → target code, function/evidence/main/data |
| `<backend>/emit_env.php` | the one env object an emission threads, and its module-wide counters/registries |
| `<backend>/emit_stmt.php` | statements and blocks: `emitBlock`, `emitStmt`, tail loops, termination |
| `<backend>/emit_expr.php` | operands and values: `emitOperand`, boxes, field reads, enum/dict method operands |
| `<backend>/emit_call.php` | calls: `emitCallValue`, `emitStaticCall`, the call-argument shaping they share |
| `<backend>/emit_fn_value.php` | function values: `emitTopLevelFnValue`, `emitPartialValue`, lambda/wrapper construction |
| `<backend>/emit_literal.php` | literal and constant loading (`formatDoubleLiteral`, the backend's integer push) |
| `<backend>/emit_pattern.php` | `emitPatternTest` and the pattern tests/bindings behind it |
| `<backend>/emit_data.php` | `emitDataDecl`: data declarations, constructors and their accessors |
| `<backend>/emit_match.php` | matches: `emitMatchStmt`, `emitMatchReturn`, `emitMatchIo` |
| `<backend>/emit_intrinsic.php` | `emitIntrinsic`: the exception/fix intrinsics, the dispatch into the families below, and (php) the module's intrinsic helper functions |
| `<backend>/emit_primop.php` | numeric primop families: `emitIntrinsicPrimop` (arithmetic, bit operations, width conversion) and `emitIntrinsicCompare` (equality, ordering, boolean), with the operand and comparison helpers they expand to |
| `<backend>/emit_value.php` | `emitIntrinsicValue` (list, maybe, string, bytes, platform intrinsics), boxing, literal values and source locations |
| `<backend>/emit_io.php` | IO statements: runs, binds, throw/catch/finally, thunks; `emitIntrinsicState` (ST refs and arrays) |
| `<backend>/foreign.php` | host path/descriptor rules, foreign call emission (`emitForeign`, `emitForeignIoWrap`) |
| `<backend>/verify.php` | `verifyForeignImportAgainstHost`: the declaration checked against the host |
| `<backend>/dependencies.php` | library-owned dependency discovery |
| `<backend>/runtime_abi.php` | generated runtime (JVM/.NET) |
| `<backend>/frames.php` | host frames → `.mog` source locations |
| `<backend>/package.php` | build tree → deployable artifact |
| `src/backend/stack/source_lines.php` | the stack backends' one rule for which locations reach the line and frame tables |
| `src/backend/stack/scalar_intrinsics.php` | the stack backends' scalar intrinsic vocabulary: every name, and the job it does |
| `src/backend/ir_meta.php` | shared IR facts (constructor layout, backing, throwing call site) |
| `src/backend/inspect.php` | rendering emitted artifacts for `:emit` |

The `emit_*.php` set is the same in all three backends and the functions in it carry
the same names; a backend differs where its host language does. That is enforced, not
assumed: `tests/backend/emit_surface.php` fails when a backend's file set differs from the
shared set plus its documented host files (`$shared` / `$hostOnly` there), or when a target
stops declaring one of the shared role names. Adding a host-only module or a new shared
role is therefore a deliberate edit to that case and to this table, never a silent drift.

An intrinsic is emitted by name: `emitIntrinsic` asks each family reader in turn (`emitIntrinsicPrimop`,
`emitIntrinsicCompare`, `emitIntrinsicState`, `emitIntrinsicValue`) and the first that
knows the name answers for it, so a new intrinsic lands in the family file that owns it
and the dispatcher never grows a case list. In the two stack backends the scalar families are
driven from one table: `src/backend/stack/scalar_intrinsics.php` names every scalar intrinsic
and the job it does, so `emitIntrinsicPrimop` and `emitIntrinsicCompare` switch on the *job*
(about forty arms) instead of on the name (about a hundred and fifty cases), and a name can
no longer exist on one target while missing on the other. A job a backend does not implement
is a `RuntimeException` at emit time, never a fallthrough; where the two hosts answer one job
differently (`word64Equality` is `moggi/rt/Word64.eq` on the JVM and object equality on the
CLR) the job names the question and each backend answers it with the operation it has. The one difference is the answer: the stack
backends push and return `true`, php returns the expression text (or `null`) and takes
the argument expressions its caller already emitted, because a php operand *is* a string.
The same holds in `emit_foreign.php`: `emitForeign` emits one foreign call and
`emitForeignIoWrap` applies its `ioWrap`, in all three backends; php's `emitForeignCall`,
`emitInstanceCall` and `emitForeignIoStatement` read the host's dispatch kinds and
assemble statement text, where the stack backends' `emitForeign` resolves descriptors
and packs JVM/.NET argument slots. Debug info has one policy too. Which locations are noted is decided once, in
`src/backend/stack/source_lines.php`: a statement that declares `$srcLoc`, and an intrinsic operand
(which carries the expression it replaced). Both stack emitters call those two functions at the two
points every emitter has — the statement prefix and the intrinsic operand — so a statement class or
intrinsic added later reaches both targets without touching either. Each then derives its frame
table from the debug info it actually emitted (the classfile `LineNumberTable`, the IL sequence
points), so a frame row exists exactly where the host can report a position, and a line noted twice
keeps the location that opened it. php is not a user of this rule: its module is one expression tree
with a runtime source map, so it records throw sites only.

Each emitter threads
exactly one `EmitEnv` (`emit_env.php`): the module facts `emitModule` sets once, and the
per-function values `emitFunction` sets on a clone. A clone — not an array copy — is how
a deeper emit derives a scope, so counters that must outlive one (`$__scrut_N` here, the
wrapper and thunk registries on JVM/.NET) sit in an object the clone shares. The hosts
differ where the targets do: `classfile.php` (a JVM class writer) against
`il.php`/`il_size.php` (CIL text and sizes), with PHP expression-valued so its
`emitIntrinsic` answers a string where the other two push to the stack. The PHP runtime is
hand-written (`php/runtime.php`, shipped as `_runtime.php`); JVM and .NET generate theirs
per build.

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
