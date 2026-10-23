<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, either makes a sales order from a
 * quotation or generates the invoice of an approved sales order — so several workers hit the same rule at the same moment.
 *
 *   php tests/Support/order_worker.php order   <companyId> <actorId> <quotationId> <customerId> <itemId> <startAt>
 *   php tests/Support/order_worker.php invoice <companyId> <orderId> <startAt>
 *
 * Prints one line: "OK <number>", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\InvoiceGenerator;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);
$startAt = (float) array_pop($args);

try {
    // Connect first, so that what follows the wait is the work itself and not the handshake.
    DB::select('select 1');

    // Everything is loaded and warm; now wait for the shared start time so the calls really collide.
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    if ($mode === 'order') {
        [$companyId, $actorId, $quotationId, $customerId, $itemId] = $args;

        $order = app(TenantContext::class)->withCompany((int) $companyId, function () use ($actorId, $quotationId, $customerId, $itemId) {
            $quotation = Quotation::query()->findOrFail((int) $quotationId);

            return app(SalesOrderService::class)->createFromQuotation($quotation, [
                'order' => [
                    'delivery_date' => now()->addDays(10)->toDateString(), 'place_of_supply_state_id' => (string) DB::table('states')->where('company_id', (int) $quotation->company_id)->where('gst_code', '24')->value('id'),
                    'party_name' => 'Racing customer', 'bill_line1' => '1 Race Road', 'reprice' => '',
                ],
                'items' => [['id' => '', 'quotation_item_id' => (string) DB::table('quotation_items')->where('quotation_id', (int) $quotationId)->value('id'), 'deal_item_id' => '',
                    'item_id' => $itemId, 'unit_id' => '', 'quantity' => '5', 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '']],
                'customer_ledger_id' => $customerId,
            ], User::query()->findOrFail((int) $actorId));
        });

        echo 'OK '.$order->so_number, PHP_EOL;
    } elseif ($mode === 'invoice') {
        [$companyId, $orderId] = $args;

        $invoice = app(TenantContext::class)->withCompany((int) $companyId, fn () => app(InvoiceGenerator::class)->generateFor((int) $orderId));

        echo 'OK '.($invoice->invoice_number ?? 'none'), PHP_EOL;
    } else {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (FieldRuleException $e) {
    echo 'REFUSED: '.$e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}
