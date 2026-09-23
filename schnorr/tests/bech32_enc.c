#include <stdio.h>
#include <string.h>
#include <stdint.h>
#include "bech32.h"

int main(int argc, char **argv) {
    if (argc != 3) return 2;
    if (strlen(argv[2]) % 2 != 0) return 3;
    size_t n = strlen(argv[2]) / 2;
    if (n == 0 || n > 40) return 4;
    unsigned char data[40];
    for (size_t i = 0; i < n; i++) {
        unsigned int b;
        if (sscanf(&argv[2][i * 2], "%2x", &b) != 1) return 5;
        data[i] = (unsigned char)b;
    }
    struct bech32_encoder_state st;
    size_t n_hrp = strlen(argv[1]);
    size_t req = bech32_encoded_size(n_hrp, n * 8, 0);
    char out[128];
    if (req == SIZE_MAX || req + 1 > sizeof(out)) return 6;
    if (bech32_encode_begin(&st, out, sizeof(out), argv[1], n_hrp) != 0) return 7;
    if (bech32_encode_data(&st, data, n * 8) != 0) return 8;
    if (bech32_encode_finish(&st, 1) != 0) return 9;
    out[req] = '\0';
    printf("%s\n", out);
    return 0;
}
