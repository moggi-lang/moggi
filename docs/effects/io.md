---
title: "IO and effects"
description: How effects are typed and ordered, what main must be, and how ST gives you local mutation without IO.
---
# IO and effects

Moggi separates computation from effect. A pure function returns a value; a
function that talks to the world returns `IO a`, where `a` is what it produces.
An `IO a` value is a description of effects — building one does nothing. `main`
is the description the runtime executes.

```moggi
main :: IO ()
main = do
  putStr "a"
  putStrLn "b"
  putStrLn (show (1 :: Int))
```

```text
ab
1
```

The lines run in the order written, and `putStr "a"` really does write before
`putStrLn "b"`. There is no deferred evaluation to reorder them: evaluation is
strict, and `do` sequences actions in bind order.

`print x` is `putStr (show x)`: it adds **no** trailing newline. Write
`putStrLn (show x)` when you want a line.

## `do` notation

`do` is sugar for `>>=` and `>>`, with two extra forms:

```moggi
import System.Environment (getArgs)

main :: IO ()
main = do
  args <- getArgs                       -- run it and bind the result
  let count = length args               -- a pure binding, no `in`
  putStrLn (show count <> " argument(s)")  -- run it, discard the result
  pure ()                                -- an action that does nothing
```

* `x <- action` runs `action` and binds its result.
* A bare `action` runs it and discards the result.
* `let name = expression` binds a pure value for the rest of the block; there is
  no `in`.
* `pure value` is the action with no effects that produces `value`.

The last statement is the block's result, so a `do` block of type `IO ()`
normally ends with `pure ()` or with an action that returns `()`.

`do` works for any type with a `Monad` instance, not only `IO`
([Classes](../types/classes.md)); for `IO` the sequencing is the effect order.

## `main` and the entry point

`main` is the entry point of the module compiled as the program. It must be an
`IO` action for the program to have an entry:

```moggi
main :: IO Int
main = pure 42
```

This compiles and runs; the `42` is discarded. `main :: IO a` is accepted for any
`a`, so a function can return a value nobody reads.

A `main` of a non-`IO` type is not rejected, but it is not an entry point
either — `moggi run` reports that there is nothing to run:

```text
error: no entry `main` found to run
```

If you see that message with a `main` in the file, its type is the reason.

## The purity boundary

`IO` may appear only as the result of a function that performs effects. It
cannot be stored in a data type, a record field or a tuple:

```moggi
data X = X (IO ())
```

```text
Main.mog:1:13: type error: IO values are forbidden outside an IO-returning function
```

The rule is what keeps "pure" meaningful: a function whose signature has no
`IO` cannot perform effects, directly or by unpacking one. There is no
`unsafePerformIO`, no way to run an action from pure code, and no `IO` value to
pattern-match on.

In a foreign declaration, `IO` may appear only outermost in the result
([FFI](../ffi.md)).

**Advanced from here.** Local mutation is a separate tool from `IO`, needed by
some algorithms and avoidable in most programs. Skip to the next page if your
program only reads input, writes output and returns values.

## Local mutation with `ST`

Some algorithms want a mutable cell or array without touching the world. `ST` is
a thread of local mutation that is run purely: `runST` turns an `ST s a` into an
`a`, and the compiler refuses any thread whose cell could escape.

```moggi
import Control.Monad.ST
import Data.STRef

answer :: ST s Int
answer = do
  ref <- newSTRef 41
  modifySTRef ref (+ 1)
  readSTRef ref

main :: IO ()
main = putStrLn (show (runST answer))
```

```text
42
```

`STRef s a` is a cell in thread `s`. The thread variable keeps one run's cells
apart from another's, and `runST` requires that `s` does not appear in the
result. A cell that would escape is rejected:

```text
Main.mog:6:1: type error: ST values are forbidden outside an ST-returning function
```

For a loop, pass the cell to a recursive helper. A top-level function keeps the
helper's signature and guards out of the `do` block:

```moggi
import Control.Monad.ST
import Data.STRef

total :: Int -> STRef s Int -> Int -> ST s Int
total n ref i
  | i > n = readSTRef ref
  | otherwise = do
      x <- readSTRef ref
      writeSTRef ref (x + i)
      total n ref (i + 1)

sumTo :: Int -> Int
sumTo n = runST do
  ref <- newSTRef 0
  total n ref 1

main :: IO ()
main = putStrLn (show (sumTo 10))
```

```text
55
```

`stToIO` runs a `RealWorld` thread as an `IO` action when the mutation has to
meet the outside world:

```moggi
import Control.Monad.ST
import Data.STRef

main :: IO ()
main = do
  x <- stToIO do
    ref <- newSTRef 1
    modifySTRef ref (+ 1)
    readSTRef ref
  putStrLn (show x)
```

```text
2
```

Mutable arrays use the same split: `Data.Array` is immutable, `Data.Array.ST`
and `Data.Array.IO` are the mutable variants, and `Data.Array.MArray` is the
class over both. They live in the `array` package, which is a separate library
root ([Standard library](../stdlib.md)).

### `IO` or `ST`?

| Need | Use |
|---|---|
| Read or write the world: files, arguments, console, clock | `IO` |
| A mutable cell or array inside one pure function | `ST` |
| Mutation whose result must be visible to `IO` | `ST` with `stToIO`, or `IO` directly |
| A value that outlives the computation | neither: a cell cannot escape its run |

## Limits

* **No concurrency.** There is no `forkIO`, no `MVar`, no `IORef`, and no async
  exception API. Effects are sequential.
* **No `IO` in data**, as above. If a computation must be passed around, pass a
  function returning `IO`.
* **No lifting between `ST` and `IO` except `stToIO`.** An `IO` action cannot be
  run inside `ST`.
* **In a `do` block, keep an `if` statement on one line.** A multi-line `if`
  works as a function body, but as a bare statement it cuts the branch at its
  first application and fails:

  ```text
  parse error: expected keyword `else`, found string "pos"
  ```

  Write the whole `if … then … else …` on one line, or bind it with `let` and
  run the binding:

  ```moggi
  main :: IO ()
  main = do
    let n = 1
    let action = if n > 0 then putStrLn (show n) else pure ()
    action
  ```

* **Handles are sequential streams.** There is no seeking, no text encoding
  selection and no explicit buffering; see the
  [standard library](../stdlib.md) for what `System.IO` provides.
* **Exception reports** are covered in [Diagnostics](../diagnostics.md):
  `error` aborts, `throwIO`/`catch` handle typed exceptions, and an uncaught
  exception prints a report naming your code.

## Where next

* [Exceptions](../diagnostics.md) — the report format and per-backend precision.
* [Type classes](../types/classes.md) — why `do` works for `Monad` and what a
  constraint means.
* [Standard library](../stdlib.md) — `System.IO`, `System.Environment`,
  `Control.Exception`, and the packages that ship with the compiler.
