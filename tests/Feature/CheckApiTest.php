<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/v1/checks is the write path for an external checker. Without it,
 * nothing outside the panel's own session could ever create an incident.
 */
class CheckApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        [, $plain] = ApiToken::issue($user, 'test');

        return $plain;
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function monitor(array $attributes = []): Monitor
    {
        return Monitor::create(array_merge([
            'name' => 'Site',
            'type' => 'http',
            'target' => 'https://example.com',
            'interval_seconds' => 60,
            'timeout_seconds' => 5,
            'status' => 'up',
        ], $attributes));
    }

    public function test_posting_a_down_result_opens_an_incident(): void
    {
        $token = $this->tokenFor($this->admin());
        $monitor = $this->monitor();

        $this->withToken($token)
            ->postJson('/api/v1/checks', [
                'monitor_id' => $monitor->id,
                'status' => 'down',
                'message' => 'Probe in eu-west could not connect',
                'response_time_ms' => 4300,
            ])
            ->assertCreated();

        $this->assertDatabaseCount('checks', 1);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertSame('down', $monitor->fresh()->status);
    }

    public function test_posting_an_up_result_resolves_the_incident(): void
    {
        $token = $this->tokenFor($this->admin());
        $monitor = $this->monitor();

        $this->withToken($token)->postJson('/api/v1/checks', ['monitor_id' => $monitor->id, 'status' => 'down']);
        $this->withToken($token)->postJson('/api/v1/checks', ['monitor_id' => $monitor->id, 'status' => 'up'])->assertCreated();

        $this->assertNotNull(Incident::first()->resolved_at);
        $this->assertSame('up', $monitor->fresh()->status);
    }

    public function test_it_requires_a_token(): void
    {
        $monitor = $this->monitor();

        $this->postJson('/api/v1/checks', ['monitor_id' => $monitor->id, 'status' => 'up'])
            ->assertUnauthorized();
    }

    public function test_it_rejects_a_monitor_the_token_owner_cannot_see(): void
    {
        $mine = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('x'), 'role' => 'user']);
        $theirs = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => bcrypt('x'), 'role' => 'user']);

        $monitor = $this->monitor(['user_id' => $theirs->id]);

        $this->withToken($this->tokenFor($mine))
            ->postJson('/api/v1/checks', ['monitor_id' => $monitor->id, 'status' => 'down'])
            ->assertForbidden();

        $this->assertDatabaseCount('checks', 0);
    }

    public function test_it_validates_the_status(): void
    {
        $this->withToken($this->tokenFor($this->admin()))
            ->postJson('/api/v1/checks', ['monitor_id' => $this->monitor()->id, 'status' => 'sideways'])
            ->assertStatus(422);
    }
}
