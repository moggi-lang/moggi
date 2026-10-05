# Environment variables

Everything the compiler, the generated runtimes and the test harness read from the environment.

Moggi reads three groups: **its own variables** (below), the **host toolchain
variables** it honors when locating `java`, `dotnet` and friends, and a few
variables the **test harness** sets. Nothing else is consulted — there is no
configuration file, and no per-project settings beyond the compile cache.

> Two `MOGGI_*` names in the source are **not** environment variables:
> `MOGGI_PROJECT_ROOT` is a PHP constant the test harness defines, and
> `MOGGI_TRACE_FRAME_LIMIT` is the frame cap emitted into the generated
> runtimes. Setting either in the environment has no effect.

## Moggi's own variables

| Variable | Default | Effect |
|----------|---------|--------|
| `MOGGI_ROOT` | the installation or checkout | Locate the standard library: `<MOGGI_ROOT>/lib`. |
| `MOGGI_MICRO_SFX` | unset | The micro PHP runtime a `--native` build on the `php` backend appends the PHAR to. |
| `MOGGI_CACHE_DIR` | `./.moggi` | Root of the per-project cache: compile entries, fetched metadata, unpacked packages, `moggi mogdoc serve` output. |
| `MOGGI_NO_CACHE` | unset (cache on) | Disable the compile cache. |
| `MOGGI_MAX_RESPONSE_BYTES` | `268435456` (256 MiB) | Cap on any single fetched body — registry metadata, a blob, or a `source` archive. |
| `MOGGI_ALLOW_LOCAL_SOURCES` | unset | Allow a release's `source` to reproduce from a local path or `file://` URL when the registry does not serve its blob; without it, only `https` URLs are fetched. |
| `MOGGI_REGISTRY` | the canonical registry | The registry a packaging command uses when `--registry` is not given. |
| `MOGGI_REGISTRY_NPUB` | unset (trust on first use) | Pin the registry's signing key. |
| `MOGGI_USER_CACHE` | `$XDG_CACHE_HOME/moggi`, else `$HOME/.cache/moggi` | The user-level cache: downloaded archives, plus the remembered key and accepted root version of each registry. |
| `MOGGI_ALLOW_STALE_REGISTRY` | unset | Accept a signed root that has expired, or that is older than one this machine has already accepted. |
| `MOGGI_SCHNORR` | the `schnorr` beside the installation | Path to the verifier/signer binary. A test and CI override; the search still never uses `PATH`. |

### `MOGGI_ROOT`

Overrides where the standard library is looked for. `<MOGGI_ROOT>/lib` is tried
*first*, and is only accepted if it actually contains `base.moggi`. Without it
the compiler looks for `lib/` beside its own installation — for a distribution
that is `<installation>/lib`, next to `bin/` — and then next to its own sources
in a checkout. Set it when the compiler has been relocated away from its `lib/`.

### `MOGGI_MICRO_SFX`

Names the micro PHP runtime that `moggi compile --backend php --native` appends the
packaged PHAR to — the PHP counterpart of GraalVM's `native-image` for `jvm`. It
accepts the runtime file itself, or a directory holding `micro.sfx`. A
distribution that bundles PHP carries one under `runtime/php-native/`, which is
where the compiler looks when this is unset, so setting it is only needed in a
checkout, for an installation whose runtime lives somewhere else, or for the
`--native` test smoke. Without a runtime, `--native` on `php` fails with an error
naming this variable; it is never a silent fallback to the PHAR.

### `MOGGI_ALLOW_LOCAL_SOURCES`

A release's `source` (§4.3 of the registry spec) is the bytes of a package from
another origin when the registry does not serve its blob. The release that names
it is attacker-controlled in the case that matters — a registry answering `404`
for `blobs/<digest>` can name any `source` — so the client fetches only ordinary
`https` URLs: git remote helpers (`ext::sh -c …`), `file://`, and bare paths are
refused. Set this variable to a truthy value (`1`, `true`, `yes`, `on`) to allow
local paths and `file://` URLs as well, which is what reproducing a package from
a checkout needs. A local `source` that does not pack to the blob digest reports
the expected digest but withholds the one it produced.

### `MOGGI_REGISTRY` and `MOGGI_REGISTRY_NPUB`

`MOGGI_REGISTRY` selects the registry for `install`, `update`, `build`, `verify`
and `publish`; it may be an `https` URL or a directory, which is how a mirror, a
checkout and the canonical registry are one code path.

A registry's signature proves the root is internally consistent, not that it is
the registry you meant. `MOGGI_REGISTRY_NPUB` pins the signing key, and any
difference is refused; without a pin the first key seen for a base is remembered
and every later change is refused, so a key that changes after first contact is
always an error rather than a silent trust refresh.

### `MOGGI_ALLOW_STALE_REGISTRY`

A signed root carries `version` and `expires` (RFC 3339), and the client refuses
a root that is past its expiry or below the highest version it has already
accepted for that base. That is what makes a **frozen** or **replayed** root
visible: a signature over an old root verifies exactly as well as one over the
current root, so freshness has to be signed and checked rather than assumed.

Set this variable to a truthy value (`1`, `true`, `yes`, `on`) to waive both
checks — for a development registry whose clock or counters are not maintained,
or to accept a deliberate downgrade. The highest version seen is still recorded
while the waiver is on, so a later run without it compares against the real
high-water mark.

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
| `MOGGI_FUZZ_DIR` | `<cache>/fuzz-failures` | Where the frontend fuzzer keeps generated inputs and minimised reproducers. |
| `MOGGI_TEST_STANDALONE_TIMEOUT` | `300` | Watchdog seconds for one `*_test.php` case before it is killed and reported as `timeout`. |
| `MOGGI_TEST_CPU_COUNT` | probed | Pin the machine's CPU count instead of probing it, for a run that asserts a worker count. |
| `MOGGI_TEST_FORCE_TTY` | unset | `1` makes stderr count as a terminal, so the live progress block is on without a pty. |
| `MOGGI_TEST_COLUMNS` | `COLUMNS`, the terminal, 80 | Progress-block width. |
| `MOGGI_TEST_LINES` | `LINES`, the terminal, 24 | Progress-block height; a taller block folds its earliest rows. |
| `NUMBER_OF_PROCESSORS` | probed | Windows' CPU count, used only to size an automatic `--jobs`. |
| `JAVA_HOME` | probed | Locating `java` and `native-image` for jvm cases. |
| `MOGGI_MICRO_SFX` | unset | The micro PHP runtime the `--native` smoke on php builds with. Without one the verdict is a skip, never a pass. |

A case invokes the compiler in-process, or spawns `moggi.php` for the packaged backends, so the
compiler's own variables apply to a run exactly as they apply to `moggi` itself.

See [development/testing.md](development/testing.md) for how a run uses them.
