---
title: "Differences to Haskell / GHC `base`"
---

# Differences to Haskell / GHC `base`

Moggi looks like Haskell and ports the API of GHC's `base` library, but it is a
strict language that compiles to three hosts (PHP, the JVM, .NET) — not to
native code through the C ABI. This page tells you, as a Haskell programmer,
what will surprise you. Everything not mentioned here is meant to behave like
`base`; if you find a divergence that is not listed, that is a bug — please
report it.

New to Moggi? Start with the [tour](tour.md) instead; this page assumes you
already know Haskell. The language itself is described in the
[language reference](language.md), and host calls in the [FFI guide](ffi.md).

## Evaluation is strict

This is the one big difference, and most of the rest follows from it.
Arguments are evaluated before a call; nothing is deferred to first use — not
function arguments, not record fields, not constructor fields.

- There are no thunks, no `seq`, no `$!`, and no bang patterns. They would have
  no work to do.
- There are no infinite or self-referential structures. `ones = 1 : ones` cannot
  be expressed, and for the same reason `iterate`, `repeat`, and `cycle` are not
  provided — on lists or on `NonEmpty`. In a strict language each of them would
  diverge while looking like the value `base` promises, so they are left out on
  purpose rather than shipped broken.
- Folds come in one strict flavour each. `fold` is the strict left fold; there
  is no `foldl'`, no lazy `foldl`, and no `Data.Map.Strict` beside a lazy
  `Data.Map` — the containers package's `Data.Map` is strict, full stop.
- Partial application is still a value you can pass around; it just holds
  already-evaluated arguments instead of a suspended computation.

## Names that differ

A few names are deliberately different, because Moggi is not a Haskell
source-compatibility target and keeps one spelling per operation:

- The `Functor` method is `map`, and it is also the only mapping function —
  there is no `fmap`, and `Data.List` declares no `map` of its own. `(<$>)`,
  `(<$)`, `(<$)`'s companions, and `(<&>)` all exist with their usual meanings.
- `Monad` has no `return`. Use `pure`, which `base` itself says `return` should
  equal. `Monoid` likewise has no `mappend`: `(<>)` is the only spelling, as
  `base` recommends.
- `Alternative`'s `some` and `many` are class methods with defaults (as in
  `base`), so an instance with a faster repetition strategy can override them.

## Records

Field access uses projection: `person.name`, which binds tighter than function
application, as `.` does in the host languages. A record declaration generates
no selector function, so two records in one module can freely share field names
— the receiver's type selects the field, and there is no `name person` spelling.

Record update (`p { age = 31 }`, nested form included) works on record types
with a single constructor. A multi-constructor type cannot be updated, because
which fields the value has is only known at run time; matching on the
constructor explicitly is the alternative (see
[language.md § Records](language.md#records)).

## Strings and characters

`String` is a host-native string (UTF-8/UTF-16 depending on the backend), not
`[Char]`. `Char` is a Unicode scalar value. Consequences:

- There is `Data.String` but no `Data.Text`. To move between the two worlds,
  `Data.String` provides `pack`, `unpack`, and `singleton`.
- `Data.String` also provides `length`, `take`, `drop`, `isPrefixOf`, and
  `isSuffixOf` for strings, and path manipulation (`System.Filesystem.Path`,
  `System.FilePath`) works directly on those strings: `FilePath` is
  `String`, not `[Char]`. There is no `OsPath`/`OsString` layer and no
  encoding step — pure path functions only ever inspect ASCII (separators,
  drive letters, extension markers) and pass everything else through
  untouched, and host calls take the string as-is.
- A module importing both `Data.String` and
  `Data.List` must disambiguate those names with a `hiding` list.

## Numbers

- `Int` is 64-bit signed on every backend (a 64-bit PHP build is required).
  `Integer` is arbitrary precision, as in `base`.
- The only floating-point type is `Double`. There is no `Float`, and no `Real`,
  `RealFrac`, or `RealFloat` classes; if you need a floating-point operation,
  it is a plain `Double` function.
- The numeric classes live in public `Data.*` modules: `Num` in `Data.Num`,
  `Integral`, `Fractional`, and `Floating` in `Data.Real`, with the helpers
  `subtract`, `even`, `odd`, `gcd`, `lcm`, `(^)`, and `(^^)`.
- An ambiguous numeric variable defaults to `Int` first, then `Double` —
  `default (Int, Double)` where `base` has `(Integer, Double)`. An unannotated
  computation that overflows `Int` wraps instead of promoting, so annotate
  `Integer` where the range matters.

## The Prelude is smaller

The `Prelude` re-exports the pure core plus `IO`, `putStr`, and `putStrLn`.
Everything that touches the outside world needs a named import: handles and
reading (`print`, `getChar`, `getLine`, `readFile`, `writeFile`, `appendFile`,
…) come from `import System.IO`, paths and directories from
`import System.Filesystem`. Nothing is missing from the library — only from the
implicit scope.

## Exceptions

Only synchronous exceptions exist: `throw`, `throwIO`, `catch`, `bracket`,
`finally`, and friends, but no async exceptions, no `mask`, and no
`ExceptionContext`.

- The `Exception` superclass is `Show` only — there is no `Typeable`, hence no
  `Data.Dynamic` and no `cast`. Exceptions are matched by a stable string tag
  naming the type. Writing an instance still requires `toException` and
  `fromException` bodies where `base` needs none (the compiler cannot
  synthesize the tag yet); take care to keep the tag equal to the type name.
- There is no `HasCallStack` mechanism and no `CallStack`/`SrcLoc` types yet,
  so `throw` and `error` carry no call stack. Uncaught reports still print
  backend frames; the report header reads `moggi: <Tag>: <display>`.
- A failure of the host itself — a missing method, a wrong arity — surfaces as
  `HostException backend nativeType message`, which is catchable like any other
  exception. `System.IO` translates file-operation failures into the
  `IOException` its callers expect.

Two diagnostics choices differ from GHC on purpose: an instance that satisfies
none of its class's `{-# MINIMAL … #-}` alternatives is a compile error (GHC
warns), because Moggi has no warning channel and a silent default here would
diverge at run time; and `ArithException`, shift clamping, and `bitSize` on
`Integer` behave exactly as documented under `Data.Bits`.

## Calling the host

`base` reaches the host through the C FFI; Moggi declares host members
directly with `foreign` declarations (see the [FFI guide](ffi.md)):

- A declaration belongs to exactly one backend, chosen at compile time. Shared
  code keeps the portable surface in a facade module and puts each backend's
  declarations in an implementation module behind it
  (`System.IO` → `System.IO.PHP` / `System.IO.JVM` / `System.IO.DotNet`).
- The compiler trusts the declared path and types; mistakes surface as host
  failures, wrapped in `HostException`, rather than compile errors.
- There is no `Foreign.*` layer: no pointers, no `Storable`, no manual
  marshalling. In result position `Maybe`, `Either`, lists, and `Handle` are
  converted from the host value; in argument position Moggi's own
  representations are refused rather than passed to the host.
- One PHP-only special case: PHP's `mixed` is not a class, so a `foreign php`
  declaration may name it as a type, and `Platform.PHP`'s `PHPValue` reifies
  such a value into a matchable ADT. The JVM and .NET need no equivalent
  (`java.lang.Object`, `System.Object`).

## Where things live

`base` 4.22 splits its definitions across a `ghc-internal` package behind
re-export shims; Moggi does not mirror that tree. Implementations live in the
module you import: numbers in `Data.Int`/`Data.Word`/`Data.Integer`/
`Data.Double`/`Data.Num`/`Data.Real`, `Show` and `Read` directly in
`Text.Show`/`Text.Read`. `Data.Tuple` additionally carries the tuple types
`base` puts in `GHC.Tuple` (`Solo`, `Tuple0`–`Tuple64`, `Unit`), so there is no
user-facing `GHC.*` layer at all.

Because there is one file per module and no build file, each library lives
where you import it — there is no separate `.cabal` package tree to learn.

## Not implemented yet

Honest gaps — listed here so nobody mistakes them for design choices:

- `Float`, `Data.Ratio`, `Data.Complex`, `Data.Fixed`, and `Numeric`.
- Most of `Text.Read`: the class and `read` exist, but not `reads`,
  `readMaybe`, `readEither`, or `lex`, and only a handful of instances.
- `CallStack`/`SrcLoc`, and exception tags synthesized by the compiler.
- `Control.Applicative`'s `ZipList` (its `pure` needs an infinite list, which
  strict Moggi cannot build) and `WrappedArrow` (needs an `Arrow` class, which
  does not exist yet). `WrappedMonad` lives in `Control.Monad` and `Const` in
  `Data.Functor.Const`.
- `Control.Monad.Reader`, `Control.Monad.State`, and `Control.Monad.ST`.
- `Data.Char`'s `lexLitChar` and `readLitChar` (the rest of `Data.Char`,
  including `GeneralCategory` and `generalCategory`, is implemented).
- `System.Environment`'s `getEnvironment`, `setEnv`, `unsetEnv`, `withArgs`,
  and `withProgName` (argument and variable lookup is implemented). The setters
  and `withArgs`/`withProgName` need process-global mutable state the hosts do
  not uniformly provide: the JVM has no `setenv` at all, and no backend
  exposes argv for overriding.
- `System.IO` buffering, text encodings, and seeking. Handles are sequential
  streams on every host (`BufferedReader`/`StreamReader` under the hood), so
  there is nothing seekable to expose without a second handle type; buffering
  stays host-managed (`hFlush` covers the case that matters); and strings are
  host-native, so there is no encoding to set. `openTempFile` and
  `openBinaryTempFile` (an alias — Moggi has no text/binary mode split) are
  implemented.

## What is *not* different

These look like deviations when diffing against `base`, and are not:

- `ArithException`'s constructors and `Show` strings, `ErrorCall`'s `Show`, and
  `bitSize` failing on `Integer`.
- Operator fixities and precedence in `Data.Bits`, `Data.Function`, and
  `Data.List`.
- `Data.Bits`'s class split (`Bits`, `FiniteBits`) and the two's-complement
  behaviour for negative `Integer` values.
- `Int` being 64-bit — GHC on a 64-bit platform is the same.
