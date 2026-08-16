<?php

namespace Tests\Feature;

use App\Checks\CheckRunner;
use App\Checks\HttpCheck;
use App\Models\Monitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpCheckTest extends TestCase
{
    private function monitor(array $attributes = []): Monitor
    {
        $m = new Monitor;
        $m->forceFill(array_merge([
            'name' => 'Site',
            'type' => 'http',
            'target' => 'https://example.com',
            'interval_seconds' => 60,
            'timeout_seconds' => 5,
            'status' => 'up',
        ], $attributes));

        return $m;
    }

    public function test_a_200_is_up(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $result = (new HttpCheck)->run($this->monitor());

        $this->assertSame('up', $result->status);
        $this->assertSame(200, $result->statusCode);
        $this->assertTrue($result->conclusive);
    }

    public function test_a_500_is_down(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $result = (new HttpCheck)->run($this->monitor());

        $this->assertSame('down', $result->status);
        $this->assertSame(500, $result->statusCode);
        $this->assertStringContainsString('500', (string) $result->message);
    }

    public function test_a_404_is_up_when_it_is_the_expected_code(): void
    {
        Http::fake(['*' => Http::response('gone', 404)]);

        $result = (new HttpCheck)->run($this->monitor(['expected' => '404']));

        $this->assertSame('up', $result->status);
    }

    public function test_a_connection_failure_is_down_not_inconclusive(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

        $result = (new HttpCheck)->run($this->monitor());

        $this->assertSame('down', $result->status);
        $this->assertTrue($result->conclusive);
    }

    public function test_an_unusable_target_is_inconclusive(): void
    {
        $result = (new HttpCheck)->run($this->monitor(['target' => 'ftp://example.com']));

        $this->assertFalse($result->conclusive);
    }

    public function test_expected_code_matching(): void
    {
        $check = new HttpCheck;

        $this->assertTrue($check->accepts(200, ''));
        $this->assertTrue($check->accepts(301, ''));
        $this->assertFalse($check->accepts(404, ''));

        $this->assertTrue($check->accepts(204, '200,204'));
        $this->assertFalse($check->accepts(201, '200,204'));

        $this->assertTrue($check->accepts(204, '2xx'));
        $this->assertTrue($check->accepts(302, '2xx,3xx'));
        $this->assertFalse($check->accepts(404, '2xx,3xx'));
    }

    public function test_the_runner_maps_types_and_refuses_unpolled_ones(): void
    {
        $this->assertTrue(CheckRunner::isPolled('http'));
        $this->assertTrue(CheckRunner::isPolled('ssl'));
        $this->assertFalse(CheckRunner::isPolled('agent'));
        $this->assertFalse(CheckRunner::isPolled('heartbeat'));

        $result = CheckRunner::run($this->monitor(['type' => 'agent']));
        $this->assertFalse($result->conclusive);
    }
}
