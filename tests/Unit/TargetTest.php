<?php

namespace Tests\Unit;

use App\Checks\Target;
use App\Models\Monitor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TargetTest extends TestCase
{
    private function monitor(string $target, ?int $port = null): Monitor
    {
        $m = new Monitor;
        $m->target = $target;
        $m->port = $port;

        return $m;
    }

    #[DataProvider('hosts')]
    public function test_host_normalization(string $target, ?string $expected): void
    {
        $this->assertSame($expected, Target::host($this->monitor($target)));
    }

    public static function hosts(): array
    {
        return [
            'plain hostname' => ['example.com', 'example.com'],
            'strips the scheme' => ['https://example.com/status', 'example.com'],
            'ipv4' => ['203.0.113.10', '203.0.113.10'],
            'bracketed ipv6' => ['[2001:db8::1]', '2001:db8::1'],
            'trims whitespace' => ['  example.com  ', 'example.com'],
            'empty' => ['', null],
            // The ping check hands this to a process, so anything that could
            // carry a shell payload has to be refused before it gets there.
            'semicolon' => ['example.com; rm -rf /', null],
            'backtick' => ['`id`', null],
            'pipe' => ['example.com|whoami', null],
            'space' => ['example.com foo', null],
            'dollar' => ['$(id)', null],
        ];
    }

    public function test_url_adds_a_scheme_when_missing(): void
    {
        $this->assertSame('https://example.com', Target::url($this->monitor('example.com')));
    }

    public function test_url_keeps_an_explicit_scheme_and_path(): void
    {
        $this->assertSame('http://example.com/health', Target::url($this->monitor('http://example.com/health')));
    }

    public function test_url_applies_the_port_column_when_the_url_has_none(): void
    {
        $this->assertSame('https://example.com:8443/x', Target::url($this->monitor('https://example.com/x', 8443)));
    }

    public function test_url_does_not_override_a_port_already_in_the_url(): void
    {
        $this->assertSame('https://example.com:9000/x', Target::url($this->monitor('https://example.com:9000/x', 8443)));
    }

    public function test_url_rejects_other_schemes(): void
    {
        $this->assertNull(Target::url($this->monitor('ftp://example.com')));
        $this->assertNull(Target::url($this->monitor('file:///etc/passwd')));
    }

    public function test_port_falls_back_to_the_default(): void
    {
        $this->assertSame(443, Target::port($this->monitor('example.com'), 443));
        $this->assertSame(8443, Target::port($this->monitor('example.com', 8443), 443));
        $this->assertSame(443, Target::port($this->monitor('example.com', 99999), 443));
    }
}
