# The Moggi standard library

The standard library is written in Moggi and lives here, beside the compiler. It
is part of the compiler's distribution: every program is compiled against it
automatically, so you never build the library yourself — you `import` from it.

```moggi
import Data.Map as M

main = do
  let counts = M.fromList [("a", 1), ("b", 2)]
  putStrLn (show (M.size counts))
  putStrLn (show (M.lookup "a" counts))
```

These are the library's own sources, shipped inside every installation as `lib/`
next to `bin/` and covered by the compiler's `LICENSE`. Read them whenever a
signature is not obvious; nothing here is hidden behind a build step.

## What is where

- `Prelude.mog` is the re-export list — the names every module sees without an
  `import`. The public modules re-export from it, not the other way round.
- `Data.*`, `Control.*`, `System.*`, `Text.*` and `Numeric.*` are the names a
  program is meant to use: `Data.List`, `Data.Maybe`, `Data.Map`, `System.IO`,
  `Control.Monad`, … — including the numeric types and classes (`Data.Int`,
  `Data.Integer`, `Data.Double`, `Data.Num`, `Data.Real`) and `Text.Show` /
  `Text.Read`.
- `Moggi.Internal.*` is reserved for the compiler/primitive boundary:
  `Moggi.Internal.Prim` and `Moggi.Internal.IO` are compiler-synthesized and are
  the only `Moggi.Internal` modules. Nothing else belongs there.
- `Data.Foo.PHP`, `Data.Foo.JVM` and `Data.Foo.DotNet` are one module's
  per-backend implementations. A module that has them also has a facade that
  picks the right one, and the facade is what you import — never the backend.

## Reading further

`docs.moggi-lang.org/stdlib.html` is the guided tour: the modules, the
per-backend split and the conventions in one place. The library is published as
the `base` package, so each release documents itself at
`registry.moggi-lang.org/#/pkg/base` — the modules of that very release, beside
the release that published them.
