#!/usr/bin/env php
<?php declare(strict_types=1);

// A runtime requirement a registry cannot fetch, so the resolver gathers it
// across a closure and answers which runtime provides it. These checks cover the
// `[requires.<role>.<backend>]` schema and its legacy `[php] extension`
// shorthand, the entry syntax, the merge across the root and installed packages,
// the optional marker, and the two runtimes: the PHP running this and the micro
// runtime a `--native` build appends to.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use const Moggi\Registry\EXTENSION_MANIFEST;

use function Moggi\Registry\collectPhpExtensions;
use function Moggi\Registry\collectRequirementEntries;
use function Moggi\Registry\descriptorProblems;
use function Moggi\Registry\descriptorRequirementEntries;
use function Moggi\Registry\installDir;
use function Moggi\Registry\installedPhpExtensions;
use function Moggi\Registry\installedRequirements;
use function Moggi\Registry\microRuntimeExtensions;
use function Moggi\Registry\parsePhpExtensionEntry;
use function Moggi\Registry\phpExtensionDescriptors;
use function Moggi\Registry\phpExtensionProblems;
use function Moggi\Registry\phpRuntimeExtensions;
use function Moggi\Registry\readDescriptor;
use function Moggi\Registry\requirementSections;
use function Moggi\Registry\runtimeExtensionsAt;
use function Moggi\Registry\unmetPhpExtensions;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$work = \sys_get_temp_dir() . '/moggi-extensions-' . \bin2hex(\random_bytes(6));
\mkdir($work, 0777, true);
\putenv('MOGGI_CACHE_DIR=' . $work . '/cache');

$remove = static function (string $path) use (&$remove): void {
    if (!\file_exists($path) && !\is_link($path)) {
        return;
    }
    if (\is_dir($path) && !\is_link($path)) {
        foreach (\scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $remove($path . '/' . $name);
            }
        }
        @\rmdir($path);

        return;
    }
    @\unlink($path);
};

try {
    // --- entry syntax --------------------------------------------------------
    $plain = parsePhpExtensionEntry('redis');
    $assert($plain['name'] === 'redis' && $plain['optional'] === false && $plain['path'] === null, 'a bare entry is a required name');

    $optional = parsePhpExtensionEntry('?gd');
    $assert($optional['name'] === 'gd' && $optional['optional'] === true, 'a leading `?` marks an entry optional');

    $versioned = parsePhpExtensionEntry('intl^1.2');
    $assert($versioned['name'] === 'intl' && $versioned['optional'] === false, 'a version constraint is dropped from the name');
    $assert(parsePhpExtensionEntry('intl<3')['name'] === 'intl', 'a `<` constraint is dropped from the name');

    $pathed = parsePhpExtensionEntry('imagick=ext/imagick.so');
    $assert($pathed['name'] === 'imagick' && $pathed['path'] === 'ext/imagick.so', 'a `=` entry names a path');

    $both = parsePhpExtensionEntry('?imagick=ext/imagick.so');
    $assert($both['optional'] === true && $both['name'] === 'imagick' && $both['path'] === 'ext/imagick.so', 'optional and a path combine');

    // --- the merge across the closure ---------------------------------------
    $collected = collectPhpExtensions([
        'root' => ['php' => ['extensions' => ['bcmath', '?redis']]],
        'demo' => ['php' => ['extensions' => ['redis', 'intl']]],
    ]);
    $assert(\array_keys($collected) === ['bcmath', 'intl', 'redis'], 'the merged set is keyed case-folded and sorted');
    $assert($collected['redis']['optional'] === false, 'one required source makes a name required');
    $assert($collected['redis']['sources'] === ['root', 'demo'], 'every label that asked is recorded');
    $assert($collected['bcmath']['sources'] === ['root'], 'a single source is recorded once');
    $assert($collected['intl']['optional'] === false, 'a required name stays required');

    $allOptional = collectPhpExtensions([
        'root' => ['php' => ['extensions' => ['?redis']]],
        'demo' => ['php' => ['extensions' => ['?redis']]],
    ]);
    $assert($allOptional['redis']['optional'] === true, 'a name optional everywhere stays optional');

    // --- comparing against a runtime ----------------------------------------
    $requirements = collectPhpExtensions(['root' => ['php' => ['extensions' => ['Redis', '?gd', 'intl']]]]);
    $unmet = unmetPhpExtensions($requirements, ['core', 'redis', 'INTL']);
    $assert($unmet === [], 'a case-folded match is a match, and an optional entry is never unmet: ' . \implode(', ', $unmet));

    $missing = unmetPhpExtensions($requirements, ['core']);
    $assert($missing === ['intl', 'Redis'], 'only required names are reported, in the descriptor spelling: ' . \implode(', ', $missing));

    $running = phpRuntimeExtensions();
    $assert(\in_array('core', $running, true), 'the running PHP always reports its core');

    // --- an installed package's descriptor ----------------------------------
    $digest = 'sha256:' . \str_repeat('a', 64);
    $install = installDir('demo', $digest);
    if (!\is_dir($install) && !\mkdir($install, 0777, true) && !\is_dir($install)) {
        throw new \RuntimeException("cannot create {$install}");
    }
    \file_put_contents($install . '/demo.moggi', "[package]\nname = demo\nversion = 1.0.0\n\n[php]\nextension = redis, ?gd\n");

    $installed = installedPhpExtensions($install, 'demo');
    $assert($installed === ['redis', '?gd'], 'an installed package\'s extensions are read from its descriptor');
    $assert(installedPhpExtensions($install, 'absent') === null, 'a package with no descriptor answers null');

    $descriptors = phpExtensionDescriptors(
        ['php' => ['extensions' => ['bcmath']]],
        ['packages' => ['demo' => ['version' => '1.0.0', 'digest' => $digest]]],
    );
    $assert(\array_keys($descriptors) === ['root', 'demo'], 'the closure is the root plus every installed package');
    $assert($descriptors['demo']['requirements'] === ['requires.program.php' => ['redis', '?gd']], 'the installed package contributes its own entries');

    // --- a runtime's recorded manifest --------------------------------------
    $sfxDir = $work . '/php-native';
    \mkdir($sfxDir, 0777, true);
    \file_put_contents($sfxDir . '/micro.sfx', "not a real runtime\n");
    \file_put_contents($sfxDir . '/' . EXTENSION_MANIFEST, \json_encode(['runtime' => 'php-native', 'extensions' => ['standard', 'intl']]) . "\n");

    $assert(runtimeExtensionsAt($work . '/absent') === null, 'a directory with no manifest records nothing');
    $recorded = runtimeExtensionsAt($sfxDir);
    $assert($recorded === ['standard', 'intl'], 'a runtime manifest is read back');

    \putenv('MOGGI_MICRO_SFX=' . $sfxDir . '/micro.sfx');
    $assert(microRuntimeExtensions() === ['standard', 'intl'], 'the micro runtime records its extensions beside micro.sfx');

    // `standard` is in every PHP; `redis` is in neither runtime here.
    $rootDescriptor = ['backends' => ['php'], 'php' => ['extensions' => ['standard', 'redis']]];
    $withManifest = phpExtensionProblems(phpExtensionDescriptors($rootDescriptor, []));
    $assert($withManifest['blocking'] === ['redis'], 'an extension the micro runtime lacks is blocking: ' . \json_encode($withManifest));
    $assert(\count($withManifest['problems']) === 2, 'the missing extension is reported against both the running PHP and the micro runtime');
    $assert(\str_contains($withManifest['problems'][0], 'the PHP running this'), 'the first problem names the running PHP');
    $assert(\str_contains($withManifest['problems'][1], 'the runtime a --native build uses'), 'the second problem names the native runtime');
    $assert(!\str_contains(\implode("\n", $withManifest['problems']), 'micro.sfx'), 'no message names the runtime mechanism');

    $optionalOnly = ['backends' => ['php'], 'php' => ['extensions' => ['standard', '?redis']]];
    $satisfied = phpExtensionProblems(phpExtensionDescriptors($optionalOnly, []));
    $assert($satisfied['problems'] === [], 'an optional extension the micro runtime lacks is not reported');

    \unlink($sfxDir . '/' . EXTENSION_MANIFEST);
    $unknown = phpExtensionProblems(phpExtensionDescriptors($rootDescriptor, []));
    $assert($unknown['blocking'] === [], 'a runtime that records nothing blocks nothing');
    $assert(\count(\array_filter($unknown['problems'], static fn (string $p): bool => \str_contains($p, 'cannot be verified'))) === 1, 'a runtime that records nothing is reported as unverifiable');

    \putenv('MOGGI_MICRO_SFX');

    // --- the `[requires.<role>.<backend>]` schema ---------------------------
    $npub = 'npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm';
    $descriptorFor = static function (string $name, string $body) use ($npub): string {
        return "[package]\nname = {$name}\nversion = 1.0.0\n\n[author]\nname = Test\nnpub = {$npub}\n\n{$body}";
    };
    $writeDescriptor = static function (string $name, string $body) use ($work, $descriptorFor): string {
        $path = $work . '/' . $name . '.moggi';
        \file_put_contents($path, $descriptorFor($name, $body));

        return $path;
    };

    // A section declares one role and one backend, and both roles are read.
    $path = $writeDescriptor('roleful', "[lib]\nsource-dirs = .\n\n[requires.program.php]\nextension = intl, bcmath\n\n[requires.compiler.php]\nextension = phar\n");
    $assert(descriptorProblems($path) === [], 'a descriptor with both requirement roles is valid: ' . \implode('; ', descriptorProblems($path)));
    $read = readDescriptor($path);
    $assert($read['requirements']['requires.program.php'] === ['intl', 'bcmath'], 'the program role is read');
    $assert($read['requirements']['requires.compiler.php'] === ['phar'], 'the compiler role is read');
    $assert(descriptorRequirementEntries($read, 'program', 'php') === ['intl', 'bcmath'], 'the program role is scoped out of the compiler set');
    $assert(descriptorRequirementEntries($read, 'compiler', 'php') === ['phar'], 'the compiler role is scoped out of the program set');
    $assert(descriptorRequirementEntries($read, 'program', 'jvm') === [], 'a backend with no section declares nothing');

    // The legacy `[php] extension` key is the same declaration, program-scoped.
    $legacyPath = $writeDescriptor('legacy', "[lib]\nsource-dirs = .\n\n[php]\nextension = gd, ?redis\n");
    $legacy = readDescriptor($legacyPath);
    $assert($legacy['requirements']['requires.program.php'] === ['gd', '?redis'], '`[php] extension` reads as the program-side php requirement');
    $assert($legacy['php']['extensions'] === ['gd', '?redis'], 'the legacy key is still exposed in its old place');
    $assert(descriptorRequirementEntries($legacy, 'compiler', 'php') === [], 'the legacy key contributes nothing to the compiler role');

    // Both spellings in one file are the one set, without a duplicate.
    $bothPath = $writeDescriptor('both', "[lib]\nsource-dirs = .\n\n[requires.program.php]\nextension = intl\n\n[php]\nextension = intl, bcmath\n");
    $assert(readDescriptor($bothPath)['requirements']['requires.program.php'] === ['intl', 'bcmath'], 'the two spellings fold into one set, deduplicated');

    // --- the header is the schema --------------------------------------------
    $badRole = $writeDescriptor('badrole', "[requires.programm.php]\nextension = intl\n");
    $assert(\str_contains(\implode('\n', descriptorProblems($badRole)), 'unknown role `programm`'), 'a misspelled role is refused, not read as empty');

    $badBackend = $writeDescriptor('badbackend', "[requires.program.ruby]\nextension = intl\n");
    $assert(\str_contains(\implode('\n', descriptorProblems($badBackend)), 'unknown backend `ruby`'), 'an unknown backend is refused');

    $noBackend = $writeDescriptor('nobackend', "[requires.program]\nextension = intl\n");
    $assert(\str_contains(\implode('\n', descriptorProblems($noBackend)), 'is not `[requires.<role>.<backend>]`'), 'a role with no backend is refused');

    $badKey = $writeDescriptor('badkey', "[requires.program.php]\nextensions = intl\n");
    $assert(\str_contains(\implode('\n', descriptorProblems($badKey)), 'does not take `extensions`'), 'a key the section does not declare is refused');

    $jvmKey = $writeDescriptor('jvmkey', "[requires.program.jvm]\nextension = intl\n");
    $assert(\str_contains(\implode('\n', descriptorProblems($jvmKey)), 'does not take `extension`'), 'a reserved backend takes no requirement key yet');

    $badEntry = $writeDescriptor('badentry', "[requires.program.php]\nextension = intl, 9lives\n");
    $assert(\str_contains(\implode('\n', descriptorProblems($badEntry)), 'is not an extension name'), 'a malformed extension name is refused at the descriptor');

    // --- the merge folds a name both roles ask for ---------------------------
    $merged = collectRequirementEntries([
        'root' => ['requirements' => ['requires.program.php' => ['intl']]],
        'dep' => ['requirements' => ['requires.compiler.php' => ['intl', 'phar']]],
    ], 'program', 'php');
    $assert($merged['intl']['sources'] === ['root'], 'the program collection sees only the program role');
    $compiler = collectRequirementEntries([
        'root' => ['requirements' => ['requires.program.php' => ['intl']]],
        'dep' => ['requirements' => ['requires.compiler.php' => ['intl', 'phar']]],
    ], 'compiler', 'php');
    $assert(\array_keys($compiler) === ['intl', 'phar'], 'the compiler collection sees only the compiler role');

    // An installed package contributes both roles from its own descriptor.
    $installDir = installDir('roleful', 'sha256:' . \str_repeat('b', 64));
    if (!\is_dir($installDir) && !\mkdir($installDir, 0777, true) && !\is_dir($installDir)) {
        throw new \RuntimeException("cannot create {$installDir}");
    }
    \file_put_contents($installDir . '/roleful.moggi', "[package]\nname = roleful\nversion = 1.0.0\n\n[requires.compiler.php]\nextension = phar\n");
    $installedMap = installedRequirements($installDir, 'roleful');
    $assert($installedMap === ['requires.compiler.php' => ['phar']], 'an installed package\'s requirement map is read');
    $assert(installedRequirements($installDir, 'absent') === null, 'a package with no descriptor answers null');

    // A compiler-role need the micro runtime lacks is blocking, like a program one.
    \putenv('MOGGI_MICRO_SFX=' . $sfxDir . '/micro.sfx');
    \file_put_contents($sfxDir . '/' . EXTENSION_MANIFEST, \json_encode(['runtime' => 'php-native', 'extensions' => ['standard', 'intl']]) . "\n");
    $compilerNeed = phpExtensionProblems(phpExtensionDescriptors(
        ['requirements' => ['requires.compiler.php' => ['redis']]],
        [],
    ));
    $assert($compilerNeed['blocking'] === ['redis'], 'a compiler-role extension the runtime lacks blocks a --native build');
    \putenv('MOGGI_MICRO_SFX');

    echo "extension requirement tests passed ({$checks} checks)\n";
} finally {
    $remove($work);
}
