<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the agent install one-liner advertised by the "Add Host" flow.
 *
 * Both routes are public on purpose: they are fetched by curl on a machine that
 * has no session, and the enrollment token (not the script) is the credential.
 * Neither route discloses anything install-specific.
 */
class AgentDownloadController extends Controller
{
    /** The installer script itself, served from the repo copy in deploy/. */
    public function script(): Response
    {
        $path = base_path('deploy/agent-install.sh');
        abort_unless(is_readable($path), 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agent-install.sh"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The agent binary. Prefers a local copy so an air-gapped install works
     * without reaching ScriptGain, and otherwise hands the client off to the
     * published release.
     */
    public function binary(Request $request): Response|BinaryFileResponse
    {
        $local = (string) config('monitor.agent.binary_path');
        if ($local !== '' && is_file($local) && is_readable($local)) {
            return response()->download($local, 'monitor-agent', [
                'Content-Type' => 'application/octet-stream',
            ]);
        }

        $url = (string) config('monitor.agent.download_url');
        if ($url === '') {
            abort(404, 'No agent binary is available on this panel.');
        }

        return redirect()->away($url);
    }
}
