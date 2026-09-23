#if defined(__linux__) || defined(__FreeBSD__) || defined(__NetBSD__)
#  if defined(__linux__)
#    define _GNU_SOURCE
#  elif defined(__NetBSD__)
#    define _NETBSD_SOURCE
#  else
#    define _DEFAULT_SOURCE
#    define _POSIX_C_SOURCE 200809L
#  endif
#elif defined(__OpenBSD__)
#  define _OPENBSD_SOURCE
#elif defined(__APPLE__)
#  define _DARWIN_C_SOURCE
#endif

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>

#if defined(__linux__) || defined(__FreeBSD__)
#include <unistd.h>
#include <sys/random.h>
#endif
#if defined(__APPLE__) || defined(__OpenBSD__) || defined(__NetBSD__)
#include <unistd.h>
#endif
#if defined(__APPLE__)
#include <sys/random.h>
#endif
#if defined(_WIN32)
#  ifndef WIN32_LEAN_AND_MEAN
#    define WIN32_LEAN_AND_MEAN
#  endif
#  include <windows.h>
#  include <bcrypt.h>
#endif

#include <secp256k1.h>
#include <secp256k1_extrakeys.h>
#include <secp256k1_schnorrsig.h>
#include "bech32.h"

#define MAX_ATTEMPTS 10
#define KEY_LEN 32
#define SIGNATURE_LEN 64
#define MAX_MSG_LEN 128

static int fill_random(unsigned char* data, size_t size) {
#if defined(__linux__) || defined(__FreeBSD__)
    ssize_t res = getrandom(data, size, 0);
    if (res < 0 || (size_t)res != size) {
        return 0;
    }
    return 1;
#elif defined(__APPLE__) || defined(__OpenBSD__) || defined(__NetBSD__)
    int res = getentropy(data, size);
    if (res == 0) {
        return 1;
    }
    return 0;
#elif defined(_WIN32)
    return BCryptGenRandom(NULL, data, (ULONG)size, BCRYPT_USE_SYSTEM_PREFERRED_RNG) == 0 ? 1 : 0;
#else
    return 0;
#endif
}

static int bech32_encode_bytes(char *output, size_t output_size, const char *hrp, const unsigned char *data, size_t data_len) {
    struct bech32_encoder_state state;
    size_t nbits_in = data_len * 8;
    size_t n_hrp = strlen(hrp);
    size_t required_size = bech32_encoded_size(n_hrp, nbits_in, 0);
    if (required_size == SIZE_MAX || required_size + 1 > output_size) {
        return 0;
    }
    if (bech32_encode_begin(&state, output, output_size, hrp, n_hrp) != 0) return 0;
    if (bech32_encode_data(&state, data, nbits_in) != 0) return 0;
    if (bech32_encode_finish(&state, 1) != 0) return 0;
    output[required_size] = '\0';
    return 1;
}

static int bech32_decode_bytes(unsigned char *data, size_t data_len, const char *hrp, const char *input) {
    struct bech32_decoder_state state;
    size_t n_hrp = strlen(hrp);
    size_t n_in = strlen(input);
    ssize_t hrp_len = bech32_decode_begin(&state, input, n_in);
    if (hrp_len < 0 || (size_t)hrp_len != n_hrp) return 0;
    for (size_t i = 0; i < n_hrp; i++) {
        if ((input[i] | 0x20) != (hrp[i] | 0x20)) return 0;
    }
    unsigned char tmp[33];
    if (bech32_decode_data(&state, tmp, data_len * 8) != 0) return 0;
    if (bech32_decode_finish(&state, 1) < 0) return 0;
    memcpy(data, tmp, data_len);
    return 1;
}

static void print_hex(const unsigned char* data, size_t size) {
    size_t i;
    for (i = 0; i < size; i++) {
        printf("%02x", data[i]);
    }
}

static int hex_value(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

static int hex_to_bytes(const char* hex, unsigned char* out, size_t out_len) {
    size_t hex_len = strlen(hex);
    if (hex_len != out_len * 2) {
        return 0;
    }
    for (size_t i = 0; i < out_len; i++) {
        int hi = hex_value(hex[i * 2]);
        int lo = hex_value(hex[i * 2 + 1]);
        if (hi < 0 || lo < 0) {
            return 0;
        }
        out[i] = (unsigned char)((hi << 4) | lo);
    }
    return 1;
}

static int parse_message_hex(const char* hex, unsigned char* out, size_t max_len, size_t *out_len) {
    size_t hex_len = strlen(hex);
    if (hex_len % 2 != 0 || hex_len > max_len * 2) {
        return 0;
    }
    *out_len = hex_len / 2;
    if (*out_len == 0) {
        return 1;
    }
    return hex_to_bytes(hex, out, *out_len);
}

static int cmd_generate(secp256k1_context* ctx) {
    unsigned char seckey[KEY_LEN];
    unsigned char serialized_pubkey[KEY_LEN];
    secp256k1_keypair keypair;
    int attempt;

    for (attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
        if (!fill_random(seckey, sizeof(seckey))) {
            fprintf(stderr, "Failed to generate randomness\n");
            return 1;
        }
        if (secp256k1_keypair_create(ctx, &keypair, seckey)) {
            break;
        }
    }
    if (attempt >= MAX_ATTEMPTS) {
        fprintf(stderr, "Failed to derive a valid keypair\n");
        return 1;
    }

    secp256k1_xonly_pubkey pubkey;
    if (!secp256k1_keypair_xonly_pub(ctx, &pubkey, NULL, &keypair)) {
        fprintf(stderr, "Failed to derive public key\n");
        memset(seckey, 0, sizeof(seckey));
        memset(&keypair, 0, sizeof(keypair));
        return 1;
    }
    if (!secp256k1_xonly_pubkey_serialize(ctx, serialized_pubkey, &pubkey)) {
        fprintf(stderr, "Failed to serialize public key\n");
        memset(seckey, 0, sizeof(seckey));
        memset(&keypair, 0, sizeof(keypair));
        return 1;
    }

    char nsec[BECH32_MAX_SIZE + 1];
    char npub[BECH32_MAX_SIZE + 1];
    if (!bech32_encode_bytes(nsec, sizeof(nsec), "nsec", seckey, KEY_LEN) ||
        !bech32_encode_bytes(npub, sizeof(npub), "npub", serialized_pubkey, KEY_LEN)) {
        fprintf(stderr, "Failed to encode keys\n");
        memset(seckey, 0, sizeof(seckey));
        memset(&keypair, 0, sizeof(keypair));
        return 1;
    }

    printf("%s %s\n", nsec, npub);

    memset(seckey, 0, sizeof(seckey));
    memset(&keypair, 0, sizeof(keypair));
    return 0;
}

static int read_key_from_stdin(unsigned char *seckey, size_t seckey_len) {
    char line[128];
    int ret = 0;
    if (fgets(line, sizeof(line), stdin)) {
        size_t len = strlen(line);
        while (len > 0 && (line[len - 1] == '\n' || line[len - 1] == '\r')) {
            line[--len] = '\0';
        }
        ret = bech32_decode_bytes(seckey, seckey_len, "nsec", line);
    }
    memset(line, 0, sizeof(line));
    return ret;
}

static int cmd_sign(secp256k1_context* ctx, const char* msg_hex, const char* aux_rand_hex) {
    unsigned char seckey[KEY_LEN];
    unsigned char msg[MAX_MSG_LEN];
    size_t msg_len;
    unsigned char signature[SIGNATURE_LEN];
    unsigned char auxiliary_rand[KEY_LEN];
    secp256k1_keypair keypair;
    int have_aux;
    int attempt;
    int produced;

    if (!read_key_from_stdin(seckey, sizeof(seckey))) {
        fprintf(stderr, "Invalid nsec on stdin\n");
        return 1;
    }

    if (!parse_message_hex(msg_hex, msg, MAX_MSG_LEN, &msg_len)) {
        fprintf(stderr, "Invalid message hex (must be even-length hex, 0..%d hex chars)\n", MAX_MSG_LEN * 2);
        memset(seckey, 0, sizeof(seckey));
        return 1;
    }

    if (!secp256k1_keypair_create(ctx, &keypair, seckey)) {
        fprintf(stderr, "Invalid secret key\n");
        memset(seckey, 0, sizeof(seckey));
        return 1;
    }

    have_aux = aux_rand_hex != NULL;
    if (have_aux && !hex_to_bytes(aux_rand_hex, auxiliary_rand, sizeof(auxiliary_rand))) {
        fprintf(stderr, "Invalid aux_rand hex (must be 64 hex chars = 32 bytes)\n");
        memset(seckey, 0, sizeof(seckey));
        memset(&keypair, 0, sizeof(keypair));
        return 1;
    }

    produced = 0;
    for (attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
        secp256k1_schnorrsig_extraparams extraparams = SECP256K1_SCHNORRSIG_EXTRAPARAMS_INIT;
        secp256k1_xonly_pubkey pubkey;

        if (!have_aux) {
            if (!fill_random(auxiliary_rand, sizeof(auxiliary_rand))) {
                fprintf(stderr, "Failed to generate randomness\n");
                memset(seckey, 0, sizeof(seckey));
                memset(&keypair, 0, sizeof(keypair));
                return 1;
            }
        }

        extraparams.ndata = auxiliary_rand;
        if (secp256k1_schnorrsig_sign_custom(ctx, signature, msg, msg_len, &keypair, &extraparams) &&
            secp256k1_keypair_xonly_pub(ctx, &pubkey, NULL, &keypair) &&
            secp256k1_schnorrsig_verify(ctx, signature, msg, msg_len, &pubkey)) {
            produced = 1;
            break;
        }

        if (have_aux) break;
    }
    if (!produced) {
        fprintf(stderr, "Failed to produce a valid signature\n");
        memset(seckey, 0, sizeof(seckey));
        memset(auxiliary_rand, 0, sizeof(auxiliary_rand));
        memset(&keypair, 0, sizeof(keypair));
        return 1;
    }

    print_hex(signature, sizeof(signature));
    printf("\n");

    memset(seckey, 0, sizeof(seckey));
    memset(auxiliary_rand, 0, sizeof(auxiliary_rand));
    memset(&keypair, 0, sizeof(keypair));
    return 0;
}

static int cmd_verify(secp256k1_context* ctx, const char* pk_bech, const char* sig_hex, const char* msg_hex) {
    unsigned char serialized_pubkey[KEY_LEN];
    unsigned char signature[SIGNATURE_LEN];
    unsigned char msg[MAX_MSG_LEN];
    size_t msg_len;
    secp256k1_xonly_pubkey pubkey;

    if (!bech32_decode_bytes(serialized_pubkey, KEY_LEN, "npub", pk_bech)) {
        fprintf(stderr, "Invalid npub\n");
        return 1;
    }

    if (!hex_to_bytes(sig_hex, signature, sizeof(signature))) {
        fprintf(stderr, "Invalid signature hex (must be 128 hex chars = 64 bytes)\n");
        return 1;
    }

    if (!parse_message_hex(msg_hex, msg, MAX_MSG_LEN, &msg_len)) {
        fprintf(stderr, "Invalid message hex (must be even-length hex, 0..%d hex chars)\n", MAX_MSG_LEN * 2);
        return 1;
    }

    if (!secp256k1_xonly_pubkey_parse(ctx, &pubkey, serialized_pubkey)) {
        printf("invalid\n");
        return 1;
    }

    int is_valid = secp256k1_schnorrsig_verify(ctx, signature, msg, msg_len, &pubkey);

    printf("%s\n", is_valid ? "valid" : "invalid");
    return is_valid ? 0 : 1;
}

static void print_usage(const char* prog) {
    fprintf(stderr, "Usage:\n");
    fprintf(stderr, "  %s generate\n", prog);
    fprintf(stderr, "  %s sign <msg_hex> [aux_rand_hex]    (message: 0..%d hex chars, nsec read from stdin)\n", prog, MAX_MSG_LEN * 2);
    fprintf(stderr, "  %s verify <npub> <sig_hex> <msg_hex>  (message: 0..%d hex chars)\n", prog, MAX_MSG_LEN * 2);
}

int main(int argc, char** argv) {
    if (argc < 2) {
        print_usage(argv[0]);
        return 1;
    }

    secp256k1_context* ctx = secp256k1_context_create(SECP256K1_CONTEXT_NONE);
    if (ctx == NULL) {
        fprintf(stderr, "Failed to allocate secp256k1 context\n");
        return 1;
    }
    unsigned char randomize[KEY_LEN];
    if (!fill_random(randomize, sizeof(randomize)) || !secp256k1_context_randomize(ctx, randomize)) {
        fprintf(stderr, "Failed to randomize context\n");
        secp256k1_context_destroy(ctx);
        return 1;
    }
    memset(randomize, 0, sizeof(randomize));

    int ret;
    if (strcmp(argv[1], "generate") == 0) {
        if (argc != 2) {
            print_usage(argv[0]);
            ret = 1;
        } else {
            ret = cmd_generate(ctx);
        }
    } else if (strcmp(argv[1], "sign") == 0) {
        if (argc < 3 || argc > 4) {
            print_usage(argv[0]);
            ret = 1;
        } else {
            ret = cmd_sign(ctx, argv[2], argc == 4 ? argv[3] : NULL);
        }
    } else if (strcmp(argv[1], "verify") == 0) {
        if (argc != 5) {
            print_usage(argv[0]);
            ret = 1;
        } else {
            ret = cmd_verify(ctx, argv[2], argv[3], argv[4]);
        }
    } else {
        fprintf(stderr, "Unknown command: %s\n", argv[1]);
        print_usage(argv[0]);
        ret = 1;
    }

    secp256k1_context_destroy(ctx);
    return ret;
}
