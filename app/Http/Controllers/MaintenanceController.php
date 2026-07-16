<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Metric;
use App\Models\Monitor;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    /** Ordered day-of-week tokens matching Carbon's lowercase `D` format. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public static function defaults(): array
    {
        return [
            'auto_maintenance' => '1',
            'maintenance_window_enabled' => '0',
            'maintenance_window_start' => '02:00',
            'maintenance_window_end' => '05:00',
            'maintenance_days' => implode(',', self::DAYS),
            'prune_telemetry' => '1',
            'telemetry_days' => '30',
            'prune_resolved_incidents' => '0',
            'incident_days' => '180',
            'audit_log_days' => '180',
        ];
    }

    public static function values(): array
    {
        $map = Setting::map();
        $v = [];
        foreach (static::defaults() as $key => $default) {
            $v[$key] = $map[$key] ?? $default;
        }

        return $v;
    }

    public static function allowedNow(?array $s = null, ?\DateTimeInterface $now = null): bool
    {
        $s ??= static::values();
        if (($s['auto_maintenance'] ?? '1') !== '1') {
            return false;
        }
        if (($s['maintenance_window_enabled'] ?? '0') !== '1') {
            return true;
        }

        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();

        $days = array_filter(explode(',', $s['maintenance_days'] ?? ''));
        if ($days && ! in_array(strtolower($now->format('D')), $days, true)) {
            return false;
        }

        $start = $s['maintenance_window_start'] ?? '00:00';
        $end = $s['maintenance_window_end'] ?? '23:59';
        $cur = $now->format('H:i');

        return $start <= $end
            ? ($cur >= $start && $cur <= $end)
            : ($cur >= $start || $cur <= $end);
    }

    public static function runSweep(?array $s = null): array
    {
        $s ??= static::values();
        $counts = ['telemetry_pruned' => 0, 'incidents_pruned' => 0, 'audit_pruned' => 0];

        // 1. Prune old check + metric history (telemetry).
        if (($s['prune_telemetry'] ?? '1') === '1') {
            $days = max(1, (int) ($s['telemetry_days'] ?? 30));
            $cutoff = now()->subDays($days);
            $counts['telemetry_pruned'] = Check::where('checked_at', '<', $cutoff)->delete()
                + Metric::where('recorded_at', '<', $cutoff)->delete();
        }

        // 2. Prune long-resolved incidents (history only; open incidents untouched).
        if (($s['prune_resolved_incidents'] ?? '0') === '1') {
            $days = max(1, (int) ($s['incident_days'] ?? 180));
            $counts['incidents_pruned'] = Incident::whereNotNull('resolved_at')
                ->where('resolved_at', '<', now()->subDays($days))
                ->delete();
        }

        // 3. Prune old audit rows.
        $auditDays = (int) ($s['audit_log_days'] ?? 180);
        if ($auditDays > 0) {
            $counts['audit_pruned'] = AuditLog::where('created_at', '<', now()->subDays($auditDays))->delete();
        }

        return $counts;
    }

    public function edit()
    {
        $v = static::values();

        return view('settings.maintenance', [
            'v' => $v,
            'days' => self::DAYS,
            'selectedDays' => array_filter(explode(',', $v['maintenance_days'])),
            'allowedNow' => static::allowedNow($v),
            'now' => now(),
            'stats' => [
                'Monitors' => Monitor::count(),
                'Open Incidents' => Incident::whereNull('resolved_at')->count(),
                'Telemetry Rows' => Check::count() + Metric::count(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'maintenance_window_start' => ['required', 'date_format:H:i'],
            'maintenance_window_end' => ['required', 'date_format:H:i'],
            'maintenance_days' => ['nullable', 'array'],
            'maintenance_days.*' => [Rule::in(self::DAYS)],
            'telemetry_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'incident_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'audit_log_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        foreach (['auto_maintenance', 'maintenance_window_enabled', 'prune_telemetry', 'prune_resolved_incidents'] as $t) {
            Setting::put($t, $request->boolean($t) ? '1' : '0');
        }

        Setting::put('maintenance_window_start', $data['maintenance_window_start']);
        Setting::put('maintenance_window_end', $data['maintenance_window_end']);
        Setting::put('maintenance_days', implode(',', $data['maintenance_days'] ?? []));
        Setting::put('telemetry_days', (string) $data['telemetry_days']);
        Setting::put('incident_days', (string) $data['incident_days']);
        Setting::put('audit_log_days', (string) $data['audit_log_days']);

        AuditLog::record('updated', 'Maintenance settings updated');

        return back()->with('status', 'Maintenance settings saved.');
    }

    public function runNow()
    {
        $c = static::runSweep();
        AuditLog::record('maintenance', "Manual maintenance: {$c['telemetry_pruned']} telemetry rows, {$c['incidents_pruned']} incidents, {$c['audit_pruned']} audit rows pruned");

        return back()->with('status', "Maintenance ran: {$c['telemetry_pruned']} telemetry row(s), {$c['incidents_pruned']} resolved incident(s), {$c['audit_pruned']} audit row(s) pruned.");
    }
}
