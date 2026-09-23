# schnorr

BIP-340 Schnorr CLI for secp256k1. It builds against two C libraries that are
fetched rather than committed (see [Dependencies](#dependencies)); nothing else
is needed, and no system library is linked.

## Usage

```sh
# Generate a keypair
./schnorr generate                      # prints: nsec1... npub1...

# Sign a message directly (BIP-340): any message from 0 to 128 bytes
# (0..256 hex chars); nsec read from stdin, never from argv.
# The signature is 128 hex chars.
SIG=$(echo "$NSEC" | ./schnorr sign "$MSG_HEX")

# Optional: pass explicit auxiliary randomness (32 bytes, 64 hex chars)
# for deterministic signatures, as used by the BIP-340 test vectors.
SIG_DET=$(echo "$NSEC" | ./schnorr sign "$MSG_HEX" "$AUX_RAND_HEX")

# Verify against the same raw message
./schnorr verify "$NPUB" "$SIG" "$MSG_HEX"      # prints: valid
```

Signatures are raw BIP-340: the message is fed directly into the algorithm's
internal tagged hashing, exactly as in the BIP-340 reference. An empty message
is valid (`./schnorr sign ""`). For messages longer than 128 bytes, hash the
data first (e.g. `sha256sum`) and sign the 32-byte digest; the CLI accepts that
digest as a 64-hex-char message.

## Test

```sh
./tests/bip340_test.sh
```

Runs every vector from the official BIP-340 test vectors
(`bip-0340/test-vectors.csv`): verification of all 19 vectors against the raw
message and, for the vectors with a secret key, deterministic signing that must
reproduce the expected signature byte-for-byte. The vectors are vendored at
`tests/test-vectors.csv`.

## Dependencies

Two upstream libraries are compiled into the binary. They are **not** vendored:
clone them into this directory under the exact names the Makefile expects. Both
are git-ignored, so a checkout never carries them.

| Library | Upstream | Tag | Directory |
|---|---|---|---|
| libsecp256k1 | <https://github.com/bitcoin-core/secp256k1> | `v0.8.0` | `libsecp256k1-0.8.0/` |
| libbech32 | <https://github.com/whitslack/libbech32> | `v1.1p1` | `libbech32-1.1p1/` |

`deps.json` pins both tags *and* their commits, and the fetch/build is scripted:

```sh
php scripts/dist/schnorr.php    # clone what is missing, verify the commit, build
```

or by hand:

```sh
cd schnorr
git clone --depth 1 --branch v0.8.0 https://github.com/bitcoin-core/secp256k1.git libsecp256k1-0.8.0
git clone --depth 1 --branch v1.1p1 https://github.com/whitslack/libbech32.git libbech32-1.1p1
```

A shallow clone is enough. Both tags carry the generated sources the build uses
(`src/precomputed_ecmult*.c` in libsecp256k1, `libbech32.c` in libbech32), so
neither library's own build system is run — the Makefile compiles the few
sources it needs directly.

## Build

```sh
# Linux / macOS / BSD
make

# Windows (MinGW-w64; MSVC is not supported by libbech32)
make CC=x86_64-w64-mingw32-gcc
```

The Makefile adds `-lbcrypt` automatically when the compiler is a MinGW toolchain.
On Windows MinGW names the result `schnorr.exe`.

## License

- `schnorr.c`: original work
- libsecp256k1: MIT — `libsecp256k1-0.8.0/COPYING`
- libbech32: WTFPL v2 — `libbech32-1.1p1/LICENSE`

Both are fetched at build time and keep their own licence file inside the cloned
directory. Because the released binary statically links them, every distribution
repeats both licence texts in its `THIRD-PARTY-NOTICES.md`.
