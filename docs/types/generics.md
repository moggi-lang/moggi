---
title: "Generic programming"
description: Writing one function for the shape of any data type — Generic, representation types, metadata, and Generic1's limits.
---
# Generic programming

**Advanced page.** Read [classes](classes.md) and
[higher-kinded types](higher-kinded-types.md) first; the representation below is
a class over a type constructor.

A function written with a class works for many types, but the author of each
type still writes the instance. **Generic programming** goes one level up: the
compiler can describe a data type's *structure* as a value, so a function written
once over that structure works for every type that derives `Generic`.

This is how the JSON library encodes an arbitrary record without knowing its
fields, and how you can write a "count the fields" or "render for debugging"
function that needs no per-type instance.

## The `Generic` class

`deriving (Generic)` gives a type an instance of the class in `Moggi.Generics`:

```moggi
class Generic a where
  type Rep a :: Type -> Type
  from :: a -> Rep a x
  to :: Rep a x -> a
```

`Rep a` is an **associated type** ([Classes](classes.md#associated-type-families)):
each derived instance defines its own representation type. `from` converts a
value into that representation, and `to` converts it back. The `x` parameter is a
phantom that the representation types pass around; it carries no data.

The representation is built from a small set of constructors:

| Representation | Means |
|---|---|
| `U1` | a constructor with no fields |
| `K1 i c` | one field of type `c` |
| `f :*: g` | two field groups side by side (a product) |
| `f :+: g` | a choice between constructors (a sum) |
| `M1 i c f` | metadata `c` wrapped around `f` |
| `V1` | no constructors at all |

`M1` is tagged with `D` (the datatype), `C` (a constructor) or `S` (a record
selector); the type synonyms `D1`, `C1` and `S1` name those three.

## A first generic function

A generic function is a class over the representation, plus instances for the
representation constructors. Here is a function that counts the fields a
constructor carries:

```moggi
import Moggi.Generics

class GCount f where
  gcount :: f p -> Int

instance GCount U1 where
  gcount _ = 1

instance GCount (K1 i c) where
  gcount _ = 1

instance GCount f => GCount (M1 i c f) where
  gcount (M1 x) = gcount x

instance (GCount f, GCount g) => GCount (f :+: g) where
  gcount (L1 x) = gcount x
  gcount (R1 x) = gcount x

instance (GCount f, GCount g) => GCount (f :*: g) where
  gcount (x :*: y) = gcount x + gcount y

size :: (Generic a, GCount (Rep a)) => a -> Int
size x = gcount (from x)

data Shape = Circle Double | Rect Double Double
  deriving (Generic)

data Pair a b = Pair a b
  deriving (Generic)

main :: IO ()
main = do
  putStrLn (show (size (Circle 1.0)))
  putStrLn (show (size (Pair 1 True)))
```

```text
1
2
```

Read the pieces in order:

* `gcount` is defined on the representation, not on your type. Each instance
  handles one shape: `U1` (a nullary constructor) counts one, `K1` (one field)
  counts one, `M1` (metadata) is transparent, `:+:` adds the alternatives of a
  sum, and `:*:` adds the fields of a product.
* `size` is the bridge. Its context `(Generic a, GCount (Rep a))` says "whatever
  `Rep a` turns out to be, it has a `GCount` instance". The compiler solves that
  at each use, from the representation the derived instance defined.
* `Circle 1.0` has one field, `Pair 1 True` two. `Maybe`-shaped sums would count
  the fields of whichever constructor the value uses.

The `p` in `gcount :: f p -> Int` is the representation's phantom parameter. It
never appears in a result, which is why any `p` will do.

## Metadata

The `M1` tags are not opaque. `Moggi.Generics` declares three classes that answer
questions about them, and `deriving (Generic)` generates the instances:

| Class | Methods answer |
|---|---|
| `Datatype d` | the type's name, module, package, whether it is a newtype, whether it is a sum or all-nullary |
| `Constructor c` | the constructor's name, whether it is a record, how many fields it has |
| `Selector s` | the field's name |

Each method takes a `MetaProxy` — a value whose only purpose is to carry the
type in its parameter — and returns a `String`. The names are stored as
promoted strings, so the answers are compile-time constants
([DataKinds](data-kinds.md)).

Using these from your own code means naming a concrete representation type,such as `MetaProxy (Rep Color ())` for `Color`. That is a library-facing facility:
a signature cannot abstract over `Rep a`
([Polymorphism § Limits](polymorphism.md#limits)), so the library's codecs read
metadata inside the instances they generate rather than from user code.

## Generic in the library: JSON

The clearest production use is JSON. `ToJSON` and `FromJSON` have Generic-based
defaults, so a type with `Generic` gets an encoder and decoder from one
`deriving anyclass` clause:

```moggi
import Data.ByteString.Char8 qualified as BS
import Data.JSON
import Moggi.Generics

data Person = Person { name :: String, age :: Int }
  deriving (Generic, Show)
  deriving anyclass (ToJSON, FromJSON)

main :: IO ()
main = do
  result <- eitherDecode (BS.pack "{\"name\":\"Bob\",\"age\":41}")
  case result of
    Left err -> putStrLn err
    Right p -> putStrLn (p.name <> " is " <> show p.age)
```

```text
Bob is 41
```

The JSON package is a separate library root. In a checkout, add it (and its
dependency) with `--lib`:

```bash
moggi run app.mog --lib libraries/json/src --lib libraries/containers/src
```

In a package, declare the dependency and build with `moggi build`; the
[standard library page](../stdlib.md) describes the package layout.

`deriving anyclass` is what makes this work: it writes an empty instance, and
the class's default methods do the encoding through `Generic`. The context
`GToJSON (Rep a)` is solved at each instance site, exactly as in the `GCount`
example.

## `Generic1`

`Generic1` is the same idea for a type constructor of kind `Type -> Type`: its
representation marks the recursive and parameter positions with `Par1` and
`Rec1`, and it exposes `Rep1`, `from1` and `to1`.

It is **not derivable** in this release. `deriving (Generic1)` on a parameterized
type is rejected:

```text
Main.mog:4:13: type error: expected kind `Type -> Type`, got `Type`
```

So `Generic1` is present in the library and usable by code that writes its
instances by hand, but there is no compiler-generated `Rep1` for your types. For
container-shaped generic code, write the class over the container directly
([Higher-kinded types](higher-kinded-types.md)) or write the instance per type.

## Wrappers

`Moggi.Generics` also declares `Generically a` and `Generically1 f a`, newtype
wrappers that exist so a class instance can be routed through a type's generic
representation. The library ships no instances for them, so they are useful only
once you write the instances your own classes need; they are not a drop-in
`deriving` mechanism.

## Limits

* **`Generic1` cannot be derived**, as above.
* **`Generic` is stock-only.** `deriving newtype (Generic)` is refused: the
  representation must be the structure of the declaration, not of its
  representation type ([Deriving](../deriving.md#restrictions)).
* **Metadata cannot name `Rep a` in a general signature.** Use it from library
  code, or with a concrete representation type.
* **No reflection on values.** The metadata is compile-time; nothing inspects a
  value's type at run time.
* **GADTs do not exist**, so there is no generic programming over index-refined
  constructors.

## Where next

* [Deriving](../deriving.md) — the clause that creates the instance.
* [Type classes](classes.md) — the mechanism a generic function is built from.
* [Higher-kinded types](higher-kinded-types.md) — why `Rep a` has kind
  `Type -> Type`.
