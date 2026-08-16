<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\HostGroup;
use App\Models\MonitoredHost;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HostGroupController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $groups = HostGroup::visibleTo(auth()->user())
            ->with('owner:id,name')
            ->withCount(['hosts', 'triggers', 'downtimeWindows'])
            ->orderBy('name')
            ->paginate(25);

        return view('host-groups.index', compact('groups'));
    }

    public function create()
    {
        return view('host-groups.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $members = $data['hosts'] ?? [];
        unset($data['hosts'], $data['owner_id']);
        $data['user_id'] = $this->resolveOwner($request);

        $group = HostGroup::create($data);
        $group->hosts()->sync($this->visibleHostIds($members));
        AuditLog::record('host_group', "Created host group {$group->name}");

        return redirect()->route('host-groups.index')->with('status', "Host group \"{$group->name}\" created.");
    }

    public function edit(HostGroup $hostGroup)
    {
        $this->guard($hostGroup);

        return view('host-groups.edit', $this->formData(['group' => $hostGroup->load('hosts:id')]));
    }

    public function update(Request $request, HostGroup $hostGroup)
    {
        $this->guard($hostGroup);
        $data = $this->validated($request);
        $members = $data['hosts'] ?? [];
        unset($data['hosts']);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $request->input('owner_id') ?: null;
        }
        unset($data['owner_id']);

        $hostGroup->update($data);
        $hostGroup->hosts()->sync($this->visibleHostIds($members));
        AuditLog::record('host_group', "Updated host group {$hostGroup->name}");

        return redirect()->route('host-groups.index')->with('status', 'Host group updated.');
    }

    public function destroy(HostGroup $hostGroup)
    {
        $this->guard($hostGroup);
        $name = $hostGroup->name;
        // Triggers and windows aimed at this group go with it, which is the
        // cascade the migration declares. Say so on the confirm, not here.
        $hostGroup->delete();
        AuditLog::record('host_group', "Deleted host group {$name}");

        return redirect()->route('host-groups.index')->with('status', "Host group \"{$name}\" deleted.");
    }

    /** Never let a submitted id add a host the user cannot see. */
    private function visibleHostIds(array $ids): array
    {
        return MonitoredHost::visibleTo(auth()->user())->whereIn('id', $ids)->pluck('id')->all();
    }

    private function formData(array $extra = []): array
    {
        return array_merge([
            'owners' => $this->assignableOwners(),
            'hosts' => MonitoredHost::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'hostname']),
            'group' => null,
        ], $extra);
    }

    private function guard(HostGroup $group): void
    {
        abort_unless($group->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', Rule::in(array_keys(HostGroup::COLORS))],
            'description' => ['nullable', 'string', 'max:500'],
            'hosts' => ['nullable', 'array'],
            'hosts.*' => ['integer'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
    }
}
