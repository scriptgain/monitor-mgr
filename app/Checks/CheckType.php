<?php

namespace App\Checks;

use App\Models\Monitor;

interface CheckType
{
    /**
     * Execute the check. Implementations must not throw: any failure to reach
     * the target is a "down" result, and any failure of the panel's own
     * environment is CheckResult::unavailable().
     */
    public function run(Monitor $monitor): CheckResult;
}
