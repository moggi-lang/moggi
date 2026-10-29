---
title: "Type-level data (DataKinds)"
description: Promoting constructors, numbers and strings to types so a phantom parameter can carry a tag.
---
# Type-level data (DataKinds)

**Advanced page.** Read [kinds](kinds.md) first; this page uses kind signatures
throughout.

Moggi lets a data constructor appear in a type, written with a leading quote:
`'Red`. The constructor is **promoted** — the same name now exists at the type
level, where it can be used as a parameter's kind. Together with the built-in
kinds `Nat` and `Symbol`, this lets a type carry a compile-time tag without
storing anything at run time.

```moggi
data Color = Red | Green | Blue

data Tagged (c :: Color) = Tagged

red :: Tagged 'Red
red = Tagged

blue :: Tagged 'Blue
blue = Tagged

name :: Tagged c -> String
name Tagged = "tagged"

main :: IO ()
main = do
  putStrLn (name red)
  putStrLn (name blue)
```

```text
tagged
tagged
```

`Tagged` has no field; the parameter exists only in the type. `'Red` and `'Blue`
are distinct types, so `red` and `blue` have different types even though both
values are the same constructor.

The kind annotation is what makes the parameter a tag: `data Tagged (c :: Color)`
declares `c` to be of kind `Color`. Without it, `c` would be an ordinary type
parameter, and `'Red` would not fit.

Once the parameter has a kind, the compiler holds callers to it. An ordinary
type in that position is a kind error, not a type mismatch:

```moggi
data Color = Red | Blue
data OnlyColor (c :: Color) = OnlyColor

bad :: OnlyColor Int
bad = OnlyColor
```

```text
type error: kind mismatch applying `OnlyColor`: expected `Color`, got `Type`
```

The message names the constructor and both kinds, so the fix — pass `'Red`, not
`Int` — is usually obvious from it.

## Numbers: `Nat`

`Nat` is the kind of type-level natural numbers, written as literals:

```moggi
data Port (p :: Nat) = Port

http :: Port 80
http = Port

api :: Port 8080
api = Port

describe :: Port p -> String
describe Port = "a port"

main :: IO ()
main = do
  putStrLn (describe http)
  putStrLn (describe api)
```

```text
a port
a port
```

Two numbers that differ are different types, so a function that expects one port
cannot be given another:

```text
Main.mog:4:12: type error: could not unify `2` with `3`
```

Leading zeros do not make a new type: `Port 080` and `Port 80` are the same type.
The literal is not limited to machine width either — `Port 18446744073709551616`
is accepted, and it is a different type from `Port 18446744073709551617` —
because the number exists only in the type. The REPL's `:kind` does not render
`Nat` or promoted kinds reliably (it prints placeholders such as `k0`), so a
type annotation in a program is the dependable check.

## Strings: `Symbol`

`Symbol` is the kind of type-level strings, written as string literals:

```moggi
data Label (s :: Symbol) = Label

greeting :: Label "hello"
greeting = Label

other :: Label "goodbye"
other = Label

nameOf :: Label s -> String
nameOf Label = "a label"

main :: IO ()
main = do
  putStrLn (nameOf greeting)
  putStrLn (nameOf other)
```

```text
a label
a label
```

Again the two labels are distinct types. The library's generic metadata uses
`Symbol` for constructor and field names, which is how `conName` and `selName`
can answer with a compile-time string
([Generic programming](generics.md#metadata)).

## What it is for

Type-level tags are useful when two values have the same representation but must
not be mixed:

* **Units and sizes.** `data Length (u :: Unit) = Length Double` keeps metres and
  seconds apart without a wrapper field.
* **Protocol states.** `data Connection (s :: State) = Connection` makes "open"
  and "closed" different types, so a function that needs an open connection
  cannot be called with a closed one.
* **Named positions.** `Label "name"` and `Label "email"` distinguish fields of
  the same type in a schema.

In every case the tag exists only in the type: no field, no tag at run time, no
cost.

## Limits

* **No type-level computation.** There is no addition on `Nat`, no comparison,
  and no type family over indices. `Port (n + 1)` is not a type you can write.
  Without functions on indices, `Nat` is a tag, not a numeric type system.
* **No GADT refinement.** A constructor cannot inspect its index and produce a
  different type; `data Expr a where …` does not parse
  ([Data types](data-types.md#what-is-not-available)). Type-level tags constrain
  what may be passed; they do not let a function learn something from a value's
  type at run time.
* **No reflection, beyond one class.** A type-level `Symbol` can be turned into a
  `String` with `KnownSymbol`/`symbolVal` from `Moggi.Generics`; there is no
  equivalent for `Nat` or promoted constructors.
* **Only data constructors and literals are promoted.** Functions and type
  constructors cannot appear at the type level.
* **A promoted constructor is not a value.** `'Red` is a type; the value is
  `Red`.
* **No pattern matching on indices.** A function over `Tagged c` is polymorphic
  in `c` unless its signature names one tag.

## Where next

* [Kinds](kinds.md) — the system these annotations live in.
* [Generic programming](generics.md) — `Symbol` metadata in practice.
* [Deriving](../deriving.md) — instances for tagged types.
