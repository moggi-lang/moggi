<?php declare(strict_types=1);

namespace Moggi\Semantics\Effects;

use Moggi\Modules\ImportContext;
use function Moggi\Modules\publishElaboration;
use Moggi\Pipeline\CompilePurpose;
use Moggi\Syntax\Ast\Program;

use function Moggi\Semantics\IoBoundary\validate as validateIo;
use function Moggi\Semantics\StBoundary\validate as validateSt;
use function Moggi\Semantics\IoEscape\analyze;
use function Moggi\Semantics\StrictIoNormalize\normalize;
use function Moggi\Semantics\Types\checkRaw;

/**
 * Semantic effect elaboration boundary.
 *
 * This is the single entry point from raw, parsed AST into the typed AST dialect
 * expected by IR lowering: type checking first, IO and ST surface validation
 * second, strict IO normalization last. ST needs no normalization: an ST
 * action is a re-executable description, so building one is pure and each use
 * runs it, which is what keeps allocation and read/write order.
 *
 * @return Program
 */
/**
 * @param ?ImportContext $importContext null when the module imports nothing
 * @param ?array{env: array<string, mixed>, data: array<string, mixed>, typeSynonyms: array<string, mixed>, intrinsicWrappers: array<string, string>} $interface
 *   receives the local type environment the check commits, so a caller can
 *   publish it as the module interface instead of building one separately
 */
/**
 * Whether a check publishes the elaboration of the tree it just checked.
 *
 * The elaboration exists for two readers: lowering, which needs every term's structure and origin,
 * and the module cache, which stores the checked product for a later compilation to load. A
 * documentation pass reads neither -- it pages from the parsed and checked trees -- so it turns
 * publication off and skips building term tables nothing will read. Turning it off is only sound
 * when the checked product is not written to the cache, which is the caller's decision to make.
 */
final class ElaborationPublishing
{
    public static bool $enabled = true;
}

function checkAndNormalize(
    Program $program,
    string $source = '',
    string $filename = '',
    ?ImportContext $importContext = null,
    CompilePurpose $purpose = CompilePurpose::Executable,
    ?array &$interface = null,
    array $entryModules = [],
    string $backendId = 'php',
): Program {
    $state = null;
    $typed = checkRaw(
        $program,
        $source,
        $filename,
        $importContext,
        $purpose,
        $interface,
        $entryModules,
        $backendId,
        $state,
    );
    validateIo($typed, $source, $filename);
    validateSt($typed, $source, $filename);

    $final = analyze(normalize($typed));
    if (ElaborationPublishing::$enabled) {
        publishElaboration($final, $state);
    }

    return $final;
}
