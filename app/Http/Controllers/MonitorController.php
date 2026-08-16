<?php

namespace App\Http\Controllers;

use App\Checks\CheckResult;
use App\Checks\CheckRunner;
use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\Monitor;
use App\Services\CheckRecorder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MonitorController extends Controller
{
    use ManagesOwners;

    public function index(Request $request)
    {
        $user = auth()->user();
        $status = $request->query('status');
        $tag = trim((string) $request->query('tag'));
        $query = Monitor::visibleTo($user);
        if ($status && isset(Monitor::STATUSES[$status])) {
            $query->where('status', $status);
        }
        if ($tag !== '') {
            $query->tagged($tag);
        }
        $monitors = $query->with('owner:id,name')
            ->withCount(['incidents as open_incidents_count' => fn ($q) => $q->whereNull('resolved_at')])
            ->latest()->paginate(25)->withQueryString();

        $stats = [
            'up' => Monitor::visibleTo($user)->where('status', 'up')->count(),
            'down' => Monitor::visibleTo($user)->where('status', 'down')->count(),
            'paused' => Monitor::visibleTo($user)->where('status', 'paused')->count(),
        ];

        $allTags = Monitor::visibleTo($user)->pluck('tags')
            ->flatMap(fn ($t) => (array) $t)->unique()->sort()->values();

        return view('monitors.index', compact('monitors', 'status', 'stats', 'tag', 'allTags'));
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
        $isPolled = CheckRunner::isPolled((string) $monitor->type);

        return view('monitors.show', compact('monitor', 'checks', 'openIncident', 'incidents', 'latestMetric', 'isPolled'));
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
     * Record a check by hand from the monitor page. The poller and the REST API
     * are the usual writers now; this stays as the way to force a state for a
     * demo or to close out a monitor the poller cannot reach.
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

        $result = $data['status'] === 'up'
            ? CheckResult::up($data['response_time_ms'] ?? null, $data['status_code'] ?? null, $data['message'] ?? null)
            : CheckResult::down($data['message'] ?? 'Check failed', $data['response_time_ms'] ?? null, $data['status_code'] ?? null);

        CheckRecorder::record($monitor, $result);

        return back()->with('status', 'Check recorded.');
    }

    /** Run this monitor's check right now, without waiting for the sweep. */
    public function runCheck(Monitor $monitor)
    {
        $this->guard($monitor);

        if (! CheckRunner::isPolled((string) $monitor->type)) {
            return back()->with('status', "{$monitor->typeLabel()} monitors are not polled by the panel.");
        }

        $result = CheckRunner::run($monitor);
        CheckRecorder::record($monitor, $result);
        AuditLog::record('monitor', "Ran check on demand for monitor {$monitor->name}");

        $summary = $result->conclusive
            ? strtoupper($result->status).($result->message ? ': '.$result->message : '')
            : 'Could not run: '.$result->message;

        return back()->with('status', "Check complete. {$summary}");
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
            'tags' => ['nullable', 'string', 'max:500'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
    }
}
