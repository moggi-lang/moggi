<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Codegen;


use Moggi\IR;
use function Moggi\Backend\Meta\lambdaCaptureNamesInBlock;
use function Moggi\IR\Visit\collectLocalsInBlock;
use function Moggi\IR\Visit\collectLocalsInOperand;
use function Moggi\IR\Visit\stmtNestedBlocks;
use function Moggi\IR\Visit\walkBlock;
use function Moggi\IR\Visit\walkOperand;

/**
 * @param array<string, int> $functionArity
 * @param array<string, string> $externalFns
 * @param array<string, array{captures: list<string>, params: list<string>}> $lambdaMeta
 */
function emitPendingIoThunkMethods(
    ClassIlBuilder $b,
    string $owner,
    array $functionArity,
    array $externalFns,
    array $lambdaMeta,
    FnWrapperRegistry $wrapperRegistry,
    IoThunkRegistry $ioThunkRegistry,
    array $newtypeConstructors = [],
    array $localCallables = [],
): void {
    while (($pending = $ioThunkRegistry->pending()) !== []) {
        $ioThunkRegistry->markEmitted();
        foreach ($pending as $spec) {
            $params = $spec['captures'];
            $arity = \count($params);
            $name = $spec['name'];
            $body = $spec['body'];
            $result = $spec['result'];

            $m = new MethodIl();
            $env = new EmitEnv($m, $owner, $functionArity, $externalFns, $lambdaMeta, $wrapperRegistry, $ioThunkRegistry, $newtypeConstructors, null, $localCallables);
            foreach ($params as $i => $p) {
                $env->locals[$p] = 'arg:' . $i;
            }
            emitBlock($env, $body);
            emitOperand($env, $result);
            $m->emit('ret');

            $b->addMethod($m->render($name, \array_fill(0, $arity, 'object')));
        }
    }
}

function emitIoCall(EmitEnv $env, IR\IoCall $stmt): void
{
    $op = $stmt->op;

    if ($op instanceof IR\IoIntrinsic) {
        emitIntrinsic($env, new IR\Intrinsic($op->name, $stmt->args));
        if ($stmt->dest !== null) {
            $env->m->store($env->localSlot('t' . $stmt->dest));
        } else {
            $env->m->emit('pop');
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
            $env->m->store($env->localSlot('t' . $stmt->dest));
        } else {
            $env->m->emit('pop');
        }

        return;
    }

    emitStaticCall($env, $stmt->callee, $stmt->args);
    if ($stmt->dest !== null) {
        $env->m->store($env->localSlot('t' . $stmt->dest));
    } else {
        $env->m->emit('pop');
    }
}

/**
 * Box a delayed IO action: body/result → IO(Fn). Registers a synthetic static
 * IO thunk on the owning type, closing over free locals as positional captures.
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
        ?? throw new \RuntimeException('DotNet emit: missing IO thunk registry');

    if ($captures === []) {
        emitTopLevelFnValue($env, $spec['name'], 0);
    } else {
        $arity = \count($captures);
        $env->m->emit("ldc.i4 {$arity}");
        emitTopLevelFnValue($env, $spec['name'], $arity);
        $captureArgs = \array_map(
            static fn (string $capture): IR\Operand => new IR\Local($capture),
            $captures,
        );
        pushObjectArray($env, $captureArgs);
        $env->m->emit('newobj instance void Moggi.Rt.Partial::.ctor(int32, class Moggi.Rt.Fn, object[])');
    }
    $actionSlot = $env->freshLocal();
    $env->m->store($actionSlot);
    $env->m->load($actionSlot);
    $env->m->emit('newobj instance void Moggi.Rt.IO::.ctor(object)');
    $env->m->store($env->localSlot('t' . $stmt->dest));
}

function emitIntrinsicState(EmitEnv $env, IR\Intrinsic $op): bool
{
    $args = $op->args;
    switch ($op->name) {
        case 'ioPure#':
        case 'ioBind#':
            throw new \RuntimeException(
                "intrinsic `{$op->name}` must be erased by strict IO normalization before DotNet emit",
            );
        case 'stPure#':
            emitOperand($env, $args[0]);
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StPure(object)');

            return true;
        case 'stBind#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ST');
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StBind(class Moggi.Rt.ST, object)');

            return true;
        case 'runST#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ST');
            $env->m->emit('call object Moggi.Rt.RT::StRun(class Moggi.Rt.ST)');

            return true;
        case 'stToIO#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ST');
            $env->m->emit('call class Moggi.Rt.IO Moggi.Rt.RT::StToIO(class Moggi.Rt.ST)');

            return true;
        case 'newSTRef#':
            emitOperand($env, $args[0]);
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StNewRef(object)');

            return true;
        case 'readSTRef#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.STRef');
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StReadRef(class Moggi.Rt.STRef)');

            return true;
        case 'writeSTRef#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.STRef');
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StWriteRef(class Moggi.Rt.STRef, object)');

            return true;
        case 'arrayNew#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.ArrArray Moggi.Rt.RT::ArrayNew(object, object)');

            return true;
        case 'arraySize#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrArray');
            $env->m->emit('call object Moggi.Rt.RT::ArraySize(class Moggi.Rt.ArrArray)');

            return true;
        case 'arrayBounds#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrArray');
            $env->m->emit('call object Moggi.Rt.RT::ArrayBounds(class Moggi.Rt.ArrArray)');

            return true;
        case 'arrayRead#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrArray');
            emitOperand($env, $args[1]);
            $env->m->emit('call object Moggi.Rt.RT::ArrayRead(class Moggi.Rt.ArrArray, object)');

            return true;
        case 'newSTArray#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StNewArray(object, object)');

            return true;
        case 'readSTArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrSTArray');
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StReadArray(class Moggi.Rt.ArrSTArray, object)');

            return true;
        case 'writeSTArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrSTArray');
            emitOperand($env, $args[1]);
            emitOperand($env, $args[2]);
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StWriteArray(class Moggi.Rt.ArrSTArray, object, object)');

            return true;
        case 'sizeSTArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrSTArray');
            $env->m->emit('call object Moggi.Rt.RT::StSizeArray(class Moggi.Rt.ArrSTArray)');

            return true;
        case 'boundsSTArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrSTArray');
            $env->m->emit('call object Moggi.Rt.RT::StBoundsArray(class Moggi.Rt.ArrSTArray)');

            return true;
        case 'sameSTArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrSTArray');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.ArrSTArray');
            $env->m->emit('ceq');
            $env->m->emit('box bool');

            return true;
        case 'freezeSTArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrSTArray');
            $env->m->emit('ldc.i4.1');
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StFreezeArray(class Moggi.Rt.ArrSTArray, bool)');

            return true;
        case 'thawArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrArray');
            $env->m->emit('ldc.i4.1');
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StThawArray(class Moggi.Rt.ArrArray, bool)');

            return true;
        case 'unsafeFreezeSTArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrSTArray');
            $env->m->emit('ldc.i4.0');
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StFreezeArray(class Moggi.Rt.ArrSTArray, bool)');

            return true;
        case 'unsafeThawArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrArray');
            $env->m->emit('ldc.i4.0');
            $env->m->emit('call class Moggi.Rt.ST Moggi.Rt.RT::StThawArray(class Moggi.Rt.ArrArray, bool)');

            return true;
        case 'newIOArray#':
            emitOperand($env, $args[0]);
            emitOperand($env, $args[1]);
            $env->m->emit('call class Moggi.Rt.ArrIOArray Moggi.Rt.RT::IoNewArray(object, object)');

            return true;
        case 'readIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrIOArray');
            emitOperand($env, $args[1]);
            $env->m->emit('call object Moggi.Rt.RT::IoReadArray(class Moggi.Rt.ArrIOArray, object)');

            return true;
        case 'writeIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrIOArray');
            emitOperand($env, $args[1]);
            emitOperand($env, $args[2]);
            $env->m->emit('call object Moggi.Rt.RT::IoWriteArray(class Moggi.Rt.ArrIOArray, object, object)');

            return true;
        case 'sizeIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrIOArray');
            $env->m->emit('call object Moggi.Rt.RT::IoSizeArray(class Moggi.Rt.ArrIOArray)');

            return true;
        case 'boundsIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrIOArray');
            $env->m->emit('call object Moggi.Rt.RT::IoBoundsArray(class Moggi.Rt.ArrIOArray)');

            return true;
        case 'sameIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrIOArray');
            emitOperand($env, $args[1]);
            $env->m->emit('castclass Moggi.Rt.ArrIOArray');
            $env->m->emit('ceq');
            $env->m->emit('box bool');

            return true;
        case 'freezeIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrIOArray');
            $env->m->emit('ldc.i4.1');
            $env->m->emit('call class Moggi.Rt.ArrArray Moggi.Rt.RT::IoFreezeArray(class Moggi.Rt.ArrIOArray, bool)');

            return true;
        case 'thawIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrArray');
            $env->m->emit('ldc.i4.1');
            $env->m->emit('call class Moggi.Rt.ArrIOArray Moggi.Rt.RT::IoThawArray(class Moggi.Rt.ArrArray, bool)');

            return true;
        case 'unsafeFreezeIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrIOArray');
            $env->m->emit('ldc.i4.0');
            $env->m->emit('call class Moggi.Rt.ArrArray Moggi.Rt.RT::IoFreezeArray(class Moggi.Rt.ArrIOArray, bool)');

            return true;
        case 'unsafeThawIOArray#':
            emitOperand($env, $args[0]);
            $env->m->emit('castclass Moggi.Rt.ArrArray');
            $env->m->emit('ldc.i4.0');
            $env->m->emit('call class Moggi.Rt.ArrIOArray Moggi.Rt.RT::IoThawArray(class Moggi.Rt.ArrArray, bool)');

            return true;
    }

    return false;
}
