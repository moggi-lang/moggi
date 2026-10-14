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
    php = "8.5.7";
    dotnet = "8";
    composer = "2.10.1";
    maven = "3.9.12";
  };
  moggiRootScript = ''
    if [ -n "''${MOGGI_ROOT:-}" ]; then
      echo "''${MOGGI_ROOT}"
    else
      git -C "''${PWD}" rev-parse --show-toplevel 2>/dev/null || pwd
    fi
  '';
in
{
  packages = with pkgs; [
    git
    php85
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
    (writeShellScriptBin "runalltest" ''
      ROOT="$(${moggiRootScript})"
      exec ${php85}/bin/php "$ROOT/test-all.php" "$@"
    '')
  ];

  shellHook = ''
    MOGGI_ROOT="$(git -C "$PWD" rev-parse --show-toplevel 2>/dev/null || echo "$PWD")"
    export MOGGI_ROOT

    echo "PHP $(php --version | head -n1) (pinned ${pinnedVersions.php})"
    echo "nixpkgs ${nixpkgsRev}"
    echo "Java $(java -version 2>&1 | head -n1) (nixpkgs graalvmPackages.graalvm-ce; JVM backend target)"
    echo "native-image $(native-image --version 2>&1 | head -n1)"
    echo "dotnet $(dotnet --version 2>&1 | head -n1) (nixpkgs dotnet-sdk_8; DotNet IL backend target)"
    echo "nuget $(dotnet nuget --version 2>&1 | tail -n1) (from dotnet-sdk_8)"
    echo "composer $(composer --version 2>&1 | head -n1 | cut -d' ' -f1-3) (pinned ${pinnedVersions.composer})"
    echo "maven $(mvn --version 2>&1 | head -n1 | cut -d' ' -f1-3) (pinned ${pinnedVersions.maven})"
  '';
}
