<?php

namespace App\Checks;

use App\Models\Monitor;

/**
 * TLS certificate expiry. Connects, reads the peer certificate, and goes down
 * when the certificate has expired, is not yet valid, or has fewer days left
 * than `expected` (default 14).
 *
 * Verification is deliberately off for the handshake itself: an expired or
 * self-signed certificate must be reportable rather than unreachable, and the
 * expiry maths below is what decides up or down.
 */
class SslCheck implements CheckType
{
    private const DEFAULT_MIN_DAYS = 14;

    public function run(Monitor $monitor): CheckResult
    {
        $host = Target::host($monitor);
        if ($host === null) {
            return CheckResult::unavailable('Monitor target is not a usable host.');
        }

        $port = Target::port($monitor, 443);
        $timeout = max(1, (int) $monitor->timeout_seconds);
        $minDays = $this->minDays((string) $monitor->expected);

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $started = microtime(true);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            "ssl://{$host}:{$port}", $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $context
        );
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($client === false) {
            $why = trim($errstr) !== '' ? trim($errstr) : "errno {$errno}";

            return CheckResult::down(mb_substr("TLS handshake failed: {$why}.", 0, 255), $ms);
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! $cert) {
            return CheckResult::down('The server presented no certificate.', $ms);
        }

        $parsed = openssl_x509_parse($cert);
        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return CheckResult::down('The certificate could not be parsed.', $ms);
        }

        $now = time();
        $expiresAt = (int) $parsed['validTo_time_t'];
        $startsAt = (int) ($parsed['validFrom_time_t'] ?? 0);

        if ($startsAt > $now) {
            return CheckResult::down('The certificate is not valid yet.', $ms);
        }
        if ($expiresAt <= $now) {
            return CheckResult::down('The certificate has expired.', $ms);
        }

        $daysLeft = (int) floor(($expiresAt - $now) / 86400);
        if ($daysLeft < $minDays) {
            return CheckResult::down("The certificate expires in {$daysLeft} day(s).", $ms);
        }

        return CheckResult::up($ms, null, "Valid for {$daysLeft} more day(s).");
    }

    private function minDays(string $expected): int
    {
        $expected = trim($expected);

        return ctype_digit($expected) ? max(0, (int) $expected) : self::DEFAULT_MIN_DAYS;
    }
}
