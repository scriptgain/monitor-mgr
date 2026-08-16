<?php

namespace App\Checks;

use App\Models\Monitor;

/**
 * Resolves a name and, optionally, asserts on the answer.
 *
 * `expected` accepts three forms:
 *   ""            any answer of any type is up
 *   "A"           the name must have at least one A record
 *   "A=1.2.3.4"   an A record must equal that value
 *
 * MX and TXT compare against the target and full text respectively, which is
 * what an operator watching for a hijacked mail record actually wants.
 */
class DnsCheck implements CheckType
{
    private const TYPES = [
        'A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX,
        'NS' => DNS_NS, 'TXT' => DNS_TXT, 'SOA' => DNS_SOA, 'SRV' => DNS_SRV,
        'CAA' => DNS_CAA, 'PTR' => DNS_PTR,
    ];

    public function run(Monitor $monitor): CheckResult
    {
        $host = Target::host($monitor);
        if ($host === null) {
            return CheckResult::unavailable('Monitor target is not a usable host.');
        }

        [$type, $wanted] = $this->parse((string) $monitor->expected);
        if ($type !== null && ! isset(self::TYPES[$type])) {
            return CheckResult::unavailable("Unknown DNS record type \"{$type}\".");
        }

        $started = microtime(true);
        $records = @dns_get_record($host, $type !== null ? self::TYPES[$type] : DNS_ANY);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($records === false) {
            return CheckResult::unavailable('The panel host could not perform a DNS lookup.');
        }
        if ($records === []) {
            $what = $type !== null ? "{$type} record" : 'record';

            return CheckResult::down("No {$what} for {$host}.", $ms);
        }
        if ($wanted === null) {
            return CheckResult::up($ms);
        }

        foreach ($records as $record) {
            foreach ($this->values($record) as $value) {
                if (strcasecmp(rtrim($value, '.'), rtrim($wanted, '.')) === 0) {
                    return CheckResult::up($ms);
                }
            }
        }

        return CheckResult::down("No {$type} record matching \"{$wanted}\".", $ms);
    }

    /** Split "A=1.2.3.4" into a type and a value; either half may be absent. */
    private function parse(string $expected): array
    {
        $expected = trim($expected);
        if ($expected === '') {
            return [null, null];
        }
        if (! str_contains($expected, '=')) {
            $upper = strtoupper($expected);

            // A bare record type asserts presence; anything else is a value to
            // find, and A is the type an operator means by default.
            return isset(self::TYPES[$upper]) ? [$upper, null] : ['A', $expected];
        }

        [$type, $value] = explode('=', $expected, 2);

        return [strtoupper(trim($type)), trim($value)];
    }

    /** The comparable fields of one dns_get_record() row, across record types. */
    private function values(array $record): array
    {
        $out = [];
        foreach (['ip', 'ipv6', 'target', 'txt', 'value', 'mname', 'ns'] as $key) {
            if (isset($record[$key]) && is_string($record[$key])) {
                $out[] = $record[$key];
            }
        }

        return $out;
    }
}
