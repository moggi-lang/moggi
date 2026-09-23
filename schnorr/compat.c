#include "compat.h"

#if defined(__APPLE__) || defined(_WIN32)

void *moggi_memrchr(const void *s, int c, size_t n)
{
	const unsigned char *p = (const unsigned char *) s + n;

	while (n--) {
		if (*--p == (unsigned char) c) {
			return (void *) p;
		}
	}

	return NULL;
}

#endif
