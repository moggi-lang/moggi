# Moggi Language Server

The Moggi compiler **is** the language service: one long-lived PHP process owns
typed AST, Merkle caches, export origins, doc comments/moogle, and a project-wide
occurrence index (module-scoped binder ids + call edges).

```bash
php moggi.php lsp
```

Flags: `--lib PATH` (repeatable), `--backend B` (default `php` for LSP).

## VS Code

Install the extension from
[moggi-lang/moggi-vscode-plugin](https://github.com/moggi-lang/moggi-vscode-plugin).
It starts this server from a compiler it finds — a checkout, or an installed
distribution via `moggi.serverPath` (`<dist>/bin/moggi.phar`).

| Surface | Notes |
|---------|--------|
| Commands | **Restart Language Server**, **Show Output** |
| Setting `moggi.inlayHints` | Toggle type inlays |
| Setting `moggi.libPaths` | Extra `--lib` roots passed to `moggi.php lsp` |
| Setting `moggi.testRoot` | Test Explorer fixture dir (default `tests/backend/runtime`; empty if missing) |
| Setting `moggi.backend` | Documented preference; Run/LSP use PHP today |
| Status bar | `Moggi` / `Moggi: checking…` via `moggi/status` (+ `$/progress` when the client supports workDoneProgress) |
| Run | **Moggi: Run (PHP)** — compile + run only; breakpoints are unverified until Xdebug |
| Test Explorer | Discovers `moggi.testRoot` or `tests/backend/runtime/*.mog`; no hard-fail outside the repo |
| Tasks | `moggi: compile`, `moggi: run` |

The server loads the compiler once, at startup, and keeps it: while you develop
Moggi itself, an edit under `src/` changes nothing until the server restarts, and
the stale one keeps answering. It therefore watches its own revision (~6 ms on an
idle tick) and says so when the sources move on — a warning naming the revision it is
running and the one on disk, in the output log every time and once as a notification.
**Moggi: Restart Language Server** applies the new compiler.

## Capabilities

| Feature | Status |
|---------|--------|
| Diagnostics (parse immediate; type after debounce) | yes |
| Stable diagnostic codes (`hole`, `non-exhaustive`, `unify`, `parse`, …) | yes |
| relatedInformation (holes, missing patterns, unify) | yes |
| Typed holes `_` (expected type diag + fill stubs + hover/inlay) | yes |
| Hover (scheme + constraints + origin + docs) | yes |
| Completion (locals, imports, auto-import, moogle + fuzzy rank) | yes |
| Record field labels: completion inside `{ … }` (works unparsed) and hover on a label | yes |
| `completionItem/resolve` (docs/type) | yes |
| Signature help (`FunctionDecl.params` + active) | yes |
| Definition / typeDefinition / implementation | yes |
| References / documentHighlight (binder-aware, cross-file) | yes |
| Rename / prepareRename (module-scoped binders; cross-file exports) | yes |
| Linked editing (binder-scoped, same document) | yes |
| Workspace symbols (ModuleIndex + moogle when warm) | yes |
| Document symbols (hierarchical) | yes |
| Semantic tokens (typed AST + delta) | yes |
| Inlay hints (PatVar + typed holes) | yes |
| Code actions (import, typed hole fill, missing case arms, signatures) | yes |
| Code lens (reference counts) | yes |
| Folding / selection range (parent chain) | yes |
| Formatting + range formatting (intersecting top-level items) | yes |
| Call hierarchy (incoming + outgoing via App/Infix edges) | yes |
| Type hierarchy (class supers + instances as subtypes) | yes |
| Incremental sync + watched files | yes |
| willRenameFiles (nested modules + qualified/`as` imports) | yes |
| Stdlib warm + `moggi/status` / `$/progress` on analyze | yes |
| Run (PHP) launch adapter | yes (extension; **not** a full debugger) |
| Test Explorer | yes (extension) |

## LSP 3.18 Feature Matrix

The Moggi language server implements the following LSP 3.18 features:

### Initialization & Lifecycle

| Feature | Status | Notes |
|---------|--------|-------|
| initialize | ✅ Complete | Returns server capabilities + serverInfo |
| shutdown | ✅ Complete | Proper state validation, -32002/-32600 errors |
| exit | ✅ Complete | Returns 0 after shutdown, 1 without |
| $/cancelRequest | ✅ Complete | No-op (single-threaded, spec-permitted) |
| $/setTrace | ✅ Complete | Supports "off", "messages", "verbose" |
| client/registerCapability | ✅ Complete | Stores registrations, returns success |
| client/unregisterCapability | ✅ Complete | Removes registrations, returns success |

### Server Capabilities

| Capability | Status | Dynamic Registration |
|------------|--------|---------------------|
| textDocumentSync | ✅ Complete | Yes (incremental change: 2) |
| hoverProvider | ✅ Complete | Yes |
| definitionProvider | ✅ Complete | Yes |
| declarationProvider | ✅ Complete | Yes |
| typeDefinitionProvider | ✅ Complete | Yes |
| implementationProvider | ✅ Complete | Yes |
| referencesProvider | ✅ Complete | Yes |
| documentHighlightProvider | ✅ Complete | Yes |
| completionProvider | ✅ Complete | Yes (resolve + trigger chars) |
| signatureHelpProvider | ✅ Complete | Yes (trigger chars) |
| documentSymbolProvider | ✅ Complete | Yes (hierarchical) |
| workspaceSymbolProvider | ✅ Complete | Yes |
| semanticTokensProvider | ✅ Complete | Yes (full+delta, no range) |
| renameProvider | ✅ Complete | Yes (prepareProvider) |
| codeActionProvider | ✅ Complete | Yes (resolve + kinds) |
| codeLensProvider | ✅ Complete | Yes (resolve: false) |
| foldingRangeProvider | ✅ Complete | Yes |
| selectionRangeProvider | ✅ Complete | Yes |
| documentFormattingProvider | ✅ Complete | Yes |
| documentRangeFormattingProvider | ✅ Complete | Yes |
| onTypeFormattingProvider | ✅ Complete | Yes (trigger chars) |
| inlayHintProvider | ✅ Complete | Yes (resolve) |
| callHierarchyProvider | ✅ Complete | Yes |
| typeHierarchyProvider | ✅ Complete | Yes |
| linkedEditingRangeProvider | ✅ Complete | Yes |
| documentLinkProvider | ✅ Complete | Yes (resolve) |
| colorProvider | ✅ Complete | N/A (object format) |
| inlineValueProvider | ✅ Complete | Yes |
| monikerProvider | ✅ Complete | Yes |
| diagnosticProvider | ✅ Complete | Yes (pull diagnostics) |

### Workspace Features

| Feature | Status | Notes |
|---------|--------|-------|
| workspaceFolders | ✅ Complete | Supported + change notifications |
| didChangeWorkspaceFolders | ✅ Complete | Notification handled |
| didChangeConfiguration | ✅ Complete | Settings (inlayHints toggle) |
| didChangeWatchedFiles | ✅ Complete | File invalidation |
| executeCommand | ✅ Complete | No-op (no commands advertised) |
| willRenameFiles | ✅ Complete | Import rewriting |
| workspace/diagnostic | ✅ Complete | Returns empty (workspaceDiagnostics: false) |
| workspace/diagnostic/refresh | ✅ Complete | No-op handler |

### Text Document Synchronization

| Feature | Status | Notes |
|---------|--------|-------|
| didOpen | ✅ Complete | Parse diagnostics immediately |
| didChange | ✅ Complete | Incremental (contentChanges) |
| didSave | ✅ Complete | Triggers analyze |
| didClose | ✅ Complete | Clears diagnostics |
| publishDiagnostics | ✅ Complete | Versioned, debounced |

### Diagnostics

| Feature | Status | Notes |
|---------|--------|-------|
| PublishDiagnostics | ✅ Complete | Parse + type errors |
| Pull Diagnostics | ✅ Complete | textDocument/diagnostic (kind: full) |

### Semantic Tokens

| Feature | Status | Notes |
|---------|--------|-------|
| Full Semantic Tokens | ✅ Complete | Full document tokens with delta |
| Range Semantic Tokens | ✅ Complete | Range-based tokens (LSP 3.18) |
| Delta Support | ✅ Complete | Efficient delta updates |
| Caching | ✅ Complete | Semantic token cache with version tracking |

### Navigation Features

| Feature | Status | Notes |
|---------|--------|-------|
| Hover | ✅ Complete | Markdown, types, constraints, docs |
| Go to Definition | ✅ Complete | Cross-module, constructors |
| Go to Declaration | ✅ Complete | Imported symbols |
| Go to Type Definition | ✅ Complete | Constructor→type, values→type |
| Go to Implementation | ✅ Complete | Instances, methods |
| Find References | ✅ Complete | includeDeclaration option |
| Document Highlight | ✅ Complete | Same-document occurrences |

### Code Intelligence

| Feature | Status | Notes |
|---------|--------|-------|
| Completion | ✅ Complete | Resolve, cross-module, auto-import |
| Signature Help | ✅ Complete | Parameter tracking |
| Document Symbols | ✅ Complete | Hierarchical DocumentSymbol |
| Workspace Symbols | ✅ Complete | Cross-module search |

### Code Editing

| Feature | Status | Notes |
|---------|--------|-------|
| Code Actions | ✅ Complete | Hole fills, exhaustiveness, imports |
| Rename | ✅ Complete | WorkspaceEdit with changes |
| Prepare Rename | ✅ Complete | 3.18 {range, placeholder} format |
| Formatting | ✅ Complete | Full document |
| Range Formatting | ✅ Complete | Top-level regions |
| On-Type Formatting | ✅ Complete | Braces, semicolons |

### UI Features

| Feature | Status | Notes |
|---------|--------|-------|
| Folding Ranges | ✅ Complete | Token-derived spans |
| Selection Ranges | ✅ Complete | Nested parent chain |
| Inlay Hints | ✅ Complete | Type hints, resolve, config toggle |
| Code Lens | ✅ Complete | Per-declaration lenses |
| Document Links | ✅ Complete | URL extraction from comments |
| Document Colors | ✅ Complete | Hex/rgb parsing |
| Color Presentations | ✅ Complete | TextEdit presentations |
| Inline Values | ✅ Complete | Declared type display |
| Semantic Tokens | ✅ Complete | Full + delta, cached |
| Monikers | ✅ Complete | Export scheme |

### Hierarchy

| Feature | Status | Notes |
|---------|--------|-------|
| Call Hierarchy | ✅ Complete | Prepare, incoming, outgoing |
| Type Hierarchy | ✅ Complete | Prepare, supertypes, subtypes |
| Linked Editing Range | ✅ Complete | Returns ranges or null |

### Protocol Compliance

| Feature | Status | Notes |
|---------|--------|-------|
| JSON-RPC 2.0 Transport | ✅ Complete | Content-Length framing |
| Error Handling | ✅ Complete | -32002, -32600, -32601, -32603 |
| Malformed Messages | ✅ Complete | Survives, reports via window/showMessage |
| Unknown $/ Methods | ✅ Complete | MethodNotFound (-32601) |
| Cancellation | ✅ Complete | No-op (single-threaded) |
| Progress Reporting | ✅ Complete | WorkDoneProgress (fire-and-forget create) |
| UTF-16 Position Handling | ✅ Complete | Correct non-BMP character support |

### VS Code Extension Support

| Feature | Status | Notes |
|---------|--------|-------|
| Language Configuration | ✅ Complete | .mog extension, grammar |
| Semantic Token Scopes | ✅ Complete | Keyword, type, function, etc. |
| Task Provider | ✅ Complete | Build task |
| Test Explorer | ✅ Complete | Runtime test discovery |
| Configuration | ✅ Complete | serverPath, phpPath, libPaths, inlayHints, backend |
| Commands | ✅ Complete | Restart server, show output, run current file |

### Backend Support

| Backend | LSP Support | Notes |
|---------|-------------|-------|
| PHP | ✅ Complete | Primary backend, all features |
| JVM | ✅ Partial | Frontend features work, codegen differs |
| DotNet | ✅ Partial | Frontend features work, codegen differs |

**Note**: The LSP server always uses the PHP backend for analysis regardless of
the target backend for code generation. The `--lib` flags can specify additional
library paths for cross-backend development.

## Records

A field name in Moggi belongs to its record, not to the module (there are no
selector functions), so both record features start from the record's type and
look the label up inside it — which is what makes `person.name` and
`company.name` two different fields rather than one ambiguous name.

* **Completion inside `{ … }`** offers the record's field labels and leaves out
the ones already written. The record is the name in front of the brace: the
constructor in `Person { … }`, or the receiver in `p { … }` — for the latter its
type comes from the enclosing signature (`renamed p = p { … }` with
`renamed :: Person -> Person`), for a `where`-bound helper from the enclosing
function's. This runs while the buffer does **not** parse — that is the state a
record being written is in — so it reads the declaration from the source text
once the parse tree has nothing to offer.
* **Hover on a field label** shows `label :: Type`, the record that declares it
and where, for a projection (`alice.name`), a label the reader wrote in a
construction or update (`Person { name = … }`, `p { age = … }`), a record pattern
(`Person { name = n }`) and the label in the record declaration itself. Hovering
the dot answers with the projection's type; the label is one character in.
* **Completion after `.`** (`alice.`) lists the receiver's fields, falling back
to its nullary constructors, and keeps offering plain identifiers when the
receiver's type is unknown.

`src/lsp/textdocument/records.php` holds the shared record knowledge:
declaration lookup by type *or* constructor name, field lists, and the type head
of a record-valued expression.

## Latency model

`textDocument/didChange` only updates the VFS and schedules a versioned typecheck
(~200ms idle). Parse/lex diagnostics publish immediately via `quickDiagnostics`.
The stdio loop uses `stream_select` timeouts so due analyzes flush between
messages without threads. Stale versions are superseded; overlay writes skip when
the content fingerprint is unchanged. When the client advertises
`window.workDoneProgress`, due typechecks emit `$/progress` begin/end around the
flush (plus `moggi/status`).

## Architecture

```text
┌─────────────────────────────────────────────────────────┐
│  AnalysisService (single process, owns PHP compiler)    │
│  VirtualFS → ProjectGraph → ModuleIndex                 │
│                      ↓                                  │
│              OccurrenceIndex (binder:Mod:id + edges)    │
│                      ↓                                  │
│  features / actions / format / tokens / hierarchy       │
└─────────────────────────────────────────────────────────┘
        │                              │
   stdio LSP                    VS Code extension
                                   ├─ status + $/progress
                                   ├─ Run (PHP) launch
                                   └─ Test Explorer
```

`AnalysisService` owns `VirtualFS`, `ModuleIndex`, `OccurrenceIndex`, and an
optional `DocIndex`. Successful prepares **reindex every checked module**.
Navigation prefers `resolvedOrigin` / `backendResolved` / `externalFns`; locals
use `binder:{Module}:{id}`. Call edges are recorded at `Apply` / `Infix` sites.

Source layout under `src/lsp/`, where **namespace = directory**:
`protocol/` (framing, positions, messages), `textdocument/` (one file per feature
group: `hover`, `navigation`, `symbols`, `completion`, `signature`,
`semanticTokens`, `hints`, `color`, `formatting`, `rename`, `hierarchy`,
`codeAction`, `codeLens`, `moniker`, `diagnostics`), `workspace/` (file
operations, configuration, watched files), `index/` (module index, occurrences,
token map), `analysis/` (service + VFS, analyzer, diagnostics) and `formatter/`;
`server.php` is the stdio loop and dispatch only, `capabilities.php` is the one
place a capability is named, `lifecycle.php` and `sync.php` shape the lifecycle
and document-sync requests.

Capability ↔ dispatch **drift is checked, not hoped away**:
`capabilities.php`'s `capabilityDispatchMismatches()` reports a handler without a
capability and a capability without a handler, and the check runs in the test
suite (`tests/lsp/capability_drift_test.php`).

## Run vs debug

**Not implemented:** interactive Moggi debugging (step/break/locals) across backends.

What ships today:

- VS Code type `moggi` is labeled **Moggi: Run (PHP)**: `moggi compile --backend php`,
  then `php` the artifact. Breakpoints are **unverified** (they never halt).
- Source maps still emit for exception stack mapping and a future DAP, but they no longer take the
  same shape per backend. On PHP each module carries its own `const __MOGGI_MAP` (positional,
  pre-resolved rows + a declaration-site table); the runtime reads it by the namespace of the frame's
  function name, only when a frame from that module is reported, and nothing is decoded, registered or
  cached up front. On jvm/.NET the JSON `.moggi.map` is the packaging interchange: the packager parses
  it once and bakes `moggi/rt/Frames` / `Moggi.Frames`. An *external* consumer that wants a PHP
  module's map must read the `const` out of the module source; no such reader exists yet (the
  JSON-only one was deleted together with the sidecar era), which is the open half of D-10.

### Physical limits

- **Host debugger required** — maps alone cannot pause optimized code. PHP needs
  Xdebug; JVM needs JDWP; .NET needs netcoredbg. One DAP façade per backend;
  shared layer is Moggi sourcemaps only.
- **Xdebug in nix** — keep default `php`/`moggi`/`runtest` without Xdebug. A future
  `php-debug` wrapper would load the extension with `xdebug.mode=debug` only for
  DAP launch.
- **Optimized IR** — maps are built at backend emit from **post-opt** IR `SrcLoc`s.
  Inlining, dict specialization, and folds can erase sites. A debug build will need
  `-O0` / no-inline / retain dictionaries. Maps do not point at pre-opt IR.

### Future debugger work

Per the [Debug Adapter Protocol](https://microsoft.github.io/debug-adapter-protocol/), the editor speaks
to a **debug adapter** (`launch`/`attach`, `setBreakpoints`, `configurationDone`, `stackTrace`,
`scopes`, `variables`, `continue`/`next`/`stepIn` in; `initialized`, `stopped`, `output`,
`terminated` out); the adapter drives a real host debugger. That is a *sibling* of the LSP here, not a
part of it, and none of it is implemented yet.

| Item | Notes |
|------|-------|
| `php-debug` nix wrapper | Xdebug packaged; default `php` stays clean |
| PHP DAP + Xdebug | Real breakpoints/step/locals via the module map |
| Debug IR mode | `-O0` / no-inline so surface sites survive |
| JVM / .NET DAP | Separate adapters; reuse map reader |
| Map reader for tooling | Nothing outside the runtime and the packagers reads a map today. The adapter needs both the PHP module `const` and the jvm/.NET JSON, plus the `.mog` → generated direction the runtime lookups never needed (breakpoints); write it with the adapter, not before |

## Formatter limits

The formatter pretty-prints from the AST and reattaches nearby `--` line
comments. Mid-expression comments may move; unparsable buffers fall back to
whitespace normalization. Range formatting rewrites only intersecting top-level
items (not a silent whole-file format). Comment-perfect formatting would need a
comment-preserving CST.
