# Packaging and releases

How Moggi is built, tested and published. This page is for people working on the
compiler; if you just want to *use* a release, read
[quickstart.md](../quickstart.md).

The workflow sources are `.github/workflows/test.yml`, `.github/workflows/package.yml` and
`.github/workflows/runtime-check.yml`; the packaging pipeline itself is `dist/README.md` and
`scripts/dist/`.

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
| windows-aarch64 | — | **no** | unsupported: php.net publishes no ARM64 Windows PHP, so there is neither a PHP to bundle nor one to run the build with. `php scripts/dist/pin.php` reports it. |

Architecture-specific jobs exist to catch code generators and runtimes that only work on one
machine shape; a target that cannot be run is not published.

## The five distributions

`moggi` (PHP + .NET + JDK + GraalVM), `moggi-php`, `moggi-dotnet`, `moggi-jvm`, `moggi-minimal`
(nothing bundled). They differ only in `runtime/`: one target is built once — compiler archive,
launcher, docs, examples, one prepared copy of each runtime — and the smaller variants are derived
from it by removing runtime directories. Each variant is smoke-tested (the launcher reports its
version, an example is compiled and run on each backend it bundles, and a PHAR artifact is built and
executed — the last one exists because writing a PHAR needs a second PHP process, and only a
distribution runs the compiler from inside an archive) before it is archived.

An installation is `bin/moggi` (the C launcher) plus `bin/moggi.phar` (the compiler: every file
under `src/`, and the `VERSION` entry naming this build), `bin/schnorr` (a standalone BIP-340 CLI
with no user in the language yet — it ships because it is part of the toolbox and costs nothing at
run time), the standard library as ordinary sources under `lib/`, `docs/`, `examples/`,
`LICENSE`, `THIRD-PARTY-NOTICES.md` (see [Licences](#licences)), and `runtime/`. The library sits beside the archive rather than inside
it: users can read the sources they compile against, and the installation's `LICENSE` plainly covers
them. It also removes the archive's only reason to write outside its own installation. The `moggi-lsp` extension is *not* bundled: it ships as a `.vsix` asset beside the
archives, because VS Code installs extensions from the Marketplace or a file it is given, not from
a directory inside an unpacked compiler. The compiler's sources are shipped *inside* the archive and nowhere else — the launcher
puts a bundled runtime in front of `PATH`, so `php`, `javac`, `java`, `native-image` and `dotnet`
resolve to the bundled copies when there are any, and to the host's otherwise. A runtime that is
neither bundled nor on `PATH` is a clear error, reported by `moggi version` as `not found`.

## Runtimes

Versions, upstream URL templates and the variant definitions live in `dist/runtimes.json`; the
exact URL and checksum of every asset, per target, live in `dist/runtimes.lock.json` — written by
`php scripts/dist/pin.php`. `scripts/dist/runtimes.php` downloads, verifies, extracts, validates
and caches one runtime for one target; the runners' own installations are never used as bundled
runtimes.

`php scripts/dist/pin.php --check --target <target>` re-validates every asset: reachable, on the
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
`ctype`, which is exactly what the compiler uses. Everything else — .NET SDK, JDK, GraalVM — is
downloaded. Downloads and builds are cached under `.dist-cache/`, keyed by the lock hash: a cache
hit makes a packaging job minutes long, and a miss makes it slower, never wrong.

### Portability floor

A bundled runtime cannot be more portable than the machine it was built on, so the floor is
enforced rather than intended:

* **glibc 2.35** (Ubuntu 22.04 LTS), from `linuxGlibcFloor` in `dist/runtimes.json`. The Linux
targets build inside an `ubuntu:22.04` container — both architectures, since the image is
multi-arch — so the source-built PHP and the launcher are linked against that libc whichever
runner produced them. Both are then checked: `assemble.php --glibc-floor` reads the highest
`GLIBC_` symbol the binary asks for with `objdump -T` and **fails the build** if it is above the
floor. Only the release jobs pass that flag; a local build on a newer machine is expected to miss
it, which is exactly why the check is opt-in.
* **macOS deployment target** set explicitly for the launcher and the source-built PHP, since the
  runner's default would otherwise become the minimum macOS version.

A distribution that needs a newer libc than this is a bug, not a documentation problem.

## Licences

Every distribution bundles other people's runtimes, so it has to carry their terms:

| Bundled | Licence |
|---|---|
| PHP | PHP License 3.01 (php.net publishes no binary for Linux or macOS, so this is the source build) |
| .NET SDK | MIT on Linux and macOS; the .NET Library License on Windows |
| JDK (Eclipse Temurin) | GPLv2 with the Classpath Exception |
| GraalVM Community | GPLv2 with the Classpath Exception |

Each bundled runtime keeps its own licence files inside `runtime/<name>/`, and every variant ships
`THIRD-PARTY-NOTICES.md`, which names what is bundled, the version, the licence and any
acknowledgement upstream asks redistributors to carry (PHP, for one, requires the "This product
includes PHP software…" line). `scripts/dist/runtimes.php` stages the licence that an archive does
not provide itself — php.net's source tarball and Windows zip ship neither — and
`scripts/dist/assemble.php` refuses to build a variant whose bundled runtime has no licence file.

`bin/schnorr` is the project's own source, but it statically links two libraries whose sources are
not part of this repository: libsecp256k1 (MIT) and libbech32 (WTFPL v2). MIT requires its notice to
travel with binary distributions, so the notices page repeats both texts in full and is written for
every variant, including `moggi-minimal`, and the build fails if either library's licence file is
missing from the fetched sources.

No runtime is modified: each is the unmodified upstream build, so the upstream terms are the ones
that apply.

## The schnorr CLI

`schnorr/deps.json` pins libsecp256k1 at `v0.8.0` and libbech32 at `v1.1p1` by tag *and* commit,
with the licence file each one carries. `scripts/dist/schnorr.php` clones any of them that is not
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
php scripts/dist/schnorr.php                 # fetch the pinned sources and build in place
./schnorr/tests/bip340_test.sh               # the official BIP-340 vectors
```

## Version identity

`VERSION` at the repository root is the release number. `build-phar.php` bakes the *build*
identity into the archive's own `VERSION` entry, so a distribution still carries no file for a user
to edit by accident and `moggi version` reads it from inside the phar:

* a clean commit that *is* a tag reports the tag (`0.0.1`);
* anything else reports `0.0.1-dev.20260922+f60fb9f` — the version file, the commit date and the
  short commit, with `.dirty` appended when tracked files were modified;
* without git (a source tarball) the version file is used verbatim.

`moggi version --json` says what that means: `channel` (`release`, `dev`, or `source` when this is a
checkout rather than an archive), `commit` (dev builds), and `variant` (`moggi`, `moggi-php`,
`moggi-dotnet`, `moggi-jvm`, `moggi-minimal`), derived from the `runtime/` directories the
installation actually bundles — so a bug report identifies the archive it came from. Nothing in the
pipeline may use a `-dev.` string to decide which of two builds is newer: the tag is authoritative.

The release job asserts that the tag and `VERSION` agree — the tag *is* the version string
(`0.0.1`, no `v` prefix) — so a mis-tagged release fails instead of publishing under a number it does
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
| official | a version tag (`0.0.1`, no `v` prefix) | the tag | public (`-alpha`/`-beta` marked as pre-releases) |
| dev | the `build` branch | `dev-<date>-<sha>` plus the moving alias `dev` | pre-release, never `latest` |
| snapshot | the default branch, daily | `snapshot-<date>` plus the moving alias `snapshot` | pre-release, never `latest` |

A snapshot or dev build is always a pre-release: GitHub treats the newest non-prerelease as
`releases/latest`, so marking one as normal would silently change what users download by default.
Dev and snapshot releases are pruned (last few kept), official releases are kept forever, and a
snapshot that would rebuild the commit already published is skipped.

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
| macOS | **Developer ID** certificate + **notarization** | **$99/year** for the Apple Developer Program (the Enterprise Program is $299/year and is for internal distribution only); notarization itself is free | Requires the Account Holder role and an annual renewal; up to 5 Developer ID Application certificates per team. Notarizing means signing **every Mach-O in the archive** — the launcher, the bundled PHP and any libraries we ship — with the hardened runtime and a timestamp; a `.tar.gz` cannot be stapled, so the first launch needs the network to fetch the ticket |
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
export MOGGI_DIST_CC=clang        # clang is what a release is built with
php scripts/dist/assemble.php --target linux-x86_64 --out .dist --archives
php scripts/dist/assemble.php --variants moggi-minimal --archives --out /tmp/out
php scripts/dist/assemble.php --target linux-x86_64 --force-runtimes
php scripts/dist/pin.php --check --target linux-x86_64
```

`assemble.php` stages the installation, derives the variants, runs each variant's smoke test and
optionally writes the archives; `--help` lists the options. The smoke test runs `version`, compiles
and runs an example on every backend the variant bundles, builds an application PHAR, and generates
a key with `bin/schnorr`. Building PHP from source needs
`libzip` and `oniguruma` (`PKG_CONFIG_PATH` pointing at their `lib/pkgconfig`); the dev shell sets
that up. The distribution tests are a normal part of the suite:

```bash
php test.php distribution
```

## Keeping the runtimes current

When PHP, .NET, the JDK or GraalVM ships a release that matters (a security fix especially): bump
the version in `dist/runtimes.json`, run `php scripts/dist/pin.php` to re-resolve and re-lock the
assets, and cut a patch release. The daily `runtime-check` workflow reports an upstream asset that
moved or disappeared (its findings land as a `runtime-pins` issue), so the trigger is a
notification rather than a user's bug report.

