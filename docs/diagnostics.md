# Diagnostics

Moggi reports failures in terms of *your* code, not the generated artifact. A
compile error names a `.mog` location, and the compiler records a source map for
every module — embedded in the build output — so each runtime translates the
host's own stack back into Moggi terms when an exception escapes.

There are two families, and they have different shapes:

* **Compiler diagnostics** — the program is rejected; nothing runs.
* **Runtime reports** — the program failed while running; the report names the
  Moggi frames it reached, and the host's own words underneath.

## Compiler diagnostics

Every one has one shape: a location, a kind, a message, and the source line with
a caret under the exact token.

```text
Main.mog:4:18: type error: could not unify `String` with `Int`

  4 | answer = 1 + "x"
    |                  ^
```

The kind is `parse error`, `lex error`, `type error` or `kind error`, and it is
always lowercase so a tool can match on it. The message names Moggi things only;
a host class or exception type never appears in a compile error.

### Syntax

| Message | What it means |
|---|---|
| ``expected ')', found end of file`` | The parser ran out of input inside an open construct. The caret is where the missing token belongs, which is often the line *before* the one you think. |
| ``expected keyword `else`, found end of file`` | `if` without an `else` is not an expression here: both branches are required. |
| ``use `import M qualified as Alias`, not `import qualified M` `` | Qualification is a suffix. The parser states the form it wants instead of leaving you to guess. |
| ``function `foo` has inconsistent clause arities`` | Two equations of one function take a different number of arguments — clauses of one binding must agree. |
| ``type signature for `g` lacks an accompanying binding`` | A signature with no definition; in a `let`/`where` group every signature must have one. |

Lexer errors carry the kind `lex error`: ``unexpected character …`` (with the
character and its code point), plus ``unterminated string literal``,
``unterminated character literal``, ``unterminated block comment``,
``unterminated documentation block`` and ``unterminated pragma``.

### Pragmas

| Message | What it means |
|---|---|
| ``unsupported pragma `FOO` `` | Only `LANGUAGE` and `BACKEND` exist ([language.md § Pragmas](language.md#pragmas)); any other pragma is rejected instead of ignored. |
| `a pragma comes before the module header` | The pragma sits below the header (or below the header's doc comment), where the parser is reading declarations. Move it to the top of the file. |
| `a module names its implementations in a `{-# BACKEND … #-}` pragma above the module header, as in `{-# BACKEND php = Data.Char.PHP #-}`` | The old `backend php Data.Char.PHP` line — `backend` is an ordinary identifier now, so this is a declaration the parser cannot make sense of. |
| ``malformed BACKEND entry `php Data.Char.PHP`; expected `<backend> = <Module>` `` | The entry is missing its `=` (each entry is `<backend> = <Module>`, separated by newlines or commas). |
| `` `data.char` is not a module name `` | The right-hand side must be a module name: dot-separated constructors, as in `Data.Char.PHP`. |
| ``backend `php` is listed twice in the BACKEND pragma`` | Two entries for one backend; keep the one you meant. |
| ``BACKEND pragma needs at least one `<backend> = <Module>` entry`` | `{-# BACKEND #-}` with nothing in it. |

### Errors you will meet first

| Message | What it means |
|---|---|
| ``undefined variable `notInt` `` | A name that is not in scope — a typo, or a definition you forgot to `import` ([tour.md](tour.md#modules-imports-and-the-prelude) lists what the Prelude gives you and what needs an import). |
| ``undefined constructor `Bogus` `` | A data constructor that is not in scope; import the module that declares it. |
| ``unknown type constructor `Unti` `` | A type name that is not in scope. |
| ``unknown type constructor `Int#` (import `Moggi.Internal.Prim` to use compiler primitives)`` | Primitives are **not** global. They are reachable only through `Moggi.Internal.Prim`, so writing library internals is a deliberate import. |
| ``undefined variable `intAdd#` `` | The same rule for an intrinsic *function*. |
| ``could not unify `String` with `Int` `` | The two sides of an expression, annotation or argument disagree on type. The caret is on the one that did not match. |
| ``could not unify `Maybe a` with `b -> c` `` | You applied something that is not a function, or passed more arguments than it takes. |
| ``unresolved type class constraint: `Eq Box` `` | The code needs an instance that is not declared (or is out of scope). Write one, or add the type signature that pins the type down. |
| ``ambiguous constraint `Show a`; add a type signature`` | Nothing in the expression fixes `a`, so no instance can be chosen — the fix is a signature, not a longer candidate list. |
| ``ambiguous occurrence `foo`: it could refer to `Conflict.A.foo` or `Conflict.B.foo` `` | Two imports export the same name. Qualify one (`import Conflict.A as A`) or `hiding`-list it at one site. |
| ``non-exhaustive case: missing patterns: Nothing`` | A `case` or a function's clauses do not cover every constructor. The message names the first shapes missing; the integer variants add ``; add a catch-all (_) pattern``. |
| `lambda parameters must be variables` | A lambda may not destructure: `\(a, b) -> …` is a `case` in the body instead. |

### Records and patterns

| Message | What it means |
|---|---|
| ``missing field `age` in record construction`` | Construction is strict: every field must be given (`User { name = "a", age = 0 }`). |
| ``record type `User` has no field `missing` `` | The field is not one this record declares. |
| ``conflicting definitions for `x` `` | A pattern binds one name twice (`f x x = …`); rename one. |
| ``duplicate binding `x` in the same group`` | Two bindings of the same name in one `let`/`where` group. |

### Classes and instances

| Message | What it means |
|---|---|
| ``duplicate method `check` in instance for `Check` `` | Consecutive equations of one method are clauses of one binding and merge; this means the method is *repeated* (another method sits between the two), so the instance declares it twice. |
| ``missing superclass instance: `Eq Foo` `` | `Ord Foo` (say) requires `Eq Foo`, and that instance is not in scope. |
| ``instance for `Counter` implements none of the alternatives its MINIMAL pragma requires: count \| countUp`` | `{-# MINIMAL #-}` states what an instance must implement. Implementing none of the alternatives is an error, not a warning — the defaulted pair would form a cycle that diverges at run time. |
| ``duplicate instance for `Eq Foo` `` | The same instance is declared twice, here or in `deriving`. |

### Types that never finish

| Message | What it means |
|---|---|
| ``infinite type: `a` occurs in itself`` | The program asked `a` to equal a type containing `a` (`f = \x -> x x`). |
| ``kind mismatch applying `OnlyColor`: expected `Color`, got `Bool` `` | A promoted data constructor or a higher-kinded type was applied to the wrong kind. Kind errors always name both sides. |
| `` `Nat` is a kind, not a type `` | A promoted kind was used where a type belongs. |

### Calling the host platform

| Message | What it means |
|---|---|
| ``foreign import backend `jvm` does not match compile backend `php` `` | A `foreign` declaration belongs to one backend; compile for that one, or move the declaration into that backend's module. |
| ``foreign type `Path` is declared for backend `jvm` but used with `dotnet` `` | A foreign type may only appear in a signature of its own backend. |
| `` `Maybe` cannot be an argument of a foreign call — only a result is converted; pass the payload instead `` | Only a *result* is converted, so a container argument has no marshalling to use ([ffi.md §3](ffi.md#3-what-a-signature-may-say)). |
| `` `[a]` cannot be an argument of a jvm foreign call — only php's array is the same value as a Moggi list `` | The same rule for lists, which are the host's own array on PHP alone. |
| ``foreign const import requires static class path (`Class:NAME`)`` | A `foreign const` needs a class-qualified path, not a bare name. |
| `instance foreign import requires handle as first argument` | An instance-style path (`Class.member`) takes its receiver as the first Moggi argument. |

### Where the exact text lives

Every message above has a fixture in `tests/` — the lexer's two
`unterminated` messages are the one exception, since nothing exercises them —
and the sibling `.err.expected` file *is* the rendered diagnostic, byte for
byte, next to the smallest program that produces it. Examples: `tests/semantics/Bad-Record-Missing-Field.mog`,
`Bad-Minimal-Unsatisfied.mog`, `bad-import-conflict/Main.mog`,
`syntax/parser/bad-parse/Paren.mog`, `foreign/bad/Maybe-Argument.mog`. When a
message changes, that golden changes with it — which is what keeps this page
honest. `runtest syntax semantics` runs them.

## Uncaught exceptions

```text
moggi: ErrorCall: mapped
  at Control.Exception.Base.throwIO (lib/Control/Exception/Base.mog:43:13)
  at Main.boom (tests/backend/runtime/Exec-Exception-Trace.mog:5:8)
  at Main.indirect (tests/backend/runtime/Exec-Exception-Trace.mog:8:12)
  at Main.main (tests/backend/runtime/Exec-Exception-Trace.mog:13:3)
```

* **Header** — `moggi: <Tag>: <message>`. An `IOException` also names its
  subtype: `moggi: IOException (NoSuchThing): gone`.
* **Frames** — `  at <Module.Function> (<path>:<line>:<col>)`, innermost first.
  The named function is the one *executing*, and the location is the call it
  was making. Paths are project-relative, so a report never leaks the build
  machine's directory layout.
* **Cap** — at most 50 frames per section; the remainder is summarized as
  `  ... N more`.

The report goes to stderr and the process exits non-zero, on all three
backends. Nothing is buffered into stdout, so program output stays parseable.

## Native failures

When the failure originates in the host, the native throwable is reported
underneath the Moggi path — and the *Moggi* part is identical on all three
backends, because it comes from the same source map:

```text
php     moggi: HostException: Division by zero
jvm     moggi: HostException: / by zero
dotnet  moggi: HostException: Attempted to divide by zero.
  at Main.boom (tests/backend/runtime/Exec-Exception-HostFrame.mog:10:8)
  at Main.main (tests/backend/runtime/Exec-Exception-HostFrame.mog:13:8)
caused by: DivisionByZeroError: Division by zero          # php
caused by: java.lang.ArithmeticException: / by zero       # jvm
caused by: System.DivideByZeroException: Attempted …      # dotnet
  #0 intdiv (tests/backend/runtime/Exec-Exception-HostFrame.php:15)
```

* **`caused by:` is unconditional.** It is never gated behind an environment
  variable, because a production failure cannot be re-run with a diagnostic
  switch turned on.
* **Host frames are raw and prefixed `#N`**, so they can never be mistaken for
  `.mog` locations. They are unfiltered — including any Moggi runtime frames,
  which is what makes them useful as evidence. Their location is whatever the
  host can supply: `file:line` on PHP and the JVM, `il:<offset>` on .NET (the
  current toolchain cannot emit a PDB).
* **Moggi runtime frames are suppressed in the Moggi section only.** They are
  the same constant noise between the throw and your code; they remain visible
  in the host section.
* **A rethrow keeps its origin.** `catch e (throwIO :: SomeException -> IO ())`
  reports where the exception was originally thrown, not the catch site.

## Precision and limits

| Backend | Debug information used | Exact to |
|---------|------------------------|----------|
| PHP | the module's own `const __MOGGI_MAP`, read out of the loaded module when a report needs it | column |
| .NET | compile-time IL offsets, baked into the frames table | column |
| JVM | `LineNumberTable`, baked into the frames table | line |

* **JVM traces are line-exact, not column-exact.** The JVM debug format carries
  no column, so two calls on one line are indistinguishable there.
* **Nothing is decoded on the happy path.** A PHP module is not re-read, no JSON
  map is parsed and no frame table is built until something is reported — a
  program that never throws pays nothing for its maps.
* **Inlined functions can disappear.** Host JITs inline small functions; when a
  Moggi function is inlined into its caller, its frame is *missing*, not wrong.
  PHP has no inlining and always shows the full path. A trace is therefore a
  picture of the code that was *not* inlined — the normal behaviour of any stack
  trace taken on a JIT.
* **Unmappable frames are dropped, never guessed.** A host frame with no Moggi
  location is omitted from the Moggi section (it stays in the host section)
  rather than rendered as `<unknown>`.

## Tests

Report fixtures are `tests/backend/runtime/Exec-Exception-*.mog`; compile
diagnostics live under `tests/semantics` (`Bad-*`, `bad-*`) and `tests/syntax`.
Most report fixtures share one golden across backends; where the host output
legitimately differs (exception class names, native paths) the fixture carries a
`<name>.<backend>.stderr.expected` override. See [testing.md](development/testing.md).
