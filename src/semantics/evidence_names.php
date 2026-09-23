<?php declare(strict_types=1);

namespace Moggi\Semantics\Types;

use Moggi\IR;
use Moggi\Syntax\Ast;

function evidenceFunctionName(string $className, Ast\TypeNode $head): string
{
    return '__ev_' . $className . '_' . evidenceHeadSuffix($head);
}

function evidenceHeadSuffix(Ast\TypeNode $head): string
{
    if (isSimpleEvidenceHead($head)) {
        if ($head instanceof Ast\TypeApp && $head->con instanceof Ast\TypeCon) {
            return sanitizeEvidenceNamePart($head->con->name);
        }

        return sanitizeEvidenceNamePart($head->name);
    }

    return 'h_' . substr(hash('sha256', canonicalTypeKey($head)), 0, 16);
}

function isSimpleEvidenceHead(Ast\TypeNode $head): bool
{
    if ($head instanceof Ast\TypeCon
        && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $head->name) === 1
    ) {
        return true;
    }

    // Polymorphic apps like `Tuple64 a1 … a64` / `Maybe a`: the constructor
    // name alone distinguishes same-class instances; avoid sha256(json) of the
    // full head on every colliding-method uniquify.
    if ($head instanceof Ast\TypeApp && $head->con instanceof Ast\TypeCon) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $head->con->name) !== 1) {
            return false;
        }
        foreach ($head->args as $arg) {
            if (!$arg instanceof Ast\TypeVar) {
                return false;
            }
        }

        return true;
    }

    return false;
}

function sanitizeEvidenceNamePart(string $name): string
{
    $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? 'Type';
    if (preg_match('/^[A-Za-z_]/', $safe) !== 1) {
        $safe = '_' . $safe;
    }

    return $safe;
}

/** IR name for an instance method so multiple instances in one module do not collide. */
function instanceMethodIrName(string $className, Ast\TypeNode $head, string $methodName): string
{
    return evidenceFunctionName($className, $head) . '_' . instanceMethodIrSuffix($methodName);
}

/**
 * Identifier-safe suffix for a method name. Known operators get readable names;
 * any other operator is byte-encoded so distinct operators (`===` vs `/==`)
 * cannot both sanitize to the same underscores.
 */
function instanceMethodIrSuffix(string $methodName): string
{
    $known = match ($methodName) {
        '==' => 'eq',
        '/=' => 'ne',
        '<>' => 'concat',
        '<' => 'lt',
        '<=' => 'lte',
        '>' => 'gt',
        '>=' => 'gte',
        '&&' => 'and',
        '||' => 'or',
        '+' => 'plus',
        '-' => 'minus',
        '*' => 'mul',
        '/' => 'div',
        default => null,
    };
    if ($known !== null) {
        return $known;
    }
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $methodName) === 1) {
        return $methodName;
    }

    $suffix = '';
    foreach (str_split($methodName) as $char) {
        $suffix .= preg_match('/[A-Za-z0-9]/', $char) === 1
            ? $char
            : '_' . dechex(ord($char));
    }

    return $suffix;
}

function canonicalTypeKey(Ast\TypeNode $type): string
{
    $normalize = static function (Ast\TypeNode $node) use (&$normalize): array {
        return match ($node::class) {
            Ast\TypeCon::class => [
                'tag' => 'type_con',
                'name' => $node->name,
                'args' => [],
            ],
            Ast\TypeVar::class => ['tag' => 'type_var', 'name' => $node->name],
            Ast\TypeApp::class => [
                'tag' => 'type_app',
                'con' => $normalize($node->con),
                'args' => \array_map($normalize, $node->args),
            ],
            Ast\TypeArrow::class => [
                'tag' => 'type_arrow',
                'from' => $normalize($node->from),
                'to' => $normalize($node->to),
            ],
            Ast\TypeStringLit::class => [
                'tag' => 'type_string_lit',
                'value' => $node->value,
            ],
            Ast\TypeNatLit::class => [
                'tag' => 'type_nat_lit',
                'digits' => $node->negative ? '-' . $node->digits : $node->digits,
            ],
            Ast\TypePromoted::class => [
                'tag' => 'type_promoted',
                'name' => $node->name,
            ],
            default => ['tag' => surfaceTypeTag($node)],
        };
    };

    return json_encode($normalize($type), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ?: serialize($type);
}

function typeAstToArray(Ast\TypeNode $type): array
{
    return match ($type::class) {
        Ast\TypeCon::class => ['tag' => 'type_con', 'name' => $type->name],
        Ast\TypeVar::class => ['tag' => 'type_var', 'name' => $type->name],
        Ast\TypeApp::class => [
            'tag' => 'type_app',
            'con' => typeAstToArray($type->con),
            'args' => \array_map(typeAstToArray(...), $type->args),
        ],
        Ast\TypeArrow::class => [
            'tag' => 'type_arrow',
            'from' => typeAstToArray($type->from),
            'to' => typeAstToArray($type->to),
        ],
        Ast\TypeStringLit::class => [
            'tag' => 'type_string_lit',
            'value' => $type->value,
        ],
        Ast\TypeNatLit::class => [
            'tag' => 'type_nat_lit',
            'digits' => $type->negative ? '-' . $type->digits : $type->digits,
        ],
        Ast\TypePromoted::class => [
            'tag' => 'type_promoted',
            'name' => $type->name,
        ],
        default => ['tag' => surfaceTypeTag($type)],
    };
}

/**
 * Wire tag for the surface type AST nodes that carry no payload the evidence
 * naming scheme needs to distinguish.
 */
function surfaceTypeTag(Ast\TypeNode $type): string
{
    return match ($type::class) {
        Ast\TypeUnit::class => 'type_unit',
        Ast\TypeConstrained::class => 'type_constrained',
        Ast\TypeQualified::class => 'type_qualified',
        default => throw new \InvalidArgumentException('unsupported type AST node `' . $type::class . '`'),
    };
}

/** @return IR\FnRef */
function evidenceRefOperand(string $className, Ast\TypeNode $head): IR\FnRef
{
    return new IR\FnRef(
        evidenceFunctionName($className, $head),
        $className,
        typeAstToArray($head),
    );
}

function evidenceIs(IR\FnRef $evidence, string $className, string $typeName): bool
{
    $head = $evidence->evidenceHead;

    return $evidence->evidenceClass === $className
        && \is_array($head)
        && ($head['tag'] ?? null) === 'type_con'
        && ($head['name'] ?? null) === $typeName
        && ($head['args'] ?? []) === [];
}
