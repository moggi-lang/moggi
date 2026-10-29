---
title: "Type classes"
description: One operation, many types — defining classes, writing instances, and what the compiler checks about constraints.
---
# Type classes

A class names a set of operations that several types can provide. A function
with a class constraint works for every type that has an instance, and the
compiler picks the instance from the type at each call site. There is no
interface value passed at run time in your source, no `instanceof`, and no
lookup table you maintain.

```moggi
class Pretty a where
  pretty :: a -> String
  prettyList :: [a] -> String
  prettyList xs = "[" <> unwords (map pretty xs) <> "]"

instance Pretty Bool where
  pretty b = case b of
    True -> "yes"
    False -> "no"

instance Pretty Int where
  pretty n = show n

instance Pretty a => Pretty [a] where
  pretty xs = prettyList xs

main :: IO ()
main = do
  putStrLn (pretty True)
  putStrLn (pretty [True, False])
  putStrLn (pretty [1, 2, 3])
```

```text
yes
[yes no]
[1 2 3]
```

`pretty [1, 2, 3]` works because the list instance requires `Pretty Int`, and
that instance exists. If it did not, the call would be a compile error naming
the missing constraint, not a run-time failure.

## Declaring a class

A class declaration lists method signatures. A method may have a **default
body**, which instances inherit unless they override it:

```moggi
class Describe a where
  describe :: a -> String
  describe _ = "something"
```

Methods may be operators, and several methods may share one signature:

```moggi
class Same a where
  (===), (=/=) :: a -> a -> Bool
```

A class may state its **superclasses**: `class Eq a => Pretty a where …` means
every `Pretty` instance must also be an `Eq` instance. The requirement is checked
at the instance, as [below](#missing-superclasses) shows.

### MINIMAL

`{-# MINIMAL … #-}` states which methods an instance must implement. Alternatives
are separated by `|`, and an alternative is satisfied only when every method it
lists is written:

```moggi
class Split a where
  split :: a -> (a, a)
  join :: a -> a -> a
  {-# MINIMAL split | join #-}
```

An instance that satisfies no alternative is rejected by name —
`instance for `Split` missing method `split`` — so the mistake is caught at the
instance rather than at the first call.

## Instances

An instance supplies the methods for one type:

```moggi
class Sized a where
  size :: a -> Int

instance Sized Bool where
  size _ = 1

instance Sized a => Sized (Maybe a) where
  size m = case m of
    Nothing -> 1
    Just x -> 1 + size x
```

Four rules govern instances:

* **Instances are global.** Every module sees every instance in the program
  without importing anything, which is why a function in one module can call
  `pretty` on a type declared in another.
* **A method may have several clauses.** An instance's method is one definition
  whose clauses are tried in order, like any function. The usual rules apply: a
  gap that leaves a constructor unhandled is a non-exhaustive-match error at the
  instance.
* **Instances may be constrained.** `instance Pretty a => Pretty [a]` is how the
  library gives behaviour for containers of anything.
* **An instance may be empty** when every method has a default: `instance Tag
  Suit where` is complete if `Tag`'s methods all have defaults.

Instances can be declared in any module, including one that declares neither the
class nor the type. There is no orphan restriction, so a library can supply an
instance for a type it does not own.

## Constraints

A signature may require one or more classes:

```moggi
maximumOf :: (Ord a, Show a) => [a] -> String
maximumOf xs = show (maximum xs)
```

Read `=>` as "provided that". The constraints are checked at each call: the
caller must have `Ord` and `Show` instances for the element type, or be
polymorphic with the same constraints.

A constrained local binding is generalised like a top-level function, so a
`where` helper that needs a class can be used at several types:

```moggi
showTwice :: Show a => a -> String
showTwice x = both x
  where
    both y = show y <> " and " <> show y
```

### Missing superclasses

If a class requires another, the instance must come with it:

```moggi
class Ord a => Sorted a where
  sortIt :: [a] -> [a]

data Box = Box Int

instance Sorted Box where
  sortIt xs = xs
```

```text
Main.mog:6:10: type error: missing superclass instance: `Ord Box`

  6 | instance Sorted Box where
    |          ^^^^^^
```

Deriving `Ord` on `Box` (which also derives `Eq`, because `Ord` requires it)
satisfies the requirement; see [Deriving](../deriving.md).

## How an instance is chosen

The instance is chosen from the type of the operation's arguments. Nothing in
the source says which one to use, so a type that is not fixed by the surrounding
code is an error:

```moggi
main :: IO ()
main = putStrLn (show mempty)
```

```text
Main.mog:1:1: type error: unresolved type class constraint: `Show a`
```

The reported class is the one that could not be discharged. The fix is a
signature or an ascription that names the type, not a longer list of instances.

Instances may overlap. When more than one matches, the **more specific** one is
used, regardless of declaration order:

```moggi
class C a where
  c :: a -> String

instance C a where
  c _ = "any"

instance C Int where
  c _ = "int"

main :: IO ()
main = putStrLn (c (1 :: Int))
```

```text
int
```

There are no overlap pragmas. If two instances are equally specific the call is
ambiguous and reported as such.

**Advanced from here.** The remaining sections go beyond everyday use: a
relationship between two types, type-level functions per instance, and how a
default body is checked. Stop here and come back when a design needs them.

## Multi-parameter classes

A class may take more than one parameter, which lets an instance describe a
relationship between two types rather than a property of one:

```moggi
class Convert a b where
  convert :: a -> b

instance Convert Int String where
  convert = show

instance Convert Bool Int where
  convert b = case b of
    True -> 1
    False -> 0

main :: IO ()
main = do
  putStrLn (convert (1 :: Int))
  putStrLn (show (convert True :: Int))
```

```text
1
1
```

Resolution needs every parameter that distinguishes the instances to be known.
`putStrLn (convert (1 :: Int))` fixes the result to `String` because that is
what `putStrLn` takes; `show (convert True)` alone does not say whether the
result is `Int` or `String`, so the ascription `:: Int` is what selects the
`Convert Bool Int` instance.

## Associated type families

A class may declare a type as part of its interface, and each instance says what
that type is:

```moggi
class Collection c where
  type Element c
  toList :: c -> [Element c]

instance Collection Bool where
  type Element Bool = Bool
  toList b = [b]
```

`Element Bool` reduces to `Bool`, so `toList True` is a `[Bool]`. This is how the
library's `Generic` class exposes each type's representation
([Generic programming](generics.md#the-generic-class)).

An associated type is **not injective**: `Element c` being `Bool` does not tell
the compiler that `c` is `Bool`. A function may therefore go from the container
to its element type, but not back from the element type to a unique container.

Limits of the mechanism:

* Only class-associated families exist. There is no standalone `type family`
  declaration, no closed family, and no injectivity annotation.
* A family's kind may be fixed in the class (`type Rep a :: Type -> Type`); the
  equations are written per instance.
* An instance must define every associated type the class declares.

## Defaults are checked at the instance

A default body is type-checked again at each instance with the class parameter
substituted, so it may rely on a constraint that the method's own signature does
not mention:

```moggi
class Tag a where
  tag :: a -> String
  tag x = show x

data Suit = Hearts | Spades
  deriving (Show)

instance Tag Suit where

main :: IO ()
main = putStrLn (tag Hearts)
```

```text
Hearts
```

`tag :: a -> String` does not require `Show a`, but the default's body uses
`show`. The instance for `Suit` is accepted because `Show Suit` exists there. A
type without `Show` would be rejected at its own instance.

## Limits

* **No functional dependencies.** `class Convert a b | a -> b` is a parse error.
  Encode the relationship with an associated type, or fix the second parameter
  with a signature.
* **No quantified or equality constraints** in signatures, and no class-level
  `deriving` clauses (`deriving` is a data-declaration or standalone form; see
  [Deriving](../deriving.md)).
* **No overlap pragmas.** Selection is by specificity; equally specific
  instances are ambiguous.
* **A method has at most one default**, and its body is one definition whose
  clauses are tried in order.

## Where next

* [Deriving](../deriving.md) — the instances the compiler can write for you.
* [Higher-kinded types](higher-kinded-types.md) — classes whose parameter is a
  container rather than an element.
* [Generic programming](generics.md) — classes over the shape of a data type.
