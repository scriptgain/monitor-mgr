<?php

// MonitorMGR agent-based host monitoring settings.
return [
    // A host that has not checked in within this many seconds reads "offline".
    // Should comfortably exceed the agent's sampling interval (default 30s).
    'offline_after_seconds' => (int) env('MONITOR_OFFLINE_AFTER_SECONDS', 90),

    // Rolling metrics history retention. Older samples are pruned on ingest.
    'metrics_retention_days' => (int) env('MONITOR_METRICS_RETENTION_DAYS', 7),
];
