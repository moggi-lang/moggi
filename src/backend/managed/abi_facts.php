<?php declare(strict_types=1);

namespace Moggi\Backend\Managed;

/**
 * The runtime protocol both managed targets implement, as facts rather than two independent copies.
 *
 * Each fact names the type in each language's spelling. The JVM renders classfiles and the CLR
 * renders ILASM text, so a fact is checked at the level each target actually produces: a classfile
 * key for the JVM, a type name in the text for .NET. Only types both runtimes always define are
 * facts here; the per-arity `StaticFn` family is rendered on demand from a program's arities.
 *
 * @return array<string, array{jvm: string, dotnet: string}>
 */
function protocolTypes(): array
{
    return [
        'Fn' => ['jvm' => 'moggi/rt/Fn', 'dotnet' => 'Moggi.Rt.Fn'],
        'Partial' => ['jvm' => 'moggi/rt/Partial', 'dotnet' => 'Moggi.Rt.Partial'],
        'Con' => ['jvm' => 'moggi/rt/Con', 'dotnet' => 'Moggi.Rt.Con'],
        'Dict' => ['jvm' => 'moggi/rt/Dict', 'dotnet' => 'Moggi.Rt.Dict'],
        'IO' => ['jvm' => 'moggi/rt/IO', 'dotnet' => 'Moggi.Rt.IO'],
        'MList' => ['jvm' => 'moggi/rt/MList', 'dotnet' => 'Moggi.Rt.MList'],
        'TopLevelFn' => ['jvm' => 'moggi/rt/TopLevelFn', 'dotnet' => 'Moggi.Rt.TopLevelFn'],
        'MoggiException' => ['jvm' => 'moggi/rt/MoggiException', 'dotnet' => 'Moggi.Rt.MoggiException'],
        'RT' => ['jvm' => 'moggi/rt/RT', 'dotnet' => 'Moggi.Rt.RT'],
    ];
}

/**
 * Protocol entries present in a rendered JVM runtime, keyed by internal name.
 *
 * @param array<string, string> $classes internal name => classfile bytes
 * @return list<string> the facts this runtime does not implement
 */
function missingJvmProtocol(array $classes): array
{
    $missing = [];
    foreach (protocolTypes() as $fact => $names) {
        if (! isset($classes[$names['jvm']])) {
            $missing[] = "{$fact} ({$names['jvm']})";
        }
    }

    return $missing;
}

/**
 * Protocol entries present in a rendered .NET runtime.
 *
 * @return list<string> the facts this runtime does not implement
 */
function missingDotNetProtocol(string $il): array
{
    $missing = [];
    foreach (protocolTypes() as $fact => $names) {
        if (! \str_contains($il, $names['dotnet'])) {
            $missing[] = "{$fact} ({$names['dotnet']})";
        }
    }

    return $missing;
}
