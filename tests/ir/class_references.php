<?php declare(strict_types=1);

/**
 * Every class an `instanceof` names must be a class this tree declares.
 *
 * `instanceof` with a name that resolves to nothing is not an error in PHP: it is a silent `false`.
 * That cost this repository a real defect — an arm that tested `instanceof CheckedVariable` without
 * importing the class answered false for every site, so a family's whole producer looked empty and
 * lowering refused work that was correctly planned. A type error would have caught it at first use;
 * this check is the type error, for the one operator that does not raise.
 *
 * The scan is static and needs no autoloader: it collects every class, interface, trait and enum the
 * tree declares (with its namespace), then resolves each `instanceof` operand through its file's
 * `use` aliases and namespace. A name that is not declared anywhere fails the case and is printed
 * with the file and line that used it.
 */

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$files = [];
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
\sort($files);
$assert($files !== [], 'no PHP sources under src/');

$declared = [];
$parsed = [];
foreach ($files as $path) {
    $body = (string) \file_get_contents($path);
    $namespace = '';
    if (\preg_match('/^namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m', $body, $match) === 1) {
        $namespace = $match[1];
    }

    $aliases = [];
    if (\preg_match_all('/^use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/m', $body, $uses, \PREG_SET_ORDER) > 0) {
        foreach ($uses as $use) {
            $qualified = $use[1];
            $alias = ($use[2] ?? '') !== ''
                ? $use[2]
                : (\str_contains($qualified, '\\')
                    ? \substr($qualified, (int) \strrpos($qualified, '\\') + 1)
                    : $qualified);
            $aliases[$alias] = $qualified;
        }
    }

    if (\preg_match_all('/\b(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $body, $types) > 0) {
        foreach ($types[1] as $name) {
            $declared[($namespace === '' ? '' : $namespace . '\\') . $name] = true;
        }
    }

    $parsed[] = ['path' => $path, 'body' => $body, 'namespace' => $namespace, 'aliases' => $aliases];
}

$assert(\count($declared) > 100, 'the declaration scan found only ' . \count($declared) . ' classes, so it is not reading the tree');

$unresolved = [];
$checked = 0;
foreach ($parsed as $file) {
    if (\preg_match_all('/\binstanceof\s+(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/', $file['body'], $matches, \PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }
    foreach ($matches[1] as [$name]) {
        if (\in_array(\strtolower($name), ['self', 'static', 'parent'], true)) {
            continue;
        }
        ++$checked;
        $resolved = $name;
        if (\str_starts_with($resolved, '\\')) {
            $resolved = \substr($resolved, 1);
        } else {
            $parts = \explode('\\', $resolved);
            $head = $parts[0];
            if (isset($file['aliases'][$head])) {
                \array_shift($parts);
                $resolved = $file['aliases'][$head] . ($parts === [] ? '' : '\\' . \implode('\\', $parts));
            } elseif ($file['namespace'] !== '') {
                $resolved = $file['namespace'] . '\\' . $resolved;
            }
        }

        // A built-in PHP type (`\stdClass`) or an already-loaded vendor class is a real class even
        // though no file under `src/` declares it; only a name that is neither is the silent-false case.
        if (! isset($declared[$resolved])
            && ! \class_exists($resolved, false)
            && ! \interface_exists($resolved, false)
            && ! \enum_exists($resolved, false)) {
            $unresolved[\str_replace($root . '/', '', $file['path']) . ' → ' . $name] = true;
        }
    }
}

$assert($checked > 100, 'the scan resolved only ' . $checked . ' `instanceof` operands, so it is not reading the code');
$assert(
    $unresolved === [],
    "an `instanceof` names a class this tree does not declare — PHP answers false instead of raising:\n  "
        . \implode("\n  ", \array_keys($unresolved)),
);

echo 'class reference tests passed (' . $checked . ' instanceof operands, ' . \count($declared) . " declared types)\n";
