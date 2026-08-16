<?php

namespace App\Checks;

use App\Models\Monitor;
use Illuminate\Support\Facades\Process;

/**
 * ICMP reachability. Prefers fping, which reports round-trip time in one line
 * and does not need root, and falls back to iputils ping.
 *
 * Shared hosting frequently disables process execution and often blocks ICMP
 * outright. Both cases return unavailable() rather than down(), so a panel that
 * cannot ping does not manufacture an outage.
 */
class PingCheck implements CheckType
{
    public function run(Monitor $monitor): CheckResult
    {
        $host = Target::host($monitor);
        if ($host === null) {
            return CheckResult::unavailable('Monitor target is not a usable host.');
        }

        $timeout = max(1, (int) $monitor->timeout_seconds);
        $binary = $this->locate();
        if ($binary === null) {
            return CheckResult::unavailable('Neither fping nor ping is installed on the panel host.');
        }

        $command = $binary === 'fping'
            ? [$binary, '-c1', '-t'.($timeout * 1000), $host]
            : [$binary, '-c', '1', '-W', (string) $timeout, $host];

        $started = microtime(true);
        try {
            $result = Process::timeout($timeout + 5)->run($command);
        } catch (\Throwable $e) {
            return CheckResult::unavailable("Could not run {$binary}: ".mb_substr($e->getMessage(), 0, 180));
        }
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($result->successful()) {
            return CheckResult::up($this->rtt($result->output().$result->errorOutput()) ?? $ms);
        }

        // fping exits 1 for unreachable and 2 for a name that will not resolve;
        // ping uses the same two codes. Anything else is the panel's problem,
        // not the target's, so it must not read as an outage.
        $exit = $result->exitCode();
        if (! in_array($exit, [1, 2], true)) {
            return CheckResult::unavailable("{$binary} exited {$exit}: ".mb_substr(trim($result->errorOutput()), 0, 180));
        }

        return CheckResult::down($exit === 2 ? 'Host did not resolve.' : 'No ICMP reply.', $ms);
    }

    private function locate(): ?string
    {
        foreach (['fping', 'ping'] as $bin) {
            try {
                if (Process::timeout(5)->run(['which', $bin])->successful()) {
                    return $bin;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** Pull the round trip out of either tool's output, in milliseconds. */
    private function rtt(string $output): ?int
    {
        if (preg_match('/time[=<]\s*([0-9.]+)\s*ms/i', $output, $m)) {
            return (int) round((float) $m[1]);
        }
        if (preg_match('/=\s*[0-9.]+\/([0-9.]+)\/[0-9.]+/', $output, $m)) {
            return (int) round((float) $m[1]);
        }

        return null;
    }
}
