---
title: "Using libraries from PHP, the JVM and .NET"
---

# Using libraries from PHP, the JVM and .NET

Moggi compiles to a host — PHP, the JVM or .NET — and a `foreign` declaration
is how a program calls that host's own code (the declaration rules live in
[ffi.md](ffi.md)). This page is about the next step: calling a *library* that
lives in the host's ecosystem — a Composer package on PHP, a Maven artifact on
the JVM, a NuGet package on .NET.

The shape of the work is the same on all three backends:

1. **Declare** the package in your descriptor with `moggi add`.
2. **Call** it with ordinary `foreign` declarations.
3. **Build** with `moggi build`, which fetches the package with the host's own
   tool and folds it into your artifact.

A `foreign` import always belongs to one backend, so a program that uses a
host library builds for that backend. (For a program that must run on all
three, see [“one module per backend”](ffi.md#9-a-module-that-must-work-on-every-backend).)

## Two kinds of dependency

`moggi add` has two halves, and the spelling decides which one you get:

| You write | It means | It is recorded in |
|---|---|---|
| `moggi add json` | a package of ours, from the Moggi registry | `[dependencies]` |
| `moggi add php:monolog/monolog:^3.0` | a Composer package | `[php] composer` |
| `moggi add jvm:org.slf4j:slf4j-api:2.0.16` | a Maven artifact | `[jvm.maven]` |
| `moggi add dotnet:Serilog:4.1.0` | a NuGet package | `[dotnet.nuget]` |

A bare name is always a package of ours; a host package always carries the
`php:`, `jvm:` or `dotnet:` prefix. Nothing is guessed from the shape of the
name, so leaving the prefix off is an error that names the fix:

```text
error: `guzzlehttp/guzzle` is not a package name (...) — for a Composer package say `php:guzzlehttp/guzzle`
```

A host coordinate may leave the version out when its ecosystem allows it
(`jvm:group:artifact` plus `--version VERSION`, since Maven needs one; a bare
`dotnet:Id` floats). Giving the version twice — in the coordinate *and* in
`--version` — is refused rather than resolved by precedence.

## Add it

`moggi add` records the requirement *and* makes it real: for a host package it
resolves the whole coordinate set through the ecosystem's own tool *before* it
touches your descriptor, so a missing tool or a nonexistent artifact leaves the
descriptor exactly as it was. `--print` shows the edit and stops — nothing
fetched, nothing written:

```bash
moggi add --print php:monolog/monolog:^3.0
#   + [php]
#   + composer = monolog/monolog:^3.0
#
# nothing written (--print)

moggi add --print jvm:org.slf4j:slf4j-api:2.0.16
#   + [jvm.maven]
#   + org.slf4j:slf4j-api = 2.0.16
#
# nothing written (--print)

moggi add --print dotnet:Serilog:4.1.0
#   + [dotnet.nuget]
#   + Serilog = 4.1.0
#
# nothing written (--print)
```

Without `--print`, the same commands write those lines into your descriptor
(the file is named after your package, e.g. `demo-php.moggi`). A second
coordinate joins the table its ecosystem owns — a further Composer package is
appended to the `composer` list, a further Maven or NuGet coordinate gets its
own key — and re-adding the same requirement is a no-op.

One more rule: your package must claim the backend first. A package whose
`[package] backends` says `php` refuses a `jvm:` coordinate, because no build
of it would ever resolve that table:

```ini
[package]
name = demo-php
version = 0.1.0
backends = php
```

```text
error: demo-php does not declare the `jvm` backend ([package] backends = php) — add it there first, or add for one of those
```

## Build it

`moggi build` reads your descriptor, resolves the host coordinates with the
host's own tool (`composer install`, `mvn dependency:copy-dependencies`,
`dotnet restore`), and hands the resolved tree to the compile as one more
library root. Resolved jars are merged into your `.jar`; resolved assemblies
are staged beside your `.dll`; a Composer `vendor/` tree is copied into your
`.phar` with its autoloader. Then run the artifact the way you always do
([quickstart.md](quickstart.md)):

| Backend | Build | Run |
|---|---|---|
| `php` | `moggi build` → `app.phar` | `php app.phar` |
| `jvm` | `moggi build --backend jvm` → `app.jar` | `java -jar app.jar` |
| `dotnet` | `moggi build --backend dotnet` → `app.dll` | `dotnet app.dll` |

`moggi run <dir> --backend <B>` compiles and runs in one step when you are
iterating. You need the host toolchain for the backend you build — PHP 8.5.12+
and Composer, JDK 21+ and Maven, or the .NET 8 SDK — or a distribution that
bundles them.

## PHP: logging with Monolog, HTTP with Guzzle

Declare both Composer packages:

```bash
moggi add php:monolog/monolog:^3.0
moggi add php:guzzlehttp/guzzle:^7.0
```

```ini
[php]
composer = monolog/monolog:^3.0, guzzlehttp/guzzle:^7.0
```

Call them with ordinary `foreign php` declarations. A constructor is
`Class:__con`; an instance method is `Class.member` with the receiver as the
first argument; a call with side effects returns `IO`:

```moggi
module Main

foreign php type Logger "Monolog\\Logger"
foreign php type StreamHandler "Monolog\\Handler\\StreamHandler"
foreign php function newLogger "Monolog\\Logger:__con" :: String -> Logger
foreign php function newHandler "Monolog\\Handler\\StreamHandler:__con" :: String -> StreamHandler
foreign php function addHandler "Monolog\\Logger.pushHandler" :: Logger -> StreamHandler -> Logger
foreign php function info "Monolog\\Logger.info" :: Logger -> String -> IO ()

foreign php type Client "GuzzleHttp\\Client"
foreign php type Response "Psr\\Http\\Message\\ResponseInterface"
foreign php type Body "Psr\\Http\\Message\\StreamInterface"
foreign php function newClient "GuzzleHttp\\Client:__con" :: Client
foreign php function get "GuzzleHttp\\Client.get" :: Client -> String -> IO Response
foreign php function responseBody "Psr\\Http\\Message\\ResponseInterface.getBody" :: Response -> Body
foreign php function bodyText "Psr\\Http\\Message\\StreamInterface.getContents" :: Body -> String

logger :: Logger
logger = addHandler (newLogger "demo") (newHandler "php://stdout")

main :: IO ()
main = do
  info logger "hello from Moggi"
  response <- get newClient "https://example.com"
  putStrLn (bodyText (responseBody response))
```

```bash
moggi build          # -> app.phar, with vendor/ carried inside
php app.phar
```

A known object gets a `foreign php type`, never `PHPValue` — reification is
for structurally open values such as `mixed`, as [ffi.md](ffi.md#6-php-the-special-case-platformphp)
describes. And if the package is not installed yet, the compiler says so
instead of guessing: compiling the declarations above with no Composer tree
present fails with ``php has no class or interface `Monolog\\Logger` ``. Run
`moggi build` (or `moggi add`) first so the tree exists.

## JVM: logging with SLF4J, HTTP with the JDK client

The logger comes from Maven; the HTTP client below is the JDK's own
`java.net.http`, so it needs declarations but no coordinate:

```bash
moggi add jvm:org.slf4j:slf4j-api:2.0.16
```

```ini
[jvm.maven]
org.slf4j:slf4j-api = 2.0.16
```

```moggi
module Main

foreign jvm type Logger "org.slf4j.Logger"
foreign jvm function loggerFor "org.slf4j.LoggerFactory:getLogger" :: String -> Logger
foreign jvm function info "org.slf4j.Logger.info" :: Logger -> String -> IO ()

foreign jvm type HttpClient "java.net.http.HttpClient"
foreign jvm function newClient "java.net.http.HttpClient:newHttpClient" :: HttpClient

main :: IO ()
main = do
  info (loggerFor "demo") "hello from Moggi"
  putStrLn "http client ready"
```

```bash
moggi build --backend jvm   # -> app.jar, with the resolved jars merged in
java -jar app.jar
```

Two things JVM newcomers meet here. First, Moggi's `Int` is 64-bit and Java's
`int` is not: a method returning `int` is declared `Int32` and converted with
`fromIntegral`, exactly as in the `parseInt` example in [ffi.md](ffi.md#7-jvm).
Second, `slf4j-api` alone logs nowhere — it is the logging *facade*; add a
binding such as `jvm:org.slf4j:slf4j-simple:2.0.16` (the same spelling, one more
key in `[jvm.maven]`) if you want the lines on stderr.

The declaration is checked against the host wherever it can be asked: a member
the class does not have, or an argument count the host cannot accept, is a
compile error naming the host's own descriptor — for example,
``jvm class `java.lang.String` has no member `toUpperCaseX` ``. A class the
check cannot read (no JDK here, or a name that lives in the carried jar tree)
is left alone rather than reported.

## .NET: logging with Serilog, HTTP with HttpClient

The logger comes from NuGet; `HttpClient` is the framework's own, so again it
needs declarations but no coordinate:

```bash
moggi add dotnet:Serilog:4.1.0
```

```ini
[dotnet.nuget]
Serilog = 4.1.0
```

```moggi
module Main

foreign dotnet type Logger "Serilog.Core.Logger"
foreign dotnet type LoggerConfiguration "Serilog.LoggerConfiguration"
foreign dotnet function newLoggerConfig "Serilog.LoggerConfiguration:__con" :: LoggerConfiguration
foreign dotnet function createLogger "Serilog.LoggerConfiguration.CreateLogger" :: LoggerConfiguration -> Logger
foreign dotnet function info "Serilog.Core.Logger.Information" :: Logger -> String -> IO ()

foreign dotnet type HttpClient "System.Net.Http.HttpClient"
foreign dotnet function newClient "System.Net.Http.HttpClient:__con" :: HttpClient

main :: IO ()
main = do
  info (createLogger newLoggerConfig) "hello from Moggi"
  putStrLn "http client ready"
```

```bash
moggi build --backend dotnet   # -> app.dll, with the resolved assemblies staged beside it
dotnet app.dll
```

Serilog only emits where a sink tells it to — a bare `CreateLogger()` builds a
working logger with nowhere to write, so follow Serilog's own documentation to
add a sink (for example `WriteTo.Console()`) when you want output. The
declaration shape stays the one above; only the configuration call is added.
Also note the one current limit, stated plainly in [ffi.md](ffi.md#11-the-declaration-is-checked-against-the-host):
`dotnet` declarations are not yet checked against the host, and a declaration
can need CLR detail — the defining assembly, value-typeness, the real
parameter list of a method with optional parameters — that a package carries
beside its sources. If a .NET call compiles but fails at run time, that detail
is the first place to look.

## What was checked while writing this

Honestly labelled, so a failure on your machine means something:

* **Ran here:** `moggi add --print` for every coordinate on this page
  (`php:monolog/monolog:^3.0`, `php:guzzlehttp/guzzle:^7.0`,
  `jvm:org.slf4j:slf4j-api:2.0.16`, `dotnet:Serilog:4.1.0`), showing exactly
  the edits quoted above; parsing (`compile --ast`) of each page's module;
  `moggi check` on a scratch package; `moggi run examples/host-php`; and the
  full compile of a Monolog declaration with no Composer tree present, which
  produced the `php has no class or interface` error quoted above.
* **Illustrative — not run here:** fetching the packages (`composer install`,
  `mvn`, `dotnet restore` — none of those tools are on this machine),
  compiling for `jvm`/`dotnet` (no JDK or .NET SDK here), and executing any of
  the three example programs against the real libraries. The declaration
  spellings follow the same rules as the in-tree `host-php`/`host-jvm`/
  `host-dotnet` examples and the `foreign` reference, but the exact upstream
  APIs — Monolog's handler methods, Guzzle's response shape, Serilog's sink
  configuration — are the libraries' documentation's word, not a run on this
  machine.
