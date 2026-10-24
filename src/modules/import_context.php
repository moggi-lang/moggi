<?php declare(strict_types=1);

namespace Moggi\Modules;
final class ImportContext
{
    /**
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, array<string, mixed>> $classScopes
     * @param list<array<string, mixed>> $projectInstances
     * @param array{
     *   byClass: array<string, list<array<string, mixed>>>,
     *   byClassHead: array<string, array<string, list<array<string, mixed>>>>,
     *   associatedEquations: array<string, array<string, list<array<string, mixed>>>>
     * }|null $projectInstanceIndex
     */
    public function __construct(
        public readonly ImportInterface $interface,
        public readonly EmitPlan $plan,
        public readonly array $classes = [],
        public readonly array $classScopes = [],
        public readonly array $projectInstances = [],
        public readonly ?array $projectInstanceIndex = null,
        public readonly ?string $currentModule = null,
    ) {
    }

    /** The same context with a re-published interface (class-scope refs, facade types). */
    public function withInterface(ImportInterface $interface): self
    {
        return new self(
            interface: $interface,
            plan: $this->plan,
            classes: $this->classes,
            classScopes: $this->classScopes,
            projectInstances: $this->projectInstances,
            projectInstanceIndex: $this->projectInstanceIndex,
            currentModule: $this->currentModule,
        );
    }

    /**
     * The same context with the project scopes it is checked in.
     *
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, array<string, mixed>> $classScopes
     * @param list<array<string, mixed>> $projectInstances
     * @param array{
     *   byClass: array<string, list<array<string, mixed>>>,
     *   byClassHead: array<string, array<string, list<array<string, mixed>>>>,
     *   associatedEquations: array<string, array<string, list<array<string, mixed>>>>
     * }|null $projectInstanceIndex
     */
    public function withProjectScopes(
        array $classes,
        array $classScopes,
        array $projectInstances,
        ?array $projectInstanceIndex,
        string $currentModule,
    ): self {
        return new self(
            interface: $this->interface,
            plan: $this->plan,
            classes: $classes,
            classScopes: $classScopes,
            projectInstances: $projectInstances,
            projectInstanceIndex: $projectInstanceIndex,
            currentModule: $currentModule,
        );
    }

    /**
     * The same context with the project's class tables as they stand now.
     *
     * Both maps grow while modules are checked, so a context built early holds the snapshot of
     * its own turn; a consumer that reads a hydrated or shallow context needs the final ones.
     *
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, array<string, mixed>> $classScopes
     */
    public function withClassScopes(array $classes, array $classScopes): self
    {
        return new self(
            interface: $this->interface,
            plan: $this->plan,
            classes: $classes,
            classScopes: $classScopes,
            projectInstances: $this->projectInstances,
            projectInstanceIndex: $this->projectInstanceIndex,
            currentModule: $this->currentModule,
        );
    }
}
