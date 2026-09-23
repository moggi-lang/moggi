# Distributions

Everything needed to turn this checkout into the Moggi release distributions.

```
dist/runtimes.json         runtime versions, asset URLs, the five variants
dist/runtimes.lock.json    the exact URL and checksum of every asset, per target
schnorr/deps.json          the pinned sources of the two C libraries schnorr links
scripts/dist/pin.php       resolve + verify + lock the assets
scripts/dist/runtimes.php  download, verify, extract, build and validate one runtime
scripts/dist/build-phar.php  the compiler as a single `moggi.phar`
scripts/dist/schnorr.php   fetch the pinned C sources and build the schnorr CLI
scripts/dist/assemble.php  stage the installation, derive the variants, archive them
launcher/moggi.c           the native launcher (Clang, C11)
.github/workflows/package.yml  build them all, archive them, attach them to a release
```

## The five distributions

| Variant | Bundles |
|---|---|
| `moggi` | PHP, .NET SDK, JDK, GraalVM |
| `moggi-php` | PHP |
| `moggi-dotnet` | PHP, .NET SDK |
| `moggi-jvm` | PHP, JDK, GraalVM |
| `moggi-minimal` | nothing — uses the runtimes on `PATH` |

Every variant is the same installation apart from `runtime/`. A bundled runtime
is put in front of `PATH` by the launcher, so the compiler's child processes
(`php`, `javac`, `java`, `native-image`, `dotnet`) resolve to the bundled copies;
whatever a variant does not bundle comes from the host, and a runtime that is
neither bundled nor on `PATH` is a clear error rather than a confusing failure
inside the compiler.

Besides the launcher and the compiler archive, `bin/` carries `schnorr` — a
standalone BIP-340 CLI built from `schnorr/` and the two C libraries pinned in
`schnorr/deps.json`. Nothing in Moggi uses it yet; it ships because it is part of
the toolbox and costs nothing at run time (it links statically and reads no
runtime files). Every variant, `moggi-minimal` included, is smoke-tested by
generating a key with it.

## Runtime availability

Upstream does not publish every runtime for every target. This table is what
`php scripts/dist/pin.php` resolves; **nothing is ever substituted across
architectures**, and a target/runtime combination that upstream does not build is
reported as unsupported and left out of the distributions that need it.

| Target | PHP | .NET SDK | JDK | GraalVM |
|---|---|---|---|---|
| `linux-x86_64` | built from source | yes | yes | yes |
| `linux-aarch64` | built from source | yes | yes | yes |
| `macos-x86_64` | built from source | yes | yes | yes |
| `macos-aarch64` | built from source | yes | yes | yes |
| `windows-x86_64` | php.net zip | yes | yes | yes |
| `windows-aarch64` | **none** | yes | yes | **none** |

Two consequences worth stating plainly:

* **PHP on Linux and macOS** is built from the official php.net source release
  (`--disable-all` plus the handful of extensions Moggi needs). php.net publishes
  binaries for Windows only, so the source release is the only official upstream
  PHP for those platforms — and it keeps every architecture on a genuine build of
  the same release. macOS has no static linking worth the name, so the built
  binary's third-party libraries (`libzip`, `oniguruma`) are copied next to it and
  their `install_name`s rewritten with `install_name_tool`; the build fails rather
  than shipping a binary that only works on the machine that built it.
* **Windows ARM64** gets no distributions other than `moggi-minimal`, and that
  one is of little use: php.net publishes no Windows ARM64 PHP at all, so there is
  no PHP to bundle and nothing for a minimal installation to fall back to.

## Versions

`dist/runtimes.json` is the single place versions live, next to a short note on
why each URL is the official one. It mirrors the development environment in
`flake.nix`; bump both together, then re-lock:

```bash
php scripts/dist/pin.php            # every target and runtime
php scripts/dist/pin.php --target windows-x86_64 --runtime php
php scripts/dist/pin.php --check    # validate only, write nothing
```

`pin.php` checks that every asset is on an upstream host, that its filename names
the target's architecture, that it resolves, and — where upstream publishes a
checksum (`php.net` release metadata, the .NET release metadata, the Adoptium
asset API, the GraalVM `.sha256` files) — that its digest matches. The result
goes into `runtimes.lock.json`, and the build verifies the download against it, so
an upstream change is a loud failure instead of a silent substitution.

## Building locally

```bash
php scripts/dist/assemble.php --target linux-x86_64 --archives --out dist-out
php scripts/dist/assemble.php --variants moggi-minimal --out dist-out   # fast, no runtimes
php scripts/dist/assemble.php --target linux-x86_64 --glibc-floor       # what a release does
```

The target defaults to the host. Clang builds the launcher (`MOGGI_DIST_CC`
overrides it), `schnorr` is built with `cc`/`gcc`/`clang`
(`MOGGI_DIST_SCHNORR_CC` overrides it, and `MOGGI_DIST_SCHNORR` hands the
assembler an already-built binary instead — which is how Windows, whose Makefile
needs a POSIX shell, gets one from an MSYS2 step), runtimes are prepared once per
target under `.dist-cache/` and reused, and each variant is smoke-tested — the
launcher reports its version, `examples/factorial` is compiled and run with every
backend the variant bundles, `bin/schnorr generate` produces a keypair, and a PHAR
artifact is built and executed — before its archive is written. That last one is
not decoration: writing a PHAR needs a second PHP process, and building one is the
only operation a *distribution* performs that a checkout never does, because only
a distribution runs the compiler from inside an archive.

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
checks the result: it reads the highest `GLIBC_` symbol the launcher and each
bundled runtime ask for (`objdump -T`) and refuses anything above
`linuxGlibcFloor`. A build on a newer machine is expected to miss that — which is
why only the release jobs pass the flag, and why a local Linux build is not
necessarily portable.

### Licences

Each bundled runtime keeps its own licence files under `runtime/<name>/`, and
every variant ships `THIRD-PARTY-NOTICES.md`. The archives bring their licences
along; the PHP source build and php.net's Windows zip do not, so `runtimes.php`
takes PHP's from the source release it built (or the upstream URL for the Windows
zip).

The notices page also repeats the full text of both libraries `bin/schnorr`
statically links — libsecp256k1 (MIT) and libbech32 (WTFPL v2) — because their
sources are not shipped and MIT requires the notice to travel with a binary
build. That is why even `moggi-minimal`, which bundles no runtime, carries the
page. The assembler fails a variant whose bundled runtime has no licence file, or
when a fetched schnorr dependency is missing its licence, because a distribution
missing someone else's terms is not shippable.
