<?php

namespace App\Console\Commands;

use App\Services\EscalationRunner;
use Illuminate\Console\Command;

/**
 * Walks the escalation ladder for open, unacknowledged incidents.
 *
 * Separate from monitor:poll on purpose. Polling is about finding out what is
 * true; this is about who gets told, and an operator debugging a silent pager
 * wants to run exactly this and watch what it says.
 */
class MonitorEscalate extends Command
{
    protected $signature = 'monitor:escalate';

    protected $description = 'Send escalation notifications for unacknowledged incidents';

    public function handle(): int
    {
        $sent = EscalationRunner::run();
        $this->info("{$sent} escalation notification(s) sent.");

        return self::SUCCESS;
    }
}
