# Development

Working on the compiler itself. To *use* Moggi, start at
[quickstart.md](../quickstart.md): a release distribution already carries every
runtime it needs, and none of what follows is needed for that.

Nix is the development environment, on **Linux and macOS**. It pins every
Toolchain the compiler and the test suite use: **PHP 8.5.7**, **nginx 1.31.1**,
**.NET SDK 8**, GraalVM CE (Java 21+). On Windows, or without Nix, `php moggi.php`
and `php test.php` run the same two entry points with whatever is installed.

## Setup

Install Nix (multi-user / daemon mode):

```bash
curl -L https://nixos.org/nix/install | sh -s -- --daemon
```

After installation, log out and back in (or run `newgrp nix-users`) so your user can access the Nix daemon.

## Development shell

From the project root:

```bash
nix develop
```

This drops you into a shell with PHP, Java/GraalVM, and the .NET SDK on your `PATH`. Commands: `moggi`, `runtest`.

### Optional: direnv

If you use [direnv](https://direnv.net/), the dev shell loads automatically when you `cd` into the project:

```bash
direnv allow
```

## Building

Compile a single file:

```bash
moggi compile examples/twice                 # single deployable artifact (Main.phar)
moggi compile examples/twice -o app.phar     # artifact at an explicit path
moggi compile examples/twice -o out --unpacked # unpacked/development build (directory tree)
```

Compile a directory tree (preserves layout under the output dir):

```bash
moggi compile src -o out
moggi compile lib -o out/lib
```

Inspect the pipeline:

```bash
moggi compile examples/twice/Main.mog --ast
moggi compile examples/twice/Main.mog --ir
moggi compile examples/twice/Main.mog --opt-ir
moggi compile examples/twice/Main.mog --no-opt
```

Run `moggi --help` for the full option list.

## Versions

Two plain-text files hold the version numbers, and `moggi version` reports them
alongside the compiler fingerprint and the host toolchains:

```bash
cat VERSION        # compiler version
cat lib/VERSION    # standard-library version
moggi version      # versions + fingerprint + php/java/dotnet
moggi version --json
```

They are separate files on purpose: the compiler and the stdlib can be released
independently. Cutting a release is a one-line edit to the relevant file — the
number is read at run time, never compiled in.

The fingerprint is a hash of the compiler's own sources, the same value the
compile cache is keyed by. It identifies the exact build behind an artifact even
when the version number has not moved.

## Tests

```bash
runtest
```

Or `php test.php` from the project root. See [testing.md](testing.md).

## Debugging a hang

When a compile or a test run spins without making progress, sample the running
process instead of bisecting commits. [phpspy](https://github.com/adsr/phpspy)
dumps the PHP stacks of a live process:

```bash
git clone https://github.com/adsr/phpspy.git tools/phpspy   # add tools/ to .git/info/exclude
cd tools/phpspy && make
./phpspy --limit=1000 --pid=$(pgrep -n php) > /tmp/php.traces
```

Keep the clone local — `.git/info/exclude` is untracked, so the tool stays out of
`git status` and never becomes part of the repository. It needs ptrace permission on the target
(`sudo` or a permissive `kernel.yama.ptrace_scope`); without it the trace comes
back empty, which is not evidence of a bug. Reproduce the hang as a direct
`moggi.php compile` invocation first, then sample that pid.
