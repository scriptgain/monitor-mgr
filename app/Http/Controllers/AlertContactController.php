<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOwners;
use App\Models\AlertContact;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertContactController extends Controller
{
    use ManagesOwners;

    public function index()
    {
        $alertContacts = AlertContact::visibleTo(auth()->user())->with('owner:id,name')->latest()->paginate(25);

        return view('alerts.index', compact('alertContacts'));
    }

    public function create()
    {
        return view('alerts.create', ['owners' => $this->assignableOwners()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);
        $contact = AlertContact::create($data);
        $this->assignFromRequest($contact, $request);
        AuditLog::record('alert_contact', "Created alert contact {$contact->name}");

        return redirect()->route('alerts.index')->with('status', "Alert contact \"{$contact->name}\" added.");
    }

    public function edit(AlertContact $alert)
    {
        $this->guard($alert);

        return view('alerts.edit', ['contact' => $alert, 'owners' => $this->assignableOwners()]);
    }

    public function update(Request $request, AlertContact $alert)
    {
        $this->guard($alert);
        $data = $this->validated($request);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $request->input('owner_id') ?: null;
        }
        unset($data['owner_id']);
        $alert->update($data);
        $this->assignFromRequest($alert, $request);
        AuditLog::record('alert_contact', "Updated alert contact {$alert->name}");

        return redirect()->route('alerts.index')->with('status', 'Alert contact updated.');
    }

    public function destroy(AlertContact $alert)
    {
        $this->guard($alert);
        $name = $alert->name;
        $alert->delete();
        AuditLog::record('alert_contact', "Deleted alert contact {$name}");

        return redirect()->route('alerts.index')->with('status', "Alert contact \"{$name}\" deleted.");
    }

    private function guard(AlertContact $alert): void
    {
        abort_unless($alert->isVisibleTo(auth()->user()), 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:' . implode(',', array_keys(AlertContact::TYPES))],
            'target' => ['required', 'string', 'max:255'],
            'is_enabled' => ['nullable', 'boolean'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]) + ['is_enabled' => $request->boolean('is_enabled')];
    }
}
