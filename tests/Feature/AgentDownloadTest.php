<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Add Host" flow prints a curl one-liner pointing at these two routes.
 * Before this existed both 404'd, which blocked agent onboarding outright, so
 * the point of these tests is that they stay reachable without a session.
 */
class AgentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('setup_complete', '1');
    }

    public function test_the_installer_script_is_public_and_is_the_monitor_agent_one(): void
    {
        $response = $this->get('/downloads/agent-install.sh');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/x-shellscript; charset=utf-8');

        $body = $response->getContent();
        $this->assertStringContainsString('monitor-agent', $body);
        $this->assertStringContainsString('MonitorMGR agent installer', $body);
        // The old copy of this file was BackupMGR's, kopia and all.
        $this->assertStringNotContainsString('kopia', $body);
        $this->assertStringNotContainsString('backup-agent.service', $body);
    }

    public function test_the_binary_route_redirects_to_the_published_release_when_none_is_local(): void
    {
        config(['monitor.agent.binary_path' => '/nonexistent/monitor-agent']);
        config(['monitor.agent.download_url' => 'https://scriptgain.com/v1/monitor-agent/latest/monitor-agent-linux-amd64']);

        $this->get('/downloads/monitor-agent')
            ->assertRedirect('https://scriptgain.com/v1/monitor-agent/latest/monitor-agent-linux-amd64');
    }

    public function test_the_binary_route_serves_a_local_copy_when_one_exists(): void
    {
        $path = storage_path('framework/testing/monitor-agent');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "#!/bin/true\n");
        config(['monitor.agent.binary_path' => $path]);

        $this->get('/downloads/monitor-agent')->assertOk();

        @unlink($path);
    }

    public function test_the_binary_route_404s_when_nothing_is_configured(): void
    {
        config(['monitor.agent.binary_path' => '/nonexistent/monitor-agent']);
        config(['monitor.agent.download_url' => '']);

        $this->get('/downloads/monitor-agent')->assertNotFound();
    }
}
