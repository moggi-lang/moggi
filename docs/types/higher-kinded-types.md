---
title: "Higher-kinded types"
description: Abstracting over containers rather than elements — classes over f a, partial application of type constructors, and the shape's limits.
---
# Higher-kinded types

**Advanced page.** Read [kinds](kinds.md) first; this page builds on it.

A type parameter is usually a type with values: `data Box a = Box a` puts one
`a` inside. A **higher-kinded** parameter is a type *constructor* instead — a
parameter of kind `Type -> Type`, such as `Maybe`, `List` or `Either String`.
The declaration then applies it: `f a`.

This is what lets one function work over many containers. `map` is not written
once for lists and again for `Maybe`; it is a method of a class whose parameter
is the container.

```moggi
main :: IO ()
main = do
  putStrLn (show (map (\x -> x + 1) [1, 2, 3]))
  putStrLn (show (map (\x -> x + 1) (Just 1)))
  putStrLn (show (map (\x -> x + 1) (Right 1 :: Either String Int)))
```

```text
[2,3,4]
Just 2
Right 2
```

`map` comes from `Functor`, whose parameter is `f`: the class declares
`map :: (a -> b) -> f a -> f b`. The `f` is not "a type containing an `a`" in
some loose sense; it is a constructor of kind `Type -> Type`, applied to `a` in
the signature. `Maybe`, `List`, `Either e` and your own parameterized types all
qualify.

## Folds over a shape

`Foldable` abstracts the same way over "a container of `a`s you can walk". Its
methods use `t a`, so any `t` of kind `Type -> Type` with an instance works:

```moggi
total :: (Foldable t, Num a) => t a -> a
total = foldr (+) 0

main :: IO ()
main = do
  putStrLn (show (total [1, 2, 3]))
  putStrLn (show (total (Just 41)))
```

```text
6
41
```

`Traversable` goes one step further and rebuilds the shape around effects; it is
why `traverse` can turn a `[Maybe a]` into a `Maybe [a]` without a list-specific
implementation.

## Writing a class over a constructor

The pattern is the same for a class of your own: declare the parameter, use it
applied, and give one instance per container.

```moggi
class Container f where
  empty :: f a
  insert :: a -> f a -> f a

instance Container Maybe where
  empty = Nothing
  insert x _ = Just x

instance Container List where
  empty = []
  insert x xs = x : xs

pair :: Container f => f Int
pair = insert 1 (insert 2 empty)

main :: IO ()
main = do
  putStrLn (show (pair :: Maybe Int))
  putStrLn (show (pair :: List Int))
  putStrLn (show (map (\x -> x * 2) (pair :: List Int)))
```

```text
Just 1
[1,2]
[2,4]
```

Two spelling details matter here:

* **Instance heads name the constructor, not the sugar.** `instance Container
  List` works; `instance Container []` is a parse error, because `[]` is list
  syntax, not a type expression. In signatures either spelling is accepted
  (`[a]` or `List a`).
* **The result of `pair` is polymorphic in the container.** `show (pair :: Maybe
  Int)` and `show (pair :: List Int)` are two instantiations of one definition,
  chosen by the ascription.

## Partial application

A constructor with two parameters becomes a `Type -> Type` constructor when
applied to one. `Either String` is such a constructor, which is why it has a
`Functor` instance and can be mapped over above. Your own types behave the same:
with `data Pair a b = Pair a b`, `Pair Int` is a constructor of kind
`Type -> Type`.

A type synonym is expanded before the instance is matched, so a synonym for a
partially applied constructor is accepted in an instance head and is equivalent
to writing the expansion.

## Why it matters

Higher-kinded abstraction is what makes the library's shape-generic functions
possible:

* `map`, `foldr` and `traverse` are written once and work for every container
  with an instance.
* A function can require "any container of `a`" without knowing which one, and
  the caller chooses by the type it passes.
* The same mechanism underlies `Generic`, where the parameter is a type's
  *representation* rather than a container
  ([Generic programming](generics.md)).

## Limits

* **No type lambdas.** A constructor parameter must be a named constructor or a
  partial application of one. There is no way to write "the function
  `\a -> Either e a`" as an argument, so an abstraction that needs a
  rearrangement of parameters needs a `newtype` wrapper or a dedicated type
  synonym.
* **No higher-rank signatures**, so a class method cannot itself require a
  polymorphic argument ([Polymorphism § Limits](polymorphism.md#limits)).
* **No kind polymorphism**, so a class ranges over constructors of one fixed
  kind ([Kinds § Limits](kinds.md#limits)).
* **No functional dependencies**, so a class cannot say that one parameter
  determines another; use an associated type
  ([Classes](classes.md#associated-type-families)).

## Where next

* [Kinds](kinds.md) — where `Type -> Type` comes from.
* [Type-level data](data-kinds.md) — parameters whose kind is a tag or a number.
* [Generic programming](generics.md) — the same idea applied to a data type's
  structure.
