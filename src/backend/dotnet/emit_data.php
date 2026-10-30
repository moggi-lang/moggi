<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\DotNet\Il\ilString;
use function Moggi\Backend\DotNet\Naming\symbolName;
use function Moggi\Backend\Meta\isBoolBackedData;

function emitDataDecl(ClassIlBuilder $b, IR\DataDecl $decl): void
{
    if (isBoolBackedData($decl)) {
        $falseName = symbolName($decl->constructors[0]->name);
        $trueName = symbolName($decl->constructors[1]->name);

        $mFalse = new MethodIl();
        $mFalse->emit('ldc.i4.0');
        $mFalse->emit('box bool');
        $mFalse->emit('ret');
        $b->addMethod($mFalse->render($falseName, []));

        $mTrue = new MethodIl();
        $mTrue->emit('ldc.i4.1');
        $mTrue->emit('box bool');
        $mTrue->emit('ret');
        $b->addMethod($mTrue->render($trueName, []));

        return;
    }

    if ($decl->isNewtype) {
        $ctor = $decl->constructors[0];
        $name = symbolName($ctor->name);
        $m = new MethodIl();
        $m->emit('ldarg 0');
        $m->emit('ret');
        $b->addMethod($m->render($name, ['object']));

        return;
    }

    foreach ($decl->constructors as $ctor) {
        $name = symbolName($ctor->name);
        $arity = \count($ctor->fields);
        $m = new MethodIl();
        $m->emit('ldstr ' . ilString($ctor->name));
        $m->emit("ldc.i4 {$arity}");
        $m->emit('newarr object');
        for ($i = 0; $i < $arity; ++$i) {
            $m->emit('dup');
            $m->emit("ldc.i4 {$i}");
            $m->emit("ldarg {$i}");
            $m->emit('stelem.ref');
        }
        $m->emit('call class Moggi.Rt.Con Moggi.Rt.RT::Con(string, object[])');
        $m->emit('ret');
        $b->addMethod($m->render($name, \array_fill(0, $arity, 'object')));
    }
}
