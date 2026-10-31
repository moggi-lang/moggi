---
title: "Data types and pattern matching"
description: How to describe data with data, newtype, records and synonyms, and how to take it apart with patterns.
---
# Data types and pattern matching

A `data` declaration defines a type by listing every shape a value of that type
can have. The list is closed: nothing outside the declaration can add an
alternative, which is what lets the compiler check that a `case` handles all of
them.

```moggi
data Shape = Circle Double | Rect Double Double
  deriving (Show, Eq)

area :: Shape -> Double
area s = case s of
  Circle r -> 3.141592653589793 * r * r
  Rect w h -> w * h

main :: IO ()
main = putStrLn (show (area (Rect 2.0 3.0)))
```

```text
6.0
```

`Shape` has two **constructors**. `Circle` takes one field, `Rect` two; a
constructor with fields is a function (`Circle :: Double -> Shape`), so
`Circle 1.0` builds a value. Constructors are ordinary names: they are exported
from a module like anything else, and they can be passed to functions.

## Parameterized and recursive types

A declaration may take type parameters, and a constructor may mention the type
being declared:

```moggi
data Tree a = Leaf | Node (Tree a) a (Tree a)
  deriving (Show)

insert :: Ord a => a -> Tree a -> Tree a
insert x t = case t of
  Leaf -> Node Leaf x Leaf
  Node l v r
    | x < v -> Node (insert x l) v r
    | x > v -> Node l v (insert x r)
    | otherwise -> t

toList :: Tree a -> [a]
toList t = case t of
  Leaf -> []
  Node l v r -> toList l ++ [v] ++ toList r

main :: IO ()
main = putStrLn (show (toList (insert 2 (insert 1 (insert 3 Leaf)))))
```

```text
[1,2,3]
```

`Leaf` is a **nullary** constructor: it takes no fields, so it is a value rather
than a function.

## `newtype`: a distinct type with no wrapper

`newtype` declares exactly one constructor with exactly one field. It is a
distinct type to the compiler, but construction and matching are the identity at
run time — no tag, no allocation beyond the field itself:

```moggi
newtype Age = Age Int
  deriving (Show)

birthday :: Age -> Age
birthday (Age n) = Age (n + 1)

main :: IO ()
main = putStrLn (show (birthday (Age 41)))
```

```text
Age 42
```

`Age` is not `Int`: an `Int` will not type-check where an `Age` is expected, and
the compiler will not let you add two `Age` values unless you derive or write
the instance ([deriving](../deriving.md) covers `deriving newtype`, which
borrows the representation's instances).

An ordinary single-constructor `data` type stays tagged:

```moggi
data Box = Box Int   -- one constructor, but still a tagged value at run time
```

## Records

A constructor can name its fields. The record form is still one constructor with
positional fields; the names are for reading and writing them.

```moggi
data Address = Address { city :: String, zip :: String }
data Person = Person { name :: String, age :: Int, address :: Address }

home :: Address
home = Address { city = "Berlin", zip = "10115" }

alice :: Person
alice = Person { name = "Alice", age = 30, address = home }

main :: IO ()
main = do
  putStrLn alice.name
  putStrLn alice.address.city
  putStrLn (show alice.age)
```

```text
Alice
Berlin
30
```

Three rules shape how records are used:

* **Construction names every field.** `Person { name = "Bob" }` is an error;
  there is no partial construction and no default.
* **A pattern may name a subset.** The fields left out are wildcards, so
  `Person { name = n }` matches any `Person` and binds only the name.
* **A field is not a function.** `alice.name` is projection; there is no
  `name alice`. Projection binds tighter than application, so
  `show alice.age` means `show (alice.age)`.

Field names belong to the record, not to the module: two records may both
declare `name`, and the receiver's type decides which one is meant.

### Updating a record

`value { field = newValue }` copies the value with the named fields replaced.
Fields not named keep their current values, and updates nest:

```moggi
older :: Person
older = alice { age = alice.age + 1 }

moved :: Person
moved = alice { address = alice.address { city = "Hamburg" } }
```

An update rebuilds one constructor, so it needs a record type with a single
constructor. With two alternatives, which fields a value has is only known at run
time, so the compiler refuses the update instead of failing later; match on the
constructor and rebuild it instead.

## Type synonyms

`type` gives an existing type another name. It introduces no new type and costs
nothing at run time:

```moggi
type UserId = Int
type Handler = String -> String
```

Synonyms may take parameters (`type Pair a = (a, a)`) and are expanded by the
compiler wherever they appear.

## Patterns

A pattern is how a value is taken apart. The same forms work in `case`
alternatives, in function clauses, in `let`/`where` bindings and in lambda
parameters (lambdas accept variables only — destructure in the body instead).

| Pattern | Matches |
|---|---|
| `x` | anything, binding it to `x` |
| `_` | anything, binding nothing |
| `Circle r` | that constructor, binding its fields |
| `Person { name = n }` | a record, binding the fields named |
| `1`, `"abc"`, `'x'` | that literal |
| `[]`, `x : xs` | the empty list, and a non-empty list head and tail |
| `(a, b)` | a pair (up to 64-tuples are in the library) |
| `x :| xs` | an infix constructor (see below) |

Patterns nest, and a function's clauses are tried in order:

```moggi
describe :: Maybe (Int, String) -> String
describe m = case m of
  Nothing -> "nothing"
  Just (0, _) -> "zero"
  Just (n, label) -> label <> "=" <> show n
```

`if` is sugar for a two-alternative `case` on `Bool`; both branches are
required. Guards attach a condition to a clause and are tried in order:

```moggi
classify :: Int -> String
classify n
  | n < 0 = "negative"
  | n == 0 = "zero"
  | otherwise = "positive"
```

Guards also work on `case` alternatives:

```moggi
sign :: Maybe Int -> String
sign m = case m of
  Just n | n > 0 -> "positive"
         | otherwise -> "non-positive"
  Nothing -> "none"
```

## Exhaustiveness

A `case` or a clause group must cover every constructor. This is a compile
error, not a run-time surprise:

```moggi
data Color = Red | Green | Blue

name :: Color -> String
name c = case c of
  Red -> "red"
  Green -> "green"
```

```text
Main.mog:4:10: type error: non-exhaustive case: missing patterns: Blue

  4 | name c = case c of
    |          ^^^^^^
```

The message names the shapes that are missing. Extra alternatives are accepted
silently: a redundant arm is not reported, so keep `case` expressions to the
constructors that exist.

**Advanced from here.** Infix constructors, strictness and the list of absent
forms are details you can skip on a first reading; the sections above are what
everyday declarations need.

## Infix constructors

A constructor whose name starts with `:` is written between its arguments.
Fixity is optional; without a declaration it is `infixl 9`, the same default as
an ordinary operator:

```moggi
data NonEmpty a = a :| [a]
  deriving (Show)

sumAll :: NonEmpty Int -> Int
sumAll (x :| xs) = x + sum xs

main :: IO ()
main = putStrLn (show (sumAll (1 :| [2, 3])))
```

```text
6
```

`infixr 5 :|` would change how chains associate; `:|` itself is just a name, and
`(:|) 1 [2]` is the same value in prefix form.

## Strictness

Fields are evaluated when the value is built. There are no thunks and no lazy
fields: constructing a value with an expression that fails fails immediately,
even if no field is ever read.

```moggi
data P = P Int

main :: IO ()
main = do
  let q = P (error "boom")
  putStrLn "unreachable"
```

```text
moggi: ErrorCall: boom
  at Moggi.Err.error (libraries/base/src/Moggi/Err.mog:8:13)
  at Main.main (Main.mog:4:8)
```

For the same reason there are no infinite or self-referential structures: a
value is finite, and recursion must consume what it produces
([Polymorphism](polymorphism.md) covers inference; [IO and effects](../effects/io.md)
covers `error` in context).

## What is not available

* **GADTs.** The `where` form of a data declaration is not part of the language;
  it is a parse error rather than an unimplemented feature:

  ```text
  Main.mog:1:13: parse error: expected expression, found keyword 'where'
  ```

  The alternatives are an ordinary tagged type whose constructor carries the
  evidence you need, plus a smart constructor that only builds the valid
  combinations. [Kinds](kinds.md) covers the type-level half of what a GADT
  would give you.

* **As-patterns.** `x@(Just n)` is not supported; bind the whole value in the
  clause head and match again in the body, or use a `case` on the bound name.

* **Strictness annotations.** There is no `!` on fields and no `~`; evaluation
  is strict already.

## Where next

* [Polymorphism and inference](polymorphism.md) — signatures, what the compiler
  infers, and when a binding is restricted to one type.
* [Type classes](classes.md) — giving these types behaviour.
* [Deriving](../deriving.md) — `Show`, `Eq`, `Ord`, `Functor` and the rest,
  written by the compiler.
* [Kinds](kinds.md) — the kind of `Shape`, `Maybe` and `Tree`.
