<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm\Codegen;


use Moggi\Backend\Jvm\Classfile\ClassBuilder;
use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;
use Moggi\IR;
use function Moggi\Backend\Meta\lambdaCaptureNamesInBlock;
use function Moggi\IR\Visit\collectLocalsInBlock;
use function Moggi\IR\Visit\collectLocalsInOperand;
use function Moggi\IR\Visit\stmtNestedBlocks;
use function Moggi\IR\Visit\walkBlock;
use function Moggi\IR\Visit\walkOperand;

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 * @param array<string, true> $localCallables
 * @param array<string, true> $foreignInterfaces
 */
function emitPendingIoThunkMethods(
    ClassBuilder $b,
    string $owner,
    array $functionArity,
    array $externalFns,
    array $lambdaMeta,
    ?FnWrapperRegistry $wrapperRegistry,
    IoThunkRegistry $ioThunkRegistry,
    array $newtypeConstructors = [],
    array $localCallables = [],
    array $foreignInterfaces = [],
): void {
    while (($pending = $ioThunkRegistry->pending()) !== []) {
        $ioThunkRegistry->markEmitted();
        foreach ($pending as $spec) {
            $params = $spec['captures'];
            $arity = \count($params);
            $desc = '(' . \str_repeat('Ljava/lang/Object;', $arity) . ')Ljava/lang/Object;';
            $maxLocals = \max($arity + 32, 16);
            $paramV = \array_fill(0, $arity, 'java/lang/Object');
            $name = $spec['name'];
            $body = $spec['body'];
            $result = $spec['result'];
            $b->addMethod(
                $name,
                $desc,
                0x0009,
                $maxLocals,
                $paramV,
                static function (CodeBuilder $c, ConstantPool $cp) use ($owner, $functionArity, $externalFns, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $params, $arity, $body, $result, $newtypeConstructors, $localCallables, $foreignInterfaces): void {
                    $env = new EmitEnv($c, $cp, $owner, $functionArity, $externalFns, $arity, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, null, $localCallables, $foreignInterfaces);
                    foreach ($params as $i => $p) {
                        $env->locals[$p] = $i;
                    }
                    emitBlock($env, $body);
                    emitOperand($env, $result);
                    $env->c->areturn();
                }
            );
        }
    }
}

function emitIoCall(EmitEnv $env, IR\IoCall $stmt): void
{
    $op = $stmt->op;

    if ($op instanceof IR\IoIntrinsic) {
        emitIntrinsic($env, new IR\Intrinsic($op->name, $stmt->args));
        if ($stmt->dest !== null) {
            $env->c->astore($env->localSlot('t' . $stmt->dest));
        } else {
            $env->c->pop_();
        }

        return;
    }

    if ($op instanceof IR\IoForeign && \is_string($op->runtime) && \str_starts_with($op->runtime, 'foreign:')) {
        $foreign = $op->call;
        $call = new IR\ForeignCall(
            $foreign->backend,
            $foreign->kind,
            $foreign->path,
            $foreign->dispatch,
            $stmt->args,
            $foreign->classPath,
            $foreign->member,
            $foreign->ioWrap,
            $foreign->phpValueBox,
            $foreign->handleBox,
            $foreign->handleUnboxArgs,
            $foreign->nativeSig,
        );
        emitForeign($env, $call);
        if ($stmt->dest !== null) {
            $env->c->astore($env->localSlot('t' . $stmt->dest));
        } else {
            $env->c->pop_();
        }

        return;
    }

    emitStaticCall($env, $stmt->callee, $stmt->args);
    if ($stmt->dest !== null) {
        $env->c->astore($env->localSlot('t' . $stmt->dest));
    } else {
        $env->c->pop_();
    }
}

/**
 * Box a delayed IO action: ['__io', fn] → new IO(Fn).
 * MVP: run body eagerly into a nullary thunk class is hard without nested classes;
 * instead emit body into a synthetic static method and wrap as Fn via RT.thunk.
 */
function emitIoAssignAction(EmitEnv $env, IR\IoAssignAction $stmt): void
{
    $assigned = [];
    $collectAssigned = static function (IR\Block $block) use (&$assigned, &$collectAssigned): void {
        foreach ($block->items as $item) {
            if ($item instanceof IR\Let) {
                $assigned[$item->name] = true;
            }
            $definedTemp = match ($item::class) {
                IR\Assign::class,
                IR\Call::class,
                IR\CallValue::class,
                IR\DictCall::class,
                IR\MatchStmt::class,
                IR\IoAssignAction::class => $item->dest,
                IR\IoRun::class, IR\IoCall::class,
                IR\IoThrow::class, IR\IoCatch::class, IR\IoFinally::class => $item->dest,
                default => null,
            };
            if ($definedTemp !== null) {
                $assigned['t' . $definedTemp] = true;
            }
            foreach (stmtNestedBlocks($item) as $nested) {
                $collectAssigned($nested);
            }
        }
    };
    $collectAssigned($stmt->body);

    $captures = [];
    foreach ([...collectLocalsInBlock($stmt->body), ...collectLocalsInOperand($stmt->result)] as $name) {
        if (! isset($assigned[$name])) {
            $captures[$name] = true;
        }
    }
    foreach (lambdaCaptureNamesInBlock($stmt->body, $env->lambdaMeta) as $capture) {
        if (! isset($assigned[$capture])) {
            $captures[$capture] = true;
        }
    }
    $temps = [];
    walkBlock(
        $stmt->body,
        static function (IR\Stmt $_stmt): void {
        },
        static function (IR\Operand $op) use (&$temps): void {
            if ($op instanceof IR\Temp) {
                $temps[$op->id] = true;
            }
        },
    );
    walkOperand($stmt->result, static function (IR\Operand $op) use (&$temps): void {
        if ($op instanceof IR\Temp) {
            $temps[$op->id] = true;
        }
    });
    foreach (\array_keys($temps) as $tempId) {
        $key = 't' . $tempId;
        if (! isset($assigned[$key])) {
            $captures[$key] = true;
        }
    }
    $captures = \array_keys($captures);

    $spec = $env->ioThunkRegistry?->register($captures, $stmt->body, $stmt->result)
        ?? throw new \RuntimeException('JVM emit: missing IO thunk registry');

    if ($captures === []) {
        emitTopLevelFnValue($env, $spec['name'], 0);
    } else {
        $env->c->new_($env->cp->class_('moggi/rt/Partial'));
        $env->c->dup();
        $env->c->iconst(\count($captures));
        emitTopLevelFnValue($env, $spec['name'], \count($captures));
        $captureArgs = \array_map(
            static fn (string $capture): IR\Operand => new IR\Local($capture),
            $captures,
        );
        pushObjectArray($env, $captureArgs);
        $env->c->invokespecial(
            $env->cp->methodRef('moggi/rt/Partial', '<init>', '(ILmoggi/rt/Fn;[Ljava/lang/Object;)V'),
            3,
            false,
        );
    }
    $actionSlot = $env->freshLocal();
    $env->c->astore($actionSlot);
    $env->c->new_($env->cp->class_('moggi/rt/IO'));
    $env->c->dup();
    $env->c->aload($actionSlot);
    $env->c->invokespecial(
        $env->cp->methodRef('moggi/rt/IO', '<init>', '(Ljava/lang/Object;)V'),
        1,
        false,
    );
    $env->c->astore($env->localSlot('t' . $stmt->dest));
}

function emitIntrinsicState(EmitEnv $env, IR\Intrinsic $op): bool
{
    $args = $op->args;
    switch ($op->name) {
        case 'ioPure#':
        case 'ioBind#':
            throw new \RuntimeException(
                "intrinsic `{$op->name}` must be erased by strict IO normalization before JVM emit",
            );
        case 'stPure#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stPure', '(Ljava/lang/Object;)Lmoggi/rt/ST;'),
                1,
                true,
            );

            return true;
        case 'stBind#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ST'));
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stBind', '(Lmoggi/rt/ST;Ljava/lang/Object;)Lmoggi/rt/ST;'),
                2,
                true,
            );

            return true;
        case 'runST#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ST'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stRun', '(Lmoggi/rt/ST;)Ljava/lang/Object;'),
                1,
                true,
            );

            return true;
        case 'stToIO#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ST'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stToIO', '(Lmoggi/rt/ST;)Lmoggi/rt/IO;'),
                1,
                true,
            );

            return true;
        case 'newSTRef#':
            emitOperand($env, $args[0]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stNewRef', '(Ljava/lang/Object;)Lmoggi/rt/ST;'),
                1,
                true,
            );

            return true;
        case 'readSTRef#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/STRef'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stReadRef', '(Lmoggi/rt/STRef;)Lmoggi/rt/ST;'),
                1,
                true,
            );

            return true;
        case 'writeSTRef#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/STRef'));
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stWriteRef', '(Lmoggi/rt/STRef;Ljava/lang/Object;)Lmoggi/rt/ST;'),
                2,
                true,
            );

            return true;
        case 'arrayNew#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'arrayNew', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/ArrArray;'),
                2,
                true,
            );

            return true;
        case 'arraySize#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrArray'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'arraySize', '(Lmoggi/rt/ArrArray;)Ljava/lang/Long;'),
                1,
                true,
            );

            return true;
        case 'arrayBounds#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrArray'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'arrayBounds', '(Lmoggi/rt/ArrArray;)Ljava/lang/Object;'),
                1,
                true,
            );

            return true;
        case 'arrayRead#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrArray'));
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'arrayRead', '(Lmoggi/rt/ArrArray;Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );

            return true;
        case 'newSTArray#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stNewArray', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/ST;'),
                2,
                true,
            );

            return true;
        case 'readSTArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrSTArray'));
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stReadArray', '(Lmoggi/rt/ArrSTArray;Ljava/lang/Object;)Lmoggi/rt/ST;'),
                2,
                true,
            );

            return true;
        case 'writeSTArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrSTArray'));
            emitOperand($env, $args[1]);
            emitOperand($env, $args[2]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stWriteArray', '(Lmoggi/rt/ArrSTArray;Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/ST;'),
                3,
                true,
            );

            return true;
        case 'sizeSTArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrSTArray'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stSizeArray', '(Lmoggi/rt/ArrSTArray;)Ljava/lang/Long;'),
                1,
                true,
            );

            return true;
        case 'boundsSTArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrSTArray'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stBoundsArray', '(Lmoggi/rt/ArrSTArray;)Ljava/lang/Object;'),
                1,
                true,
            );

            return true;
        case 'sameSTArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrSTArray'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrSTArray'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'sameRef', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );

            return true;
        case 'freezeSTArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrSTArray'));
            $env->c->iconst(1);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stFreezeArray', '(Lmoggi/rt/ArrSTArray;Z)Lmoggi/rt/ST;'),
                2,
                true,
            );

            return true;
        case 'thawArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrArray'));
            $env->c->iconst(1);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stThawArray', '(Lmoggi/rt/ArrArray;Z)Lmoggi/rt/ST;'),
                2,
                true,
            );

            return true;
        case 'unsafeFreezeSTArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrSTArray'));
            $env->c->iconst(0);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stFreezeArray', '(Lmoggi/rt/ArrSTArray;Z)Lmoggi/rt/ST;'),
                2,
                true,
            );

            return true;
        case 'unsafeThawArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrArray'));
            $env->c->iconst(0);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'stThawArray', '(Lmoggi/rt/ArrArray;Z)Lmoggi/rt/ST;'),
                2,
                true,
            );

            return true;
        case 'newIOArray#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioNewArray', '(Ljava/lang/Object;Ljava/lang/Object;)Lmoggi/rt/ArrIOArray;'),
                2,
                true,
            );

            return true;
        case 'readIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrIOArray'));
            emitOperand($env, $args[1]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioReadArray', '(Lmoggi/rt/ArrIOArray;Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );

            return true;
        case 'writeIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrIOArray'));
            emitOperand($env, $args[1]);
            emitOperand($env, $args[2]);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioWriteArray', '(Lmoggi/rt/ArrIOArray;Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Object;'),
                3,
                true,
            );

            return true;
        case 'sizeIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrIOArray'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioSizeArray', '(Lmoggi/rt/ArrIOArray;)Ljava/lang/Long;'),
                1,
                true,
            );

            return true;
        case 'boundsIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrIOArray'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioBoundsArray', '(Lmoggi/rt/ArrIOArray;)Ljava/lang/Object;'),
                1,
                true,
            );

            return true;
        case 'sameIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrIOArray'));
            emitOperand($env, $args[1]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrIOArray'));
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'sameRef', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Boolean;'),
                2,
                true,
            );

            return true;
        case 'freezeIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrIOArray'));
            $env->c->iconst(1);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioFreezeArray', '(Lmoggi/rt/ArrIOArray;Z)Lmoggi/rt/ArrArray;'),
                2,
                true,
            );

            return true;
        case 'thawIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrArray'));
            $env->c->iconst(1);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioThawArray', '(Lmoggi/rt/ArrArray;Z)Lmoggi/rt/ArrIOArray;'),
                2,
                true,
            );

            return true;
        case 'unsafeFreezeIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrIOArray'));
            $env->c->iconst(0);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioFreezeArray', '(Lmoggi/rt/ArrIOArray;Z)Lmoggi/rt/ArrArray;'),
                2,
                true,
            );

            return true;
        case 'unsafeThawIOArray#':
            emitOperand($env, $args[0]);
            $env->c->checkcast($env->cp->class_('moggi/rt/ArrArray'));
            $env->c->iconst(0);
            $env->c->invokestatic(
                $env->cp->methodRef('moggi/rt/RT', 'ioThawArray', '(Lmoggi/rt/ArrArray;Z)Lmoggi/rt/ArrIOArray;'),
                2,
                true,
            );

            return true;
    }

    return false;
}
