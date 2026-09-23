<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm;

use Moggi\Backend\Backend;
use Moggi\IR\Module;
use Moggi\Syntax\Ast\TypeNode;

use function Moggi\Backend\Inspect\describeJvmEmit;
use function Moggi\Backend\Jvm\Codegen\emitModule;
use function Moggi\Backend\Jvm\Foreign\jvmDescriptorFromMoggiType;
use function Moggi\Backend\Jvm\Naming\artifactPath;
use function Moggi\Backend\Jvm\Naming\symbolName;
use function Moggi\Debug\mapPathForArtifact;

require_once __DIR__ . '/runtime_abi.php';
require_once __DIR__ . '/package.php';
require_once __DIR__ . '/codegen.php';
require_once __DIR__ . '/frames.php';
require_once __DIR__ . '/naming.php';
require_once __DIR__ . '/foreign.php';
require_once __DIR__ . '/dependencies.php';

final class JvmBackend implements Backend
{
    public function extension(): string
    {
        return '.class';
    }

    public function artifactPath(string $moduleName): ?string
    {
        return artifactPath($moduleName);
    }

    public function symbolName(string $name): string
    {
        return symbolName($name);
    }

    /** @return list<string> */
    public function runtimeFiles(): array
    {
        return []; // generated into the jar via packageOutput
    }

    public function emit(Module $ir, string $sourcePath, array $options = []): string|array
    {
        $emitted = emitModule($ir, $sourcePath, $options);
        $out = [];
        foreach ($emitted as $key => $bytes) {
            if ($key === '__moggi.map__') {
                $artifact = (string) ($options['outputRelative'] ?? 'Module.class');
                $out[mapPathForArtifact($artifact)] = $bytes;
                continue;
            }
            $out[$key . '.class'] = $bytes;
        }

        return $out;
    }

    public function describeEmit(string|array $emit, array $focusNames = []): string
    {
        return describeJvmEmit($emit, $focusNames);
    }

    public function packageOutput(string $outputRoot, array $options = []): void
    {
        packageJvmOutput($outputRoot, $options);
    }

    public function foreignNativeSig(
        TypeNode $type,
        string $kind,
        array $pathInfo,
        string $name,
    ): ?string {
        return jvmDescriptorFromMoggiType(
            $type,
            $pathInfo['dispatch'],
            $kind,
            $pathInfo['classPath'],
            $pathInfo['member'],
        );
    }

    public function validateForeignImport(
        string $kind,
        array $pathInfo,
        bool $instanceReceiverIsHandle,
    ): ?string {
        if ($pathInfo['dispatch'] === 'global') {
            return 'JVM foreign path must be `Class:member` or `Class.member`; bare host names are not supported';
        }

        return null;
    }
}
