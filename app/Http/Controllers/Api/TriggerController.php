<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trigger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TriggerController extends Controller
{
    public function index(Request $request)
    {
        return Trigger::visibleTo($request->user())
            ->with('host:id,name')
            ->when($request->integer('monitored_host_id'), fn ($q, $id) => $q->where('monitored_host_id', $id))
            ->when($request->query('metric'), fn ($q, $m) => $q->where('metric', $m))
            ->orderBy('metric')
            ->paginate(50);
    }

    public function show(Trigger $trigger)
    {
        abort_unless($trigger->isVisibleTo(auth()->user()), 403);

        return $trigger->load('host:id,name');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $request->user()->id;

        return response()->json(Trigger::create($data), 201);
    }

    public function update(Request $request, Trigger $trigger)
    {
        abort_unless($trigger->isVisibleTo($request->user()), 403);
        $trigger->update($this->validated($request, $trigger));

        return $trigger->fresh();
    }

    public function destroy(Trigger $trigger)
    {
        abort_unless($trigger->isVisibleTo(auth()->user()), 403);
        $trigger->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Trigger $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'monitored_host_id' => ['nullable', Rule::exists('monitored_hosts', 'id')],
            'metric' => [$required, 'string', 'max:120', 'regex:/^([a-z0-9_]+|disk\..+\.pct)$/'],
            'operator' => [$required, Rule::in(array_keys(Trigger::OPERATORS))],
            'threshold' => [$required, 'numeric'],
            'recovery_threshold' => ['nullable', 'numeric'],
            'for_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'severity' => ['sometimes', Rule::in(array_keys(Trigger::SEVERITIES))],
            'is_enabled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
