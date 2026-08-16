<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\HostGroup;
use App\Models\HostMetric;
use App\Models\MonitoredHost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

// Web UI for agent-based server monitoring: host list, live per-host dashboard,
// and the "Add Host" enrollment flow (one-time token + curl install one-liner).
class HostController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $group = $request->integer('group') ?: null;
        $tag = trim((string) $request->query('tag'));

        $hosts = MonitoredHost::visibleTo($user)
            ->with(['latestMetric', 'groups:id,name,color'])
            ->when($group, fn ($q, $id) => $q->whereHas('groups', fn ($g) => $g->whereKey($id)))
            ->when($tag !== '', fn ($q) => $q->tagged($tag))
            ->orderBy('name')
            ->get();

        // Every tag actually in use, so the filter offers real options rather
        // than a free text box that silently matches nothing.
        $allTags = MonitoredHost::visibleTo($user)->pluck('tags')
            ->flatMap(fn ($t) => (array) $t)->unique()->sort()->values();

        return view('hosts.index', [
            'hosts' => $hosts,
            'groups' => HostGroup::visibleTo($user)->orderBy('name')->get(['id', 'name', 'color']),
            'allTags' => $allTags,
            'activeGroup' => $group,
            'activeTag' => $tag,
        ]);
    }

    public function edit(Request $request, MonitoredHost $host)
    {
        abort_unless($host->isVisibleTo($request->user()), 403);

        return view('hosts.edit', [
            'host' => $host->load('groups:id'),
            'groups' => HostGroup::visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, MonitoredHost $host)
    {
        abort_unless($host->isVisibleTo($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:500'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['integer'],
        ]);

        $host->update([
            'name' => $data['name'],
            'notes' => $data['notes'] ?? null,
            'tags' => $data['tags'] ?? [],
        ]);
        // Scoped, so a submitted id cannot attach a group the user cannot see.
        $host->groups()->sync(
            HostGroup::visibleTo($request->user())->whereIn('id', $data['groups'] ?? [])->pluck('id')
        );
        AuditLog::record('host', "Updated host {$host->name}");

        return redirect()->route('hosts.show', $host)->with('status', 'Host updated.');
    }

    public function create()
    {
        return view('hosts.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:500'],
        ]);

        $host = MonitoredHost::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'notes' => $data['notes'] ?? null,
            'tags' => $data['tags'] ?? [],
            'status' => 'pending',
        ]);
        $token = $host->issueEnrollmentToken();

        return redirect()
            ->route('hosts.show', $host)
            ->with('enroll_token', $token);
    }

    public function show(Request $request, MonitoredHost $host)
    {
        abort_unless($host->isVisibleTo($request->user()), 403);
        $host->load(['latestMetric', 'groups']);

        return view('hosts.show', [
            'host' => $host,
            'token' => session('enroll_token'),
            'installUrl' => URL::to('/downloads/agent-install.sh'),
            'masterUrl' => URL::to('/'),
        ]);
    }

    /** Regenerate a one-time enrollment token (previous token is invalidated). */
    public function token(Request $request, MonitoredHost $host)
    {
        abort_unless($host->isVisibleTo($request->user()), 403);
        $token = $host->issueEnrollmentToken();

        return redirect()->route('hosts.show', $host)->with('enroll_token', $token);
    }

    public function destroy(Request $request, MonitoredHost $host)
    {
        abort_unless($host->isVisibleTo($request->user()), 403);
        $host->delete();

        return redirect()->route('hosts.index')->with('status', 'Host removed.');
    }

    /**
     * Bulk-delete selected hosts. Only the submitted ids are touched, and
     * only hosts the current user is allowed to see.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = MonitoredHost::visibleTo($request->user())->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching hosts were selected.');
        }

        $count = MonitoredHost::whereIn('id', $ids->all())->delete();

        return back()->with('status', $count.' host'.($count === 1 ? '' : 's').' deleted.');
    }

    /** Live metrics feed for the dashboard (polled by Alpine on an interval). */
    public function metricsJson(Request $request, MonitoredHost $host)
    {
        abort_unless($host->isVisibleTo($request->user()), 403);

        $samples = HostMetric::where('monitored_host_id', $host->id)
            ->orderByDesc('captured_at')
            ->limit(60)
            ->get()
            ->reverse()
            ->values();

        $latest = $samples->last();

        return response()->json([
            'status' => $host->effective_status,
            'agent_version' => $host->agent_version,
            'os' => $host->os,
            'arch' => $host->arch,
            'cpu_cores' => $host->cpu_cores,
            'last_seen' => $host->last_seen_at?->diffForHumans(),
            'boot_time' => $host->boot_time?->toDayDateTimeString(),
            'latest' => $latest ? [
                'captured_at' => $latest->captured_at->toIso8601String(),
                'cpu_pct' => $latest->cpu_pct,
                'mem_used' => (int) $latest->mem_used,
                'mem_total' => (int) $latest->mem_total,
                'mem_pct' => $latest->memPct(),
                'swap_used' => (int) $latest->swap_used,
                'swap_total' => (int) $latest->swap_total,
                'disk_used' => (int) $latest->disk_used,
                'disk_total' => (int) $latest->disk_total,
                'disk_pct' => $latest->diskPct(),
                'load1' => $latest->load1,
                'load5' => $latest->load5,
                'load15' => $latest->load15,
                'uptime' => (int) $latest->uptime,
                'net_rx' => (int) $latest->net_rx,
                'net_tx' => (int) $latest->net_tx,
                'detail' => $latest->detail,
            ] : null,
            'history' => [
                'cpu' => $samples->map(fn ($m) => round($m->cpu_pct, 1))->all(),
                'mem' => $samples->map(fn ($m) => $m->memPct())->all(),
                'disk' => $samples->map(fn ($m) => $m->diskPct())->all(),
            ],
        ]);
    }
}
