<?php

namespace App\Http\Controllers;

use App\Models\Check;
use App\Models\Incident;
use App\Models\Monitor;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();
        $stats = [
            'monitors' => Monitor::visibleTo($user)->count(),
            'up' => Monitor::visibleTo($user)->where('status', 'up')->count(),
            'down' => Monitor::visibleTo($user)->where('status', 'down')->count(),
            'paused' => Monitor::visibleTo($user)->where('status', 'paused')->count(),
        ];

        $overallUptime = Monitor::visibleTo($user)->whereIn('status', ['up', 'down'])->avg('uptime_ratio');

        $openIncidents = Incident::visibleTo($user)->whereNull('resolved_at')->count();

        $avgResponseMs = Check::visibleTo($user)->where('checked_at', '>=', now()->subDay())->avg('response_time_ms');

        $downMonitors = Monitor::visibleTo($user)->where('status', 'down')->latest('last_checked_at')->limit(8)->get();

        $recentIncidents = Incident::visibleTo($user)->with('monitor')->latest('started_at')->limit(8)->get();

        // 14-day check activity for the dashboard chart. Pulled in one query and
        // bucketed per day in PHP so it stays portable across SQLite/MySQL.
        $since = now()->subDays(13)->startOfDay();
        $recentChecks = Check::visibleTo($user)
            ->where('checked_at', '>=', $since)
            ->get(['checked_at', 'status']);

        $activity = collect(range(0, 13))->map(function ($i) use ($recentChecks) {
            $day = now()->subDays(13 - $i)->startOfDay();
            $next = $day->copy()->addDay();
            $onDay = $recentChecks->filter(fn ($c) => $c->checked_at >= $day && $c->checked_at < $next);

            return [
                'label' => $day->format('M j'),
                'total' => $onDay->count(),
                'up' => $onDay->where('status', 'up')->count(),
                'down' => $onDay->where('status', 'down')->count(),
            ];
        })->all();

        $windowTotal = (int) array_sum(array_column($activity, 'total'));
        $windowUp = (int) array_sum(array_column($activity, 'up'));
        $successRate = $windowTotal ? (int) round($windowUp / $windowTotal * 100) : null;

        return view('dashboard', compact(
            'stats', 'overallUptime', 'openIncidents', 'avgResponseMs',
            'downMonitors', 'recentIncidents', 'activity', 'windowTotal', 'successRate',
        ));
    }
}
