<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

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

    return new TCon($dataName, $paramTypes);
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
        if (! isset($byName[$fieldName])) {
            throw typeFail($state, "missing field `{$fieldName}` in record construction", $expr);
        }
    }

    foreach (\array_keys($byName) as $extra) {
        if (! \in_array($extra, $meta['fields'], true)) {
            throw typeFail($state, "unknown field `{$extra}` in record construction", $expr);
        }
    }

    return $byName;
}
