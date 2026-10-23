<?php

namespace App\Modules\Shared\Console;

use App\Modules\Shared\Services\Heartbeat as Beats;
use Illuminate\Console\Command;

/** Run by the scheduler every minute, for no other reason than to prove that the scheduler is running. */
class Heartbeat extends Command
{
    protected $signature = 'system:heartbeat';

    protected $description = 'Record that the scheduler is running (shown on the System health page)';

    public function handle(Beats $beats): int
    {
        $beats->beat(Beats::SCHEDULER);

        return self::SUCCESS;
    }
}
