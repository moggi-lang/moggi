<?php declare(strict_types=1);

final class TestFailure extends \RuntimeException
{
}

function normalize(string $text): string
{
    $text = \str_replace("\r\n", "\n", $text);

    return rtrim($text, "\n") . "\n";
}

function readExpected(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new TestFailure("missing expected file {$path}");
    }

    return normalize($contents);
}

function assertSameOutput(string $name, string $expectedPath, string $actual, string $label): void
{
    if (!\is_file($expectedPath)) {
        throw new TestFailure("{$label}: missing expected file {$expectedPath}");
    }

    $expected = readExpected($expectedPath);
    $actual = normalize($actual);

    if ($expected !== $actual) {
        throw new TestFailure("{$label}: output mismatch for {$name}\n--- expected {$expectedPath}\n+++ actual\n"
            . diffText($expected, $actual));
    }
}

function diffText(string $expected, string $actual): string
{
    $expectedLines = explode("\n", rtrim($expected, "\n"));
    $actualLines = explode("\n", rtrim($actual, "\n"));
    $out = '';
    $max = max(count($expectedLines), count($actualLines));

    for ($i = 0; $i < $max; ++$i) {
        $e = $expectedLines[$i] ?? null;
        $a = $actualLines[$i] ?? null;
        if ($e === $a) {
            continue;
        }

        if ($e !== null) {
            $out .= "- {$e}\n";
        }

        if ($a !== null) {
            $out .= "+ {$a}\n";
        }
    }

    return $out === '' ? "(no line diff)\n" : $out;
}

function testRelativePath(string $path): string
{
    $root = realpath(MOGGI_PROJECT_ROOT);
    $real = realpath($path);
    if ($root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        return \str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
    }

    return \str_replace(DIRECTORY_SEPARATOR, '/', $path);
}

function fixtureBasePath(string $path): string
{
    return substr($path, 0, -strlen('.mog'));
}
