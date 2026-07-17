<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MonitorController extends Controller
{
    use ManagesOwners;

    public function index(Request $request)
    {
        $user = auth()->user();
        $status = $request->query('status');
        $query = Monitor::visibleTo($user);
        if ($status && isset(Monitor::STATUSES[$status])) {
            $query->where('status', $status);
        }
        $monitors = $query->with('owner:id,name')
            ->withCount(['incidents as open_incidents_count' => fn ($q) => $q->whereNull('resolved_at')])
            ->latest()->paginate(25)->withQueryString();

        $stats = [
            'up' => Monitor::visibleTo($user)->where('status', 'up')->count(),
            'down' => Monitor::visibleTo($user)->where('status', 'down')->count(),
            'paused' => Monitor::visibleTo($user)->where('status', 'paused')->count(),
        ];

        return view('monitors.index', compact('monitors', 'status', 'stats'));
    }

    public function create()
    {
        return view('monitors.create', ['owners' => $this->assignableOwners()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        $monitor = Monitor::create($data);
        $this->assignFromRequest($monitor, $request);
        AuditLog::record('monitor', "Created monitor {$monitor->name}");

        return redirect()->route('monitors.show', $monitor)->with('status', "Monitor \"{$monitor->name}\" created.");
    }

    public function show(Monitor $monitor)
    {
        $this->guard($monitor);
        $checks = $monitor->checks()->latest('checked_at')->limit(20)->get();
        $openIncident = $monitor->openIncident();
        $incidents = $monitor->incidents()->latest('started_at')->limit(10)->get();
        $latestMetric = $monitor->isAgentType() ? $monitor->metrics()->latest('recorded_at')->first() : null;

        return view('monitors.show', compact('monitor', 'checks', 'openIncident', 'incidents', 'latestMetric'));
    }

    public function edit(Monitor $monitor)
    {
        $this->guard($monitor);

        return view('monitors.edit', ['monitor' => $monitor, 'owners' => $this->assignableOwners()]);
    }

    public function update(Request $request, Monitor $monitor)
    {
        $this->guard($monitor);
        $data = $this->validated($request);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $request->input('owner_id') ?: null;
        }
        unset($data['owner_id']);
        $monitor->update($data);
        $this->assignFromRequest($monitor, $request);
        AuditLog::record('monitor', "Updated monitor {$monitor->name}");

        return redirect()->route('monitors.show', $monitor)->with('status', 'Monitor updated.');
    }

    public function destroy(Monitor $monitor)
    {
        $this->guard($monitor);
        $name = $monitor->name;
        $monitor->delete();
        AuditLog::record('monitor', "Deleted monitor {$name}");

        return redirect()->route('monitors.index')->with('status', "Monitor \"{$name}\" deleted.");
    }

    /** Delete / pause / resume the selected monitors (visibility-scoped). */
    public function bulkAction(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:delete,pause,resume'],
        ]);

        $monitors = Monitor::whereIn('id', $data['ids'])->get()
            ->filter(fn ($m) => $m->isVisibleTo(auth()->user()));

        foreach ($monitors as $m) {
            match ($data['action']) {
                'delete' => $m->delete(),
                'pause' => $m->update(['status' => 'paused']),
                'resume' => $m->update(['status' => 'up', 'last_checked_at' => now()]),
            };
        }

        $n = $monitors->count();
        $verb = ['delete' => 'deleted', 'pause' => 'paused', 'resume' => 'resumed'][$data['action']];
        AuditLog::record('monitor', "Bulk {$data['action']} on {$n} monitor(s)");

        return back()->with('status', "{$n} monitor(s) {$verb}.");
    }

    /**
     * Demo/manual way to record a check against a monitor. In production this
     * would be posted by an external checker process or the install agent;
     * this endpoint lets an admin simulate one from the monitor's show page.
     */
    public function storeCheck(Request $request, Monitor $monitor)
    {
        $this->guard($monitor);
        $data = $request->validate([
            'status' => ['required', 'in:up,down'],
            'response_time_ms' => ['nullable', 'integer', 'min:0'],
            'status_code' => ['nullable', 'integer', 'min:0'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $check = $monitor->checks()->create([
            'checked_at' => now(),
            'status' => $data['status'],
            'response_time_ms' => $data['response_time_ms'] ?? null,
            'status_code' => $data['status_code'] ?? null,
            'message' => $data['message'] ?? null,
        ]);

        $wasDown = $monitor->status === 'down';
        $monitor->last_checked_at = $check->checked_at;
        $monitor->status = $data['status'];

        // Recompute a simple uptime ratio from recent check history.
        $recent = $monitor->checks()->latest('checked_at')->limit(100)->get();
        $monitor->uptime_ratio = $recent->count()
            ? round($recent->where('status', 'up')->count() / $recent->count() * 100, 2)
            : 100;
        $monitor->save();

        if ($data['status'] === 'down' && ! $wasDown) {
            Incident::create([
                'monitor_id' => $monitor->id,
                'started_at' => now(),
                'cause' => $data['message'] ?? 'Check failed',
            ]);
            AuditLog::record('incident', "Incident opened for monitor {$monitor->name}");
        } elseif ($data['status'] === 'up' && $wasDown) {
            if ($open = $monitor->openIncident()) {
                $open->update([
                    'resolved_at' => now(),
                    'duration_seconds' => now()->diffInSeconds($open->started_at),
                ]);
                AuditLog::record('incident', "Incident resolved for monitor {$monitor->name}");
            }
        }

        return back()->with('status', 'Check recorded.');
    }

    private function guard(Monitor $monitor): void
    {
        abort_unless($monitor->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:' . implode(',', array_keys(Monitor::TYPES))],
            'target' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'interval_seconds' => ['required', 'integer', 'min:10', 'max:86400'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'expected' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:' . implode(',', array_keys(Monitor::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
    }
}
