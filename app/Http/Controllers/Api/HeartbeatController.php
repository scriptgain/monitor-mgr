<?php

namespace App\Http\Controllers\Api;

use App\Checks\CheckResult;
use App\Http\Controllers\Controller;
use App\Models\Monitor;
use App\Services\CheckRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dead-man's-switch endpoint. A cron job, backup script, or CI step curls its
 * monitor's URL on success; going quiet past the interval is what monitor:poll
 * turns into an incident.
 *
 * Public by design: the random token in the path is the entire credential, so
 * there is nothing to authenticate against. It answers to GET as well as POST
 * because most of the things that ping it are a one-line `curl` in a crontab.
 */
class HeartbeatController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $monitor = Monitor::where('heartbeat_token', $token)->where('type', 'heartbeat')->first();
        if (! $monitor) {
            return response()->json(['message' => 'Unknown heartbeat token.'], 404);
        }

        if ($monitor->status === 'paused') {
            return response()->json(['status' => 'paused']);
        }

        // An optional ?status=down (or a failing exit code piped in as such)
        // lets a job report its own failure rather than only its silence.
        $failed = in_array(strtolower((string) $request->query('status')), ['down', 'fail', 'failed', 'error'], true);
        $message = mb_substr(trim((string) $request->query('message', '')), 0, 255) ?: null;

        CheckRecorder::record($monitor, $failed
            ? CheckResult::down($message ?: 'The job reported failure.')
            : CheckResult::up(null, null, $message));

        return response()->json(['status' => $failed ? 'down' : 'up']);
    }
}
