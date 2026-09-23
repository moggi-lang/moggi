# Compiler

The Moggi compiler and programs written in Moggi live under `src/`.

## Bootstrap compiler (PHP)

The bootstrap compiler is implemented in PHP until a self-hosted version replaces it:

| File | Role |
|------|------|
| `moggi.php` | CLI entry shim (calls `Moggi\CLI\main`) |
| `src/CLI/` | argv parsing, dispatch, and command orchestration |
| `test.php` | Golden-file test runner (project root) |
| `src/compiler.php` | Compile API (`compile`, `compileFile`, …) |
| `src/syntax/` | Lexer, parser, and AST |
| `src/semantics/` | Type/kind/effect checking and intrinsic registry |
| `src/modules/` | Module graph, path planning, imports, exports, and linking |
| `src/IR/` | IR representation, lowering, visiting, and dumping |
| `src/optimize/` | IR optimization passes |
| `src/backend/` | Target backends (PHP / JVM / .NET) and shared backend helpers |
| `src/docs/` | mogdoc (HTML API docs) and moogle (API search) |

### Backend layout

Every backend uses the same file set, so one concern lives in the same-named
file regardless of target:

| File | Role |
|------|------|
| `<backend>/<X>Backend.php` | `Backend` implementation (target id, extension, artifact path, symbol mangling, `describeEmit`) |
| `<backend>/naming.php` | Module → package/type names, symbol mangling |
| `<backend>/codegen.php` | IR → target code |
| `<backend>/foreign.php` | Foreign-import path/descriptor rules |
| `<backend>/dependencies.php` | Library-owned dependency discovery |
| `<backend>/runtime_abi.php` | Generated language runtime (JVM / .NET) |
| `<backend>/frames.php` | Host stack trace → `.mog` frame table (JVM / .NET) |
| `<backend>/package.php` | Finalize the build directory into the artifact |
| `src/backend/ir_meta.php` | Backend-neutral IR metadata shared by the emitters |
| `src/backend/Backend.php` | `Backend` interface and compile-target registry |
| `src/backend/inspect.php` | `describeEmit` helpers: render emitted artifacts for tooling (`:emit`) |

Files that describe a specific toolchain keep their own names: `jvm/classfile.php`
(class-file encoding), `dotnet/il.php` + `dotnet/il_size.php` (IL text and
instruction sizing), `php/emit_expr.php` + `php/emit_match.php` (expression and
match emission), and `php/intrinsics.php` + `php/io.php` (intrinsic and IO
emission). The PHP runtime ships as `php/runtime.php` (deployed as `_runtime.php`)
rather than generated
`runtime_abi.php`, because the JVM/.NET runtimes are emitted per build.

Run from the project root (or use the `moggi` / `runtest` commands):

```bash
moggi compile examples/twice
runtest
```

## Documentation and search

Doc comments (`-- |`, export-list `-- *`) in `.mog` sources are extracted by **mogdoc** and rendered to static HTML. **moogle** searches the same index by name or by type signature. What they are for, from a user's side, is [stdlib.md](../stdlib.md); what matters here:

```bash
moggi moogle --root tests/docs/fixture id   # index an arbitrary tree, not just lib/
```

All modules are indexed, including `Moggi.Internal.*` (compiler-synthesized primops) and backend implementations (`*.PHP`, `*.JVM`, `*.DotNet`). Facade modules with `backend` declarations show linked backend implementations on their mogdoc page. Symbols not on a module's export list are omitted from that module's page.

For local preview: `moggi mogdoc serve`. For production, generate static HTML with `moggi mogdoc lib -o public/doc` and serve that directory with any static file host — do not run the built-in PHP dev server in production.

Static mogdoc output includes **`search-index.json`** (schema version 1, search rows only, ~25 KB gzipped for stdlib; includes a content `revision` for cache busting) and **`moogle.js`** (bundled from `moogle.client.js`, kept in sync with `moogle_search.php`). Every HTML page loads both; the browser runs full Moogle search with no backend. Use `moggi moogle` for terminal search against the same index.

Indexing type-checks modules (PHP backend, compile cache) so inferred signatures and constraints appear in the index. Re-exports collapse to one moogle hit with alias modules listed.

## Optimizations

IR optimizations run by default before codegen. See [optimizations.md](optimizations.md) for pass details and inspection flags.

For the phase-by-phase compiler architecture and refactoring boundaries, see
[architecture.md](architecture.md).

## CLI layout

`moggi.php` is a thin shim. Command handling lives under `src/CLI/`:

| Path | Role |
|------|------|
| `main.php` | Dispatch table: subcommand name → handler |
| `usage.php` | Help text, unknown-subcommand hints |
| `args.php` | Shared flags (`--backend`, `--lib`, …) |
| `paths.php` | `findMogFiles`, `resolveCompileInputs`, `resolveLibraryDirs` |
| `compile.php` | Single-file compile path |
| `commands/*.php` | One file per subcommand (`compile`, `run`, `repl`, …) |

Libraries (`src/pipeline/`, `src/modules/`, `src/docs/`, `src/repl/`) do the work; CLI only parses argv and calls them.
