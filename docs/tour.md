# The language, by example

This is the tour: the two ideas that explain most of Moggi, then the language as
it is actually written. Everything it uses is explained where it first appears,
and the comparisons are drawn from PHP, Java and C# — the three worlds Moggi's
backends live in. Complete syntax is [language.md](language.md); you do not need
it yet.

## The ideas, if you come from PHP, Java or C#

Moggi is not object-oriented, and it is not a script. Two shifts explain most of
the difference:

* **A module is a list of definitions, not a sequence of steps.** There are no
  statements at file level, no `for`/`foreach`/`while`, and nothing is
  reassigned. A name is bound once, to a value or to a function.
* **Functions are values.** You can pass a function to another function, return
  it, and keep it in a data structure. Functions taking functions (and returning
  them) replace most of the design patterns an OO programmer would reach for:
  a strategy object is just a function argument, and a callback is just a value.

The rest follows from those two.

| Moggi | What it is | Closest thing in PHP / Java / C# |
|---|---|---|
| `f :: Int -> Int` | a signature: a function from an `Int` to an `Int` | `function f(int $n): int` |
| `a = b` | a binding, once | `readonly`/`final` field, `const` |
| `\x -> x + 1` | a function value (a lambda) | `fn($x) => $x + 1`, `x => x + 1` |
| `data Shape = …` | a closed set of alternatives | a sealed hierarchy: an abstract class plus subclasses, or Java's `sealed interface` + `record`s |
| `case s of …` | pattern matching that also *destructures* | `match`/`switch`, but it binds the fields and must be exhaustive |
| `class Eq a where …` | a type class: an interface dispatched by type | an interface, but resolved by the compiler from the type (no container, no `instanceof`) |
| `instance Eq Shape where …` | an implementation, and it can be written anywhere | `implements`, except it lives outside the type, so you can add one without touching it |
| `deriving (Show, Eq)` | compiler-written `show`/`==` | `__toString`/`equals`, C# `record`s |
| `newtype Age = Age Int` | a distinct type, zero cost at runtime | a one-field value object with no runtime wrapper |
| `Maybe a` | present-or-absent | `?T`, `Optional<T>` |
| `Either e a` | a result that is an error or a value | a `Result<T, E>` type |
| `IO a` | a *description* of an effect, run when asked | a closure you call to do the work; `do` reads like a script |
| `error "…"` | give up (a bug, not a case to handle) | an uncaught exception, thrown on purpose |
| `String` | the host's native string | PHP string / `java.lang.String` / `System.String` |
| `Int` | 64-bit signed integer | PHP `int`, Java `long`, C# `long` |
| `Integer` | integer of any size | `BigInteger`, GMP values |
| `Double` | IEEE-754 double | PHP `float`, Java/C# `double` |

Two terms this tour uses that have no direct equivalent:

* **Partial application.** `add :: Int -> Int -> Int` applied to one argument
  gives you a function of the remaining argument: `add 1` is "add one". Any
  function can be used that way.
* **Type inference.** You may write a signature for every definition, or none at
  all — the compiler works out the type, use by use, and reports it when
  something does not fit. Writing the signature is the norm for anything you
  intend to reuse.

Terms used later here, defined once: a **constructor** is a way to build an
alternative of a data type (`Circle 3.0`); a **pattern** is the shape-matching
form in `case` (`Circle r`); a **fold** is the general form of "walk a container
and combine its elements" (`foldl`, `foldr`); the **Prelude** is the set of
definitions every module sees without importing anything.

## Modules, imports, and the Prelude

One module per file; the file name must match the module name.

```moggi
module Demo.Shapes (Shape(..), area) where

data Shape = Circle Double | Rect Double Double

area :: Shape -> Double
area s = case s of
  Circle r -> 3.141592653589793 * r * r
  Rect w h -> w * h
```

That header means: this file is the module `Demo.Shapes`, and it offers exactly
`Shape` (with its constructors, because of `(..)`) and `area` to whoever imports
it. Omitting the header entirely means `module Main (main) where`, which is why
the smallest program is a single binding. Only `Main.main` is the entry point — a
function called `main` in another module is an ordinary function.

Imports are explicit and there is no autoloading:

```moggi
import System.IO                        -- everything the module exports
import Data.Maybe (Maybe, Just, Nothing)
import Data.List as L                   -- also visible as L.map, L.filter, …
import Data.Map qualified as M          -- only as M.lookup, not unqualified
```

Two modules are allowed to export the same name. Nothing is ambiguous until you
*use* the name unqualified; qualifying it (`L.unzip`) always works, and never
naming it is fine. A collision is reported where you used it, with that
expression's position — not at the import.

### What the Prelude already gives you

Every module implicitly imports `Prelude`, so these are always in scope: the
basic types (`Bool`, `Maybe`, `Either`, `Ordering`, `Int`, `Integer`, `Double`,
`Word`, `Char`, `String`, tuples), the classes (`Eq`, `Ord`, `Enum`, `Bounded`,
`Num`, `Integral`, `Fractional`, `Semigroup`, `Monoid`, `Functor`, `Applicative`,
`Monad`, `Foldable`, `Traversable`, `Show`), the list and fold functions, and
`IO` with `putStr`/`putStrLn`. The full list is `lib/Prelude.mog`, one export per
line; [stdlib.md](stdlib.md) shows how to search it.

### What needs an import

Things that touch the world outside the program are **not** in every module's
scope. Import them where you use them:

| You want | Import |
|---|---|
| `print`, `getLine`, `readFile`, `writeFile`, `interact`, handles | `System.IO` |
| `getArgs`, `getEnv`, `lookupEnv` | `System.Environment` |
| `throwIO`, `catch`, `bracket`, `SomeException` | `Control.Exception` |
| directories, file metadata, `removeFile`, `copyFile` | `System.Filesystem` |
| path manipulation (`takeFileName`, `(</>)`, `normalise`) | `System.Filesystem.Path` |
| `Data.Map`, `Data.JSON`, `Data.ByteString`, `Data.Time.Clock`, … | their own module |

## Values and functions

Signatures are optional; when you write one, it is checked.

```moggi
twice :: Int -> Int
twice x = x + x

add x y = x + y             -- inferred: Int -> Int -> Int

increment = add 1           -- partial application
plusOne = (+ 1)             -- a section: the same thing, written differently
```

Local bindings use `let` or `where`, and both are recursive:

```moggi
factorial n = go n 1
  where
    go 0 acc = acc
    go k acc = go (k - 1) (k * acc)
```

Branching is `if`/`then`/`else` (both branches required), `case`, and guards.
There are no loops — repetition is recursion or a fold:

```moggi
classify n
  | n < 0 = "negative"
  | n == 0 = "zero"
  | otherwise = "positive"

sumOf :: [Int] -> Int
sumOf xs = foldl (\acc x -> acc + x) 0 xs
```

`foldl` here is the `foreach` you would have written: start from `0`, add each
element. `foldl` is strict (the accumulator is evaluated as it goes), so there is
only one of it — no `foldl'` variant to remember.

**Evaluation is strict.** Nothing is computed later, nothing is cached for you,
and there are no infinite structures: `repeat 1` and friends do not exist. A
value is built exactly as far as it is used, and a recursive function has to
consume what it produces.

## Data types

A `data` declaration is a closed set of alternatives, each with fields:

```moggi
data Shape = Circle Double | Rect Double Double
  deriving (Show, Eq)

newtype UserId = UserId Int              -- one constructor, one field: no tag at runtime

data Person = Person { name :: String, age :: Int }
  deriving (Show)

data Tree a = Leaf | Node (Tree a) a (Tree a)
  deriving (Show, Eq)
```

`data` is the tagged union you would otherwise hand-write as an abstract base
class with subclasses; `newtype` is a distinct type with no runtime wrapper.
Records add named fields, and both forms can be matched:

```moggi
describe :: Shape -> String
describe s = case s of
  Circle r -> "circle " <> show r
  Rect w h -> "rect " <> show w <> "x" <> show h

nameOf (Person n _) = n            -- constructor pattern in a function head
grownUp Person { age = a }         -- a record pattern names only what it needs
  | a >= 18 = True
  | otherwise = False
```

A record field is read with `.` on the value — `person.name` — and reads chain:
`person.address.city`. The field is not a function, and the dot binds tighter
than application, so `show person.age` is `show (person.age)`. Field names
belong to their record, not to the module, so two records may both declare
`name`.

To change a field, name it in braces after the value; the fields you leave out
are kept. Construction is the one place that must name *all* fields; a pattern
may name a subset, and the rest are wildcards.

```moggi
alice = Person { name = "Alice", age = 30, address = home }

-- the updated Alice: only the field that changes is written
older = alice { age = alice.age + 1 }

-- nested, one level down
moveTo :: String -> Person -> Person
moveTo c p = p { address = p.address { city = c } }
```

Matching is exhaustive: a `case` that misses an alternative is a compile error,
not a runtime surprise. Infix constructors start with `:` (`data NonEmpty a = a :| [a]`),
and `Maybe`, `Either`, `Ordering`, lists and tuples come from the Prelude.

## Type classes

A class is an interface, plus the fact that the compiler picks the
implementation from the *type*. No `instanceof`, no container, no runtime lookup
in your code:

```moggi
class Pretty a where
  pretty :: a -> String
  prettyList :: [a] -> String
  prettyList = unwords . map pretty     -- a default, overridable

instance Pretty Shape where
  pretty s = case s of
    Circle r -> "o" <> show r
    Rect w h -> show w <> "x" <> show h
```

* A class may have superclasses (`class Eq a => Pretty a where …`), and a method
  may have a default body that instances inherit.
* **One equation per method inside an instance** — match on the argument with
  `case` instead of writing a second equation for the same method.
* Instances are global: every module sees every instance, without importing
  anything. That is why you can call `pretty` on a type another module declared.
* Instances may be constrained (`instance Pretty a => Pretty [a] where …`), which
  is how the standard library gives you behaviour for containers of anything.
* A signature may require a class: `biggest :: Ord a => [a] -> a` means "works
  for any `a` that has `Ord`". That is generics with the constraint written in
  front, not a type parameter list.
* `Show`, `Eq`, `Ord`, `Enum`, `Bounded`, `Functor`, `Foldable`, `Traversable`
  and `Generic` can be derived instead of written by hand
  ([deriving.md](deriving.md) has every strategy):

```moggi
data Color = Red | Green | Blue
  deriving (Show, Eq, Ord, Enum, Bounded)
```

## IO

An effectful function returns `IO a`. `main` is `IO ()` (or `IO a`, whose result
is discarded). `do` runs actions **in order** — a line is not deferred, it is the
next thing that happens:

```moggi
import System.IO

main :: IO ()
main = do
  putStrLn "What is your name?"
  name <- getLine
  putStrLn ("Hello, " <> name <> "!")
  let greeting = "Nice to meet you."
  putStrLn greeting
```

* `x <- action` runs the action and binds its result; a bare `action` line
  discards the result.
* `let x = …` binds a pure value inside `do` (no `in`).
* `pure v` is the action that does nothing and returns `v`.
* Pure computations are values, not actions: `putStrLn (show (twice 21))` shows
  the result of an ordinary function call.

Reading arguments and environment:

```moggi
import System.Environment (getArgs)
import System.IO
import Data.List as L

main :: IO ()
main = do
  args <- getArgs
  putStrLn (show (L.length args) <> " argument(s)")
```

```bash
moggi run report.mog -- input.csv --verbose   # everything after -- reaches getArgs
```

## Failure and stack traces

`error` aborts with a message; `undefined` is the same thing with a fixed text.
Use them for states that cannot happen, not for input validation:

```moggi
checked n
  | n < 0 = error "negative"
  | otherwise = n
```

For anything you intend to catch, throw and catch exceptions:

```moggi
import Control.Exception

safeDiv :: Int -> Int -> IO Int
safeDiv a b = catch
  (if b == 0 then throwIO (ErrorCall "divide by zero") else pure (a `div` b))
  handler

handler :: ErrorCall -> IO Int
handler e = do
  putStrLn ("caught: " <> displayException e)
  pure 0
```

The handler's type is what tells `catch` which exceptions to take, so it has to
be written down (a named handler with a signature, or an annotation on the
lambda's argument). This is the difference from `try`/`catch` in PHP, Java or
C#: an exception *value* is a normal value you can pass around, and
`fromException` is how you ask "is this one of mine?".

When an exception escapes `main`, the runtime prints a report that names *your*
code — on every backend — and exits non-zero:

```text
moggi: ErrorCall: mapped
  at Control.Exception.Base.throwIO (lib/Control/Exception/Base.mog:42:13)
  at Main.boom (Main.mog:5:8)
  at Main.main (Main.mog:13:3)
```

Frames are `at <Module.Function> (<path>:<line>:<col>)`, innermost first, and the
path is project-relative. A failure that originated in the host keeps the native
throwable underneath, as a `caused by:` section. Precision differs per backend:
PHP and .NET are column-exact, the JVM is line-exact, and inlined functions can
be missing from a trace. [diagnostics.md](diagnostics.md) has the full rules.

## Calling the host platform

Moggi runs on PHP, the JVM or .NET, and you can call that host's APIs directly —
no wrapper layer, no generated stubs. A `foreign` declaration names the target
function or method and gives it a Moggi signature:

```moggi
foreign php type DateTime "DateTime"
foreign php function newDateTime "DateTime:__con" :: String -> DateTime
foreign php function formatDate "DateTime.format" :: DateTime -> String -> String

main = putStrLn (formatDate (newDateTime "2026-09-21") "Y-m-d")
```

```text
2026-09-21
```

`Class:member` is a static member, `Class.member` an instance method with the
receiver first, `Class:__con` a constructor, and `Class:CONST` a constant.
The declaration's backend must match the one you compile for, so a portable
module keeps its host calls in per-backend modules — `System.IO` names
`System.IO.PHP`, `System.IO.JVM` and `System.IO.DotNet`.

Three rules that decide whether a signature works:

* **Match the host's width.** Java's `int` is not Moggi's `Int` (64-bit), so
  `"java.lang.Integer:parseInt"` is `String -> Int32` and needs `fromIntegral`:

  ```moggi
  foreign jvm function parseInt "java.lang.Integer:parseInt" :: String -> Int32

  answer :: Int
  answer = fromIntegral (parseInt "42") + 1
  ```

* **Only a result is converted.** `Maybe`, `Either` and lists come back from a
  host call as Moggi values; handing one *to* a host call is a type error, since
  there is nothing that unwraps it — except on PHP, where a Moggi list is the
  host's own array.

* **Host failures are not Moggi exceptions** — they arrive as `HostException`
  with the native throwable in the `caused by:` section.

That is the whole idea. [ffi.md](ffi.md) is the reference when you actually need
it: every path form, what may appear in a signature, the `Maybe`/`Either` result
conversions, PHP's `PHPValue` reification boundary (a `foreign php` declaration
only), and the limits of the mechanism.

## Behaviour worth knowing up front

These are deliberate, and they are the ones most likely to surprise you:

| Topic | Behaviour |
|---|---|
| `Int` | 64-bit signed on every backend, and it **wraps** on overflow: `maxBound + 1` is `minBound`, on php too |
| Numeric literals | an untyped literal is an `Int`; write `9223372036854775808 :: Integer` when you need arbitrary precision |
| Overflow | there is no overflow *exception*: bit operations and `bitSize` report it as `ErrorCall` |
| Shifts | a shift count at or beyond the width is clamped (`shiftL (1 :: Int) 100` is `0`); `shiftR` on a signed type is arithmetic (`shiftR minBound 100` is `-1`) |
| `String` | the host's native string, and **not** a list of `Char`: `head "abc"` is a type error. Use `Data.String` for string work, `Data.ByteString` for bytes |
| Folds | one `foldl`, and it is strict — no `foldl'`, no `seq`, no `$!` |
| Structures | no infinite or lazy structures anywhere; recursion must terminate |
| `SomeException` | opaque: you cannot pattern-match it, you ask with `fromException` |
| Backends | the same program prints the same thing on all three (a differential test enforces it); only trace *precision* differs |
| Frames | a trace names the statement being executed, with the fault's own location in the innermost frame |

The rest of the language, when you want it: [language.md](language.md) for
complete syntax, [deriving.md](deriving.md) for every deriving strategy, and
[differences-to-haskell.md](differences-to-haskell.md) if you already know
Haskell (whose module and function names the library follows).

## Next

[repl.md](repl.md) — try the pieces above one expression at a time.
