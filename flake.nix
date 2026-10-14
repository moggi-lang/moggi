{
  description = "Compiler dev environment";

  inputs = {
    # Pin nixpkgs to a specific commit so PHP versions stay fixed.
    # Current: PHP 8.5.7
    # To upgrade: nix flake update nixpkgs, then update this rev and the
    # `nixpkgsRev` passed below (it only feeds the shellHook banner).
    nixpkgs.url = "github:NixOS/nixpkgs/9ae611a455b90cf061d8f332b977e387bda8e1ca";
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
          nixpkgsRev = "9ae611a455b90cf061d8f332b977e387bda8e1ca";
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
