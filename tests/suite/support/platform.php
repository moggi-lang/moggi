<?php declare(strict_types=1);

/**
 * What the machine running the suite looks like: CPUs it may use, and free memory. This file probes
 * and never picks a worker count.
 *
 * Each probe is best-effort and returns null when it cannot tell — an unknown machine is a one-CPU
 * machine, never an error. On Linux a cgroup's cpuset and quota are part of the answer.
 */

/** The first number in a probe's output, or null when it produced none. */
function platformFirstNumber(string $output): ?int
{
    return \preg_match('/\d+/', $output, $match) === 1 ? (int) $match[0] : null;
}

/** Output of a shell command, with the platform's own way of silencing stderr. */
function platformShellOutput(string $command): string
{
    if (!\function_exists('shell_exec')) {
        return '';
    }

    return \trim((string) @\shell_exec($command . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null')));
}

/**
 * CPUs to parallelise across: physical cores, clamped by what this process may use (a container can
 * report twelve host CPUs on a two-CPU quota).
 */
function detectedCpuCount(): int
{
    $topology = platformCpuTopology();
    $usable = \array_filter([$topology['threads'], cgroupCpuLimit()], static fn (?int $n): bool => $n !== null);
    $cap = $usable === [] ? null : \min($usable);
    $recommended = $topology['cores'] ?? $cap;

    if ($recommended === null || $cap === null) {
        return \max(1, $recommended ?? 1);
    }

    return \max(1, \min($recommended, $cap));
}

/**
 * The machine's CPU counts; `cores` is null when the platform cannot tell cores from threads.
 * `MOGGI_TEST_CPU_COUNT` overrides the whole probe.
 *
 * @return array{cores: ?int, threads: ?int}
 */
function platformCpuTopology(): array
{
    $override = \getenv('MOGGI_TEST_CPU_COUNT');
    if (\is_string($override) && \ctype_digit($override) && (int) $override > 0) {
        return ['cores' => (int) $override, 'threads' => (int) $override];
    }

    return match (PHP_OS_FAMILY) {
        'Linux' => linuxCpuTopology(),
        // `hw.ncpu` is the older spelling of the logical count.
        'Darwin' => [
            'cores' => platformFirstNumber(platformShellOutput('sysctl -n hw.physicalcpu')),
            'threads' => platformFirstNumber(platformShellOutput('sysctl -n hw.logicalcpu'))
                ?? platformFirstNumber(platformShellOutput('sysctl -n hw.ncpu')),
        ],
        // CIM (PowerShell) reports both, summed over sockets; `NUMBER_OF_PROCESSORS` is the CPU set
        // the OS gave this process.
        'Windows' => [
            'cores' => windowsCimInt('NumberOfCores'),
            'threads' => windowsCimInt('NumberOfLogicalProcessors')
                ?? platformFirstNumber((string) \getenv('NUMBER_OF_PROCESSORS')),
        ],
        default => ['cores' => null, 'threads' => platformFirstNumber(platformShellOutput('getconf _NPROCESSORS_ONLN'))],
    };
}

/**
 * `lscpu` and `/proc/cpuinfo` report the same facts and fall back to each other; threads prefer
 * `nproc`, which respects this process's affinity.
 *
 * @return array{cores: ?int, threads: ?int}
 */
function linuxCpuTopology(): array
{
    $lscpu = linuxTopologyFromLscpu(platformShellOutput('lscpu -p=CPU,CORE,SOCKET'));
    $cpuinfo = \is_readable('/proc/cpuinfo') ? linuxTopologyFromCpuinfo((string) \file_get_contents('/proc/cpuinfo')) : ['cores' => null, 'threads' => null];

    return [
        'cores' => $lscpu['cores'] ?? $cpuinfo['cores'],
        'threads' => platformFirstNumber(platformShellOutput('nproc')) ?? $lscpu['threads'] ?? $cpuinfo['threads'],
    ];
}

/**
 * Distinct cores and CPUs from `lscpu -p=CPU,CORE,SOCKET`. Cores are (socket, core) pairs, since a
 * core id is only unique within its socket.
 *
 * @return array{cores: ?int, threads: ?int}
 */
function linuxTopologyFromLscpu(string $output): array
{
    $threads = 0;
    /** @var array<string, true> $cores */
    $cores = [];
    foreach (\preg_split('/\R/', $output) ?: [] as $line) {
        $fields = \explode(',', \trim($line));
        if (\count($fields) < 3 || !\ctype_digit($fields[0])) {
            continue;
        }
        ++$threads;
        if (\ctype_digit($fields[1]) && \ctype_digit($fields[2])) {
            $cores[$fields[2] . ':' . $fields[1]] = true;
        }
    }

    return ['cores' => $cores === [] ? null : \count($cores), 'threads' => $threads > 0 ? $threads : null];
}

/**
 * Cores and threads from `/proc/cpuinfo`, one block per CPU. A kernel with no SMT topology reports
 * no `core id`, and cores stay unknown rather than guessed.
 *
 * @return array{cores: ?int, threads: ?int}
 */
function linuxTopologyFromCpuinfo(string $cpuinfo): array
{
    $threads = 0;
    /** @var array<string, true> $cores */
    $cores = [];
    foreach (\preg_split('/\n\s*\n/', $cpuinfo) ?: [] as $block) {
        if (\preg_match('/^processor\s*:/m', $block) !== 1) {
            continue;
        }
        ++$threads;
        if (\preg_match('/^core id\s*:\s*(\d+)/m', $block, $core) === 1) {
            $package = \preg_match('/^physical id\s*:\s*(\d+)/m', $block, $match) === 1 ? $match[1] : '0';
            $cores[$package . ':' . $core[1]] = true;
        }
    }

    return ['cores' => $cores === [] ? null : \count($cores), 'threads' => $threads > 0 ? $threads : null];
}

/** A `Win32_Processor` property summed over sockets, or null when PowerShell cannot be run. */
function windowsCimInt(string $property): ?int
{
    return platformFirstNumber(platformShellOutput(\sprintf(
        'powershell -NoProfile -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property %s -Sum).Sum"',
        $property,
    )));
}

/** A cgroup file's contents, or '' when it is absent — cgroup files are probed, never required. */
function cgroupRead(string $path): string
{
    $contents = @\file_get_contents($path);

    return \is_string($contents) ? \trim($contents) : '';
}

/** A cgroup numeric field, or null when it is missing or not a number (`-1`, `max`). */
function cgroupInt(string $value): ?int
{
    return \preg_match('/^\d+$/', \trim($value)) === 1 ? (int) \trim($value) : null;
}

/**
 * CPUs a cpuset names (`0-3,6` => 5), or null when it names none. An unreadable or oddly shaped
 * list is "unknown", not one CPU, so the caller falls back to another probe.
 */
function cgroupCpuListCount(string $list): ?int
{
    if ($list === '') {
        return null;
    }

    $count = 0;
    foreach (\explode(',', $list) as $range) {
        if (\preg_match('/^(\d+)(?:-(\d+))?$/', \trim($range), $match) !== 1) {
            return null;
        }
        $count += isset($match[2]) ? (int) $match[2] - (int) $match[1] + 1 : 1;
    }

    return $count > 0 ? $count : null;
}

/**
 * CPUs a quota allows, or null when there is none or it is unlimited. v2 keeps quota and period in
 * one `cpu.max` line, v1 in `cpu.cfs_quota_us`/`cpu.cfs_period_us`; both spell "unlimited" as a
 * non-number, which is what makes it null here.
 */
function cgroupCpuQuota(string $v2Max, string $v1Quota, string $v1Period): ?int
{
    $fields = \preg_split('/\s+/', \trim($v2Max)) ?: [];
    [$quota, $period] = \count($fields) >= 2 ? $fields : [$v1Quota, $v1Period];

    $quotaUs = cgroupInt($quota);
    $periodUs = cgroupInt($period);
    if ($quotaUs === null || $periodUs === null || $quotaUs <= 0 || $periodUs <= 0) {
        return null;
    }

    // A quota smaller than one CPU's worth still allows one worker.
    return (int) \max(1, (int) \ceil($quotaUs / $periodUs));
}

/**
 * Cgroup directories that can limit this process, most specific first, ending at the root: a quota is
 * enforced where it is set, so reading only `/sys/fs/cgroup/cpu.max` would call a capped container
 * unlimited.
 *
 * @return list<string>
 */
function cgroupCpuDirs(): array
{
    if (PHP_OS_FAMILY !== 'Linux' || !\is_dir('/sys/fs/cgroup')) {
        return [];
    }

    // "0::/user.slice/x.scope" is the unified hierarchy; "5:cpu,cpuacct:/docker/y" is v1, which
    // mounts one hierarchy per controller.
    $dirs = [];
    foreach (\preg_split('/\R/', cgroupRead('/proc/self/cgroup')) ?: [] as $line) {
        if (\preg_match('#^\d+:([^:]*):(/.+)$#', $line, $match) !== 1) {
            continue;
        }
        $roots = $match[1] === '' ? ['/sys/fs/cgroup'] : ['/sys/fs/cgroup/cpu', '/sys/fs/cgroup/cpuset'];
        foreach ($roots as $root) {
            $leaf = \rtrim($root . '/' . \ltrim($match[2], '/'), '/');
            for ($dir = $leaf; \str_starts_with($dir, $root . '/'); $dir = \dirname($dir)) {
                $dirs[] = $dir;
            }
            $dirs[] = $root;
        }
    }
    $dirs[] = '/sys/fs/cgroup';

    return \array_values(\array_unique($dirs));
}

/** CPUs this process's cgroups allow, or null when none of them restricts it. */
function cgroupCpuLimit(): ?int
{
    $limits = [];
    foreach (cgroupCpuDirs() as $dir) {
        $limits[] = cgroupCpuQuota(
            cgroupRead($dir . '/cpu.max'),
            cgroupRead($dir . '/cpu.cfs_quota_us'),
            cgroupRead($dir . '/cpu.cfs_period_us'),
        );
        $limits[] = cgroupCpuListCount(cgroupRead($dir . '/cpuset.cpus.effective'))
            ?? cgroupCpuListCount(cgroupRead($dir . '/cpuset.effective_cpus'));   // cgroup v1
    }

    $known = \array_filter($limits, static fn (?int $cpus): bool => $cpus !== null && $cpus > 0);

    return $known === [] ? null : \min($known);
}

/** Free memory in bytes, or null when the platform does not report it cheaply. */
function availableMemoryBytes(): ?int
{
    if (PHP_OS_FAMILY === 'Linux' && \is_readable('/proc/meminfo')) {
        $info = (string) \file_get_contents('/proc/meminfo');
        if (\preg_match('/^MemAvailable:\s*(\d+)\s*kB/m', $info, $match) === 1) {
            return (int) $match[1] * 1024;
        }
    }

    return null;
}

/**
 * How many workers free memory can hold at `$perWorkerBytes` each, or null when that cannot be told:
 * an unbounded automatic `--jobs` on a small machine is how a worker dies mid-run.
 */
function workerLimitForMemory(int $perWorkerBytes): ?int
{
    $available = availableMemoryBytes();
    if ($available === null || $perWorkerBytes <= 0) {
        return null;
    }

    return \max(1, \intdiv($available, $perWorkerBytes));
}
