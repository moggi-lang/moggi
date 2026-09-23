{
  description = "Compiler dev environment";

  inputs = {
    # Pin nixpkgs to a specific commit so PHP/nginx versions stay fixed.
    # Current: PHP 8.5.7, nginx 1.31.1
    # To upgrade: nix flake update nixpkgs, then update this rev and the comment.
    nixpkgs.url = "github:NixOS/nixpkgs/9ae611a455b90cf061d8f332b977e387bda8e1ca";
  };

  outputs = { self, nixpkgs, ... }:
    let
      pinnedVersions = {
        php = "8.5.7";
        nginx = "1.31.1";
        dotnet = "8";
        nixpkgsRev = "9ae611a455b90cf061d8f332b977e387bda8e1ca";
      };
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
          moggiRootScript = ''
            if [ -n "''${MOGGI_ROOT:-}" ]; then
              echo "''${MOGGI_ROOT}"
            else
              git -C "''${PWD}" rev-parse --show-toplevel 2>/dev/null || pwd
            fi
          '';
        in
        pkgs.mkShell {
          packages = with pkgs; [
            git
            php85
            nginxMainline
            # GraalVM CE provides the Java 21 toolchain plus native-image.
            graalvmPackages.graalvm-ce
            # .NET 8 SDK for the DotNet IL backend (run + Native AOT).
            dotnet-sdk_8
            (writeShellScriptBin "moggi" ''
              ROOT="$(${moggiRootScript})"
              exec ${php85}/bin/php "$ROOT/moggi.php" "$@"
            '')
            (writeShellScriptBin "runtest" ''
              ROOT="$(${moggiRootScript})"
              exec ${php85}/bin/php "$ROOT/test.php" "$@"
            '')
          ];

          shellHook = ''
            MOGGI_ROOT="$(git -C "$PWD" rev-parse --show-toplevel 2>/dev/null || echo "$PWD")"
            export MOGGI_ROOT

            echo "PHP $(php --version | head -n1) (pinned ${pinnedVersions.php})"
            echo "nginx $(nginx -v 2>&1 | cut -d/ -f2) (pinned ${pinnedVersions.nginx})"
            echo "nixpkgs ${pinnedVersions.nixpkgsRev}"
            echo "Java $(java -version 2>&1 | head -n1) (nixpkgs graalvmPackages.graalvm-ce; JVM backend target)"
            echo "native-image $(native-image --version 2>&1 | head -n1)"
            echo "dotnet $(dotnet --version 2>&1 | head -n1) (nixpkgs dotnet-sdk_8; DotNet IL backend target)"
          '';
        };
    in
    {
      devShells = forAllSystems (system: {
        default = mkDevShell system;
      });

      # Legacy flake output for older tooling that requests devShell.<system>
      devShell = forAllSystems (system: mkDevShell system);
    };
}
