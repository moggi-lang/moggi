---
title: "IR optimization"
description: The optimizer's model, the stage order, what each pass family may assume, and how to add a pass.
---
# IR optimization

The optimizer runs on IR after type checking and before codegen, on every
backend. `--no-opt` skips it; `--ir` and `--opt-ir` show before and after.

## The model

IR has one shape worth knowing: **a computation is an operand, not a statement
kind**. `x + 1` lowers to `intrinsic intAdd#(x, 1)`, an application to
`call f(x)`, a method projection to `dict_call`. A statement names a
computation only when a later read needs its result, so `ret intrinsic
intAdd#(x, 1)` and `t0 = intrinsic intAdd#(x, 1)` are the same operation with
and without a destination.

A pass that folds a computation rewrites the operand; a pass that schedules one
emits the statement. Two consequences:

* an intrinsic is never a CSE candidate, because it is not a statement;
* a computation read twice must be stabilized into a temp at lowering time.

Effects are a model, not a guess: `Optimize\Effects\Model` answers whether an
operand constructs, executes, may throw or may diverge, and the model follows
the call graph through function values and partial applications. A callee the
IR cannot name counts as possibly throwing.

## Stage order

`optimize()` in `src/optimize/optimize.php` runs these stages, each under a
checkpoint label; the test harness validates the module after each one, which is
how a pass that leaves an invalid module fails with that pass's label. A user
compile does not pay that cost and has no switch to turn it on.

1. `early-folds` — forward walk, CSE, DCE, tail-recursion elimination,
   match-return folding; an IO-returning function stops after the walk and a
   straight-line action is skipped.
2. `specialize-dict-calls-1`
3. `inline-functions` (up to 4 rounds)
4. `specialize-dict-calls-2`
5. `after-inline-folds` — constructor field cleanup, forward walk, CSE, copy
   propagation, intrinsic folding, DCE, tail-return and match-return folding.
6. `normalize-known-applies`
7. `legalize-callables`
8. `fold-partial-apply`, `specialize-partial-return`, `fold-partial-apply-2`
9. `eliminate-intrinsic-wrappers`, `fold-io-action-boxes`,
   `recompute-io-straight-line`
10. `final-folds` — copy propagation, intrinsic folding, DCE, tail-return.
11. `annotate-io-effects`, `final-dce`, `legalize-callables-final`

Two orderings are load-bearing: dictionary specialization runs both before and
after inlining (inlining changes which evidence is in scope), and callable
legalization runs both after partial folding and at the very end, because
folding can leave a non-canonical callable.

## Pass families

| Family | Files | What they do |
|---|---|---|
| Local block | `local.php`, `case_fold.php` | copy propagation, tail-return and match-return folding, constructor field extraction cleanup |
| Folding | `fold_intrinsic.php`, `intrinsic.php` | primop folding by the registry's strategy, list-spine folding, wrapper elimination |
| Global (per module) | `global_pass.php` | CSE of calls, DCE, block copy propagation |
| Callables | `partial.php`, `interproc.php` | inline direct calls, saturate partial applications, normalize known applies, legalize callables |
| Dictionaries | `dict.php` | replace a dictionary call with the resolved method when the evidence is settled |
| Recursion | `tco.php` | self tail call → loop |
| IO | `io_specialize.php`, `effects.php` | fold IO action boxes, straight-line IO, annotate effects |
| Whole program | `specialize.php`, `tree_shake.php` | cross-module specialization; reachability and stripping |

Size budgets live in the pass, not in the driver: inlining's profitability is
`inlineIsWorthwhile()`, and changing it is a tuning change, not an architecture
change. Legality is separate from profitability, and only legality can make the
output wrong.

## Adding or changing a pass

1. Decide the family: local (one block), module (one function or module), or
   whole program. A pass that needs program facts must read them from the run's
   owner (`Optimize\Facts`), not build a private index.
2. Write it as an IR-to-IR rewrite with an explicit precondition: what it
   requires of its input, what it preserves (effects, termination, arity).
3. Give it a checkpoint label, place it in the stage order, and say why that
   position (which earlier pass exposes the pattern, which later pass needs the
   result).
4. Add a fixture under `tests/optimize/` whose `opt-ir` golden shows the
   rewrite, plus a case that must *not* rewrite (a legality boundary).
5. Run `runtest --group optimize` and then the backend groups; `--no-opt`
   compares behavior with the optimizer off.

## Limits

The optimizer is deliberately conservative: no global value numbering, no loop
invariant code motion, no strength reduction, and no unused top-level
declaration elimination outside the whole-program stripping pass. Inlining is
bounded by statement count and refuses match/loop bodies except through the
match-wrapper path. A pass that cannot prove an effect harmless leaves the
statement in place; that is the intended failure mode.
