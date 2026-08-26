<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonTimeZone;

class SchedulingService
{
    /**
     * Convert user/workspace local datetime string into UTC Carbon instance.
     */
    public function toUtc(string|\DateTimeInterface $localTime, string $workspaceTimezone = 'UTC'): Carbon
    {
        $timezone = new CarbonTimeZone($workspaceTimezone);

        if ($localTime instanceof \DateTimeInterface) {
            return Carbon::instance($localTime)->setTimezone('UTC');
        }

        return Carbon::parse($localTime, $timezone)->setTimezone('UTC');
    }

    /**
     * Convert a stored UTC Carbon instance into workspace local time string/Carbon.
     */
    public function toWorkspaceTime(string|\DateTimeInterface $utcTime, string $workspaceTimezone = 'UTC'): Carbon
    {
        return Carbon::parse($utcTime, 'UTC')->setTimezone($workspaceTimezone);
    }
}
