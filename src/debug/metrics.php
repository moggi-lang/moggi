<?php declare(strict_types=1);

namespace Moggi\Debug;

/**
 * Optional compile-time counters for the inference/AST refactor.
 *
 * Off unless `MOGGI_METRICS=1`, so the normal compiler and test suite pay
 * nothing beyond one static boolean check. When on, counts are written as JSON
 * at process shutdown to `MOGGI_METRICS_FILE` (default: `<tmp>/moggi-metrics.json`).
 *
 * These counters are evidence, not invariants: they show where work happens
 * (e.g. the module double-check, probe multiplicity), never a value a phase is
 * required to reach.
 */
final class Metrics
{
    private static ?bool $enabled = null;

    /** @var array<string, int> */
    private static array $counts = [];

    private static bool $registered = false;

    public static function enabled(): bool
    {
        return self::$enabled ??= getenv('MOGGI_METRICS') === '1';
    }

    public static function increment(string $key): void
    {
        self::register();
        self::$counts[$key] = (self::$counts[$key] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        return self::$counts;
    }

    public static function reset(): void
    {
        self::$counts = [];
    }

    private static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function(static function (): void {
            if (self::$counts === []) {
                return;
            }
            ksort(self::$counts);
            $path = getenv('MOGGI_METRICS_FILE') ?: (sys_get_temp_dir() . '/moggi-metrics.json');
            file_put_contents($path, json_encode(self::$counts, JSON_PRETTY_PRINT) . "\n");
        });
    }
}

function metric(string $key): void
{
    if (Metrics::enabled()) {
        Metrics::increment($key);
    }
}
