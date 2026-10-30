<?php declare(strict_types=1);

namespace Moggi\Foreign;

/**
 * The host types each module declares for its own Moggi names.
 *
 * A `foreign jvm type Path "…"` is one module's statement about one of its own names, so the
 * module is half the key: this corpus has `Path` declared against both `java.lang.String` and
 * `java.nio.file.Path`, and a bare-name map would let whichever module was checked last decide
 * what every reader after it sees.
 *
 * The table outlives a single module because the declarations are *checking* facts and the reads
 * are *emit* facts — the emitter sees IR, not the checker's state — so it belongs to the target
 * that was compiled for, and each backend object owns one ({@see \Moggi\Backend\Backend::foreignTypes()}).
 */
final class ForeignTypeTable
{
    /** @var array<string, array<string, string>> module => Moggi name => host type */
    private array $declarations = [];

    public function register(string $module, string $name, string $hostType): void
    {
        $this->declarations[$module][$name] = $hostType;
    }

    /**
     * Every module that declares `$name`, with the host type it declares.
     *
     * @return array<string, string> module => host type
     */
    public function declarationsOf(string $name): array
    {
        $found = [];
        foreach ($this->declarations as $module => $declarations) {
            if (isset($declarations[$name])) {
                $found[$module] = $declarations[$name];
            }
        }

        return $found;
    }

    /**
     * The host type `$name` stands for, read from the module that declared it.
     *
     * With a module, that module's declaration and nothing else; re-checking the same module — what
     * the REPL and LSP do on every keystroke — replaces its entry instead of colliding with it.
     * Without one, the answer must be the same in every declaring module, and a real cross-module
     * disagreement is refused rather than resolved by whichever module ran last.
     */
    public function lookup(string $name, ?string $module, string $target): ?string
    {
        if ($module !== null) {
            return $this->declarations[$module][$name] ?? null;
        }

        $byModule = $this->declarationsOf($name);
        if ($byModule === []) {
            return null;
        }

        $hosts = \array_values(\array_unique($byModule));
        if (\count($hosts) > 1) {
            $said = [];
            foreach ($byModule as $declaringModule => $host) {
                $said[] = "`{$declaringModule}` says {$host}";
            }

            throw new \RuntimeException(
                "foreign {$target} type `{$name}` is declared with different host types ("
                . \implode('; ', $said)
                . '); a read with no module to answer from cannot pick one',
            );
        }

        return $hosts[0];
    }

    /**
     * Every host type any module declares.
     *
     * For set membership — whether an internal name is declared as an interface anywhere — where
     * the declaring module is not part of the question.
     *
     * @return list<string>
     */
    public function hostTypes(): array
    {
        $hosts = [];
        foreach ($this->declarations as $declarations) {
            foreach ($declarations as $host) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
