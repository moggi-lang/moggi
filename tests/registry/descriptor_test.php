#!/usr/bin/env php
<?php declare(strict_types=1);

// The descriptor's metadata: the four `[package]` fields plan 3 adds, and the
// absence of the old `format` integer. `descriptorProblems()` is the one gate a
// descriptor passes, so every check goes through it — an accepted descriptor
// reads back through `readDescriptor()`, and a rejected one names the field.
//
// A descriptor carries no `format` of its own. One that writes it is refused as
// a key the closed schema does not declare, which is the sentence to keep, and
// not the old "unsupported descriptor format" one.

$root = __DIR__;
while (!is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use function Moggi\Registry\descriptorProblems;
use function Moggi\Registry\readDescriptor;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$work = \sys_get_temp_dir() . '/moggi-descriptor-' . \bin2hex(\random_bytes(6));
\mkdir($work, 0777, true);

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
    $npub = 'npub1yywlmj053p4qp7z2qvqsrgcwz488mw0pr0t50xz3e5rx7s9uaelsjpdmfm';
    $write = static function (string $name, string $packageBody = '', string $body = '') use ($work, $npub): string {
        $text = "[package]\nname = {$name}\nversion = 1.0.0\n{$packageBody}\n"
            . "[author]\nname = Test\nemail = test@example.com\nnpub = {$npub}\n\n{$body}";
        $path = $work . '/' . $name . '.moggi';
        \file_put_contents($path, $text);

        return $path;
    };
    $problems = static fn (string $path): string => \implode("\n", descriptorProblems($path));

    // --- no `format`, and a maintainer that defaults --------------------------
    $plain = $write('plain', '', "[lib]\nsource-dirs = .\n");
    $assert(descriptorProblems($plain) === [], 'a descriptor without `format` is valid: ' . $problems($plain));
    $read = readDescriptor($plain);
    $assert($read['homepage'] === null, 'an omitted homepage reads as null');
    $assert($read['repository'] === null, 'an omitted repository reads as null');
    $assert($read['keywords'] === [], 'omitted keywords read as an empty list');
    $assert($read['maintainer'] === 'test@example.com', 'the maintainer defaults to the first author email');

    // --- `format` is gone, and refused as an undeclared key -------------------
    $formatted = $write('formatted', "format = 1\n");
    $assert(\str_contains($problems($formatted), 'does not take `format`'), '`format` is refused as an undeclared key');
    $assert(!\str_contains($problems($formatted), 'unsupported descriptor format'), 'the removed format check no longer speaks');
    $threw = false;
    try {
        readDescriptor($formatted);
    } catch (\RuntimeException) {
        $threw = true;
    }
    $assert($threw, 'a descriptor that still writes `format` is refused by readDescriptor');

    // --- the metadata fields are accepted and read back -----------------------
    $meta = $write(
        'meta',
        "homepage = https://example.com/meta\n"
        . "repository = https://github.com/example/meta\n"
        . "keywords = json, parsing, serialization\n"
        . "maintainer = Meta Maintainers <meta@example.com>\n",
        "[lib]\nsource-dirs = .\n",
    );
    $assert(descriptorProblems($meta) === [], 'a descriptor with every metadata field is valid: ' . $problems($meta));
    $fields = readDescriptor($meta);
    $assert($fields['homepage'] === 'https://example.com/meta', 'homepage reads back');
    $assert($fields['repository'] === 'https://github.com/example/meta', 'repository reads back');
    $assert($fields['keywords'] === ['json', 'parsing', 'serialization'], 'keywords split on commas');
    $assert($fields['maintainer'] === 'Meta Maintainers <meta@example.com>', 'an explicit maintainer wins over the author email');

    // --- a malformed URL names the field -------------------------------------
    $badHome = $write('badhome', "homepage = example.com/meta\n");
    $assert(\str_contains($problems($badHome), '`homepage = example.com/meta` is not a URL'), 'a homepage without a scheme is refused: ' . $problems($badHome));

    $badRepo = $write('badrepo', "repository = git@github.com:example/meta.git\n");
    $assert(\str_contains($problems($badRepo), '`repository = git@github.com:example/meta.git` is not a URL'), 'a scp-style repository is refused');

    $badScheme = $write('badscheme', "homepage = file:///tmp/meta\n");
    $assert(\str_contains($problems($badScheme), 'is not a URL'), 'a non-http scheme is refused');

    // --- an empty value says nothing -----------------------------------------
    $emptyKeywords = $write('emptykeywords', "keywords =\n");
    $assert(\str_contains($problems($emptyKeywords), '`keywords =` is empty'), 'an empty keywords line is refused');

    $emptyMaintainer = $write('emptymaintainer', "maintainer =\n");
    $assert(\str_contains($problems($emptyMaintainer), '`maintainer =` is empty'), 'an empty maintainer line is refused');

    // --- keywords drop blanks and trim ---------------------------------------
    $spaced = $write('spaced', "keywords = json, , parsing ,\n");
    $assert(readDescriptor($spaced)['keywords'] === ['json', 'parsing'], 'blank keyword entries are dropped and the rest trimmed');

    // --- an author block without a key is credit, not a publisher ------------
    $credited = $write('credited', '', "[author.original]\nname = Ada Example\nemail = ada@example.com\n");
    $assert(descriptorProblems($credited) === [], 'a block that names no npub is credit, not an error: ' . $problems($credited));
    $read = readDescriptor($credited);
    $assert($read['authors'] === [$npub], 'only the keyed block gates the allowed list');
    $assert(\count($read['credits']) === 2, 'both blocks read back as credits');
    $assert($read['credits'][0]['npub'] === $npub, 'a keyed credit carries its npub');
    $assert($read['credits'][1] === ['name' => 'Ada Example', 'email' => 'ada@example.com', 'npub' => null, 'automation' => false], 'a keyless credit is a name and an address, and nothing else');
    $assert($read['credits'][0]['automation'] === false, 'a person is not automation');

    // --- a credit keeps the order the descriptor wrote ----------------------
    $ordered = $write('ordered', '', "[author.second]\nname = Second\nemail = second@example.com\n\n[author.first]\nname = First\nemail = first@example.com\n");
    $names = \array_column(readDescriptor($ordered)['credits'], 'name');
    $assert($names === ['Test', 'Second', 'First'], 'credits keep document order: ' . \implode(', ', $names));

    // --- automation still needs its key, and a package still needs one -------
    $labelled = $write('labelled', '', "[author.ci]\nname = Bot\nautomation = true\n");
    $assert(\str_contains($problems($labelled), '`automation = true` names no npub'), 'an automation block without a key is still refused: ' . $problems($labelled));

    $keyless = $work . '/keyless.moggi';
    \file_put_contents($keyless, "[package]\nname = keyless\nversion = 1.0.0\n\n[author]\nname = Ada\nemail = ada@example.com\n");
    $assert(\str_contains($problems($keyless), 'needs at least one block with an npub'), 'a package of credits alone is refused: ' . $problems($keyless));

    echo "descriptor metadata tests passed ({$checks} checks)\n";
} finally {
    $remove($work);
}
