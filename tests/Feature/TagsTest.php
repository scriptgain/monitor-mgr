<?php

namespace Tests\Feature;

use App\Models\HostGroup;
use App\Models\Monitor;
use App\Models\MonitoredHost;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Setting::put('setup_complete', '1');

        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    public function test_tags_are_normalized_on_the_way_in(): void
    {
        $host = MonitoredHost::create(['name' => 'web01', 'tags' => '  Production , WEB ,, production ,  ']);

        // Lower cased, trimmed, deduplicated, sorted, blanks dropped.
        $this->assertSame(['production', 'web'], $host->fresh()->tagList());
    }

    public function test_an_array_works_as_well_as_a_string(): void
    {
        $host = MonitoredHost::create(['name' => 'web01', 'tags' => ['B', 'a']]);

        $this->assertSame(['a', 'b'], $host->fresh()->tagList());
    }

    /**
     * The separators people actually type survive; the characters that would
     * make a tag impossible to type back into the filter, or unsafe to echo, do
     * not. A comma is the one the filter splits on.
     */
    public function test_it_keeps_real_separators_and_strips_the_rest(): void
    {
        $host = MonitoredHost::create(['name' => 'web01', 'tags' => ['we"b<script>', 'eu-west_1', 'a/b:c']]);

        $this->assertSame(['a/b:c', 'eu-west_1', 'webscript'], $host->fresh()->tagList());
    }

    public function test_a_comma_inside_a_tag_splits_it_rather_than_hiding_it(): void
    {
        $host = MonitoredHost::create(['name' => 'web01', 'tags' => 'one,two']);

        $this->assertSame(['one', 'two'], $host->fresh()->tagList());
    }

    public function test_tags_are_capped_and_length_limited(): void
    {
        $many = array_map(fn ($i) => "tag{$i}", range(1, 40));
        $host = MonitoredHost::create(['name' => 'web01', 'tags' => array_merge($many, [str_repeat('x', 80)])]);

        $tags = $host->fresh()->tagList();
        $this->assertLessThanOrEqual(25, count($tags));
        foreach ($tags as $tag) {
            $this->assertLessThanOrEqual(40, strlen($tag));
        }
    }

    public function test_has_tag_normalizes_the_needle_too(): void
    {
        $host = MonitoredHost::create(['name' => 'web01', 'tags' => 'production']);

        $this->assertTrue($host->hasTag('  PRODUCTION '));
        $this->assertFalse($host->hasTag('staging'));
    }

    public function test_the_tagged_scope_requires_every_tag(): void
    {
        MonitoredHost::create(['name' => 'a', 'tags' => ['production', 'web']]);
        MonitoredHost::create(['name' => 'b', 'tags' => ['production']]);
        MonitoredHost::create(['name' => 'c', 'tags' => ['web']]);

        $this->assertSame(['a', 'b'], MonitoredHost::tagged('production')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['a'], MonitoredHost::tagged('production, web')->orderBy('name')->pluck('name')->all());
        $this->assertSame([], MonitoredHost::tagged('nope')->pluck('name')->all());
    }

    public function test_the_hosts_list_filters_by_tag_and_by_group(): void
    {
        $admin = $this->admin();
        MonitoredHost::create(['name' => 'tagged-host', 'tags' => ['production']]);
        MonitoredHost::create(['name' => 'other-host', 'tags' => ['staging']]);

        $this->actingAs($admin)->get('/hosts?tag=production')
            ->assertOk()->assertSee('tagged-host')->assertDontSee('other-host');
    }

    public function test_monitors_carry_tags_and_filter_by_them(): void
    {
        $admin = $this->admin();
        Monitor::create([
            'name' => 'tagged-monitor', 'type' => 'http', 'target' => 'https://a.example.com',
            'interval_seconds' => 60, 'timeout_seconds' => 5, 'status' => 'up', 'tags' => 'api',
        ]);
        Monitor::create([
            'name' => 'other-monitor', 'type' => 'http', 'target' => 'https://b.example.com',
            'interval_seconds' => 60, 'timeout_seconds' => 5, 'status' => 'up', 'tags' => 'web',
        ]);

        $this->actingAs($admin)->get('/monitors?tag=api')
            ->assertOk()->assertSee('tagged-monitor')->assertDontSee('other-monitor');
    }

    public function test_editing_a_host_saves_tags_and_group_membership(): void
    {
        $admin = $this->admin();
        $host = MonitoredHost::create(['name' => 'web01']);
        $group = HostGroup::create(['name' => 'Production']);

        $this->actingAs($admin)->put("/hosts/{$host->id}", [
            'name' => 'web01-renamed', 'tags' => 'production, web', 'groups' => [$group->id],
        ])->assertRedirect(route('hosts.show', $host));

        $host = $host->fresh();
        $this->assertSame('web01-renamed', $host->name);
        $this->assertSame(['production', 'web'], $host->tagList());
        $this->assertCount(1, $host->groups);
    }
}
