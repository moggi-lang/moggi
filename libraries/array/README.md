# The `array` Package

Mutable and immutable boxed arrays, with overloaded interfaces for both.

* `Data.Array` — immutable boxed arrays (`Array`)
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
