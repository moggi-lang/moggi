---
title: "Architecture and invariants"
description: The phases, the representations they exchange, and the rules a change must not break.
---
# Architecture and invariants

The compiler is a pipeline of phases. Each phase owns one representation; a fact that leaks into another phase is the defect this page exists to prevent.

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

Kind inference is part of checking (`src/semantics/kinds.php`), not a separate pass. `Moggi\Pipeline\run` (`src/pipeline/`) drives one module; its `stopAt` parameter supplies `--ast`, `--typed-ast`, `--ir` and `--opt-ir`.

The project layer (`src/modules/modules.php`) runs before that:

```text
source closure → fixity → dependency order → import/export resolution
→ per-module pipeline (cached) → cross-module specialization
→ reachability/stripping → per-module codegen → packaging
```

Instances are global: every module can see them without an import, which is why cross-module specialization can rewrite a call. An executable keeps only what `main`, exports and foreign bindings can reach; a library build keeps everything.

## Who owns what

| Phase | Owns | Produces | Must not know |
|---|---|---|---|
| Lexing (`src/syntax/lexer.php`) | tokens, lexical diagnostics | token stream | parsing, modules, types |
| Parsing (`src/syntax/parser.php`, `parse_*.php`) | surface grammar, fixity, layout, purely syntactic sugar | raw AST | types, evidence, backend names |
| Modules (`src/modules/`) | closure, import/export selection, fixity collection, project preparation | import contexts, checked core | target syntax |
| Kinds (`src/semantics/kinds.php`) | kind inference and unification | kinded constructors | values, codegen |
| Types (`src/semantics/types/`) | declaration registration, inference, classes/evidence, records, exhaustiveness | typed AST | PHP names, IR-only control flow |
| IO boundary / normalization (`io_boundary.php`, `strict_io_normalize.php`) | pure/effect split and explicit sequencing | elaborated AST | backend emission |
| IR (`src/ir/`) | backend-neutral operations, types, lowering | IR | source-only syntax, unresolved evidence |
| Optimizer (`src/optimize/`) | IR-to-IR rewrites and their order | optimized IR | resolver state |
| Backends (`src/backend/`) | names, layouts, emission, runtime, packaging | target code, artifact | type-class semantics, name resolution |

Type checking is the largest phase. Keep type core, declaration registry, class/evidence elaboration, pattern/exhaustiveness, records and entry-point validation separable.

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

* **One owner per fact.** Effects, callable shape, constructor layout, evidence plans and generated names each have exactly one owning module. A second table or a name-shaped test is a defect.
* **The callable table is the ABI.** `IR\Callables\table()` publishes each entry's arity, hidden slots and captures. Consumers use `sig()` or `publishedLayout()`; a parallel arity or capture map is a defect.
* **Captures keep binder order.** `FunctionDecl` owns the vector, `with()` recomputes it when the body, parameters, shape or kind changes, and the table refuses a capture-taking declaration with no vector. `E` and `C` are a split of that vector, never a re-sorted `E`-first list.
* **The checked core owns one structural tree.** Terms in `src/modules/checked_term.php` own inference decisions; `CheckedStructure` owns each site's arrangement and children by id. Lowering materializes a source-located view, so a copied, reparsed or scrubbed AST cannot change the IR.
* **No backend facts before codegen.** The typed AST and IR never carry PHP names, import lines or output paths.
* **IO is strict sequencing.** `do` desugars through `>>=`; IO is ordinary `Monad` syntax over `ioPure#`/`ioBind#`, normalized into explicit sequencing and lowered mechanically.
* **Evidence has one shape.** The checker inserts dictionaries; IR consumes them; no backend defines its own evidence protocol.
* **Generated names are reserved.** `__fieldN`, `__tupleN`, evidence and worker names cannot be declared in source; `assertNotGeneratedName()` is the single refusal.
* **The REPL loads one runtime.** Exactly one `_runtime.php` may load per process; the scratch tree forwards to the compiler's copy.
* **The compiler owns its cache.** `compilerFingerprint()` hashes compiler sources; `ensureCache()` drops the compiler-owned tree when it changes. A module product is also keyed by its declared compilation inputs.
* **Foreign types are exact.** A `foreign` declaration names a specific host type or a portable boundary type, never a generic host object. Distinct foreign types never unify; the compiler checks the declaration against the host wherever it can, and behaviour remains a runtime contract.

## Backends

Every target implements the same `Backend` contract and uses the same `emit_*` role set, with `EmitEnv $env` first and IR payloads following in the same order. `tests/backend/emit_surface.php` enforces parity over every file a backend directory holds, whatever its extension and nesting: the set must be the shared files plus that host's own, each shared role must be declared *and called*, and each IR-walking signature must start with the same prefix. A host-specific file or parameter is a documented exception, not a silent difference.

The managed backends share statement traversal and ordering in `src/backend/managed/stmt.php`; each `EmitEnv` implements `StmtTarget` for the host instructions. Intrinsics dispatch by family (`emitIntrinsicPrimop`, `emitIntrinsicCompare`, `emitIntrinsicState`, `emitIntrinsicValue`), so adding an intrinsic lands in its family. The stack backends decide source locations once in `src/backend/stack/source_lines.php`; PHP is expression-valued while JVM and .NET are stack-based.

The managed runtimes' shared protocol is declared once in `src/backend/managed/abi_facts.php` and checked against both rendered runtimes by `tests/backend/managed_abi_parity.php`, so a protocol type cannot be renamed on one target alone. A module's artifact path is the target's `artifactRelativePath()`; the project layer asks rather than substituting an extension, and `src/backend/dotnet/inspect/Program.cs` is the IL inspector the .NET verifier compiles.

## Changing the compiler

1. Find the owner in the table above; merge duplicate owners first.
2. Add the smallest fixture that fails for the old behaviour, under the phase's group in [testing.md](testing.md).
3. Keep the representation boundary: put a semantic decision in the earliest phase that can make it.
4. For a new pass, add a checkpoint, document its effects, and place it in the order in [optimizations.md](optimizations.md).
5. Run the affected group, then the full suite; change a golden only when the new output is intended.

`src/cli/main.php` dispatches; `src/cli/commands/*.php` parse argv and call libraries. No library reads argv.
