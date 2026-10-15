#!/usr/bin/env php
<?php declare(strict_types=1);

// A `foreign` declaration is checked against the host wherever the host can be asked: PHP reflects
// the compiler's own process, the JVM is asked through `javap`, and the fixtures under
// `tests/semantics/foreign/` prove both end to end. This asserts the two pieces a fixture cannot:
// the `javap` output grammar (which no golden should freeze to one JDK's printing) and the PHP
// verdicts themselves, in one process, with no toolchain in the loop.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\Syntax\Ast;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$same = static function ($expected, $actual, string $message) use ($assert): void {
    $assert(
        $expected === $actual,
        $message . "\n  expected: " . \var_export($expected, true) . "\n  actual:   " . \var_export($actual, true),
    );
};

// --- `javap -s -p` output, as the grammar the parser is written against. ------------------------

$javap = <<<'JAVAP'
Compiled from "String.java"
public final class java.lang.String implements java.io.Serializable,java.lang.Comparable<java.lang.String> {
  public static final java.util.Comparator<java.lang.String> CASE_INSENSITIVE_ORDER;
    descriptor: Ljava/util/Comparator;

  public static final int MAX_VALUE = 2147483647;
    descriptor: I
  static {};
    descriptor: ()V
  public java.lang.String();
    descriptor: ()V
  public java.lang.String(byte[], int, int);
    descriptor: ([BII)V
  public int length();
    descriptor: ()I
  public java.lang.String toUpperCase();
    descriptor: ()Ljava/lang/String;
  public java.lang.String toUpperCase(java.util.Locale);
    descriptor: (Ljava/util/Locale;)Ljava/lang/String;
  public static int parseInt(java.lang.String) throws java.lang.NumberFormatException;
    descriptor: (Ljava/lang/String;)I
  private void secret();
    descriptor: ()V
  public static <T> T[] copyOf(T[] original, int newLength);
    descriptor: ([Ljava/lang/Object;I)[Ljava/lang/Object;
}
JAVAP;

$table = \Moggi\Backend\Jvm\Verify\parseJavapTable($javap);

$same('String', $table['simple'], 'the class header names the simple class name');
$same('()I', $table['methods']['length'][0]['descriptor'], 'an instance method keeps its descriptor');
$assert($table['methods']['length'][0]['public'], '`length` is public');
$assert(isset($table['methods']['String']), 'a constructor is stored under the class simple name');
$same(2, \count($table['methods']['toUpperCase']), 'both `toUpperCase` overloads are kept');
$assert($table['methods']['parseInt'][0]['public'], 'a method with a `throws` clause is still read');
$same(
    '([Ljava/lang/Object;I)[Ljava/lang/Object;',
    $table['methods']['copyOf'][0]['descriptor'],
    'a generic method reads as its erasure',
);
$assert(! $table['methods']['secret'][0]['public'], 'a private method is recorded as not public');
$same('I', $table['fields']['MAX_VALUE']['descriptor'], 'a constant printed with its value is still read');
$same(
    'Ljava/util/Comparator;',
    $table['fields']['CASE_INSENSITIVE_ORDER']['descriptor'],
    'a generic field reads as its erasure',
);
$assert(! isset($table['fields']['{}']), 'a static initializer is not a field');

$same(
    'List',
    \Moggi\Backend\Jvm\Verify\parseJavapTable(
        "Compiled from \"List.java\"\n"
        . "public interface java.util.List<E> extends java.util.Collection<E> {\n"
        . "  boolean isEmpty();\n    descriptor: ()Z\n}\n",
    )['simple'],
    'an interface header names the interface',
);

// "Class not found" is a verdict about the declaration — unless vendor `.java` sources are
// carried, which `javap` never sees. The grammar below only recognises the report; the verdict
// itself is asserted through `javapClassTable` with a fake `javap`, and through a child compile
// with no JDK at all, further down.
$assert(
    \Moggi\Backend\Jvm\Verify\javapCouldNotReadClass("Error: class not found: p.T\n"),
    'javap reports a class it cannot find',
);
$assert(
    ! \Moggi\Backend\Jvm\Verify\javapCouldNotReadClass("public final class java.lang.String {\n}\n"),
    'a class javap printed is readable',
);

$same(0, \Moggi\Backend\Jvm\Verify\descriptorParameterCount('()V'), 'no parameters');
$same(1, \Moggi\Backend\Jvm\Verify\descriptorParameterCount('(I)J'), 'one primitive');
$same(2, \Moggi\Backend\Jvm\Verify\descriptorParameterCount('(Ljava/lang/String;I)V'), 'a reference and a primitive');
$same(1, \Moggi\Backend\Jvm\Verify\descriptorParameterCount('([Ljava/lang/Object;)V'), 'an array is one parameter');
$same(2, \Moggi\Backend\Jvm\Verify\descriptorParameterCount('([[II)V'), 'a nested array is one parameter');
$same(4, \Moggi\Backend\Jvm\Verify\descriptorParameterCount('(ZLjava/lang/String;II)V'), 'four parameters');

// --- The PHP verdicts, against the PHP running this test. --------------------------------------

$int = new Ast\TypeCon('Int');
$string = new Ast\TypeCon('String');
$dateTime = new Ast\TypeCon('DateTime');
// The declared argument count is the arrow count, so a signature is read as the arrows it has.
$arity1 = new Ast\TypeArrow($string, $int);
$arity2 = new Ast\TypeArrow($string, new Ast\TypeArrow($int, $int));
$instantiate = new Ast\TypeArrow($string, new Ast\TypeArrow($string, $dateTime));
$formatOn = new Ast\TypeArrow($dateTime, new Ast\TypeArrow($string, $string));

$php = static fn (string $kind, array $pathInfo, ?Ast\TypeNode $type = null): ?string => \Moggi\Backend\Php\Verify\verifyForeignImportAgainstHost($kind, $pathInfo, $type, 'probe');

$global = static fn (string $path, ?Ast\TypeNode $type = null): array => ['dispatch' => 'global', 'classPath' => null, 'hostMember' => null, 'path' => $path];

$same(
    'php has no function `strlenn`',
    $php('function', $global('strlenn'), $int),
    'a global function the host does not have is reported',
);
$same(
    null,
    $php('function', $global('strlen'), $arity1),
    'a global function with the arity the host declares is accepted',
);
$same(
    'php function `strlen` takes at most 1 argument, but this foreign import declares 2 arguments',
    $php('function', $global('strlen'), $arity2),
    'too many declared arguments is reported',
);
$same(
    null,
    $php('function', $global('printf'), $arity2),
    'a variadic host function accepts more arguments than it lists',
);
$same(
    null,
    $php('const', ['dispatch' => 'static', 'classPath' => 'PHP', 'hostMember' => 'PHP_OS'], $string),
    'a host constant the host does define is accepted',
);
$same(
    'php has no global constant `PHP_OSP`',
    $php('const', ['dispatch' => 'static', 'classPath' => 'PHP', 'hostMember' => 'PHP_OSP'], $int),
    'a host constant the host does not define is reported',
);
$same(
    'php method `DateTime::format` is not static; use `DateTime.format` and pass the receiver first',
    $php('function', ['dispatch' => 'static', 'classPath' => 'DateTime', 'hostMember' => 'format'], $formatOn),
    'a `Class:member` path needs a static host method',
);
$same(
    null,
    $php('function', ['dispatch' => 'static', 'classPath' => 'DateTime', 'hostMember' => 'createFromFormat'], $instantiate),
    'a real static host method is accepted',
);
$same(
    null,
    $php('function', ['dispatch' => 'instance', 'classPath' => 'DateTime', 'hostMember' => 'format'], $formatOn),
    'an instance host method is accepted through `Class.member`',
);
$same(
    'php has no class or interface `Moggi\\Nope`',
    $php('function', ['dispatch' => 'static', 'classPath' => 'Moggi\\Nope', 'hostMember' => 'now'], null),
    'a class the host does not have is reported',
);

// --- There is no switch. -----------------------------------------------------------------------
//
// Verification was once skippable through the environment. It is not any more, and the proof is
// that asking for it to be skipped changes nothing: the same declaration is refused with the same
// diagnostic, from a child whose environment sets the old variable every way it used to read.

require $root . '/tests/suite/support/process.php';

$fixture = $root . '/tests/semantics/foreign/bad/Host-Arity.mog';
$probe = static function (?string $verify) use ($root, $fixture): array {
    $code = 'require ' . \var_export($root . '/src/compiler.php', true) . ';'
        . ' $out = \Moggi\Compiler\compileFileCapturingErrorsBoth(' . \var_export($fixture, true) . ', true, "php");'
        . ' echo $out["emit"]["output"];';
    $env = \getenv();
    unset($env['MOGGI_NO_FFI_VERIFY']);
    if ($verify !== null) {
        $env['MOGGI_NO_FFI_VERIFY'] = $verify;
    }

    return runCompiledProcess([\PHP_BINARY, '-r', $code], 60, $env);
};

$plain = $probe(null);
$same(0, $plain['exitCode'], 'the fixture compiles far enough to be refused: ' . $plain['stderr']);
$assert(
    \str_contains($plain['stdout'], 'php function `strlen` takes at most 1 argument'),
    'the host rejection is what the fixture is for: ' . $plain['stdout'],
);

foreach (['1', 'php', 'all', 'jvm'] as $value) {
    $skipped = $probe($value);
    $same(
        $plain['stdout'],
        $skipped['stdout'],
        "MOGGI_NO_FFI_VERIFY={$value} no longer changes the verdict",
    );
    $assert(
        ! \str_contains($skipped['stderr'], 'FFI_VERIFY'),
        'and nothing claims a check was skipped: ' . $skipped['stderr'],
    );
}

// --- The vendor-tree stand-down (step-1 pin for F4). ---------------------------
//
// With a carried Composer tree, a name that does not resolve is accepted
// today: the whole check stands down project-wide. Step 5 replaces this with
// a lookup over the vendor maps, so a name the tree cannot have provided is
// refused while a name that resolves is still verified. A child process is
// needed because the stand-down memoizes per process.

require_once $root . '/tests/suite/support/workspace.php';

$vendorParent = createTempDir('moggi-php-vendor-pin');
\mkdir($vendorParent . '/vendor', 0777, true);
\file_put_contents($vendorParent . '/vendor/autoload.php', '<?php // probe');

// A second tree that actually provides names: a classmap class, a files-declared function, and a
// PSR-4 prefix whose computed path exists — plus names in none of those, which stay errors.
$richVendor = createTempDir('moggi-php-vendor-rich');
\mkdir($richVendor . '/vendor/composer', 0777, true);
\mkdir($richVendor . '/psr-src', 0777, true);
\file_put_contents($richVendor . '/vendor/autoload.php', '<?php // probe');
\file_put_contents(
    $richVendor . '/vendor/composer/autoload_classmap.php',
    "<?php return ['Vendor\\\\Mapped' => '/src/Mapped.php'];\n",
);
\file_put_contents(
    $richVendor . '/vendor/composer/autoload_files.php',
    "<?php return ['0123456789abcdef0123456789abcdef' => '/helpers.php'];\n",
);
\file_put_contents(
    $richVendor . '/helpers.php',
    "<?php\nfunction vendor_probe_helper() { return 1; }\n",
);
\file_put_contents(
    $richVendor . '/vendor/composer/autoload_psr4.php',
    "<?php return ['Vendor\\\\Psr\\\\' => ['/psr-src']];\n",
);
\file_put_contents($richVendor . '/psr-src/Widget.php', "<?php // probe\n");

$vendorProbe = static function (string $vendorRoot, string $kind, array $pathInfo) use ($root, $same): mixed {
    $code = 'require ' . \var_export($root . '/src/compiler.php', true) . ';'
        . '\Moggi\Modules\setStdlibLibPath(' . \var_export($root . '/libraries/base/src', true) . ');'
        . '\Moggi\Modules\addLibraryRoot(' . \var_export($vendorRoot, true) . ');'
        . 'echo \json_encode(\Moggi\Backend\Php\Verify\verifyForeignImportAgainstHost('
        . \var_export($kind, true) . ', ' . \var_export($pathInfo, true) . ', null, \'probe\'));';
    $result = runCompiledProcess([\PHP_BINARY, '-r', $code], 60, \getenv());
    $same(0, $result['exitCode'], 'the vendor probe must run: ' . $result['stderr']);

    return \json_decode($result['stdout'], true);
};

$same(
    'php has no class or interface `Vendor\\Nope`',
    $vendorProbe($vendorParent, 'function', ['dispatch' => 'static', 'classPath' => 'Vendor\\Nope', 'hostMember' => 'make']),
    'a name no carried tree provides is refused, even with a vendor tree present',
);
$same(
    'php class `DateTime` has no method `nopeMethod`',
    $vendorProbe($vendorParent, 'function', ['dispatch' => 'instance', 'classPath' => 'DateTime', 'hostMember' => 'nopeMethod']),
    'a name that does resolve is still verified while a vendor tree is carried',
);
$same(
    null,
    $vendorProbe($richVendor, 'function', ['dispatch' => 'static', 'classPath' => 'Vendor\\Mapped', 'hostMember' => 'make']),
    'a class the classmap provides is accepted without loading it',
);
$same(
    null,
    $vendorProbe($richVendor, 'function', ['dispatch' => 'instance', 'classPath' => 'Vendor\\Psr\\Widget', 'hostMember' => 'make']),
    'a class a PSR-4 prefix computes to an existing file for is accepted',
);
$same(
    null,
    $vendorProbe($richVendor, 'function', ['dispatch' => 'global', 'classPath' => null, 'hostMember' => null, 'path' => 'vendor_probe_helper']),
    'a function a files-listed file declares is accepted without requiring it',
);
$same(
    'php has no function `vendor_probe_missing`',
    $vendorProbe($richVendor, 'function', ['dispatch' => 'global', 'classPath' => null, 'hostMember' => null, 'path' => 'vendor_probe_missing']),
    'a function no carried tree declares is refused',
);

removeDirectory($vendorParent);
removeDirectory($richVendor);

// --- The verifier without a JDK in the loop (step 4). --------------------------
//
// Fake-tool paths, so the verdicts hold on a machine that has no JDK: a fake
// `javap` reporting "class not found" (non-zero exit) is a verdict, and no
// `javap` anywhere is an error naming it rather than a stand-down.

require_once $root . '/tests/suite/support/workspace.php';

if (\PHP_OS_FAMILY !== 'Windows') {
    $fakeBin = createTempDir('moggi-fake-javap');
    $notFoundJavap = $fakeBin . '/javap';
    \file_put_contents($notFoundJavap, "#!/bin/sh\necho 'Error: class not found: p.T'\nexit 1\n");
    \chmod($notFoundJavap, 0755);
    \mkdir($fakeBin . '/empty', 0777, true);

    $missing = \Moggi\Backend\Jvm\Verify\javapClassTable('p.T', $notFoundJavap);
    $assert(
        $missing !== null && $missing['found'] === false,
        'a class `javap` cannot find is "not found", not "cannot answer"',
    );

    // No `javap` anywhere: scrub the toolchain variables and `PATH` in-process
    // (nothing spawns before the throw, so this is safe) and restore them after.
    $savedEnv = [];
    foreach (['JAVA_HOME', 'GRAALVM_HOME', 'JDK_HOME', 'PATH'] as $var) {
        $savedEnv[$var] = \getenv($var);
        \putenv($var);
    }
    \putenv('PATH=' . $fakeBin . '/empty');

    try {
        try {
            \Moggi\Backend\Jvm\Verify\javapClassTable('java.lang.String');
            $assert(false, 'a missing `javap` must throw');
        } catch (\Moggi\Backend\VerificationUnavailable $e) {
            $assert(
                \str_contains($e->getMessage(), '`javap`'),
                'the missing-tool error names `javap`: ' . $e->getMessage(),
            );
        }
    } finally {
        foreach ($savedEnv as $var => $value) {
            if (\is_string($value)) {
                \putenv("{$var}={$value}");
            } else {
                \putenv($var);
            }
        }
    }
    removeDirectory($fakeBin);
}

// --- Step 4 acceptance: the missing-tool and `PATH`-fallback verdicts ---------
//
// Child compiles, because the toolchain lookup, the warnings and the
// `VerificationUnavailable` refusal are all per-process. The fixture is written
// to a temp dir rather than `tests/` so it never becomes a suite case of its own.

$toolchainWork = createTempDir('moggi-javap-toolchain');
$badMember = $toolchainWork . '/Bad.mog';
\file_put_contents($badMember, "foreign jvm function nosuch \"java.lang.String:nosuchmember\" :: String -> String\n");

$scrubbedToolchainEnv = static function () use ($toolchainWork): array {
    $env = \getenv();
    unset($env['JAVA_HOME'], $env['GRAALVM_HOME'], $env['JDK_HOME']);
    $env['MOGGI_CACHE_DIR'] = $toolchainWork . '/cache';

    return $env;
};

$compileBadMember = static function (array $env) use ($root, $badMember): array {
    $code = 'require ' . \var_export($root . '/src/compiler.php', true) . ';'
        . ' $out = \Moggi\Compiler\compileFileCapturingErrorsBoth(' . \var_export($badMember, true) . ', true, "jvm");'
        . ' echo $out["emit"]["output"];'
        . ' exit($out["emit"]["exitCode"]);';

    return runCompiledProcess([\PHP_BINARY, '-r', $code], 120, $env);
};

if (\PHP_OS_FAMILY !== 'Windows') {
    // No JDK on `PATH` and no toolchain variable: the bad declaration fails
    // and the failure names `javap`.
    $emptyBin = $toolchainWork . '/empty-bin';
    \mkdir($emptyBin, 0777, true);
    $noJdkEnv = $scrubbedToolchainEnv();
    $noJdkEnv['PATH'] = $emptyBin;
    $noJdk = $compileBadMember($noJdkEnv);
    $assert($noJdk['exitCode'] !== 0, 'without a JDK the bad declaration must fail: ' . $noJdk['stderr']);
    $assert(
        \str_contains($noJdk['stdout'], '`javap`'),
        'the failure must name `javap`: ' . $noJdk['stdout'],
    );

    // A fake `javap` early on `PATH` must not let the bad declaration pass,
    // and the fallback warning must name the binary that answered.
    $fallbackBin = $toolchainWork . '/fallback-bin';
    \mkdir($fallbackBin, 0777, true);
    $fakeJavap = $fallbackBin . '/javap';
    \file_put_contents($fakeJavap, "#!/bin/sh\necho 'Error: class not found: java.lang.String'\nexit 1\n");
    \chmod($fakeJavap, 0755);
    $fallbackEnv = $scrubbedToolchainEnv();
    $fallbackEnv['PATH'] = $fallbackBin . ':' . (string) \getenv('PATH');
    $fallback = $compileBadMember($fallbackEnv);
    $assert($fallback['exitCode'] !== 0, 'a fake `javap` must not let a bad declaration pass: ' . $fallback['stderr']);
    $assert(
        \str_contains($fallback['stderr'], $fakeJavap),
        'the `PATH`-fallback warning must name the binary: ' . $fallback['stderr'],
    );
}

removeDirectory($toolchainWork);

echo "foreign host verification tests passed\n";
