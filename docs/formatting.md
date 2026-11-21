---
title: Formatting
description: The canonical layout, `moggi format`, the 100-column target, and what the formatter refuses.
---
# Formatting

Moggi has one canonical source layout. The formatter is part of the compiler, so
the same implementation serves the command line and the language server.

## Command line

```bash
moggi format Main.mog          # rewrite one file
moggi format src               # rewrite every .mog file under src
moggi format src --dry-run     # report what would change, write nothing
moggi format src --check       # report every file that is not canonical
```

A directory formats every `.mog` file below it. `--dry-run` and `--check` only
report; `--check` is the form for CI, because it exits nonzero when a file is not
canonical. A refused file also makes the command exit nonzero.

A directory formats in parallel by default. `--jobs N` chooses the number of
worker threads; `--jobs 1` keeps the whole run in one process. Without the
`parallel` PHP extension the files are formatted one at a time.

Files are written by replacing them atomically: a temporary file in the same
directory is renamed into place, so an editor or watchdog never sees a
half-written source.

## What the canonical layout is

The formatter preserves the program, not its whitespace. It changes:

* indentation to two columns per level, with a fixed place to break for each
  construct (applications at their arguments, operator chains at their
  operators, `let` at its `in`, brackets at their commas);
* spacing around punctuation, operators and declarations;
* blank lines between declarations;
* the layout of signatures, `case`, `do`, guards, `where`, `let`, records,
  lists, tuples and comprehensions.

It keeps module headers, exports, imports, pragmas, fixity declarations, doc
comments and ordinary comments. A comment is placed with the declaration or
expression it belongs to. A comment with no safe place in the output refuses the
file rather than being dropped.

Formatting does not typecheck, rename or refactor anything. A module may refer to
names that do not exist; that is the checker's job, and the formatter still lays
the source out. Only the lexer and parser have to accept it.

The formatter reads standard operator fixities, the `infix`, `infixl` and
`infixr` declarations written in the file, and the fixities its imports bring
into scope. It resolves the module graph from module headers — the file's module
root, configured library roots and the standard library — and honours named,
hiding and qualified imports exactly as the compiler does. Formatting a single
file still looks at its neighbours, and the language server uses the fixity the
analysis already resolved for the open buffer.

If an imported module cannot be found, the formatter uses the standard fixity, or
the parser's default for an operator it has never seen. That is the same source
the compiler would reject as a missing import; formatting does not invent a
fixity or refuse the file.

## The 100-column target

The canonical width is 100 columns, measured by display width rather than bytes.
It is a target, not a guarantee. When a construct has a valid break point the
formatter uses it. When it does not — a single long identifier, a long string or
character literal, or an expression with no place that may be split without
changing its tokens — the line is left long.

Over-width output is never a refusal by itself. A user who wants a narrower
layout can add parentheses, bind a subexpression to a name, or otherwise give
the formatter a break point; the formatter will not split a token for them.

## Editor

The language server formats the whole document and, where an editor asks for it,
the top-level items intersecting a range. Format-on-save uses the same canonical
layout as `moggi format`. A buffer that is mid-edit, unparseable or refused by the
formatter produces no edit, so the text is never replaced with a partial result.
[lsp.md](lsp.md) covers the editor side.
