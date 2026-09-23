<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Pipeline\CompilePurpose;
use Moggi\Semantics\Kinds;
use Moggi\Semantics\Registry;
use Moggi\Syntax\Ast;

use function Moggi\Errors\formatDiagnostic;
use function Moggi\Semantics\Deriving\commitProjectInstance;
use function Moggi\Semantics\Deriving\processDeriving;
use function Moggi\Semantics\Deriving\processStandaloneDeriving;
use function Moggi\Semantics\Deriving\restoreInstanceState;
use function Moggi\Semantics\Deriving\snapshotInstanceState;
use function Moggi\Semantics\Foreign\checkForeignImportItem;
use function Moggi\Semantics\Foreign\validateForeignTypeDecl;
use function Moggi\Semantics\IntrinsicRegistry\standaloneOperatorSchemes;

require_once __DIR__ . '/type_expr.php';
require_once __DIR__ . '/types/state.php';
require_once __DIR__ . '/evidence_names.php';
require_once __DIR__ . '/types/type_core.php';
require_once __DIR__ . '/types/constraints.php';
require_once __DIR__ . '/types/literals.php';
require_once __DIR__ . '/types/instances.php';
require_once __DIR__ . '/types/infer.php';
require_once __DIR__ . '/types/normalize.php';
require_once __DIR__ . '/types/declarations.php';
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/deriving/framework.php';

final class TypeError extends \RuntimeException
{
    /** @var list<string>|null Structured missing patterns for non-exhaustive cases. */
    public ?array $missingPatterns = null;

    /** Stable diagnostic code when set (e.g. `non-exhaustive`, `hole`). */
    public ?string $diagnosticCode = null;

    /** Structured hole type string when diagnosticCode is `hole`. */
    public ?string $holeType = null;

    /**
     * Extra locations for LSP `relatedInformation`.
     *
     * @var list<array{message: string, filename?: string, line: int, col: int, endCol?: int}>|null
     */
    public ?array $relatedLocations = null;

    public function __construct(
        string $message,
        public readonly string $filename = '',
        public readonly string $source = '',
        private int $errorLine = 0,
        private int $errorCol = 0,
        private int $errorEndCol = 0,
    ) {
        parent::__construct($message);
    }

    public function line(): int
    {
        return $this->errorLine;
    }

    public function col(): int
    {
        return $this->errorCol;
    }

    public function endCol(): int
    {
        return $this->errorEndCol;
    }

    public function display(): string
    {
        if ($this->source === '' || $this->errorLine <= 0) {
            $prefix = $this->filename !== '' ? "{$this->filename}: " : '';

            return "{$prefix}type error: {$this->getMessage()}\n";
        }

        return formatDiagnostic(
            $this->filename,
            'type error',
            $this->getMessage(),
            $this->source,
            $this->errorLine,
            $this->errorCol,
            $this->errorEndCol,
        );
    }
}

/**
 * The declarations of a program that are inferred rather than spelled out.
 *
 * @return list<Ast\FunctionDecl>
 */
function inferredFunctionDecls(Ast\Program $program): array
{
    $functions = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl && ! Ast\hasDeclaredSignature($item)) {
            $functions[] = $item;
        }
    }

    return $functions;
}

/**
 * @param array<string, mixed> $importContext
 */
function checkRaw(
    Ast\Program $program,
    string $source = '',
    string $filename = '',
    array $importContext = [],
    CompilePurpose $purpose = CompilePurpose::Executable,
): Ast\Program {
    $state = newState($source, $filename);

    Registry\applyImportContext($state, $program, $importContext);
    Registry\registerTypeDeclarations($state, $program);
    Registry\registerFunctionSchemesFromProgram($state, $program);
    discoverInferredSignatures($state, inferredFunctionDecls($program));

    $items = [];
    $seenData = [];
    foreach ($program->items as $item) {
        $state->declSpan = [
            'line' => $item->line ?? 0,
            'col' => $item->col ?? 0,
            'endCol' => $item->endCol ?? 0,
        ];
        match ($item::class) {
            Ast\ImportDecl::class => null,
            Ast\InstanceDecl::class => (function () use ($state, $item, &$items): void {
                foreach (checkInstance($state, $item) as $fn) {
                    $items[] = $fn;
                }
            })(),
            Ast\StandaloneDerivingDecl::class => (function () use ($state, $item, &$items): void {
                $inst = processStandaloneDeriving($state, $item);
                $saved = snapshotInstanceState($state);
                $state->stockDeriving = true;
                try {
                    commitProjectInstance($state, $inst);
                    foreach (checkInstance($state, $inst) as $fn) {
                        $items[] = $fn;
                    }
                } catch (\Throwable $e) {
                    restoreInstanceState($state, $saved);
                    throw $e;
                } finally {
                    $state->stockDeriving = false;
                }
            })(),
            Ast\ForeignImportDecl::class => (function () use ($state, $item, &$items): void {
                $fn = checkForeignImportItem($state, $item);
                $items[] = checkFunction($state, $fn);
            })(),
            Ast\ForeignTypeDecl::class => (function () use ($state, $item, &$items): void {
                // Already registered in registerTypeDeclarations (like DataDecl).
                validateForeignTypeDecl($state, $item);
                $items[] = $item;
            })(),
            Ast\FunctionDecl::class => $items[] = $item->signatureOnly ? $item : checkFunction($state, $item),
            Ast\DataDecl::class => (function () use ($state, $item, &$items, &$seenData): void {
                if (isset($seenData[$item->name])) {
                    throw typeFail($state, "duplicate data type `{$item->name}`", $item);
                }
                $seenData[$item->name] = true;
                $items[] = $item;
                foreach (processDeriving($state, $item) as $fn) {
                    $items[] = $fn;
                }
            })(),
            Ast\TypeSynonymDecl::class,
            Ast\ClassDecl::class => $items[] = $item,
            default => (function () use ($state, $item, &$items): void {
                inferExpr($state, $item, $state->env);
                assertNoPendingConstraintsInExpr($state, $item);
                $items[] = $item;
            })(),
        };
    }

    // A restricted declaration is settled by the whole module, not by itself.
    finishRestrictedDeclarations($state);

    validateEntryPoint($state, $items, $program->module, $purpose);
    $freeFnNames = [];
    foreach ($items as $item) {
        if ($item instanceof Ast\FunctionDecl
            && !$item->instanceMethod
            && !$item->signatureOnly
            && !$item->foreign
        ) {
            $freeFnNames[$item->name] = true;
        }
    }
    uniquifyCollidingInstanceMethods($state, $freeFnNames);

    $checked = new Ast\Program(
        $items,
        $program->module,
        $program->imports,
        $program->exports,
        $importContext['codegen']['externalFns'] ?? [],
        $state->constructorRenames,
        $state->instanceEvidence,
        $importContext['externalFnRuntimeArity'] ?? [],
        $program->moduleBackend,
        $program->backendMap,
        exportedInferredSchemesFromEnv($state, $program),
        $program->implicitMain,
        $program->language,
        $program->moduleDoc,
        $program->fixityDocs,
        externalActionReturnFns: $importContext['externalActionReturnFns'] ?? [],
    );


    return $checked;
}

function exportedInferredSchemesFromEnv(TypeCheckState $state, Ast\Program $program): array
{
    $exports = $program->exports;
    if ($exports === []) {
        return [];
    }

    $exportedNames = null;
    if ($exports !== null) {
        $exportedNames = [];
        foreach ($exports as $export) {
            if (($export['tag'] ?? '') === 'value') {
                $exportedNames[$export['name']] = true;
            }
        }
    }

    $schemes = [];
    foreach ($program->items as $item) {
        if (!($item instanceof Ast\FunctionDecl)) {
            continue;
        }

        $name = $item->name;
        if ($exportedNames !== null && !isset($exportedNames[$name])) {
            continue;
        }
        if (Ast\hasDeclaredSignature($item) || $item->signatureOnly) {
            continue;
        }

        if (isset($state->env[$name])) {
            $schemes[$name] = $state->env[$name];
        }
    }

    return $schemes;
}

function newState(string $source, string $filename = ''): TypeCheckState
{
    $state = new TypeCheckState($source, $filename);

    Kinds\bootstrapKindEnv($state);

    foreach (standaloneOperatorSchemes() as $name => $scheme) {
        $state->env[$name] = $scheme;
        $state->standaloneEnvNames[$name] = true;
    }

    return $state;
}

function isErrorCallExpr(Ast\AstNode $expr): bool
{
    if ($expr instanceof Ast\IntrinsicCall && $expr->name === 'error#') {
        return true;
    }

    // Bare `undefined` is a polymorphic bottom (defined as `error "…"` in Moggi.Err).
    if ($expr instanceof Ast\Variable || $expr instanceof Ast\QualifiedRef) {
        return $expr->name === 'undefined';
    }

    if (!$expr instanceof Ast\Apply) {
        return false;
    }

    $fn = $expr;
    while ($fn instanceof Ast\Apply) {
        $fn = $fn->function;
    }

    return match ($fn::class) {
        Ast\Variable::class,
        Ast\QualifiedRef::class => $fn->name === 'error' || $fn->name === 'undefined',
        default => false,
    };
}
