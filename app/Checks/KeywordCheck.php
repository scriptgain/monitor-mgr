<?php

namespace App\Checks;

use App\Models\Monitor;
use Illuminate\Support\Facades\Http;

/**
 * Fetches the page and asserts on its body. `expected` is the keyword; prefix it
 * with "!" to invert, so "!Database error" is up while the phrase is absent.
 * Matching is case insensitive, because page copy changes case more often than
 * it changes meaning.
 */
class KeywordCheck implements CheckType
{
    public function run(Monitor $monitor): CheckResult
    {
        $url = Target::url($monitor);
        if ($url === null) {
            return CheckResult::unavailable('Monitor target is not a usable URL.');
        }

        $expected = trim((string) $monitor->expected);
        if ($expected === '') {
            return CheckResult::unavailable('Keyword monitors need a keyword in the Expected field.');
        }

        $negate = str_starts_with($expected, '!');
        $needle = $negate ? trim(substr($expected, 1)) : $expected;
        if ($needle === '') {
            return CheckResult::unavailable('Keyword monitors need a keyword in the Expected field.');
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
            return CheckResult::down(mb_substr(trim(explode("\n", $e->getMessage())[0]), 0, 255), $this->elapsed($started));
        }

        $ms = $this->elapsed($started);
        $code = $response->status();

        if ($code >= 400) {
            return CheckResult::down("Unexpected status {$code}.", $ms, $code);
        }

        $found = mb_stripos($response->body(), $needle) !== false;
        if ($found === $negate) {
            $what = $negate ? 'Forbidden keyword found' : 'Keyword not found';

            return CheckResult::down("{$what}: \"{$needle}\".", $ms, $code);
        }

        return CheckResult::up($ms, $code);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
