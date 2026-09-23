<?php declare(strict_types=1);

namespace Moggi\Backend\Jvm;

use Moggi\Backend\Jvm\Classfile\ClassBuilder;
use Moggi\Backend\Jvm\Classfile\CodeBuilder;
use Moggi\Backend\Jvm\Classfile\ConstantPool;

require_once __DIR__ . '/classfile.php';

/**
 * Generate `moggi/rt/Frames`: a static `HashMap` from a host stack-trace key
 * `"<class>#<method>#<sourceLine>"` to the pre-rendered `.mog` frame line.
 *
 * JVM debug info carries the source line but neither the column nor the
 * project-relative path, so the compiler bakes those in at packaging time.
 * Values are pre-rendered, so the runtime needs no formatting or parsing.
 */
function buildFrames(array $rows): string
{
    $b = new ClassBuilder('moggi/rt/Frames');

    // Rows are de-duplicated by the compiler; keep first-wins here too.
    $table = [];
    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }
        $key = (string) ($row['key'] ?? '');
        $text = (string) ($row['text'] ?? '');
        if ($key === '' || $text === '') {
            continue;
        }
        if (!isset($table[$key])) {
            $table[$key] = $text;
        }
    }

    $b->addField('TABLE', 'Ljava/util/HashMap;', 0x000A);

    $entries = [];
    foreach ($table as $key => $text) {
        $entries[] = [$key, $text];
    }

    $chunkSize = 500;
    $chunks = $entries === [] ? [[]] : array_chunk($entries, $chunkSize);

    $b->addMethod('<clinit>', '()V', 0x0008, 1, [], static function (CodeBuilder $c, ConstantPool $cp) use ($chunks): void {
        $c->new_($cp->class_('java/util/HashMap'));
        $c->dup();
        $c->invokespecial($cp->methodRef('java/util/HashMap', '<init>', '()V'), 0, false);
        $c->putstatic($cp->fieldRef('moggi/rt/Frames', 'TABLE', 'Ljava/util/HashMap;'));
        $c->noteFrame([]);
        foreach ($chunks as $i => $_) {
            $c->invokestatic($cp->methodRef('moggi/rt/Frames', 'init' . $i, '()V'), 0, false);
        }
        $c->return_();
    });

    foreach ($chunks as $i => $chunk) {
        $b->addMethod('init' . $i, '()V', 0x000A, 1, [], static function (CodeBuilder $c, ConstantPool $cp) use ($chunk): void {
            foreach ($chunk as [$key, $text]) {
                $c->getstatic($cp->fieldRef('moggi/rt/Frames', 'TABLE', 'Ljava/util/HashMap;'));
                $c->ldc($cp->string_($key));
                $c->ldc($cp->string_($text));
                $c->invokevirtual(
                    $cp->methodRef('java/util/HashMap', 'put', '(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Object;'),
                    3,
                    true,
                );
                $c->pop_();
            }
            $c->return_();
        });
    }

    $b->addMethod(
        'lookup',
        '(Ljava/lang/String;)Ljava/lang/String;',
        0x0009,
        1,
        ['java/lang/String'],
        static function (CodeBuilder $c, ConstantPool $cp): void {
            $c->getstatic($cp->fieldRef('moggi/rt/Frames', 'TABLE', 'Ljava/util/HashMap;'));
            $c->aload(0);
            $c->invokevirtual(
                $cp->methodRef('java/util/HashMap', 'get', '(Ljava/lang/Object;)Ljava/lang/Object;'),
                2,
                true,
            );
            $c->checkcast($cp->class_('java/lang/String'));
            $c->areturn();
        }
    );

    return $b->toBytes();
}
