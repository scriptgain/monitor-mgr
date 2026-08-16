<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AuditLog;
use App\Models\MonitoredHost;
use App\Models\Trigger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TriggerController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $triggers = Trigger::visibleTo(auth()->user())
            ->with(['owner:id,name', 'host:id,name'])
            ->withCount(['incidents as open_incidents_count' => fn ($q) => $q->whereNull('resolved_at')])
            ->orderByDesc('is_enabled')->orderBy('metric')
            ->paginate(25);

        return view('triggers.index', compact('triggers'));
    }

    public function create()
    {
        return view('triggers.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);
        $trigger = Trigger::create($data);
        $this->assignFromRequest($trigger, $request);
        AuditLog::record('trigger', "Created trigger {$trigger->name}");

        return redirect()->route('triggers.index')->with('status', "Trigger \"{$trigger->name}\" created.");
    }

    public function edit(Trigger $trigger)
    {
        $this->guard($trigger);

        return view('triggers.edit', $this->formData(['trigger' => $trigger]));
    }

    public function update(Request $request, Trigger $trigger)
    {
        $this->guard($trigger);
        $data = $this->validated($request);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $request->input('owner_id') ?: null;
        }
        unset($data['owner_id']);
        $trigger->update($data);
        $this->assignFromRequest($trigger, $request);
        AuditLog::record('trigger', "Updated trigger {$trigger->name}");

        return redirect()->route('triggers.index')->with('status', 'Trigger updated.');
    }

    public function destroy(Trigger $trigger)
    {
        $this->guard($trigger);
        $name = $trigger->name;
        $trigger->delete();
        AuditLog::record('trigger', "Deleted trigger {$name}");

        return redirect()->route('triggers.index')->with('status', "Trigger \"{$name}\" deleted.");
    }

    /** Bulk delete, enable or disable. Only the submitted ids, scoped to what the user may see. */
    public function bulkAction(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:delete,enable,disable'],
        ]);

        $triggers = Trigger::visibleTo(auth()->user())->whereIn('id', $data['ids'])->get();
        if ($triggers->isEmpty()) {
            return back()->with('warning', 'No matching triggers were selected.');
        }

        foreach ($triggers as $t) {
            match ($data['action']) {
                'delete' => $t->delete(),
                'enable' => $t->update(['is_enabled' => true]),
                'disable' => $t->update(['is_enabled' => false]),
            };
        }

        $n = $triggers->count();
        $verb = ['delete' => 'deleted', 'enable' => 'enabled', 'disable' => 'disabled'][$data['action']];
        AuditLog::record('trigger', "Bulk {$data['action']} on {$n} trigger(s)");

        return back()->with('status', "{$n} trigger(s) {$verb}.");
    }

    private function formData(array $extra = []): array
    {
        return array_merge([
            'owners' => $this->assignableOwners(),
            'hosts' => MonitoredHost::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name']),
            'trigger' => null,
        ], $extra);
    }

    private function guard(Trigger $trigger): void
    {
        abort_unless($trigger->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:160'],
            'monitored_host_id' => ['nullable', Rule::exists('monitored_hosts', 'id')],
            // Free text so disk.<mount>.pct can be written for any mount the agent
            // reports; the dropdown covers everything else.
            'metric' => ['required', 'string', 'max:120', 'regex:/^([a-z0-9_]+|disk\..+\.pct)$/'],
            'operator' => ['required', Rule::in(array_keys(Trigger::OPERATORS))],
            'threshold' => ['required', 'numeric'],
            'recovery_threshold' => ['nullable', 'numeric'],
            'for_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'severity' => ['required', Rule::in(array_keys(Trigger::SEVERITIES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);

        // A recovery point on the wrong side of the threshold can never be
        // reached, so the incident it opens stays open forever. Refuse it at the
        // form rather than let someone find out three days later.
        $validator->after(function ($v) use ($request) {
            $recovery = $request->input('recovery_threshold');
            if ($recovery === null || $recovery === '') {
                return;
            }
            $above = in_array($request->input('operator'), ['>', '>='], true);
            $threshold = (float) $request->input('threshold');
            if ($above && (float) $recovery > $threshold) {
                $v->errors()->add('recovery_threshold', 'For an "is above" rule the recovery value must be at or below the threshold.');
            }
            if (! $above && (float) $recovery < $threshold) {
                $v->errors()->add('recovery_threshold', 'For an "is below" rule the recovery value must be at or above the threshold.');
            }
        });

        return $validator->validate() + ['is_enabled' => $request->boolean('is_enabled')];
    }
}
