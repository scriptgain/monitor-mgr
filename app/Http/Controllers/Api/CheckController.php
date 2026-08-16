<?php

namespace App\Http\Controllers\Api;

use App\Checks\CheckResult;
use App\Http\Controllers\Controller;
use App\Models\Check;
use App\Models\Monitor;
use App\Services\CheckRecorder;
use Illuminate\Http\Request;

class CheckController extends Controller
{
    public function index(Request $request)
    {
        return Check::visibleTo($request->user())
            ->with('monitor:id,name')
            ->when($request->integer('monitor_id'), fn ($q, $id) => $q->where('monitor_id', $id))
            ->latest('checked_at')
            ->paginate(50);
    }

    public function show(Check $check)
    {
        abort_unless($check->isVisibleTo(auth()->user()), 403);

        return $check->load('monitor:id,name');
    }

    /**
     * Record a check result from outside the panel.
     *
     * This is the hook for an external checker: a probe in another region, a
     * Nagios plugin wrapper, or a CI job asserting on something the built-in
     * check types cannot express. It goes through the same recorder as the
     * poller, so it opens and closes incidents and sends alerts identically.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'monitor_id' => ['required', 'integer', 'exists:monitors,id'],
            'status' => ['required', 'in:up,down'],
            'response_time_ms' => ['nullable', 'integer', 'min:0'],
            'status_code' => ['nullable', 'integer', 'min:0'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $monitor = Monitor::findOrFail($data['monitor_id']);
        abort_unless($monitor->isVisibleTo($request->user()), 403);

        $result = $data['status'] === 'up'
            ? CheckResult::up($data['response_time_ms'] ?? null, $data['status_code'] ?? null, $data['message'] ?? null)
            : CheckResult::down($data['message'] ?? 'Check failed', $data['response_time_ms'] ?? null, $data['status_code'] ?? null);

        $check = CheckRecorder::record($monitor, $result);

        return response()->json($check?->load('monitor:id,name'), 201);
    }
}
