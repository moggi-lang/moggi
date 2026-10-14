# Legacy (non-flake) entry point for direnv.
#
# Evaluate with `nix print-dev-env --impure -f nix/shell.nix`. A `--file`
# expression is read in place, so — unlike the repo flake (`path:.` or
# `git+file://…`, which archive the whole working tree into the store on every
# evaluation) — entering the shell after editing compiler sources copies
# nothing and re-resolves nothing. nixpkgs stays exactly pinned: the tarball
# URL and hash come from `flake.lock`, the same lock `flake.nix` uses.
{ system ? builtins.currentSystem }:

let
  lock = builtins.fromJSON (builtins.readFile ../flake.lock);
  locked = lock.nodes.nixpkgs.locked;
  src = builtins.fetchTarball {
    url = "https://github.com/${locked.owner}/${locked.repo}/archive/${locked.rev}.tar.gz";
    sha256 = locked.narHash;
  };
  pkgs = import src { inherit system; };
in
pkgs.mkShell (import ./devshell.nix { inherit pkgs; nixpkgsRev = locked.rev; })
