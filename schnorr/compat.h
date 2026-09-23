/* What libbech32 relies on and the platform's C library does not provide. */

#if defined(__APPLE__) || defined(_WIN32)
#include <stddef.h>

/* Neither libSystem nor the Windows CRT has memrchr. */
void *moggi_memrchr(const void *s, int c, size_t n);
#define memrchr moggi_memrchr

/* Mach-O and PE/COFF have no symbol aliases; the weak declarations stay. */
#define __alias__(name)

#endif

#if defined(_WIN32)

/* Windows is LLP64: `size_t` is wider than `unsigned long`, so the `l` builtins
   cannot take its address. The generic ones accept any integer type. */
#define __builtin_uaddl_overflow(a, b, c) __builtin_add_overflow(a, b, c)
#define __builtin_usubl_overflow(a, b, c) __builtin_sub_overflow(a, b, c)

#endif
