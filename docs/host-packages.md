---
title: "Using Composer, Maven and NuGet libraries"
description: Declaring a host package, resolving it with its own tool, and building it into an artifact.
---
# Using Composer, Maven and NuGet libraries

A `foreign` declaration calls one host member ([ffi.md](ffi.md)). A *host
library* is the next step: a Composer package, a Maven artifact or a NuGet
package that the artifact has to carry.

The shape is the same on every backend:

1. **Declare** the package with `moggi add`.
2. **Call** it with ordinary `foreign` declarations.
3. **Build** with `moggi build`, which resolves the package with the host's own
   tool and folds the result into your artifact.

A `foreign` import belongs to one backend, so a program using a host library
builds for that backend. For a program that must run on all three, keep the
calls in one implementation module per backend behind a facade
([ffi.md §9](ffi.md#9-a-module-that-must-work-on-every-backend)).

## Two kinds of dependency

The spelling decides which ecosystem a name belongs to:

| You write | It means | Recorded in |
|---|---|---|
| `moggi add json` | a package from the Moggi registry | `[dependencies]` |
| `moggi add php:monolog/monolog:^3.0` | a Composer package | `[php] composer` |
| `moggi add jvm:org.slf4j:slf4j-api:2.0.16` | a Maven artifact | `[jvm.maven]` |
| `moggi add dotnet:Serilog:4.1.0` | a NuGet package | `[dotnet.nuget]` |

A bare name is always a Moggi package. A host coordinate without its prefix is
refused with a message that names the fix:

```text
error: `guzzlehttp/guzzle` is not a package name (...) — for a Composer package say `php:guzzlehttp/guzzle`
```

`--print` shows the edit without writing or fetching anything:

```bash
moggi add --print php:monolog/monolog:^3.0
#   + [php]
#   + composer = monolog/monolog:^3.0
#
# nothing written (--print)
```

Without `--print` the same lines are written to your descriptor. A package must
also declare the backend it uses:

```text
error: demo-php does not declare the `jvm` backend ([package] backends = php) — add it there first, or add for one of those
```

## Build it

`moggi build` resolves the coordinates with the ecosystem's own tool
(`composer install`, `mvn dependency:copy-dependencies`, `dotnet restore`) and
then forwards the result to the compile: every Moggi root as `--lib`, and the
resolved directory as the host flag.

```bash
moggi build                                  # php, the default backend
moggi build --backend jvm
moggi build --backend dotnet
```

Because the build is only forwarding, the same compile can be written by hand:

```bash
moggi compile app/src -o app.phar --backend php    --lib-composer vendor
moggi compile app/src -o app.jar  --backend jvm    --lib-maven target/dependency
moggi compile app/src -o app.dll  --backend dotnet --lib-nuget packages
```

| Flag | The directory it names |
|---|---|
| `--lib-composer` | a Composer project whose autoloader is `vendor/autoload.php`, or that `vendor/` directory itself |
| `--lib-maven` | the directory `mvn dependency:copy-dependencies` copied its jars into |
| `--lib-nuget` | the directory of DLLs a restore resolved to |
| `--lib` | a **Moggi** module root, not a host tree; a host tree passed here is never searched for modules |

Each host flag is checked for the artifact its resolver produces before the
compile starts, so a wrong directory is refused by name instead of failing to
link later. The names follow the package manager, not the target
(`--lib-nuget`, not `--lib-dotnet`).

On .NET, a `.json` file beside a DLL is Moggi metadata: it says which assembly
defines a CLR type and what its IL signature is. The framework's own types need
no sidecar; calling a type from a third-party assembly without one fails at run
time with `TypeLoadException`.

## Run it

| Backend | Build | Run | Host toolchain |
|---|---|---|---|
| `php` | `moggi build` → `app.phar` | `php app.phar` | PHP 8.5.12+ and Composer |
| `jvm` | `moggi build --backend jvm` → `app.jar` | `java -jar app.jar` | JDK 21+ and Maven |
| `dotnet` | `moggi build --backend dotnet` → `app.dll` | `dotnet app.dll` | .NET 8 SDK |

`moggi run <dir> --backend <B>` compiles and runs in one step while iterating.

## Calling what you added

Declarations follow [ffi.md](ffi.md): `Class:__con` constructs, `Class.member`
takes the receiver first, `Class:STATIC` is static, and a call with effects
returns `IO`. The compiler checks a declaration against the host where the host
can be asked, so a misspelt member or a wrong argument count is a compile error,
not a run-time surprise.

```moggi
module Main

foreign php type DateTime "DateTime"
foreign php function newDateTime "DateTime:__con" :: String -> DateTime
foreign php function formatDate "DateTime.format" :: DateTime -> String -> String

main :: IO ()
main = putStrLn (formatDate (newDateTime "2026-09-21") "Y-m-d")
```

```text
2026-09-21
```

Only an artifact that carries the library can run such a program: a bare
`moggi compile --lib-composer` needs the resolved `vendor/` tree, and `moggi build`
is what puts it into the artifact.

## Limits

* **One backend per declaration.** A program with `foreign php` imports does not
  compile for `jvm`; split it behind a facade.
* **The toolchain is not bundled by every distribution.** A `moggi-minimal`
  install needs Composer, Maven or the .NET SDK on `PATH`
  ([quickstart.md](quickstart.md)).
* **A declaration is checked against the host's API, not its behaviour.** The
  check can confirm a member exists with the descriptor the emitter will use; it
  cannot say what the member does with a `null`
  ([ffi.md §11](ffi.md#11-the-declaration-is-checked-against-the-host)).
* **No argument marshalling for containers.** `Maybe`, `Either` and tuples are
  result-only, and a list argument is legal only on PHP
  ([ffi.md §3](ffi.md#3-what-a-signature-may-say)).
