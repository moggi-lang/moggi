---
title: "Calling the host platform"
---
# Calling the host platform

Moggi compiles to PHP, the JVM or .NET. A `foreign` declaration is how a Moggi
program reaches that host's own functions, methods and classes: you name the
host member and give it a Moggi type. There is no wrapper layer — the
compiler emits the call directly. The declarations themselves can be written
by hand or read off the host by a binding generator (§12); either way what
reaches the compiler is the same `foreign` declaration.

PHP is the one host that needs something the compiler cannot supply, because
its `mixed` has no type to write down. That support is a library module:
`Platform.PHP`, the single FFI-specific module in `libraries/` (§6).

Because the host is chosen at compile time, **a foreign import belongs to one
backend**, and the language tells you when you got that wrong:

```moggi
module Main where

foreign jvm function absOf "java.lang.Math:abs" :: Int -> Int
```

```
Main.mog:3:22: type error: foreign import backend `jvm` does not match compile backend `php`

Did you mean this?
    php
```

## 1. The three declarations

```moggi
foreign <backend> function <name> "<host path>" :: <type>
foreign <backend> const    <name> "<host path>" :: <type>
foreign <backend> type     <Name>       "<host type>"
```

* `function` — a host function, method, constructor or static accessor.
* `const` — a host constant: a global constant (`PHP:PHP_VERSION`) or a static
  field (`java.io.File:separator`). It takes no arguments and may not return
  `IO`.
* `type` — a nominal Moggi type standing for one host class. Values of it can
  only come from a host call; Moggi cannot construct, match or derive on it. On
  PHP the path is a label only — it never reaches the emitted code, which is
  why `"mixed"` is a legal path there (§6).

`<backend>` is `php`, `jvm` or `dotnet`. `<name>` joins the module's own
namespace like any other definition — export it if other modules should see it.

## 2. Host paths

| Path form | What it calls | Example |
|---|---|---|
| `name` | a plain host function (PHP only) | `"strlen"` |
| `Class:member` | a static method (or `Class::MEMBER`, same thing) | `"java.lang.Integer:parseInt"` |
| `Class.member` | an instance method — **the receiver is the first argument** | `"java.lang.String.toUpperCase"` |
| `Class:__con` | a constructor; the arguments are the constructor's, and the result is the value | `"DateTime:__con"` |
| `Class:__cast` | re-types a value the host already returned — a **reference cast**, emitted (no call) | `"java.lang.CharSequence:__cast"` |
| `Class:CONST` | a constant/static field (`const` declarations) | `"PDO::ATTR_CASE"` |
| `PHP:CONST` | a PHP global constant | `"PHP:PHP_VERSION"` |

Class names are written the host's way, with dots: `java.lang.Math`,
`System.Text.StringBuilder`; PHP names may use backslashes
(`"Some\\Library\\Client:__con"`). The member is the last segment; a run
of `:` counts as one separator.

`__cast` is how a value becomes an interface-typed foreign type, which a
constructor cannot always give you:

```moggi
foreign jvm type Chars "interface java.lang.CharSequence"
foreign jvm function asChars "java.lang.CharSequence:__cast" :: String -> Chars
foreign jvm function charsLen "java.lang.CharSequence.length" :: Chars -> Int32
```

```
5
```

(.NET also has `Class:__default` and `Class:__null` for a valuetype's default
value and a typed null; those exist because the CLR needs them.)

A JVM class that is really an interface must say so, or the call is emitted as
`invokevirtual` and the class loader rejects it:

```moggi
foreign jvm type Chars "interface java.lang.CharSequence"
foreign jvm function newChars "java.lang.StringBuilder:__con" :: Chars
foreign jvm function seqLen "java.lang.CharSequence.length" :: Chars -> Int32
```

```
0
```

## 3. What a signature may say

Every type in a foreign signature is checked. Legal: primitives (`Int`, `Int8`
… `Int64`, `Word…`, `Integer`, `Double`, `Bool`, `Char`, `String`), `()`, a
foreign type declared for the same backend, `Handle`/`Resource`, `IOMode`,
`PHPValue` — but only in a `foreign php` declaration (§6) — and the container
types below. `IO` may appear only outermost in the result.

A container's *position* decides whether it is legal, because only a result is
converted:

* `Maybe`, `Either`, `Tuple` — **result only**. As an argument there is nothing
to unwrap, so the host would receive the constructor:

  ```
  Main.mog:3:22: type error: `Maybe` cannot be an argument of a foreign call — only a result is converted; pass the payload instead
  ```

* `[a]` — a *php* declaration may take one, because there a Moggi list **is** a
  PHP array (that is how `implode`/`array_combine` are declared). On the JVM and
  .NET it is the runtime's own list, not the host's array type, so it is
  rejected rather than failing in the host at run time:

  ```
  Main.mog:3:22: type error: `[a]` cannot be an argument of a jvm foreign call — only php's array is the same value as a Moggi list; call it element by element or declare the host's own type
  ```

Not legal: a type variable, a type of your own, and an `IO` argument.

```
Main.mog:3:22: type error: type `Colour` is not legal in a foreign signature; use a specific foreign type, a primitive, or PHPValue for reification
```

A declaration is **checked against the host it names**, where the host can be
asked at compile time (§11): a member the host does not have, a member of the
wrong kind, and an argument count the host cannot accept are compile errors
today. What the compiler can still not promise is that the host *behaves* as the
signature says — nothing at compile time can, and a `NULL` the host returns is
still a `NULL`. Two consequences worth internalising:

* Match the host's width. Moggi's `Int` is 64-bit, Java's `int` is not, so a
  method returning `int` is `Int32` and needs `fromIntegral` to become `Int`:

  ```moggi
  foreign jvm function parseInt "java.lang.Integer:parseInt" :: String -> Int32

  answer :: Int
  answer = fromIntegral (parseInt "42") + 1
  ```

  This one is no longer left to the reader: `java.lang.Integer:parseInt`
  carrying `Int` where the host declares `(Ljava/lang/String;)I` is a compile
  error naming both descriptors (§11).

* An argument must be something the host can receive: a primitive, `String`, a
  foreign type, or a `Handle`. Containers are for results.

## 4. Results that are not plain values

`Maybe`, `Either`, lists and `Handle` in result position get a defined
conversion, so a host API's failure convention becomes a Moggi value instead of
a surprise.

```moggi
foreign php function rawGetenv "getenv" :: String -> IO (Maybe String)
foreign php function rawGetFile "file_get_contents" :: String -> IO (Either String String)
```

* `IO (Maybe a)` — a `false`/`null` result becomes `Nothing`, anything else
  `Just` it.
* `IO (Either String a)` — a host failure **thrown** during the call becomes
  `Left "Class: message"`; a returned value becomes `Right`.
* A result declared `Handle` is boxed as the portable handle.

A host function that reports failure by *returning* `false` rather than throwing
is what `Maybe` is for: `Right` never inspects the value, so declaring
`file_get_contents` as `Either String String` gives you `Right false` on a
missing file — a type-correct lie you asked for.

## 5. When the host fails

A host error that Moggi does not classify arrives as `HostException backend
nativeType message` (`Control.Exception` re-exports it), with the native
throwable in the report's `caused by:` section and the Moggi frame that made the
call:

```moggi
foreign php function f "strlen" :: Maybe Int -> Int

main = putStrLn (show (f (Just 1)))
```

```
moggi: HostException: strlen(): Argument #1 ($string) must be of type string, array given
  at Main.main (Main.mog:5:8)
caused by: TypeError: strlen(): Argument #1 ($string) must be of type string, array given
  #0 Main\f (Main.php:16)
```

Classification into `IOException` is the library's job, because only the library
knows what path an operation used — `System.IO` catches a `HostException` from a
file operation and re-raises the `IOError` its callers expect.

## 6. PHP: the special case (`Platform.PHP`)

The JVM and .NET story ends at §2: their members are typed, so a declaration is
all there is to it. PHP has `mixed` — a host value with no type Moggi can write
down — and that needs support a declaration cannot give. The support is a
library module instead of more compiler machinery: **`Platform.PHP` is the one
module in `libraries/` whose entire reason to exist is the FFI.**

Two levels cover the boundary; use the weaker one that still works.

**A `mixed` type of your own.** `"mixed"` is a legal host path in a
`foreign php type` declaration, and it is how the standard library names “any
PHP value” when it merely has to carry one around:

```moggi
foreign php type Encoding "mixed"        -- Data.JSON.Encoding.PHP
```

The type is nominal: you can pass and return it, but not build, match or derive
on it, and there is nothing to inspect. On PHP the path of a foreign *type*
never appears in the emitted code — the value is already whatever the host
produced — so `"mixed"` is as good a label as any class name, and the compiler
checks neither.

**`Platform.PHP`, when you do need to look inside.** It declares `PHPValue` —
an ordinary Moggi ADT, not a host handle — together with `PhpError` and the
helpers for walking one. `PHPValue` is the single type the compiler
special-cases in a `foreign` signature: a host value crossing back is boxed into
it by the runtime, at the boundary you chose.

```moggi
module Main where

import Platform.PHP

foreign php type DateTime "DateTime"

foreign php function rawStrlen "strlen" :: String -> Int
foreign php function makeDate "DateTime::createFromFormat" :: String -> String -> DateTime
foreign php function formatDate "DateTime.format" :: DateTime -> String -> String
foreign php const phpVersion "PHP:PHP_VERSION" :: String
foreign php function jsonDecode "json_decode" :: String -> PHPValue

main :: IO ()
main = do
  putStrLn (show (rawStrlen "hello"))
  putStrLn (formatDate (makeDate "Y-m-d" "2026-09-21") "d/m/Y")
  putStrLn phpVersion
  case jsonDecode "{\"n\": 3, \"ok\": true}" of
    PhpObject entries -> case lookupStringPHPValue "ok" entries of
      Just (PhpBool ok) -> putStrLn ("ok=" <> show ok)
      _                 -> putStrLn "no ok"
    _ -> putStrLn "not an object"
```

```
5
21/09/2026
8.5.7
ok=True
```

The type is `php`-only. Naming `PHPValue` in a `foreign jvm` or `foreign dotnet`
declaration is a compile error, because nothing on those backends boxes a host
value into it:

```
Main.mog:3:22: type error: `PHPValue` reification is only available on the php backend, but it is used with `jvm`
```

Its shape:

```moggi
data PHPValue
  = PhpNull | PhpBool Bool | PhpInt Int | PhpDouble Double
  | PhpString String | PhpArray [PHPValue]
  | PhpObject [MapEntry String PHPValue]
  | PhpResource Resource
```

`Platform.PHP` also supplies the small helpers for walking one:
`lookupStringPHPValue`, `indexIntList`, `pushIntList`, `lookupStringIntMap`,
`insertStringIntMap`, over `PHPList`/`PHPMap`/`MapEntry`. Its `PhpError` carries
a message for reified PHP diagnostics.

Rules for the boundary:

* **A precise type beats reification.** If the host API is precise, declare the
  precise type (`getenv` is `String -> IO (Maybe String)`, not `PHPValue`).
* **A known object is a `foreign php type`**, never `PHPValue`. A Composer
  client is `foreign php type Client "Some\\Library\\Client"`; only values that
  are *structurally* open — `mixed`, or an array of them — go through
  `PHPValue`.
* **A `PHPValue` receiver is refused** (``PHPValue cannot be an instance-method
  receiver``): an instance call needs a real host object.
* Reification refuses an opaque object rather than fake a tree — an unknown
  class instance does not silently become `PhpObject`.

Other PHP specifics:

* A `[ByteString]`/`[String]` argument *is* a PHP array — the one backend where a
  list argument is legal (§3) — so PHP's own list-taking functions (`implode`,
  `array_combine`, `array_flip`) are declared directly.
* A call is emitted with `@`: a host diagnostic is not a value, and the function
  that wants to report one reads it itself (`error_get_last`).
* `IO (Maybe String)` from a member whose name contains `fgets` also maps an
  empty line to `Nothing` and strips the trailing newline — that is how
  `hGetLine` is built.

## 7. JVM

```moggi
module Main where

import Data.Int

foreign jvm function upper "java.lang.String.toUpperCase" :: String -> String
foreign jvm function parse "java.lang.Integer:parseInt" :: String -> Int32
foreign jvm function maxI "java.lang.Math:max" :: Int -> Int -> Int
foreign jvm const pathSep "java.io.File:separator" :: String

main :: IO ()
main = do
  putStrLn (upper "hi")
  putStrLn (show (parse "43"))
  putStrLn (show (maxI 3 9))
  putStrLn pathSep
```

```
HI
43
9
/
```

Method descriptors are inferred from the Moggi types, so the path carries no
signature. Instance methods take the receiver first (above, `upper "hi"` calls
`"hi".toUpperCase()`), and a constructor's Moggi result is the object it built.

## 8. .NET

```moggi
module Main where

import Data.Int

foreign dotnet type StringBuilder "System.Text.StringBuilder"

foreign dotnet function upper "System.String.ToUpper" :: String -> String
foreign dotnet function parse "System.Int32:Parse" :: String -> Int32
foreign dotnet function maxI "System.Math:Max" :: Int -> Int -> Int
foreign dotnet function newSb "System.Text.StringBuilder:__con" :: StringBuilder
foreign dotnet function append "System.Text.StringBuilder.Append" :: StringBuilder -> String -> StringBuilder
foreign dotnet function render "System.Text.StringBuilder.ToString" :: StringBuilder -> String

main :: IO ()
main = do
  putStrLn (upper "hi")
  putStrLn (show (parse "43"))
  putStrLn (show (maxI 3 9))
  putStrLn (render (append (append newSb "foo") "bar"))
```

```
HI
43
9
foobar
```

## 9. A module that must work on every backend

The standard library never puts a `foreign` import in a portable module. It
splits in three:

```moggi
{-# BACKEND                             -- one implementation module per backend
  php    = System.IO.PHP
  jvm    = System.IO.JVM
  dotnet = System.IO.DotNet
#-}
module System.IO (... ) where          -- the facade: signatures only

openFile :: FilePath -> IOMode -> IO Handle
```

Each implementation module holds that backend's `foreign` declarations and its
bodies; the facade is what users import. The library modules that already work
this way are the ones to copy — `System.IO`, `System.Directory`,
`System.Environment`, `System.Exit`, `Data.String`, `Data.Char`, `Data.Int`,
`Data.ByteString`, `Data.Time.Clock` and `Data.JSON.Encoding`.

`Platform.PHP` is the one member of the library that is *not* split this way,
because it is not portable code behind a facade — it is the PHP boundary
itself, so importing it makes a module PHP-only. `System.IO.PHP` and
`Data.JSON.Encoding.PHP` are the two users to look at.

Testing follows from the same fact: a module with a `foreign` import only
compiles for its backend, so its fixture is backend-qualified
(`Foo.php.stdout.expected`) and the other backends skip it.

## 10. What the FFI does not do

* **No argument marshalling for containers — the compiler says so instead.**
  `Maybe`, `Either` and `Tuple` are results only; a `[a]` argument is a php-only
  privilege (§3). Before this was a rule, the mistake reached the host: a host
  method wanting `string[]` failed with `HostException: Method not found: '…
  Join(Char, Moggi.Rt.MList)'`. Call it element by element, or reach the host
  through a declared foreign type.
* **No polymorphic or higher-order signatures.** A foreign type is a fixed host
  class; there is no `Object`/`object` fallback, and a type variable is not
  allowed in a signature.
* **No host subtyping.** Two foreign types never unify, and a call through an
  interface needs that interface declared (JVM).
* **A declaration is checked against the host, not against the host's
  behaviour.** The compiler can ask whether `java.util.Iterator.next` exists and
  what descriptor it really has (§11); it cannot ask what the method does with
  the value, whether the result is `null`, or whether a framework changes under
  the same name. A host failure at run time is still the host's own failure.

## 11. The declaration is checked against the host

A `foreign` declaration is a claim about another language's API, and the
compiler checks it wherever the host can be asked at compile time. The check is
about the claim, never about behaviour: it can say the member exists with the
descriptor this import will emit, and it can say it does not.

| Backend | How the host is asked | What is checked |
|---|---|---|
| `php` | the compiler's own process (`function_exists`, reflection) | the function/class/method/constant exists, a `Class:member` path names a *static* method, `Class.__con` an instantiable class, and the declared arity can be satisfied |
| `jvm` | `javap -s -p` on the class the path names, from the JDK the build compiles with | the member exists on that class, is public, and has exactly the descriptor the emitter will use — so a wrong name, a wrong argument count and a wrong type are all compile errors. A class `javap` cannot find is a verdict too (``jvm class `X` was not found on the compile classpath``), and a missing `javap` refuses every `foreign jvm` declaration with an error naming it |
| `dotnet` | a reflection inspector built from shipped C# source over the compile's reference set (vendored assemblies plus the target framework) | the emitted signature matches a member the CLR declares — a missing class, member, or signature is a compile error naming the host. The sidecar cross-check still applies first; assembly scopes are ignored in the comparison because the runtime resolves across forwarding. No `dotnet` refuses the declaration; a broken inspector warns and leaves it unverified |

The name in the diagnostic is always the *host's*, and the descriptor it prints
is the one codegen would put on the wire:

```moggi
foreign jvm function rawLength "java.lang.String.length" :: String -> Int
```

```
Main.mog:1:22: type error: jvm member `java.lang.String.length` has no signature matching `()J`; the host declares `()I`
```

Verification never invents a failure, but "cannot answer" has two shapes with
two answers:

* **A missing tool is an error.** Verifying a `foreign jvm` declaration needs
`javap`, and a machine without one cannot keep the promise the check makes —
so the declaration is refused with an error naming `javap`, not waved through.
The `javap` must be the build's own: the JDK behind `JAVA_HOME` (or
`GRAALVM_HOME`/`JDK_HOME`) answers first, and a `javap` from `PATH` is used
only with a warning naming the binary, once per compile.
* **A broken tool is a warning.** A `javap` that exits without saying the class
is missing, or prints no class at all, is a tool failure rather than a verdict:
it warns on stderr and the declaration is left alone. That path is for broken
tools, never for missing ones.

The check still declines to guess where the host genuinely cannot be asked:

* **Vendor `.java` sources.** Carried jars are on `javap`'s classpath already,
so "not found" among them is a verdict; vendor `.java` sources are compiled by
the backend and never appear on the classpath, so a class `javap` cannot find
while they are carried is accepted with a warning saying it was not verified.
(On `php`, a carried Composer tree still stands the whole check down — the
vendor lookup replaces that next.)
* **A `foreign … type` declaration on `php`.** Its path is a label that never
reaches the emitted code — `libraries/base/src/Platform/PHP.mog` names one `"mixed"`,
`libraries/base/src/System/IO/PHP.mog` one `"array"` — so there is nothing to compare.
* **A dynamic host.** `__call`/`__callStatic` make any method name legal at run
time, so a name the class does not list is not reported.

None of that is a switch, and there is none: those cases are the check declining
to guess, not a bypass. A declaration the check rejects is one whose emitted call
would fail at run time on that host, so a build that ran with verification
disabled would mean something different from what it says — and the whole value of
the check is that it is the compiler's own word.

This is also what keeps the check honest: verification is a service to the
declaration, never a hazard of its own. A verifier that breaks trying warns on
stderr and lets the compile continue; a verifier that cannot run because its
tool is missing refuses the declaration instead.

## 12. Generated bindings

Hand-written declarations are reviewed annotations: the compiler checks the
member exists, but the *types* are the author's word. A generated binding
removes the author's memory from the loop — the declarations are read off the
host's own type information instead:

```
moggi bind php:acme/hello --tree ./acme-hello --out src/
moggi bind jvm:com.acme:hello --tree ./acme-hello --out src/
moggi bind dotnet:Acme.Hello --tree ./acme-hello --out src/
```

The coordinate spelling follows `moggi add`, and the module it becomes is
prefixed by ecosystem, so three backends' bindings for "the same" library
never share a namespace:

| Coordinate | Module | Needs |
|---|---|---|
| `php:vendor/package` | `Composer.Vendor.Package` | in-process reflection over the resolved `vendor/` tree |
| `jvm:group:artifact[:version]` | `Maven.…` | the bundled JDK's `javap` over jars under a `jvm/` directory |
| `dotnet:Id[:version]` | `Nuget.…` | a .NET SDK for the CLR inspector, over assemblies under a `dotnet/` directory |

A .NET bind also writes the versioned `dotnet/dependencies.json` sidecar
beside the module — the optional tails and assembly scopes a declaration
cannot spell. Bindings are backend-specific by construction; portable code
needs a hand-written facade over one binding per backend.

What the host cannot express is omitted and reported, never guessed: each
bind ends with a coverage line (`N bound, M omitted`), and `--json` carries
every skipped symbol with its reason. A missing tool is an error naming it,
never a stand-down, and output is byte-deterministic for an unchanged host,
so a regenerated module only diffs when the host API did.

`moggi build --bind` generates from the descriptor instead of the command
line: every `[ffi] bindings` entry for the build's backend is bound from the
resolved host tree into the cache (reused while the tree is unchanged, bumpless
without `--bind`), and the generated root rides as a library root. A binding
is then an ordinary package: `moggi bind` → review the diff → `moggi pack` →
`moggi publish` → `moggi install`, and downstream builds compile the shipped
`.mog` (and sidecar) without running a host tool at all.

Signing can happen on another machine than the upload. `pack --record`
prints the signed release record instead of writing the archive,
`moggi sign` signs a record file, `publish --signed` uploads either one's
output after checking it against the packed tree byte for byte, and
`publish --dry-run` prints the payload and the requests it would travel in
without sending them. The record, the signature and the checks are one shared
path, so the three verbs cannot drift apart.
