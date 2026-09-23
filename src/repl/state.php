<?php declare(strict_types=1);

namespace Moggi\Repl;

use Moggi\Modules\PreparedProject;
use Moggi\Pipeline\PipelineArtifacts;
use Moggi\Syntax\Ast\Program;

use function Moggi\Paths\moduleNameToPath;

/** Mutable interactive session state for the Moggi REPL. */
final class State
{
    /**
     * @param list<string> $libDirs
     * @param list<string> $history
     * @param list<string> $declSources
     * @param list<string> $importLines
     * @param list<string> $loadedPaths
     * @param list<string>|null $lastFragmentFocus
     * @param array<string, string> $phpDepsModuleRel
     */
    public function __construct(
        public string $backend = 'php',
        public string $sessionDir = '',
        public string $moduleName = 'Interactive',
        public array $libDirs = [],
        public array $history = [],
        public array $declSources = [],
        public array $importLines = [],
        public array $loadedPaths = [],
        public ?string $loadedPrimary = null,
        public ?PreparedProject $prepared = null,
        /** Stable stdlib/:load prepare used as the typecheck backdrop for Interactive. */
        public ?PreparedProject $basePrepared = null,
        public ?string $basePreparedFp = null,
        public ?Program $typedInteractive = null,
        public ?PipelineArtifacts $lastFragment = null,
        public ?array $lastFragmentFocus = null,
        public int $generation = 0,
        public bool $optimize = true,
        public string $buffer = '',
        public ?string $phpDepsFingerprint = null,
        public array $phpDepsModuleRel = [],
        /**
         * Memo of prepared interactive modules: fingerprint => context
         * (`prepared`, `checked`, `importContext`, `source`), newest first.
         *
         * Holds the current module plus the previous one so the
         * probe → restore pattern (`:type`, `:ast`, `:emit`) does not
         * re-typecheck the module twice per command.
         *
         * @var array<string, array<string, mixed>>
         */
        public array $interactiveCache = [],
    ) {
    }

    /** Drop every memoized interactive context (source or libraries changed). */
    public function forgetInteractiveCache(): void
    {
        $this->interactiveCache = [];
    }

    public function interactivePath(): string
    {
        return $this->sessionDir . DIRECTORY_SEPARATOR . moduleNameToPath($this->moduleName) . '.mog';
    }
}
