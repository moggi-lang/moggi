<?php declare(strict_types=1);

use function Moggi\Errors\diagnosticCode;
use function Moggi\Errors\diagnosticRegistry;
use function Moggi\Errors\diagnosticSpan;

/**
 * Check the diagnostic *contract* (registry code + source span), which editor tooling branches on and
 * which must stay stable while messages get reworded. Run wherever an error fixture is compared.
 *
 * @return ?string a failure message, or null when the diagnostic is well-formed
 */
function diagnosticShapeFailure(\Throwable $error): ?string
{
    $code = diagnosticCode($error);
    if ($code === null) {
        return 'not a compiler diagnostic: ' . get_class($error) . ': ' . $error->getMessage();
    }
    if (!array_key_exists($code, diagnosticRegistry())) {
        return "diagnostic code `{$code}` is not in the registry (src/errors.php) — add it there first";
    }

    $span = diagnosticSpan($error);
    if ($span === null) {
        return "diagnostic `{$code}` carries no source span: {$error->getMessage()}";
    }
    if ($span['endCol'] < $span['col']) {
        return "diagnostic `{$code}` has an inverted span ({$span['col']}…{$span['endCol']})";
    }

    return null;
}
