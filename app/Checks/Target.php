<?php

namespace App\Checks;

use App\Models\Monitor;

/**
 * Normalizes the free-text `target` column into something each check type can
 * actually use, and refuses anything that is not a plain host or URL.
 *
 * The refusal matters: `target` is operator-supplied and PingCheck hands it to
 * a shell, so a host that does not match this pattern never reaches exec().
 */
class Target
{
    /** A hostname, IPv4, or bracketed IPv6 literal. No scheme, path, or spaces. */
    public static function host(Monitor $monitor): ?string
    {
        $raw = trim((string) $monitor->target);
        if ($raw === '') {
            return null;
        }

        // Accept a URL in the target field and take its host.
        if (str_contains($raw, '://')) {
            $raw = (string) (parse_url($raw, PHP_URL_HOST) ?: '');
        }

        $raw = trim($raw, '[]');
        if ($raw === '' || strlen($raw) > 253) {
            return null;
        }

        $isHostname = (bool) preg_match('/^(?!-)[A-Za-z0-9_-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9_-]{1,63}(?<!-))*\.?$/', $raw);
        $isIp = filter_var($raw, FILTER_VALIDATE_IP) !== false;

        return ($isHostname || $isIp) ? $raw : null;
    }

    /** An absolute http(s) URL. Adds a scheme when the operator omitted one. */
    public static function url(Monitor $monitor): ?string
    {
        $raw = trim((string) $monitor->target);
        if ($raw === '') {
            return null;
        }
        if (! str_contains($raw, '://')) {
            $raw = 'https://'.$raw;
        }

        $parts = parse_url($raw);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        if (($parts['host'] ?? '') === '') {
            return null;
        }

        // An explicit port column wins only when the URL did not carry one.
        if ($monitor->port && ! isset($parts['port'])) {
            $raw = preg_replace('~^(https?://[^/?#]+)~i', '$1:'.(int) $monitor->port, $raw, 1);
        }

        return $raw;
    }

    /** The port to connect to, falling back to the given default. */
    public static function port(Monitor $monitor, int $default): int
    {
        $port = (int) $monitor->port;

        return ($port >= 1 && $port <= 65535) ? $port : $default;
    }
}
