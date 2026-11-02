<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\TVar;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Semantics\TypeExpr\scheme;

/**
 * Give every named field of an ordinary record a selector, and remember how to
 * build its declaration.
 *
 * A selector is an ordinary, typed function (`getProduct :: Product a -> a`), so
 * it reaches inference, imports, exports and code generation the way any other
 * binding does. The receiver is taken apart by the constructor the field belongs
 * to, which is what gives the selector its type without a hand-written
 * signature.
 *
 * A field whose name an imported binding already holds keeps that binding and
 * gets no selector here: field names are a namespace of their own (`x.field`
 * resolves on the receiver's type), and a name that is already a value cannot
 * be replaced by one silently. A field only some of a type's constructors carry
 * gets a selector too, one that takes the receiver apart by the constructor the
 * field belongs to; building it on any other constructor is a match failure, so
 * the declaration stays honest about which values it accepts. A GADT constructor's fields are left out, since
 * its selector type may mention variables the result type does not fix.
 */
function registerRecordFieldSelectors(TypeCheckState $state, Ast\DataDecl $decl): void
{
    $selectors = [];
    $params = $state->data[$decl->name]['params'];

    foreach ($state->data[$decl->name]['paramKinds'] ?? [] as $kind) {
        if (! $kind instanceof Kinds\KType) {
            $state->data[$decl->name]['fieldSelectors'] = $selectors;

            return;
        }
    }

    $paramTypes = [];
    foreach ($params as $param) {
        $paramTypes[] = new TVar($param);
    }
    $result = new TCon($decl->name, $paramTypes, ($state->data[$decl->name]['result'] ?? null)?->module);

    $byField = [];
    $constructorCount = 0;
    foreach ($state->data[$decl->name]['constructors'] as $ctorName => $ctor) {
        if (isset($ctor['aliasOf']) || ($ctor['gadt'] ?? false)) {
            continue;
        }

        ++$constructorCount;
        foreach ($ctor['fields'] as $index => $fieldName) {
            if ($fieldName === null || $fieldName === '' || \str_starts_with($fieldName, '$')) {
                continue;
            }

            $type = $ctor['fieldTypes'][$index];
            if (isset($byField[$fieldName])) {
                if (typeToString($byField[$fieldName]['type']) !== typeToString($type)) {
                    throw typeFail(
                        $state,
                        "duplicate record field `{$fieldName}`: `{$decl->name}` gives it two types",
                        $decl,
                    );
                }

                $byField[$fieldName]['cases'][] = [
                    'constructor' => $ctorName,
                    'index' => $index,
                    'fieldCount' => \count($ctor['fields']),
                ];

                continue;
            }

            $byField[$fieldName] = [
                'type' => $type,
                'cases' => [[
                    'constructor' => $ctorName,
                    'index' => $index,
                    'fieldCount' => \count($ctor['fields']),
                ]],
            ];
        }
    }

    foreach ($byField as $fieldName => $field) {
        $first = $field['cases'][0];
        $selectors[$fieldName] = [
            'constructor' => $first['constructor'],
            'index' => $first['index'],
            'fieldCount' => $first['fieldCount'],
            'cases' => $field['cases'],
            'type' => $field['type'],
            'total' => \count($field['cases']) === $constructorCount,
            'generated' => false,
        ];

        if (isset($state->env[$fieldName])) {
            continue;
        }

        $state->env[$fieldName] = scheme(new TArrow($result, $field['type']), $params);
        $selectors[$fieldName]['generated'] = true;
    }

    $state->data[$decl->name]['fieldSelectors'] = $selectors;
}

/**
 * The selector declarations `registerRecordFieldSelectors` promised, for the
 * fields no existing binding already names.
 *
 * @param array<string, true> $declared elsewhere in the module, by hand
 * @return list<Ast\FunctionDecl>
 */
function recordFieldSelectorDecls(TypeCheckState $state, Ast\DataDecl $decl, array $declared = []): array
{
    $decls = [];
    foreach ($state->data[$decl->name]['fieldSelectors'] ?? [] as $fieldName => $info) {
        if (! $info['generated'] || isset($declared[$fieldName])) {
            continue;
        }

        $alts = [];
        foreach ($info['cases'] as $case) {
            $args = [];
            for ($i = 0; $i < $case['fieldCount']; ++$i) {
                $args[] = $i === $case['index'] ? new Ast\PatVar('__value') : new Ast\PatWild();
            }
            $alts[] = new Ast\Alt(new Ast\PatCon($case['constructor'], $args), new Ast\Variable('__value'));
        }

        $body = new Ast\CaseExpr(new Ast\Variable('__record'), $alts);
        $body->exhaustiveCheck = $info['total'];

        $decls[] = new Ast\FunctionDecl(
            $fieldName,
            null,
            [new Ast\PatVar('__record')],
            $body,
            line: $decl->line ?? 0,
            col: $decl->col ?? 0,
            endCol: $decl->endCol ?? 0,
        );
    }

    return $decls;
}

function recordFieldNames(TypeCheckState $state, string $dataName): array
{
    $names = [];
    foreach ($state->data[$dataName]['constructors'] ?? [] as $ctor) {
        foreach ($ctor['fields'] as $name) {
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }
    }

    return \array_values(\array_unique($names));
}

function allFieldNames(TypeCheckState $state): array
{
    $names = [];
    foreach ($state->data as $data) {
        foreach ($data['constructors'] as $ctor) {
            foreach ($ctor['fields'] as $name) {
                if ($name !== null && $name !== '') {
                    $names[] = $name;
                }
            }
        }
    }

    return \array_values(\array_unique($names));
}

function fieldCandidates(TypeCheckState $state, string $fieldName): array
{
    $candidates = [];
    foreach ($state->data as $dataName => $data) {
        foreach ($data['constructors'] as $ctor) {
            foreach ($ctor['fields'] as $index => $name) {
                if ($name === $fieldName) {
                    $candidates[] = [
                        'dataName' => $dataName,
                        'fieldIndex' => $index,
                        'type' => $ctor['fieldTypes'][$index],
                    ];
                }
            }
        }
    }

    return $candidates;
}

function lookupConstructorMeta(TypeCheckState $state, string $ctorName): ?array
{
    foreach ($state->data as $dataName => $data) {
        if (! isset($data['constructors'][$ctorName])) {
            continue;
        }

        return ['dataName' => $dataName, ...$data['constructors'][$ctorName]];
    }

    return null;
}

/** Records where the field lives, so IR lowering reads the position the receiver's type gives it. */
function annotateFieldAccess(
    TypeCheckState $state,
    Ast\FieldAccess $expr,
    string $dataName,
    int $index,
): void {
    $expr->fieldIndex = $index;
    $expr->fieldOnNewtype = (bool) ($state->data[$dataName]['newtype'] ?? false);
}

function lookupFieldOnType(TypeCheckState $state, string $dataName, string $fieldName): ?array
{
    $data = $state->data[$dataName] ?? null;
    if ($data === null) {
        return null;
    }

    foreach ($data['constructors'] as $ctor) {
        foreach ($ctor['fields'] as $index => $name) {
            if ($name === $fieldName) {
                return ['fieldIndex' => $index, 'type' => $ctor['fieldTypes'][$index]];
            }
        }
    }

    return null;
}

function freshDataTypeFromName(TypeCheckState $state, string $dataName): Type
{
    $data = $state->data[$dataName];
    $paramTypes = \array_map(static fn (string $_): Type => freshType($state), $data['params']);

    return new TCon($dataName, $paramTypes, ($data['result'] ?? null)?->module);
}

function instantiateFieldTypeForObject(TypeCheckState $state, Type $objectType, Type $fieldType): Type
{
    $objectType = prune($state, $objectType);
    if (! $objectType instanceof TCon || ! isset($state->data[$objectType->name])) {
        return $fieldType;
    }

    $mapping = [];
    foreach ($state->data[$objectType->name]['params'] as $i => $param) {
        if (isset($objectType->args[$i])) {
            $mapping[$param] = $objectType->args[$i];
        }
    }

    return substitute($fieldType, $mapping);
}

function recordValueFields(TypeCheckState $state, Ast\RecordCon $expr, array $meta): array
{
    $byName = [];
    foreach ($expr->fields as $field) {
        if (isset($byName[$field->name])) {
            throw typeFail($state, "duplicate field `{$field->name}` in record construction", $expr);
        }
        $byName[$field->name] = $field->expr;
    }

    foreach ($meta['fields'] as $fieldName) {
        if (\str_starts_with($fieldName, '$')) {
            continue;
        }
        if (! isset($byName[$fieldName])) {
            throw typeFail($state, "missing field `{$fieldName}` in record construction", $expr);
        }
    }

    foreach (\array_keys($byName) as $extra) {
        if (! \in_array($extra, $meta['fields'], true) || \str_starts_with($extra, '$')) {
            throw typeFail($state, "unknown field `{$extra}` in record construction", $expr);
        }
    }

    return $byName;
}
