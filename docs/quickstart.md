# Quickstart

Get something running, then change it. Nothing here needs a functional
programming background.

Moggi is a general-purpose, purely functional language with **strict**
evaluation, algebraic data types, pattern matching and type classes. You write
`.mog` modules and the compiler turns them into one deployable artifact: a PHP
`.phar`, a JVM `.jar`, or a .NET `.dll`.

## Install

Download the distribution for your platform from the releases page, unpack it,
and run it. There is nothing to install, configure or build:

```bash
tar -xzf moggi-0.1.0-linux-x86_64.tar.gz
cd moggi
./bin/moggi version
```

The distributions differ only in which runtimes they carry:

| Distribution | Bundles |
|---|---|
| `moggi` | PHP, the micro PHP runtime, .NET SDK, JDK, GraalVM — everything, including `--native` — plus Composer and Maven |
| `moggi-php` | PHP, the micro PHP runtime and Composer — the PHP backend, `--native` included |
| `moggi-dotnet` | PHP, the micro PHP runtime and the .NET SDK (whose `dotnet nuget` resolves `[dotnet.nuget]`) |
| `moggi-jvm` | PHP, the micro PHP runtime, JDK, GraalVM and Maven |
| `moggi-minimal` | nothing — uses the `php`, `javac`/`java`, `dotnet`, `composer` and `mvn` on your `PATH` |

A bundled runtime is preferred over the one on your `PATH`; anything the
variant does not bundle has to be installed yourself. Only `moggi-minimal`
needs PHP 8.5+ to be installed first. The micro PHP runtime is not on `PATH` at
all: it is the `micro.sfx` file a `--native` build on the `php` backend appends
the artifact to, so a variant without it can only build a native executable from
a runtime named by `MOGGI_MICRO_SFX`.

If you would rather run the compiler from a checkout, `php moggi.php …` does the
same thing — [development.md](development/development.md) covers that setup.

Add `bin` to your `PATH`, or call `bin/moggi` by path, and the rest of this page
applies as written.

The archives are not code-signed yet, so the two platforms that care about that
say something the first time:

```bash
# macOS: a download from a browser carries the quarantine attribute
xattr -dr com.apple.quarantine moggi

# Windows: unblock the extracted archive
Get-ChildItem -Recurse moggi | Unblock-File
```

On Windows, Windows Defender SmartScreen may also warn about an unknown
publisher — "More info", then "Run anyway". On macOS, a build that cannot be
verified is the same message. Signing is planned; the commands above are the
workaround until then.

## Hello, world

A file with no module header is `Main`, and `main` is the entry point. A file
with a header works the same way — `main` in the module you run is the entry,
so a program need not call its module `Main`:

```moggi
main = putStrLn "Hello, world!"
```

```bash
moggi run hello.mog          # compile and run (backend: php)
```

## Build an artifact

```bash
moggi compile hello.mog                        # -> hello.phar
php hello.phar

moggi compile hello.mog --backend jvm          # -> hello.jar
java -jar hello.jar

moggi compile hello.mog --backend dotnet       # -> hello.dll (+ deps/runtimeconfig)
dotnet hello.dll
```

| Backend | Default artifact | Needs | Run with |
|---|---|---|---|
| `php` (default) | `hello.phar` | PHP 8.5+ | `php hello.phar` |
| `jvm` | `hello.jar` | JDK 21+ | `java -jar hello.jar` |
| `dotnet` | `hello.dll` (+ `hello.deps.json`, `hello.runtimeconfig.json`) | .NET 8 SDK | `dotnet hello.dll` |

Without `-o` the artifact is named after the entry source file; give the full
file name to choose your own (`-o app.phar`, `-o app.jar`, `-o app.dll`).
`moggi run hello.mog --backend jvm` compiles and runs in one step.

Three flags worth knowing now:

* `--unpacked` keeps the generated tree instead of packing it into one artifact —
  the fastest way to read what your program became
  (`moggi compile hello.mog -o out --unpacked`).
* `--native` produces a standalone executable instead of an artifact that needs
  the host runtime: the micro PHP runtime for `php` (the PHAR appended to a
  statically linked PHP, so `./hello` runs with no PHP installed), GraalVM
  `native-image` for `jvm`, `dotnet publish -p:PublishAot=true` for `dotnet`.
  Slow, and the toolchain has to be installed — on `php`, a distribution that
  bundles that runtime or `MOGGI_MICRO_SFX` naming one.
* `--tokens`, `--ast`, `--typed-ast`, `--ir`, `--opt-ir` stop after the stage of
  the same name and print it ([repl.md](repl.md) shows the same stages
  interactively).

## Run the examples

`examples/` holds small programs that compile and run as they are — the fastest
way to see what Moggi code looks like when it does something:

```bash
moggi run examples/twice           # pattern matching, recursion, IO
moggi run examples/factorial       # a recursive function and `show`
moggi run examples/data-class      # algebraic data types, `deriving`, `case`
moggi run examples/SystemDemo.mog  # the Prelude and the standard library at work
moggi run examples/host-php        # calling PHP from Moggi

moggi run examples/host-jvm --backend jvm
moggi run examples/host-dotnet --backend dotnet
```

Pass a directory (`examples/twice`) or the file inside it; `moggi run` compiles
to a temporary artifact and executes it, so nothing is left behind. Each
`Main.mog` sits next to the output it is expected to produce, if you want to
compare.

## Editor

There is a VS Code extension. It is packaged as `moggi-lsp-<version>.vsix` and
attached to every release — syntax highlighting, diagnostics as you type,
completion, hover, go-to-definition and references, rename, formatting, inlay
hints, plus **Moggi: Run Current File** and a compile task. It is not on the
Marketplace yet, so install the file:

```bash
code --install-extension moggi-lsp-0.1.0.vsix
```

The extension carries no compiler; it starts the one you already have and talks
to it over the language server protocol. Point it at your installation:

| Setting | Value |
|---|---|
| `moggi.serverPath` | `<installation>/bin/moggi.phar` where PHP is bundled, `<installation>/bin/moggi` in a PHP-less variant, or `<root>/moggi.php` in a checkout |
| `moggi.phpPath` | a PHP 8.5 binary — the bundled `runtime/php/bin/php` will do; not used when `serverPath` is the native `bin/moggi` |

Any other editor works too: `moggi lsp` is a plain stdio language server.
[lsp.md](lsp.md) describes the server and the extension's commands in full.

## Next

Change a program and see what happens — that is the whole loop. When you want to
try an expression without saving a file, go to [repl.md](repl.md). To read the
language itself, start at [tour.md](tour.md).
