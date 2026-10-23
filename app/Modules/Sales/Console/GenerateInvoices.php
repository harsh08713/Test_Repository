<?php

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\InvoiceGenerator;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Makes the invoice for every approved sales order that has none — the retry for an approval whose invoice could not be made at the time
 * (the database was busy, a number could not be taken…). Safe to run at any time and by any number of runs at once: the generator makes one
 * invoice per order however often it is asked.
 */
class GenerateInvoices extends Command
{
    protected $signature = 'sales:generate-invoices';

    protected $description = 'Make the sales invoice for every approved sales order that does not have one yet';

    private const BATCH = 500;

    public function handle(InvoiceGenerator $invoices, TenantContext $tenant): int
    {
        $made = 0;
        $failed = 0;

        $waiting = SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)->where('approval_status', 'approved')->where('state', 'open')
            ->whereDoesntHave('invoices', fn ($q) => $q->withoutGlobalScopes(Tenancy::SCOPES))
            ->orderBy('id')->limit(self::BATCH)->get(['id', 'company_id']);

        foreach ($waiting as $row) {
            try {
                $invoice = $tenant->withCompany((int) $row->getAttribute('company_id'), fn () => $invoices->generateFor((int) $row->getKey()));
                $made += $invoice === null ? 0 : 1;
            } catch (Throwable $e) {
                $failed++;
                $this->error("Order #{$row->getKey()}: ".$e->getMessage());
            }
        }

        $this->info("Invoices made: {$made}");

        if ($failed > 0) {
            $this->warn("Orders that could not be invoiced: {$failed}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
