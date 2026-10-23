<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Services\StockService;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Console\Command;

/** Makes every stock balance again from the stock ledger, and says how many were wrong (it should be none). */
class RebuildBalancesCommand extends Command
{
    protected $signature = 'stock:rebuild-balances';

    protected $description = 'Rebuild the stock balances from the stock ledger and report any that were wrong';

    public function handle(StockService $stock): int
    {
        $wrong = 0;

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->pluck('id') as $companyId) {
            $wrong += $stock->rebuildBalances((int) $companyId);
        }

        $wrong === 0 ? $this->info('Every balance agrees with the ledger.') : $this->warn("{$wrong} balance(s) disagreed with the ledger and were corrected.");

        return $wrong === 0 ? self::SUCCESS : self::FAILURE;
    }
}
