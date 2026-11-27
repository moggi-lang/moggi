---
title: "Quickstart"
---
# Quickstart

Get something running, then change it. Nothing here needs a functional
programming background.

Moggi is a general-purpose, purely functional language with **strict**
evaluation, algebraic data types, pattern matching and type classes. You write
`.mog` modules and the compiler turns them into one deployable artifact — a PHP
`.phar`, a JVM `.jar` or a .NET `.dll` — or, with `--native`, a standalone
executable.

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
| `moggi-dotnet` | the .NET SDK (whose `dotnet nuget` resolves `[dotnet.nuget]`) |
| `moggi-jvm` | JDK, GraalVM and Maven |
| `moggi-minimal` | nothing — uses the `php`, `javac`/`java`, `dotnet`, `composer` and `mvn` on your `PATH` |

A bundled runtime is preferred over the one on your `PATH`; anything the
variant does not bundle has to be provided yourself — put it on `PATH`, or use
a distribution that bundles it. `moggi runtime list` says which backends can
run programs here and where each tool came from; `moggi runtime <backend>
version` reports what was found.

`bin/moggi` is native in
every variant, so none of them needs PHP to run the compiler — but a build with
the `php` backend needs a PHP 8.6.0+ to execute what it produced, which only
`moggi` and `moggi-php` bundle. The micro PHP runtime is not on `PATH` at all:
it is the `micro.sfx` file a `--native` build on the `php` backend appends the
artifact to, so a variant without it (`moggi-dotnet`, `moggi-jvm`,
`moggi-minimal`) can only build a native PHP executable from a runtime named by
`MOGGI_MICRO_SFX`.

If you would rather run the compiler from a checkout, `php moggi.php …` does the
same thing — [the contributor guide](development/index.md) covers that setup.

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

| Backend | Artifact | Run with | Needs |
|---|---|---|---|
| `php` (default) | `hello.phar` | `php hello.phar` | PHP 8.6.0+ |
| `jvm` | `hello.jar` | `java -jar hello.jar` | JDK 21+ |
| `dotnet` | `hello.dll` (+ `hello.deps.json`, `hello.runtimeconfig.json`) | `dotnet hello.dll` | .NET 8 SDK |

`--native` builds a standalone executable that needs no runtime installed, and
writes the archive it was built from beside it under its usual name (`hello.exe`
is the executable's name on Windows):

```bash
moggi compile hello.mog --native                   # -> hello (+ hello.phar)
./hello

moggi compile hello.mog --native --backend jvm     # -> hello (+ hello.jar)
./hello

moggi compile hello.mog --native --backend dotnet  # -> hello (+ hello.dll and its sidecars)
./hello
```

| Backend | Native executable | Built with |
|---|---|---|
| `php` | `hello` | the micro PHP runtime: the packaged PHAR appended to a statically linked PHP |
| `jvm` | `hello` | GraalVM `native-image` |
| `dotnet` | `hello` | `dotnet publish -p:PublishAot=true` |

A native build needs the toolchain that produces it: the micro PHP runtime for
`php`, GraalVM's `native-image` for `jvm`, the AOT compiler for `dotnet`. The
`moggi` distribution bundles all three, and a variant without the micro PHP
runtime can still build a native PHP executable from one named by
`MOGGI_MICRO_SFX`. `jvm` and `dotnet` compile a whole runtime, so those builds
take noticeably longer than the plain archive; the `php` one is the archive
appended to the runtime. `--native` cannot be combined with `--unpacked`,
because the executable is built from the packaged archive.

### Project build options

The project descriptor (`<name>.moggi`, named after the package) can carry the
same native options on `[executable]`, so `moggi build` reproduces the build
without repeating flags:

```ini
[executable]
main = Main
source-dirs = src
native = true
package = true
# static = true
# musl = true
```

The values are booleans. `static`, `musl` and `package` require `native = true`;
`moggi build` forwards each enabled option to `moggi compile`. A flag passed
after `--` is forwarded too and is not passed twice. `--package` is available
on Linux as well; use it when a dependency cannot practically be linked
statically (Qt5 is the common example), and the bundle carries its non-system
shared libraries beside the executable.

### Static and musl native executables

On Linux, add `--static` to a native build to require an executable with no
dynamic loader and no shared-library dependencies:

```bash
moggi compile hello.mog --native --static
```

The flag is only valid with `--native`. The PHP backend already produces a
static executable, so it mainly verifies that fact. GraalVM does not support a
static glibc link, so `jvm` uses GraalVM's musl mode (`--static --libc=musl`)
and needs a musl C toolchain with static zlib installed. The `dotnet` backend
likewise targets `linux-musl-<arch>` and passes Native AOT's
`StaticExecutable=true`; its link needs the musl runtime pack and static zlib.
The musl .NET modes also set `InvariantGlobalization=true`, because Native AOT
otherwise looks up ICU with `dlopen` at run time; invariant mode is what makes
the executable genuinely self-contained on a minimal musl host. Every result
is checked with `readelf`; a binary that still names a dynamic loader or a
`DT_NEEDED` library fails the build rather than being reported as static.
`readelf` therefore has to be on `PATH`.

A fully static executable is Linux-only. macOS provides no static `libSystem`,
and a Windows PE executable always imports system DLLs (`kernel32.dll` and
friends), so `--static` is refused there with a message rather than producing
a binary that is not really static. Use `--package` on those platforms.

Use `--musl` to ask for a musl-linked executable without requiring a fully
static one:

```bash
moggi compile hello.mog --native --musl
moggi compile hello.mog --native --static --musl
```

Like `--static`, `--musl` is Linux-only and needs `--native` and `readelf`. The
PHP micro runtime is already a static musl build, so on `php` the flag mainly
verifies the result. `jvm` passes `--libc=musl` to GraalVM; `dotnet` publishes
for `linux-musl-<arch>` so the SDK selects the musl runtime pack, with
`InvariantGlobalization=true` so it does not need a separate musl ICU install.
A dynamic musl executable needs the musl loader at run time, and a static one
needs the musl C toolchain and static library pack. The build is rejected if
the result names a non-musl loader or carries glibc symbol versions.

### Packaging dependencies beside the executable

When a fully static executable is not possible or not wanted, `--package`
writes a directory beside the native executable:

```bash
moggi compile hello.mog --native --package -o hello
# hello          the executable (as before)
# hello.bundle/  hello plus its non-system shared libraries
```

On Linux the executable and bundled libraries are relinked to `$ORIGIN`, on
macOS to `@loader_path`; Windows already searches the executable's directory.
There is no launcher and no environment variable to set. System libraries —
libc, `libSystem`, the Windows system DLLs — stay with the operating system.
The bundle is a directory, so it can be zipped or copied as one unit; it is
not an AppImage.

`--package` requires `--native` and may be combined with `--static` (where a
static executable simply needs no libraries). It is not a macOS/Windows-only
fallback: on Linux it is the answer when a dependency such as Qt5 is impractical
to link statically. Linux needs `ldd` and `patchelf`; macOS needs `otool`,
`install_name_tool` and `codesign`; Windows needs `objdump`/`llvm-objdump` or
`dumpbin` to discover DLL imports.

### Build-option matrix

`--native` is the common case. `--static` is a Linux guarantee; on macOS and
Windows the correct portable answer is `--package`. A refusal is intentional:
the compiler does not label a binary static when the platform cannot make it so.

| Host OS | Backend | `--native` | `--static` | `--musl` | `--package` |
|---|---|---|---|---|---|
| Linux | `php` | yes | yes (the micro runtime is already static musl) | yes (verifies the runtime) | yes |
| Linux | `jvm` | yes | yes, with `<arch>-linux-musl-gcc` and static musl zlib | yes, with a musl C toolchain | yes |
| Linux | `dotnet` | yes | yes, with the musl runtime pack, musl C toolchain and static zlib | yes, with the musl runtime pack and toolchain | yes |
| macOS | `php`, `jvm`, `dotnet` | yes | refused: Apple provides no static `libSystem` | refused: Linux-only | yes (`@loader_path`) |
| Windows | `php`, `jvm`, `dotnet` | yes | refused: a PE executable always imports system DLLs | refused: Linux-only | yes (DLLs beside the `.exe`) |

Expected verification, per result:

| Result | Check | Expected |
|---|---|---|
| Linux `--static` | `readelf -l BIN` / `readelf -d BIN` | no `INTERP` and no `(NEEDED)` entries |
| Linux `--musl` (dynamic) | `readelf -l BIN` | the interpreter contains `ld-musl` |
| Linux `--musl --static` | `readelf -V BIN` / `readelf -p .rodata BIN` | no `GLIBC_*` version references or glibc loader strings |
| Linux/macOS `--package` | `ldd BIN.bundle/BIN` / `otool -L BIN.bundle/BIN` | non-system libraries resolve inside `BIN.bundle/` |
| Windows `--package` | `objdump -p BIN` or `dumpbin /dependents` | non-system DLLs are beside the `.exe` |
| any native result | run the binary | same stdout as the managed build |

Without `-o` the artifact is named after the entry source file; give the full
file name to choose your own (`-o app.phar`, `-o app.jar`, `-o app.dll`). With
`--native`, `-o app` names the executable and the archive becomes `app.phar`
(`app.jar`, `app.dll`) beside it. `moggi run hello.mog --backend jvm` compiles
and runs in one step.

Two flags worth knowing now:

* `--unpacked` keeps the generated tree instead of packing it into one artifact —
  the fastest way to read what your program became
  (`moggi compile hello.mog -o out --unpacked`).
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
| `moggi.serverPath` | `<installation>/bin/moggi`, or `<root>/moggi.php` in a checkout |
| `moggi.phpPath` | a PHP 8.6.0 binary, used only when `serverPath` is a `.php` |

Any other editor works too: `moggi lsp` is a plain stdio language server.
[lsp.md](lsp.md) describes the server and the extension's commands in full.

## Next

Change a program and see what happens — that is the whole loop. When you want to
try an expression without saving a file, go to [repl.md](repl.md). To read the
language itself, start at [tour.md](tour.md).
