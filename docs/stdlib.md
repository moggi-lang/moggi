# Standard library

The standard library is written in Moggi and lives in `lib/`. It is part of the
compiler: every program is compiled against it automatically, so you never
compile the library yourself — you just `import` from it. In an installation that
is the `lib/` directory next to `bin/`, shipped as ordinary sources: read them
whenever a signature is not obvious, they are covered by the same `LICENSE`.

```moggi
import Data.Map as M
import System.Filesystem (removeFile)
```

Module names follow the names you would expect from the language Moggi borrows
its shape from (`Data.List`, `Data.Maybe`, `Data.Map`, `System.IO`,
`Control.Monad`, …), and so do the names each module exports. Two namespaces are
worth knowing about:

* `Moggi.Internal.*` is where implementation lives — primitives
  (`Moggi.Internal.Prim`, `Moggi.Internal.IO`), the numeric types, the
  `Show`/`Read` machinery. Public modules re-export from it, and the Prelude is
  the re-export list. Import it only if you are working on the library.
* `Data.Foo.PHP` / `Data.Foo.JVM` / `Data.Foo.DotNet` are the per-backend
  implementations of a module that has one; a facade module declares which one
  to use, and you import the facade, never the backend module.
* `Platform.PHP` is the exception to that split — it is not portable code behind
  a facade but the host boundary itself, the PHP reification of an untyped value
  into an inspectable `PHPValue`. Importing it makes a module PHP-only; the
  guide is [ffi.md](ffi.md#6-php-the-special-case-platformphp).

The Prelude (implicitly imported everywhere) carries the basic types, classes
and functions — see [tour.md](tour.md#modules-imports-and-the-prelude) for what
is in it and what needs an explicit import.

## What is in it today

Grouped by namespace, these are the modules a program can import. The list is the
honest one: a name appears here because `lib/` has it, and the API of each is
Haskell `base`'s API (porting rules and the few deliberate differences are in
[differences-to-haskell.md](differences-to-haskell.md)).

| Namespace | Modules |
|---|---|
| `Prelude` | implicitly imported everywhere; the re-export list |
| `Control.*` | `Applicative`, `Monad`, `Monad.Fail`, `Exception` (+ `Exception.Base`) |
| `Data.*` | `Bits`, `Bifunctor`, `Bool`, `Bounded`, `ByteString` (+ `Char8`), `Char`, `Either`, `Enum`, `Eq`, `Foldable`, `Function`, `Functor`, `Int`, `List` (+ `NonEmpty`), `Map`, `Maybe`, `Monoid`, `Ord`, `Proxy`, `Semigroup`, `Set`, `String`, `Traversable`, `Tuple`, `Void`, `Word` |
| `Data.JSON.*` | `JSON` (the API), `Class`, `Encoding`, `Generic`, `GenericEncoding`, `Types` |
| `Data.Time.*` | `Clock` |
| `Numeric.*` | `Natural` |
| `System.*` | `Environment`, `Exit`, `Filesystem` (+ `Filesystem.Path`), `IO` (+ `IO.Base`, `IO.Error`, `IO.Types`) |
| `Text.*` | `Show`, `Read` |
| `Moggi.*` | the compiler-facing side: `Internal.*` (the primitives, the numeric types, `Show`/`Read` machinery), `Exception`, `Err`, `Generics`, `IO.Exception` |

Not there yet, and listed so nobody hunts for them: `Data.Ratio`,
`Data.Complex`, `Data.Fixed`, `Data.Functor.Identity`, `Data.Functor.Const`, and
`Control.Monad.Reader`/`State`/`ST`. Classes that exist only in part (`RealFrac`,
`Floating`, `RealFloat`, most `Read` instances, `CallStack`) are listed in §7 of
[differences-to-haskell.md](differences-to-haskell.md), which is the one place
that tracks what is still missing.

## Finding your way around it

You do not have to know a module name in advance. Two tools read the same index,
built from the `-- |` doc comments in the sources:

```bash
moggi moogle fromMaybe                # search by name
moggi moogle -- "a -> a"              # search by type (`--` so `-` is not a flag)
moggi moogle --json "Maybe a"         # machine-readable, for your own tooling

moggi mogdoc lib -o out/doc           # static HTML for every module
moggi mogdoc serve --port 8080        # local preview while you read
```

Open `out/doc/index.html` and the pages are browsable: each module lists its
declarations with signatures and doc comments, a facade module's page links to
its backend implementations, and the internal modules are indexed too. `moogle`
is the terminal version of the same search, and it answers the question you
type — "what is it called", "what has this type", "where is `Foldable`".

Searching by type is the one worth remembering, because it is how you find the
function you want without knowing its name:

```bash
moggi moogle -- "Maybe a -> a"        # -> fromMaybe
moggi moogle -- "[a] -> Int"          # -> length
```

Both commands take `--lib` for extra library roots and `--root` to search a
directory of your own code instead of the standard library.

## Building it on its own

```bash
moggi compile lib -o out/lib      # a library build: nothing is stripped
```

An executable build (`moggi compile app`) keeps only what `main` reaches. A
library build keeps everything, which is what you want when the compiled library
is an output in its own right: `--lib-php out/lib` then lets a later single-file
compile reuse that precompiled tree.
