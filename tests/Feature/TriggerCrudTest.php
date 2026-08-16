<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Trigger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriggerCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Setting::put('setup_complete', '1');

        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Disk high',
            'monitored_host_id' => null,
            'metric' => 'disk_pct',
            'operator' => '>',
            'threshold' => 90,
            'recovery_threshold' => 85,
            'for_seconds' => 300,
            'severity' => 'high',
            'is_enabled' => '1',
        ], $overrides);
    }

    public function test_a_fresh_install_ships_with_working_default_rules(): void
    {
        $this->assertGreaterThanOrEqual(5, Trigger::count());
        $this->assertTrue(Trigger::where('metric', 'agent_offline')->where('is_enabled', true)->exists());
        // Global, so they cover hosts enrolled before the rules existed.
        $this->assertSame(0, Trigger::whereNotNull('monitored_host_id')->count());
    }

    public function test_the_index_and_form_render(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/triggers')->assertOk()->assertSee('Triggers');
        $this->actingAs($admin)->get('/triggers/create')->assertOk()->assertSee('Sustained For');
    }

    public function test_it_creates_a_trigger(): void
    {
        $this->actingAs($this->admin())->post('/triggers', $this->payload())->assertRedirect('/triggers');

        $trigger = Trigger::where('name', 'Disk high')->first();
        $this->assertNotNull($trigger);
        $this->assertSame(90.0, $trigger->threshold);
        $this->assertSame(85.0, $trigger->recovery_threshold);
        $this->assertTrue($trigger->is_enabled);
    }

    /**
     * A recovery point above the threshold on an "is above" rule can never be
     * reached, so the incident it opens would stay open forever.
     */
    public function test_it_refuses_a_recovery_value_that_can_never_be_reached(): void
    {
        $this->actingAs($this->admin())
            ->post('/triggers', $this->payload(['recovery_threshold' => 95]))
            ->assertSessionHasErrors('recovery_threshold');

        $this->assertNull(Trigger::where('name', 'Disk high')->first());
    }

    public function test_it_refuses_the_mirror_case_on_a_below_rule(): void
    {
        $this->actingAs($this->admin())
            ->post('/triggers', $this->payload(['operator' => '<', 'threshold' => 10, 'recovery_threshold' => 5]))
            ->assertSessionHasErrors('recovery_threshold');
    }

    public function test_a_blank_recovery_value_is_allowed(): void
    {
        $this->actingAs($this->admin())
            ->post('/triggers', $this->payload(['recovery_threshold' => null]))
            ->assertRedirect('/triggers');

        $this->assertNull(Trigger::where('name', 'Disk high')->first()->recovery_threshold);
    }

    public function test_it_accepts_a_per_mount_metric(): void
    {
        $this->actingAs($this->admin())
            ->post('/triggers', $this->payload(['metric' => 'disk./var/lib.pct']))
            ->assertRedirect('/triggers');

        $this->assertSame('/var/lib', Trigger::where('name', 'Disk high')->first()->mountPoint());
    }

    public function test_it_refuses_a_metric_that_is_not_a_metric(): void
    {
        $this->actingAs($this->admin())
            ->post('/triggers', $this->payload(['metric' => '../../etc/passwd']))
            ->assertSessionHasErrors('metric');
    }

    public function test_bulk_disable_and_delete(): void
    {
        $admin = $this->admin();
        $ids = Trigger::pluck('id')->all();

        $this->actingAs($admin)->post('/triggers/bulk', ['ids' => $ids, 'action' => 'disable'])->assertRedirect();
        $this->assertSame(0, Trigger::where('is_enabled', true)->count());

        $this->actingAs($admin)->post('/triggers/bulk', ['ids' => $ids, 'action' => 'delete'])->assertRedirect();
        $this->assertSame(0, Trigger::count());
    }

    public function test_a_non_admin_cannot_see_another_users_trigger(): void
    {
        Setting::put('setup_complete', '1');
        $mine = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('x'), 'role' => 'user']);
        $theirs = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => bcrypt('x'), 'role' => 'user']);

        $trigger = Trigger::create($this->payload(['user_id' => $theirs->id, 'is_enabled' => true]));

        $this->actingAs($mine)->get("/triggers/{$trigger->id}/edit")->assertForbidden();
    }
}
