# Packaging and releases

How Moggi is built, tested and published. This page is for people working on the
compiler; if you just want to *use* a release, read
[quickstart.md](../quickstart.md).

The workflow sources are `.github/workflows/test.yml`, `.github/workflows/package.yml` and
`.github/workflows/runtime-check.yml`; the packaging pipeline itself is `dist/README.md` and
`packaging/`.

## What runs when

| Workflow | Trigger | What it does |
|---|---|---|
| `test` | push to `master`, every pull request, manual | the whole suite — `php test.php --backend all --native` — on each platform below, plus a `vs-code-extension` job (typecheck, the server end-to-end, version agreement) |
| `package` | any tag, manual | builds one installation per target, derives the five distributions, smoke-tests each and archives them, and packages the VS Code extension as `moggi-lsp-<version>.vsix`; on a tag, a single publish job then fills a draft release (`SHA256SUMS` from the uploaded files, provenance attested) and makes it public, and a verification job re-downloads the published archives, checks their sums and runs them |
| `runtime-check` | daily, manual | validates every pinned runtime URL for every target (`pin.php --check`); a failure opens (or updates) a `runtime-pins` issue, and a passing run closes it |

Both matrices use `fail-fast: false`, so one broken target does not hide the others. Every action is pinned
to a commit SHA and each job declares only the permissions it needs (`contents: read`, widened to
`write` solely in the publishing job), so a moved tag or a compromised step cannot reach the releases.

## Targets

| Target | Tested | Packaged | Runner |
|---|---|---|---|
| linux-x86_64 | yes | yes | `ubuntu-24.04` |
| linux-aarch64 | yes | yes | `ubuntu-24.04-arm` |
| macos-x86_64 | **no** (gap) | yes | `macos-13`, the last x86_64 macOS image |
| macos-aarch64 | yes | yes | `macos-latest` |
| windows-x86_64 | yes | yes | `windows-2022` |

Architecture-specific jobs exist to catch code generators and runtimes that only work on one
machine shape; a target that cannot be run is not published.

## The five distributions

`moggi` (PHP + the micro PHP runtime + .NET + JDK + GraalVM + Composer + Maven), `moggi-php`
(PHP + the micro PHP runtime + Composer), `moggi-dotnet` (.NET only), `moggi-jvm`
(JDK + GraalVM + Maven), `moggi-minimal` (nothing bundled). The tools a variant carries follow the
backend it is named after — the host tools `[php] composer` and `[jvm.maven]` are resolved with.
`bin/moggi` is the *native* compiler in every variant, so the PHP a variant bundles is a program
runtime, never the compiler's, and `moggi-jvm`/`moggi-dotnet` carry no PHP at all. They differ only
in `runtime/`: one target is built once — compiler archive, native compiler, docs, examples, one
prepared copy of each runtime — and the smaller variants are derived from it by removing runtime
directories and dropping `bin/moggi.phar` where PHP is not bundled. Every variant that bundles PHP
also bundles the micro PHP runtime, so `--native` on the `php` backend works wherever the `php`
backend does. Each variant is smoke-tested (`bin/moggi` reports its version, an example is compiled
and run on each backend it bundles, a PHAR artifact is built and executed, and — when the micro
runtime is bundled — a native executable is built and run; the PHAR step is the one operation only a
distribution performs) before it is archived.

An installation is `bin/moggi` (the compiler as one self-contained executable: the micro PHP runtime
with `bin/moggi.phar` appended), `bin/moggi.phar` in the variants that bundle PHP (the same compiler
as a plain archive: every file under `src/`, and the `VERSION` entry naming this build),
`bin/schnorr` (a standalone BIP-340 CLI with no user in the language yet — it ships because it is
part of the toolbox and costs nothing at run time), the standard library as ordinary sources under
`lib/`, `docs/`, `examples/`,
`LICENSE`, `THIRD-PARTY-NOTICES.md` (see [Licences](#licences)), and `runtime/`. The library sits beside the archive rather than inside
it: users can read the sources they compile against, and the installation's `LICENSE` plainly covers
them. It also removes the archive's only reason to write outside its own installation. The `moggi-lsp` extension is *not* bundled: it ships as a `.vsix` asset beside the
archives, because VS Code installs extensions from the Marketplace or a file it is given, not from
a directory inside an unpacked compiler. The compiler's sources are shipped *inside* the archive and nowhere else — the compiler
puts a bundled runtime in front of `PATH` as it starts, so `php`, `javac`, `java`, `native-image`,
`dotnet`, `composer` and `mvn` resolve to the bundled copies when there are any, and to the host's
otherwise. A runtime that is neither bundled nor on `PATH` is a clear error, reported by
`moggi version` as `not found`.

The micro PHP runtime under `runtime/php-native/` is the one exception to that rule: it is not a
directory of commands but the single `micro.sfx` file a native build appends the PHAR to, so nothing
puts it on `PATH`. It is also the runtime every `bin/moggi` is *built* from, which is why assembly
always prepares it even in a variant that does not bundle it.

## Runtimes

Versions, upstream URL templates and the variant definitions live in `dist/runtimes.json`; the
exact URL and checksum of every asset, per target, live in `dist/runtimes.lock.json` — written by
`php packaging/pin.php`. `packaging/runtimes.php` downloads, verifies, extracts, validates
and caches one runtime for one target — or, for a runtime whose entry carries a `build` recipe
rather than an asset URL, compiles it; the runners' own installations are never used as bundled
runtimes.

`php packaging/pin.php --check --target <target>` re-validates every asset: reachable, on the
allowed upstream host, the configured version, the right architecture. Run it after touching
`runtimes.json`, and in CI before a build so an upstream change fails there rather than halfway
through an assembly.

The same check runs on its own every day (`runtime-check.yml`), for every target. Upstream does
not announce a withdrawn release or a replaced asset, and a packaging run only finds out when a
tag is being built — which is the worst moment to be told. The daily job turns that into an issue
filed a few days earlier. It never fails the workflow: the report *is* the deliverable, and the
issue closes itself on the next run that passes.

**PHP is built from the official source release** (php.net publishes Windows binaries only) with a
deliberately minimal configure — `--disable-all` plus `phar`, `bcmath`, `mbstring`, `zip`,
`ctype` and `intl` (the `Char` cons builds a character with `IntlChar::chr`), which is exactly what
the compiler and its generated code use. Everything else — .NET SDK, JDK, GraalVM — is
downloaded, and so is the `spc` build tool the micro PHP runtime is built with. Downloads and builds
are cached under `.dist-cache/`, keyed by the lock hash for a download and by the build recipe for
a derived runtime: a cache hit makes a packaging job minutes long, and a miss makes it slower, never
wrong.

**The micro PHP runtime is built, not downloaded.** Its `runtimes.json` entry carries a `build`
recipe — tool, PHP series, SAPI — instead of an asset URL, and the build tool is
[`spc`](https://github.com/crazywhalecc/static-php-cli), static-php-cli's build tool. `spc` is a
pinned download from that project's own GitHub release (not a Composer checkout), so a packaging run
needs no PHP toolchain beyond the one already building the compiler. It compiles the micro SAPI with
the *same* extension list as the `php` runtime, which is what makes `intl` available under a native
executable — `Data.Char`'s and `Data.String`'s `IntlChar` foreigns have a host there. Two
consequences follow from building it here rather than taking an upstream artifact:

* the patch level is whatever the 8.5 series `spc` pins, so the version a build reports is
  *recorded* in the runtime marker rather than asserted against a php.net release;
* a statically linked PHP links far more than PHP — `zlib`, `libzip` and ICU — so the build runs
  `spc dump-license` and ships every one of those terms in `runtime/php-native/license/`. The
  assembler refuses a variant whose runtime has no such dump.

**The compiler is pinned too.** `spc` installs a Zig of its own when it finds none, taking whatever
version ziglang.org lists as newest, so the archive would silently depend on the day it was built.
`runtimes.json` therefore pins Zig in its own `zig` entry, and `packaging/runtimes.php` installs
it, with the `zig-cc` and `zig-c++` wrappers `spc` looks for beside it, into the build's
`pkgroot/zig` — the directory `spc` would otherwise install into. This is not caution for its own
sake: Zig 0.16.0 miscompiles static-musl code (its `strnlen` reads past the buffer it is given),
and every binary it produces — the micro runtime included — segfaults before it starts. Only the
Linux toolchain uses Zig; macOS builds with Clang and Windows with MSVC.

`spc` publishes no Windows ARM64 build, and `bin/moggi` is built from the micro runtime it produces,
so Windows ARM64 could not host the compiler at all. It is not a target.

### Portability floor

A bundled runtime cannot be more portable than the machine it was built on, so the floor is
enforced rather than intended:

* **glibc 2.35** (Ubuntu 22.04 LTS), from `linuxGlibcFloor` in `dist/runtimes.json`. The Linux
targets build inside an `ubuntu:22.04` container — both architectures, since the image is
multi-arch — so the source-built PHP is linked against that libc whichever runner produced it. It
is then checked: `assemble.php --glibc-floor` reads the highest
`GLIBC_` symbol the binary asks for with `objdump -T` and **fails the build** if it is above the
floor. Only the release jobs pass that flag; a local build on a newer machine is expected to miss
it, which is exactly why the check is opt-in. The micro PHP runtime is statically linked (musl on
Linux), so the check skips it: `objdump -T` finds no dynamic symbols to compare against a floor.
* **macOS deployment target** set explicitly for the source-built PHP, since the runner's default
  would otherwise become the minimum macOS version.

A distribution that needs a newer libc than this is a bug, not a documentation problem.

## Host-tool dependencies

A package declares third-party dependencies in the vocabulary of the tool that already owns
them — `[php] composer = vendor/package:^1.0`, then a table per backend: `[jvm.maven]`
`group:artifact = version`, `[dotnet.nuget]` `Package = 8.0.0`. The descriptor never names a file or a resolved artifact, and
Moggi has no dependency solver of its own: `moggi build` writes the manifest the tool reads
(`composer.json`, `pom.xml`, `build.csproj`) and runs the tool.

One tree per coordinate set, under `<cache>/runtime/<backend>/<key>/`, where the key is the
sorted coordinate set and the manifest is committed beside the artifacts it produced. A
`.complete` marker records the tool and its version, so upgrading Composer re-resolves instead of
serving artifacts of a resolver that is no longer installed. The tree holds the tool's own output
— Maven writes jars into `jvm/`, `dotnet restore` leaves assemblies in the machine-wide NuGet cache
and only records where, so they are copied into `dotnet/` and the tree stops depending on that
cache — and the manifest is what a diff shows when the dependencies changed.

That tree is then handed to the compile as one more library root, and the backend dependency
scans walk *every* configured root rather than only the standard library. So a resolved
`jvm/*.jar` is demand-merged into the artifact, a resolved `dotnet/*.dll` is staged beside the app
assembly and named in its `deps.json` (the app is framework-dependent, so an assembly it
references has to be there), and Composer's `vendor/` tree is copied into the artifact with the
entry module requiring its autoloader — a dependency the program calls into, unlike a library's
own `php/` helpers, which are inlined as always. A coordinate set that is empty skips the whole
step, so a package with no `[php]`, `[jvm]` or `[dotnet]` section never runs a tool.

Because the PHP emit depends on whether a Composer tree is being carried, the backend's
`emitCacheDiscriminator()` folds that into the emit cache key: emitted output is cached per
project, and an input the key cannot see is how a cached module gets reused for a build that
would have emitted something else.

The part a tool cannot supply is the CLR detail a `foreign dotnet` declaration leaves out: which
assembly defines a type, whether it is a value type, and the real parameter list of a method whose
C# signature has optional parameters. Those are declared by the package as `dotnet/*.json` under a
library root, generically discovered like the other dependency directories — the same shape that
lets the standard library describe `System.Numerics.BigInteger`.

## Descriptor metadata

`<name>.moggi` at a package root is the package declaring itself. `src/registry/descriptor.php`
is the whole format — it is closed, so a key the schema does not declare is an error, and a
descriptor carries no `format` field of its own. `[package]` holds the identity (`name`,
`version`, `license`, `license-file`, `copyright`, `description`), the backends the package can be
built for, and the metadata a package page leads with: `homepage` and `repository` (absolute
`http`/`https` URLs), `keywords` (comma-separated) and `maintainer` (a contact, defaulting to the
first author's email when omitted). Every signing key gets its own `[author]` block, and
`[author.<id>] automation = true` marks such a key as a job — a CI uploader — rather than a
person, so tooling can tell the two apart; the flag labels the key and grants nothing, and a
package page leaves such a key out of its maintainers, since a reader wants someone to contact
rather than a key. The allowed-user list is still the catalog's `authors`.

`npub` is optional, and a block without one is **credit only**: someone who wrote the package
and may no longer be involved is named without being handed a way to publish. Credit never
reaches the allowed-user list — a package still needs at least one block that names a key, and
that is what `readDescriptor()` returns as `authors`, with every block, keyed or not, in
`credits`. A package page shows the credits as authors and the keyed people as maintainers,
which is how an original author keeps their name on a package they have handed over. A package
does not carry `bug-reports`, `category`, `changelog`, `stability` or `tested-with` yet — they are
deferred rather than forgotten. What a package needs *from* a backend is separate: its runtime
floor (`[php] version`, `[jvm] version`, `[dotnet] version`), its runtime requirements
(`[php.extensions]`), and the host-tool tables above. The compiler is a native executable, so a
descriptor declares only what a compiled program needs — there is one requirement set, not one per
role.

## Licences

Every distribution bundles other people's runtimes, so it has to carry their terms:

| Bundled | Licence |
|---|---|
| PHP | PHP License 3.01 (php.net publishes no binary for Linux or macOS, so this is the source build) |
| micro PHP runtime | PHP License 3.01 plus the terms of everything the static link pulls in (`zlib`, `libzip`, ICU, …), collected by `spc dump-license` under `runtime/php-native/license/` |
| .NET SDK | MIT on Linux and macOS; the .NET Library License on Windows |
| JDK (Eclipse Temurin) | GPLv2 with the Classpath Exception |
| GraalVM Community | GPLv2 with the Classpath Exception |
| Composer | MIT (`moggi`, `moggi-php`) |
| Apache Maven | Apache License 2.0 (`moggi`, `moggi-jvm`) |

Each bundled runtime keeps its own licence files inside `runtime/<name>/`, and every variant ships
`THIRD-PARTY-NOTICES.md`, which names what is bundled, the version, the licence and any
acknowledgement upstream asks redistributors to carry (PHP, for one, requires the "This product
includes PHP software…" line). `packaging/runtimes.php` stages the licence that an archive does
not provide itself — php.net's source tarball and Windows zip ship neither — and
`packaging/assemble.php` refuses to build a variant whose bundled runtime has no licence file.

`bin/schnorr` is the project's own source, but it statically links two libraries whose sources are
not part of this repository: libsecp256k1 (MIT) and libbech32 (WTFPL v2). MIT requires its notice to
travel with binary distributions, so the notices page repeats both texts in full and is written for
every variant, including `moggi-minimal`, and the build fails if either library's licence file is
missing from the fetched sources.

No runtime is modified: each is the unmodified upstream build, so the upstream terms are the ones
that apply.

## The schnorr CLI

`schnorr/deps.json` pins libsecp256k1 at `v0.8.0` and libbech32 at `v1.1p1` by tag *and* commit,
with the licence file each one carries. `packaging/schnorr.php` clones any of them that is not
checked out yet (a shallow clone of the pinned tag, verified against the pinned commit), then drives
`schnorr/Makefile`, which owns the compiler flags — including the `ECMULT_WINDOW_SIZE` /
`COMB_BLOCKS` / `COMB_TEETH` values the shipped precomputed tables were generated for. The clone
directories are git-ignored: they are a build input, not project content.

`MOGGI_DIST_SCHNORR_CC` names the compiler (default: `cc`, `gcc` or `clang` on Linux and macOS;
MinGW on Windows). `MOGGI_DIST_SCHNORR` names an already-built binary to use instead of building —
which is what Windows does, because the Makefile's recipes need a POSIX shell and the assembler runs
under PowerShell there; the Windows jobs build it in an MSYS2 step and pass the result on. MinGW is
detected by the triple the compiler reports, not by its name (in MSYS2's UCRT64 shell the Windows
compiler is simply `gcc`), and it forces a static link: MinGW would otherwise make `bin/schnorr.exe`
depend on `libgcc_s_*.dll` and `libwinpthread-1.dll`, which no Windows machine has by default.

```bash
php packaging/schnorr.php                 # fetch the pinned sources and build in place
./schnorr/tests/bip340_test.sh               # the official BIP-340 vectors
```

## Version identity

`VERSION` at the repository root is the release number. `build-phar.php` bakes the *build*
identity into the archive's own `VERSION` entry, so a distribution still carries no file for a user
to edit by accident and `moggi version` reads it from inside the phar:

* a clean commit that *is* a tag reports the tag (`0.1.0`);
* anything else reports `0.1.0-dev.20260922+f60fb9f` — the version file, the commit date and the
  short commit, with `.dirty` appended when tracked files were modified;
* without git (a source tarball) the version file is used verbatim.

`moggi version --json` says what that means: `channel` (`release`, `dev`, or `source` when this is a
checkout rather than an archive), `commit` (dev builds), and `variant` (`moggi`, `moggi-php`,
`moggi-dotnet`, `moggi-jvm`, `moggi-minimal`), derived from the `runtime/` directories the
installation actually bundles — so a bug report identifies the archive it came from. Nothing in the
pipeline may use a `-dev.` string to decide which of two builds is newer: the tag is authoritative.

The release job asserts that the tag and `VERSION` agree — the tag *is* the version string
(`0.1.0`, no `v` prefix) — so a mis-tagged release fails instead of publishing under a number it does
not match.

The VS Code extension is not part of this release: it lives in its own repository
([moggi-lang/moggi-vscode-plugin](https://github.com/moggi-lang/moggi-vscode-plugin)), tags its own
versions, and is attached to its own release. A `.vsix` is installed through VS Code and never sits
inside a distribution archive, so the two version numbers are free to move independently — the
contract between them is the language server, and the plugin's CI checks that against a released
distribution.

## Channels

| Channel | Built from | Tag | Release |
|---|---|---|---|
| official | a version tag (`0.1.0`, no `v` prefix) | the tag | public (`-alpha`/`-beta` marked as pre-releases) |
| dev | the `build` branch | `dev-build` (one tag, moved onto every new build) | pre-release, never `latest` |
| snapshot | the default branch, daily | `snapshot-<date>` plus the moving alias `snapshot` | pre-release, never `latest` |

A snapshot or dev build is always a pre-release: GitHub treats the newest non-prerelease as
`releases/latest`, so marking one as normal would silently change what users download by default.
Official releases are kept forever, one per version tag. A dev build keeps no history at all:
`dev-build` is the only dev tag, deleted and recreated on each build of `build`, so it always names
the newest one and the per-build tags an earlier scheme left behind are removed. A snapshot that
would rebuild the commit already published is skipped.

Nothing merges *into* `build`: it marks the commit that should be built, and pushing to it triggers
the dev build. `workflow_dispatch` on `package` builds any ref on demand.

## What a release verifies

1. the test workflow is green on the commit being packaged;
2. the tag and `VERSION` agree, and the VS Code extension declares the same version;
3. every target builds, and every variant passes its smoke test;
4. the archives are uploaded, and `SHA256SUMS` is generated **from the uploaded files**;
5. provenance is attested, so a download can be traced to the workflow and commit that produced
   it (`gh attestation verify <archive> --repo <owner>/moggi`);
6. on a clean runner the *published* archive is downloaded, its checksum verified, unpacked and
   run — the check that a user's bytes are intact and self-contained.

Official releases are created as a draft and only made public once every step above has passed, so
a release is either complete or invisible. Users verify a download with
`sha256sum -c SHA256SUMS` (`shasum -a 256` on macOS); `quickstart.md` carries the commands.

## Signing

The archives are **not signed** yet: a browser download sets the quarantine attribute on macOS and
SmartScreen/Mark-of-the-Web on Windows, and `quickstart.md` tells the user how to clear both. What
signing costs and what it would take, so the decision can be made deliberately:

| Platform | Mechanism | Cost | Notes |
|---|---|---|---|
| macOS | **Developer ID** certificate + **notarization** | **$99/year** for the Apple Developer Program (the Enterprise Program is $299/year and is for internal distribution only); notarization itself is free | Requires the Account Holder role and an annual renewal; up to 5 Developer ID Application certificates per team. Notarizing means signing **every Mach-O in the archive** — `bin/moggi` and `bin/schnorr`, the bundled PHP and any libraries we ship — with the hardened runtime and a timestamp; a `.tar.gz` cannot be stapled, so the first launch needs the network to fetch the ticket |
| Windows | **Authenticode** OV certificate from a public CA, or EV | OV roughly $100–400/year, EV more; since the CA/B Forum change of March 2026 a code-signing certificate is only valid ~460 days, so it is an annual cost | Since 2023 the private key must live on FIPS 140-2 Level 2 hardware or an approved cloud HSM — a USB token cannot be used on a GitHub-hosted runner, so this means a cloud signing service |
| Windows | **Azure Artifact Signing** (formerly Trusted Signing) | reported at ~$10/month (Basic: 5,000 signatures/month) and ~$100/month (Premium) — Microsoft publishes the tiers, not the price in the docs | Needs a paid Azure subscription plus identity validation (organization or individual; supported countries/regions only) and gives short-lived, automatically rotated certificates |
| Windows | **free for open source**: SignPath Foundation, or Certum's Open Source Code Signing | $0 (SignPath is an OV-level certificate held by the foundation; Certum's is an individual-tier cloud certificate) | Both require an application and a documented release process; they exist precisely because a token would not work in CI |

Windows signing also does not remove the SmartScreen prompt for a low-reputation certificate: it
replaces "Unknown publisher" and the warnings decay as the certificate and files build reputation.
For 0.1-alpha the decision is to stay unsigned and document it; the natural first step afterwards is
to apply to the SignPath Foundation, since this is an open-source project, and keep Artifact Signing
(~$10/month) as the fallback.

## Building a distribution locally

```bash
php packaging/assemble.php --target linux-x86_64 --out .dist --archives
php packaging/assemble.php --variants moggi-minimal --archives --out /tmp/out
php packaging/assemble.php --target linux-x86_64 --force-runtimes
php packaging/pin.php --check --target linux-x86_64
```

`assemble.php` stages the installation, derives the variants, runs each variant's smoke test and
optionally writes the archives; `--help` lists the options. A path you pass means what it means to the
shell you started the script in: `--out` is anchored to that working directory immediately, and nothing
under `packaging` changes the working directory of the process, so a relative `MOGGI_DIST_CACHE` or
`--source` is read from there too. The smoke test runs `bin/moggi version`, compiles and runs an
example on every backend the variant bundles, builds an application PHAR, and generates a key with
`bin/schnorr`.
Building PHP from source needs `libzip` and `oniguruma` (`PKG_CONFIG_PATH` pointing at their
`lib/pkgconfig`); the dev shell sets that up. The distribution tests are a normal part of the suite:

```bash
php test.php distribution
```

## Keeping the runtimes current

When PHP, .NET, the JDK or GraalVM ships a release that matters (a security fix especially): bump
the version in `dist/runtimes.json`, run `php packaging/pin.php` to re-resolve and re-lock the
assets, and cut a patch release. The daily `runtime-check` workflow reports an upstream asset that
moved or disappeared (its findings land as a `runtime-pins` issue), so the trigger is a
notification rather than a user's bug report.

## The website

`moggi-lang.org` names the release in its download links, and those have to be literal (the page
works with JavaScript off), so they were a hand edit of 25 links on every release.
`packaging/website.php --tag <release>` now rewrites them from `dist/runtimes.json` — the `RELEASE`
constant, the archive table between the `archives:begin`/`archives:end` markers, the count and the
default download link and command — using the same `archiveName()` the assembler does, so the page
cannot name an archive the build does not produce. `--check` exits non-zero when the page does not
name the current release, which is what a release check wants. On a tag, the `website` job runs it
against the site once `WEBSITE_REPOSITORY` (a variable) and `WEBSITE_TOKEN` (a secret with push
access) are configured; without them the job is skipped rather than failing.

