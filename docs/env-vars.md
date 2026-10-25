---
title: "Environment variables"
---
# Environment variables

Everything the compiler, the generated runtimes and the test harness read from the environment.

Moggi reads three groups: **its own variables** (below), the **host toolchain
variables** it honors when locating `java`, `dotnet` and friends, and a few
variables the **test harness** sets.

## Moggi's own variables

| Variable | Default | Effect |
|----------|---------|--------|
| `MOGGI_MICRO_SFX` | unset | The micro PHP runtime a `--native` build on the `php` backend appends the PHAR to. |
| `MOGGI_CACHE_DIR` | `./.moggi` | Root of the per-project cache: compile entries, fetched metadata, unpacked packages, `moggi mogdoc serve` output. |
| `MOGGI_NO_CACHE` | unset (cache on) | Disable the compile cache. |
| `MOGGI_MAX_FETCH_BYTES` | `268435456` (256 MiB) | Cap on any single registry fetch — one metadata shard, blob or `source` archive — however it is read: the registry, a mirror directory, or a reader a caller supplies. |
| `MOGGI_REGISTRY` | the canonical registry | The registry a *read* command uses when `--registry` is not given. |
| `MOGGI_REGISTRY_WRITE` | the registry's write origin | The registry a *write* command — `publish`, `bad`, `unmaintained`, `takeover` — uses when `--registry` is not given; falls back to `MOGGI_REGISTRY`. |
| `MOGGI_REGISTRY_NPUB` | unset (trust on first use) | Pin the registry's signing key. |
| `MOGGI_USER_CACHE` | `$XDG_CACHE_HOME/moggi`, else `$HOME/.cache/moggi` | The user-level cache: downloaded archives, plus the remembered key and accepted root version of each registry. |

### `MOGGI_MICRO_SFX`

Names the micro PHP runtime that `moggi compile --backend php --native` appends the
packaged PHAR to — the PHP counterpart of GraalVM's `native-image` for `jvm`. It
accepts the runtime file itself, or a directory holding `micro.sfx`. A
distribution that bundles PHP carries one under `runtime/php-native/`, which is
where the compiler looks when this is unset, so setting it is only needed in a
checkout, for an installation whose runtime lives somewhere else, or for the
`--native` test smoke. Without a runtime, `--native` on `php` fails with an error
naming this variable; it is never a silent fallback to the PHAR.

### `MOGGI_REGISTRY`, `MOGGI_REGISTRY_WRITE` and `MOGGI_REGISTRY_NPUB`

`MOGGI_REGISTRY` selects the registry for `install`, `update`, `build`, `verify`,
`show`, `search`, `outdated` and `why`; it may be an `https` URL or a directory,
which is how a mirror, a checkout and the canonical registry are one code path.

The registry's **reads and writes are two origins**. The tree is static and is
served from the registry itself; the API that accepts a signed write answers on a
host of its own, because every request signature binds the host it was made for
— so a client pointed at the read origin would have every write refused as
`bad-signature`, never quietly redirected. `MOGGI_REGISTRY_WRITE` selects that
origin for `publish`, `bad`, `unmaintained` and `takeover`. A write with neither
`--registry` nor this variable falls back to `MOGGI_REGISTRY`, because a machine
pointed at another registry meant both halves of it, and only then to the
project's write default.

A registry's signature proves the root is internally consistent, not that it is
the registry you meant. `MOGGI_REGISTRY_NPUB` pins the signing key, and any
difference is refused; without a pin the first key seen for a base is remembered
and every later change is refused, so a key that changes after first contact is
always an error rather than a silent trust refresh.

A signed root carries `version` and `expires` (RFC 3339), and the client refuses
a root that is past its expiry or below the highest version it has already
accepted for that base. That is what makes a **frozen** or **replayed** root
visible: a signature over an old root verifies exactly as well as one over the
current root, so freshness has to be signed and checked rather than assumed.
There is no waiver: a stale or replayed root is always refused.

### `MOGGI_CACHE_DIR`

Root of the project cache. Defaults to `./.moggi` in the current working
directory. Trailing slashes are trimmed. One root holds everything moggi keeps
between runs, each kind of thing under a directory named for what put it there:

```text
<cache>/fingerprint                                    the compiler that filled the rest
<cache>/compile/<backend>/<source-mirror>.<kind>.mogc  a compilation entry
<cache>/compile/artifacts/<key>.blob                   a whole-project artifact
<cache>/compile/docs-index/<key>.json                  the mogdoc/moogle index
<cache>/docs/                                          `moggi mogdoc serve` output
<cache>/catalog/<registry-key>/                        fetched registry metadata
<cache>/packages/<name>/<digest>/                      an unpacked package
<cache>/runtime/<key>/                                 an artifact a host tool fetched
<cache>/test/                                          the test harness's scratch trees
```

One compiler owns the tree. `fingerprint` hashes the compiler's own sources; a
compiler whose fingerprint does not match drops what the compiler *derives*
(`compile/`, `docs/`) and keeps what it merely *found* (`catalog/`, `packages/`,
`runtime/` — all addressed by digest), which is why clearing the cache is never
*required* after upgrading. `moggi cache info` shows each area and its size, and
`moggi cache clear [<area>]` drops one at a time:

| Area | What goes |
|------|-----------|
| `compiler` | module entries, project artifacts, the docs index and the served site |
| `catalog` | fetched registry metadata |
| `packages` | unpacked packages |
| `runtime` | artifacts a host tool (maven, composer, nuget) fetched |
| `test` | the test harness's scratch trees |
| `downloads` | the user-level download cache, shared by every project |
| `all` | every area above — the default |

`moggi mogdoc serve` reuses the same variable as its default output root, writing
to `<cache>/docs` unless `--output` overrides it.

### `MOGGI_NO_CACHE`

Set it to any value other than empty or `0` to disable cache reads and writes:

```bash
MOGGI_NO_CACHE=1 php moggi.php run app.mog
```

`--no-cache` / `--cache` on the command line take precedence over this variable.
Useful when bisecting a codegen bug and you want certainty that nothing is being
reused.

## Host toolchain variables

Read only to find the host executables, and nothing about them is
Moggi-specific: the `jvm` backend uses `JAVA_HOME` the way any Java tool does,
and the `dotnet` backend uses `DOTNET_ROOT` the way the SDK does. Both fall back
to `PATH`. Moggi never sets them.

| Variable | Used for |
|----------|----------|
| `JAVA_HOME` | `bin/java` (`moggi run --backend jvm`) and `bin/native-image` (`--native`), preferred when set; `bin/javap` fallback for the REPL |
| `GRAALVM_HOME`, `JDK_HOME` | `bin/javap` fallback for the REPL, after `JAVA_HOME` |
| `DOTNET_ROOT` | `dotnet`, and `<DOTNET_ROOT>/packages` as a NuGet root |
| `NUGET_PACKAGES` | NuGet package root |
| `XDG_CACHE_HOME`, `HOME` | locating the .NET workspace root |
| `PATH` | discovery for `java`, `javac`, `ilasm`, `javap`, `dotnet` |

Resolution differs by tool: `java` and `native-image` use `JAVA_HOME` **first**
and fall back to `PATH`; `javap` tries `command -v` first and only then the
`*_HOME` variables; `dotnet` uses `DOTNET_ROOT` first, then `PATH`.

The `--backend dotnet` fast path assembles with CoreCLR `ilasm`, which the .NET
SDK does not put on `PATH` by itself. It is found in this order, with no setting
of its own: `ilasm` on `PATH`, then the `runtime.<rid>.microsoft.netcore.ilasm`
package in the NuGet caches (`$NUGET_PACKAGES`, `$HOME/.nuget/packages`,
`$DOTNET_ROOT/packages`), then a one-time `Microsoft.NET.Sdk.IL` restore that
puts that package in the user NuGet cache. When none of them yields a binary —
offline, or a platform the RID mapping (`<os>-<arch>` from `PHP_OS_FAMILY` and
`php_uname('m')`) does not cover — packaging falls back to
`dotnet build --no-restore` in a workspace under `$XDG_CACHE_HOME/moggi`, or
`$HOME/.cache/moggi`, or the system temp dir, whichever is writable first.

## Test harness

The complete list of what the test harness reads. None is required: every one has a working
default.

| Variable | Default | Effect |
|----------|---------|--------|
| `MOGGI_CACHE_DIR` | pinned to `<repo>/.moggi` | Where the compile cache lives. Pinned *only* when it is unset, so a test run never writes into your working directory's cache and an explicit choice wins. |
| `MOGGI_NO_CACHE` | unset (cache on) | Inherited: bypasses the cache for the compiler the cases invoke. |
| `JAVA_HOME` | probed | Locating `java` and `native-image` for jvm cases. |
| `MOGGI_MICRO_SFX` | unset | The micro PHP runtime the `--native` smoke on php builds with. Without one the verdict is a skip, never a pass. |

A case invokes the compiler in-process, or spawns `moggi.php` for the packaged backends, so the
compiler's own variables apply to a run exactly as they apply to `moggi` itself.

See [development/testing.md](development/testing.md) for how a run uses them.
