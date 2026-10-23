<?php

namespace App\Modules\Loss\Console;

use App\Modules\Loss\Services\SavingsService;
use Illuminate\Console\Command;

/** Flags each Finance-verified saving whose loss has come back (an incident of the same category at the same branch after its window), once. */
class SavingsRecurrenceCommand extends Command
{
    protected $signature = 'savings:check-recurrence';

    protected $description = 'Flag verified savings whose loss has recurred';

    public function handle(SavingsService $savings): int
    {
        $this->info('Savings flagged as recurred: '.$savings->sweepRecurrence());

        return self::SUCCESS;
    }
}
