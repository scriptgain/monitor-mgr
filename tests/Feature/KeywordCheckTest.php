<?php

namespace Tests\Feature;

use App\Checks\KeywordCheck;
use App\Models\Monitor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeywordCheckTest extends TestCase
{
    private function monitor(?string $expected): Monitor
    {
        $m = new Monitor;
        $m->forceFill([
            'name' => 'Site',
            'type' => 'keyword',
            'target' => 'https://example.com',
            'interval_seconds' => 60,
            'timeout_seconds' => 5,
            'expected' => $expected,
            'status' => 'up',
        ]);

        return $m;
    }

    public function test_present_keyword_is_up(): void
    {
        Http::fake(['*' => Http::response('<h1>All Systems Operational</h1>', 200)]);

        $this->assertSame('up', (new KeywordCheck)->run($this->monitor('Operational'))->status);
    }

    public function test_matching_ignores_case(): void
    {
        Http::fake(['*' => Http::response('<h1>ALL SYSTEMS OPERATIONAL</h1>', 200)]);

        $this->assertSame('up', (new KeywordCheck)->run($this->monitor('operational'))->status);
    }

    public function test_missing_keyword_is_down(): void
    {
        Http::fake(['*' => Http::response('<h1>Maintenance</h1>', 200)]);

        $result = (new KeywordCheck)->run($this->monitor('Operational'));

        $this->assertSame('down', $result->status);
        $this->assertStringContainsString('Keyword not found', (string) $result->message);
    }

    public function test_a_bang_prefix_inverts_the_match(): void
    {
        Http::fake(['*' => Http::response('Fatal error: database is gone', 200)]);

        $result = (new KeywordCheck)->run($this->monitor('!Fatal error'));

        $this->assertSame('down', $result->status);
        $this->assertStringContainsString('Forbidden keyword found', (string) $result->message);
    }

    public function test_an_error_status_is_down_before_the_body_is_read(): void
    {
        Http::fake(['*' => Http::response('Operational', 503)]);

        $result = (new KeywordCheck)->run($this->monitor('Operational'));

        $this->assertSame('down', $result->status);
        $this->assertSame(503, $result->statusCode);
    }

    public function test_no_keyword_configured_is_inconclusive(): void
    {
        Http::fake(['*' => Http::response('anything', 200)]);

        $this->assertFalse((new KeywordCheck)->run($this->monitor(null))->conclusive);
    }
}
