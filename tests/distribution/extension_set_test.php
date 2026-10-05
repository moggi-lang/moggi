#!/usr/bin/env php
<?php declare(strict_types=1);

// The extension set a distribution's bundled PHP is built with is derived from
// the packages it ships, not typed beside the runtime's version. These checks
// cover the derivation (base.moggi's two roles), the role separation, and the
// two refusals: a pinned list that has drifted from what the packages require,
// and a runtime that does not carry a declared name.

$root = __DIR__;
while (!is_file($root . '/packaging/runtimes.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/packaging/runtimes.php';

use function Moggi\Dist\assertPhpRuntimeExtensions;
use function Moggi\Dist\assertRuntimeExtensionsDeclared;
use function Moggi\Dist\distributionExtensionRoles;
use function Moggi\Dist\loadRuntimeConfig;
use function Moggi\Dist\missingPhpExtensions;
use function Moggi\Dist\requiredPhpExtensions;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$roles = distributionExtensionRoles();

$assert(
    $roles['program'] === ['bcmath', 'intl', 'mbstring'],
    'the program role is what a compiled program needs: ' . \implode(', ', $roles['program']),
);
$assert(
    $roles['compiler'] === ['ctype', 'mbstring', 'phar', 'zip'],
    'the compiler role is what running the compiler needs: ' . \implode(', ', $roles['compiler']),
);

$assert(
    \in_array('bcmath', $roles['program'], true) && !\in_array('bcmath', $roles['compiler'], true),
    'a name only the program role declares must not appear in the compiler role',
);
$assert(
    \in_array('phar', $roles['compiler'], true) && !\in_array('phar', $roles['program'], true),
    'a name only the compiler role declares must not appear in the program role',
);
$assert(
    \in_array('mbstring', $roles['program'], true) && \in_array('mbstring', $roles['compiler'], true),
    'a name both roles need is in both',
);

$declaredUnion = \array_values(\array_unique([...$roles['program'], ...$roles['compiler']]));
\sort($declaredUnion, \SORT_STRING);
$derived = requiredPhpExtensions();
$assert(
    $derived === $declaredUnion,
    'the runtime set is the union of both roles: ' . \implode(', ', $derived),
);
$assert(
    $derived === ['bcmath', 'ctype', 'intl', 'mbstring', 'phar', 'zip'],
    'the derived set is base.moggi\'s declared union: ' . \implode(', ', $derived),
);

$config = loadRuntimeConfig();
assertRuntimeExtensionsDeclared($config);
$assert(true, 'the pinned extension list must match the derived set');

$drifted = $config;
$drifted['runtimes']['php']['extensions'] = ['bcmath', 'mbstring'];
try {
    assertRuntimeExtensionsDeclared($drifted);
    $assert(false, 'a pinned list that has drifted must be refused');
} catch (\RuntimeException $error) {
    $assert(\str_contains($error->getMessage(), 'drifted'), 'the drift refusal names the cause: ' . $error->getMessage());
    $assert(\str_contains($error->getMessage(), 'intl'), 'the drift refusal names what is missing: ' . $error->getMessage());
}

$assert(
    missingPhpExtensions(['bcmath', 'intl', 'mbstring'], ['bcmath', 'INTL', 'mbstring']) === [],
    'a runtime carrying every required name is complete, case-insensitively',
);
$assert(
    missingPhpExtensions(['bcmath', 'intl'], ['bcmath']) === ['intl'],
    'only the names a runtime lacks are reported, in the requirement spelling',
);

try {
    assertPhpRuntimeExtensions(['bcmath', 'intl'], ['bcmath'], ['bcmath'], 'linux-x86_64');
    $assert(false, 'a runtime missing a declared name must be refused');
} catch (\RuntimeException $error) {
    $assert(
        \str_contains($error->getMessage(), 'intl') && \str_contains($error->getMessage(), 'linux-x86_64'),
        'the runtime refusal names the missing extension and the target: ' . $error->getMessage(),
    );
}

assertPhpRuntimeExtensions(['bcmath', 'intl'], ['bcmath', 'intl'], ['bcmath', 'intl'], 'linux-x86_64');
$assert(true, 'a runtime carrying every required name is accepted');

\fwrite(STDOUT, "distribution extension set: {$checks} checks passed\n");
