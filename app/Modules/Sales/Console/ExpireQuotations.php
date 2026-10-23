<?php

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Services\QuotationExpiry;
use Illuminate\Console\Command;

/** Expires the sent quotations that ran past their valid-until date and warns owners about the ones about to (see QuotationExpiry). */
class ExpireQuotations extends Command
{
    protected $signature = 'sales:quotation-expiry';

    protected $description = 'Expire sent quotations past their valid-until date and warn their owners about the ones about to expire';

    public function handle(QuotationExpiry $expiry): int
    {
        $result = $expiry->run();

        $this->info('Quotations expired: '.$result['expired']);
        $this->info('Expiry warnings sent: '.$result['warned']);

        return self::SUCCESS;
    }
}
