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
| `MOGGI_CACHE_DIR` | `./.moggi` | Root of the per-project compile cache (and `moggi doc serve` output). |
| `MOGGI_NO_CACHE` | unset (cache on) | Disable the compile cache. |

### `MOGGI_ROOT`

Overrides where the standard library is looked for. `<MOGGI_ROOT>/lib` is tried
*first*, and is only accepted if it actually contains `Data/Eq.mog`. Without it
the compiler looks for `lib/` beside its own installation — for a distribution
that is `<installation>/lib`, next to `bin/` — and then next to its own sources
in a checkout. Set it when the compiler has been relocated away from its `lib/`.

### `MOGGI_CACHE_DIR`

Root of the compile cache. Defaults to `./.moggi` in the current working
directory. Trailing slashes are trimmed. Layout:

```text
<cache>/.fingerprint                                 fingerprint of the compiler that built it
<cache>/<backend>/<source-mirror>.<kind>.mogc        a compilation entry
<cache>/test-lib-<backend>-<signature>/              the test harness's stdlib build
<cache>/test-artifacts/{exec,logs}/                  harness scratch and failing-case logs
<cache>/docs-index/                                  the mogdoc/moogle index
```

One cache tree belongs to one compiler. `.fingerprint` hashes the compiler's own
sources; a compiler whose fingerprint does not match throws the whole tree away and
rebuilds instead of reading entries another compiler wrote — that is why clearing
the cache is never *required* after upgrading. `moggi cache clear` deletes the tree,
and the next compile rebuilds it.

`moggi doc serve` reuses the same variable as its default output root, writing
to `<cache>/mogdoc-serve` unless `--output` overrides it.

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

A case invokes the compiler in-process, or spawns `moggi.php` for the packaged backends, so the
compiler's own variables apply to a run exactly as they apply to `moggi` itself.

See [development/testing.md](development/testing.md) for how a run uses them.
