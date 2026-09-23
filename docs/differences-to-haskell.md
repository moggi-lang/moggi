# Differences to Haskell / GHC `base`

Moggi ports the API surface of Haskell's `base`, but it is not Haskell and it is not GHC.
**You do not need this document to use Moggi.** It exists for readers who already know Haskell
and want to know, name by name, where Moggi differs; the user-facing behaviours are in
[tour.md](tour.md#behaviour-worth-knowing-up-front) and the language reference. It is
also the **only** place where the comparison is written down: source comments in `lib/` and `src/`
describe Moggi on its own terms.

Everything not listed below is meant to behave like `base`. A divergence that is not in this
file is a bug, not a design choice — fix it, or add a row here and justify it.

Rows are grouped by *why* the difference exists, and the ids are referenced nowhere else:

- **S** — strictness: Moggi evaluates strictly and has no thunks/laziness.
- **R** — representation: a value or module boundary the runtime cannot carry as `base` does.
- **D** — deliberate: we chose differently, on purpose, and keep it.

## 1. Evaluation (S)

| # | Difference | Notes |
|---|---|---|
| S1 | **No laziness, no thunks.** Arguments are evaluated before a call; there are no suspensions. | Partial application is a plain value (a `__partial` cell holding already-evaluated arguments), not a deferred computation. |
| S2 | **No infinite or self-referential structures.** `ones = 1 : ones` cannot be expressed. | Strict lists. Consequently `iterate`, `repeat` and `cycle` are **not provided** — on lists *or* on `Data.List.NonEmpty` — because each one's only possible result is an infinite structure: in a strict language `repeat x` must build the whole spine before returning, so every use would diverge rather than return the value `base` promises. This is an omission on purpose, not an unfinished port. |
| S3 | **`fold` is the strict left fold; there is no `foldl'`, and no lazy `foldl`.** `Data.Map` is strict and there is no `Data.Map.Strict`. | One name per operation instead of `base`'s lazy/strict pairs. |
| S4 | **`Monad` has no `return`; neither the `Prelude` nor `Control.Monad` exports one.** Use `pure` (from `Applicative`, a `Monad` superclass). | `base` keeps `return` as a method that its own documentation says "should /not/ be different from its default implementation as 'pure'" and whose "justification for the existence of this function is merely historic". A second spelling of an operation the superclass already provides is not worth keeping, so `Control.Monad`'s export list is one name shorter than `base`'s here. |
| S5 | **No `seq`, no `$!`, no `!` bang patterns**, and bottom is not observable via laziness. | Strictness is the evaluation strategy, so these have no work to do. |
| S6 | **`IO a` is an action value; there is no `RealWorld` token** and no `State#` plumbing. | IO is implemented by compiler intrinsics; only the observable behaviour (ordering, exception propagation) is part of the contract. |
| S7 | **`-XStrict`-like semantics everywhere**, including records and constructor fields. | Nothing is deferred to first use. |

## 2. Representation (R)

| # | Difference | Notes |
|---|---|---|
| R1 | **`String` is a host-native string, not `[Char]`.** There is `Data.String` only; no `Data.Text` and no `Data.Text.Lazy`. | Strings are UTF-8/UTF-16 native values in all three backends; `Char` is a Unicode scalar. Two consequences for the API: `Data.String` carries the list-to-string bridge (`unpack`, `pack`, `singleton`) that `base` does not need because there `String ~ [Char]`, and it overlays `length`/`take`/`drop`/`isPrefixOf`/`isSuffixOf`, which `base` keeps in `Data.List` — a module importing both must `hiding`-list them, since Moggi cannot resolve a name over two distinct types. `base`'s `IsString` is not implemented yet. |
| R2 | **`Int` is 64-bit signed** on every backend. | `base`: platform word size. PHP's native int is 64-bit too, so no emulation is needed for i64. `Bounded`/`Enum` report the full i64 range. |
| R3 | **`SomeException` has no constructor.** It is a compiler-provided tagged value (`SomeException#`). | `base` has `SomeException e` with an existential. Construction happens through `toException`; matching through `fromException`. |
| R4 | **No `Typeable`.** | `base`'s `Exception` superclass is `(Typeable e, Show e)`; ours is `Show e` only, and `Data.Dynamic`/`cast` follow from it (D8). Exceptions are matched by a stable *string* tag (`Exception#`'s `RT.exceptionUnwrap`), not by a type representation: no value in any backend carries a type identity to map one to (an ADT value is `Con { tag, … }` / an array with a tag; generated classes are per-module and per-closure, never per-type). This is the deliberate part. **The tag being *written by hand* in each instance is not a consequence of it — that is a library gap**: an `Exception` instance needs `toException`/`fromException` bodies where `base` needs none, and a stale tag is a silent bug. The compiler can synthesize the tag from the instance head, and giving `toException`/`fromException` class defaults follows from that. One semantic gap survives the fix: a tag names the *type constructor*, so it cannot distinguish `MyErr Int` from `MyErr Bool` the way an argument-reifying `TypeRep` does. |
| R5 | **No implicit parameters** (so no `HasCallStack` *as an implicit-parameter mechanism*). `throw` and `error` carry no implicit call stack today. | `base`: `throw :: (HasCallStack, Exception e) => e -> a`. The absence of implicit parameters is deliberate (Moggi has no `-XImplicitParams`); the absent *capability* — a call stack that a raised exception carries — is a gap, not a design choice. |
| R6 | **No `CallStack` / `SrcLoc` yet** (`fromCallSiteList`, `getCallStack`, `prettyCallStack`, `prettyCallStackLines`, `prettySrcLoc`). | A gap, not a design choice. The runtime already reifies frames for uncaught reports and the lowering already knows the statement a call sits in, so the compiler has the data; what is missing is the type, its operations, and the compiler filling the frames. `errorCallWithCallStackException` depends on it. |
| R7 | **No `ErrorCallWithLocation` (the *pattern synonym*).** | Moggi has no `PatternSynonyms`, so that spelling of the API is not portable — deliberate. What is *not* deliberate is that the location a raise site knows never reaches the `ErrorCall` value: today it is supplied only by the report's frames, so catching an `ErrorCall` loses it. That capability is a gap, not a design choice. |
| R8 | **`HostException` is a Moggi addition** (backend/native failure: backend, native type, message). | No `base` counterpart; it is how an FFI/backend fault is made catchable. |
| R9 | **Report header is `moggi: <Tag>: <display>`.** | `base` prints `prog: <displayException e>` with no type tag. Ours names the exception type, then the displayed value. |
| R10 | **Exception display text travels inside the exception value.** | The runtime holds no dictionaries, so `toException` records the rendered text (`someException = {__se, tag, payload, display}`). Re-throws and uncaught reports print it verbatim instead of reconstructing it from the payload's shape. |
| R11 | **A record field is read by projecting the value (`person.name`), and a record declaration generates no selector function.** | `base` gives every field a function (`name :: Person -> String`) whose name lives in the module's namespace, so two records in one module cannot both have `name` without `DuplicateRecordFields`. In Moggi a field name belongs to its record: the receiver's type selects the field, so homonymous fields in different records are ordinary, and there is no `name person` spelling. Projection binds tighter than application, as `.` does in the host languages. |
| R12 | **A record update needs a record type with one constructor.** `p { age = 31 }` is the update `base` has, nested form included, but `data Shape = Circle { r :: Int } \| Square { s :: Int }` cannot be updated: which fields the value has is decided by the constructor it was built with, and that is not known until it runs. | `base` accepts the update anywhere a selector exists and raises "record update for a value of another constructor" at run time. Refusing it at compile time is the point of not having a runtime case for a shape the type cannot describe; the alternative is to match on the constructor explicitly and rebuild in each arm ([language.md § Records](language.md#records)). |
| R13 | **An instance that satisfies none of a class's `{-# MINIMAL … #-}` alternatives is an error**, where GHC reports it as the `-Wmissing-methods` warning. A class whose methods are mutually defaulted (base's `Traversable`: `traverse` is defined by `sequenceA` and `sequenceA` by `traverse`) compiles an empty instance into a call cycle that never reaches a value, and Moggi has no warning channel to report it in, so the diagnostic is hard. The alternatives are a disjunction of conjunctions: an instance has to write *every* method of *one* alternative (`{-# MINIMAL traverse \| sequenceA #-}` is satisfied by `sequenceA` alone), and a method that has no default still has to be implemented as before. | Moggi's diagnostics are errors; making this a warning would let the default silently diverge at run time in a strict language. |

## 3. Exceptions (D)

| # | Difference | Notes |
|---|---|---|
| D1 | **Synchronous exceptions only.** No async exceptions, no `mask`/`uninterruptibleMask`, no `ExceptionContext`. | `Control.Exception` is the synchronous subset: `throw`, `throwIO`, `ioError`, `catch`, `handle`, `try`, `bracket`, `finally`, `onException`, … |
| D2 | **`ArithException` matches `base` exactly** (six constructors including `Denormal`, with `base`'s `Show` strings). | Negative shift counts raise `Overflow`, integer division by zero raises `DivideByZero`; hosts' own faults (`DivisionByZeroError`, `ArithmeticException`, `DivideByZeroException`) are never what a Moggi program sees. |
| D3 | **Shift and rotate counts are clamped to the type width.** | `base` leaves counts `>= bitSize` undefined (`unsafeShiftL`: "undefined for negative shift amounts and shift amounts greater or equal to the bitSize"). Clamping is deterministic and identical on all three backends; the raw primops require `[0,63]`. Negative counts still raise `ArithException Overflow`, as in `base`. |
| D4 | **`bitSize` on `Integer`/`Natural` fails with `ErrorCall`** ("bitSize is undefined"). | Same as `base`; listed here because it is the one `Bits` operation that can fail. |

## 4. Classes (D)

The classes are a work in progress; the method sets below are the *current* state. Naming decisions that
are deliberate:

| # | Difference | Notes |
|---|---|---|
| D5 | **`Functor`'s method is `map`; there is no `fmap`.** | `base` splits the concept: `fmap` is the method and `map` is a list function. Moggi has one mapping operation, the class method `map`, which is therefore the uniform spelling for every `Functor`; `Data.List` declares no `map` of its own, and `fmap` does not exist. This is deliberate: Moggi is not a Haskell source-compatibility target (its strict semantics already preclude that), so a second name for the same operation is not a reason to keep `base`'s split. `(<$)` remains a method, as in `base`. |
| D6 | **`Foldable` exposes one strict fold, not `foldl`/`foldl'`/`foldr` triples by default.** | `base` calls that out itself: its `mconcat` is documented as "a strict left fold instead of a lazy right fold". |
| D7 | **`Show` is the full class (`showsPrec`/`showList`/`show` plus `ShowS`); `Read` is still just `read`/`Read`.** | `Read`'s `readsPrec`/`readList`/`ReadS`/`lex` machinery and the `Read` instances are not there yet. |
| D8 | **No `Typeable`-based `Dynamic`/`cast`.** | Follows R4. |
| D9 | **`Enum`, `Applicative`, `Monad`, `Semigroup` expose part of `base`'s method set as top-level functions.** | They must become methods (`enumFrom*`, `liftA2`/`*>`/`<*`, `>>`, `sconcat`/`stimes`) for `base` code to be portable. |
| D10 | **`Monoid` has no `mappend` method.** | `base` documents it as redundant (`mappend = (<>)`) and slated for removal from the class. `(<>)`, from the `Semigroup` superclass, is the only spelling, and `mconcat`'s default body is written with it. |

## 5. Host interface (R, D)

`base` reaches the host through the C FFI; Moggi reaches three different hosts through
`foreign` declarations ([ffi.md](ffi.md) is the user-facing reference).

| # | Difference | Notes |
|---|---|---|
| R14 | **No `Foreign.*` layer, no `Ptr`, no `Storable`, no `Foreign.Marshal`.** | There is no pointer type, no `alloca`/`malloc`/`free`, no `peek`/`poke`, and no `CInt`/`CString` family: a declaration names the host member as a string and the compiler emits the call. What `base` builds by hand around a C ABI — allocating, marshalling, reading back — does not exist to be built. |
| R15 | **PHP is the one host with a reification boundary: `Platform.PHP`'s `PHPValue`.** | PHP's `mixed` is not a class, so a PHP declaration may name `"mixed"` as a foreign *type* and `Platform.PHP`'s `PHPValue` reifies such a value into a Moggi ADT that can be matched. The JVM and .NET need no such thing: an open host value there is an ordinary foreign type with a real class path (`java.lang.Object`, `System.Object`). `PHPValue` may only appear in a `foreign php` signature. |
| D11 | **A declaration belongs to one backend, chosen at compile time.** | GHC links one ABI, so `foreign import ccall` is portable by construction. Moggi compiles to three hosts, so a host call is *not* portable: a module that needs one keeps it in a per-backend implementation module behind a facade (`System.IO` → `System.IO.PHP` / `System.IO.JVM` / `System.IO.DotNet`). Naming another backend's import is a compile error, not a runtime discovery. |
| D12 | **A signature is not checked against the host; a host fault is a catchable `HostException`.** | The compiler trusts the path and the Moggi types, so a wrong member name or arity surfaces as the host's own failure (`NoSuchMethodError`, `MissingMethodException`) instead of being rejected at compile time. What the host raises becomes `HostException backend nativeType message` (R8), which the library may classify — `System.IO` catches one from a file operation and re-raises the `IOException` its callers expect. |
| D13 | **Results may be converted; arguments may not be marshalled.** | `Maybe`, `Either`, lists and `Handle` in *result* position have a defined conversion (a host `false`/`null` to `Nothing`, a thrown failure to `Left`). In *argument* position the compiler refuses the shape rather than passing Moggi's own representation to the host: `Maybe`/`Either`/`Tuple` anywhere, and `[a]` unless the declaration is a php one (there a Moggi list is the host array). Call element by element, or reach the host through a declared foreign type. |

## 6. Module layout (D)

| # | Difference | Notes |
|---|---|---|
| D14 | **`Prelude` is a list of re-exports plus the compiler's intrinsic modules**, not a copy of GHC's internal hierarchy to mirror. | Same idea as `base`'s `Prelude`, which is also a re-export list. |
| D15 | **No `GHC.Internal.*` mirror.** The intrinsic boundary is `Moggi.Internal.Prim` and `Moggi.Internal.IO`, and intrinsics are reachable only by importing them. | Implementation helpers that other stdlib modules need move into a `Moggi.Internal.*` module (`Moggi.Internal.Integer`, `Moggi.Internal.Real`, `Moggi.Internal.Float`, `Moggi.Internal.Show`, `Moggi.Internal.Read`, `Moggi.Internal.Base`). |
| D16 | **Public modules are exactly `base`'s public modules**; a definition with no public home of its own lives in `Moggi.Internal.*`. | There is no `Data.Num`/`Data.Integral`/`Data.Fractional`/`Data.Integer`/`Data.Double`; those names are not in `base`, so they are not in Moggi either. Instances stay with their type's module. |
| D17 | **The PHP runtime is a compiler source file**: `src/backend/php/runtime.php`, copied into every build as `_runtime.php` at the root of the generated tree. | `base` has no analogue. `_runtime.php` keeps the name collision-free against a userland `runtime.mog`/`runtime/`. |
| D18 | **Partial list functions are planned to take `NonEmpty`** (`head`, `tail`, `last`, `init`). | Deviation from `base`'s partial signatures, sanctioned; keeps `base`'s names. |
| D19 | **`Data.Tuple` also carries `GHC.Tuple`'s contents** — `Solo`, `Tuple0`..`Tuple64`, `Unit`. | There is no `GHC.*` layer (D15/D16), and `Data.Tuple` is the only tuple module a user has, so the tuple types `base` splits between `Data.Tuple` and `GHC.Tuple` meet in one place. |
| D20 | **`Prelude` does not carry file or filesystem operations.** It re-exports the pure core plus `IO`, `putStr` and `putStrLn`; handles and read-side IO (`print`, `getChar`, `getLine`, `readFile`, `writeFile`, `appendFile`, …) come from an explicit `import System.IO`, and the path/directory operations from an explicit `import System.Filesystem`. | `base`'s `Prelude` exports `readFile`/`writeFile`/`appendFile`/`print`/`getLine`/`interact`/`FilePath` and friends on top of `System.IO`. Moggi keeps the effects that touch the outside world behind a named import instead of putting them in every module's scope. Nothing is missing from the library — only from the implicit scope: the whole set, character IO included, is in `System.IO`. |
| D21 | **An ambiguous numeric variable defaults to `Int` first, then `Double`** (`default (Int, Double)`), where `base` defaults `(Integer, Double)`. | `Int` is the intrinsic machine integer on every backend; `Integer` is a library type whose PHP representation is a decimal string for BCMath, so defaulting to it would make the smallest unannotated program (`1 + 2`) allocate, convert and call through a dictionary. The mechanism is `base`'s: the same obligation-solving pass walks the candidate list and picks the first candidate that satisfies every constraint, and `Integer` stays one annotation (`(1 + 2 :: Integer)`) away. Consequence: an unannotated computation that overflows `Int` wraps instead of promoting, so annotate `Integer` where the range matters — the same holds for a literal larger than `Int` that this default pins to `Int`, which wraps to its low 64 bits (GHC wraps there too, but only when the literal is not made `Integer` by defaulting). A literal is otherwise exact: one that does not fit the host `int` keeps its digits, so at `Integer` it is the number it spells. |
| D22 | **A module's backend implementations are declared in the module itself**, in a `{-# BACKEND php = System.IO.PHP #-}` pragma above the header — one entry per line when there is more than one. | GHC splits this across package stanzas and platform-conditional module lists; Moggi has one file per module and no build file, so the map is part of the module it belongs to. A backend the pragma does not name cannot be built — the error is in §5. |
| D23 | **`LANGUAGE` accepts exactly one extension: `NoImplicitPrelude`.** | There is no GHC to switch behaviour on. Every other GHC extension names something that either does not exist here or is always on, so a pragma naming one is a parse error rather than a silent no-op. |

## 7. Not implemented yet (gaps, not deviations)

Listed here so nobody mistakes a missing module for a design choice:

`Float`, `Real`, `RealFrac`, `Floating`, `RealFloat`, `Fractional.fromRational`, `Data.Ratio`,
`Data.Complex`, `Data.Fixed`, `Numeric`, most `Read` instances,
`CallStack`/`SrcLoc` and the call stack an exception carries (R5–R7), synthesized
exception tags and `toException`/`fromException` class defaults (R4), the remaining class
methods (§4), the arithmetic helpers `subtract`, `even`, `odd`, `gcd`, `lcm`, `(^)`, `(^^)`,
and `asTypeOf`/`errorWithoutStackTrace`.

The `Prelude` re-export list matches `base`'s: the re-export gaps and the convenience names
that belong to `base` are both closed.

Also missing, found by a name-by-name export audit: `Data.Enum.enumerate`,
`Data.Foldable.msum`, `Data.Monoid.Alt`/`Ap`, `Data.Ord.Down`, `Data.Proxy.KProxy`/`asProxyTypeOf`,
`Data.List.inits1`/`tails1`, `Data.Semigroup`'s wrapper types and `stimes*` family, `Data.Traversable`'s
`mapAccumM`/`forAccumM`, `Control.Applicative`'s `Const`/`ZipList`/`WrappedMonad`/
`WrappedArrow`/`asum`, `Control.Monad`'s `forM`/`forM_`/`liftM3`–`liftM5`/`mapAndUnzipM`/`mapM`/`mapM_`/
`sequence`/`sequence_`/`void`, `Data.Char`'s `GeneralCategory`/`generalCategory`/`isLowerCase`/`isUpperCase`/
`isMark`/`lexLitChar`/`readLitChar`, `System.Environment`'s `getEnvironment`/`setEnv`/`unsetEnv`/
`getExecutablePath`/`executablePath`/`withArgs`/`withProgName`, and `System.IO`'s buffering/encoding/seek/
temp-file API.

Also missing as *library API*: `Control.Monad.Reader`,
`Control.Monad.State`, `Control.Monad.ST` (with their `MonadReader`/`MonadState` classes and the
`MonadTrans`/`MonadIO` they build on), `Data.Functor.Identity`
and `Data.Functor.Const`.

## 8. What is *not* a difference

Useful when diffing against `base`, because these look like deviations and are not:

- `ArithException`'s six constructors and `Show` strings (D2).
- `ErrorCall`'s `Show` (the message) and `bitSize`'s `ErrorCall` (D4).
- Operator fixities and precedence in `Data.Bits`, `Data.Function`, `Data.List`.
- `Data.Bits`'s class split (`Bits`, `FiniteBits`) and the `Integer`/`Natural` "fake two's
  complement" rule for negative values.
- `Int`'s 64-bit width (R2) — GHC on a 64-bit platform is the same.
