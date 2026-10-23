<?php

namespace App\Modules\HR\Console;

use App\Modules\HR\Services\AttendanceService;
use Illuminate\Console\Command;

/** Closes yesterday's attendance (and any day of the last week that was missed) for everyone. Safe to run again: it rewrites the same lines. */
class CloseAttendance extends Command
{
    protected $signature = 'hr:attendance-close';

    protected $description = 'Close the attendance of the days that have ended';

    public function handle(AttendanceService $attendance): int
    {
        $this->info('Attendance days written: '.$attendance->sweep());

        return self::SUCCESS;
    }
}
