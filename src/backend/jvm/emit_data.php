<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\Backend\Jvm\Classfile\ClassBuilder;
use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;
use Moggi\IR;
use function Moggi\Backend\Jvm\Naming\symbolName;
use function Moggi\Backend\Meta\isBoolBackedData;

function emitDataDecl(ClassBuilder $b, IR\DataDecl $decl): void
{
    if (isBoolBackedData($decl)) {
        $falseName = symbolName($decl->constructors[0]->name);
        $trueName = symbolName($decl->constructors[1]->name);
        $b->addMethod(
            $falseName,
            '()Ljava/lang/Object;',
            0x0009,
            0,
            [],
            static function (CodeBuilder $c, ConstantPool $cp): void {
                $c->iconst(0);
                $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
                $c->areturn();
            }
        );
        $b->addMethod(
            $trueName,
            '()Ljava/lang/Object;',
            0x0009,
            0,
            [],
            static function (CodeBuilder $c, ConstantPool $cp): void {
                $c->iconst(1);
                $c->invokestatic($cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);
                $c->areturn();
            }
        );

        return;
    }

    if ($decl->isNewtype) {
        $ctor = $decl->constructors[0];
        $name = symbolName($ctor->name);
        $b->addMethod(
            $name,
            '(Ljava/lang/Object;)Ljava/lang/Object;',
            0x0009,
            1,
            ['java/lang/Object'],
            static function (CodeBuilder $c, ConstantPool $cp): void {
                $c->aload(0);
                $c->areturn();
            }
        );

        return;
    }

    foreach ($decl->constructors as $ctor) {
        $name = symbolName($ctor->name);
        $arity = \count($ctor->fields);
        $desc = '(' . \str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
        $b->addMethod(
            $name,
            $desc,
            0x0009,
            \max($arity + 2, 4),
            \array_fill(0, $arity, 'java/lang/Object'),
            static function (CodeBuilder $c, ConstantPool $cp) use ($ctor, $arity): void {
                $c->ldc($cp->string_($ctor->name));
                $c->iconst($arity);
                $c->anewarray($cp->class_('java/lang/Object'));
                for ($i = 0; $i < $arity; ++$i) {
                    $c->dup();
                    $c->iconst($i);
                    $c->aload($i);
                    $c->aastore();
                }
                $c->invokestatic($cp->methodRef('moggi/rt/RT', 'con', '(Ljava/lang/String;[Ljava/lang/Object;)Lmoggi/rt/Con;'), 2, true);
                $c->areturn();
            }
        );
    }
}

