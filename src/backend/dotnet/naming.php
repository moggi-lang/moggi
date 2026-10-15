<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Naming;

use function Moggi\Backend\DotNet\Dependencies\dotNetTypeAssembly;
use function Moggi\Paths\moduleNameToPath;

/**
 * Moggi module name → CLR type name (e.g. Data.String → Moggi.Data.String).
 * One module → one sealed static class under the Moggi namespace root.
 */
function moduleTypeName(string $moduleName): string
{
    if ($moduleName === '') {
        return 'Moggi.Main';
    }

    return 'Moggi.' . $moduleName;
}

/** Relative path for a given CLR type's `.il` artifact (`Moggi.Data.String` → `Moggi\Data\String.il`). */
function typeArtifactPath(string $typeName): string
{
    return moduleNameToPath($typeName) . '.il';
}

/** Relative path for a module's primary `.il` artifact (e.g. `Moggi/Data/String.il`). */
function artifactPath(string $moduleName): string
{
    return typeArtifactPath(moduleTypeName($moduleName));
}

/**
 * Relative path of the shared Fn-wrapper bundle (`Moggi/Fn/__wrappers__.il`).
 *
 * One file for every `Moggi.Fn.*` wrapper class in the program instead of one
 * file per class (see {@see \Moggi\Backend\DotNet\DotNetBackend::foldProgramEmit}).
 * No module can claim this path: module segments are `ConId`s, so a segment
 * can never start with an underscore.
 */
function fnWrapperBundlePath(): string
{
    return \implode(DIRECTORY_SEPARATOR, ['Moggi', 'Fn', '__wrappers__.il']);
}

/**
 * Whether an emit-map path is a per-function Fn wrapper (`Moggi/Fn/<tag>_<arity>.il`).
 *
 * The trailing `_<arity>` is what keeps a real module out: `Moggi/Fn/Foo.il`
 * (module `Fn.Foo`) does not match, so it keeps its own file. A module named
 * like a wrapper (`Fn.Foo_2`) would fold — harmless, its one class is still
 * emitted exactly once — but no sane module is named that.
 */
function isFnWrapperArtifact(string $path): bool
{
    $flat = \str_replace('\\', '/', $path);

    return \str_starts_with($flat, 'Moggi/Fn/')
        && \preg_match('/_[0-9]+\.il$/', $flat) === 1;
}

/**
 * Mangle a Moggi binding to a legal CLR member name.
 *
 * The CLR accepts almost any UTF-8 member name; exotic identifiers are
 * hex-escaped so ildasm output stays readable and C#-legal.
 *
 * ILAsm has a large reserved-word grammar (`error`, `value`, `method`, opcode
 * mnemonics like `add`/`div`, …) — unquoted Moggi names could break assembly.
 * Every identifier is emitted as a single-quoted SQSTRING (`'name'`), which
 * ILAsm accepts anywhere a DottedName/Id is expected.
 */
function symbolName(string $name): string
{
    if ($name === '') {
        return "'_'";
    }
    if (\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1) {
        return "'{$name}'";
    }

    return "'_m_" . \bin2hex($name) . "'";
}

/**
 * The CLR member name an ILASM identifier carries at runtime.
 *
 * ILASM quoted identifiers (`'error'`) and hex-escaped ones are syntax only:
 * reflection reports the unquoted name.
 */
function runtimeMemberName(string $ilName): string
{
    if (\strlen($ilName) >= 2 && $ilName[0] === "'" && \str_ends_with($ilName, "'")) {
        return \substr($ilName, 1, -1);
    }

    return $ilName;
}

/**
 * Assembly that defines a well-known BCL type, for AssemblyRef generation.
 *
 * .NET Core splits the BCL across facades: System.Runtime forwards most of
 * CoreLib, but Console, BigInteger and the collection types live elsewhere and
 * a TypeRef scoped to the wrong AssemblyRef fails to resolve at runtime.
 *
 * Types whose assembly is not a well-known BCL facade get their AssemblyRef
 * from library-owned .NET metadata (`<module-dir>/dotnet/*.json`, mirrored
 * under `meta/` for the stdlib package), which declares CLR type → assembly.
 * No assembly knowledge lives in the compiler.
 */
function bclAssemblyFor(string $fullTypeName): string
{
    static $exact = null;
    if ($exact === null) {
        $exact = [
            'System.Console' => 'System.Console',
            'System.Numerics.BigInteger' => 'System.Runtime.Numerics',
            'System.Numerics.Complex' => 'System.Runtime.Numerics',
            'System.Collections.Generic.List`1' => 'System.Collections',
            'System.Collections.Generic.Dictionary`2' => 'System.Collections',
            'System.Collections.Generic.HashSet`1' => 'System.Collections',
            'System.Collections.Generic.Stack`1' => 'System.Collections',
            'System.Collections.Generic.Queue`1' => 'System.Collections',
            'System.Collections.Generic.KeyValuePair`2' => 'System.Runtime',
            'System.Collections.Generic.EqualityComparer`1' => 'System.Runtime',
            'System.Linq.Enumerable' => 'System.Linq',
        ];
    }

    if (isset($exact[$fullTypeName])) {
        return $exact[$fullTypeName];
    }

    $declared = dotNetTypeAssembly($fullTypeName);
    if ($declared !== null) {
        return $declared;
    }

    return 'System.Runtime';
}
