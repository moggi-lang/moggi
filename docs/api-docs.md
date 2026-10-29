---
title: "API documentation and search"
description: Write doc comments, search an API by name or type with moogle, and render it as HTML with mogdoc.
---
# API documentation and search

`moogle` and `mogdoc` read the same index, built from the `-- |` doc comments in
a tree of Moggi sources. `moogle` searches it from the command line; `mogdoc`
renders it as a static site with the same search built in. Both default to the
standard library, and both take `--root` and `--lib`.

## Doc comments

A `-- |` comment directly above a declaration documents it; one directly above
`module` documents the module. The comment has to start at or left of the
declaration's own column. A declaration without a doc comment still appears in
the index: the tree is parsed and type-checked, so every signature is known
whether it is documented or not.

```moggi
-- |
-- /Shapes/ for the drawing program.
--
-- Use 'area' with a "Shapes#Shape" value:
--
-- - circles: @Circle r@
-- - rectangles: __w__ by __h__
--
-- Squaring: x^2^.
module Shapes (Shape(..), area) where

-- | A closed shape.
data Shape = Circle Double | Rect Double Double
  deriving (Show, Eq)

-- | The area of a 'Shape', e.g. @area (Circle 1.0)@.
area :: Shape -> Double
area s = case s of
  Circle r -> 3.0 * r * r
  Rect w h -> w * h
```

The markup is a small subset, not Markdown:

| Written | Rendered |
|---|---|
| `@Double@` or `{@code Double}` | inline code |
| `'area'` | a code-styled link to the declaration when one resolves, plain code otherwise |
| `"Shapes"` or `"Shapes#Shape"` | a link to a module, or to one declaration in it |
| `/text/` | emphasis |
| `__text__` | strong |
| `^text^` | superscript |
| a line starting with `- ` | a bullet item |
| a blank line | a new paragraph |

There is no markup for headings, tables, fenced code or external URLs.

## Search with `moogle`

```bash
moggi moogle fromMaybe                # by name
moggi moogle -- "Maybe a -> a"        # by type; `--` keeps the leading `-` from being a flag
moggi moogle "fromMaybe :: a -> Maybe a -> a"   # name and type together
moggi moogle "Maybe is:type"          # kinds: is:module, is:class, is:type, is:value
moggi moogle module:Data.Maybe        # everything in one module
moggi moogle --json "a -> a"          # one JSON array instead of lines
```

Each line is one declaration, `Module.name — name :: type`:

```text
Data.Maybe.fromMaybe — fromMaybe :: a -> Maybe a -> a
```

A type query is ranked rather than exact: an argument order that differs still
scores, so `Maybe a -> a` lists `fromJust` first and `fromMaybe` below it.
Because the index holds signatures as text, curried functions are written with
arrows, and a constraint appears as written in the source (`Eq a => …`).

With no query on the line, the query is read from standard input, so it can be
piped. `--root DIR` indexes a tree of your own instead of the library, and
`--lib DIR` adds another root; repeat `--lib` for several. The index is cached
per tree and survives between runs: `--rebuild` recreates it, `--no-cache` does
that for one run without writing, which is what you want while editing docs.

## Render with `mogdoc`

```bash
moggi mogdoc app/src -o out/doc              # every module under app/src
moggi mogdoc --root app/src --lib libraries/containers/src -o out/doc
moggi mogdoc serve --root app/src --port 8080  # render once, then serve it live
```

`mogdoc` writes one page per module plus an index, and copies of the sources
under `src/` with line anchors, so every declaration links to the line it came
from:

```text
out/doc/
  index.html              every module, with the search box
  Data-Maybe.html            one module: its types, classes, values and instances
  src/Data-Maybe.mog.html    the source, with #L anchors
  search-index.json       the rows the search reads
  moogle.js               the in-browser search
```

`-o` is the output tree and is required when generating. `serve` renders into
the docs cache by default (or into `-o` when you name one), re-renders when the
sources change, and serves on `--port` (8080 by default). It is a development
server: deploy the static tree `mogdoc -o` writes, not the server.

Both commands follow imports to type-check a module, but only modules under the
roots you name are documented. A facade module with a `{-# BACKEND … #-}`
pragma links to the implementation modules it names, and the index carries a
search box, so a generated site needs no network access.
