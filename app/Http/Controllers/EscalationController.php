<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AlertContact;
use App\Models\AuditLog;
use App\Models\EscalationStep;
use App\Models\Trigger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EscalationController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $steps = EscalationStep::visibleTo(auth()->user())
            ->with(['contact:id,name,type', 'owner:id,name'])
            ->orderBy('after_minutes')
            ->paginate(25);

        return view('escalations.index', compact('steps'));
    }

    public function create()
    {
        return view('escalations.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);
        $step = EscalationStep::create($data);
        $this->assignFromRequest($step, $request);
        AuditLog::record('escalation', "Created escalation step {$step->name}");

        return redirect()->route('escalations.index')->with('status', "Escalation \"{$step->name}\" created.");
    }

    public function edit(EscalationStep $escalation)
    {
        $this->guard($escalation);

        return view('escalations.edit', $this->formData(['step' => $escalation]));
    }

    public function update(Request $request, EscalationStep $escalation)
    {
        $this->guard($escalation);
        $data = $this->validated($request);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $request->input('owner_id') ?: null;
        }
        unset($data['owner_id']);
        $escalation->update($data);
        $this->assignFromRequest($escalation, $request);
        AuditLog::record('escalation', "Updated escalation step {$escalation->name}");

        return redirect()->route('escalations.index')->with('status', 'Escalation updated.');
    }

    public function destroy(EscalationStep $escalation)
    {
        $this->guard($escalation);
        $name = $escalation->name;
        $escalation->delete();
        AuditLog::record('escalation', "Deleted escalation step {$name}");

        return redirect()->route('escalations.index')->with('status', "Escalation \"{$name}\" deleted.");
    }

    private function formData(array $extra = []): array
    {
        return array_merge([
            'owners' => $this->assignableOwners(),
            'contacts' => AlertContact::visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'type']),
            'severities' => Trigger::SEVERITIES,
            'step' => null,
        ], $extra);
    }

    private function guard(EscalationStep $step): void
    {
        abort_unless($step->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'alert_contact_id' => ['required', Rule::exists('alert_contacts', 'id')],
            'after_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'min_severity' => ['nullable', Rule::in(array_keys(Trigger::SEVERITIES))],
            'repeat_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]) + ['is_enabled' => $request->boolean('is_enabled')];
    }
}
