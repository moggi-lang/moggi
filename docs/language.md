---
title: "Language overview"
---
# Language overview

# Language overview

This reference describes Moggi's syntax and language constructs, with examples of how to use them. For a gentler introduction, start with [tour.md](tour.md); for an explanation of how the compiler processes source code, see [pipeline.md](pipeline.md).

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

`if` is syntax, not a function, and desugars to a `case` on the condition with `True`/`False` alternatives — the same shape the type checker and the backends already handle. `then` and `else` are keywords: they may sit on later lines as long as they are indented deeper than the enclosing declaration, and both branches are required.

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

Infix data constructors use operator names that start with `:` (not bare `:` / `::`, which stay reserved for list cons and type ascription):

```moggi
data NonEmpty a = a :| [a]

infixr 5 :|

headNe xs = case xs of
  x :| _ -> x

oneTwoThree = 1 :| [2, 3]
```

Prefix form `(:|) a [a]` is also accepted. Fixity declarations apply to constructor operators the same way as to value operators. List cons `:` remains the built-in list operator (not a user data constructor).

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

A **type class** defines a set of operations that types can implement. Instances provide implementations for specific types, allowing the same functions to work with different types.

```moggi
class Eq a => Pretty a where
  pretty :: a -> String
  prettyList :: [a] -> String
  prettyList = unwords . map pretty

  {-# MINIMAL pretty #-}                -- what an instance must define

instance Pretty Bool where
  pretty b = case b of
    True -> "yes"
    False -> "no"

instance Pretty a => Pretty [a] where
  pretty xs = "[" <> prettyList xs <> "]"
```

- **Superclasses** express requirements. `Eq a =>` means that a type must also have an `Eq` instance to have a `Pretty` instance.
- **Default methods** provide implementations that instances can inherit or override. Here, `prettyList` uses `pretty` to format a list of values.
- **Instances** define how a type implements a class. The `Bool` instance formats booleans as `"yes"` or `"no"`, while the list instance formats lists using the element type's `Pretty` instance.
- **Constraints** restrict which types a function accepts. For example, `Pretty a => a -> String` works for any type with a `Pretty` instance.
- **One equation per method.** A method is one definition: a class allows a single default per method, so a default that matches on its argument uses `case` rather than a second equation, and an instance's equations have to be written together — together they are one definition, matched in order. A method an instance leaves out is the class's default body.
- **Minimal definitions** name what an instance must provide: `{-# MINIMAL pretty #-}` requires `pretty`, and an instance is complete when it satisfies one alternative of the pragma. Alternatives are separated by `|`, and an alternative is satisfied only when every method it lists is written — `{-# MINIMAL bimap | first, second #-}` means an instance either defines `bimap` or defines both `first` and `second`.

Operators can also be class methods. Their names must be in scope, usually through an import or the `Prelude`, and what an operator means is what the instance for its operands' type declares: `n + 1` at `Int` is the `Num` instance `Data.Num` gives `Int`, and the same `+` at another type is that type's instance.

Instances are available program-wide, regardless of which module defines them.

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

Expressions are evaluated eagerly. Function arguments are evaluated before the function is called, and data structures are evaluated as they are constructed.

Folds evaluate their input eagerly. Recursive functions should consume data as they process it rather than rely on deferred evaluation.

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

`main` in the module compiled as the program is the entry point — `Main` by
convention, or whichever module a package's descriptor names. It must have type
`IO a` for some `a` (the result is discarded), with or without an explicit
signature. A binding named `main` in a module that is only imported is an
ordinary function.

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

A module name resolves to exactly one file. A name offered twice — two library
roots that both ship `A`, or a project module that shares its name with a library
module — is refused where the program reaches it, and the refusal names every file
the name could mean: nothing in the name tells the compiler which one the author
meant. Two installed packages may both hold the name; it becomes a fault when a
program imports it.

The implicit `import Prelude` is the standard library's, so a project module named
`Prelude` is refused rather than allowed to redefine what every module gets by
default. A prelude of your own is a module under another name, imported explicitly
where it is wanted (see `NoImplicitPrelude` below).

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
has the whole pattern; `libraries/base/src/System/IO.mog` is a real example to copy.

## Comments

Comments do not change what a program does.

```moggi
-- to the end of the line
add x y = x + y  -- trailing note

{- across lines -}
{- and {- nesting -} is supported -}
```

A `--` begins a comment unless the character right after it is `>`, so `-->` is an operator while `---` is a comment.

Documentation comments are the one kind that is kept: `-- |` on the lines before a declaration (or `{-| … -}` in block form), `-- ^` after it, and `-- *` to head a section of an export list. They describe the declaration they are attached to and are what the documentation tool shows; anywhere else they are ordinary comments.

## Built-in types and primitives

Moggi provides the fundamental types Int (64-bit), Integer (arbitrary precision), Double, Word, fixed-width signed and unsigned integers (Int8–Int64, Word8–Word64), Char, String, Bool and IO.

The language also supports lists, tuples, algebraic data types, records, newtypes, lambdas, type classes and instances.

Low-level primitives are provided by Moggi.Internal.Prim and Moggi.Internal.IO. They are primarily intended for implementing the standard library.

See stdlib.md for the standard library and libraries/base/src/Prelude.mog for the exact exports of Prelude.
