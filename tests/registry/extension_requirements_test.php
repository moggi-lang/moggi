#!/usr/bin/env php
<?php declare(strict_types=1);

// A runtime requirement a registry cannot fetch, so the resolver gathers it
// across a closure and answers which runtime provides it. These checks cover the
// `[php.extensions]` table and the entry syntax, the merge across the root and
// installed packages, and the two runtimes: the PHP running this and the micro
// runtime a `--native` build appends to. The compiler is a native executable, so
// there is one requirement set and no role axis.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use const Moggi\Registry\EXTENSION_MANIFEST;

use function Moggi\Registry\collectPhpExtensions;
use function Moggi\Registry\descriptorProblems;
use function Moggi\Registry\installDir;
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
    $assert($plain['name'] === 'redis' && $plain['path'] === null, 'a bare entry is a name');

    $assert(parsePhpExtensionEntry('intl^1.2')['name'] === 'intl', 'a `^` constraint is dropped from the name');
    $assert(parsePhpExtensionEntry('intl<3')['name'] === 'intl', 'a `<` constraint is dropped from the name');
    $assert(parsePhpExtensionEntry('intl>=8.0')['name'] === 'intl', 'a `>=` constraint is dropped from the name');
    $assert(parsePhpExtensionEntry('intl>=8.0')['path'] === null, 'a `>=` constraint is not mistaken for a path');

    $pathed = parsePhpExtensionEntry('imagick=ext/imagick.so');
    $assert($pathed['name'] === 'imagick' && $pathed['path'] === 'ext/imagick.so', 'a `=` entry names a path');

    // --- the table is the whole requirement section set ----------------------
    $sections = requirementSections(\parse_ini_string(
        "[php.extensions]\nbcmath = *\n\n[jvm.maven]\ncom.acme:lib = 1.0\n",
        true,
        \INI_SCANNER_RAW,
    ));
    $assert($sections === ['php.extensions' => ['bcmath']], 'only php extensions are requirement sections: ' . \json_encode($sections));

    // --- the merge across the closure ---------------------------------------
    $collected = collectPhpExtensions([
        'root' => ['requirements' => ['php.extensions' => ['bcmath', 'redis']]],
        'demo' => ['requirements' => ['php.extensions' => ['redis', 'intl']]],
    ]);
    $assert(\array_keys($collected) === ['bcmath', 'intl', 'redis'], 'the merged set is keyed case-folded and sorted');
    $assert($collected['redis']['sources'] === ['root', 'demo'], 'every label that asked is recorded');
    $assert($collected['bcmath']['sources'] === ['root'], 'a single source is recorded once');

    // --- comparing against a runtime ----------------------------------------
    $requirements = collectPhpExtensions(['root' => ['requirements' => ['php.extensions' => ['Redis', 'intl']]]]);
    $assert(unmetPhpExtensions($requirements, ['core', 'redis', 'INTL']) === [], 'a case-folded match is a match');

    $missing = unmetPhpExtensions($requirements, ['core']);
    $assert($missing === ['intl', 'Redis'], 'only unmet names are reported, in the descriptor spelling: ' . \implode(', ', $missing));

    $running = phpRuntimeExtensions();
    $assert(\in_array('core', $running, true), 'the running PHP always reports its core');

    // --- an installed package's descriptor ----------------------------------
    $digest = 'sha256:' . \str_repeat('a', 64);
    $install = installDir('demo', $digest);
    if (!\is_dir($install) && !\mkdir($install, 0777, true) && !\is_dir($install)) {
        throw new \RuntimeException("cannot create {$install}");
    }
    \file_put_contents($install . '/demo.moggi', "[package]\nname = demo\nversion = 1.0.0\n\n[php.extensions]\nredis = *\ngd = *\n");

    $installedMap = installedRequirements($install, 'demo');
    $assert($installedMap === ['php.extensions' => ['redis', 'gd']], 'an installed package\'s requirement map is read');
    $assert(installedRequirements($install, 'absent') === null, 'a package with no descriptor answers null');

    $descriptors = phpExtensionDescriptors(
        ['requirements' => ['php.extensions' => ['bcmath']]],
        ['packages' => ['demo' => ['version' => '1.0.0', 'digest' => $digest]]],
    );
    $assert(\array_keys($descriptors) === ['root', 'demo'], 'the closure is the root plus every installed package');
    $assert($descriptors['demo']['requirements'] === ['php.extensions' => ['redis', 'gd']], 'the installed package contributes its own entries');

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
    $rootDescriptor = ['backends' => ['php'], 'requirements' => ['php.extensions' => ['standard', 'redis']]];
    $withManifest = phpExtensionProblems(phpExtensionDescriptors($rootDescriptor, []));
    $assert($withManifest['blocking'] === ['redis'], 'an extension the micro runtime lacks is blocking: ' . \json_encode($withManifest));
    $assert(\count($withManifest['problems']) === 2, 'the missing extension is reported against both the running PHP and the micro runtime');
    $assert(\str_contains($withManifest['problems'][0], 'the PHP running this'), 'the first problem names the running PHP');
    $assert(\str_contains($withManifest['problems'][1], 'the runtime a --native build uses'), 'the second problem names the native runtime');
    $assert(!\str_contains(\implode("\n", $withManifest['problems']), 'micro.sfx'), 'no message names the runtime mechanism');

    \unlink($sfxDir . '/' . EXTENSION_MANIFEST);
    $unknown = phpExtensionProblems(phpExtensionDescriptors($rootDescriptor, []));
    $assert($unknown['blocking'] === [], 'a runtime that records nothing blocks nothing');
    $assert(\count(\array_filter($unknown['problems'], static fn (string $p): bool => \str_contains($p, 'cannot be verified'))) === 1, 'a runtime that records nothing is reported as unverifiable');

    \putenv('MOGGI_MICRO_SFX');

    // --- the `[php.extensions]` table ---------------------------------------
    $npub = 'npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm';
    $descriptorFor = static function (string $name, string $body) use ($npub): string {
        return "[package]\nname = {$name}\nversion = 1.0.0\n\n[author]\nname = Test\nnpub = {$npub}\n\n{$body}";
    };
    $writeDescriptor = static function (string $name, string $body) use ($work, $descriptorFor): string {
        $path = $work . '/' . $name . '.moggi';
        \file_put_contents($path, $descriptorFor($name, $body));

        return $path;
    };

    $path = $writeDescriptor('extensionful', "[lib]\nsource-dirs = .\n\n[php.extensions]\nintl = *\nbcmath = *\n");
    $assert(descriptorProblems($path) === [], 'a `[php.extensions]` table is valid: ' . \implode('; ', descriptorProblems($path)));
    $read = readDescriptor($path);
    $assert($read['requirements'] === ['php.extensions' => ['intl', 'bcmath']], 'the table is read as its entries');
    $assert($read['php']['extensions'] === ['intl', 'bcmath'], 'the extensions are exposed where a checker reads them');

    // A constraint and a path survive the table, and the `=` stays a path.
    $shaped = $writeDescriptor('shaped', "[php.extensions]\nintl = ^8.0\nlibcurl = /usr/lib/libcurl.so\n");
    $shapedRead = readDescriptor($shaped);
    $assert($shapedRead['requirements']['php.extensions'] === ['intl^8.0', 'libcurl=/usr/lib/libcurl.so'], 'a constraint and a path are kept: ' . \implode(', ', $shapedRead['requirements']['php.extensions']));
    $assert(parsePhpExtensionEntry($shapedRead['requirements']['php.extensions'][1])['path'] === '/usr/lib/libcurl.so', 'the path half is read as a path');

    // --- the host-tool tables ------------------------------------------------
    $jvm = $writeDescriptor('jvmful', "[jvm]\nversion = 21\n\n[jvm.maven]\ncom.fasterxml.jackson.core:jackson-core = 2.17.3\n");
    $assert(descriptorProblems($jvm) === [], 'a `[jvm.maven]` table is valid: ' . \implode('; ', descriptorProblems($jvm)));
    $jvmRead = readDescriptor($jvm);
    $assert($jvmRead['jvm']['version'] === '21', 'the jvm runtime floor is read');
    $assert($jvmRead['jvm']['maven'] === ['com.fasterxml.jackson.core:jackson-core:2.17.3'], 'a maven coordinate is read from its table');

    $dotnet = $writeDescriptor('dotnetful', "[dotnet]\nversion = 8\n\n[dotnet.nuget]\nSystem.Text.Json = 8.0.0\n");
    $dotnetRead = readDescriptor($dotnet);
    $assert($dotnetRead['dotnet']['version'] === '8', 'the dotnet runtime floor is read');
    $assert($dotnetRead['dotnet']['nuget'] === ['System.Text.Json:8.0.0'], 'a nuget coordinate is read from its table');

    // --- the old shapes are gone --------------------------------------------
    $roleSection = $writeDescriptor('rolesection', "[lib]\nsource-dirs = .\n\n[requires.program.php]\nextension = intl\n");
    $assert(\str_contains(\implode("\n", descriptorProblems($roleSection)), 'unknown section [requires.program.php]'), 'the old role section is no longer valid');

    $legacyKey = $writeDescriptor('legacykey', "[lib]\nsource-dirs = .\n\n[php]\nextension = intl\n");
    $assert(\str_contains(\implode("\n", descriptorProblems($legacyKey)), 'does not take `extension`'), 'the old `[php] extension` key is no longer valid');

    $badEntry = $writeDescriptor('badentry', "[php.extensions]\n9lives = *\n");
    $assert(\str_contains(\implode("\n", descriptorProblems($badEntry)), 'is not an extension name'), 'a malformed extension name is refused at the descriptor');

    echo "extension requirement tests passed ({$checks} checks)\n";
} finally {
    $remove($work);
}
