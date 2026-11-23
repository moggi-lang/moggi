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
  # The compiler shells out to PHP; the concurrency runtime needs a ZTS build
  # with ext-parallel, which is exactly what `parallel` requires.
  php = (pkgs.php85.override { ztsSupport = true; }).withExtensions (
    { enabled, all, ... }: enabled ++ [ all.parallel ]
  );
  # The name packaging/assemble.php uses for this host, so the shell can find
  # the runtime a previous packaging run left in .dist-cache/.
  target =
    if pkgs.stdenv.hostPlatform.isLinux then
      if pkgs.stdenv.hostPlatform.isx86_64 then "linux-x86_64" else "linux-aarch64"
    else if pkgs.stdenv.hostPlatform.isx86_64 then "macos-x86_64"
    else "macos-aarch64";
in
{
  packages = with pkgs; [
    git
    # --package rewrites ELF rpaths to $ORIGIN after a native build.
    patchelf
    php
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
      exec ${php}/bin/php "$ROOT/moggi.php" "$@"
    '')
    (writeShellScriptBin "runtest" ''
      ROOT="$(${moggiRootScript})"
      exec ${php}/bin/php "$ROOT/test.php" "$@"
    '')
    (writeShellScriptBin "runalltest" ''
      ROOT="$(${moggiRootScript})"
      exec ${php}/bin/php "$ROOT/test-all.php" "$@"
    '')
  ];

  shellHook = ''
    # A checkout bundles no runtime. Point --native at the one packaging built,
    # unless the caller already chose one.
    if [ -z "''${MOGGI_MICRO_SFX-}" ]; then
      cached="$(${moggiRootScript})/.dist-cache/runtimes/${target}/php-native/micro.sfx"
      if [ -f "$cached" ]; then
        export MOGGI_MICRO_SFX="$cached"
      fi
    fi

    echo "PHP $(php --version | head -n1) (ZTS: $(php -r 'echo PHP_ZTS ? "yes" : "no";') / parallel: $(php -r 'echo extension_loaded("parallel") ? "yes" : "no";'); pinned ${pinnedVersions.php})"
    if [ -n "''${MOGGI_MICRO_SFX-}" ]; then
      echo "micro PHP runtime ''${MOGGI_MICRO_SFX} (MOGGI_MICRO_SFX)"
    else
      echo "micro PHP runtime not built: --native on php needs one; run php packaging/assemble.php --target ${target} --variants moggi-minimal"
    fi
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
