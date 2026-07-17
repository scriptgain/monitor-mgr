<?php

namespace App\Http\Middleware;

use App\Models\MonitoredHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Authenticates a host agent by its per-host bearer API key (issued at
// enrollment). The matched host is stashed on the request for the controller.
class AuthenticateHostAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $host = MonitoredHost::where('api_key', hash('sha256', $bearer))->first();
        if (! $host) {
            return response()->json(['message' => 'Invalid agent key.'], 401);
        }

        $request->attributes->set('agent_host', $host);

        return $next($request);
    }
}
