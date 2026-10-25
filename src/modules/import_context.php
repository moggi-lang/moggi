<?php declare(strict_types=1);

namespace Moggi\Modules;
final class ImportContext
{
    /**
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, array<string, mixed>> $classScopes
     * @param ?InstanceWorld $instanceWorld the prepared project's instance world, when this
     *   module is checked as part of one; null only for a check that was given no project
     *   (`checkRaw` with no context), which is then its own world
     */
    public function __construct(
        public readonly ImportInterface $interface,
        public readonly EmitPlan $plan,
        public readonly array $classes = [],
        public readonly array $classScopes = [],
        public readonly ?InstanceWorld $instanceWorld = null,
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
            instanceWorld: $this->instanceWorld,
            currentModule: $this->currentModule,
        );
    }

    /**
     * The same context with the project scopes it is checked in.
     *
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, array<string, mixed>> $classScopes
     */
    public function withProjectScopes(
        array $classes,
        array $classScopes,
        InstanceWorld $instanceWorld,
        string $currentModule,
    ): self {
        return new self(
            interface: $this->interface,
            plan: $this->plan,
            classes: $classes,
            classScopes: $classScopes,
            instanceWorld: $instanceWorld,
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
            instanceWorld: $this->instanceWorld,
            currentModule: $this->currentModule,
        );
    }
}
