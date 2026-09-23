<?php declare(strict_types=1);

namespace Moggi\Docs;

/** Bundle client assets into moogle.js for static mogdoc output. */
function emitMoogleJs(): string
{
    $search = readClientAsset('moogle.client.js');
    $init = readClientAsset('mogdoc-search.client.js');

    return $search . "\n" . $init;
}

function emitMoogleSearchJs(): string
{
    return readClientAsset('moogle.client.js');
}

function readClientAsset(string $filename): string
{
    $path = __DIR__ . '/' . $filename;
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new \RuntimeException("cannot read docs client asset {$filename}");
    }

    return $contents;
}
