<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Naming;

/** Moggi module name → JVM internal class name (e.g. Data.String → moggi/Data/String). */
function moduleInternalName(string $moduleName): string
{
    if ($moduleName === '') {
        return 'moggi/Main';
    }

    return 'moggi/' . \str_replace('.', '/', $moduleName);
}

/** Relative path of a module's primary artifact (internal name + `.class`). */
function artifactPath(string $moduleName): string
{
    return moduleInternalName($moduleName) . '.class';
}

function symbolName(string $name): string
{
    if ($name === '') {
        return '_';
    }
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1
        && !isJavaKeyword($name)) {
        return $name;
    }

    return '_m_' . bin2hex($name);
}

function isJavaKeyword(string $name): bool
{
    static $kw = null;
    if ($kw === null) {
        $kw = \array_fill_keys([
            'abstract', 'assert', 'boolean', 'break', 'byte', 'case', 'catch', 'char', 'class',
            'const', 'continue', 'default', 'do', 'double', 'else', 'enum', 'extends', 'final',
            'finally', 'float', 'for', 'goto', 'if', 'implements', 'import', 'instanceof', 'int',
            'interface', 'long', 'native', 'new', 'package', 'private', 'protected', 'public',
            'return', 'short', 'static', 'strictfp', 'super', 'switch', 'synchronized', 'this',
            'throw', 'throws', 'transient', 'try', 'void', 'volatile', 'while', 'true', 'false', 'null',
            'var', 'record', 'sealed', 'permits', 'yield', 'when',
        ], true);
    }

    return isset($kw[$name]);
}
