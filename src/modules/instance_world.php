<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Syntax\Ast;

/**
 * The identity of one instance world: a digest over its records, the module set
 * it was collected from, and how many records it holds.
 *
 * The records themselves stay in the world preparation owns. A module product
 * carries this identity instead of a copy: a program that embedded the whole
 * world would multiply it by every module and every cache entry, and no consumer
 * of a checked module needs the records — they are already elaborated into its
 * evidence. What a consumer does need is which world the module was checked
 * against, and whether another route prepared the same one.
 */
final class InstanceWorldId
{
    /** @param list<string> $modules the module set the world was collected from, sorted */
    public function __construct(
        public readonly string $digest,
        public readonly array $modules,
        public readonly int $records,
    ) {
    }
}

/**
 * The instance world one compilation is checked against.
 *
 * The world is an *input* of the check, not a fact of any one module: a
 * declaration's scheme means what it means relative to the instances visible in
 * the prepared project, and coherence is project-global. So the world is named
 * once per compilation, `ImportContext` carries it to every module, and each
 * checked program publishes its {@see InstanceWorldId}.
 *
 * The identity is deliberately route-independent: records are kept in a
 * canonical order (by module name, then the order the module declares them) and
 * the digest is taken over the sorted canonical lines, so two preparations of
 * the same module set — a full project compile, the focus route a single-file
 * compile or the language server takes — publish the same identity even when
 * they walk the set in a different order. A route that sees a smaller set gets a
 * different digest, which is the fact coherence diagnostics need to explain it.
 */
final class InstanceWorld
{
    /** @var array{byClass: array<string, list<array<string, mixed>>>, byClassHead: array<string, array<string, list<array<string, mixed>>>>, associatedEquations: array<string, array<string, list<array<string, mixed>>>>}|null */
    private ?array $index = null;

    /** @param list<array<string, mixed>> $records in canonical order, one entry per visible instance */
    private function __construct(
        public readonly array $records,
        public readonly InstanceWorldId $id,
    ) {
    }

    /**
     * The class/head/associated-equation index the checker installs.
     *
     * Built from this world's records and cached on it, so the index and the
     * identity can never describe different sets.
     *
     * @return array{
     *   byClass: array<string, list<array<string, mixed>>>,
     *   byClassHead: array<string, array<string, list<array<string, mixed>>>>,
     *   associatedEquations: array<string, array<string, list<array<string, mixed>>>>
     * }
     */
    public function index(): array
    {
        return $this->index ??= indexProjectInstances($this->records);
    }

    /** @return list<string> */
    public function modules(): array
    {
        return $this->id->modules;
    }

    /**
     * The world the given units declare, over a canonical module set.
     *
     * `$moduleNames` defaults to every non-synthetic unit the preparation
     * parsed — the modules this route actually read — so every route that
     * prepares the same paths asks for the same set without restating it. A
     * module that was not parsed declares instances nothing can see, so naming
     * it would claim coverage the identity does not have; a caller that passes
     * such a module explicitly is refused by the collector rather than served a
     * world with a hole in it. Synthetic compiler units declare no instances and
     * are not part of a project.
     *
     * @param array<string, array<string, mixed>> $units
     * @param list<string>|null $moduleNames
     */
    public static function forUnits(array $units, ?array $moduleNames = null): self
    {
        if ($moduleNames === null) {
            $moduleNames = [];
            foreach ($units as $name => $unit) {
                if (($unit['synthetic'] ?? false) === true || ($unit['parsed'] ?? false) !== true) {
                    continue;
                }

                $moduleNames[] = (string) $name;
            }
        }

        $moduleNames = \array_values(\array_unique(\array_map(
            static fn (mixed $name): string => (string) $name,
            $moduleNames,
        )));
        \sort($moduleNames);

        return self::over(collectProjectInstances($units, $moduleNames), $moduleNames);
    }

    /**
     * A world over records already collected for `$modules`.
     *
     * Records are re-ordered into the canonical module order and the digest is
     * taken from their canonical text, so the identity depends on the set and
     * the records, never on how they were gathered. A record outside the set is
     * refused: a world that silently held an instance of an unlisted module
     * would publish an identity that does not describe what the checker saw.
     *
     * @param list<array<string, mixed>> $records
     * @param list<string> $modules
     */
    public static function over(array $records, array $modules): self
    {
        $modules = \array_values(\array_unique(\array_map(
            static fn (mixed $name): string => (string) $name,
            $modules,
        )));
        \sort($modules);
        $position = \array_flip($modules);

        $ordered = [];
        $lines = ['modules:' . \implode(',', $modules)];
        foreach ($records as $record) {
            $module = (string) ($record['module'] ?? '');
            if (! isset($position[$module])) {
                throw new \LogicException(
                    "the instance world was given a record of `{$module}`, which is not in its module set",
                );
            }
            $ordered[] = [$position[$module], \count($ordered), $record];
            $lines[] = canonicalInstanceRecord($record);
        }

        \usort($ordered, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);
        \sort($lines);

        return new self(
            \array_map(static fn (array $entry): array => $entry[2], $ordered),
            new InstanceWorldId('sha256:' . \hash('sha256', \implode("\n", $lines)), $modules, \count($records)),
        );
    }
}

/**
 * One record's canonical text: the facts that make an instance visible, in a
 * form that does not depend on the process that rendered it.
 *
 * Positions, doc comments and method bodies are deliberately absent. The world
 * says which instances exist and what they claim (class, head, constraints,
 * associated equations, intrinsic methods); the bodies belong to the module that
 * declares them and are elaborated there.
 *
 * @param array<string, mixed> $record
 */
function canonicalInstanceRecord(array $record): string
{
    $equations = [];
    foreach ($record['associatedEquations'] ?? [] as $name => $equation) {
        $equations[(string) $name] = canonicalTypeText($equation['lhsArgs'] ?? [])
            . '=' . canonicalTypeText($equation['rhs'] ?? null);
    }
    \ksort($equations);

    $intrinsics = \array_map(static fn (mixed $name): string => (string) $name, \array_values($record['intrinsicMethods'] ?? []));
    \sort($intrinsics);

    return \implode("\0", [
        (string) ($record['module'] ?? ''),
        (string) ($record['class'] ?? ''),
        canonicalTypeText($record['instanceHead'] ?? null),
        canonicalTypeText($record['constraints'] ?? []),
        \implode(',', $intrinsics),
        \implode(',', \array_map(
            static fn (string $name): string => $name . '=' . $equations[$name],
            \array_keys($equations),
        )),
        (($record['fromDeriving'] ?? false) === true) ? 'derived' : 'declared',
    ]);
}

/** The stable text of a type-valued field: surface type nodes, lists and scalars. */
function canonicalTypeText(mixed $value): string
{
    if ($value instanceof Ast\TypeNode) {
        return Ast\dumpTypeInline($value);
    }

    if (\is_array($value)) {
        $parts = [];
        foreach ($value as $key => $entry) {
            $parts[] = (\is_string($key) ? $key . ':' : '') . canonicalTypeText($entry);
        }

        return '[' . \implode(',', $parts) . ']';
    }

    if (\is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return $value === null ? 'null' : (string) $value;
}
