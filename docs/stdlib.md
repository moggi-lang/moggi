---
title: "Standard library"
description: The packages that ship with the compiler, what each contains, and how to find the function you need.
---
# Standard library

The library is written in Moggi and ships as ordinary sources under
`libraries/`. It is part of the compiler distribution: you never compile it
yourself, and its `LICENSE` covers it like the rest of the installation.

Seven packages are bundled:

| Package | What it provides |
|---|---|
| `base` | the Prelude, the core types and classes, `System.IO`, `System.Environment`, `System.Exit`, `System.Info`, `Control.Exception`, `Control.Monad.ST`, `Data.STRef`, `Moggi.Generics` |
| `array` | immutable and mutable boxed arrays: `Data.Array`, `Data.Array.ST`, `Data.Array.IO`, `Data.Array.MArray`, `Data.Array.IArray` |
| `containers` | `Data.Map`, `Data.Set`, `Data.IntMap`, `Data.IntSet`, `Data.Sequence`, `Data.Tree`, `Data.Graph` |
| `directory` | `System.Directory` |
| `filepath` | `System.FilePath`, `System.FilePath.Posix`, `System.FilePath.Windows` |
| `json` | `Data.JSON`, `Data.JSON.Class`, `Data.JSON.Types`, `Data.JSON.Generic`, `Data.JSON.Encoding` |
| `time` | `Data.Time`, `Data.Time.Calendar`, `Data.Time.Clock`, `Data.Time.LocalTime`, `Data.Time.Format` |

## Which packages are on the module path

Only `base` is compiled against automatically; `Prelude` is its export list. The
other six are separate library roots. In a checkout, add one with `--lib`:

```bash
moggi run app.mog --lib libraries/json/src --lib libraries/containers/src
```

In a package, declare the dependency in the package descriptor and let
`moggi build` add the roots:

```ini
[dependencies]
containers = >=0.1.0
```

A distribution ships all seven packages under `libraries/`, so the sources are
readable even when a program does not use them.

## What is in `base`

Public modules, grouped by namespace. Implementation modules (`Data.Char.PHP`,
`System.IO.JVM`, …) exist beside a facade and are not imported directly; a
facade with a `{-# BACKEND … #-}` pragma chooses the implementation per target.

| Namespace | Modules |
|---|---|
| `Prelude` | the implicit import; see `libraries/base/src/Prelude.mog` |
| `Control.*` | `Applicative`, `Monad`, `Monad.Fail`, `Monad.ST`, `Exception`, `Exception.Base` |
| `Data.*` | `Bits`, `Bifunctor`, `Bifoldable`, `Bool`, `Bounded`, `ByteString` (+ `Char8`), `Char`, `Double`, `Either`, `Enum`, `Eq`, `Foldable`, `Function`, `Functor`, `Functor.Const`, `Int`, `Integer`, `Ix`, `List`, `List.NonEmpty`, `Maybe`, `Monoid`, `Num`, `Ord`, `Proxy`, `Real`, `Semigroup`, `STRef`, `String`, `Traversable`, `Tuple`, `Version`, `Void`, `Word` |
| `Numeric.*` | `Natural` |
| `System.*` | `Environment`, `Exit`, `IO` (+ `IO.Base`, `IO.Error`, `IO.Types`), `Info` |
| `Text.*` | `Show`, `Read` |
| `Moggi.*` | `Err`, `Exception`, `Generics`, `IO.Exception`, `Internal.*` (primitives and the numeric backend) |
| `Platform.PHP` | PHP reification (`PHPValue`); importing it makes a module PHP-only ([FFI](ffi.md)) |

`Moggi.Internal.*` is implementation: primitives, the numeric types and the
`Show`/`Read` machinery. Import it only when you are working on the library.

## What is not there

Listed so nobody hunts for it: `Float` (the only floating type is `Double`),
`Data.Ratio`, `Data.Complex`, `Data.Fixed`, `Data.Text`, `Control.Monad.Reader`,
`Control.Monad.State`. `Text.Read` provides the class and `read`, not the wider
`reads`/`readMaybe` family. `System.Environment` provides lookup but not the
setters, because the hosts do not uniformly expose them. The
[differences page](differences-to-haskell.md) tracks the remaining gaps in
detail.

## Finding your way around

You do not have to know a module name in advance. `moogle` searches the names,
signatures and doc comments of the library — or of a tree of your own — and
`mogdoc` renders the same index as HTML:

```bash
moggi moogle fromMaybe                # -> Data.Maybe.fromMaybe — fromMaybe :: a -> Maybe a -> a
moggi moogle -- "Maybe a -> a"        # by type, for when you do not know the name
moggi mogdoc libraries/base/src -o out/doc   # one page per module
moggi mogdoc serve --port 8080               # local preview
```

[API documentation and search](api-docs.md) covers the query syntax, `--json`,
`--root` and `--lib`, the doc-comment markup, and the generated site.

## Reading the sources

The library is the reference for behaviour as well as names. Signatures,
instances and doc comments are next to each other, and the Prelude's export list
is the answer to "is this in scope without an import?".

```bash
sed -n 1,80p libraries/base/src/Prelude.mog
```

A library build keeps everything rather than only what a `main` reaches, which
is what you want when the compiled library is the output:

```bash
moggi compile libraries/base/src -o out/lib --unpacked
```

## Host libraries are a different axis

`--lib` names **Moggi** library roots. A Composer, Maven or NuGet package is a
host library, reached through `foreign` declarations and named with
`--lib-composer`, `--lib-maven` or `--lib-nuget`
([calling the host](ffi.md), [host libraries](host-packages.md)). A host tree
passed as `--lib` is never searched for modules.
