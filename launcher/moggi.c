/*
 * moggi — native launcher for a packaged Moggi installation.
 *
 * The launcher does three things and nothing else: find the compiler archive
 * next to itself, find the runtimes the requested command needs, and hand the
 * process over to PHP with the arguments and environment it was given.
 *
 * Layout it expects, all relative to the launcher executable's own directory
 * (never the working directory, so `moggi` runs from anywhere):
 *
 *   bin/moggi          this launcher
 *   bin/moggi.phar     the compiler
 *   runtime/php/…      bundled PHP        (optional)
 *   runtime/dotnet/…   bundled .NET SDK   (optional)
 *   runtime/jvm/…      bundled JDK        (optional)
 *   runtime/graalvm/…  bundled GraalVM    (optional)
 *
 * Every runtime is optional. A bundled one is put in front of PATH, so the
 * compiler's child processes find it before any system copy; one that is not
 * bundled is looked up on PATH. A runtime is required only when the command
 * asks for it (a .NET or JVM backend, or a native build), and a missing one is
 * a clear error instead of a confusing failure inside the compiler.
 *
 * Build (clang):
 *   clang -std=c11 -O2 -Wall -Wextra -o bin/moggi launcher/moggi.c
 */

#if defined(_WIN32)
/* The UCRT marks strcpy/strcat/getenv deprecated, and the launcher builds with -Werror. */
#define _CRT_SECURE_NO_WARNINGS
#endif

#if !defined(_WIN32)
#define _POSIX_C_SOURCE 200809L
#if defined(__APPLE__)
#define _DARWIN_C_SOURCE 1
#endif
#endif

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#if defined(_WIN32)

#include <windows.h>
#include <process.h>
#define MOGGI_PATH_SEP ';'
#define MOGGI_DIR_SEP '\\'

#else

#include <limits.h>
#include <sys/stat.h>
#include <unistd.h>
#define MOGGI_PATH_SEP ':'
#define MOGGI_DIR_SEP '/'

#if defined(__APPLE__)
#include <mach-o/dyld.h>
#endif

#endif

/* Same convention as a shell: the command could not be run at all. */
#define EXIT_RUNTIME_MISSING 127

static void fail(const char *message)
{
    fprintf(stderr, "moggi: %s\n", message);
    exit(EXIT_RUNTIME_MISSING);
}

static void fail_path(const char *message, const char *path)
{
    fprintf(stderr, "moggi: %s%s\n", message, path);
    exit(EXIT_RUNTIME_MISSING);
}

static char *dup_string(const char *text);

static int file_exists(const char *path)
{
#if defined(_WIN32)
    DWORD attributes = GetFileAttributesA(path);
    return attributes != INVALID_FILE_ATTRIBUTES && (attributes & FILE_ATTRIBUTE_DIRECTORY) == 0;
#else
    struct stat st;
    return stat(path, &st) == 0 && S_ISREG(st.st_mode);
#endif
}

static int dir_exists(const char *path)
{
#if defined(_WIN32)
    DWORD attributes = GetFileAttributesA(path);
    return attributes != INVALID_FILE_ATTRIBUTES && (attributes & FILE_ATTRIBUTE_DIRECTORY) != 0;
#else
    struct stat st;
    return stat(path, &st) == 0 && S_ISDIR(st.st_mode);
#endif
}

static int executable_exists(const char *path)
{
#if defined(_WIN32)
    return file_exists(path);
#else
    return access(path, X_OK) == 0;
#endif
}

/* Allocate "dir" + separator + "name". */
static char *path_join(const char *dir, const char *name)
{
    size_t dir_length = strlen(dir);
    size_t name_length = strlen(name);
    char *joined = (char *)malloc(dir_length + name_length + 2);
    if (joined == NULL) {
        fail("out of memory");
    }
    memcpy(joined, dir, dir_length);
    joined[dir_length] = MOGGI_DIR_SEP;
    memcpy(joined + dir_length + 1, name, name_length + 1);

    return joined;
}

/* Duplicate `text` with its last path component removed. */
static char *parent_directory(const char *text)
{
    char *parent = dup_string(text);
    char *separator = strrchr(parent, MOGGI_DIR_SEP);
#if defined(_WIN32)
    if (separator == NULL) {
        separator = strrchr(parent, '/');
    }
#endif
    if (separator == NULL) {
        fail("the launcher does not live in a `bin` directory");
    }
    *separator = '\0';

    return parent;
}

/*
 * Directory holding this executable, with symlinks resolved where the platform
 * can report it. The result has no trailing separator.
 */
static char *executable_directory(void)
{
#if defined(_WIN32)

    char buffer[MAX_PATH];
    DWORD length = GetModuleFileNameA(NULL, buffer, (DWORD)sizeof(buffer));
    if (length == 0 || length >= sizeof(buffer)) {
        fail("cannot determine the launcher's own path");
    }
    char *separator = strrchr(buffer, '\\');
    if (separator == NULL) {
        separator = strrchr(buffer, '/');
    }
    if (separator == NULL) {
        fail("the launcher does not live in a `bin` directory");
    }
    *separator = '\0';

#elif defined(__APPLE__)

    char buffer[PATH_MAX];
    uint32_t size = (uint32_t)sizeof(buffer);
    if (_NSGetExecutablePath(buffer, &size) != 0) {
        fail("cannot determine the launcher's own path");
    }
    char resolved[PATH_MAX];
    if (realpath(buffer, resolved) == NULL) {
        fail("cannot resolve the launcher's own path");
    }
    char *separator = strrchr(resolved, '/');
    if (separator == NULL) {
        fail("the launcher does not live in a `bin` directory");
    }
    *separator = '\0';
    strcpy(buffer, resolved);

#else

    char buffer[PATH_MAX];
    ssize_t length = readlink("/proc/self/exe", buffer, sizeof(buffer) - 1);
    if (length <= 0) {
        fail("cannot determine the launcher's own path");
    }
    buffer[length] = '\0';
    char *separator = strrchr(buffer, '/');
    if (separator == NULL) {
        fail("the launcher does not live in a `bin` directory");
    }
    *separator = '\0';

#endif

    char *copy = (char *)malloc(strlen(buffer) + 1);
    if (copy == NULL) {
        fail("out of memory");
    }
    strcpy(copy, buffer);

    return copy;
}

/*
 * Search PATH for an executable, without a shell and without `which`. PATH is
 * read as it currently stands, so a bundled directory this process has already
 * prepended is searched first. On Windows a bare name is not a file name — a
 * program is `php.exe`, `java.exe`, or a `.cmd` script such as GraalVM's
 * `native-image` — so every suffix the platform uses is tried.
 */
static char *find_on_path(const char *name)
{
    const char *path = getenv("PATH");
    if (path == NULL || *path == '\0') {
        return NULL;
    }

    static const char *suffixes[] =
#if defined(_WIN32)
        {"", ".exe", ".cmd", ".bat", ".com"};
#else
        {""};
#endif

    char *copy = (char *)malloc(strlen(path) + 1);
    if (copy == NULL) {
        fail("out of memory");
    }
    strcpy(copy, path);

    char *cursor = copy;
    char *candidate = NULL;
    while (cursor != NULL && *cursor != '\0') {
        char *separator = strchr(cursor, MOGGI_PATH_SEP);
        if (separator != NULL) {
            *separator = '\0';
        }
        if (*cursor != '\0') {
            for (size_t i = 0; i < sizeof(suffixes) / sizeof(suffixes[0]); ++i) {
                char *entry = path_join(cursor, name);
                size_t needed = strlen(entry) + strlen(suffixes[i]) + 1;
                char *joined = (char *)malloc(needed);
                if (joined == NULL) {
                    fail("out of memory");
                }
                snprintf(joined, needed, "%s%s", entry, suffixes[i]);
                free(entry);
                if (executable_exists(joined)) {
                    candidate = joined;
                    break;
                }
                free(joined);
            }
            if (candidate != NULL) {
                break;
            }
        }
        cursor = separator == NULL ? NULL : separator + 1;
    }
    free(copy);

    return candidate;
}

/*
 * Put the bundled runtime directories in front of PATH, in the order given, so
 * the compiler's child processes (`javac`, `java`, `native-image`, `dotnet`,
 * `php`) resolve to the bundled copies before any system ones.
 *
 * The list is built by offset: writing the separator after an appended directory
 * is what terminates it otherwise, and the next append then runs past the write.
 */
static void prepend_paths(char **directories, int count)
{
    const char *current = getenv("PATH");
    size_t needed = 1;
    for (int i = 0; i < count; ++i) {
        needed += strlen(directories[i]) + 1;
    }
    needed += current != NULL ? strlen(current) : 0;

    char *final = (char *)malloc(needed);
    if (final == NULL) {
        fail("out of memory");
    }
    size_t offset = 0;
    for (int i = 0; i < count; ++i) {
        size_t length = strlen(directories[i]);
        memcpy(final + offset, directories[i], length);
        offset += length;
        final[offset++] = MOGGI_PATH_SEP;
    }
    strcpy(final + offset, current != NULL ? current : "");

#if defined(_WIN32)
    SetEnvironmentVariableA("PATH", final);
#else
    setenv("PATH", final, 1);
#endif

    free(final);
}

static void set_environment(const char *name, const char *value)
{
#if defined(_WIN32)
    SetEnvironmentVariableA(name, value);
#else
    setenv(name, value, 1);
#endif
}

/*
 * A bundled PHP can carry its own shared libraries in `php/lib` (a source build
 * links against libzip and oniguruma, which the host is not required to have).
 * The loader variable is the one the platform's dynamic linker reads.
 */
static void add_library_path(const char *directory)
{
    if (!dir_exists(directory)) {
        return;
    }

#if defined(_WIN32)
    (void)directory;
#else
#if defined(__APPLE__)
    const char *name = "DYLD_FALLBACK_LIBRARY_PATH";
#else
    const char *name = "LD_LIBRARY_PATH";
#endif

    const char *current = getenv(name);
    size_t needed = strlen(directory) + (current != NULL ? strlen(current) : 0) + 2;
    char *value = (char *)malloc(needed);
    if (value == NULL) {
        fail("out of memory");
    }
    snprintf(value, needed, "%s%c%s", directory, MOGGI_PATH_SEP, current != NULL ? current : "");
    set_environment(name, value);
    free(value);
#endif
}

static char *dup_string(const char *text)
{
    char *copy = (char *)malloc(strlen(text) + 1);
    if (copy == NULL) {
        fail("out of memory");
    }
    strcpy(copy, text);

    return copy;
}

static int starts_with(const char *text, const char *prefix)
{
    return strncmp(text, prefix, strlen(prefix)) == 0;
}

typedef struct {
    const char *backend;
    int native_build;
} Needs;

static Needs scan_needs(int argc, char **argv)
{
    Needs needs;
    needs.backend = "php";
    needs.native_build = 0;

    for (int i = 1; i < argc; ++i) {
        const char *arg = argv[i];
        if (strcmp(arg, "--backend") == 0 && i + 1 < argc) {
            needs.backend = argv[++i];
        } else if (starts_with(arg, "--backend=")) {
            needs.backend = arg + strlen("--backend=");
        } else if (strcmp(arg, "--native") == 0) {
            needs.native_build = 1;
        } else if (strcmp(arg, "--") == 0) {
            /* Everything after `--` belongs to the compiled program. */
            break;
        }
    }

    return needs;
}

int main(int argc, char **argv)
{
    char *bin_dir = executable_directory();
    char *dist_dir = parent_directory(bin_dir);

    char *phar = path_join(bin_dir, "moggi.phar");
    if (!file_exists(phar)) {
        fail_path("cannot find the compiler archive next to this launcher: ", phar);
    }

    char *runtime_dir = path_join(dist_dir, "runtime");
#if defined(_WIN32)
    char *php_bundled = path_join(runtime_dir, "php/php.exe");
    char *php_bundled_bin = path_join(runtime_dir, "php");
#else
    char *php_bundled = path_join(runtime_dir, "php/bin/php");
    char *php_bundled_bin = path_join(runtime_dir, "php/bin");
#endif
    char *dotnet_dir = path_join(runtime_dir, "dotnet");
    char *jvm_dir = path_join(runtime_dir, "jvm");
    char *graalvm_dir = path_join(runtime_dir, "graalvm");

    /* Bundled first, then the system PATH. */
    char *php = executable_exists(php_bundled) ? php_bundled : find_on_path("php");
    if (php == NULL) {
        fprintf(
            stderr,
            "moggi: no PHP runtime found.\n"
            "  looked for the bundled %s\n"
            "  and for `php` on PATH\n"
            "  install PHP 8.5+, or use one of the Moggi distributions that bundle it\n",
            php_bundled);
        exit(EXIT_RUNTIME_MISSING);
    }

    Needs needs = scan_needs(argc, argv);

    /* Bundled runtime directories, in resolution order. */
    char *bundled[4];
    int bundled_count = 0;
    char *jvm_bin = NULL;
    char *graalvm_bin = NULL;

    if (dir_exists(jvm_dir)) {
        jvm_bin = path_join(jvm_dir, "bin");
        if (dir_exists(jvm_bin)) {
            bundled[bundled_count++] = jvm_bin;
            /*
             * The compiler prefers JAVA_HOME over PATH, so a bundled JDK has to
             * be handed over the same way a bundled SDK is: otherwise a host
             * JAVA_HOME silently wins and the distribution runs on a JDK nobody
             * tested it with.
             */
            set_environment("JAVA_HOME", jvm_dir);
        }
    }
    if (dir_exists(graalvm_dir)) {
        graalvm_bin = path_join(graalvm_dir, "bin");
        if (dir_exists(graalvm_bin)) {
            bundled[bundled_count++] = graalvm_bin;
        }
    }
    if (dir_exists(dotnet_dir)) {
        bundled[bundled_count++] = dotnet_dir;
        /* The SDK resolves its own runtime from here. */
        set_environment("DOTNET_ROOT", dotnet_dir);
    }
    if (dir_exists(php_bundled_bin)) {
        bundled[bundled_count++] = php_bundled_bin;
        /*
         * A bundled PHP may carry an ini next to the binary (the official
         * php.net Windows build ships its extensions as DLLs, which PHP only
         * loads when an ini names them). PHPRC is PHP's own way to say where to
         * look, and pointing it at the bundled runtime keeps the host's
         * configuration out of the picture.
         */
        char *php_home = path_join(runtime_dir, "php");
        char *php_ini = path_join(php_home, "php.ini");
        if (file_exists(php_ini)) {
            set_environment("PHPRC", php_home);
            set_environment("PHP_INI_SCAN_DIR", "");
        }
        char *php_lib = path_join(php_home, "lib");
        add_library_path(php_lib);
        free(php_lib);
        free(php_ini);
        free(php_home);
    }
    if (bundled_count > 0) {
        prepend_paths(bundled, bundled_count);
    }

    /* A runtime counts as available when it can be found, bundled or not. */
    int have_dotnet = dir_exists(dotnet_dir);
    if (!have_dotnet) {
        char *found = find_on_path("dotnet");
        have_dotnet = found != NULL;
        free(found);
    }
    int have_jvm = dir_exists(jvm_dir);
    if (!have_jvm) {
        char *found = find_on_path("java");
        have_jvm = found != NULL;
        free(found);
    }
    int have_graalvm = dir_exists(graalvm_dir);
    if (!have_graalvm) {
        char *found = find_on_path("native-image");
        have_graalvm = found != NULL;
        free(found);
    }

    if (strcmp(needs.backend, "dotnet") == 0 && !have_dotnet) {
        fail_path("--backend dotnet needs a .NET SDK: nothing bundled at ", dotnet_dir);
    }
    if (strcmp(needs.backend, "jvm") == 0 && !have_jvm) {
        fail_path("--backend jvm needs a JDK: nothing bundled at ", jvm_dir);
    }
    if (needs.native_build && strcmp(needs.backend, "jvm") == 0 && !have_graalvm) {
        fail_path("--native with --backend jvm needs GraalVM (native-image): nothing bundled at ", graalvm_dir);
    }
    if (needs.native_build && strcmp(needs.backend, "dotnet") == 0 && !have_dotnet) {
        fail_path("--native with --backend dotnet needs a .NET SDK: nothing bundled at ", dotnet_dir);
    }

    /*
     * PHP argv: php [-d extension_dir=…] <phar> <arguments…>, with PHP's own name
     * replaced. A bundled PHP that ships its extensions as DLLs (the official
     * php.net Windows build) needs an absolute extension directory: a relative
     * one is resolved against whatever directory the user happens to be in.
     */
    char *php_home = path_join(runtime_dir, "php");
    char *extension_dir = path_join(php_home, "ext");
    free(php_home);
    int extra = dir_exists(extension_dir) ? 2 : 0;
    char *extension_option = NULL;
    if (extra > 0) {
        size_t needed = strlen(extension_dir) + 20;
        extension_option = (char *)malloc(needed);
        if (extension_option == NULL) {
            fail("out of memory");
        }
        snprintf(extension_option, needed, "extension_dir=%s", extension_dir);
    }
    free(extension_dir);

    char **child_argv = (char **)calloc((size_t)argc + 2 + (size_t)extra, sizeof(char *));
    if (child_argv == NULL) {
        fail("out of memory");
    }
    child_argv[0] = php;
    if (extra > 0) {
        child_argv[1] = "-d";
        child_argv[2] = extension_option;
    }
    child_argv[1 + extra] = phar;
    for (int i = 1; i < argc; ++i) {
        child_argv[i + 1 + extra] = argv[i];
    }
    child_argv[argc + 1 + extra] = NULL;

#if defined(_WIN32)
    intptr_t status = _spawnv(_P_WAIT, php, (const char *const *)child_argv);
    if (status == -1) {
        fail_path("cannot start PHP: ", php);
    }

    return (int)status;
#else
    execv(php, child_argv);
    fail_path("cannot start PHP: ", php);

    return EXIT_RUNTIME_MISSING;
#endif
}
