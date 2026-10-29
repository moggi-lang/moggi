---
title: "Polymorphism and inference"
description: How Moggi decides types, when to write a signature, and how the monomorphism restriction changes a binding.
---
# Polymorphism and inference

A **polymorphic** function works at more than one type. Moggi infers that
generality from the function's body: you write `id x = x` and the compiler gives
`id` the type `a -> a`, where `a` stands for any type. Nothing about `id` changes
between uses — there is one compiled function, and it is called with different
values.

```moggi
id' :: a -> a
id' x = x

const' :: a -> b -> a
const' x _ = x

twice :: (a -> a) -> a -> a
twice f x = f (f x)

main :: IO ()
main = do
  putStrLn (show (twice (\n -> n + 1) (1 :: Int)))
  putStrLn (twice (\s -> s <> "!") "hi")
  putStrLn (show (const' (1 :: Int) "unused"))
```

```text
3
hi!!
1
```

The type variables in a signature are implicitly quantified: `a -> a` means
"for every type `a`". A type argument is never written at a call site either;
the compiler instantiates the signature from the context.

Because `a` can be anything, the body of `id'` cannot inspect it. The only
operations available on a value of type `a` are the ones the signature's
constraints name. That restriction is what makes a polymorphic function behave
identically at every type.

## Signatures are optional and checked

A definition without a signature is inferred. With a signature, the body is
checked against it — a mismatch is an error, never a silent reinterpretation.

Write signatures for exported functions, for recursive functions, and for
anything whose type you had to think about. They document the interface, they
catch a change that would otherwise generalise quietly, and they make error
messages point at the signature instead of at a distant use.

## Inference works from use sites

The compiler unifies the types of a definition's body with the types its uses
demand. Two consequences are worth knowing:

* **A function without a signature is generalised from its body.**
  `add x y = x + y` becomes `Num a => a -> a -> a`, so one definition serves
  `Int` and `Double`; a use does not narrow it. Defaulting applies only where an
  ambiguous value has to be shown or stored without a type.
* **A recursive function is inferred from its body and its recursive uses.**
  There is less outside evidence to go on, so the result can be more general
  than you intended. Add the signature when the function is recursive.

A use that cannot be satisfied is reported at the definition that introduced the
constraint:

```moggi
n = 1

b :: String
b = n
```

```text
Main.mog:1:1: type error: unresolved type class constraint: `Num String`

  1 | n = 1
    | ^
```

## Local bindings are polymorphic

A `let` or `where` binding with parameters is generalised like a top-level
function, so one helper can be used at several types:

```moggi
pair = let f x = x in (f 1, f True)

main :: IO ()
main = putStrLn (show (fst pair))
```

```text
1
```

`f` is used at `Int` and at `Bool`; it is one definition, instantiated twice.

## Advanced

The rest of this page is about the corners: how a value binding differs from a
function, what defaulting picks when nothing else does, and how to pin a type.
On a first reading, take away that signatures are optional but checked, and that
a function without one is generalised from its body.

## Value bindings and the monomorphism restriction

A binding with no parameters and no signature — `count = 1 + 1`,
`idAlias = \x -> x`, `empty = []` — is a **pattern binding**; only a binding
with parameters is a function. Moggi applies a *monomorphism restriction* to
pattern bindings: unlike a constrained function, such a binding is not given the
dictionary parameters of its constraints, so give it a signature when its type
matters.

```moggi
count = 1 + 1

asInt :: Int
asInt = count

main :: IO ()
main = putStrLn (show asInt)
```

```text
2
```

What you can observe:

* **An ambiguous numeric value binding takes the default type.**
  `putStrLn (show count)` prints `2`, the `Int` default, although `count`'s body
  could also be `Double`.
* **A missing instance is reported at the binding, not the use.** Add
  `asString :: String` with `asString = count`, and the error is
  `unresolved type class constraint: `Num String`` at `count`.
* **A pattern binding cannot call a dictionary-taking member while the
  recursive group is undecided.** It is refused by name:

  ```text
  type error: `b` takes dictionaries, and the pattern binding `a` calls it from a recursive group that has not decided them yet; give the caller a signature
  ```

* **A binding with parameters is unaffected.** `add x y = x + y` is generalised
  and works at `Int` and `Double` alike.

Do not use a pattern binding to share one polymorphic value across several
types: write a function (`idAlias x = x`) or put a signature on the value.

## Defaulting

A numeric literal is polymorphic until a use pins it. When nothing does, the
compiler picks a default: `Int` first, then `Double` for a fractional operation.

```moggi
main :: IO ()
main = do
  putStrLn (show (1 + 2))        -- Int
  putStrLn (show (1 / 2))        -- Double
```

```text
3
0.5
```

`show` renders each expression at its defaulted type, and `putStrLn` adds the
newline. Adding a signature removes the default: `putStrLn (show (1 :: Integer))`
renders one as an arbitrary-precision value. (`print`, from `System.IO`, is
`Show a => a -> IO ()` and writes without a newline.)

## Ascribing a type

`(expression :: Type)` fixes an expression's type at that point:

```moggi
main :: IO ()
main = putStrLn (show ((1 :: Int) + 2))
```

```text
3
```

Ascription is how you settle a literal, a `mempty`, or a value read from a
polymorphic function. It is also the tool for an ambiguous constraint: the error
says *which* class has no instance, and the fix is usually a signature that
names the type.

## Limits

* **No higher-rank signatures.** A signature cannot say "this argument is
  itself polymorphic". A function argument's type is fixed at the call site,
  so passing `id` where `a -> a` is expected works only because `id` is
  instantiated there; a *requirement* that the argument work for every `a`
  cannot be written. There are no scoped type variables either.

* **No explicit type application.** There is no syntax to choose a type
  argument; the compiler infers it, and an ambiguity is resolved with an
  ascription.

* **No as-patterns.** `x@(Just n)` is not supported; see
  [Data types](data-types.md#what-is-not-available).

* **No type-level computation in a signature** beyond the type constructors and
  class-associated families that exist ([Classes](classes.md#associated-type-families)).

## Where next

* [Type classes](classes.md) — the constraints that make a polymorphic function
  useful.
* [Kinds](kinds.md) — what `a` itself may be, once types take parameters.
* [IO and effects](../effects/io.md) — why `main` has type `IO ()`.
