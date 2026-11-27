{
  description = "Compiler dev environment";

  inputs = {
    # Pin nixpkgs to a specific commit so PHP versions stay fixed.
    # Current: PHP 8.6.0 — the minimum this compiler runs on.
    # To upgrade: nix flake update nixpkgs, then update this rev and the
    # `nixpkgsRev` passed below (it only feeds the shellHook banner).
    nixpkgs.url = "github:NixOS/nixpkgs/2786a2a72088ecf32aea5b3ec52099e6a7cff78f";
  };

  outputs = { self, nixpkgs, ... }:
    let
      systems = [
        "x86_64-linux"
        "aarch64-linux"
        "x86_64-darwin"
        "aarch64-darwin"
      ];
      forAllSystems = nixpkgs.lib.genAttrs systems;
      mkDevShell = system:
        let
          pkgs = nixpkgs.legacyPackages.${system};
        in
        pkgs.mkShell (import ./nix/devshell.nix {
          inherit pkgs;
          nixpkgsRev = "2786a2a72088ecf32aea5b3ec52099e6a7cff78f";
        });
    in
    {
      devShells = forAllSystems (system: {
        default = mkDevShell system;
      });

      # Legacy flake output for older tooling that requests devShell.<system>
      devShell = forAllSystems (system: mkDevShell system);
    };
}
