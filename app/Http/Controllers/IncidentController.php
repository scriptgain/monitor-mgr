<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Incident;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $status = $request->query('status');
        $query = Incident::visibleTo($user)->with('monitor');
        if ($status === 'open') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }
        $incidents = $query->latest('started_at')->paginate(25)->withQueryString();

        $stats = [
            'open' => Incident::visibleTo($user)->whereNull('resolved_at')->count(),
            'resolved' => Incident::visibleTo($user)->whereNotNull('resolved_at')->count(),
            'unacknowledged' => Incident::visibleTo($user)->whereNull('resolved_at')->whereNull('acknowledged_at')->count(),
        ];

        return view('incidents.index', compact('incidents', 'status', 'stats'));
    }

    public function show(Incident $incident)
    {
        $this->guard($incident);
        $incident->load('monitor');

        return view('incidents.show', compact('incident'));
    }

    public function acknowledge(Incident $incident)
    {
        $this->guard($incident);
        $incident->update(['acknowledged_at' => now()]);
        AuditLog::record('incident', "Acknowledged incident #{$incident->id}");

        return back()->with('status', 'Incident acknowledged.');
    }

    public function resolve(Incident $incident)
    {
        $this->guard($incident);
        if (! $incident->resolved_at) {
            $incident->update([
                'resolved_at' => now(),
                'duration_seconds' => (int) ($incident->started_at ? $incident->started_at->diffInSeconds(now()) : 0),
            ]);
            if ($incident->monitor && $incident->monitor->status === 'down') {
                $incident->monitor->update(['status' => 'up']);
            }
            AuditLog::record('incident', "Resolved incident #{$incident->id}");
        }

        return back()->with('status', 'Incident resolved.');
    }

    /**
     * Bulk-delete selected incidents (downtime history rows). Only operates on
     * the ids explicitly submitted, scoped to what the current user may see.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $user = auth()->user();
        $ids = Incident::visibleTo($user)->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching incidents were selected.');
        }

        $count = Incident::visibleTo($user)->whereIn('id', $ids->all())->delete();

        AuditLog::record('incident', "Bulk deleted {$count} incident".($count === 1 ? '' : 's').'.');

        return back()->with('status', $count.' incident'.($count === 1 ? '' : 's').' deleted.');
    }

    private function guard(Incident $incident): void
    {
        abort_unless($incident->isVisibleTo(auth()->user()), 403);
    }
}
