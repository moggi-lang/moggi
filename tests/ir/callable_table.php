<?php declare(strict_types=1);

// The callable table is the one published record of an entry's layout: distinct evidence /
// capture / value slots, plus the result, execution and acquisition conventions. These four
// programs are the conventions' worked examples, so this pins the compiler-produced signatures
// against what the tree promises rather than against a dump.
//
// What it holds down:
//   1. lowering records a declaration's evidence slots where the declaration is made, so the
//      table gets `E` from the solved constraint list and the scope a lambda closed over, never
//      from the `__ev_` spelling;
//   2. the flattened runtime vector is `E ++ C ++ V`, and its length is the entry's runtime arity;
//   3. the post-legalize check rejects a direct call whose vector disagrees with the table, and
//      a partial that is not an underapplication — with or without the optimizer.

$root = __DIR__;
while (! \is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}
require $root . '/src/compiler.php';
require $root . '/tests/suite/support/assert.php';
require $root . '/tests/suite/support/workspace.php';

use Moggi\IR;
use Moggi\IR\Callables;
use Moggi\IR\Validate\InvalidIr;

use function Moggi\IR\Validate\validateCallableLayouts;
use function Moggi\Pipeline\run as pipelineRun;
use function Moggi\Syntax\Lexer\lex;
use function Moggi\Syntax\Parser\parse;

\Moggi\Modules\setStdlibLibPath($root . '/libraries/base/src');

$scratch = createTempDir('moggi-callable-table');
\register_shutdown_function(static function () use ($scratch): void {
    removeDirectory($scratch);
});

/** @var list<string> $failures */
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

/**
 * Compile one source the way the pipeline does and return its table.
 *
 * @return array{IR\Module, Callables\CallableTable}
 */
$compile = static function (string $source, string $filename, bool $optimize = false) use ($scratch): array {
    $dir = $scratch . '/' . \preg_replace('/\.mog$/', '', $filename);
    if (! \is_dir($dir)) {
        \mkdir($dir, 0o777, true);
    }
    $path = $dir . '/Main.mog';
    \file_put_contents($path, $source);

    $program = parse(lex($source, $path), $source, $path);
    $context = \Moggi\Pipeline\CompilationContext::for(
        'php',
        \Moggi\Pipeline\CompilePurpose::Executable,
        entryModules: ['Main'],
        roots: [],
        sources: [$path],
    );
    $moduleContext = \Moggi\Modules\moduleCompileContext($path, $context, $program);
    $artifacts = pipelineRun(new \Moggi\Pipeline\PipelineRequest(
        context: $context,
        source: $source,
        filename: $path,
        program: $program,
        optimize: $optimize,
        importContext: $moduleContext['importContext'],
    ), \Moggi\Pipeline\PipelineStage::IrOpt);

    return [$artifacts->irOpt, Callables\table($artifacts->irOpt->functions, 'Main')];
};

/** @param list<string> $evidence @return string */
$shape = static function (Callables\CallableSig $sig, array $evidence): string {
    return 'E=' . \count($sig->evidence) . '|' . \implode(',', $evidence)
        . ' C=' . \count($sig->captures) . '|' . \implode(',', $sig->captures)
        . ' V=' . \count($sig->params) . '|' . \implode(',', $sig->params);
};

[$module, $table] = $compile(<<<'MOGGI'
module Main where

import System.IO
import Text.Show

class Tag a where
  tag :: a -> String

instance Tag Int where
  tag x = "tag-int"

choose :: Tag a => a -> (a -> String)
choose x = \y -> tag x <> tag y

main :: IO ()
main = putStrLn (choose 1 2)
MOGGI, 'CallableTable-Captured.mog');


$choose = $table->sig('choose');
$check($choose !== null, 'choose has no published signature');
if ($choose !== null) {
    $check(
        $shape($choose, ['__ev_Tag']) === 'E=1|__ev_Tag C=0| V=1|x',
        'choose layout is not E=[Tag a] C=[] V=[x]: ' . $shape($choose, $choose->evidenceNames()),
    );
    $check($choose->result === Callables\ResultConvention::Callable, 'choose does not publish a callable result');
}

$lambda = null;
foreach ($module->functions as $function) {
    if ($function->kind === IR\FunctionKind::Lambda) {
        $lambda = $function;
    }
}
$check($lambda !== null, 'the example has no lifted lambda');
$lambdaSig = $lambda === null ? null : $table->sig($lambda->name);
$check($lambdaSig !== null, 'the lifted lambda has no published signature');
if ($lambdaSig !== null) {
    $check(
        $shape($lambdaSig, ['__ev_Tag']) === 'E=1|__ev_Tag C=1|x V=1|y',
        'the closure entry is not E=[Tag a] C=[x] V=[y]: ' . $shape($lambdaSig, $lambdaSig->evidenceNames()),
    );
    $check($lambdaSig->runtimeArity() === 3, 'the closure entry arity is not |E|+|C|+|V| = 3');
    $check(
        $lambdaSig->slotNames() === ['__ev_Tag', 'x', 'y'],
        'the runtime slot order is not E ++ C ++ V: ' . \implode(',', $lambdaSig->slotNames()),
    );
    $check($lambdaSig->kind === IR\FunctionKind::Lambda, 'the closure entry lost its kind');
}

[, $plain] = $compile(<<<'MOGGI'
module Main where

import Text.Show

pick :: Int -> (Int -> Int)
pick x = \y -> x + y

main :: Int
main = pick 1 2
MOGGI, 'CallableTable-Plain.mog');
$pickLambda = null;
foreach ($plain->byEntry as $sig) {
    if ($sig->kind === IR\FunctionKind::Lambda) {
        $pickLambda = $sig;
    }
}
$check($pickLambda !== null, 'the plain example has no lifted lambda');
if ($pickLambda !== null) {
    $check(
        $pickLambda->evidence === [] && $pickLambda->captures === ['x'] && $pickLambda->params === ['y'],
        'a lambda in an unconstrained scope recorded evidence: ' . $shape($pickLambda, $pickLambda->evidenceNames()),
    );
    $check(
        $pickLambda->result === Callables\ResultConvention::Value,
        'a lifted callable did not publish its value result: ' . $pickLambda->result->value,
    );
    $check(
        $pickLambda->execution === Callables\ExecutionConvention::ReturnValue,
        'a lifted value callable published execution ' . $pickLambda->execution->value,
    );
    $check(
        $pickLambda->returnsCallable === false,
        'a lifted callable returning a String published a callable result',
    );
}

[$module, $table] = $compile(<<<'MOGGI'
module Main where

import Text.Show

class Tag a where
  tag :: a -> String

class Tag a => Labelled a where
  combine :: Tag b => a -> b -> String

instance Tag Int where
  tag x = "tag-int"

instance Tag Bool where
  tag x = "tag-bool"

instance Labelled Int where
  combine x y = tag x <> ":" <> tag y

withOne :: Tag b => b -> String
withOne = combine 1

main :: String
main = withOne True
MOGGI, 'CallableTable-Method.mog');

$combine = $table->sig('combine');
$check($combine !== null, 'combine has no published signature');
if ($combine !== null) {
    $check(
        $shape($combine, ['__ev_Tag']) === 'E=1|__ev_Tag C=0| V=2|x,y',
        'combine does not publish its method-local evidence as its own slot set: '
            . $shape($combine, $combine->evidenceNames()),
    );
}

$withOne = $table->sig('withOne');
$check($withOne !== null, 'withOne has no published signature');
if ($withOne !== null) {
    $check(
        $shape($withOne, ['__ev_Tag']) === 'E=1|__ev_Tag C=0| V=0|',
        'a constrained nullary binding is not E=[Tag b] V=[]: ' . $shape($withOne, $withOne->evidenceNames()),
    );
    $check(
        $withOne->acquisition === Callables\AcquisitionKind::EvaluateBinding,
        'a nullary binding with a signature is not an evaluated binding',
    );
}

[$module, $table] = $compile(<<<'MOGGI'
module Main where

import Text.Show

class Tag a where
  tag :: a -> String

instance Tag Int where
  tag x = "tag-int"

provider :: Tag a => a -> String
provider = tag

main :: String
main = provider 1 <> provider 2
MOGGI, 'CallableTable-Provider.mog');

$provider = $table->sig('provider');
$check($provider !== null, 'provider has no published signature');
if ($provider !== null) {
    $check(
        $provider->acquisition === Callables\AcquisitionKind::EvaluateBinding,
        'the provider is not published as an evaluated binding',
    );
    $check($provider->runtimeArity() === 1, 'the provider entry arity is not its evidence slot');
}

$acquires = 0;
foreach ($module->functions as $function) {
    \Moggi\IR\Visit\walkBlock(
        $function->body,
        static function (IR\Stmt $stmt) use (&$acquires): void {
            if ($stmt instanceof IR\Call && $stmt->callee === 'provider') {
                ++$acquires;
            }
        },
        static function (IR\Operand $operand): void {
        },
    );
}
$check($acquires >= 2, "expected one acquisition per reference, found {$acquires}");

[$module, $table] = $compile(<<<'MOGGI'
module Main where

import Text.Show

choose :: Int -> (Int -> Int)
choose x = \y -> x + y

main :: Int
main = choose 3 4
MOGGI, 'CallableTable-Over.mog');

$choose = $table->sig('choose');
$check($choose !== null, 'choose has no published signature');
if ($choose !== null) {
    $check(
        $shape($choose, []) === 'E=0| C=0| V=1|x',
        'the overapplication entry is not a one-parameter callable: ' . $shape($choose, $choose->evidenceNames()),
    );
    $check($choose->runtimeArity() === 1, 'the entry was given an arity from its source arrows');
    $check($choose->result === Callables\ResultConvention::Callable, 'the entry does not return a callable');
}

$callee = new IR\FunctionDecl(
    'takesOne',
    ['x'],
    null,
    new IR\Block([new IR\Ret(new IR\Local('x'))]),
);
$caller = new IR\FunctionDecl(
    'caller',
    [],
    null,
    new IR\Block([
        new IR\Call('takesOne', [], 0),
        new IR\Ret(new IR\Unit()),
    ]),
);
$malformed = new IR\Module([$callee, $caller], [], [], null, 'Main');

try {
    validateCallableLayouts($malformed, 'test');
    $check(false, 'an underapplied direct call was accepted');
} catch (InvalidIr $e) {
    $message = $e->getMessage();
    $check(\str_contains($message, 'takesOne'), 'the failure does not name the entry: ' . $message);
    $check(\str_contains($message, 'caller'), 'the failure does not name the caller: ' . $message);
    $check(\str_contains($message, '1 slots'), 'the failure does not state the published arity: ' . $message);
    $check($e->pass === 'test', 'the failure does not carry its pass label');
}

$saturated = new IR\FunctionDecl(
    'partialCaller',
    [],
    null,
    new IR\Block([new IR\Ret(new IR\Partial('takesOne', 1, [new IR\Local('y')]))]),
);
try {
    validateCallableLayouts(new IR\Module([$callee, $saturated], [], [], null, 'Main'), 'test');
    $check(false, 'a saturated partial was accepted as an underapplication');
} catch (InvalidIr $e) {
    $check(\str_contains($e->getMessage(), 'underapplication'), 'the failure is not about saturation: ' . $e->getMessage());
}

$stale = new IR\FunctionDecl(
    'staleCaller',
    [],
    null,
    new IR\Block([new IR\Ret(new IR\Partial('takesOne', 2, [new IR\Local('y')]))]),
);
try {
    validateCallableLayouts(new IR\Module([$callee, $stale], [], [], null, 'Main'), 'test');
    $check(false, 'a partial with a stale arity was accepted');
} catch (InvalidIr $e) {
    $check(\str_contains($e->getMessage(), 'arity 2'), 'the failure does not state the stale arity: ' . $e->getMessage());
}

$underapplied = <<<'MOGGI'
module Main where

import Text.Show

main :: Int
main = let add x y = x + y
           inc = add 1
       in inc 2
MOGGI;

foreach ([false, true] as $optimize) {
    [$module, $table] = $compile($underapplied, 'CallableTable-Underapplied' . ($optimize ? '-opt' : '') . '.mog', $optimize);

    $label = $optimize ? 'optimized' : 'no-opt';
    $shortCalls = [];
    $partialArities = [];
    foreach ($module->functions as $function) {
        \Moggi\IR\Visit\walkBlock(
            $function->body,
            static function (IR\Stmt $stmt) use (&$shortCalls, $table, $function): void {
                if ($stmt instanceof IR\Call) {
                    $sig = $table->sig($stmt->callee);
                    if ($sig !== null && \count($stmt->args) < $sig->runtimeArity()) {
                        $shortCalls[] = $function->name . ' -> ' . $stmt->callee
                            . ' (' . \count($stmt->args) . ' of ' . $sig->runtimeArity() . ')';
                    }
                }
            },
            static function (IR\Operand $operand) use (&$partialArities, $table): void {
                if ($operand instanceof IR\Partial) {
                    $sig = $table->sig($operand->fn);
                    $partialArities[] = $operand->fn . ':' . $operand->arity
                        . '/' . ($sig?->runtimeArity() ?? -1);
                }
            },
        );
    }

    $check($shortCalls === [], "{$label}: under-supplied calls survived legalization: " . \implode('; ', $shortCalls));
    if (! $optimize) {
        $check(
            $partialArities !== [],
            'no-opt: the under-application is not an explicit partial: ' . \implode(', ', $partialArities),
        );
    }
    foreach ($partialArities as $partial) {
        [$entry, $lengths] = \explode(':', $partial);
        [$declared, $published] = \explode('/', $lengths);
        $check(
            $declared === $published,
            "{$label}: partial of {$entry} declares arity {$declared}, the table publishes {$published}",
        );
    }
}

$captured = new IR\FunctionDecl(
    'λ0',
    [],
    null,
    new IR\Block([new IR\Ret(new IR\Local('c'))]),
    kind: IR\FunctionKind::Lambda,
);
$bareReference = new IR\FunctionDecl(
    'bareCaller',
    [],
    null,
    new IR\Block([new IR\Ret(new IR\FnRef('λ0'))]),
);
try {
    validateCallableLayouts(new IR\Module([$captured, $bareReference], [], [], null, 'Main'), 'test');
    $check(false, 'a bare FnRef to a capture-taking entry was accepted');
} catch (InvalidIr $e) {
    $check(
        \str_contains($e->getMessage(), 'referenced bare'),
        'the failure is not about the bare reference: ' . $e->getMessage(),
    );
}

[$optimizedModule, $optimizedTable] = $compile(<<<'MOGGI'
module Main where

import System.IO
import Text.Show

class Tag a where
  tag :: a -> String

instance Tag Int where
  tag x = "tag-int"

choose :: Tag a => a -> (a -> String)
choose x = \y -> tag x <> tag y

main :: IO ()
main = putStrLn (choose 1 2)
MOGGI, 'CallableTable-Optimized.mog', true);

$optimizedLambda = null;
foreach ($optimizedTable->byEntry as $sig) {
    if ($sig->kind === IR\FunctionKind::Lambda) {
        $optimizedLambda = $sig;
    }
}
$check($optimizedLambda !== null, 'the optimized example has no lifted lambda');
if ($optimizedLambda !== null && $lambdaSig !== null) {
    $check(
        $optimizedLambda->evidenceNames() === $lambdaSig->evidenceNames()
            && $optimizedLambda->captures === $lambdaSig->captures
            && $optimizedLambda->params === $lambdaSig->params,
        'the optimizer changed the published layout: '
            . $shape($optimizedLambda, $optimizedLambda->evidenceNames())
            . ' vs ' . $shape($lambdaSig, $lambdaSig->evidenceNames()),
    );
}

$importSource = <<<'MOGGI'
module Main where

import System.IO
import Data.Function (($))
import Text.Show

main :: IO ()
main = putStrLn (showString "a" "b")
MOGGI;
$importDir = $scratch . '/CallableTable-Imported';
if (! \is_dir($importDir)) {
    \mkdir($importDir, 0o777, true);
}
$importPath = $importDir . '/Main.mog';
\file_put_contents($importPath, $importSource);
$importProgram = parse(lex($importSource, $importPath), $importSource, $importPath);
$importContext = \Moggi\Pipeline\CompilationContext::for(
    'php',
    \Moggi\Pipeline\CompilePurpose::Executable,
    entryModules: ['Main'],
    roots: [],
    sources: [$importPath],
);
$typedArtifacts = pipelineRun(new \Moggi\Pipeline\PipelineRequest(
    context: $importContext,
    source: $importSource,
    filename: $importPath,
    program: $importProgram,
    optimize: false,
    importContext: \Moggi\Modules\moduleCompileContext($importPath, $importContext, $importProgram)['importContext'],
), \Moggi\Pipeline\PipelineStage::TypedAst);
$typed = $typedArtifacts->typedAst;
$check($typed !== null, 'the imported-entry example did not typecheck');

if ($typed !== null) {
    $check(
        ($typed->interface?->callableArity()['showString'] ?? null) === 2,
        'showString reached the importer as ' . \json_encode($typed->interface?->callableArity()['showString'] ?? null)
            . ' slot(s); its declared parameters are 2',
    );
    $check(
        ($typed->interface?->callableArity()['shows'] ?? null) === 3,
        'shows reached the importer as ' . \json_encode($typed->interface?->callableArity()['shows'] ?? null)
            . ' slot(s); one evidence slot plus two parameters is 3',
    );
    $check(
        ($typed->interface?->callableArity()['$'] ?? null) === 1,
        '($) reached the importer as ' . \json_encode($typed->interface?->callableArity()['$'] ?? null)
            . ' slot(s); its declared vector is 1',
    );

    $units = \Moggi\Modules\moduleCompileContext($importPath, $importContext, $importProgram)['units'];
    $function = $units['Data.Function']['facts']['callables']['entries'] ?? null;
    $check(\is_array($function), 'Data.Function published no callable facts');
    if (\is_array($function)) {
        $check(
            ($function['fix'] ?? null) === ['evidence' => 0, 'params' => 0, 'arity' => 0],
            'fix did not publish a nullary binding: ' . \json_encode($function['fix'] ?? null),
        );
        $check(
            ($function['$'] ?? null) === ['evidence' => 0, 'params' => 1, 'arity' => 1],
            '($) did not publish its one declared parameter: ' . \json_encode($function['$'] ?? null),
        );
    }
    $show = $units['Text.Show']['facts']['callables']['entries'] ?? null;
    $check(
        ($show['shows'] ?? null) === ['evidence' => 1, 'params' => 2, 'arity' => 3],
        'shows did not publish E=1 V=2: ' . \json_encode($show['shows'] ?? null),
    );
}

$importedCall = static function (array $args): IR\FunctionDecl {
    return new IR\FunctionDecl(
        'importedCaller',
        [],
        null,
        new IR\Block([new IR\Call('shows', $args, 0), new IR\Ret(new IR\Unit())]),
    );
};
try {
    validateCallableLayouts(
        new IR\Module([$importedCall([new IR\Local('x')])], [], [], null, 'Main'),
        'test',
        ['shows' => 2],
    );
    $check(false, 'an under-supplied imported call was accepted');
} catch (InvalidIr $e) {
    $check(
        \str_contains($e->getMessage(), 'imported entry'),
        'the failure does not name the imported entry: ' . $e->getMessage(),
    );
}
validateCallableLayouts(
    new IR\Module([$importedCall([new IR\Local('x'), new IR\Local('y')])], [], [], null, 'Main'),
    'test',
    ['shows' => 2],
);

$methodCall = <<<'MOGGI'
module Main where

import System.IO

data A = A

class Tag a where
  tag :: a -> String

class (Tag a, Tag b) => Both a b where
  both :: a -> b -> String

instance Tag A where
  tag x = "t"

instance (Tag a, Tag b) => Both a b where
  both x y = "b"

main :: IO ()
main = putStrLn (both A A)
MOGGI;

foreach ([false, true] as $optimize) {
    [$module, $table] = $compile($methodCall, 'CallableTable-MethodSlots' . ($optimize ? '-opt' : '') . '.mog', $optimize);
    $label = $optimize ? 'optimized' : 'no-opt';

    $sig = $table->sig('both');
    $check($sig !== null, "{$label}: the instance method publishes no entry");
    $published = $sig === null ? -1 : \count($sig->evidence);

    $calls = [];
    foreach ($module->functions as $function) {
        \Moggi\IR\Visit\walkBlock(
            $function->body,
            static function (IR\Stmt $stmt) use (&$calls): void {
                if ($stmt instanceof IR\DictCall) {
                    $calls[] = $stmt;
                }
            },
            static function (): void {
            },
        );
    }

    $check($calls !== [], "{$label}: the method call did not lower to a dict_call");
    foreach ($calls as $call) {
        $check(
            $call->localEvidence !== null,
            "{$label}: {$call->method} carries no published instance-context width",
        );
        $check(
            $call->localEvidence === $published,
            "{$label}: {$call->method} publishes " . \var_export($call->localEvidence, true)
                . ' context dictionary/ies; its entry has ' . $published . ' leading slot(s)',
        );
    }
}

$ownTable = Callables\table([
    new IR\FunctionDecl(
        'helper',
        [],
        null,
        new IR\Block([new IR\Ret(new IR\Unit())]),
        kind: IR\FunctionKind::Source,
    ),
], 'Main');
$check(
    $ownTable->sig('Main::helper') !== null,
    "a qualified reference to this module's own entry no longer resolves",
);
$check(
    $ownTable->sig('Data.Foldable::helper') === null,
    "a qualified reference to another module's `helper` resolved to this module's entry",
);

$threw = false;
try {
    \Moggi\Modules\externalFunctionRuntimeArity([], 'Facade', 'entry');
} catch (\LogicException) {
    $threw = true;
}
$check(
    $threw,
    'an entry with no published layout resolved anyway',
);
$check(
    \Moggi\Modules\externalFunctionRuntimeArity(
        ['Lib' => ['facts' => ['callables' => ['entries' => ['eta' => ['evidence' => 0, 'params' => 1, 'arity' => 1]]]]]],
        'Lib',
        'eta',
    ) === 1,
    "a declared entry's published vector was not read",
);
$threw = false;
try {
    \Moggi\Modules\publishedCallableLayout(
        ['Lib' => ['facts' => ['callables' => ['entries' => ['partial' => ['arity' => 2]]]]]],
        'Lib',
        'partial',
    );
} catch (\LogicException) {
    $threw = true;
}
$check(
    $threw,
    'an arity-only record was published as if it were a layout, and the split read as zero',
);

$scopeInterface = (new \Moggi\Modules\ImportInterface(
    'Main',
    externalFns: ['importedEntry' => 'Prov::importedEntry'],
))->withClassScopeRefs(
    [
        'externalFns' => ['importedEntry' => 'Prov::importedEntry'],
        'callables' => ['importedEntry' => ['evidence' => 0, 'params' => 2, 'arity' => 2]],
    ],
    [],
);
$check(
    ($scopeInterface->externalFnRuntimeArity['importedEntry'] ?? null) === 2,
    'a class-scope reference published a layout the runtime arities do not carry',
);
$check(
    ($scopeInterface->callableArity()['importedEntry'] ?? null) === 2,
    'a class-scope reference published an arity the layout check cannot see',
);

$scopeCaller = new IR\FunctionDecl(
    'scopeCaller',
    [],
    null,
    new IR\Block([
        new IR\Call('importedEntry', [new IR\Local('x')], 0),
        new IR\Ret(new IR\Unit()),
    ]),
);
try {
    validateCallableLayouts(new IR\Module([$scopeCaller], [], [], null, 'Main'), 'class-scope', $scopeInterface->callableArity());
    $check(false, 'a class-scope reference with a wrong published arity was accepted');
} catch (InvalidIr $e) {
    $check(
        \str_contains($e->getMessage(), 'published slot'),
        'the failure does not state the published arity: ' . $e->getMessage(),
    );
}

if ($failures !== []) {
    throw new \RuntimeException(
        \count($failures) . " callable-table failure(s):\n  " . \implode("\n  ", $failures),
    );
}

echo "callable table tests passed\n";
