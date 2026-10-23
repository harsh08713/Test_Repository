<?php

namespace App\Modules\HR\Console;

use App\Modules\HR\Services\LeaveAccrual;
use Illuminate\Console\Command;

/** Grants the leave that is due and carries last year's unused days forward (see LeaveAccrual). Safe to run again: every line is written once. */
class AccrueLeave extends Command
{
    protected $signature = 'hr:leave-accrue';

    protected $description = 'Grant the leave days that are due and carry forward the unused ones';

    public function handle(LeaveAccrual $accrual): int
    {
        $this->info('Leave ledger lines written: '.$accrual->run());

        return self::SUCCESS;
    }
}
