<?php declare(strict_types=1);

namespace Moggi\LSP\TextDocument;

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\ensureAnalyzed;
use function Moggi\LSP\Protocol\codepointColToUtf16;
use function Moggi\LSP\Protocol\splitLines;
use function Moggi\LSP\Protocol\utf16Length;

function svcDocumentColors(AnalysisService $svc, string $uri): array
{
    $analysis = $svc->ensureAnalyzed($uri);
    if ($analysis === null) {
        return [];
    }
    $colors = [];
    $source = $analysis->source;
    $lines = splitLines($source);
    foreach ($lines as $i => $line) {
        if (preg_match_all('/#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3}|rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)/i', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $colorStr = $match[0];
                // preg offsets are bytes; LSP character offsets are UTF-16 units.
                $start = codepointColToUtf16($line, $match[1] + 1);
                $end = $start + utf16Length($colorStr);
                $colors[] = [
                    'range' => [
                        'start' => ['line' => $i, 'character' => $start],
                        'end' => ['line' => $i, 'character' => $end],
                    ],
                    'color' => parseColor($colorStr),
                ];
            }
        }
    }
    return $colors;
}

function parseColor(string $colorStr): array
{
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $colorStr, $m)) {
        $hex = $m[1];
        return [
            'red' => hexdec(substr($hex, 0, 2)) / 255,
            'green' => hexdec(substr($hex, 2, 2)) / 255,
            'blue' => hexdec(substr($hex, 4, 2)) / 255,
            'alpha' => 1.0,
        ];
    }
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $colorStr, $m)) {
        $hex = $m[1];
        return [
            'red' => hexdec(substr($hex, 0, 1) . substr($hex, 0, 1)) / 255,
            'green' => hexdec(substr($hex, 1, 1) . substr($hex, 1, 1)) / 255,
            'blue' => hexdec(substr($hex, 2, 1) . substr($hex, 2, 1)) / 255,
            'alpha' => 1.0,
        ];
    }
    if (preg_match('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i', $colorStr, $m)) {
        return [
            'red' => max(0, min(1, (int) $m[1] / 255)),
            'green' => max(0, min(1, (int) $m[2] / 255)),
            'blue' => max(0, min(1, (int) $m[3] / 255)),
            'alpha' => 1.0,
        ];
    }
    return ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 1];
}

function svcColorPresentations(AnalysisService $svc, string $uri, array $color, array $range, array $context): array
{
    $r = (int) round(($color['red'] ?? 0) * 255);
    $g = (int) round(($color['green'] ?? 0) * 255);
    $b = (int) round(($color['blue'] ?? 0) * 255);
    $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
    return [
        [
            'label' => $hex,
            'textEdit' => [
                'range' => $range,
                'newText' => $hex,
            ],
        ],
    ];
}
