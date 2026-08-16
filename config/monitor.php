<?php

// MonitorMGR agent-based host monitoring settings.
return [
    // A host that has not checked in within this many seconds reads "offline".
    // Should comfortably exceed the agent's sampling interval (default 30s).
    'offline_after_seconds' => (int) env('MONITOR_OFFLINE_AFTER_SECONDS', 90),

    // Rolling metrics history retention. Older samples are pruned on ingest.
    'metrics_retention_days' => (int) env('MONITOR_METRICS_RETENTION_DAYS', 7),

    // Agent distribution. The install one-liner shown in "Add Host" points at
    // /downloads/monitor-agent on this panel. That route serves the binary from
    // `binary_path` when an operator has dropped one there (the air-gapped
    // case), and otherwise redirects to the published release below.
    'agent' => [
        'binary_path' => env('MONITOR_AGENT_BINARY', storage_path('app/agent/monitor-agent')),
        'download_url' => env('MONITOR_AGENT_URL', 'https://scriptgain.com/v1/monitor-agent/latest/monitor-agent-linux-amd64'),
    ],

];
