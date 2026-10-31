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
 * The instance world for one compilation.
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
     * The checker index.
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
     * Build from prepared units
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
     * Build from collected records.
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
 * Canonical instance record text.
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
