<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Services\ExpiryWatch;
use Illuminate\Console\Command;

/** Marks batches past their expiry date as expired and warns the stock team about the ones about to (see ExpiryWatch). */
class ExpiryWatchCommand extends Command
{
    protected $signature = 'stock:expiry-watch';

    protected $description = 'Mark expired batches and warn about batches about to expire';

    public function handle(ExpiryWatch $watch): int
    {
        $result = $watch->run();

        $this->info('Batches marked expired: '.$result['expired']);
        $this->info('Expiry warnings sent: '.$result['warned']);

        return self::SUCCESS;
    }
}
