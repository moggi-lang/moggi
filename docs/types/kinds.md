---
title: "Kinds"
description: The type of a type — why Maybe needs one argument, how kind errors are reported, and how to write a kind signature.
---
# Kinds

**Advanced page.** Read [data types](data-types.md) and
[polymorphism](polymorphism.md) first; this page assumes both.

Every type has a **kind**, which says how many type arguments it takes before it
is a type you can have a value of. `Int` and `String` have kind `Type`; `Maybe`
does not — it needs one argument, so its kind is `Type -> Type`. Kind arrows read
like function types: `Either` is `Type -> Type -> Type`, and `(->)` is
`Type -> Type -> Type` as well.

| Type | Kind | Meaning |
|---|---|---|
| `Int`, `String`, `Maybe Int` | `Type` | a type with values |
| `Maybe`, `List`, `Tree` | `Type -> Type` | a type constructor waiting for one argument |
| `Either`, `(,)` | `Type -> Type -> Type` | waiting for two |
| `IO` | `Type -> Type` | a type constructor over its result |

A kind is not something you usually write. The compiler infers it from how the
type is declared and how it is used.

## Inference gives a constructor its kind

`data Pair f a = Pair (f a)` uses `f` applied to `a`, so `f` must be a
constructor of kind `Type -> Type`, and `Pair` itself becomes
`(Type -> Type) -> Type -> Type`:

```moggi
data Pair f a = Pair (f a)

pairMaybe :: Pair Maybe Int
pairMaybe = Pair (Just 1)

pairList :: Pair List Int
pairList = Pair [1, 2]

firstOf :: Pair f a -> f a
firstOf (Pair x) = x

main :: IO ()
main = do
  putStrLn (show (firstOf pairMaybe))
  putStrLn (show (firstOf pairList))
```

```text
Just 1
[1,2]
```

Nothing in the declaration said `f`'s kind; applying `f` to `a` is what fixes
it. The same inference applies to a class parameter: `class Functor f` gets
`f :: Type -> Type` because a method uses `f a`
([Higher-kinded types](higher-kinded-types.md)).

## Kind errors

Applying a constructor to more arguments than its kind accepts is an error at
the type, before any code is generated:

```moggi
bad :: Maybe Int Bool
bad = Nothing
```

```text
Main.mog:1:8: type error: type constructor `Maybe` applied to too many arguments

  1 | bad :: Maybe Int Bool
    |        ^^^^^
```

Passing a type of the wrong kind is reported the same way, naming both sides:

```text
Main.mog:4:18: type error: kind mismatch applying `OnlyColor`: expected `Color`, got `Type`
```

That example comes from [DataKinds](data-kinds.md), where a type parameter is
declared to be a *value-level* tag rather than an ordinary type.

## Kind signatures

A declaration may state the kind of one of its parameters. This is what makes a
phantom parameter a tag rather than a type:

```moggi
data Color = Red | Blue

data Tagged (c :: Color) = Tagged

red :: Tagged 'Red
red = Tagged
```

Kind signatures are also how a constructor parameter is required to be a
container:

```moggi
data Nested (f :: Type -> Type) a = Nested (f (f a))

nested :: Nested Maybe Int
nested = Nested (Just (Just 1))
```

A class parameter may be annotated the same way, `class Foo (t :: Type -> Type)
where …`, when inference alone would not settle it. There are no annotations on
type synonyms: `type Id (a :: Type) = a` is a parse error in this release, and a
synonym's parameters take the kind its right-hand side implies.

## Which kinds exist

`Type` is the kind of ordinary types. Three others are built in:

* `Nat` — type-level natural numbers, written as literals (`Port 80`).
* `Symbol` — type-level strings, written as string literals (`Label "hello"`).
* the kind of any `data` type whose constructors are promoted, such as `Color`
  above.

Both are covered in [DataKinds](data-kinds.md).

There is **no kind polymorphism**: a parameter cannot be declared "of any kind
`k`". `data Proxy (a :: k) = Proxy` is a parse error (`expected kind, found
identifier 'k'`). A declaration that must work at several kinds needs one
declaration per kind, or an ordinary type parameter where a kind would be
expected.

## Inspecting a kind

The REPL answers `:kind` (or `:k`) for a type or an expression's type:

```text
> :kind Maybe
Type -> Type
> :kind Either
Type -> Type -> Type
```

The same question at a compile error is answered by the message quoted above:
kind errors always name the constructor and both kinds.

## Limits

* **No kind polymorphism**, as above.
* **No kind signatures on type synonyms.**
* **No promotion of functions or type constructors** to the type level; only
  data constructors and the two literal kinds are promoted
  ([DataKinds](data-kinds.md)).
* **No GADTs**, so a constructor cannot refine the type parameters of the value
  it builds. The kind system checks arity and named kinds; it does not give you
  the type-indexed reasoning a GADT would
  ([Data types § What is not available](data-types.md#what-is-not-available)).

## Where next

* [Higher-kinded types](higher-kinded-types.md) — classes over `f a`, and what
  they buy.
* [Type-level data](data-kinds.md) — `Nat`, `Symbol` and promoted constructors.
