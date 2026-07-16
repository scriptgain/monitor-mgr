<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        return Incident::visibleTo($request->user())
            ->with('monitor:id,name')
            ->when($request->integer('monitor_id'), fn ($q, $id) => $q->where('monitor_id', $id))
            ->latest('started_at')
            ->paginate(50);
    }

    public function show(Incident $incident)
    {
        abort_unless($incident->isVisibleTo(auth()->user()), 403);

        return $incident->load('monitor:id,name');
    }

    public function acknowledge(Incident $incident)
    {
        abort_unless($incident->isVisibleTo(auth()->user()), 403);

        if (! $incident->acknowledged_at) {
            $incident->update(['acknowledged_at' => now()]);
        }

        return $incident->fresh();
    }

    public function resolve(Incident $incident)
    {
        abort_unless($incident->isVisibleTo(auth()->user()), 403);

        if (! $incident->resolved_at) {
            $incident->update([
                'resolved_at' => now(),
                'duration_seconds' => now()->diffInSeconds($incident->started_at),
            ]);
            if ($incident->monitor && $incident->monitor->status === 'down') {
                $incident->monitor->update(['status' => 'up']);
            }
        }

        return $incident->fresh();
    }
}
