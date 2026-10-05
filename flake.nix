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
        composer = "2.10.1";
        maven = "3.9.12";
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
            # .NET 8 SDK for the DotNet IL backend (run + Native AOT). It also
            # provides the NuGet CLI (`dotnet nuget`), so `nuget` is not a
            # separate package here: nixpkgs' `nuget` is the discontinued
            # mono-based tool, unrelated to the SDK's restore path.
            dotnet-sdk_8
            # Composer drives `[php] composer` dependencies. It is versioned with
            # the PHP it runs on, so it comes from php85's package set —
            # nixpkgs no longer has a top-level `composer`.
            php85.packages.composer
            # Maven resolves `[jvm] maven` coordinates onto this JDK.
            maven
            # GraalVM's `native-image` links a native JVM executable with the
            # host C compiler, but hands that compiler a *sanitised*
            # environment: `NIX_LDFLAGS` — the only channel a nixpkgs gcc
            # wrapper uses to add library search paths — never reaches it, so
            # the `-lz` native-image always requests cannot be resolved and
            # every native JVM build fails with "libz.a is missing". These
            # wrappers reinstate the one path the shell already provides, as a
            # command-line argument, which does survive `native-image`'s env
            # scrub. They shadow `gcc`/`cc` only inside this shell; on a
            # machine with the documented GraalVM prerequisite (zlib
            # development files) nothing here is needed at all.
            (writeShellScriptBin "gcc" ''
              exec ${pkgs.gcc}/bin/gcc -L${pkgs.zlib}/lib "$@"
            '')
            (writeShellScriptBin "cc" ''
              exec ${pkgs.gcc}/bin/gcc -L${pkgs.zlib}/lib "$@"
            '')
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
            echo "nuget $(dotnet nuget --version 2>&1 | tail -n1) (from dotnet-sdk_8)"
            echo "composer $(composer --version 2>&1 | head -n1 | cut -d' ' -f1-3) (pinned ${pinnedVersions.composer})"
            echo "maven $(mvn --version 2>&1 | head -n1 | cut -d' ' -f1-3) (pinned ${pinnedVersions.maven})"
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
