<?php

namespace App\Checks;

use App\Models\Monitor;
use Illuminate\Support\Facades\Http;

/**
 * HTTP(S) availability. Up when the response status matches `expected`, which
 * accepts a comma separated list of exact codes ("200,204") or wildcard classes
 * ("2xx,3xx"). Left blank, anything below 400 counts as up.
 */
class HttpCheck implements CheckType
{
    public function run(Monitor $monitor): CheckResult
    {
        $url = Target::url($monitor);
        if ($url === null) {
            return CheckResult::unavailable('Monitor target is not a usable URL.');
        }

        $timeout = max(1, (int) $monitor->timeout_seconds);
        $started = microtime(true);

        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('monitor.poll.user_agent')])
                ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => true]])
                ->timeout($timeout)
                ->connectTimeout(min($timeout, 10))
                ->get($url);
        } catch (\Throwable $e) {
            return CheckResult::down($this->reason($e), $this->elapsed($started));
        }

        $ms = $this->elapsed($started);
        $code = $response->status();

        return $this->accepts($code, (string) $monitor->expected)
            ? CheckResult::up($ms, $code)
            : CheckResult::down("Unexpected status {$code}.", $ms, $code);
    }

    /** Shared by KeywordCheck, which has the same accept-or-not question. */
    public function accepts(int $code, string $expected): bool
    {
        $expected = trim($expected);
        if ($expected === '') {
            return $code > 0 && $code < 400;
        }

        foreach (explode(',', $expected) as $rule) {
            $rule = strtolower(trim($rule));
            if ($rule === '') {
                continue;
            }
            if (str_ends_with($rule, 'xx') && strlen($rule) === 3) {
                if ((int) $rule[0] === intdiv($code, 100)) {
                    return true;
                }

                continue;
            }
            if (ctype_digit($rule) && (int) $rule === $code) {
                return true;
            }
        }

        return false;
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** Guzzle messages carry the full request line; keep the useful first part. */
    private function reason(\Throwable $e): string
    {
        $msg = trim(explode("\n", $e->getMessage())[0]);

        return mb_substr($msg !== '' ? $msg : 'Request failed.', 0, 255);
    }
}
