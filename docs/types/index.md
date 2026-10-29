---
title: "The type system"
description: What Moggi checks before your program runs, and the order to read it in.
---
# The type system

Moggi is statically typed. Every expression has a type, and the compiler checks
the whole program before any of it runs: a program that compiles has no
unmatched `case`, no missing instance, no arity mistake and no kind mistake left
to discover at run time. The check is also what makes the language's guarantees
usable — you can change a type and let the compiler point at every place that
still assumes the old one.

The type system is small enough to learn in a sitting, but it is layered. The
pages below follow the layers, each one using only what the pages before it
introduced.

| Page | Question it answers | You meet it when |
|---|---|---|
| [Data types and pattern matching](data-types.md) | How do I describe a shape of data, and how do I take it apart? | Your first `data` declaration |
| [Polymorphism and inference](polymorphism.md) | When do I write a type, and what does the compiler work out? | Your first function used at two types |
| [Type classes](classes.md) | How does one function work for many types, and how are constraints checked? | Your first `class`/`instance` |
| [Deriving](../deriving.md) | How do I get `show`, `==`, `map` and friends without writing them? | Your first `deriving` clause |
| [Kinds](kinds.md) | What is the "type of a type"? | A type error about `Type -> Type` |
| [Higher-kinded types](higher-kinded-types.md) | How can a class range over containers rather than elements? | Writing a class over `f a` |
| [Type-level data (DataKinds)](data-kinds.md) | How can a value's type record a number or a tag? | A phantom parameter such as `Proxy 'Red` |
| [Generic programming](generics.md) | How do I write one function over the shape of any data type? | Deriving `Generic` |

## What the check covers

* **Types.** Every binding and expression is unified with the types around it;
  a signature, when written, is checked rather than trusted.
* **Exhaustiveness.** A `case` or a group of function clauses must handle every
  constructor it can see ([data types](data-types.md#exhaustiveness)).
* **Constraints.** Every class operation must be discharged by an instance that
  is in scope; an instance is global, so "in scope" means "declared somewhere in
  the program" ([classes](classes.md#how-an-instance-is-chosen)).
* **Kinds.** A type constructor may only be applied to as many arguments as its
  kind accepts ([kinds](kinds.md#kind-errors)).
* **Effects.** `IO` is confined to functions that return it
  ([IO and effects](../effects/io.md#the-purity-boundary)).

## What it deliberately does not do

There is no run-time type test, no reflection over a value's type, and no way to
branch on a type. A function polymorphic in `a` cannot ask what `a` is; it can
only use the operations its constraints name. That is a guarantee, not a gap: it
is why a polymorphic function behaves the same at every type.

## What is not implemented

A few things a reader may look for are absent, not hidden. Indexed data
declarations whose constructors refine the type parameter do not parse; use an
explicit tag plus a smart constructor
([data types](data-types.md#what-is-not-available)). Families are only
class-associated ([classes](classes.md#associated-type-families)), and kinds are
not polymorphic ([kinds](kinds.md#limits)). A signature cannot require a
polymorphic argument, and instance selection has no overlap pragmas, only
specificity ([polymorphism](polymorphism.md#limits),
[classes](classes.md#how-an-instance-is-chosen)).

## Conventions used in these pages

* `Type` is the kind of ordinary types (`Int`, `String`, `Maybe Int`). Kinds are
  covered in [Kinds](kinds.md).
* `[a]` and `List a` are the same type; `List` is the name to use when the type
  constructor itself is the argument, for example in an instance head.
* Whenever a page shows compiler output, it is the compiler's own text, not a
  paraphrase.
