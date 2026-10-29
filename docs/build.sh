#!/usr/bin/env bash
# Build the documentation site with the Jekyll version and plugins GitHub
# Pages builds docs/ with.
#
# The Nix dev shell pins jekyll 3.10 (the version Pages runs), the
# jekyll-relative-links plugin _config.yml lists, and the GFM parser kramdown
# needs. Pass an output directory to build elsewhere.
set -euo pipefail

cd "$(dirname "$0")/.."
exec nix develop --command jekyll build --source docs --destination "${1:-docs/_site}"
