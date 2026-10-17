# The `array` Package

Mutable and immutable boxed arrays, with overloaded interfaces for both.

* `Data.Array` — immutable boxed arrays (`Array`), as specified in the
  Haskell 2010 Language Report.
* `Data.Array.IArray` — the overloaded immutable interface (`IArray`).
* `Data.Array.MArray` — the overloaded mutable interface (`MArray`).
* `Data.Array.ST` — mutable boxed arrays in `ST` (`STArray`).
* `Data.Array.IO` — mutable boxed arrays in `IO` (`IOArray`).
* `Data.Array.Unsafe` — unsafe freeze/thaw between mutable and immutable arrays.

Indexes are `Data.Ix` instances, so anything from `Int` to tuples can index
an array. The bounds are part of the array: an array remembers the `(l, u)`
pair it was created with.

This library is inspired by Haskell's [array package](https://hackage.haskell.org/package/array).

## What is not here

Unboxed arrays (`UArray`, `STUArray`, `IOUArray`), `Storable` arrays, and the
`hGetArray`/`hPutArray` operations are not defined: Moggi has no unboxed
values and no `Foreign.Ptr`/`Storable` machinery for them to be built on.
The `.Safe`/`.Internals` module variants are not defined either: Moggi has
no Safe Haskell, so the main modules are the whole interface.

Two boxed pieces are missing deliberately, not by oversight:

* `Eq (IOArray i e)`: Moggi's IO confinement forbids `IOArray` in a pure
  signature, and `(==)` is pure. `STArray` does have pointer equality
  (`sameSTArray#`, mirroring `sameMutableArray#`); for `IOArray`, compare
  identities inside `IO` with `sameIOArray#` (`Moggi.Internal.Prim`).
* `Read (Array i e)`: Moggi's `Read` class (`Text.Read`) is a
  single-method `String -> a` without instances for pairs and lists, so
  `read` could not name the bounds or the associations. `Show` writes the
  same `array (l,u) [(i,e), ...]` shape as Haskell's `showsIArray`, ready
  for the instance once base grows those.
