<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\Monitor;
use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StatusPageController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $statusPages = StatusPage::visibleTo(auth()->user())->with('owner:id,name')->withCount('monitors')->latest()->paginate(25);

        return view('status-pages.index', compact('statusPages'));
    }

    public function create()
    {
        $monitors = Monitor::visibleTo(auth()->user())->orderBy('name')->get();

        return view('status-pages.create', ['monitors' => $monitors, 'owners' => $this->assignableOwners()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $monitorIds = $this->allowedMonitorIds($request, $data['monitor_ids'] ?? []);
        unset($data['monitor_ids'], $data['owner_id']);
        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        $data['user_id'] = $this->resolveOwner($request);

        $statusPage = StatusPage::create($data);
        $statusPage->monitors()->sync($monitorIds);
        $this->assignFromRequest($statusPage, $request);
        AuditLog::record('status_page', "Created status page {$statusPage->name}");

        return redirect()->route('status-pages.show', $statusPage)->with('status', 'Status page created.');
    }

    public function show(StatusPage $statusPage)
    {
        $this->guard($statusPage);
        $statusPage->load('monitors');

        return view('status-pages.show', compact('statusPage'));
    }

    public function edit(StatusPage $statusPage)
    {
        $this->guard($statusPage);
        $monitors = Monitor::visibleTo(auth()->user())->orderBy('name')->get();
        $selected = $statusPage->monitors()->pluck('monitors.id')->all();

        return view('status-pages.edit', ['statusPage' => $statusPage, 'monitors' => $monitors, 'selected' => $selected, 'owners' => $this->assignableOwners()]);
    }

    public function update(Request $request, StatusPage $statusPage)
    {
        $this->guard($statusPage);
        $data = $this->validated($request, $statusPage->id);
        $monitorIds = $this->allowedMonitorIds($request, $data['monitor_ids'] ?? []);
        unset($data['monitor_ids'], $data['owner_id']);
        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $request->input('owner_id') ?: null;
        }

        $statusPage->update($data);
        $statusPage->monitors()->sync($monitorIds);
        $this->assignFromRequest($statusPage, $request);
        AuditLog::record('status_page', "Updated status page {$statusPage->name}");

        return redirect()->route('status-pages.show', $statusPage)->with('status', 'Status page updated.');
    }

    public function destroy(StatusPage $statusPage)
    {
        $this->guard($statusPage);
        $name = $statusPage->name;
        $statusPage->delete();
        AuditLog::record('status_page', "Deleted status page {$name}");

        return redirect()->route('status-pages.index')->with('status', "Status page \"{$name}\" deleted.");
    }

    private function guard(StatusPage $statusPage): void
    {
        abort_unless($statusPage->isVisibleTo(auth()->user()), 403);
    }

    /** Restrict synced monitors to those the user may actually see. */
    private function allowedMonitorIds(Request $request, array $ids): array
    {
        if (auth()->user()->isAdmin() || empty($ids)) {
            return $ids;
        }

        return Monitor::visibleTo($request->user())->whereIn('id', $ids)->pluck('id')->all();
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160', 'alpha_dash', 'unique:status_pages,slug' . ($ignoreId ? ",{$ignoreId}" : '')],
            'is_public' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monitor_ids' => ['nullable', 'array'],
            'monitor_ids.*' => ['integer', 'exists:monitors,id'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]) + ['is_public' => $request->boolean('is_public')];
    }
}
