<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Monitor;
use Illuminate\Http\Request;

class MonitorController extends Controller
{
    public function index(Request $request)
    {
        return Monitor::visibleTo($request->user())
            ->withCount('incidents')
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);

        return response()->json(Monitor::create($data), 201);
    }

    public function show(Monitor $monitor)
    {
        abort_unless($monitor->isVisibleTo(auth()->user()), 403);

        return $monitor->load('incidents');
    }

    public function update(Request $request, Monitor $monitor)
    {
        abort_unless($monitor->isVisibleTo($request->user()), 403);

        $data = $this->validated($request);

        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            $data['user_id'] = $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        } else {
            unset($data['user_id']);
        }

        $monitor->update($data);

        return $monitor;
    }

    public function destroy(Monitor $monitor)
    {
        abort_unless($monitor->isVisibleTo(auth()->user()), 403);

        $monitor->delete();

        return response()->noContent();
    }

    /** Admins may assign an explicit owner; everyone else owns what they create. */
    private function resolveOwner(Request $request): int
    {
        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            return (int) $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        }

        return $request->user()->id;
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
        ]);
    }
}
