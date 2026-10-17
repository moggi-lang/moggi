<?php declare(strict_types=1);

namespace Moggi\Semantics\Effects;

use Moggi\Pipeline\CompilePurpose;

use function Moggi\Semantics\IoBoundary\validate as validateIo;
use function Moggi\Semantics\StBoundary\validate as validateSt;

use function Moggi\Semantics\IoEscape\analyze;
use function Moggi\Semantics\StrictIoNormalize\normalize;
use function Moggi\Semantics\Types\checkRaw;

use Moggi\Syntax\Ast\Program;

/**
 * Semantic effect elaboration boundary.
 *
 * This is the single entry point from raw, parsed AST into the typed AST dialect
 * expected by IR lowering: type checking first, IO and ST surface validation
 * second, strict IO normalization last. ST needs no normalization: an ST
 * action is a re-executable description, so building one is pure and each use
 * runs it, which is what keeps allocation and read/write order.
 *
 * @param array<string, mixed> $importContext
 * @return Program
 */
/**
 * @param array<string, mixed> $importContext
 * @param ?array{env: array<string, mixed>, data: array<string, mixed>, typeSynonyms: array<string, mixed>, intrinsicWrappers: array<string, string>} $interface
 *   receives the local type environment the check commits, so a caller can
 *   publish it as the module interface instead of building one separately
 */
function checkAndNormalize(
    Program $program,
    string $source = '',
    string $filename = '',
    array $importContext = [],
    CompilePurpose $purpose = CompilePurpose::Executable,
    ?array &$interface = null,
): Program {
    $typed = checkRaw($program, $source, $filename, $importContext, $purpose, $interface);
    validateIo($typed, $source, $filename);
    validateSt($typed, $source, $filename);

    return analyze(normalize($typed));
}
