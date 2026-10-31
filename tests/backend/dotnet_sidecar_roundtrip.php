<?php declare(strict_types=1);

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/workspace.php';

use function Moggi\Backend\DotNet\resolveDotnetExecutable;
use function Moggi\Backend\DotNet\Verify\ensureInspectorBinary;
use function Moggi\Backend\DotNet\Verify\inspectDotNetType;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');

$dotnet = resolveDotnetExecutable();
$assert($dotnet !== null, 'the round-trip needs `dotnet` the way step 6A does');
$inspector = ensureInspectorBinary($dotnet);
$assert($inspector !== null, 'the round-trip needs the inspector built');

$work = createTempDir('moggi-dotnet-roundtrip');

try {
    $intTable = inspectDotNetType($dotnet, $inspector, 'System.Int32');
    $mathTable = inspectDotNetType($dotnet, $inspector, 'System.Math');
    $dtoTable = inspectDotNetType($dotnet, $inspector, 'System.DateTimeOffset');
    $assert(($intTable['found'] ?? false) === true, 'the inspector sees System.Int32');
    $assert(($mathTable['found'] ?? false) === true, 'the inspector sees System.Math');
    $assert(($dtoTable['found'] ?? false) === true, 'the inspector sees System.DateTimeOffset');

    $parseParams = null;
    foreach ($intTable['methods']['Parse'] ?? [] as $overload) {
        $params = $overload['params'] ?? [];
        if (\count($params) === 2 && $params[0] === 'string'
            && \str_contains($params[1], 'IFormatProvider')) {
            $parseParams = $params;

            break;
        }
    }
    $assert($parseParams !== null, 'the inspector lists a (string, IFormatProvider) Parse');

    $maxParams = null;
    foreach ($mathTable['methods']['Max'] ?? [] as $overload) {
        $params = $overload['params'] ?? [];
        if ($params === ['int32', 'int32']) {
            $maxParams = $params;

            break;
        }
    }
    $assert($maxParams !== null, 'the inspector lists a (int32, int32) Max');

    $forwardedBy = $dtoTable['forwardedBy'] ?? [];
    $assert(
        \in_array('System.Runtime', $forwardedBy, true),
        'the inspector sees System.Runtime forward DateTimeOffset',
    );
    $dtoAssembly = \in_array('System.Runtime', $forwardedBy, true)
        ? 'System.Runtime'
        : ($dtoTable['assembly'] ?? '');
    $assert($dtoAssembly !== '', 'the generator names an assembly for the type');
    $assert(($dtoTable['valueType'] ?? false) === true, 'the inspector calls out the value type');

    $sidecarRoot = $work . '/gen-root';
    \mkdir($sidecarRoot . '/dotnet', 0777, true);
    $sidecar = [
        'format' => 1,
        'assemblies' => [
            $dtoAssembly => [
                'generated-by' => 'dotnet_sidecar_roundtrip_test',
                'types' => [
                    'System.DateTimeOffset' => ['valueType' => $dtoTable['valueType']],
                ],
                'signatures' => [
                    'maxInt' => ['optionalTail' => 0, 'params' => $maxParams],
                    'parseRt' => ['optionalTail' => 1, 'params' => $parseParams],
                ],
            ],
        ],
    ];
    \file_put_contents(
        $sidecarRoot . '/dotnet/generated.json',
        \json_encode($sidecar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
    $assert(
        \str_contains((string) \file_get_contents($sidecarRoot . '/dotnet/generated.json'), $dtoAssembly),
        'the sidecar carries the inspector-reported assembly, not a hand-written one',
    );
    \Moggi\Modules\addLibraryRoot($sidecarRoot);

    $probe = $work . '/ProbeRt.mog';
    \file_put_contents($probe, <<<'MOGGI'
        module ProbeRt

        import Data.Int (Int32)
        import Moggi.Internal.Prim

        foreign dotnet type Dto "System.DateTimeOffset"

        foreign dotnet const utcNow "System.DateTimeOffset:UtcNow" :: Dto

        foreign dotnet function maxInt "System.Math:Max" :: Int32 -> Int32 -> Int32

        foreign dotnet function parseRt "System.Int32:Parse" :: String -> IO Int32

        main :: IO ()
        main =
          let _dto = utcNow
              _m = maxInt (int32FromInt# 3) (int32FromInt# 4)
          in parseRt "12" >> putStrLn "done"
        MOGGI);

    $il = '';
    foreach (\Moggi\Compiler\compileFile($probe, 'php', true, null, 'dotnet') as $relative => $bytes) {
        if (\is_string($relative) && \is_string($bytes) && \str_ends_with($relative, '.il')) {
            $il .= "\n" . $bytes;
        }
    }
    $assert(
        \str_contains($il, 'System.Math::Max(int32, int32)'),
        'the generated exact entry emits the overload it names: ' . $il,
    );
    $assert(
        \str_contains($il, 'System.Int32::Parse(string,'),
        'the generated tailed entry emits the full call: ' . $il,
    );
    $assert(
        \str_contains($il, 'ldnull'),
        'the generated-but-unpassed tail is padded: ' . $il,
    );
    $assert(
        \str_contains($il, 'get_UtcNow()'),
        'the generated types entry scopes the property getter: ' . $il,
    );
} finally {
    removeDirectory($work);
}

echo "dotnet sidecar round-trip tests passed\n";
