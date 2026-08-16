<?php

namespace App\Checks;

use App\Models\Monitor;

/** Opens a TCP connection and closes it again. Up when the handshake completes. */
class TcpCheck implements CheckType
{
    public function run(Monitor $monitor): CheckResult
    {
        $host = Target::host($monitor);
        if ($host === null) {
            return CheckResult::unavailable('Monitor target is not a usable host.');
        }

        $port = (int) $monitor->port;
        if ($port < 1 || $port > 65535) {
            return CheckResult::unavailable('TCP monitors need a port.');
        }

        $timeout = max(1, (int) $monitor->timeout_seconds);
        $started = microtime(true);
        $errno = 0;
        $errstr = '';

        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($sock === false) {
            $why = trim($errstr) !== '' ? trim($errstr) : "connection refused (errno {$errno})";

            return CheckResult::down(mb_substr("Port {$port}: {$why}.", 0, 255), $ms);
        }

        fclose($sock);

        return CheckResult::up($ms);
    }
}
