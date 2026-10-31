{
  description = "Compiler dev environment";

  inputs = {
    # Pin nixpkgs to a specific commit so PHP versions stay fixed.
    # Current: PHP 8.5.12 — the minimum this compiler runs on.
    # To upgrade: nix flake update nixpkgs, then update this rev and the
    # `nixpkgsRev` passed below (it only feeds the shellHook banner).
    nixpkgs.url = "github:NixOS/nixpkgs/674a2028661e550a97400c1cc992b9cca9a668a1";
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
          nixpkgsRev = "674a2028661e550a97400c1cc992b9cca9a668a1";
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
