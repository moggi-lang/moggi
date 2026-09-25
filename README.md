<p align="center"><img src="logo.svg" alt="Moggi" width="120" height="120"></p>

# Moggi

[![.github/workflows/test.yml](https://github.com/moggi-lang/moggi/actions/workflows/test.yml/badge.svg)](https://github.com/moggi-lang/moggi/actions/workflows/test.yml)

A **statically typed, purely functional, strictly evaluated** programming language
that compiles to **PHP**, **JVM** and **.NET**. One `.mog` source — algebraic
data types, GADT's, pattern matching, type classes, explicit-effect IO — becomes a
`.phar`, a `.jar` or a `.dll` or native executable.

[YouTube Introduction](https://www.youtube.com/watch?v=hCKdsw2_0FI)

```moggi
data Shape = Circle Double | Rect Double Double
  deriving (Show, Eq)

area :: Shape -> Double
area s = case s of
  Circle r -> 3.141592653589793 * r * r
  Rect w h -> w * h

main :: IO ()
main = do
  putStrLn (show (area (Circle 1.0)))
  putStrLn (show (map area [Rect 2.0 3.0, Circle 1.0]))
```

```text
3.141592653589793
[6.0,3.141592653589793]
```

## Quick start

Download the distribution for your platform from the releases page and unpack
it — it is a complete installation, and running it needs nothing else:

```bash
tar -xzf moggi-0.1.0-linux-x86_64.tar.gz   # or the .zip on Windows
cd moggi
./bin/moggi version
```

Write a program:

```moggi
-- hello.mog
main = putStrLn "Hello, world!"
```

```bash
./bin/moggi run hello.mog                    # compile and run it
./bin/moggi run examples/factorial           # or run one of the shipped examples
./bin/moggi repl                             # try expressions interactively
```

Build an artifact you can deploy on its own:

```bash
./bin/moggi compile hello.mog                # -> hello.phar
php hello.phar

./bin/moggi compile hello.mog --backend jvm  # -> hello.jar
java -jar hello.jar

./bin/moggi compile hello.mog --backend dotnet   # -> hello.dll
dotnet hello.dll
```

| Backend | Artifact | Toolchain |
|---|---|---|
| `php` (default) | `hello.phar` | PHP 8.5+ |
| `jvm` | `hello.jar` | JDK 21+ |
| `dotnet` | `hello.dll` (+ `hello.deps.json`, `hello.runtimeconfig.json`) | .NET 8 SDK |

Which distributions bundle which runtime, and how to use a checkout instead:

| Distribution | Bundles |
|---|---|
| `moggi` | PHP, .NET SDK, JDK, GraalVM — everything, including `--native` |
| `moggi-php` | PHP |
| `moggi-dotnet` | PHP and the .NET SDK |
| `moggi-jvm` | PHP, JDK and GraalVM |
| `moggi-minimal` | nothing — uses the runtimes on your `PATH` |

Without `-o` the artifact is named after the entry source file; pass a full file
name to choose your own (`-o app.phar`, `-o app.jar`, `-o app.dll`).

Useful flags: `--unpacked` keeps the generated tree instead of packaging it,
`--native` builds a native executable (GraalVM `native-image`, .NET
`PublishAot`), and `--tokens`/`--ast`/`--typed-ast`/`--ir`/`--opt-ir` dump the
compiler's intermediate stages. `--help` lists everything.

## Documentation

Read them in order, or pick the question you have.

| Topic | Guide |
|-------|-------|
| **Getting running (start here)** | [docs/quickstart.md](docs/quickstart.md) |
| **The language, by example** | [docs/tour.md](docs/tour.md) |
| Try expressions, inspect types | [docs/repl.md](docs/repl.md) |
| Find your way around `lib/` | [docs/stdlib.md](docs/stdlib.md) |
| Complete syntax | [docs/language.md](docs/language.md) |
| Calling PHP, the JVM or .NET | [docs/ffi.md](docs/ffi.md) |
| Deriving | [docs/deriving.md](docs/deriving.md) |
| Errors, exceptions, stack traces | [docs/diagnostics.md](docs/diagnostics.md) |
| How the compiler works, stage by stage | [docs/pipeline.md](docs/pipeline.md) |
| Differences from Haskell, whose names the library follows (not needed to use Moggi) | [docs/differences-to-haskell.md](docs/differences-to-haskell.md) |
| Language server (LSP) | [docs/lsp.md](docs/lsp.md) |
| VS Code extension | [moggi-lang/moggi-vscode-plugin](https://github.com/moggi-lang/moggi-vscode-plugin) |
| Environment variables | [docs/env-vars.md](docs/env-vars.md) |

Full index: [docs/README.md](docs/README.md), which also lists the compiler's own
documentation.

Moggi is alpha: the language and standard library are still being completed, and
the three backends are kept byte-for-byte identical by a differential test.

## Development

Working on the compiler itself. Moggi the *language* needs none of this.

### Environment

The flake pins every toolchain the compiler and test suite use — PHP 8.5, a JDK
21 (GraalVM, so `native-image` is there too), the .NET 8 SDK — and puts `moggi`
and `runtest` on your `PATH`:

```bash
nix develop
```

Nix is for development, on Linux and macOS. On Windows, or without Nix, the same
two commands work directly and use whatever toolchains you have installed:

```bash
php moggi.php version       # the compiler (what the `moggi` wrapper runs)
php test.php                # the test suite (what the `runtest` wrapper runs)
```

`php moggi.php --help` documents the compiler's commands; `php test.php --help`
documents the suite's selection and reporting flags.

### Repository layout

| Path | Purpose |
|------|---------|
| `moggi.php` | CLI entry point (`Moggi\CLI\main`) |
| `src/` | The compiler, written in PHP |
| `lib/` | The Moggi standard library, written in Moggi |
| `tests/` | Compiler test suite |
| `examples/` | Small programs to run and read |
| `dist/`, `launcher/`, `scripts/dist/` | The release distributions and the native launcher |
| `schnorr/` | Schnorr signature CLI (BIP-340), built alongside the launcher |

The editor extension is a separate project with its own release:
[moggi-lang/moggi-vscode-plugin](https://github.com/moggi-lang/moggi-vscode-plugin).

### Tests

```bash
runtest                            # the default backend (php)
runtest --backend jvm              # another backend
runtest --backend all --native     # every backend, plus native builds
runtest --group semantics          # one group; a path below a group narrows further
runtest --list                     # what would run
```

The suite runs the compiler end to end: it compiles cases and compares generated
code, IR, diagnostics and program output against goldens, on each backend they
declare. [docs/development/testing.md](docs/development/testing.md) describes the
layout, what a case looks like, and how goldens are updated.

### More

| Topic | Guide |
|-------|-------|
| Dev shell, debugging, editor setup | [docs/development/development.md](docs/development/development.md) |
| Test suite: layout, writing cases, goldens | [docs/development/testing.md](docs/development/testing.md) |
| Compiler architecture | [docs/development/architecture.md](docs/development/architecture.md) |
| Compiler stages, module by module | [docs/development/compiler.md](docs/development/compiler.md) |
| Design decisions and invariants | [docs/development/design.md](docs/development/design.md) |
| Optimizer passes | [docs/development/optimizations.md](docs/development/optimizations.md) |
| Building the distributions | [dist/README.md](dist/README.md) |

## AI disclaimer

Parts of this project — including code, tests and documentation — were developed with assistance from large language models. The language design, semantics, architecture and implementation decisions were developed independently. LLMs were used as a development aid, primarily for well-defined implementation tasks, boilerplate and iteration. Generated code was reviewed, modified, or discarded as appropriate.
