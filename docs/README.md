---
title: "Documentation"
---
# Documentation

The manual's index is [index.md](index.md): one reading path from installation
to tooling, then the reference pages and the compiler-development pages.

If you are new to Moggi, start with [quickstart.md](quickstart.md).

The sources of the compiler are laid out in
[development/index.md](development/index.md#layout); if you intend to change
Moggi itself, start there.

## Building the site

```bash
docs/build.sh            # writes docs/_site/
```

The script runs `jekyll build` inside the repository's Nix dev shell, which
pins jekyll 3.10 — the version GitHub Pages builds `docs/` with — plus the
`jekyll-relative-links` plugin and the GFM parser `_config.yml` selects, so a
local build matches the published one. Open `docs/_site/index.html` to check a
change, or pass a directory to build somewhere else.
