<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Read-only public demo data for MonitorMGR: monitored hosts + metrics,
 *  uptime monitors with recent checks, and incidents. Idempotent. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['checks', 'incidents', 'host_metrics', 'metrics', 'monitors', 'monitored_hosts', 'alert_contacts', 'status_pages'] as $t) {
            if (DB::getSchemaBuilder()->hasTable($t)) DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $uid = DB::table('users')->where('email', 'demo@scriptgain.com')->value('id')
            ?? DB::table('users')->insertGetId(['name' => 'Demo Admin', 'email' => 'demo@scriptgain.com', 'password' => Hash::make(Str::random(40)), 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('settings')->updateOrInsert(['key' => 'setup_complete'], ['value' => '1']);

        $hosts = [
            ['web-prod-01', 'ubuntu 22.04'], ['db-primary', 'debian 12'], ['app-node-2', 'ubuntu 22.04'],
            ['mail-gw', 'rocky 9'], ['file-server', 'ubuntu 20.04'], ['edge-proxy', 'ubuntu 22.04'],
            ['redis-cache', 'debian 12'], ['analytics-01', 'ubuntu 22.04'],
        ];
        foreach ($hosts as [$hn, $os]) {
            $online = random_int(1, 100) <= 75;
            $hid = DB::table('monitored_hosts')->insertGetId([
                'user_id' => $uid, 'name' => $hn, 'hostname' => $hn.'.internal', 'os' => $os, 'arch' => 'x86_64',
                'cpu_cores' => [2, 4, 8, 16][random_int(0, 3)], 'api_key' => 'mk_'.Str::random(32),
                'agent_version' => '1.1.0', 'boot_time' => now()->subDays(random_int(3, 90)),
                'last_seen_at' => $online ? now()->subSeconds(random_int(5, 90)) : now()->subHours(random_int(2, 30)),
                'status' => $online ? 'online' : 'offline', 'created_at' => now()->subDays(random_int(10, 60)), 'updated_at' => now(),
            ]);
            $memTotal = 8589934592 * random_int(1, 8);
            $diskTotal = 107374182400 * random_int(1, 10);
            DB::table('host_metrics')->insert([
                'monitored_host_id' => $hid, 'captured_at' => now()->subSeconds(random_int(10, 60)),
                'cpu_pct' => random_int(3, 88), 'mem_used' => (int) ($memTotal * random_int(30, 85) / 100), 'mem_total' => $memTotal,
                'swap_used' => 0, 'swap_total' => 2147483648, 'disk_used' => (int) ($diskTotal * random_int(20, 80) / 100), 'disk_total' => $diskTotal,
                'load1' => random_int(0, 400) / 100, 'load5' => random_int(0, 350) / 100, 'load15' => random_int(0, 300) / 100,
                'uptime' => random_int(3, 90) * 86400, 'net_rx' => random_int(1e6, 9e8), 'net_tx' => random_int(1e6, 9e8),
                'detail' => json_encode(['agent' => '1.1.0']),
            ]);
        }

        $monDefs = [
            ['Main Website', 'http', 'https://example.com', 443], ['API Gateway', 'http', 'https://api.example.com/health', 443],
            ['Customer Portal', 'http', 'https://portal.example.com', 443], ['Marketing Site', 'http', 'https://www.example.com', 443],
            ['Postgres Primary', 'tcp', 'db-primary.internal', 5432], ['Redis Cache', 'tcp', 'redis-cache.internal', 6379],
            ['SMTP Relay', 'tcp', 'mail-gw.internal', 25], ['Edge Proxy', 'ping', 'edge-proxy.internal', null],
            ['Status Page', 'http', 'https://status.example.com', 443], ['Checkout Service', 'http', 'https://pay.example.com/ping', 443],
            ['Search Cluster', 'tcp', 'search.internal', 9200], ['Docs Site', 'http', 'https://docs.example.com', 443],
        ];
        foreach ($monDefs as [$name, $type, $target, $port]) {
            $roll = random_int(1, 100);
            $status = $roll <= 82 ? 'up' : ($roll <= 94 ? 'down' : 'paused');
            $ratio = $status === 'up' ? random_int(9900, 10000) / 100 : random_int(9400, 9900) / 100;
            $mid = DB::table('monitors')->insertGetId([
                'user_id' => $uid, 'name' => $name, 'type' => $type, 'target' => $target, 'port' => $port,
                'interval_seconds' => 60, 'timeout_seconds' => 15, 'expected' => $type === 'http' ? '200' : null,
                'status' => $status, 'last_checked_at' => now()->subSeconds(random_int(5, 60)),
                'uptime_ratio' => $ratio, 'created_at' => now()->subDays(random_int(20, 90)), 'updated_at' => now(),
            ]);
            $rows = [];
            for ($i = 0; $i < 30; $i++) {
                $cs = ($status !== 'up' && $i < 3) ? 'down' : (random_int(1, 100) <= 4 ? 'down' : 'up');
                $rows[] = [
                    'monitor_id' => $mid, 'checked_at' => now()->subMinutes($i * random_int(1, 3)),
                    'status' => $cs, 'response_time_ms' => $cs === 'up' ? random_int(30, 900) : null,
                    'status_code' => $type === 'http' ? ($cs === 'up' ? 200 : [500, 502, 503, 0][random_int(0, 3)]) : null,
                    'message' => $cs === 'up' ? 'OK' : 'connection timed out', 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('checks')->insert($rows);

            if ($status === 'down') {
                DB::table('incidents')->insert([
                    'monitor_id' => $mid, 'started_at' => now()->subMinutes(random_int(5, 40)), 'resolved_at' => null,
                    'duration_seconds' => null, 'cause' => 'connection timed out', 'acknowledged_at' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (random_int(1, 100) <= 40) {
                $dur = random_int(120, 3600);
                DB::table('incidents')->insert([
                    'monitor_id' => $mid, 'started_at' => now()->subDays(random_int(1, 20)), 'resolved_at' => now()->subDays(random_int(1, 20)),
                    'duration_seconds' => $dur, 'cause' => ['HTTP 503', 'timeout', 'DNS failure'][random_int(0, 2)], 'acknowledged_at' => now()->subDays(random_int(1, 20)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach ([['On-Call Email', 'email', 'oncall@example.com'], ['Ops Slack', 'webhook', 'https://hooks.slack.com/xxx'], ['SMS Escalation', 'sms', '+15555550142']] as [$n, $t, $tg]) {
            DB::table('alert_contacts')->insert(['user_id' => $uid, 'name' => $n, 'type' => $t, 'target' => $tg, 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->command?->info('Monitor demo seeded: '.count($hosts).' hosts, '.count($monDefs).' monitors.');
    }
}
