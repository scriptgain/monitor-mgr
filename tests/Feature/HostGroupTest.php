<?php

namespace Tests\Feature;

use App\Models\DowntimeWindow;
use App\Models\HostGroup;
use App\Models\HostMetric;
use App\Models\Incident;
use App\Models\MonitoredHost;
use App\Models\Setting;
use App\Models\Trigger;
use App\Models\User;
use App\Services\TriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HostGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Trigger::query()->delete();
        Mail::fake();
    }

    private function admin(): User
    {
        Setting::put('setup_complete', '1');

        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function host(string $name = 'web01'): MonitoredHost
    {
        $host = MonitoredHost::create(['name' => $name]);
        $host->forceFill(['api_key' => hash('sha256', $name), 'last_seen_at' => now()])->save();

        return $host;
    }

    private function samples(MonitoredHost $host, float $cpu): void
    {
        for ($i = 5; $i >= 0; $i--) {
            HostMetric::create([
                'monitored_host_id' => $host->id, 'captured_at' => now()->subSeconds($i * 120),
                'cpu_pct' => $cpu, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
            ]);
        }
    }

    private function trigger(array $attributes = []): Trigger
    {
        return Trigger::create(array_merge([
            'name' => 'CPU high', 'metric' => 'cpu_pct', 'operator' => '>',
            'threshold' => 90, 'for_seconds' => 300, 'severity' => 'high', 'is_enabled' => true,
        ], $attributes));
    }

    public function test_a_host_can_be_in_several_groups(): void
    {
        $host = $this->host();
        $prod = HostGroup::create(['name' => 'Production']);
        $db = HostGroup::create(['name' => 'Database']);

        $host->groups()->sync([$prod->id, $db->id]);

        $this->assertCount(2, $host->fresh()->groups);
        $this->assertSame(1, $prod->hosts()->count());
    }

    /** The point of groups: one rule for a set of boxes. */
    public function test_a_group_rule_applies_to_its_members_and_nobody_else(): void
    {
        $member = $this->host('web01');
        $stranger = $this->host('db01');
        $group = HostGroup::create(['name' => 'Web']);
        $member->groups()->sync([$group->id]);

        $this->trigger(['name' => 'Web CPU', 'host_group_id' => $group->id]);

        $this->assertCount(1, TriggerEvaluator::rulesFor($member));
        $this->assertCount(0, TriggerEvaluator::rulesFor($stranger));
    }

    public function test_specificity_wins_host_over_group_over_fleet(): void
    {
        $host = $this->host();
        $group = HostGroup::create(['name' => 'Web']);
        $host->groups()->sync([$group->id]);

        $this->trigger(['name' => 'Fleet', 'threshold' => 90]);
        $this->trigger(['name' => 'Group', 'threshold' => 95, 'host_group_id' => $group->id]);

        $rules = TriggerEvaluator::rulesFor($host);
        $this->assertCount(1, $rules);
        $this->assertSame('Group', $rules->first()->name);

        $this->trigger(['name' => 'Host', 'threshold' => 98, 'monitored_host_id' => $host->id]);

        $rules = TriggerEvaluator::rulesFor($host);
        $this->assertCount(1, $rules);
        $this->assertSame('Host', $rules->first()->name);
    }

    public function test_the_group_rule_is_the_one_that_actually_fires(): void
    {
        $host = $this->host();
        $group = HostGroup::create(['name' => 'Runs hot']);
        $host->groups()->sync([$group->id]);

        $this->trigger(['name' => 'Fleet', 'threshold' => 90]);
        $this->trigger(['name' => 'Runs hot', 'threshold' => 98, 'host_group_id' => $group->id]);
        $this->samples($host, 95);

        TriggerEvaluator::forHost($host);

        // 95 breaches the fleet rule and not the group rule, and the group rule
        // is the one in force, so nothing fires.
        $this->assertDatabaseCount('incidents', 0);
    }

    /** Two groups naming the same metric is genuinely ambiguous; the stricter one wins. */
    public function test_two_group_rules_on_one_metric_resolve_to_the_lower_threshold(): void
    {
        $host = $this->host();
        $a = HostGroup::create(['name' => 'A']);
        $b = HostGroup::create(['name' => 'B']);
        $host->groups()->sync([$a->id, $b->id]);

        $this->trigger(['name' => 'Lenient', 'threshold' => 95, 'host_group_id' => $a->id]);
        $this->trigger(['name' => 'Strict', 'threshold' => 85, 'host_group_id' => $b->id]);

        $rules = TriggerEvaluator::rulesFor($host);
        $this->assertCount(1, $rules);
        $this->assertSame('Strict', $rules->first()->name);
    }

    public function test_a_group_downtime_window_covers_a_member_host_incident(): void
    {
        $member = $this->host('web01');
        $stranger = $this->host('db01');
        $group = HostGroup::create(['name' => 'Web']);
        $member->groups()->sync([$group->id]);

        $window = DowntimeWindow::create([
            'name' => 'Patching', 'kind' => 'once', 'host_group_id' => $group->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => true,
        ]);

        $covered = Incident::create(['monitored_host_id' => $member->id, 'started_at' => now(), 'severity' => 'high']);
        $notCovered = Incident::create(['monitored_host_id' => $stranger->id, 'started_at' => now(), 'severity' => 'high']);

        $this->assertTrue($window->coversSubject($covered));
        $this->assertFalse($window->coversSubject($notCovered));
    }

    public function test_deleting_a_group_takes_its_rules_and_leaves_its_hosts(): void
    {
        $host = $this->host();
        $group = HostGroup::create(['name' => 'Doomed']);
        $host->groups()->sync([$group->id]);
        $this->trigger(['name' => 'Group rule', 'host_group_id' => $group->id]);

        $group->delete();

        $this->assertDatabaseCount('triggers', 0);
        $this->assertNotNull($host->fresh());
        $this->assertCount(0, $host->fresh()->groups);
    }

    public function test_the_group_screens_render_and_create(): void
    {
        $admin = $this->admin();
        $host = $this->host();

        $this->actingAs($admin)->get('/host-groups')->assertOk();
        $this->actingAs($admin)->get('/host-groups/create')->assertOk();

        $this->actingAs($admin)->post('/host-groups', [
            'name' => 'Production', 'color' => 'success', 'hosts' => [$host->id],
        ])->assertRedirect('/host-groups');

        $group = HostGroup::where('name', 'Production')->first();
        $this->assertSame(1, $group->hosts()->count());
    }

    /** A submitted id must never attach a host the user cannot see. */
    public function test_membership_is_scoped_to_hosts_the_user_can_see(): void
    {
        Setting::put('setup_complete', '1');
        $mine = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('x'), 'role' => 'user']);
        $theirs = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => bcrypt('x'), 'role' => 'user']);

        $hidden = MonitoredHost::create(['name' => 'not-yours', 'user_id' => $theirs->id]);

        $this->actingAs($mine)->post('/host-groups', ['name' => 'Mine', 'hosts' => [$hidden->id]])
            ->assertRedirect('/host-groups');

        $this->assertSame(0, HostGroup::where('name', 'Mine')->first()->hosts()->count());
    }
}
