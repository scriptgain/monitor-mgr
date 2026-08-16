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

    // Poller. `monitor:poll` runs every minute from the scheduler and queues a
    // job for each monitor whose interval has elapsed. Checks are executed by
    // the queue worker, so `queue:work` must be running for any of this to fire.
    'poll' => [
        // Hard ceiling on how many checks one sweep will queue. Keeps a large
        // install from flooding the queue if the worker has fallen behind.
        'max_per_sweep' => (int) env('MONITOR_POLL_MAX_PER_SWEEP', 500),

        // How long a check may take before it is abandoned, on top of the
        // monitor's own timeout. Guards against a wedged job holding a worker.
        'job_timeout_padding' => (int) env('MONITOR_POLL_TIMEOUT_PADDING', 30),

        // A heartbeat monitor is marked down when nothing has pinged its URL
        // within its interval plus this grace period.
        'heartbeat_grace_seconds' => (int) env('MONITOR_HEARTBEAT_GRACE', 60),

        // User agent sent by HTTP and keyword checks.
        'user_agent' => env('MONITOR_POLL_USER_AGENT', 'MonitorMGR/1.0 (+https://scriptgain.com)'),
    ],
];
