<?php declare(strict_types=1);

namespace Moggi\Backend\Php\Codegen;


use function Moggi\Backend\Meta\isBoolBackedData;
use function Moggi\Backend\Php\Naming\mangleVar;
use function Moggi\Backend\Php\Naming\phpFunctionName;
use function Moggi\Backend\Php\Naming\phpSingleQuoted;
use Moggi\IR;

/** @param array<string, string> $phpNames */
function emitDataDecl(IR\DataDecl $decl, array $phpNames): string
{
    $out = '';

    if (isBoolBackedData($decl)) {
        $falseName = phpFunctionName($decl->constructors[0]->name, $phpNames);
        $trueName = phpFunctionName($decl->constructors[1]->name, $phpNames);
        $out .= "function {$falseName}() {\n    return false;\n}\n\n";
        $out .= "function {$trueName}() {\n    return true;\n}\n\n";

        return $out;
    }

    if ($decl->isNewtype) {
        $ctor = $decl->constructors[0];
        $name = phpFunctionName($ctor->name, $phpNames);
        $param = '$' . mangleVar($ctor->fields[0] !== '' ? $ctor->fields[0] : 'v0');
        $out .= "function {$name}({$param}) {\n    return {$param};\n}\n\n";

        return $out;
    }

    foreach ($decl->constructors as $ctor) {
        $name = phpFunctionName($ctor->name, $phpNames);
        $params = \array_map(
            static fn (string $field, int $i): string => '$' . mangleVar($field !== '' ? $field : "v{$i}"),
            $ctor->fields,
            \array_keys($ctor->fields),
        );
        $paramsCode = \implode(', ', $params);
        $argsCode = \implode(', ', \array_map(static fn (string $p): string => '$' . \substr($p, 1), $params));

        $tag = phpSingleQuoted($ctor->name);

        if ($argsCode === '') {
            $out .= "function {$name}() {\n    return [{$tag}];\n}\n\n";

            continue;
        }

        $out .= "function {$name}({$paramsCode}) {\n    return [{$tag}, {$argsCode}];\n}\n\n";
    }

    return $out;
}

