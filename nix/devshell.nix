# Shared dev-shell definition: the single source of truth for the tooling nix
# provides (PHP, GraalVM, .NET SDK, Composer, Maven, gcc) plus the
# `moggi`/`runtest`/`runalltest` wrappers.
#
# A pure function of `pkgs` (and the pinned nixpkgs rev, for the shellHook
# banner). Imported both by `flake.nix` (for `nix develop` and CI) and by
# `shell.nix` (for direnv, evaluated in place). Because direnv never evaluates
# the repo flake, editing compiler sources copies nothing into the store and
# never re-resolves this environment — only this directory and `flake.lock`
# affect it.
{ pkgs, nixpkgsRev }:

let
  pinnedVersions = {
    # 8.5.12 is the minimum the compiler runs on (see `MOGGI_MINIMUM_PHP`);
    php = "8.5.12";
    dotnet = "8";
    composer = "2.10.1";
    maven = "3.9.12";
  };
  moggiRootScript = ''
    git -C "''${PWD}" rev-parse --show-toplevel 2>/dev/null || pwd
  '';
in
{
  packages = with pkgs; [
    git
    php85
    # GraalVM CE provides the Java 21 toolchain plus native-image.
    graalvmPackages.graalvm-ce
    # .NET 8 SDK for the DotNet IL backend (run + Native AOT).
    dotnet-sdk_8
    # Composer drives `[php] composer` dependencies.
    php85.packages.composer
    # Maven resolves `[jvm] maven` coordinates onto this JDK.
    maven
    # The documentation site: jekyll 3.10 is the version GitHub Pages builds
    (ruby.withPackages (ps: [ ps.jekyll ps.jekyll-relative-links ps.kramdown-parser-gfm ]))
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
    (writeShellScriptBin "runalltest" ''
      ROOT="$(${moggiRootScript})"
      exec ${php85}/bin/php "$ROOT/test-all.php" "$@"
    '')
  ];

  shellHook = ''
    echo "PHP $(php --version | head -n1) (pinned ${pinnedVersions.php})"
    echo "nixpkgs ${nixpkgsRev}"
    echo "Java $(java -version 2>&1 | head -n1) (nixpkgs graalvmPackages.graalvm-ce; JVM backend target)"
    echo "native-image $(native-image --version 2>&1 | head -n1)"
    echo "dotnet $(dotnet --version 2>&1 | head -n1) (nixpkgs dotnet-sdk_8; DotNet IL backend target)"
    echo "nuget $(dotnet nuget --version 2>&1 | tail -n1) (from dotnet-sdk_8)"
    echo "composer $(composer --version 2>&1 | head -n1 | cut -d' ' -f1-3) (pinned ${pinnedVersions.composer})"
    echo "maven $(mvn --version 2>&1 | head -n1 | cut -d' ' -f1-3) (pinned ${pinnedVersions.maven})"
    echo "jekyll $(jekyll --version | cut -d' ' -f2) (docs build: docs/build.sh; the GitHub Pages version)"
  '';
}
