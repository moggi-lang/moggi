# Language overview

This is the reference for the surface language: what a `.mog` file may contain
and what each form means. For a gentler introduction start at
[tour.md](tour.md); for what the compiler does with the file, see
[pipeline.md](pipeline.md). The fixtures under `tests/syntax` are the ground
truth for every corner of the grammar.

## Basics

```moggi
add :: Int -> Int -> Int
add x y = x + y

addOne x = let y = 1 in x + y
addTwo x = x + y where y = 2

-- Recursive local helpers (evaluation is still strict)
fact n = go n 1
  where
    go 0 acc = acc
    go k acc = go (k - 1) (k * acc)
```

Functions curry by default. Type signatures are optional; inference fills in many gaps.

## Conditionals

```moggi
pick b = if b then 1 else 0

sign x =
  if x < 0
  then -1
  else if x == 0 then 0 else 1
```

`if` is syntax, not a function, and desugars to a `case` on the condition with
`True`/`False` alternatives — the same shape the type checker and the backends
already handle. `then` and `else` are keywords: they may sit on later lines as
long as they are indented deeper than the enclosing declaration, and both
branches are required.

## Data and patterns

```moggi
data Maybe a = Just a | Nothing

fromMaybe d mx = case mx of
  Just a -> a
  Nothing -> d

sign x = case x of
  0 -> 0
  _ -> 1
```

Infix data constructors use operator names that start with `:` (not bare `:` /
`::`, which stay reserved for list cons and type ascription):

```moggi
data NonEmpty a = a :| [a]

infixr 5 :|

headNe xs = case xs of
  x :| _ -> x

oneTwoThree = 1 :| [2, 3]
```

Prefix form `(:|) a [a]` is also accepted. Fixity declarations apply to
constructor operators the same way as to value operators. List cons `:` remains
the built-in list operator (not a user data constructor).

Any function can be used as an infix operator by wrapping its name in
backticks — `a \`f\` b` desugars to `f a b`. Backticked names bind with
default fixity 9 (left-associative), so they bind tighter than `*` unless a
`infix*` declaration says otherwise. Fixity declarations accept backticked
names, and a backticked name may itself be the declared operator:

```moggi
plus :: Int -> Int -> Int
plus a b = a + b

main :: IO ()
main = putStrLn (show (1 \`plus\` 2))
```

Records and type synonyms:

```moggi
type UserId = Int

data User = User { id :: UserId, name :: String }

getId User { id = i } = i
```

### Records

A record is the constructor with named fields. Construction names **every**
field; a pattern may name a subset of them, and the ones left out are wildcards.

```moggi
data Address = Address { city :: String, zip :: String }
data Person  = Person  { name :: String, age :: Int, address :: Address }

alice = Person { name = "Alice", age = 30, address = Address { city = "Berlin", zip = "10115" } }

-- construction: every field
bad = Person { name = "Bob" }               -- missing field `age`

-- a pattern names what it needs
cityOf Person { address = a } = a.city
```

Read a field with `.` on the value, and chain the reads:

```moggi
alice.name                        -- "Alice"
alice.address.city                -- "Berlin"
show alice.age                    -- projection binds tighter than application
```

A field is not a function: there is no `name alice`, only `alice.name`. Field
names are per record, not global — two records may each declare `name`, and the
receiver's type says which one is meant.

`p { city = "Hamburg" }` is the record with the fields you name replaced; the
fields you leave out are read back off the value. Only the named fields are
written, one level down included:

```moggi
rename :: String -> Person -> Person
rename n p = p { name = n }

moveTo :: String -> Person -> Person
moveTo c p = p { address = p.address { city = c } }
```

Like the projection, the braces belong to the value they follow, so they bind
tighter than application: `show p { age = 31 }` is `show (p { age = 31 })`.

An update rebuilds one constructor, so the record type needs a single
constructor — with two, which fields the value has is only known when it runs,
and the compiler refuses that shape instead of raising at run time.

`newtype` declares a distinct type with **exactly one constructor and exactly
one field**. Unlike `data`, construction and matching are identity
at runtime (no tag). Positional or single-field record form:

```moggi
newtype Age = Age Int
-- or: newtype Age = Age { unAge :: Int }

birthday (Age n) = Age (n + 1)
```

An ordinary single-constructor ADT stays tagged:

```moggi
data Box = Box Int   -- runtime ['Box', value], not identity
```

## Functions

```moggi
inc x = \y -> x + y        -- lambdas
double x = x + x           -- partial application / currying
```

## Type classes

```moggi
class Eq a => Pretty a where
  pretty :: a -> String
  prettyList :: [a] -> String
  prettyList = unwords . map pretty     -- default, overridable

  {-# MINIMAL pretty #-}                -- what an instance must define

instance Pretty Bool where
  pretty b = case b of
    True -> "yes"
    False -> "no"

instance Pretty a => Pretty [a] where
  pretty xs = "[" <> unwords (map pretty xs) <> "]"
```

* A class may have superclasses (`Eq a =>`), a method may have a default body,
  and `{-# MINIMAL ... #-}` lists the alternatives that satisfy an instance (an
  alternative is satisfied only when every method in it is written).
* **One equation per method inside an instance** — match with `case` rather than
  writing a second equation for the same method.
* Instances are global: every module sees every instance without importing
  anything, which is why a call can be resolved at a type only another module
  knows.
* A constraint is discharged by an instance or by another constraint in scope;
  a call at a still-polymorphic type stays a dictionary call.

## Deriving

```moggi
data Color = Red | Green | Blue
  deriving (Show, Eq, Ord, Enum, Bounded)

newtype Age = Age Int
  deriving newtype (Num)              -- borrow the representation's instance

data Person = Person { name :: String, age :: Int }
  deriving (Generic)
  deriving anyclass (ToJSON, FromJSON)  -- an empty instance per class

newtype Wrapped = Wrapped Int
  deriving via (Int) MyEq             -- run the instance of the `via` type
```

The stock form (`deriving (...)`), `deriving newtype`, `deriving anyclass` and
`deriving via (T)` are supported, as are standalone `deriving instance C T` and
`deriving via (T) instance C U`. [deriving.md](deriving.md) documents what each
generator emits.

## Effects and IO

An effectful function returns `IO a`; `do` sequences actions **in order**.

```moggi
import System.IO

main :: IO ()
main = do
  name <- getLine          -- run it, bind the result
  putStrLn ("Hello, " <> name)
  let greeting = "hi"      -- pure binding inside do (no `in`)
  putStrLn greeting
  pure ()                  -- the do-nothing action
```

`IO`, `putStr` and `putStrLn` come from the Prelude. Everything else that
touches the world is an explicit import: `System.IO` (handles, files, `print`,
`getLine`), `System.Environment`, `Control.Exception`, `System.Filesystem`,
`System.Filesystem.Path`.

## Strictness

Evaluation is strict. There is no `seq`, no `$!` and no `foldl'` (there is one
`foldl`, and it is strict); there is no infinite structure to build on
laziness, and `foldr` folds eagerly as it descends. Recursive code has to
consume what it produces.

## Modules

One `module` name per file. Module path segments start with an uppercase letter.
Imports are explicit. An omitted module header means:

```moggi
module Main (main) where
```

So the smallest executable is:

```moggi
main = putStrLn "Hello, world!"
```

An explicit header is equivalent:

```moggi
module Main where

main :: IO ()
main = putStrLn "Hello, world!"
```

Libraries use any other module name and need no `main`:

```moggi
module Demo.Math where

doubleIt :: Int -> Int
doubleIt n = n + n
```

Only `Main.main` is the program entry point; it must have type `IO a` for some
`a` (the result is discarded), with or without an explicit signature. A binding
named `main` in another module is an ordinary function.

```moggi
module Main

import Data.Maybe (Maybe, Just, Nothing)
import Data.Either as R
import Data.List qualified as L
```

Qualified names: `Data.Maybe.Just`, `R.Left`, `L.map`, …

`import M qualified as A` (ImportQualifiedPost style only — not `import qualified M`)
binds names solely under `A`; they are not brought into unqualified scope.
`import M as A` still brings names both unqualified and as `A.name`.

### Pragmas

Pragmas go above the module header (and above its doc comment, if it has one).
There are two:

```moggi
{-# LANGUAGE NoImplicitPrelude #-}
module Data.Char (Char, ord) where
```

`LANGUAGE` takes the one extension Moggi has. `NoImplicitPrelude` is for a module
that must see exactly what it imported — the `Prelude` itself, or a low-level
module the `Prelude` is built from — and it turns off the implicit `import Prelude`
(see [tour.md](tour.md#modules-imports-and-the-prelude)).

```moggi
{-# BACKEND
  php    = System.IO.PHP
  jvm    = System.IO.JVM
  dotnet = System.IO.DotNet
#-}
```

`BACKEND` names the module that implements this one per backend — the *facade*
pattern, where one API has one implementation module per host. Written on one
line for a single entry, and repeated if you prefer:

```moggi
{-# BACKEND php = System.IO.PHP #-}
```

Each entry is `<backend> = <Module>`, and a backend the pragma does not name cannot
be built: the facade's signatures are portable, so callers stay written once, and
compiling for a missing backend is an error naming both sides. [ffi.md §9](ffi.md#9-a-module-that-must-work-on-every-backend)
has the whole pattern; `lib/System/IO.mog` is a real example to copy.

## Comments

Comments are stripped by the lexer — they never reach the AST.

```moggi
-- line comment to end of line
add x y = x + y  -- trailing note

{- block comment -}
{- nested {- comments -} are supported -}
```

`--` does not start a comment when immediately followed by `-` or `>` (so `---` and `-->` remain valid operators).

## Built-in types and primitives

`Int` (64-bit), `Integer` (arbitrary precision), `Double`, `Word` and the
fixed-width `Int8`…`Int64`/`Word8`…`Word64`, `Char`, `String`, `Bool`, `IO`,
lists, tuples, ADTs, records, `newtype`s, lambdas, classes and instances. The
primitives behind them (`intAdd#`, `word64`, `ioPure#`, …) live in
`Moggi.Internal.Prim` and `Moggi.Internal.IO` and are imported only when writing
the library. For the library itself see [stdlib.md](stdlib.md); for the exact
`Prelude` export list, `lib/Prelude.mog`.
