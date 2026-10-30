<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\IR;
use function Moggi\Backend\Jvm\Naming\symbolName;
use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\Debug\symbolId;

function pushBoxedInt(EmitEnv $env, int $v): void
{
    if ($v === 0 || $v === 1) {
        $env->c->lconst($v);
    } elseif ($v >= -32768 && $v <= 32767) {
        $env->c->iconst($v);
        $env->c->i2l();
    } else {
        $env->c->ldc2_w($env->cp->long($v));
    }
    boxInt($env);
}

function unboxInt(EmitEnv $env): void
{
    $env->c->checkcast($env->cp->class_('java/lang/Long'));
    $env->c->invokevirtual($env->cp->methodRef('java/lang/Long', 'longValue', '()J'), 0, 2);
}

function boxInt(EmitEnv $env): void
{
    $env->c->invokestatic($env->cp->methodRef('java/lang/Long', 'valueOf', '(J)Ljava/lang/Long;'), 2, 1);
}

/** Sign-extend-truncate an i64 on the stack to the width named by an intN_* op. */
function emitSignExtend(EmitEnv $env, string $opName): void
{
    if (\str_starts_with($opName, 'int8')) {
        $env->c->l2i();
        $env->c->i2b();
        $env->c->i2l();

        return;
    }
    if (\str_starts_with($opName, 'int16')) {
        $env->c->l2i();
        $env->c->i2s();
        $env->c->i2l();

        return;
    }
    $env->c->l2i();
    $env->c->i2l();
}

function unboxDouble(EmitEnv $env): void
{
    $env->c->checkcast($env->cp->class_('java/lang/Double'));
    $env->c->invokevirtual($env->cp->methodRef('java/lang/Double', 'doubleValue', '()D'), 0, 2);
}

function boxDouble(EmitEnv $env): void
{
    $env->c->invokestatic($env->cp->methodRef('java/lang/Double', 'valueOf', '(D)Ljava/lang/Double;'), 2, 1);
}

function emitListLit(EmitEnv $env, IR\ListLit $op): void
{
    $env->c->aconst_null();
    foreach (\array_reverse($op->elements) as $item) {
        emitOperand($env, $item);
        $env->c->swap();
        $env->c->invokestatic(
            $env->cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'),
            2,
            true,
        );
    }
}

/** @param list<IR\Operand> $args */
function pushObjectArray(EmitEnv $env, array $args): void
{
    $env->c->iconst(\count($args));
    $env->c->anewarray($env->cp->class_('java/lang/Object'));
    foreach ($args as $i => $arg) {
        $env->c->dup();
        $env->c->iconst($i);
        emitOperand($env, $arg);
        $env->c->aastore();
    }
}

function emitSrcLocValue(EmitEnv $env, ?IR\SrcLoc $loc): void
{
    if ($loc === null) {
        $env->c->aconst_null();

        return;
    }
    noteSrcLoc($env, $loc);
    if ($env->sourceMap !== null) {
        $site = $env->sourceMap->allocSite($loc, symbolName($loc->function));
        $env->c->iconst((int) $site['siteId']);
        $env->c->ldc($env->cp->string_($site['symbolId']));
        $env->c->ldc($env->cp->string_($site['displayPath']));
        $env->c->iconst((int) $site['line']);
        $env->c->iconst((int) $site['col']);
    } else {
        $symbolId = symbolId($loc->module, $loc->function);
        $display = normalizeDisplayPath($loc->file);
        $env->c->iconst(0);
        $env->c->ldc($env->cp->string_($symbolId));
        $env->c->ldc($env->cp->string_($display));
        $env->c->iconst($loc->line);
        $env->c->iconst($loc->col);
    }
    $env->c->invokestatic(
        $env->cp->methodRef(
            'moggi/rt/RT',
            'throwSite',
            '(ILjava/lang/String;Ljava/lang/String;II)[Ljava/lang/Object;',
        ),
        5,
        true,
    );
}

function emitIntrinsicValue(EmitEnv $env, IR\Intrinsic $op): bool
{
    $args = $op->args;
    switch ($op->name) {
        case 'stringCompare#':
        case 'bytesCompare#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/String', 'compareTo', '(Ljava/lang/String;)I'), 1, true);
            emitCompareIntToOrdering($env);

            return true;
        case 'stringNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'listCons#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'cons', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/MList;'),
                2,
                true,
            );

            return true;
        case 'listHead#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/MList', 'head', 'Ljava/lang/Object;'));

            return true;
        case 'listTail#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
            $env->c->getfield($env->cp->fieldRef('moggi/rt/MList', 'tail', 'Lmoggi/rt/MList;'));

            return true;
        case 'listAppend#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/MList'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'listAppend', '(Lmoggi/rt/MList;Lmoggi/rt/MList;)Lmoggi/rt/MList;'),
                2,
                true,
            );

            return true;
        case 'listEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'listEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );

            return true;
        case 'listNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'listEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'listCompare#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'listCompare', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/Con;'),
                2,
                true,
            );

            return true;
        case 'maybeEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'maybeEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );

            return true;
        case 'maybeNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'maybeEq', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );
            $env->c->checkcast($env->cp->class_('java/lang/Boolean'));
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Boolean', 'booleanValue', '()Z'), 0, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'maybeCompare#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'maybeCompare', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/Con;'),
                2,
                true,
            );

            return true;
        case 'stringAppend#':
        case 'bytesAppend#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/lang/String', 'concat', '(Ljava/lang/String;)Ljava/lang/String;'),
                1,
                true,
            );

            return true;
        case 'stringCons#':
            emitOperand($env, $args[0]);
            unboxInt($env);
            $env->c->l2i();
            $env->c->i2c();
            $env->c->invokestatic(
                $env->cp->methodRef('java/lang/Character', 'toString', '(C)Ljava/lang/String;'),
                1,
                1,
            );
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('java/lang/String'));
            $env->c->invokevirtual(
                $env->cp->methodRef('java/lang/String', 'concat', '(Ljava/lang/String;)Ljava/lang/String;'),
                1,
                true,
            );

            return true;

        case 'platformArgv#':
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Platform', 'argv', '()Lmoggi/rt/MList;'),
                0,
                true,
            );

            return true;
        case 'platformStdin#':
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/Platform', 'stdin', '()Ljava/lang/Object;'),
                0,
                true,
            );

            return true;
        case 'stringEq#':
        case 'bytesEq#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
        case 'bytesNe#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokevirtual($env->cp->methodRef('java/lang/Object', 'equals', '(Ljava/lang/Object;)Z'), 1, true);
            $env->c->iconst(1);
            $env->c->opcode(0x82, -1);
            $env->c->invokestatic($env->cp->methodRef('java/lang/Boolean', 'valueOf', '(Z)Ljava/lang/Boolean;'), 1, true);

            return true;
    }

    return false;
}
