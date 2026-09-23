# Design notes

Work-in-progress decisions for primitives, type classes, and stdlib layout — a record for people
working on the compiler and the library, not a user document. A user should read
[tour.md](../tour.md) (and its *Behaviour worth knowing up front* section) instead.

## Foreign types (absolute boundary)

**Axiom:** Foreign types represent specific host types. Ordinary Moggi ADTs represent Moggi values. There is no generic opaque “host value” type. The user-facing account is [ffi.md](../ffi.md); what follows is the invariant the compiler enforces.

- `foreign … type Path "java.nio.file.Path"` (etc.) — specific host type, nominal Moggi type.
- The deleted fake opaques (`JObject` / `ClrObject` / `PhpRef`) do not come back, and not under a new name: a `foreign type … "java.lang.Object"` is the same escape hatch and is only correct when the API’s true type *is* arbitrary Object (almost never).
- `PHPValue` — ordinary Moggi ADT for deliberate mixed → inspectable reification (Composer); not a handle; unknown class instances must not fake-reify into it.
- `Encoding` — per-backend foreign type for the host JSON tree (not an inventable ADT).
- `Handle` / `Resource` — closed portable allowlist with a defined ABI.
- Nothing else is a host object: no generic Object fallback, no polymorphic `a` as an FFI object, no “any ADT is a host object”, no implicit host subtyping.

### What the boundary guarantees

Relative to trusted foreign axioms (a `foreign import` is an axiom; the compiler does not verify it against the host):

1. **No invention** — without one, a value of a foreign type cannot be constructed, matched or otherwise introduced by Moggi expressions. `data State = State Connection` is fine — it wraps, it does not manufacture.
2. **No confusion** — distinct nominal foreign types cannot unify, regardless of backend or host type string.
3. **No smuggling** — every type in a foreign signature is recursively legal, so an ordinary inventable ADT cannot pose as a host object.
4. **No unresolved leaves** — every foreign nominal leaf in a validated signature resolves to its declaration’s host type; failing that is a compile error.
5. **No polymorphic or open higher-order holes** — no type variables, and a nested arrow only under an exact higher-order ABI rule (a fixed Moggi shape with a fixed backend ABI, not “arrows allowed at this path”).

Non-goals: host subtyping, linearity, use-after-free, and the honesty of a lying signature.

### Legality, recursively

| Node | Legal when |
|------|------------|
| Foreign type | Declared `foreign` **and** for the backend of this import |
| `Handle`, `Resource` | Closed portable allowlist, defined ABI |
| Primitive / closed mapped type | A real mapping, never type-to-Object by default |
| `Maybe` / `Either` / tuple / `[a]` | Payloads recursively legal, and a defined marshalling for the position |
| `PHPValue` | Closed allowlist as mixed → PHPValue reification only, in a `foreign php` declaration; never preferred over a precise type |
| User ADT / other inventable opaque | **Error** |
| Instance receiver | Same-backend foreign type, `Handle`, or a public nullary alias of a Magichash prim (`Integer` / `String` / `Int32` / …) — never a Magichash spelling, `PHPValue`, a type variable, or an arbitrary ADT |

Synonym normalization runs first, so an unresolved synonym is never treated as a leaf. `IO` may appear only outermost in a result, and only on `function`, never on a `const`. Ordinary Moggi code may pass, return, store and wrap foreign types, but may not construct or match them, derive for them, or write an instance with one as the head — which falls out of `constructors = []` rather than a second ban list.

### Two phases

1. A validated, normalized signature is lowered to the backend ABI, which assumes closed-legal input and therefore can never reach “unknown → Object”
2. Each foreign leaf goes through `lookupForeignType` → the declaration’s required `foreignTypeDescriptor(hostType, …)`

ABI overrides that are not foreign mappings (String → `CharSequence`, `Int` → `I`/`J`) stay separate from both phases.

## Architecture (layers)

```
Surface          +, <>, ==, user ADTs, instances
Classes          data.semigroup, data.eq, data.ord, data.num  (pub methods)
Lib data.*       data.int, data.string, …  (neutral facade: signatures, instances, backend map)
Lib data.*.php   data.string.php, data.int.php, …  (backend: foreign import → PHP/JS/JVM)
IR / intrinsic   listCons#, intAdd#, stringEq#, ordering*#, bytesFromString#, …  (compiler primops only)
Backend          PHP int/string/array, Moggi runtime helpers, …
```

Facade modules declare backend implementations in a `{-# BACKEND … #-}` pragma above the
header:

```
{-# BACKEND
  php = Data.String.PHP
  js  = Platform.JS.String
#-}
```

Impl modules are ordinary modules (e.g. `module Data.String.PHP`); the pragma points at them by
name.

#### A facade may omit a backend

A map does not have to name every backend. When a library's implementation exists for one host only
— Qt5 bindings available for the JVM and not for PHP — the facade declares just that entry:

```
{-# BACKEND jvm = Platform.Qt5 #-}
```

Callers still write backend-neutral code, because the public signatures live in the facade; the
module simply cannot be built for a backend the map does not name. Selecting one is then a compile
error that names both sides, so the message says which one to change:

```text
lib/Platform.mog:5:8: type error: module `Platform` has no `php` implementation (it implements: jvm, dotnet)

  5 | module Platform
    |        ^^^^^^^^
```

This is the case the multi-implementation pattern above is *not*: there, one API has a module per
backend and every compile target finds one; here a target has no implementation at all, and the
error is what says so instead of leaving the API quietly unimplemented. `tests/semantics/bad-facade-missing-backend/`
is the fixture, and because that project compiles on the backends the facade *does* implement, the
case declares the single backend it is about.

Neutral `data.*` modules declare types, typeclass instances, and public signatures. Implementation lives in `data.*.php` backend modules via `foreign import`, same pattern as `system.fs` / `system.io`. The compiler lowers operator literals and monomorphic operators to a **minimal intrinsic allowlist**; everything else is ordinary Moggi code the optimizer can inline.

### Minimal intrinsic allowlist

| Category | Names | Why |
|----------|-------|-----|
| Numeric literals | `intFromInteger#`, `doubleFromInteger#`, `intToInteger#`, `integerFromDigits#` | Literal desugaring via `fromInteger`; a literal too large for the host `int` is lowered from its digits (`integerFromDigits#`) so no digits are lost |
| List sugar | `listCons#` | `:` on `List#` |
| Operator monomorphization | `intAdd#`, `intSub#`, `intMul#`, `intEq#`, `intNe#`, `stringAppend#`, `stringEq#`, `stringNe#`, `bytesAppend#`, `bytesEq#`, `bytesNe#`, `double*#`, `boolAnd#`, `boolOr#`, `boolNot#`, `boolEq#`, `boolNe#`, `listAppend#`, `listEq#`, `listNe#`, `maybeEq#`, `maybeNe#` | `resolveMonomorphicOperator()` |
| Ord compiler glue | `ordering*#` | Default `(<=)`, `min`, `max` from `compare` |
| IR coercions | `bytesFromString#`, `bytesToString#` | Distinct `String` / `ByteString` at zero runtime cost on PHP |
| Platform entry point | `platformArgv#` | Unique exception: program arguments must be captured by the generated `main` stub (e.g. `Platform.setArgs`); no JDK/BCL FFI surface exposes process args directly. All other platform IO/FS uses ordinary FFI. |

Library functions (`length`, `list_map`, `showInt`, …) use **foreign import** in backend modules, not intrinsics.

No magic operators or builtins in the typechecker. Everything is defined in stdlib classes; the compiler lowers and specializes (e.g. `1 + 1` → `Num.(+)` → `intAdd#` → PHP `+`).

### Intrinsics (`Moggi.Internal.Prim` / `Moggi.Internal.IO`)

- **Modules:** compiler-synthesized. `Moggi.Internal.Prim` owns general Magichash types (`Int#`, `List#`, …) and `#`-suffixed primops (`intAdd#`, …). `Moggi.Internal.IO` owns `IO#`, `ioPure#`, `ioBind#` (special IO lowering).
- **Access:** import the owning module; Magichash / `#` names are not ambient. Public synonyms (`type Int = Int#`, `type IO = IO#`) live in `Data.*` / `System.IO.Base` (re-exported by `System.IO`).
- **Naming:** a primop has exactly one name — the `#`-suffixed MagicHash name used in source (`intAdd#`, `word32Eq#`, `error#`). That name is also the IR/backend id; there is no separate snake_case id.
- **Reserved:** user declarations may not use the `#` suffix.
- **Call syntax:** ordinary application after import, e.g. `(==) a b = stringEq# a b`. No `intrinsic` keyword.

### Module paths

- Dotted names like `data.string`, `data.eq` use `data` as a **path segment**, not the `data` declaration keyword. Parser must accept keywords as module segments (today `module data.string` fails because `data` lexes as `KwData`).
- **`Moggi.*`** — language/runtime primitives the compiler knows about (`Moggi.Err`, `Moggi.Internal.Prim`, `Moggi.Internal.IO`). Not host FFI (`Platform.*`) and not library facades (`Data.*` / `System.*`). User-facing `IO` lives in `System.IO` (↔ base).

### Names

- **`Double`** is the only floating type (no `Float`). Int-doubling fixtures live under `Twice` / `examples/twice`.
- **`Data.ByteString`** — module casing matches the `ByteString` type (not `Data.Bytestring`).
- **`Data.Word`** — `Word8`/`Word16`/`Word32`/`Word64` as `type WordN = WordN#` (no machine `Word`). Instances live in `Data.Word` like `GHC.Internal.Word`. Width via `wordNFromInt#` masks on PHP/JVM.
- **Instance homes** — follow Haskell base / `ghc-internal` when the Moggi module graph allows (e.g. Word with its type; Foldable/Traversable for Maybe). Prefer type-module instances when a class-module move would create an import cycle (`Data.List` ↔ `Data.Foldable` ↔ `Control.Applicative`, etc.).

### Bottoms (`Moggi.Err`)

- **`error :: String -> a`** — primop abort (`error#`); re-exported by Prelude.
- **`undefined :: a`** — `error "Prelude.undefined"`; same IO-boundary treatment as `error` (polymorphic bottom). Under strict evaluation it aborts when forced.

### Recursion (`Data.Function`)

- **`fix :: (a -> a) -> a`** — primop `fix#`. Moggi is strict, so the least fixed point cannot be written as an ordinary recursive `let` (`fix f = f (fix f)` forces its argument forever). Each backend lowers `fix#` to a memoising self-reference that is only forced when `f` actually recurses, which is what makes `fix (\rec n -> …) 5` terminate. `lib/Data/Function.mog` re-exports it as `fix = fix#`.

### Literals

- **Haskell-style:** integer literals polymorphic via `Num` / `fromInteger`; string literals `String`; character literals `'a' :: Char`.
- **Fractional literals** — **deferred** (`Rational` / `Fractional` later); `1.0` as monomorphic `Double` when needed.
- **Source encoding:** UTF-8; invalid UTF-8 is a lexer error.
- **Character literals:** exactly one Unicode scalar value; not normalized (NFD multi-scalar forms are a lex error).

### `String` / `Char`

- **`Char`** = Unicode scalar value (`U+0000..U+10FFFF` excluding surrogates `U+D800..U+DFFF`). Language-defined; not a backend `char` / UTF-16 unit. Runtime: unboxed int. `type Char = Char#`.
- **`String`** = opaque backend-native representation of a **sequence of `Char`**. Semantic only—does not expose list ops or guarantee scalar indexing complexity. Representation ≠ `[Char]`.
- **Equality:** scalar-sequence identity; normalization is not implicit (`"\u{00E9}" /= "\u{0065}\u{0301}"`).
- **Bridges:** `singleton :: Char -> String` (cannot fail), `unpack :: String -> [Char]`, `pack :: [Char] -> String` (`pack` never receives surrogates).
- **Public String ops** (`length`, `take`, `drop`, …) count/index by Unicode scalar values, not bytes/code units.
- **`chr :: Int -> Char`:** partial; traps on non-scalars. **`Enum`/`Bounded Char`:** `minBound = U+0000`, `maxBound = U+10FFFF`; `succ`/`pred` skip surrogates and trap at bounds; enum order excludes surrogates.
- **Predicates:** Unicode category/case → backend platform APIs (`IntlChar`, etc.); code-range (`isAscii`, `isLatin1`, …) → pure Moggi on `ord`. Document platform category deltas per backend.
- **Foreign hygiene:** exported signatures use Moggi types only (`Char`, `String`); no leaked backend char types.
- **Debug/`Char` print:** `'\u{1F600}'` scalar notation (not bare decimal / UTF-8 byte dumps).
- **Out of scope here:** `Grapheme`, normalization APIs, `Path`, `ByteString`.

### Prelude

- **Deferred.** Explicit imports until prelude step.

### `Bool` → backend bool

- The declared `data Bool = False | True` lowers to the backend native bool (`False` = PHP `false`, `True` = PHP `true`).
- Other two-constructor ADTs (e.g. `data Color = Black | White`) stay ordinary tagged ADTs - `case` on `Bool` → `if` / branch when scrutinee is known.
- `Eq` / `Ord` instances still exist; monomorphic ops specialize to intrinsics / `===`.

### List types

- **Canonical:** `List a` (`data.list`).
- **Sugar:** `[a]` in types → `List a`.

### Type classes (first wave)

- **`Semigroup`**, **`Eq`**, **`Ord`**, **`Num`**, **`Integral`**, **`Fractional`**, **`Functor`**, **`Foldable`** (dictionary passing for constrained calls; class param kinds inferred when omitted).
- **`Semigroup`:** `(<>) :: a -> a -> a` (Haskell-aligned append operator).
- **`Ord` superclass `Eq`.**
- **Numeric tower:** `Num` → `Int` / `Integer` / `Double`; `Integral` → `Int` / `Integer`; `Fractional` → `Double`. No `Real` / `Floating` / `Float`.
- **Multi-constraint** from day one: `(Eq a, Ord a) => …` (several constraints share one parenthesized context).
- **Instances anywhere** — no orphan restriction; e.g. `instance Eq Maybe` may live in `maybe.mog` but need not.
- **One dictionary per class** per call; optimizer removes dicts when instance is known (monomorphic `1 + 1` → direct `intAdd#`).

### `class Semigroup`

```moggi
class Semigroup a where
  (<>) :: a -> a -> a
```

`instance Semigroup String` in `data.string` uses intrinsic `stringAppend#`; library ops like `length` are foreign imports in `data.string.php`.

### `class Eq`

```moggi
class Eq a where
  (==), (/=) :: a -> a -> Bool
```

Both methods in the class; **no** compiler-magic definition of one from the other. Instances implement both (defaults in class decl optional later).

### `class Ord`

```moggi
class Ord a where
  compare :: a -> a -> Ordering
```

**GHC-style defaults** for `(<)`, `(<=)`, `(>)`, `(>=)`, `min`, `max` derived from `compare` in the class definition (not user magic — stdlib class defaults).

### `class Num`

```moggi
class Num a where
  (+), (-), (*) :: a -> a -> a
  negate        :: a -> a
  abs, signum   :: a -> a
  fromInteger   :: Integer -> a
```

### Operators & fixity

- **No magic operators** in compiler env (remove hardcoded `+` in `types.php` when `Num` lands).
- Operators defined in stdlib (`data.num`, `data.eq`, `data.ord`) with `infix` / `infixl` declarations there.
- Parse: operator → class method → dict dispatch → specialize to intrinsic.

### Dictionaries (runtime)

- One hidden **dictionary param per class** when polymorphic.
- **Local bindings count too:** a `let`/`where` binding whose type is constrained — inferred (`where go = show`) or spelled out (`go :: C a => a -> T`) — is generalized and becomes a function of its dictionaries, exactly like a top-level function. Call sites pass the dictionaries, so one local helper can be used at several types.
- **What’s fast:** specialize monomorphic sites — `s <> t` on `String` → `stringAppend#` / PHP `.` with no `Semigroup` dict; `x == y` on `String` → `===` with no `Eq` dict. Polymorphic code keeps dicts until the optimizer can prove the type.

### Class syntax

Haskell-shaped — not an open design question. Parser/TC/codegen must implement: `class` / `instance` / `where`, grouped method sigs `(==), (/=) :: …`, infix instance methods. Ord default methods derived from `compare` in the class declaration (GHC-style).

### Kind inference

Kinds are inferred from structure; you write `data Either a b = …`, not `Either : Type -> Type -> Type`.

- **Kind variables** (`k0`, `k1`, …) and **constraints** from type application: `kind(f) ~ kind(x) -> kind(result)`.
- **Data declarations:** each constructor field and the fully-applied result type (`Either e a`) must have kind `Type`; solving yields `Either : Type -> Type -> Type`.
- **Class params:** unannotated params (`class Functor f`) get fresh kind variables; method signatures constrain them (`f a` in a type forces `f : Type -> Type`).
- **Explicit annotations** still work: `class Foo (t :: Type -> Type)` when needed.
- **Bootstrap:** primitives (`Int`, `Bool`, …), `List#`, and `TupleN` stay in the kind environment; everything else is inferred at `data` / `class` registration.

Implementation: `src/semantics/kinds.php` (`inferKindAst`, `inferDataKind`, `inferClassParamKinds`, `unifyKind`).

### `Integer` vs `Int`

- **`Integer`** — a separate type, arbitrary precision, in `Moggi.Internal.Integer` with one implementation per backend. Numeric literals desugar through `fromInteger :: Integer -> a`, so `1 + 2` at `Integer` never goes near a machine word. PHP represents it as a decimal string and computes with [bcmath](https://www.php.net/manual/en/book.bc.php) (`lib/Moggi/Internal/Integer/PHP.mog`); the JVM and .NET use their own big-integer types. `Int` is a *view* of it (`integerToInt`), not its representation.
- **`Int`** — signed 64-bit machine integer (JVM `long`, .NET `long`, PHP host `int` on a 64-bit build), with `Bounded` matching those 64 bits. It **wraps** on overflow on every backend and there is no `Overflow` exception: PHP's arithmetic promotes to `float` past `PHP_INT_MAX`, so the codegen clamps back into the 64-bit range and the optimizer refuses a constant fold that would leave it.
- **Defaulting** — an ambiguous numeric literal defaults to `Int`, not `Integer`. That is a deliberate deviation (Haskell defaults to `Integer`) and is listed in [differences-to-haskell.md](../differences-to-haskell.md).

## Where each decision is pinned

A design decision is only real if something fails when it changes, so each one
has a fixture whose golden shows the lowering:

| Decision | Fixture |
|---|---|
| `<>` on `String` lowers to `stringAppend#` | `tests/backend/codegen/Tc-Semigroup-String.mog` |
| `==`/`/=` on `Int` lower to `intEq#`/`intNe#` | `tests/semantics/Tc-Eq-Int.mog` |
| `1 + 2` lowers to `intAdd#` | `tests/semantics/Tc-Num-Add.mog` |
| literals stay polymorphic until a use pins them | `tests/semantics/Tc-Constrained-Let.mog`, `Bad-Ambiguous-Constraint.mog` |
| list patterns and cons | `tests/backend/codegen/Tc-List.mog`, `tests/backend/runtime/Exec-List.mog` |
| a missing instance / a duplicated method is an error | `tests/semantics/Bad-Instance-Method-Unresolved-Constraint.mog`, `Bad-Duplicate-Instance-Method.mog` |
| the emitted shape of each primop | `tests/optimize`, `tests/backend/codegen` |

`tests/README.md` and [testing.md](testing.md) describe the layout and what each
kind of golden compares; `runtest --list` prints every case by group.
