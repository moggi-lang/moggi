# http-types

Types and functions to describe and handle HTTP concepts, including methods,
headers, query strings, paths and HTTP versions.

The package provides `Network.HTTP.Types` plus one module per concept:
`Network.HTTP.Types.Method`, `.Version`, `.Status`, `.Header`, `.URI` and
`.QueryLike`.

This library is inspired by Haskell's
[http-types package](https://hackage.haskell.org/package/http-types).

Because Moggi has one text type (`String`) and no lazy `ByteString`, the
`Text`-typed query functions work on `String`, and the lazy instances and
builder drivers are replaced by their strict equivalents.

## Port notes

The API follows the upstream modules name for name, with these exceptions:

- The `StatusCode` pattern synonym is not provided (Moggi has no pattern
  synonyms); match on the `Status` constructor or use `statusCode` and
  `statusMessage`. The same applies to the `Http09`/`Http10`/`Http11`/
  `Http20`/`Http30` synonyms: use the `http09`…`http30` values.
- `renderStatusCodeToPtr` and `renderFullStatusToPtr` are absent: base has no
  `Foreign.Ptr`. `renderStatusCode` and `renderFullStatus` produce the same
  bytes.
- `StdMethod` derives `Read`, `Show`, `Eq`, `Ord`, `Enum` and `Bounded`, but
  not `Ix`, `Generic` or `Data`, which base does not provide.
- `QueryLike` has one instance per source type: `ByteString` and `String`
  replace the upstream `ByteString`, lazy `ByteString`, `Text` and `[Char]`
  instances.

## Tests

`tests/Suite.mog` ports the upstream specs (`HeaderSpec`, `MethodSpec`,
`StatusSpec`, `URISpec`, `VersionSpec`) case for case: every method, status and
header constant, the status category checks, the render/parse cases including
the three-digit padding and the `1337`-style wide codes, byte-range
satisfiability, the URL-encoding goldens for all 256 bytes, path and query
round trips, and `extractPath`.

QuickCheck properties are replaced by fixed samples (all standard methods, a
representative set of byte ranges, the 256-byte goldens), and the upstream
pattern-synonym matches (`StatusCode`, `Http09`…`Http30`) become constructor
and value checks. One spec case pins a corner of the grammar: `bytes=-0` is not
a satisfiable suffix, so `parseByteRanges` returns `Nothing` for it.
