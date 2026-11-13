<?php declare(strict_types=1);

namespace Moggi\Memory;

/**
 * Pause PHP's cycle collector for one project build.
 *
 * A cold library build runs the collector hundreds of times over a heap that only grows during
 * the build; each run walks every live object to find the handful that became garbage. The work
 * is real but the answer is not needed until the build ends, so the collector is switched off
 * for the build and switched back on after it, without forcing a collection: the next automatic
 * run picks up everything the build left behind, and a caller that had already disabled it keeps
 * it disabled.
 *
 * The scope is one `compileProjectBoth()` call, not the process and not each case. Pausing once
 * per compile keeps the peak bounded by that compile's own products; pausing for a whole batch
 * keeps every finished case's graph alive and measured slower in the opposite direction.
 */
final class Collector
{
    /**
     * How many paused units may finish between two explicit collections.
     *
     * The collector's cost is a full walk of the live heap, and every module a build checks leaves
     * a graph of that size behind. Collecting on each boundary bounds the peak by the modules that
     * are still prepared instead of by the last few checked programs as well: the prepare phase of
     * a cold library build drops from 1.3 GB to 1.0 GB, and the build is faster for it — the walk
     * is cheaper on the smaller heap than the one it replaces.
     */
    private const COLLECT_EVERY = 1;

    private static int $depth = 0;

    private static int $ticks = 0;

    private static bool $wasEnabled = true;

    public static function pause(): void
    {
        if (self::$depth++ > 0) {
            return;
        }

        self::$wasEnabled = \gc_enabled();
        self::$ticks = 0;
        \gc_disable();
    }

    /** One paused unit of work finished; collect on the boundary so the heap does not only grow. */
    public static function tick(): void
    {
        if (self::$depth === 0) {
            return;
        }

        if (++self::$ticks % self::COLLECT_EVERY !== 0) {
            return;
        }

        \gc_collect_cycles();
    }

    public static function resume(): void
    {
        if (self::$depth === 0 || --self::$depth > 0) {
            return;
        }

        if (self::$wasEnabled) {
            \gc_enable();
        }
    }
}

function pause(): void
{
    Collector::pause();
}

function resume(): void
{
    Collector::resume();
}

function tick(): void
{
    Collector::tick();
}
