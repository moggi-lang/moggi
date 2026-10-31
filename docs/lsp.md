---
title: "Language server"
description: Editor setup, what the server does, and what it does not do.
---
# Language server

`moggi lsp` is a language server over stdio. It is the same compiler: the server
checks your project in the background, so diagnostics, types and navigation
agree with what a build would say.

```bash
moggi lsp                 # stdio language server
moggi lsp --lib lib       # extra module roots, repeatable
```

## VS Code

Install the extension from
[moggi-lang/moggi-vscode-plugin](https://github.com/moggi-lang/moggi-vscode-plugin)
(the packaged `.vsix` is attached to each release). It starts a compiler you
already have:

| Setting | Value |
|---|---|
| `moggi.serverPath` | `<installation>/bin/moggi.phar` in a PHP-bundling distribution, `<installation>/bin/moggi` where PHP is not bundled, or `<checkout>/moggi.php` |
| `moggi.phpPath` | the PHP 8.5.12 binary, when `serverPath` is a `.phar` or `.php` |
| `moggi.libPaths` | extra `--lib` roots |
| `moggi.inlayHints` | show or hide type inlays |
| `moggi.testRoot` | fixture directory for Test Explorer (default `tests/backend/runtime`) |

Commands: **Moggi: Restart Language Server**, **Show Output**, **Moggi: Run
(PHP)**. The status bar shows `Moggi` / `Moggi: checking…` while a check runs.

Any other editor can start `moggi lsp` directly; the server speaks LSP 3.18.

## What it does

| Area | Supported |
|---|---|
| Diagnostics | parse errors immediately, type errors after a short idle delay; stable codes (`parse`, `unify`, `non-exhaustive`, `hole`, …) and related information where a location helps |
| Typed holes | `_` reports the expected type; a code action fills a stub; hover shows it |
| Hover | the type, its constraints, where the name is declared, and its doc comment |
| Completion | locals, imports with auto-import, record fields inside `{ … }`, and library search results |
| Navigation | definition, declaration, type definition, implementation, references, document highlight, workspace symbols |
| Editing | rename and prepare-rename (module-scoped binders, cross-file exports), formatting and range formatting, on-type formatting, linked editing |
| Structure | document symbols, folding ranges, selection ranges, call hierarchy, type hierarchy |
| Display | semantic tokens, inlay hints, code lens (reference counts), document links, inline values |
| Code actions | add an import, fill a hole, add missing `case` arms, add a signature |

Record support is worth knowing because a field is not a function: completion
inside `Person { … }` or `p { … }` offers that record's fields and omits the ones
already written, and hover on a field label in a projection, construction,
update or pattern shows `label :: Type` and the record that declares it.

The server checks with the PHP front end regardless of the backend you build
for, so diagnostics describe the language, not a target.

## What it does not do

* **No debugger.** **Moggi: Run (PHP)** compiles and runs the current file;
  breakpoints do not halt. Source maps are emitted for exception reports
  ([diagnostics.md](diagnostics.md)), not for stepping.
* **No project-wide pull diagnostics.** `workspace/diagnostic` answers empty;
  each open file is diagnosed on its own.
* **No cross-backend code generation.** The server type-checks; building for
  `jvm` or `dotnet` is still `moggi compile --backend …`.
* **Formatting is AST-based**, so a comment in the middle of an expression can
  move; a buffer that does not parse is whitespace-normalized instead of
  reformatted. Range formatting rewrites only the top-level items it intersects.
* **A server started from a checkout keeps its compiler.** Editing the compiler's
  own sources needs **Restart Language Server**; the server warns when its
  revision and the one on disk differ.

## Checking without an editor

`moggi check`, `moggi build` and `moggi run` use the same analysis
([quickstart.md](quickstart.md)), and `moggi repl` exposes the stages
interactively ([repl.md](repl.md)).
