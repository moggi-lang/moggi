# case-insensitive

The `Data.CaseInsensitive` module provides the `CI` type constructor, which can
be parameterised by a string-like type such as `String` or `ByteString`.
Comparisons of values of the resulting type are insensitive to case.

This library is inspired by Haskell's
[case-insensitive package](https://hackage.haskell.org/package/case-insensitive).

Because Moggi has one text type (`String`), the `Data.Text.Text` instances are
the `String` ones, and the lazy `ByteString`/`Text` and `ShortByteString`
instances are not provided.
