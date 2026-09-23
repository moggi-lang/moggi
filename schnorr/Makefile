# Self-contained Makefile for schnorr CLI
# Builds libsecp256k1 and libbech32 from the sources inside this directory.
# No system libraries are required, but both sources have to be cloned first:
# see README.md (Dependencies) for the upstream repositories and pinned tags.

CC = gcc
CFLAGS = -Wall -Wextra -pedantic -std=c99 -O2

# bech32.h marks its declarations with GCC's __access__; clang warns on every use of
# them, GCC has the attribute itself and does not know this flag.
ifneq (,$(findstring clang,$(shell $(CC) --version 2>/dev/null | head -1)))
  CFLAGS += -Wno-unknown-attributes
endif

SECP_DIR = libsecp256k1-0.8.0
BECH32_DIR = libbech32-1.1p1
TESTS_DIR = tests
BUILD_DIR = build
BECH32_ENC = $(BUILD_DIR)/bech32_enc

# --- schnorr app ---
# Feature macros (e.g. _GNU_SOURCE) are defined at the top of schnorr.c itself.
# SECP256K1_STATIC is required when consuming libsecp256k1 as a static library
# on Windows (it is a no-op on other platforms).
APP_INCLUDES = -I$(BECH32_DIR) -I$(SECP_DIR)/include
APP_DEFINES = -DSECP256K1_STATIC

# --- libsecp256k1 ---
# ECMULT_WINDOW_SIZE/COMB_BLOCKS/COMB_TEETH must match the shipped
# precomputed_ecmult*.c tables (i.e. the library defaults).
# Only the modules used by schnorr.c are enabled.
SECP_DEFINES = -DECMULT_WINDOW_SIZE=15 -DCOMB_BLOCKS=11 -DCOMB_TEETH=6 \
	-DENABLE_MODULE_EXTRAKEYS=1 -DENABLE_MODULE_SCHNORRSIG=1
SECP_INCLUDES = -I$(SECP_DIR)/include -I$(SECP_DIR)/src
# The library ships many static helpers that are only used when other
# modules or optional features are enabled; silence that expected noise.
SECP_WARN = -Wno-unused-function

# --- libbech32 ---
# Vendored code with its own -Wparentheses violations.
BECH32_CFLAGS = $(CFLAGS) -Wno-parentheses

SECP_LIB = $(BUILD_DIR)/libsecp256k1.a
SECP_OBJS = $(BUILD_DIR)/secp256k1.o \
	$(BUILD_DIR)/precomputed_ecmult.o \
	$(BUILD_DIR)/precomputed_ecmult_gen.o

# Windows: bcrypt is required for BCryptGenRandom, and the binary has to be
# self-contained — MinGW links libgcc/libwinpthread as DLLs by default, which a
# distribution cannot expect to find on the target machine. The toolchain is
# recognised by the triple it reports, not by its name: in MSYS2's UCRT64 shell
# the Windows compiler is simply `gcc`.
LDLIBS =
LDFLAGS =
PLATFORM = $(shell $(CC) -dumpmachine 2>/dev/null)
ifneq (,$(findstring mingw,$(PLATFORM)))
  LDLIBS += -lbcrypt
  LDFLAGS += -static
endif

# compat.h covers what these platforms lack in C: memrchr (libSystem and the Windows
# CRT), the weak-alias attribute (Mach-O and PE/COFF cannot express it) and the
# size_t-typed overflow builtins (Windows is LLP64, where size_t is wider than
# unsigned long).
ifneq (,$(findstring apple,$(PLATFORM)))
  COMPAT = 1
endif
ifneq (,$(findstring mingw,$(PLATFORM)))
  COMPAT = 1
endif
ifdef COMPAT
  BECH32_CFLAGS += -include $(CURDIR)/compat.h
  COMPAT_OBJS = $(BUILD_DIR)/compat.o
endif

.PHONY: all clean check-deps test-helper

all: check-deps schnorr

test-helper: $(BECH32_ENC)

check-deps:
	@test -f $(SECP_DIR)/src/secp256k1.c || { echo "schnorr: $(SECP_DIR) is missing — clone it at the pinned tag (see README.md, Dependencies)" >&2; exit 1; }
	@test -f $(BECH32_DIR)/libbech32.c || { echo "schnorr: $(BECH32_DIR) is missing — clone it at the pinned tag (see README.md, Dependencies)" >&2; exit 1; }

schnorr: $(BUILD_DIR)/schnorr.o $(BUILD_DIR)/libbech32.o $(SECP_LIB) $(COMPAT_OBJS)
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ $^ $(LDLIBS)

$(BUILD_DIR)/schnorr.o: schnorr.c | $(BUILD_DIR)
	$(CC) $(CFLAGS) $(APP_DEFINES) $(APP_INCLUDES) -MMD -MP -c -o $@ $<

$(BUILD_DIR)/libbech32.o: $(BECH32_DIR)/libbech32.c | $(BUILD_DIR)
	$(CC) $(BECH32_CFLAGS) -I$(BECH32_DIR) -MMD -MP -c -o $@ $<

$(BUILD_DIR)/compat.o: compat.c compat.h | $(BUILD_DIR)
	$(CC) $(CFLAGS) -MMD -MP -c -o $@ $<

$(BECH32_ENC): $(TESTS_DIR)/bech32_enc.c $(BUILD_DIR)/libbech32.o $(COMPAT_OBJS) | $(BUILD_DIR)
	$(CC) $(CFLAGS) $(LDFLAGS) $(APP_INCLUDES) -o $@ $^ $(LDLIBS)

$(BUILD_DIR)/%.o: $(SECP_DIR)/src/%.c | $(BUILD_DIR)
	$(CC) $(CFLAGS) $(SECP_WARN) $(SECP_DEFINES) $(SECP_INCLUDES) -MMD -MP -c -o $@ $<

$(SECP_LIB): $(SECP_OBJS)
	ar rcs $@ $^

$(BUILD_DIR):
	mkdir -p $(BUILD_DIR)

-include $(BUILD_DIR)/*.d

clean:
	rm -rf $(BUILD_DIR) schnorr schnorr.exe