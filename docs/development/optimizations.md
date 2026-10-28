---
title: "IR optimizations"
---
# IR optimizations

The optimizer runs on IR after type checking and before codegen, on every backend; `--no-opt` skips it.

Its subject is a tree in which **a computation is an operand, not a statement kind**. `x + 1` lowers to
`intrinsic intAdd#(x, 1)`, an application to `call f(x)`, a method projection to `dictMethod …`. A statement
names a computation only when a later read needs the result: `ret intrinsic intAdd#(x, 1)` and
`t0 = intrinsic intAdd#(x, 1)` are the same operation with and without a destination, exactly as `ret local`
and `t0 = local` are. A pass that folds a computation rewrites the operand; a pass that schedules one emits
the statement.

## Inspecting the pipeline

| Flag | Output |
|------|--------|
| `--ir` | IR before optimization |
| `--opt-ir` | IR after optimization |
| *(default)* | Optimized codegen |
| `--no-opt` | Unoptimized codegen |

```bash
moggi compile tests/backend/codegen/Int-Arithmetic.mog --ir
moggi compile tests/backend/codegen/Int-Arithmetic.mog --opt-ir
moggi compile tests/backend/codegen/Int-Arithmetic.mog --no-opt
```

The stored dumps under `tests/**/*.opt-ir.expected` are this output; each is compared as text.

---

## 1. Copy propagation

A statement whose value is already a value is deleted and its readers rewritten to that value. `isPropagatableExpr`
admits literals, locals, temps, function references, partials, and an `intrinsic`/`list` whose own operands are all
propagatable; a `call` and a foreign call are computations and are never propagated — `exprFromDefiningStmt()`
states that rule. A value that would be duplicated is kept: `operandIsNonDuplicable()`, the read count
(`tempUseCountsInItems()`, propagate only at one read) and the lambda capture index all veto a rewrite.

It runs in the forward block walk (`optimizeBlock`) and again as `propagateCopiesBlock` after CSE.

**Example:** `tests/optimize/Opt-Const-Fold.ir.php` (`area`), the fixture's input and its `--opt-ir`:

Before:
```
let product = intrinsic intMul#(w, h)
let pad = 2
ret intrinsic intAdd#(product, pad)
```
After (`--opt-ir`):
```
ret intrinsic intAdd#(intrinsic intMul#(w, h), 2)
```

---

## 2. Intrinsic folding

`foldIntrinsicNode()` folds a primop by the registry's own strategy (`foldStrategy()` in
`src/semantics/intrinsic_registry.php`), never by a host operator:

| Strategy | Fold |
|----------|------|
| `int-add`, `int-sub`, `int-mul` | two literals → one literal (an overflow is left alone: PHP would promote to float while `Int` wraps at 64 bits) |
| `int-add` | `x + x` → `intMul#(x, 2)`; `intMul#(x, a) + intMul#(x, b)` → `intMul#(x, a + b)` |
| `int-mul` | `x * 1` and `1 * x` → `x`; `intMul#(x, a) * b` → `intMul#(x, a * b)` |
| `string-append`, `bytes-append` | two literals → one literal |
| `list-append`, `list-cons`, `list-head`, `list-tail` | known list spines → the spine, its head, its tail, or one `ListLit`/`listCons#` chain |

A folded node is folded again (so `(2 + 3) + x` reaches `5 + x`), except for a list-spine result.

`foldIntrinsicsItems()` walks a statement list, resolving temps that hold known spines, and runs in the
after-inline and final stages — not in the early stage.

**Example:** `tests/optimize/Opt-Fold.ir.php` (`six`), `--opt-ir`
```
ret intrinsic intAdd#(5, x)
```
**Example:** `tests/optimize/Opt-Const-Fold.ir.php` (`hypot2`, `score`)
```
function hypot2(x, y)
    ret intrinsic intAdd#(intrinsic intMul#(x, x), intrinsic intMul#(y, y))
function score()
    ret 81
```

---

## 3. Tail return folding

A block whose last statement defines a temp and whose last item is `ret` of that temp becomes one `ret` of the
value (`foldTailReturn()`). It runs at the end of the forward walk and again in the after-inline and final stages.

**Example:** `tests/optimize/Opt-Dead.ir.php` (`dead`), the fixture's input and its `--opt-ir`:

Before:
```
let y = 42
t0 = intrinsic intAdd#(x, 1)
ret t0
```
After (`--opt-ir`):
```
ret intrinsic intAdd#(x, 1)
```

---

## 4. Match arm return folding

`case` lowers to a `match` that writes each arm's result into one temp and returns it. Inside an arm that temp is
the match's destination, so copy propagation would erase arm bodies: assigns to it are protected, a copyable value
becomes the arm's `ret`, and a non-copyable one goes through `foldArmMatchReturn()`. When every arm ends in `ret`
and the block ends with `ret` of the match temp, `foldMatchReturn()` turns the match into a `match_return` and drops
the outer `ret`.

**Example:** `tests/backend/codegen/Core-Recursion.opt-ir.expected` (`sign`)
```
match_return x
  arm 0
      ret 0
  arm _
      ret 1
```

---

## 5. Constructor field cleanup

Pattern matching on ADT constructors lowers to `__fieldN` extractions plus pattern bindings, while codegen also
binds fields from the scrutinee, so the IR extraction is redundant. `case_fold.php` removes a duplicate
field-extract `let` and rewrites `ret call __fieldN(scrutinee)` to `ret local(var)` when the pattern already binds
`var`. It runs inside the after-inline stage, around the forward walk, not as a separate pipeline step.

**Example:** `tests/backend/codegen/Core-Data.opt-ir.expected` (`fromMaybe`, `getAge`)
```
function fromMaybe(d, mx)
    match_return mx
      arm Just a
          ret a
      arm Nothing
          ret d
function getAge(user)
    t3 = call __field1(user)
    ret t3
```

---

## 6. Dead statement elimination (DCE)

`dceBlock()` drops an `assign`, `call`, `call_value` or `let` whose destination is read nowhere and whose value has
no effect (`operandHasEffect()`: a foreign call, an effectful function reference, or a partial/list over one). It
runs to a fixed point (16 rounds) within a function body — top-level declarations are never removed here.

Runs after the earlier folds, after inlining, and once more as the `final-dce` stage.

**Example:** `tests/optimize/Opt-Dead.ir.php` → `ret intrinsic intAdd#(x, 1)` (the unused `let y = 42` is gone).

---

## 7. Common subexpression elimination (CSE)

`cseBlock()` keys on `Call` statements — callee plus argument keys, with an effectful callee excluded — and replaces
a repeated one with `assign <dest> <first temp>`; the follow-up copy propagation rewrites the readers. It runs
twice: after the local pass and in the after-inline stage.

An intrinsic is an operand, not a statement, so identical primops are *not* merged: `tests/optimize/Opt-CSE.opt-ir.expected`
keeps both copies of `intAdd#(x, 1)` in `intMul#(intrinsic intAdd#(x, 1), intrinsic intAdd#(x, 1))`. A computation
that would be read twice wants a temp at lowering time, which is what the lowering pass's `stabilizeOperand()` does.

---

## 8. Function inlining

A direct `call` to an eligible callee is replaced by the callee's body with parameters substituted and temps
remapped (`inlineFunctions()`, up to 4 rounds, so `f → g → h` collapses).

Eligibility: not foreign, not an IO action, not a capture-taking lambda; not a self-call; at most 4 statements
ending in `ret`; not returning a function reference (a data constructor is the exception); arity matches; no
`partial` or captured-lambda argument; and the inline must not rebind a name the caller still reads. A match wrapper
and an applied-partial provider have their own inline paths. Callee definitions are not removed from the module.

**Example:** `tests/optimize/Opt-Inline-Chain.ir.php` (`f`), `--opt-ir`
```
ret intrinsic intAdd#(intrinsic intMul#(x, 2), 1)
```

---

## 9. Applied-lambda fusion

A function that returns a lambda — `ret partial λ0(x)`, its captures already leading — applied by the caller
(`call inc(x)` then `call_value t(5)`) is fused into one call of the lambda, captures and arguments together. The
work is `foldPartialApply()` in `partial.php`, gated by `isSimpleLambda()`: a one-parameter lambda whose body is a
single `ret`.

**Example:** `tests/optimize/Opt-Lambda.opt-ir.expected`
```
function inc(x)
    ret partial λ0(x)
function bump(x)
    t1 = call λ0(x, 5)
    ret t1
function λ0(y)
    ret intrinsic intAdd#(x, y)
```

---

## 10. Tail recursion elimination

A self tail call becomes a `loop` with parameter reassignment and a jump back to the head
(`eliminateTailRecursion()`). Every backend emits it: PHP `while (true)` + `continue`, JVM a label + `goto`,
.NET/CIL a label + `br`.

Argument values are evaluated into scratch temps before any parameter is written, so an argument that reads a
parameter another argument overwrites still sees the pre-recall value. Skipped for lambdas and for functions with no
parameters.

**Example:** `tests/backend/codegen/Core-Recursion.opt-ir.expected` (`fact`)
```
function fact(n, acc)
    loop
        match_return n
          arm 0
              ret acc
          arm _
              tail_recall(intrinsic intSub#(n, 1), intrinsic intMul#(n, acc))
```

---

## 11. Dictionary-call specialization

`specializeDictCallsFunctions()` (`dict.php`) replaces a `dictCall` whose dictionary and method are settled by the
module's own instance evidence with a direct call to the resolved method, at the call's own statement position. It
runs twice, before and after inlining, because inlining changes which evidence is in scope. A `dictMethod` used as a
value stays a value.

---

## 12. Partial application and call legalization

`partial.php` owns the callable forms:

* `specializePartialReturnFunction()` — a function whose body is only `ret partial f(…)` gains the remaining
  parameters and calls `f` directly, so a named binding `inc = add 1` does not build a partial at run time.
* `foldPartialApply()` — saturates a partial at its use site; an applied partial becomes the `call` it stood for.
* `normalizeKnownApplies()` — rewrites an application whose callee arity is statically known into the canonical
  direct form, so the backends never reach for the runtime `__apply`. Conservatively: module-level names only (never
  a `λN`, whose partial arity counts captures), only when the surface type accounts for every parameter, never past
  the declared arity.
* `legalizeCallables()` — brings every stray callable form back to `call`/`partial`/`call_value` before emission,
  again at the end of the pipeline.

**Example:** `tests/optimize/Opt-Apply-Collapse.opt-ir.expected` (`exact`, `over`)
```
function exact()
    t0 = call add4(10, 20, 30, 40)
    ret t0
function over(n)
    t0 = call_value @add4(1, 2, 3, 4, n)
    ret t0
```

---

## Pass order

`optimize()` runs these stages, each under its own checkpoint label; the harness validates the module after every
one (the compiler itself does not).

1. **`early-folds`** — per function `optimizeFunctionEarly`: forward block walk → CSE → DCE → tail recursion
   elimination → match-return folding. An IO-returning function stops after the walk; a straight-line action is
   skipped entirely.
2. **`specialize-dict-calls-1`**
3. **`inline-functions`** (4 rounds)
4. **`specialize-dict-calls-2`**
5. **`after-inline-folds`** — per function `optimizeFunctionAfterInline`: constructor field cleanup → forward walk →
   constructor field cleanup → CSE → copy propagation → intrinsic folding → DCE → tail-return folding →
   match-return folding. A straight-line action gets only `foldKnownMatchesInItems`.
6. **`normalize-known-applies`**
7. **`legalize-callables`**
8. **`fold-partial-apply`**, **`specialize-partial-return`**, then `fold-partial-apply-2` when the module still has
   more than two functions or more than 24 statements
9. **`eliminate-intrinsic-wrappers`**, **`fold-io-action-boxes`**, **`recompute-io-straight-line`** (module-wide)
10. **`final-folds`** — per function `optimizeFunctionFinal`: copy propagation → intrinsic folding → DCE →
    tail-return folding (a straight-line action: copy propagation, tail-return folding and single-use call folding).
11. **`annotate-io-effects`**, **`final-dce`**, **`legalize-callables-final`**

---

## Limitations

| Pass | Not handled (yet) |
|------|-------------------|
| Copy propagation | Values only, never a `call`; no rewrite that would duplicate a non-duplicable value or a temp read more than once |
| Intrinsic folding | Two-literal arithmetic (overflow excluded), string/bytes append, list spine head/tail/append; no float folding and no folding across a temp that copy propagation kept |
| CSE | `call` statements within one block; an intrinsic operand is never merged, because it is not a statement |
| DCE | Within a function body only; declarations stay in the module |
| Inlining | ≤4 statements ending in `ret`; 4 rounds; bodies with `match`/`loop` only through the match-wrapper path; callee definitions stay |
| Applied-lambda fusion | Provider is a single `ret` of a lambda; a one-parameter lambda with a single `ret` |
| Tail recursion | Named functions with ≥1 parameter; one recursive tail-call shape per function; lambdas excluded |
| Match-return | Whole body must be a `match` plus `ret` of the match temp, every arm returning |
| Dict-call specialization | Needs the module's instance evidence to fix the dictionary and method |

**Not optimized:** global value numbering, loop-invariant code motion, strength reduction, unused top-level
declaration elimination.

---

## Implementation files

| File | Passes |
|------|--------|
| `optimize.php` | Driver (`optimize`), the checkpoint labels, per-function stage sets (`optimizeFunctionEarly` / `AfterInline` / `Final`) |
| `support.php` | Shared helpers: `operandEqual`, `operandKey`, `stmtDest`, `tempUseCountsInItems`, `isPropagatableExpr`, `isSimpleOperand`, `exprFromDefiningStmt`, `scheduleCall`, `substituteTempInExpr`, `mapStmtNestedBlocks`, `callArgsAreLeaves`, `dropUnreadTemps`, `indexCapturedFunctions` |
| `local.php` | `optimizeBlock` forward walk (copy propagation, operand mapping, match-arm handling), `foldMatchReturn`, `foldArmMatchReturn`, `foldTailReturn`, `foldSingleUseCallResults`, `optimizeIoAssignAction` |
| `global_pass.php` | `cseBlock`, `dceBlock`, `propagateCopiesBlock`, effect tracking (`setEffectfulCallees`, `calleeIsEffectful`) |
| `case_fold.php` | Constructor field cleanup and known-match folding (`foldKnownConstructors`, `foldKnownConstructorsBlock`, `foldKnownMatchesInItems`), mid-block match-return rewriting, match-statement tail-consumer joining |
| `fold_intrinsic.php` | `foldIntrinsicsItems`, `foldIntrinsicNode`, the per-primop folders and the list-spine helpers |
| `intrinsic.php` | `eliminateIntrinsicWrappers` (a one-primop wrapper function collapses into its primop) |
| `partial.php` | `legalizeCallables`, `bindLambdaCaptures*`, `foldPartialApply`, `normalizeKnownApplies`, `specializePartialReturnFunction` |
| `dict.php` | Dictionary-call specialization (`specializeDictCalls*`) |
| `effects.php` | IO and host-effect annotation (`annotateIoEffects`), and the effectful-callee table (`effectfulCalleeNames`) |
| `io_specialize.php` | `foldIoActionBoxes`, `recomputeIoStraightLineAll` |
| `interproc.php` | `inlineFunctions` and the inline helpers (temp remap, capture and qualification guards) |
| `ir_transform.php` | `remapTempsIn*`, used by inlining |
| `tco.php` | `eliminateTailRecursion` |
| `specialize.php` | Cross-module specialization (root-module selection, evidence and constant burning, spec-clone naming) |
| `tree_shake.php` | Module reachability |
| `pass_checkpoints.php` | The per-stage validation the harness turns on; off during a normal compile |
