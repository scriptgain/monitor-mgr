<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\DowntimeWindow;
use App\Models\Monitor;
use App\Models\MonitoredHost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DowntimeController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $windows = DowntimeWindow::visibleTo(auth()->user())
            ->with(['monitor:id,name', 'host:id,name', 'owner:id,name'])
            ->latest()
            ->paginate(25);

        return view('downtime.index', compact('windows'));
    }

    public function create()
    {
        return view('downtime.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);
        $window = DowntimeWindow::create($data);
        $this->assignFromRequest($window, $request);
        AuditLog::record('downtime', "Created downtime window {$window->name}");

        return redirect()->route('downtime.index')->with('status', "Downtime window \"{$window->name}\" created.");
    }

    public function edit(DowntimeWindow $downtime)
    {
        $this->guard($downtime);

        return view('downtime.edit', $this->formData(['window' => $downtime]));
    }

    public function update(Request $request, DowntimeWindow $downtime)
    {
        $this->guard($downtime);
        $data = $this->validated($request);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $request->input('owner_id') ?: null;
        }
        unset($data['owner_id']);
        $downtime->update($data);
        $this->assignFromRequest($downtime, $request);
        AuditLog::record('downtime', "Updated downtime window {$downtime->name}");

        return redirect()->route('downtime.index')->with('status', 'Downtime window updated.');
    }

    public function destroy(DowntimeWindow $downtime)
    {
        $this->guard($downtime);
        $name = $downtime->name;
        $downtime->delete();
        AuditLog::record('downtime', "Deleted downtime window {$name}");

        return redirect()->route('downtime.index')->with('status', "Downtime window \"{$name}\" deleted.");
    }

    private function formData(array $extra = []): array
    {
        return array_merge([
            'owners' => $this->assignableOwners(),
            'monitors' => Monitor::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name']),
            'hosts' => MonitoredHost::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name']),
            'window' => null,
        ], $extra);
    }

    private function guard(DowntimeWindow $window): void
    {
        abort_unless($window->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(array_keys(DowntimeWindow::KINDS))],
            'subject' => ['nullable', 'string'],   // "monitor:5", "host:3", or blank for everything
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:0,6'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);

        // A window with nothing filled in silences nothing and looks like it
        // works, which is the worst of both. Each kind has to be complete.
        $validator->after(function ($v) use ($request) {
            if ($request->input('kind') === 'once') {
                if (! $request->filled('starts_at') || ! $request->filled('ends_at')) {
                    $v->errors()->add('starts_at', 'A one off window needs a start and an end.');
                } elseif (strtotime((string) $request->input('ends_at')) <= strtotime((string) $request->input('starts_at'))) {
                    $v->errors()->add('ends_at', 'The window has to end after it starts.');
                }

                return;
            }
            if (! $request->filled('days_of_week')) {
                $v->errors()->add('days_of_week', 'Pick at least one day.');
            }
            if (! $request->filled('start_time') || ! $request->filled('end_time')) {
                $v->errors()->add('start_time', 'A weekly window needs a start and an end time.');
            }
        });

        $data = $validator->validate();

        // One subject select drives two nullable columns, so the form cannot
        // offer a state where both are set.
        [$type, $id] = array_pad(explode(':', (string) ($data['subject'] ?? '')), 2, null);
        unset($data['subject']);
        $data['monitor_id'] = $type === 'monitor' ? (int) $id : null;
        $data['monitored_host_id'] = $type === 'host' ? (int) $id : null;

        // Keep the irrelevant half of the schedule empty rather than stale, so a
        // window switched from weekly to one off cannot fire on last week's days.
        if ($data['kind'] === 'once') {
            $data['days_of_week'] = null;
            $data['start_time'] = null;
            $data['end_time'] = null;
        } else {
            $data['starts_at'] = null;
            $data['ends_at'] = null;
        }

        return $data + ['is_enabled' => $request->boolean('is_enabled')];
    }
}
