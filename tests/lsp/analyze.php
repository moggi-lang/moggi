<?php declare(strict_types=1);

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';

use Moggi\LSP\Analysis\AnalysisService;

use function Moggi\LSP\Analysis\analyze;
use function Moggi\LSP\Protocol\pathToUri;
use function Moggi\LSP\TextDocument\svcCodeActions;
use function Moggi\LSP\TextDocument\svcCompletion;
use function Moggi\LSP\TextDocument\svcHover;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};

$fixturesDir = $root . '/tests/lsp';

$basicPath = $fixturesDir . '/Basic.mog';
$basicUri = pathToUri($basicPath);
$basicSrc = (string) \file_get_contents($basicPath);

$svc = new AnalysisService();
$svc->setWorkspaceRoot($fixturesDir);
$svc->openDocument($basicUri, $basicSrc, 1);
$basic = $svc->ensureAnalyzed($basicUri);
$assert($basic !== null, 'Basic.mog analyzed');
$assert(
    $basic->diagnostics === [],
    'Basic.mog has no diagnostics: ' . \json_encode($basic->diagnostics),
);
$assert(\count($basic->declarations) >= 40, 'Basic.mog declares many symbols');
$assert(
    ($basic->declarations['bump']['type'] ?? '') === 'Num a => a -> a',
    'bump :: Num a => a -> a: ' . \json_encode($basic->declarations['bump'] ?? null),
);
$assert(
    \str_contains((string) ($basic->declarations['identity']['type'] ?? ''), 'a'),
    'identity is polymorphic',
);
$assert(
    \str_contains((string) ($basic->declarations['documentedFunction']['doc'] ?? ''), 'Documented function'),
    'mogdoc attached to declaration',
);

$diagPath = $fixturesDir . '/Diagnostics.mog';
$diagUri = pathToUri($diagPath);
$diagSrc = (string) \file_get_contents($diagPath);

$diagSvc = new AnalysisService();
$diagSvc->setWorkspaceRoot($fixturesDir);
$diagSvc->openDocument($diagUri, $diagSrc, 1);
$diagResult = $diagSvc->ensureAnalyzed($diagUri);
$assert($diagResult !== null, 'Diagnostics.mog analyzed');
$assert($diagResult->diagnostics !== [], 'Diagnostics.mog has diagnostics');
$registry = \Moggi\Errors\diagnosticRegistry();
foreach ($diagResult->diagnostics as $diag) {
    $code = (string) ($diag['code'] ?? '');
    $assert(
        \array_key_exists($code, $registry),
        "diagnostic code `{$code}` must be in the registry: " . \json_encode($diag),
    );
}
$typeErrors = \array_values(\array_filter(
    $diagResult->diagnostics,
    static fn (array $d): bool => ($d['code'] ?? '') === 'unify',
));
$assert($typeErrors !== [], 'unify error present: ' . \json_encode($diagResult->diagnostics));
$msg = (string) $typeErrors[0]['message'];
$assert(
    \str_contains($msg, 'Int') && \str_contains($msg, 'String'),
    "type error mentions Int and String: {$msg}",
);
$wrongReturnLine = null;
foreach (\explode("\n", $diagSrc) as $i => $line) {
    if (\str_contains($line, 'wrongReturn = "hello"')) {
        $wrongReturnLine = $i;

        break;
    }
}
$assert($wrongReturnLine !== null, 'found wrongReturn line');
$assert(
    (int) ($typeErrors[0]['range']['start']['line'] ?? -1) === $wrongReturnLine,
    'unify error anchored at wrongReturn: ' . \json_encode($typeErrors[0]['range'] ?? null),
);
$assert(
    (int) ($typeErrors[0]['range']['end']['character'] ?? 0) > (int) ($typeErrors[0]['range']['start']['character'] ?? 0),
    'a diagnostic span covers the offending expression: ' . \json_encode($typeErrors[0]['range'] ?? null),
);

$tmp = \sys_get_temp_dir() . '/moggi-lsp-analyze-' . \bin2hex(\random_bytes(4));
$assert(\mkdir($tmp), 'create temp dir');
$path = $tmp . '/Main.mog';
$original = "module Main where\n\nmain :: IO ()\nmain = pure ()\n";
$assert(\file_put_contents($path, $original) !== false, 'write original');

$uri = pathToUri($path);
$unsaved = <<<'MOG'
module Main where

-- | Add one.
inc :: Int -> Int
inc x = x + 1

main :: IO ()
main = pure ()
MOG;

$result = analyze($uri, $unsaved, []);
$assert($result->diagnostics === [], 'good file has no diagnostics: ' . \json_encode($result->diagnostics));
$assert(isset($result->declarations['inc']), 'declares inc');
$assert(isset($result->declarations['main']), 'declares main');
$assert(! isset($result->declarations['x']), 'locals must not be top-level decls');
$assert(! isset($result->declarations['pure']), 'calls must not be top-level decls');
$assert(\str_contains((string) ($result->declarations['inc']['type'] ?? ''), 'Int'), 'inc type mentions Int');
$assert(($result->declarations['inc']['doc'] ?? '') !== '', 'inc has haddock doc');

$after = \file_get_contents($path);
$assert($after === $original, 'overlay restored original file bytes');

$bad = analyze($uri, "module Main where\n\nmain = ???\n", []);
$assert($bad->diagnostics !== [], 'parse error yields diagnostics');
$assert(
    \in_array($bad->diagnostics[0]['code'] ?? '', ['parse', 'lex'], true),
    'lex/parse error code: ' . \json_encode($bad->diagnostics),
);
$assert(\file_get_contents($path) === $original, 'overlay restored after parse error');
$holeSrc = <<<'MOG'
module TypedHole where

needInt :: Int
needInt = _
MOG;
$holePath = $tmp . '/TypedHole.mog';
\file_put_contents($holePath, $holeSrc);
$holeUri = pathToUri($holePath);
$holeSvc = new AnalysisService();
$holeSvc->setWorkspaceRoot($tmp);
$holeSvc->openDocument($holeUri, $holeSrc, 1);
$holeResult = $holeSvc->ensureAnalyzed($holeUri);
$assert($holeResult !== null, 'hole module analyzed');
$holeDiag = null;
foreach ($holeResult->diagnostics as $d) {
    if (($d['code'] ?? '') === 'hole' || \str_contains((string) ($d['message'] ?? ''), 'hole')) {
        $holeDiag = $d;

        break;
    }
}
$assert($holeDiag !== null, 'hole diagnostic present: ' . \json_encode($holeResult->diagnostics));
$foreignSrc = <<<'MOG'
module ForeignDiag where

foreign php function rawLen "strlen" :: String -> Int -> Int
MOG;
$foreignPath = $tmp . '/ForeignDiag.mog';
\file_put_contents($foreignPath, $foreignSrc);
$foreignUri = pathToUri($foreignPath);
$foreignSvc = new AnalysisService();
$foreignSvc->setWorkspaceRoot($tmp);
$foreignSvc->openDocument($foreignUri, $foreignSrc, 1);
$foreignResult = $foreignSvc->ensureAnalyzed($foreignUri);
$assert($foreignResult !== null, 'foreign module analyzed');
$foreignDiag = null;
foreach ($foreignResult->diagnostics as $d) {
    if (\str_contains((string) ($d['message'] ?? ''), 'foreign')) {
        $foreignDiag = $d;

        break;
    }
}
$assert(
    $foreignDiag !== null,
    'a foreign declaration is verified through the LSP too: ' . \json_encode($foreignResult->diagnostics),
);
$assert(
    \array_key_exists((string) ($foreignDiag['code'] ?? ''), $registry),
    'the foreign diagnostic carries a registered code: ' . \json_encode($foreignDiag),
);
$holeActions = svcCodeActions($holeSvc, $holeUri, $holeDiag['range'], ['diagnostics' => [$holeDiag]]);
$fill = null;
foreach ($holeActions as $a) {
    if (\str_contains((string) ($a['title'] ?? ''), 'Fill hole')) {
        $fill = $a;

        break;
    }
}
$assert($fill !== null, 'hole fill code action present: ' . \json_encode(\array_column($holeActions, 'title')));
$newText = $fill['edit']['documentChanges'][0]['edits'][0]['newText'] ?? null;
$assert($newText === '0', 'Int hole fills with 0, got: ' . \json_encode($fill));
$holeLines = \explode("\n", $holeSrc);
$holeLine = null;
foreach ($holeLines as $i => $line) {
    if (\str_contains($line, '= _')) {
        $holeLine = $i;

        break;
    }
}
$holeCol = (int) \strpos($holeLines[$holeLine], '_');
$holeHover = svcHover($holeSvc, $holeUri, ['line' => $holeLine, 'character' => $holeCol]);
$assert($holeHover !== null, 'hover on hole');
$hoverVal = (string) ($holeHover['contents']['value'] ?? '');
$assert(
    \str_contains($hoverVal, 'Int') || \str_contains($hoverVal, 'hole'),
    "hover shows hole type: {$hoverVal}",
);

$compFixturePath = $fixturesDir . '/Completion.mog';
$compFixtureUri = pathToUri($compFixturePath);
$compFixtureSrc = (string) \file_get_contents($compFixturePath);

$compSvc = new AnalysisService();
$compSvc->setWorkspaceRoot($fixturesDir);
$compSvc->openDocument($compFixtureUri, $compFixtureSrc, 1);
$compResult = $compSvc->ensureAnalyzed($compFixtureUri);
$assert($compResult !== null, 'Completion.mog analyzed without crashing');
$assert($compResult->diagnostics !== [], 'Completion.mog reports parse diagnostics');

$completion = svcCompletion($compSvc, $compFixtureUri, ['line' => 0, 'character' => 0]);
$labels = \array_column($completion['items'], 'label');
$assert(\in_array('data', $labels, true), 'completion offers keywords on broken file');
$assert(\in_array('let', $labels, true), 'completion offers let');
$svc2 = new AnalysisService();
$svc2->setWorkspaceRoot($tmp);
$svc2->openDocument($uri, $unsaved, 1);
$svc2->analyzeDirty();
$live = svcCompletion($svc2, $uri, ['line' => 0, 'character' => 0]);
$liveLabels = \array_column($live['items'], 'label');
$assert(\in_array('inc', $liveLabels, true), 'live completion includes inc');
$assert(\in_array('do', $liveLabels, true), 'live completion includes do snippet');
$hover = svcHover($svc2, $uri, ['line' => 4, 'character' => 1]);
$assert($hover !== null, 'hover on inc returns content');
$value = (string) ($hover['contents']['value'] ?? '');
$assert($value !== '', 'hover markdown non-empty');
$assert(\str_contains($value, 'Int'), 'hover shows Int signature: ' . $value);

@\unlink($path);
@\unlink($holePath);
@\rmdir($tmp);

echo "lsp analyze tests passed\n";
