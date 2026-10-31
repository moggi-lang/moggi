<?php declare(strict_types=1);

namespace Moggi\Modules;
final class ImportContext
{
    /**
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, array<string, mixed>> $classScopes
     * @param InstanceWorld $instanceWorld the world this module is checked against: the
     *   prepared project's, or the module's own when it is checked on its own. A context
     *   without one would let the checker fall back to a narrower world than the project,
     *   which is why it is a constructor argument rather than a nullable field.
     */
    public function __construct(
        public readonly ImportInterface $interface,
        public readonly EmitPlan $plan,
        public readonly InstanceWorld $instanceWorld,
        public readonly array $classes = [],
        public readonly array $classScopes = [],
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
     * The world is not a scope: it is fixed when the context is built, so a
     * later stage cannot hand the same module a different one.
     *
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, array<string, mixed>> $classScopes
     */
    public function withProjectScopes(
        array $classes,
        array $classScopes,
        string $currentModule,
    ): self {
        return new self(
            interface: $this->interface,
            plan: $this->plan,
            instanceWorld: $this->instanceWorld,
            classes: $classes,
            classScopes: $classScopes,
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
