<?php

namespace App\Checks;

use App\Models\Monitor;

/**
 * Maps a monitor type to its executor.
 *
 * Two of the eight types are not polled here. `agent` monitors are fed by the
 * host agent pushing metrics, and `heartbeat` monitors are fed by whatever
 * external job pings their URL; both are swept for staleness by monitor:poll
 * instead of being executed.
 */
class CheckRunner
{
    public const POLLED = ['http', 'tcp', 'ping', 'keyword', 'ssl', 'dns'];

    /** @var array<string, class-string<CheckType>> */
    private const MAP = [
        'http' => HttpCheck::class,
        'keyword' => KeywordCheck::class,
        'tcp' => TcpCheck::class,
        'ping' => PingCheck::class,
        'dns' => DnsCheck::class,
        'ssl' => SslCheck::class,
    ];

    public static function isPolled(string $type): bool
    {
        return isset(self::MAP[$type]);
    }

    public static function for(string $type): ?CheckType
    {
        $class = self::MAP[$type] ?? null;

        return $class ? app($class) : null;
    }

    /** Execute a monitor's check, converting any escaped throwable into a result. */
    public static function run(Monitor $monitor): CheckResult
    {
        $check = self::for((string) $monitor->type);
        if (! $check) {
            return CheckResult::unavailable("Monitor type \"{$monitor->type}\" is not polled.");
        }

        try {
            return $check->run($monitor);
        } catch (\Throwable $e) {
            return CheckResult::unavailable('Check errored: '.mb_substr($e->getMessage(), 0, 200));
        }
    }
}
