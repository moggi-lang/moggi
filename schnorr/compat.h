/* macOS provides neither of the two things libbech32 relies on. */

#if defined(__APPLE__)
#include <stddef.h>

void *moggi_memrchr(const void *s, int c, size_t n);
#define memrchr moggi_memrchr

/* Mach-O has no symbol aliases; the weak declarations stay. */
#define __alias__(name)

#endif
