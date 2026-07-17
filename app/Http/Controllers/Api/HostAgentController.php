<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HostMetric;
use App\Models\MonitoredHost;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Agent-facing API for MonitorMGR host agents. Agents dial out to these
// endpoints. Enroll is token-based; ingest uses the per-host agent key.
class HostAgentController extends Controller
{
    /** Trade a one-time enrollment token for a permanent agent API key. */
    public function enroll(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'hostname' => ['nullable', 'string'],
            'os' => ['nullable', 'string'],
            'arch' => ['nullable', 'string'],
            'agent_version' => ['nullable', 'string'],
        ]);

        $host = MonitoredHost::where('enrollment_token', hash('sha256', $data['token']))->first();
        if (! $host) {
            return response()->json(['message' => 'Invalid or used enrollment token.'], 401);
        }

        $plainKey = 'mon_'.Str::random(48);
        $host->forceFill([
            'api_key' => hash('sha256', $plainKey),
            'enrollment_token' => null,
            'status' => 'online',
            'os' => $data['os'] ?? $host->os,
            'arch' => $data['arch'] ?? $host->arch,
            'agent_version' => $data['agent_version'] ?? $host->agent_version,
            'hostname' => $host->hostname ?: ($data['hostname'] ?? null),
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['host_id' => (string) $host->id, 'api_key' => $plainKey]);
    }

    /** Receive and store one metrics snapshot, updating the host's liveness. */
    public function ingest(Request $request)
    {
        $host = $request->attributes->get('agent_host');

        $data = $request->validate([
            'hostname' => ['nullable', 'string'],
            'os' => ['nullable', 'string'],
            'arch' => ['nullable', 'string'],
            'agent_version' => ['nullable', 'string'],
            'cpu_pct' => ['required', 'numeric'],
            'cpu_cores' => ['nullable', 'integer'],
            'mem_used' => ['nullable', 'integer'],
            'mem_total' => ['nullable', 'integer'],
            'swap_used' => ['nullable', 'integer'],
            'swap_total' => ['nullable', 'integer'],
            'disk_used' => ['nullable', 'integer'],
            'disk_total' => ['nullable', 'integer'],
            'load1' => ['nullable', 'numeric'],
            'load5' => ['nullable', 'numeric'],
            'load15' => ['nullable', 'numeric'],
            'uptime_seconds' => ['nullable', 'integer'],
            'boot_time' => ['nullable', 'integer'],
            'net_rx_bytes_sec' => ['nullable', 'integer'],
            'net_tx_bytes_sec' => ['nullable', 'integer'],
            'disks' => ['nullable', 'array'],
            'cores' => ['nullable', 'array'],
        ]);

        HostMetric::create([
            'monitored_host_id' => $host->id,
            'captured_at' => now(),
            'cpu_pct' => $data['cpu_pct'],
            'mem_used' => $data['mem_used'] ?? 0,
            'mem_total' => $data['mem_total'] ?? 0,
            'swap_used' => $data['swap_used'] ?? 0,
            'swap_total' => $data['swap_total'] ?? 0,
            'disk_used' => $data['disk_used'] ?? 0,
            'disk_total' => $data['disk_total'] ?? 0,
            'load1' => $data['load1'] ?? 0,
            'load5' => $data['load5'] ?? 0,
            'load15' => $data['load15'] ?? 0,
            'uptime' => $data['uptime_seconds'] ?? 0,
            'net_rx' => $data['net_rx_bytes_sec'] ?? 0,
            'net_tx' => $data['net_tx_bytes_sec'] ?? 0,
            'detail' => ['disks' => $data['disks'] ?? [], 'cores' => $data['cores'] ?? []],
        ]);

        $host->forceFill([
            'last_seen_at' => now(),
            'status' => 'online',
            'os' => $data['os'] ?? $host->os,
            'arch' => $data['arch'] ?? $host->arch,
            'cpu_cores' => $data['cpu_cores'] ?? $host->cpu_cores,
            'agent_version' => $data['agent_version'] ?? $host->agent_version,
            'hostname' => $host->hostname ?: ($data['hostname'] ?? null),
            'boot_time' => isset($data['boot_time']) && $data['boot_time'] > 0
                ? Carbon::createFromTimestamp($data['boot_time']) : $host->boot_time,
        ])->save();

        $this->prune($host);

        $interval = (int) (\App\Models\Setting::get('host_agent_interval') ?: 0);

        return response()->json(['interval_seconds' => $interval > 0 ? $interval : null]);
    }

    /**
     * Keep the time series bounded: drop samples older than the retention window
     * so history never grows without limit. Runs cheaply on every ingest.
     */
    private function prune(MonitoredHost $host): void
    {
        $days = max(1, (int) config('monitor.metrics_retention_days', 7));
        HostMetric::where('monitored_host_id', $host->id)
            ->where('captured_at', '<', now()->subDays($days))
            ->delete();
    }
}
