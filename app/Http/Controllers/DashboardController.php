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
            'up' => Monitor::visibleTo($user)->where('status', 'up')->count(),
            'down' => Monitor::visibleTo($user)->where('status', 'down')->count(),
            'paused' => Monitor::visibleTo($user)->where('status', 'paused')->count(),
        ];

        $overallUptime = Monitor::visibleTo($user)->whereIn('status', ['up', 'down'])->avg('uptime_ratio');

        $openIncidents = Incident::visibleTo($user)->whereNull('resolved_at')->count();

        $avgResponseMs = Check::visibleTo($user)->where('checked_at', '>=', now()->subDay())->avg('response_time_ms');

        $downMonitors = Monitor::visibleTo($user)->where('status', 'down')->latest('last_checked_at')->limit(8)->get();

        $recentIncidents = Incident::visibleTo($user)->with('monitor')->latest('started_at')->limit(8)->get();

        return view('dashboard', compact('stats', 'overallUptime', 'openIncidents', 'avgResponseMs', 'downMonitors', 'recentIncidents'));
    }
}
