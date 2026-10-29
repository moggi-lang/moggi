---
title: "Packaging and releases"
description: CI workflows, distributions, pinned runtimes, version identity and what a release verifies.
---
# Packaging and releases

This page is for people building Moggi, not for users choosing a download
([quickstart.md](../quickstart.md)). The workflow sources are
`.github/workflows/{test,package,runtime-check}.yml`; assembly lives in
`packaging/` and `dist/`.

## What runs when

| Workflow | Trigger | Does |
|---|---|---|
| `test` | push to `master`, pull requests, manual | the suite (`php test.php --backend all --native`) on every target |
| `package` | any tag, pushes to `build`, manual | builds one installation per target, derives the five distributions, smoke-tests and archives them, then creates or moves the release |
| `runtime-check` | daily, manual | re-validates every pinned runtime URL (`php packaging/pin.php --check`) and opens/updates a `runtime-pins` issue on failure |

Matrices use `fail-fast: false`; actions are pinned to commit SHAs and each job
declares only the permissions it needs.

## Targets

| Target | Tested | Packaged | Runner |
|---|---|---|---|
| linux-x86_64 | yes | yes | `ubuntu-24.04` |
| linux-aarch64 | yes | yes | `ubuntu-24.04-arm` |
| macos-x86_64 | **no** | yes | `macos-15-intel` |
| macos-aarch64 | yes | yes | `macos-latest` |
| windows-x86_64 | yes | yes | `windows-2022` |

macOS x86_64 is a known gap: its archives are built and smoke-tested on that
runner, but the suite never runs on it.

## Distributions

| Variant | Bundles |
|---|---|
| `moggi` | PHP + micro runtime + .NET SDK + JDK + GraalVM + Composer + Maven |
| `moggi-php` | PHP + micro runtime + Composer |
| `moggi-dotnet` | .NET SDK |
| `moggi-jvm` | JDK + GraalVM + Maven |
| `moggi-minimal` | nothing; uses `PATH` |

One target is assembled once — compiler archive, native `bin/moggi`, docs,
examples, one prepared copy of each runtime — and the smaller variants are
derived by removing runtime directories. `bin/moggi` is the native compiler in
every variant; a bundled PHP is a *program* runtime, never the compiler's, and
`moggi-jvm`/`moggi-dotnet` carry no PHP at all.

An installation contains `bin/moggi` (the micro runtime with `bin/moggi.phar`
appended), `bin/moggi.phar` where PHP is bundled, `bin/schnorr`, the standard
library sources under `libraries/`, `docs/`, `examples/`, `LICENSE`,
`THIRD-PARTY-NOTICES.md` and `runtime/`. The compiler's sources live inside the
archive; the library sits beside it so users can read what they compile against.
`bin/schnorr` is always built here from this repository's sources, with the
platform toolchain, so a distribution cannot ship someone else's binary; the
assembler refuses a variant whose runtime or linked dependency lacks its
licence file.

Each variant is smoke-tested before archiving: `bin/moggi version`, compile and
run an example on every backend it bundles, build and execute a PHAR, and — when
the micro runtime is bundled — build and run a native executable.

## Runtimes

`dist/runtimes.json` names versions and variant definitions;
`dist/runtimes.lock.json` pins every asset URL and checksum per target, written
by `php packaging/pin.php`. `packaging/runtimes.php` downloads, verifies,
extracts and caches one runtime for one target, or builds it when the entry
carries a build recipe.

* **PHP is built from source** (php.net publishes no Linux/macOS binary) with a
  minimal configure: `--disable-all` plus `bcmath`, `intl` and `mbstring`.
* **The micro PHP runtime is built**, not downloaded: its entry carries a `spc`
  (static-php-cli) recipe and inherits the `php` runtime's extension set, so
  what a native executable can call is what a PHAR can call — that is what makes
  `IntlChar` available under `--native`. Its static link pulls in ICU and
  friends, whose licences ship under `runtime/php-native/license/`.
* **Zig is pinned** because `spc` would otherwise install the newest Zig, and
  Zig 0.16.0's `strnlen` reads past its buffer, so every static-musl binary it
  produces segfaults before it can run. Only Linux uses Zig; macOS uses Clang,
  Windows MSVC.

The Linux floor is glibc 2.35, enforced by `assemble.php --glibc-floor` in
release jobs: the build reads the highest `GLIBC_` symbol and fails above the
floor. The micro runtime is statically linked, so the check skips it.

## Building a distribution locally

```bash
php packaging/assemble.php --target linux-x86_64 --out .dist --archives
php packaging/assemble.php --variants moggi-minimal --archives --out /tmp/out
php packaging/assemble.php --target linux-x86_64 --force-runtimes
php packaging/pin.php --check --target linux-x86_64
php packaging/schnorr.php          # fetch pinned sources and build bin/schnorr
php test.php distribution          # the packaging tests
```

`assemble.php --help` lists the options. It stages the installation, derives the
variants, smoke-tests each and optionally writes archives. Building PHP from
source needs `libzip` and `oniguruma` on `PKG_CONFIG_PATH`; the dev shell sets
that up.

## Version identity

`VERSION` at the repository root is the release number. `build-phar.php` bakes
the build identity into the archive's own `VERSION` entry, so `moggi version`
reads it from inside the phar:

* a clean commit that is a tag reports the tag (`0.2.0`);
* anything else reports `0.2.0-dev.20260922+f60fb9f`, optionally with `.dirty`
  appended when tracked files changed (version, commit date, short commit);
* without git (source tarball) the version file is used verbatim.

`moggi version --json` adds `channel` (`release`, `dev`, `source`), `commit`
for dev builds, and `variant` derived from the bundled `runtime/` directories.
The release job asserts the tag equals `VERSION`.

## Channels

`package.yml` runs on any tag and on pushes to `build`:

| Channel | Built from | Tag | Release |
|---|---|---|---|
| official | a version tag equal to `VERSION` (no `v`) | the tag | public; a `-alpha`/`-beta` tag is a pre-release |
| dev | the `build` branch | `dev-build`, moved onto each build | pre-release, never `latest` |

## What a release verifies

1. the test workflow is green on the commit;
2. tag and `VERSION` agree;
3. every target builds and every variant passes its smoke test;
4. `SHA256SUMS` is generated over the archives and uploaded beside them;
5. provenance is attested (`gh attestation verify <archive> --repo …`);
6. a clean runner downloads the *published* archive, verifies its checksum,
   unpacks and runs it.

Official releases are created as drafts and published only after every step
passes; the dev tag is force-moved, and older dev releases are pruned.

## Signing

Archives are **not code-signed yet**; integrity rests on `SHA256SUMS` and the
build provenance attestation, and macOS quarantine and Windows SmartScreen need
the manual workarounds in [quickstart.md](../quickstart.md).

## Licences

Every variant ships `THIRD-PARTY-NOTICES.md` naming each bundled runtime, its
version and licence. PHP and the micro runtime are PHP License 3.01 (plus the
static link's terms), the .NET SDK is MIT on Linux/macOS and the .NET Library
License on Windows, GraalVM is GPLv2 with the Classpath Exception, Composer is
MIT and Maven is Apache 2.0.
