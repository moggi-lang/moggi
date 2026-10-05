# Distributions

Everything needed to turn this checkout into the Moggi release distributions.

```
dist/runtimes.json              runtime versions, asset URLs, the five variants
dist/runtimes.lock.json         the exact URL and checksum of every asset, per target
schnorr/deps.json               the pinned sources of the two C libraries schnorr links
packaging/pin.php               resolve + verify + lock the assets
packaging/runtimes.php          download, verify, extract, build and validate one runtime
                                (static-php-cli's spc compiles the micro PHP one)
packaging/build-phar.php        the compiler as a single `moggi.phar`
packaging/schnorr.php           fetch the pinned C sources and build the schnorr CLI
packaging/assemble.php          stage the installation, derive the variants, archive them
packaging/website.php           rewrite the release links on moggi-lang.org
.github/workflows/package.yml   build them all, archive them, attach them to a release
```

## Variants

| Variant | Bundles |
|---|---|
| `moggi` | PHP, micro PHP runtime, .NET SDK, JDK, GraalVM, Composer, Maven |
| `moggi-php` | PHP, micro PHP runtime, Composer |
| `moggi-dotnet` | .NET SDK |
| `moggi-jvm` | JDK, GraalVM, Maven |
| `moggi-minimal` | nothing — uses the runtimes on `PATH` |

Every variant is the same installation apart from `runtime/`. `bin/moggi` — the
compiler — is native in all of them, so the PHP a variant bundles is a *program*
runtime, never the compiler's: `moggi-jvm` and `moggi-dotnet` carry no PHP at all.
A bundled runtime is put in front of `PATH` by the compiler itself as it starts
(`src/installation.php`), so its child processes (`php`, `javac`, `java`,
`native-image`, `dotnet`, `composer`, `mvn`) resolve to the bundled copies;
whatever a variant does not bundle comes from the host, and a runtime that is
neither bundled nor on `PATH` is a clear error rather than a confusing failure
inside the compiler.

The micro PHP runtime (`runtime/php-native/micro.sfx`) is the exception to that
`PATH` rule: it is the file `moggi compile --backend php --native` appends the
packaged PHAR to, and no process is ever launched by that name. It is also a
*build input* for every variant — `bin/moggi` is that runtime with the compiler
appended — so assembly always prepares it, and only the variants that ship
`--native` on the PHP backend bundle it afterwards (`moggi`, `moggi-php`). The
compiler finds a bundled one at the installation root, or at whatever path
`MOGGI_MICRO_SFX` names.

`composer` and `maven` are the host tools `[php] composer` and `[jvm] maven`
dependencies are resolved with, and a variant carries the tool for the backend it
is named after. `[dotnet] nuget` needs no runtime of its own: the bundled .NET SDK
already ships `dotnet nuget`. Composer arrives as a bare phar rather than an
archive — `format: plain` in `dist/runtimes.json` — so `runtimes.php` generates a
`bin/composer` shim beside it that runs it on the bundled PHP (or the one on
`PATH`), and the smoke test runs both tools from the finished archive.

Besides the compiler (`bin/moggi`) and, where PHP is bundled, the compiler archive
`bin/moggi.phar`, `bin/` carries `schnorr` — a
standalone BIP-340 CLI built from `schnorr/` and the two C libraries pinned in
`schnorr/deps.json`. Nothing in Moggi uses it yet; it ships because it is part of
the toolbox and costs nothing at run time (it links statically and reads no
runtime files). Every variant, `moggi-minimal` included, is smoke-tested by
generating a key with it.

Building the micro PHP runtime is a source build of PHP and its extension
libraries, so it is the slowest step of an assembly — static-php-cli's own
`doctor --auto-fix` step runs first and installs or reports whatever the host is
missing, before hours of compiling rather than during them. It is also the only
step a fresh assembly of any variant cannot skip, because `bin/moggi` is built
from it: the compiler runs as one self-contained executable with no PHP of its
own, which is what lets `moggi-jvm` and `moggi-dotnet` ship no PHP. A variant
that bundles PHP also ships `bin/moggi.phar`, the same compiler as a plain PHP
archive, beside it.

## Runtime availability

Upstream does not publish every runtime for every target. This table is what
`php packaging/pin.php` resolves; **nothing is ever substituted across
architectures**, and a target/runtime combination that upstream does not build is
reported as unsupported and left out of the distributions that need it.

| Target | PHP | micro PHP | .NET SDK | JDK | GraalVM |
|---|---|---|---|---|---|
| `linux-x86_64` | built from source | built by spc | yes | yes | yes |
| `linux-aarch64` | built from source | built by spc | yes | yes | yes |
| `macos-x86_64` | built from source | built by spc | yes | yes | yes |
| `macos-aarch64` | built from source | built by spc | yes | yes | yes |
| `windows-x86_64` | php.net zip | built by spc | yes | yes | yes |

Two consequences worth stating plainly:

* **PHP on Linux and macOS** is built from the official php.net source release
  (`--disable-all` plus the handful of extensions Moggi needs). php.net publishes
  binaries for Windows only, so the source release is the only official upstream
  PHP for those platforms — and it keeps every architecture on a genuine build of
  the same release. macOS has no static linking worth the name, so the built
  binary's third-party libraries (`libzip`, `oniguruma`) are copied next to it and
  their `install_name`s rewritten with `install_name_tool`; the build fails rather
  than shipping a binary that only works on the machine that built it.
* **Windows ARM64 is not a target.** php.net publishes no Windows ARM64 PHP and
  static-php-cli publishes no Windows ARM64 `spc`, so there is neither a PHP to
  bundle nor a micro runtime to build the compiler from. That makes even
  `moggi-minimal` impossible, because `bin/moggi` itself needs the micro runtime,
  so the target is dropped rather than shipped half-working.
* **The micro PHP runtime is built, not downloaded.** Its `runtimes.json` entry
  has no asset URL: it carries a `build` recipe, and `packaging/runtimes.php`
  compiles it with `spc` — static-php-cli's build tool, which is itself a pinned
  download from that project's own release rather than a Composer checkout. The
  compiler `spc` uses on Linux is pinned the same way, because it cannot be left
  to `spc`: `spc` installs whatever Zig ziglang.org lists as newest, and Zig
  0.16.0 miscompiles static-musl code — its `strnlen` reads past its buffer, so
  every binary it produces, this runtime included, segfaults before it starts.
  The pinned version is installed, with the wrappers `spc` looks for beside it,
  where `spc` finds it. The runtime is built from the same PHP source as the
  `php` runtime with the same extension list, so `intl` is there and
  `Data.Char`'s and `Data.String`'s `IntlChar` foreigns have a host under a
  native executable.

## Versions

`dist/runtimes.json` is the single place versions live, next to a short note on
why each URL is the official one. It mirrors the development environment in
`flake.nix`; bump both together, then re-lock:

```bash
php packaging/pin.php            # every target and runtime
php packaging/pin.php --target windows-x86_64 --runtime php
php packaging/pin.php --check    # validate only, write nothing
```

`pin.php` checks that every asset is on an upstream host, that its filename names
the target's architecture, that it resolves, and — where upstream publishes a
checksum (`php.net` release metadata, the .NET release metadata, the Adoptium
asset API, the GraalVM `.sha256` files) — that its digest matches. The result
goes into `runtimes.lock.json`, and the build verifies the download against it, so
an upstream change is a loud failure instead of a silent substitution.

## Building locally

```bash
php packaging/assemble.php --target linux-x86_64 --archives --out dist-out
php packaging/assemble.php --variants moggi-minimal --out dist-out   # one variant
php packaging/assemble.php --target linux-x86_64 --glibc-floor       # what a release does
```

The target defaults to the host. `schnorr` is built with `cc`/`gcc`/`clang`
(`MOGGI_DIST_SCHNORR_CC` overrides it, and `MOGGI_DIST_SCHNORR` hands the
assembler an already-built binary instead — which is how Windows, whose Makefile
needs a POSIX shell, gets one from an MSYS2 step), runtimes are prepared once per
target under `.dist-cache/` and reused, and each variant is smoke-tested —
`bin/moggi` reports its version, `examples/factorial` is compiled and run with
every backend the variant bundles, `bin/schnorr generate` produces a keypair, and
a PHAR artifact is built and executed — before its archive is written. That last
one is not decoration: writing a PHAR is the one operation a *distribution*
performs that a checkout never does, and the native compiler can only do it
because `phar.readonly=0` sits in the INI block between its runtime and its
archive.

Building PHP from source needs `autoconf`, `pkg-config` and the development
packages for `libzip` and `oniguruma`:

```bash
# Debian/Ubuntu
sudo apt-get install -y autoconf pkg-config libzip-dev libonig-dev
# macOS
brew install libzip oniguruma
export PKG_CONFIG_PATH="$(brew --prefix libzip)/lib/pkgconfig:$(brew --prefix oniguruma)/lib/pkgconfig"
```

`.dist-cache/` holds downloads, the PHP build tree, and the prepared runtimes; it
is safe to delete and never leaves the checkout.

### The glibc floor

A bundled binary cannot be more portable than the machine that built it, so the
Linux releases are built inside an `ubuntu:22.04` container and `--glibc-floor`
checks the result: it reads the highest `GLIBC_` symbol each bundled runtime asks
for (`objdump -T`) and refuses anything above
`linuxGlibcFloor`. A build on a newer machine is expected to miss that — which is
why only the release jobs pass the flag, and why a local Linux build is not
necessarily portable.

### Licences

Each bundled runtime keeps its own licence files under `runtime/<name>/`, and
every variant ships `THIRD-PARTY-NOTICES.md`. The archives bring their licences
along; the PHP source build and php.net's Windows zip do not, so `runtimes.php`
takes PHP's from the source release it built (or the upstream URL for the Windows
zip). The micro PHP runtime is statically linked, so it links far more than PHP
(`zlib`, `libzip`, ICU); its build runs `spc dump-license` and ships every one of
those terms under `runtime/php-native/license/`, and the assembler refuses a
variant whose runtime has no such dump.

The notices page also repeats the full text of both libraries `bin/schnorr`
statically links — libsecp256k1 (MIT) and libbech32 (WTFPL v2) — because their
sources are not shipped and MIT requires the notice to travel with a binary
build. That is why even `moggi-minimal`, which bundles no runtime, carries the
page. The assembler fails a variant whose bundled runtime has no licence file, or
when a fetched schnorr dependency is missing its licence, because a distribution
missing someone else's terms is not shippable.
