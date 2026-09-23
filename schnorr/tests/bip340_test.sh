#!/usr/bin/env bash
# BIP-340 compliance tests using the official test vectors from
# https://github.com/bitcoin/bips/blob/master/bip-0340/test-vectors.csv

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHNORR="${SCRIPT_DIR}/../schnorr"
BECH32_ENC="${SCRIPT_DIR}/bech32_enc"

# Build helper
gcc -std=c99 -O2 -I"${SCRIPT_DIR}/../libbech32-1.1p1" -o "$BECH32_ENC" "${SCRIPT_DIR}/bech32_enc.c" "${SCRIPT_DIR}/../libbech32-1.1p1/libbech32.c"

# Official test vectors, vendored from
# https://raw.githubusercontent.com/bitcoin/bips/master/bip-0340/test-vectors.csv
VECTORS_CSV="${SCRIPT_DIR}/test-vectors.csv"

# Trim surrounding whitespace/CR from a CSV field (keeps inner spaces, e.g. comments)
trim() {
    sed -e 's/^[[:space:]\r]*//' -e 's/[[:space:]\r]*$//'
}

PASS=0

# Flip the last hex nibble of a string so that tampering always changes it
flip_last() {
    local s="$1"
    local last="${s: -1}"
    if [ "$last" = "0" ]; then
        printf '%s' "${s%?}1"
    else
        printf '%s' "${s%?}0"
    fi
}

echo "=== BIP-340 test vector verification ==="
echo ""

# Process each vector. Process substitution (not a pipe) keeps this loop in the
# current shell so that 'set -e' and exit codes behave as expected.
while IFS=',' read -r index seckey pubkey aux_rand message signature expected comment; do
    index=$(echo "$index" | trim)
    seckey=$(echo "$seckey" | trim)
    pubkey=$(echo "$pubkey" | trim)
    aux_rand=$(echo "$aux_rand" | trim)
    message=$(echo "$message" | trim)
    signature=$(echo "$signature" | trim)
    expected=$(echo "$expected" | trim)
    comment=$(echo "$comment" | trim)

    [ -n "$index" ] || continue
    [ -n "$expected" ] || { echo "Vector $index: missing verification result in CSV"; exit 1; }

    echo -n "Vector $index (expected $expected): ${comment:-plain vector} ... "

    if [ -n "$pubkey" ]; then
        NPUB=$("$BECH32_ENC" npub "$pubkey")
    else
        NPUB=""
    fi

    # ---- verification against the raw message from the CSV ----
    if [ "$expected" = "TRUE" ]; then
        "$SCHNORR" verify "$NPUB" "$signature" "$message" >/dev/null 2>&1 \
            || { echo "FAIL (expected valid)"; exit 1; }
        echo -n "verify PASS; "
    else
        "$SCHNORR" verify "$NPUB" "$signature" "$message" >/dev/null 2>&1 \
            && { echo "FAIL (expected invalid)"; exit 1; }
        echo -n "verify PASS (correctly invalid); "
    fi

    # ---- deterministic signing (vectors with a secret key) ----
    if [ -n "$seckey" ]; then
        NSEC=$("$BECH32_ENC" nsec "$seckey")
        PRODUCED=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "$message" "$aux_rand")
        if [ "$(echo "$PRODUCED" | tr 'A-Z' 'a-z')" = "$(echo "$signature" | tr 'A-Z' 'a-z')" ]; then
            echo "sign matches expected"
        else
            echo "sign FAIL (expected ${signature}, got ${PRODUCED})"
            exit 1
        fi
    else
        echo "verify-only vector"
    fi

    PASS=$((PASS + 1))
done < <(tail -n +2 "$VECTORS_CSV")

echo ""
echo "All $PASS official test vectors passed (verification + deterministic signing)."

echo ""
echo "=== Fresh generate + sign + verify ==="
OUT=$("$SCHNORR" generate)
read -r NSEC NPUB <<< "$OUT"
DIGEST=$(printf 'deadbeef%.0s' $(seq 1 8))
SIG=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "$DIGEST")
[ ${#SIG} -eq 128 ] || { echo "FAIL: sig len"; exit 1; }
"$SCHNORR" verify "$NPUB" "$SIG" "$DIGEST" >/dev/null && echo "PASS" || { echo "FAIL"; exit 1; }

echo "=== Sign+verify a 100-byte message ==="
BIGMSG=$(printf 'ab%.0s' $(seq 1 100))
SIG_BIG=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "$BIGMSG")
"$SCHNORR" verify "$NPUB" "$SIG_BIG" "$BIGMSG" >/dev/null && echo "PASS" || { echo "FAIL"; exit 1; }

echo "=== Sign+verify an empty message ==="
SIG_EMPTY=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "")
"$SCHNORR" verify "$NPUB" "$SIG_EMPTY" "" >/dev/null && echo "PASS" || { echo "FAIL"; exit 1; }

echo "=== Sign deterministically with explicit aux_rand, then verify ==="
AUX=$(printf '00%.0s' $(seq 1 32))
SIG_DET=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "$DIGEST" "$AUX")
SIG_DET2=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "$DIGEST" "$AUX")
[ "$SIG_DET" = "$SIG_DET2" ] || { echo "FAIL: aux_rand did not make signing deterministic"; exit 1; }
"$SCHNORR" verify "$NPUB" "$SIG_DET" "$DIGEST" >/dev/null && echo "PASS" || { echo "FAIL"; exit 1; }

echo "=== Uppercase npub should verify ==="
"$SCHNORR" verify "$(echo "$NPUB" | tr 'a-z' 'A-Z')" "$SIG" "$DIGEST" >/dev/null && echo "PASS" || { echo "FAIL"; exit 1; }

echo "=== Uppercase nsec should sign ==="
SIG_UPPER=$(printf '%s\n' "$(echo "$NSEC" | tr 'a-z' 'A-Z')" | "$SCHNORR" sign "$DIGEST")
"$SCHNORR" verify "$NPUB" "$SIG_UPPER" "$DIGEST" >/dev/null && echo "PASS" || { echo "FAIL"; exit 1; }

echo "=== Tampered message should be INVALID ==="
TAMPERED=$(flip_last "$DIGEST")
[ "$TAMPERED" != "$DIGEST" ] || { echo "FAIL: tampering didn't change digest"; exit 1; }
"$SCHNORR" verify "$NPUB" "$SIG" "$TAMPERED" >/dev/null && { echo "FAIL: expected invalid"; exit 1; } || echo "PASS (correctly invalid)"

echo "=== Tampered signature should be INVALID ==="
SIG_TAMPERED=$(flip_last "$SIG")
[ "$SIG_TAMPERED" != "$SIG" ] || { echo "FAIL: tampering didn't change signature"; exit 1; }
"$SCHNORR" verify "$NPUB" "$SIG_TAMPERED" "$DIGEST" >/dev/null && { echo "FAIL: expected invalid"; exit 1; } || echo "PASS (correctly invalid)"

echo "=== Tampered pubkey should be INVALID ==="
NPUB_TAMPERED=$(flip_last "$NPUB")
[ "$NPUB_TAMPERED" != "$NPUB" ] || { echo "FAIL: tampering didn't change pubkey"; exit 1; }
"$SCHNORR" verify "$NPUB_TAMPERED" "$SIG" "$DIGEST" >/dev/null 2>&1 && { echo "FAIL: expected invalid"; exit 1; } || echo "PASS (correctly invalid)"

echo "=== Odd-length message hex rejected ==="
OUTPUT=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "48656c6c6" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid message hex" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Invalid message hex rejected ==="
OUTPUT=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid message hex" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Message too long rejected ==="
OUTPUT=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "$(printf 'ab%.0s' $(seq 1 129))" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid message hex" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Invalid aux_rand hex rejected ==="
OUTPUT=$(printf '%s\n' "$NSEC" | "$SCHNORR" sign "$DIGEST" "zz" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid aux_rand hex" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Invalid signature hex length rejected ==="
OUTPUT=$("$SCHNORR" verify "$NPUB" "deadbeef" "$DIGEST" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid signature hex" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Invalid signature hex rejected ==="
OUTPUT=$("$SCHNORR" verify "$NPUB" "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz" "$DIGEST" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid signature hex" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Invalid npub rejected ==="
OUTPUT=$("$SCHNORR" verify "npub1invalidpubkeyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy" "$SIG" "$DIGEST" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid npub" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Invalid nsec on stdin rejected ==="
OUTPUT=$(echo "nsec1invalid" | "$SCHNORR" sign "$DIGEST" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid nsec" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Empty stdin rejected ==="
OUTPUT=$(echo "" | "$SCHNORR" sign "$DIGEST" 2>&1 || true)
echo "$OUTPUT" | grep -q "Invalid nsec" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo "=== Usage help ==="
OUTPUT=$("$SCHNORR" 2>&1 || true)
echo "$OUTPUT" | grep -q "Usage" && echo "PASS" || { echo "FAIL: $OUTPUT"; exit 1; }

echo ""
echo "ALL TESTS PASSED"
