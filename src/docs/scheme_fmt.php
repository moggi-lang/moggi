<?php declare(strict_types=1);

namespace Moggi\Docs;

use Moggi\Docs\DocIndex;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\TArrow;
use Moggi\Semantics\TypeExpr\TCon;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Syntax\Ast;

use function Moggi\Docs\search;
use function Moggi\Semantics\Types\friendlyTypeVarNames;
use function Moggi\Semantics\Types\internalTypeToAst;
use function Moggi\Syntax\Ast\dumpTypeInline;
use function Moggi\Syntax\isTypeOperator;

/**
 * Render a surface type with friendly type-variable names (t71 → a).
 *
 * @param array<string, string> $rename
 */
function dumpTypeForDocs(Ast\TypeNode $node, array $rename = []): string
{
    if ($rename === []) {
        $rename = friendlyAstTypeVarNames([$node]);
    }

    return dumpAstTypeForDocs($node, $rename);
}

/**
 * `Name :: Type` block with friendly type variables, or just `Type` when the
 * name is unknown. Arrow arguments are parenthesized so nested functions
 * render unambiguously (`compose` shows (a -> b) -> (c -> a) -> c -> b).
 */
function nameTypeSignature(?string $name, ?Ast\TypeNode $type): ?string
{
    if ($type === null) {
        return null;
    }
    $body = dumpTypeForDocs($type);
    if ($body === '') {
        return null;
    }

    return $name !== null && $name !== '' ? "{$name} :: {$body}" : $body;
}

/**
 * The type to show for a declaration: the signature it spells, written or
 * inferred. An inferred one is kept as a signature (`Num a => a -> a`) rather
 * than the internal dictionary-arrow form the checker works with
 * (`__Dict_Num -> t -> t`), which is what a reader would otherwise see.
 */
function declarationTypeSignature(Ast\FunctionDecl $decl): ?string
{
    return surfaceTypeSignature($decl->inferredSignatureType ?? $decl->type);
}

/**
 * @return array{0: Type, 1: list<string>}
 */
function peelLeadingDictClasses(Type $type): array
{
    $classes = [];
    while ($type instanceof TArrow
        && $type->from instanceof TCon
        && str_starts_with($type->from->name, '__Dict_')) {
        $classes[] = substr($type->from->name, strlen('__Dict_'));
        $type = $type->to;
    }

    return [$type, $classes];
}

function schemeToString(Scheme $scheme): string
{
    [$type, $dictClasses] = peelLeadingDictClasses($scheme->type);
    $rename = friendlyTypeVarNames([$type]);
    $body = internalTypeForDocs($type, $rename);

    $parts = [];
    foreach ($scheme->constraints as $constraint) {
        $argStr = join(' ', \array_map(
            static fn ($arg) => internalTypeForDocs($arg, $rename),
            $constraint->args,
        ));
        $parts[] = $constraint->class . ($argStr !== '' ? ' ' . $argStr : '');
    }

    foreach ($dictClasses as $class) {
        $label = $class;
        if (!\in_array($label, $parts, true)) {
            $parts[] = $label;
        }
    }

    if ($parts === []) {
        return $body;
    }

    return implode(', ', $parts) . ' => ' . $body;
}

/**
 * Render an internal (typechecker) type for documentation. Function types in
 * argument position are parenthesized, so a primop like `fix#` shows
 * `(a -> a) -> a` rather than the ambiguous `a -> a -> a`.
 *
 * @param array<string, string> $rename
 */
function internalTypeForDocs(Type $type, array $rename = []): string
{
    return dumpAstTypeForDocs(internalTypeToAst($type, []), $rename);
}

function surfaceTypeSignature(?Ast\TypeNode $type): ?string
{
    if ($type === null) {
        return null;
    }

    if ($type instanceof Ast\TypeConstrained) {
        $rename = friendlyAstTypeVarNames([$type]);

        return dumpAstTypeForDocs($type, $rename);
    }

    [$body, $dictClasses] = peelLeadingDictClassesFromAst($type);
    if ($dictClasses !== []) {
        $rename = friendlyAstTypeVarNames([$body]);

        return implode(', ', $dictClasses) . ' => ' . dumpAstTypeForDocs($body, $rename);
    }

    $rename = friendlyAstTypeVarNames([$type]);

    return dumpAstTypeForDocs($type, $rename);
}

/**
 * @param list<Ast\TypeNode> $types
 * @return array<string, string>
 */
function friendlyAstTypeVarNames(array $types): array
{
    $userVars = [];
    $freshOrder = [];

    $collect = function (Ast\TypeNode $type) use (&$collect, &$userVars, &$freshOrder): void {
        if ($type instanceof Ast\TypeVar) {
            if (preg_match('/^t\d+$/', $type->name) !== 1) {
                $userVars[$type->name] = true;
            } elseif (!\in_array($type->name, $freshOrder, true)) {
                $freshOrder[] = $type->name;
            }

            return;
        }

        if ($type instanceof Ast\TypeArrow) {
            $collect($type->from);
            $collect($type->to);

            return;
        }

        if ($type instanceof Ast\TypeApp) {
            $collect($type->con);
            foreach ($type->args as $arg) {
                $collect($arg);
            }

            return;
        }

        if ($type instanceof Ast\TypeConstrained) {
            foreach ($type->constraints as $constraint) {
                $collect($constraint);
            }
            $collect($type->body);
        }
    };

    foreach ($types as $type) {
        $collect($type);
    }

    $rename = [];
    $next = 0;
    foreach ($freshOrder as $fresh) {
        do {
            $letter = astTypeVarLetter($next++);
        } while (isset($userVars[$letter]));
        $rename[$fresh] = $letter;
    }

    return $rename;
}

function astTypeVarLetter(int $index): string
{
    $letter = chr(97 + ($index % 26));
    $suffix = intdiv($index, 26);

    return $suffix === 0 ? $letter : $letter . $suffix;
}

/**
 * @param array<string, string> $rename
 */
function dumpAstTypeForDocs(Ast\TypeNode $node, array $rename = []): string
{
    return match ($node::class) {
        Ast\TypeVar::class => $rename[$node->name] ?? $node->name,
        Ast\TypeUnit::class => '()',
        Ast\TypeCon::class => $node->name,
        Ast\TypePromoted::class => "'" . $node->name,
        Ast\TypeStringLit::class => json_encode($node->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        Ast\TypeNatLit::class => ($node->negative ? '-' : '') . $node->digits,
        Ast\TypeQualified::class => $node->module . '.' . $node->name,
        Ast\TypeApp::class => (static function () use ($node, $rename): string {
            if (
                $node->con instanceof Ast\TypeCon
                && $node->con->name === 'List'
                && count($node->args) === 1
            ) {
                return '[' . dumpAstTypeForDocs($node->args[0], $rename) . ']';
            }

            if (
                $node->con instanceof Ast\TypeCon
                && isTypeOperator($node->con->name)
                && count($node->args) === 2
            ) {
                return dumpAstTypeForDocs($node->args[0], $rename) . ' ' . $node->con->name . ' '
                    . dumpAstTypeForDocs($node->args[1], $rename);
            }

            return dumpAstTypeForDocs($node->con, $rename) . ' ' . join(' ', \array_map(
                static fn (Ast\TypeNode $arg): string => dumpAstTypeAtom($arg, $rename),
                $node->args,
            ));
        })(),
        Ast\TypeArrow::class => dumpAstTypeAtom($node->from, $rename) . ' -> '
            . dumpAstTypeForDocs($node->to, $rename),
        Ast\TypeConstrained::class => join(', ', \array_map(
            static fn (Ast\TypeNode $constraint): string => dumpAstTypeForDocs($constraint, $rename),
            $node->constraints,
        )) . ' => ' . dumpAstTypeForDocs($node->body, $rename),
        default => dumpTypeInline($node),
    };
}

/**
 * Render a type in argument position: function-type arguments are
 * parenthesized so `(a -> b) -> c` cannot be misread as `a -> b -> c`.
 *
 * @param array<string, string> $rename
 */
function dumpAstTypeAtom(Ast\TypeNode $node, array $rename = []): string
{
    $s = dumpAstTypeForDocs($node, $rename);
    if ($node instanceof Ast\TypeArrow || $node instanceof Ast\TypeConstrained) {
        return '(' . $s . ')';
    }

    return $s;
}

/**
 * @return array{0: Ast\TypeNode, 1: list<string>}
 */
function peelLeadingDictClassesFromAst(Ast\TypeNode $type): array
{
    $classes = [];
    while ($type instanceof Ast\TypeArrow
        && $type->from instanceof Ast\TypeCon
        && str_starts_with($type->from->name, '__Dict_')) {
        $classes[] = substr($type->from->name, strlen('__Dict_'));
        $type = $type->to;
    }

    return [$type, $classes];
}

function astTypeUsesDictEncoding(?Ast\TypeNode $type): bool
{
    if ($type === null) {
        return false;
    }

    [$_, $dictClasses] = peelLeadingDictClassesFromAst($type);

    return $dictClasses !== [];
}

function dataTypeParamNames(Ast\DataDecl $decl): array
{
    return array_map(static fn (Ast\DataParam $param): string => $param->name, $decl->params);
}

function dataTypeHeadSignature(Ast\DataDecl $decl): string
{
    $kw = $decl->isNewtype ? 'newtype' : 'data';
    $head = $kw . ' ' . $decl->name;
    $params = dataTypeParamNames($decl);
    if ($params !== []) {
        $head .= ' ' . implode(' ', $params);
    }

    return $head;
}

function dataDeclSignature(Ast\DataDecl $decl): string
{
    $ctors = [];
    foreach ($decl->constructors as $ctor) {
        $ctors[] = constructorLhs($decl, $ctor);
    }

    return dataTypeHeadSignature($decl) . ' = ' . implode(' | ', $ctors);
}

function instanceParentTypeName(Ast\TypeNode $head): ?string
{
    if ($head instanceof Ast\TypeCon) {
        return $head->name;
    }
    if ($head instanceof Ast\TypeApp) {
        return instanceParentTypeName($head->con);
    }

    return null;
}

function instanceDeclSignature(Ast\InstanceDecl $decl): string
{
    $head = surfaceTypeSignature($decl->head);
    $constraints = [];
    foreach ($decl->constraints as $constraint) {
        $surface = surfaceTypeSignature($constraint);
        if ($surface !== null) {
            $constraints[] = $surface;
        }
    }

    $body = $decl->class . ($head !== null && $head !== '' ? ' ' . $head : '');
    if ($constraints !== []) {
        return 'instance ' . implode(', ', $constraints) . ' => ' . $body;
    }

    return 'instance ' . $body;
}

function instanceEntityName(Ast\InstanceDecl $decl): string
{
    $head = surfaceTypeSignature($decl->head);

    return $decl->class . ($head !== null && $head !== '' ? ' ' . $head : '');
}

function constructorLhs(Ast\DataDecl $decl, Ast\ConstructorDecl $ctor): string
{
    if ($ctor->fields === []) {
        return $ctor->name;
    }

    $parts = [];
    foreach ($ctor->fields as $field) {
        if ($field->name !== '') {
            $parts[] = $field->name;
        } else {
            $parts[] = surfaceTypeSignature($field->type) ?? '_';
        }
    }

    return $ctor->name . ' ' . implode(' ', $parts);
}

function constructorSignature(Ast\DataDecl $decl, Ast\ConstructorDecl $ctor): string
{
    $params = dataTypeParamNames($decl);
    $typeHead = $decl->name;
    if ($params !== []) {
        $typeHead .= ' ' . implode(' ', $params);
    }

    return constructorLhs($decl, $ctor) . ' :: ' . $typeHead;
}

/**
 * Look up a moogle doc-comment for a (possibly module-qualified) entity name
 * like `map`, `Data.Maybe.fromJust`, or `Maybe::Just`. Returns null when the
 * DocIndex is unavailable or the name is unknown, and never duplicates a doc
 * already present in $existing.
 *
 * @param list<string> $existing
 */
function docForEntityName(DocIndex $index, string $name, array $existing = []): ?string
{
    $candidates = [$name];
    // `Module.name` → also try bare `name`; `name` → also try `Module::name`.
    $dot = strrpos($name, '.');
    if ($dot !== false && $dot > 0 && $dot < strlen($name) - 1) {
        $candidates[] = substr($name, $dot + 1);
    } else {
        $parts = explode('::', $name);
        if (count($parts) === 2) {
            $candidates[] = $parts[1];
        }
    }

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        try {
            foreach (search($index, $candidate, 8) as $hit) {
                $e = $hit->entity;
                if ($e->name !== $candidate || $e->doc === null || $e->doc === '') {
                    continue;
                }
                if (in_array($e->doc, $existing, true)) {
                    return null;
                }

                return $e->doc;
            }
        } catch (\Throwable) {
            return null;
        }
    }

    return null;
}

/**
 * Constructor use-site signature: `Just :: a -> Maybe a`. Named fields render
 * as labels (mirroring `constructorLhs`), anonymous fields as their types.
 */
function constructorTypeSignature(Ast\DataDecl $decl, Ast\ConstructorDecl $ctor): ?string
{
    $params = dataTypeParamNames($decl);
    $typeHead = $decl->name;
    if ($params !== []) {
        $typeHead .= ' ' . implode(' ', $params);
    }
    $args = [];
    foreach ($ctor->fields as $field) {
        if ($field->name !== '') {
            $args[] = $field->name;
        } else {
            $args[] = surfaceTypeSignature($field->type) ?? '_';
        }
    }
    if ($args === []) {
        return "{$ctor->name} :: {$typeHead}";
    }

    return "{$ctor->name} :: " . implode(' -> ', $args) . " -> {$typeHead}";
}

function classParamNames(Ast\ClassDecl $decl): array
{
    return array_map(static fn (Ast\ClassParam $param): string => $param->name, $decl->params);
}

function classDeclHeader(Ast\ClassDecl $decl): string
{
    $head = 'class ';
    if ($decl->superclasses !== []) {
        $supers = [];
        foreach ($decl->superclasses as $sc) {
            $s = surfaceTypeSignature($sc);
            if ($s !== null) {
                $supers[] = $s;
            }
        }
        if ($supers !== []) {
            $head .= implode(', ', $supers) . ' => ';
        }
    }
    $head .= $decl->name;
    $params = classParamNames($decl);
    if ($params !== []) {
        $head .= ' ' . implode(' ', $params);
    }

    return $head . ' where';
}
