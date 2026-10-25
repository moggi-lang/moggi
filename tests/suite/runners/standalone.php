<?php declare(strict_types=1);

/**
 * Watchdog for one standalone test: 300s. A case that needs to assert the kill-and-report path
 * spawns its own short-lived process instead of shortening this.
 */
function standaloneTimeoutSeconds(): int
{
    return 300;
}

/**
 * Standalone tests: an independent PHP program beside the code it tests, named `snake_case.php`, run in
 * its own process so a `die()` or fatal cannot take the suite down. Exit 0 is success.
 *
 * @return array{passed: bool, message: string}
 */
function runStandaloneTest(string $path): array
{
    if (! \is_file($path)) {
        return ['passed' => false, 'message' => "missing standalone test {$path}"];
    }

    $result = runCompiledProcess([PHP_BINARY, $path], standaloneTimeoutSeconds(), null, projectRootPath());
    if (($result['timedOut'] ?? false) === true) {
        return ['passed' => false, 'message' => 'standalone test timed out', 'timeout' => true];
    }
    if (($result['exitCode'] ?? 1) !== 0) {
        $output = \trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? ''));

        return ['passed' => false, 'message' => $output === '' ? "exit {$result['exitCode']}" : $output];
    }

    return ['passed' => true, 'message' => ''];
}
