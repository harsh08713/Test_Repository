<?php

namespace App\Modules\Loss\Console;

use App\Modules\Loss\Services\CapaService;
use Illuminate\Console\Command;

/** Tells the owner and approver of each corrective action that has passed its due date, once (see CapaService::escalateOverdue). */
class EscalateLossCommand extends Command
{
    protected $signature = 'loss:escalate';

    protected $description = 'Escalate corrective actions that are past their due date';

    public function handle(CapaService $capa): int
    {
        $this->info('Overdue actions escalated: '.$capa->escalateOverdue());

        return self::SUCCESS;
    }
}
