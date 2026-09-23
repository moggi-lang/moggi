# IR optimizations

The compiler runs an optimization pass on IR after type checking and before PHP codegen. Optimizations are enabled by default; use `--no-opt` to skip them.

## Inspecting the pipeline

| Flag | Output |
|------|--------|
| `--ir` | IR before optimization |
| `--opt-ir` | IR after optimization |
| *(default)* | Optimized PHP codegen |
| `--no-opt` | Unoptimized PHP codegen |

Example workflow:

```bash
moggi compile tests/let.mog --ir
moggi compile tests/let.mog --opt-ir
moggi compile tests/let.mog --no-opt
moggi compile tests/let.mog
```

---

## 1. Copy propagation

Redundant `let` and `assign` bindings to constants, locals, temps, or function references are removed; uses are rewritten to the value directly. Pure `binop` results are also tracked (`t1 = x + x` propagates into `t1 + 1` as `(x + x) + 1` without a separate temp). The same idea runs again in finalize as `propagateCopiesBlock` (after CSE creates copyable temps).

**Example:** `tests/let.mog`

```moggi
addOne x = let y = 1 in x + y
```

**Before (`--ir`):**
```
let y = 1
t0 = x + y
ret t0
```

**After (`--opt-ir`):**
```
ret x + 1
```

---

## 2. Constant folding

Binary operations on two integer constants are evaluated at compile time. Only `+`, `-`, and `*` on integer literals are folded.

Folding runs in two places:

1. **Statement-level** — during the local forward pass, when a `binop` statement has two constant operands.
2. **Expression trees** — `foldExpr` recursively folds nested `expr_binop` nodes (in `ret` values and propagated copies). This matters after inlining collapses a call chain into one big return expression.

**Example:** `tests/opt-fold.mog`

```moggi
six x = 2 + 3 + x
```

**Before (`--ir`):**
```
t0 = 2 + 3
t1 = t0 + x
ret t1
```

**After (`--opt-ir`):**
```
ret 5 + x
```

(Verified: `moggi compile tests/opt-fold.mog --opt-ir`.)

**Example (expression folding after inlining):** `tests/php-keywords.mog`

```moggi
useKeywords = list 1 + echo 2 + match 3 + function 4 + __fn_list 5 + fn_list 6
```

After inlining the helper calls, the body of `useKeywords` is one addition tree. Expression folding reduces it to a single constant:

**After (`--opt-ir`, `useKeywords` only):**
```
ret 24
```

**Codegen (`useKeywords`):**
```php
function useKeywords() {
    return 24;
}
```

The helper functions (`list`, `echo`, …) are still emitted — every top-level binding is kept as an exported PHP function even when inlined away at call sites.

---

## 3. Algebraic identities and peephole rules

Peephole rules on binops:

| Rule | Example |
|------|---------|
| `x + 0` → `x` | |
| `0 + x` → `x` | |
| `x - 0` → `x` | |
| `x - x` → `0` | `tests/opt-zero.mog` |
| `x + x` → `x * 2` | `double` in `tests/opt-inline.mog` |
| `x * 1` → `x` | |
| `1 * x` → `x` | |
| `x * 0` → `0` | |
| `0 * x` → `0` | |

**Example:** `tests/opt-zero.mog`

```moggi
zero x = x - x
```

**After (`--opt-ir`):**
```
ret 0
```

**Codegen:**
```php
return 0;
```

---

## 4. Tail return folding

When the last statement defines a temp and the final `ret` returns that same temp, both are merged into a single `return` of the expression. Runs at the end of each local block walk and again in the finalize pass.

**Example:** a function body whose final `ret` returns a locally-bound temp — e.g. `add x y = x + y` from the add codegen fixtures

**Before:**
```
t0 = x + 1
ret t0
```

**After (`--opt-ir`):**
```
ret x + 1
```

---

## 5. Match arm return folding

`case` lowers to a `match` that writes each arm’s result into a shared temp, then returns it. Inside match arms, that temp is the same ID as the match’s destination — copy propagation would incorrectly erase arm bodies.

### Dest aliasing fix

Inside match arms, assigns to the match result temp are treated specially:

- Copyable values become `ret` in the arm.
- Non-copyable values are folded via `foldArmMatchReturn` (e.g. `call` + assign → `ret call …`).

When every arm ends with `ret` and the block ends with `ret t0` where `t0` is the match dest, the match becomes a `match_return` and the outer `ret` is dropped.

**Example:** `tests/int-case.mog`

**After (final `--opt-ir`):**
```
match_return x
  arm 0
      ret 0
  arm _
      ret 1
```

---

## 6. Constructor field cleanup

Pattern matching on ADT constructors lowers to `__fieldN` extractions plus pattern bindings. Codegen also binds fields from the scrutinee, so the IR work is redundant.

This pass removes duplicate field-extract `let`s and rewrites `ret call __fieldN(scrutinee)` to `ret local(var)` when the pattern already binds `var`. It runs **inside the local forward pass** when each `match` / `match_return` arm is optimized (not as a separate pipeline step).

**Example:** `tests/case.mog`

```moggi
fromMaybe d mx = case mx of
  Just a -> a
  Nothing -> d
```

**Before (arm body, mid-opt):**
```
ret call __field0(mx)
```

**After:**
```
ret a
```

**Codegen:**
```php
$a = $mx[1];
return $a;
```

---

## 7. Dead assignment elimination (DCE)

Removes `assign`, `let`, `binop`, and `call` statements whose result is never used. Runs twice (after local pass and in finalize).

**Example:** `tests/opt-dead.mog`

```moggi
dead x = let y = 42 in x + 1
```

**Before (`--ir`):**
```
let y = 42
t0 = x + 1
ret t0
```

**After (`--opt-ir`):**
```
ret x + 1
```

---

## 8. Common subexpression elimination (CSE)

Identical `binop` and `call` expressions within a block share one temp. A follow-up copy-propagation pass rewrites uses of the duplicate temp.

CSE runs **twice**: once per function after the local pass, and again in finalize (after inlining and lambda fusion may have introduced new duplicates).

**Example:** `tests/opt-cse.mog`

```moggi
dup x = (x + 1) * (x + 1)
```

**Before (`--ir`):**
```
t0 = x + 1
t1 = x + 1
t2 = t0 * t1
ret t2
```

**After (`--opt-ir`):**
```
t0 = x + 1
ret t0 * t0
```

---

## 9. Function inlining

Eligible callees are inlined at direct `call` sites and in some `ret call …` expressions. The pass runs to a fixed point (up to 4 rounds) so call chains like `f → g → h` can collapse.

**Eligibility:** not a lambda; body ends with `ret` (not returning a lambda); at most 10 statements; no `match`, `loop`, or `tail_recall`; no self-calls; callee temps are remapped when splicing a multi-statement body.

**Limits:** no recursive/self inlining; functions returning a lambda reference are skipped; callees with control-flow constructs are skipped.

**Example:** `tests/opt-inline.mog`

```moggi
double x = x + x
quad x = double (double x)
```

**Before (`--ir`, `quad` only):**
```
t1 = call double(x)
t2 = call double(t1)
ret t2
```

**After (`--opt-ir`, `quad`):**
```
ret x * 2 * 2
```

(Copy-through binop propagates `x + x` into later binops; `x + x` is rewritten to `x * 2`.)

**Example (call chain):** `tests/opt-inline-chain.mog`

```moggi
h x = x + x
g x = h x + 1
f x = g x
```

**After (`--opt-ir`, `f`):**
```
ret x * 2 + 1
```

(Callees are inlined into callers using an index updated as each function is processed; nested calls inside spliced bodies are inlined too.)

**Codegen (`quad`):**
```php
function double($x) {
    return $x * 2;
}

function quad($x) {
    return ($x * 2) * 2;
}
```

(`double` remains in the module for direct calls; `quad` no longer calls it. Top-level functions are never removed from the module after inlining.)

---

## 10. Lambda fusion

When a function returns a lambda (`ret @λN`) and the caller immediately applies it (`call f(x)` followed by `call_value t(y)` or `ret call_value t(y)`), the call chain is fused into the lambda body with captures and arguments substituted.

**Limits:** provider is a single `ret` of a lambda; lambda has one parameter and a single `ret` of an `expr_binop`; fusion runs before inlining.

**Example:** `tests/opt-lambda.mog`

```moggi
inc x = \y -> x + y
bump x = inc x 5
```

**Before (`--ir`, `bump` only):**
```
t1 = call inc(x)
t2 = call_value t1(5)
ret t2
```

**After (`--opt-ir`):**
```
ret x + 5
```

**Codegen (`inc` still returns a native PHP arrow function; `bump` is fused):**
```php
function inc($x) {
    return fn($y) => $x + $y;
}

function bump($x) {
    return $x + 5;
}
```

---

## 11. Tail recursion elimination

Self tail calls are turned into a `while (true)` loop with parameter reassignment and a jump back to the loop head. This runs on **every** backend: PHP emits `while (true)` + `continue`, JVM emits a label + `goto`, .NET/CIL emits a label + `br`.

Supports direct tail calls, `case` with one recursive arm, and curried tail calls (`f (g x) (h y)`).

Because the recall reuses the parameter slots, every argument is evaluated into a
scratch local *before* any parameter is written, so arguments that mention a
parameter another argument overwrites (e.g. `swapAndDec a b = … swapAndDec b (a - 1)`)
still read the pre-recall values.

**Limits:** skipped for lambda functions and for functions with no parameters.

**Example:** `tests/countdown.mog`, `tests/fact.mog`

```moggi
countdown n = case n of
  0 -> 0
  _ -> countdown (n - 1)
```

**Before (`--ir`, `countdown`):**
```
match n
  arm 0
      assign t0 0
  arm _
      t1 = n - 1
      t2 = call countdown(t1)
      assign t0 t2
ret t0
```

**After (`--opt-ir`):**
```
loop
    match_return n
      arm 0
          ret 0
      arm _
          tail_recall(intrinsic intSub#(n, 1))
```

(Verified: `moggi compile tests/integration/full-pipeline/Core-Recursion.mog --backend jvm --opt-ir`. The recursive arm becomes `tail_recall` inside a `loop`, and the match becomes a `match_return` because its arms now end in `ret` / `tail_recall` themselves — a `MatchStmt` carries a result temp, and codegen would turn an arm's `ret` into a *yield* into that temp instead of a return. This is the same shape on all three backends.)

**Codegen (`countdown`):**
```php
function countdown($n) {
    while (true) {
        if ($n === 0) {
            return 0;
        } elseif (true) {
            $t1 = $n - 1;
            $n = $t1;
            continue;
        } else {
            throw new \RuntimeException('non-exhaustive match');
        }
    }
}
```

**Codegen (`fact` — two accumulator params):**
```php
function fact($n, $acc) {
    while (true) {
        if ($n === 0) {
            return $acc;
        } elseif (true) {
            $t1 = $n - 1;
            $t3 = $n * $acc;
            $n = $t1;
            $acc = $t3;
            continue;
        } else {
            throw new \RuntimeException('non-exhaustive match');
        }
    }
}
```

---

## Pass order

Per function (`optimizeFunction`), then module-wide passes, then per-function finalize:

1. **Local forward pass** (`optimizeBlock`): copy propagation, constant folding (statements and `foldExpr` on expression trees), peephole rules, constructor field cleanup inside match arms, tail-return / arm-return folding at block end
2. **CSE** (first round)
3. **DCE** (first round) — unused temps and lets within a function body only
4. **Tail recursion elimination** (converts eligible `match` + `ret` bodies into `loop` + `tail_recall`)
5. **Match-return folding** (`foldMatchReturnBlock` — applies when step 4 did not rewrite the body, e.g. `int-case.mog`)
6. **Lambda fusion** (module-wide)
7. **Function inlining** (module-wide, fixed-point up to 4 rounds)
8. **Local re-pass** (`reoptimizeLocal`: forward block pass including `foldExpr`, copy propagation, DCE, tail-return fold)
9. **Finalize** per function: CSE → post-CSE copy propagation → DCE → tail-return fold

Copy propagation appears in step 1 (fused with the forward walk), step 8 (`propagateCopiesBlock` after inlining), and step 9 (`propagateCopiesBlock` after CSE). CSE and DCE each run twice (steps 2 and 9). `foldExpr` runs during steps 1, 8, and 9 via `optimizeBlock` / `mapOperand`.

All top-level functions are always emitted to PHP, even when every call site has been inlined.

---

## Limitations

| Pass | Not handled (yet) |
|------|-------------------|
| Copy propagation | Simple operands plus copy-through `binop` chains (`expr_binop` in the temps map); no copy-through `call` |
| Constant folding | Integer `+`, `-`, `*` only; no floats or division folding; expression folding does not run as a separate fixed-point loop beyond the local passes |
| Peephole | Rules listed in §3 (including `x + x` → `x * 2`) |
| CSE | Within a single block only; `binop` and `call` (not `call_value`) |
| DCE | Within a function body only; top-level functions are always kept in the module |
| Inlining | Up to 10 stmts ending in `ret`; no match/loop/TCO; fixed-point (4 rounds); no lambda-returning callees; does not remove callee definitions from the module |
| Lambda fusion | Immediate `call` + `call_value`/`ret call_value` pattern; simple one-param lambdas with `expr_binop` body |
| TCO | Named functions with ≥1 param; one recursive tail-call shape per function; lambdas excluded |
| Match-return | Entire function body must be `match` + `ret` of the match temp, all arms returning |

**Not optimized:** cross-block/global value numbering, loop invariant code motion, strength reduction, unused top-level function elimination.

**Partial-application specialization** (in `src/optimize/partial.php`): functions whose body is only `ret partial f(...)` are rewritten to take the remaining parameters and call `f` directly, avoiding runtime partial thunks when the binding is named (e.g. `inc = add 1` becomes `inc(_p1) = add(1, _p1)`). Call-site folding (`foldPartialApply`) saturates partials at use sites, and accepts the `expr_partial` spelling so a fusion can consume the result of an earlier one.

**Known-arity apply canonicalization** (also `partial.php`): after inlining, an application whose callee arity is statically known is rewritten into the canonical direct forms, so the backends never reach for the runtime `__apply`: `call_value @f(args)` with `|args|` equal to `f`'s arity becomes `expr_call f(args)` (under-applied stays `partial f(args)`), and a curried call on a parameter whose declared type fixes its arity flattens `(f x) y` into one `f(x, y)`. Deliberately conservative: only module-level names (never `@λN`, whose partial arity counts captures), only when the surface type accounts for every parameter, and never past the declared arity.

---

## Implementation files

| File | Passes |
|------|--------|
| `optimize.php` | Driver (`optimize`), per-function stages (`optimizeFunctionEarly` / `AfterInline` / `Final`), pass ordering |
| `support.php` | Shared IR helpers (`operandEqual`, `operandKey`, `stmtDest`, `operandToExpr`, `foldExpr`, `foldBinop*`, temp use counts, `isLambdaName`, `mapStmtNestedBlocks`, `buildLambdaMeta`) |
| `local.php` | `optimizeBlock` forward block pass: copy propagation, constant folding, `foldExpr` in `mapOperand`, peephole; block-end `foldMatchReturn` / `foldArmMatchReturn` / `foldTailReturn`; `optimizeIoAssignAction` |
| `global_pass.php` | CSE (`cseBlock`), DCE (`dceBlock`), post-CSE copy propagation (`propagateCopiesBlock`), constructor field cleanup; `rewriteStmtOperands` operand rewrite helper; host-effect tracking |
| `case_fold.php` | Known-constructor match folding (`foldKnownConstructors`, `foldKnownMatchesInItems`), mid-block match-return rewriting (`rewriteMidBlockMatchReturns`), match-statement tail-consumer joining (`joinMatchStmtConsumer`), field-extract folding |
| `fold_intrinsic.php` | Intrinsic constant folding (`foldIntrinsicsItems`, `foldIntAdd`, `foldIntMul`, `foldStringAppend`, list spine/head/tail/append) |
| `intrinsic.php` | `eliminateIntrinsicWrappers`: collapse one-primop wrapper functions into the primop |
| `partial.php` | `bindLambdaCaptures`, partial-application folding (`foldPartialApply`), known-arity apply canonicalization (`normalizeKnownApplies`), partial-return specialization, runtime-`__apply` requirement analysis (`moduleUsesPartialApply`) |
| `dict.php` | Dictionary-call specialization (`specializeDictCalls*`) |
| `effects.php` | IO / host-effect annotation (`annotateIoEffects`, `hostEffectFunctionNames`) |
| `io_specialize.php` | IO action box folding (`foldIoActionBoxes`), `recomputeIoStraightLineAll` |
| `interproc.php` | Lambda fusion, function inlining |
| `ir_transform.php` | Temp remapping (`remapTempsIn*`), used by inlining |
| `tco.php` | Tail recursion elimination |
| `specialize.php` | Cross-module specialization (root-module selection, evidence/const burning, spec-clone naming) |
| `tree_shake.php` | Reachability-based module / function elimination |
