<?php

namespace App\Modules\Sales\Services;

use App\Modules\Masters\Exceptions\UomException;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\CostResolver;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Sales\Exceptions\InvoiceAlreadyMade;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesOrderStateEntry;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\NumberSeriesService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes the sales invoice for an approved sales order — **exactly one, however many times it is asked for**.
 *
 * Under this release's full-invoice policy an approved order gets one invoice for all of its lines, keyed `full`. It is asked for when the
 * order is approved (the DocumentApproved listener), when the retry command finds an approved order without one, and by anyone who calls
 * `generateFor()` again; every one of those is safe:
 *
 *  1. An invoice that already exists is returned at once, without taking a number.
 *  2. Otherwise, inside a transaction: the invoice number is taken **first** (so concurrent callers queue on the series instead of
 *     deadlocking), the order's row is locked, and the existence of the invoice is checked again with a locking read. If it has appeared in
 *     the meantime the transaction is rolled back — which also gives the number back, so the series stays free of gaps — and that invoice is
 *     returned.
 *  3. `unique(sales_order_id, source_key)` is the backstop: even if every one of the above failed, a second invoice cannot be inserted.
 *
 * The invoice is a copy of the order as it was approved — customer, addresses, place of supply, prices, tax, totals — with its own number and
 * links to the order and to each of its lines. It posts nothing to stock or to accounts. A failure that is not a duplicate leaves the order
 * approved and without an invoice, records what went wrong on the order (`invoice_error`) and is retried by `sales:generate-invoices`.
 */
final class InvoiceGenerator
{
    public function __construct(private readonly NumberSeriesService $numbers, private readonly CostResolver $costs) {}

    /**
     * The invoice for an order — made now if it has not been, otherwise the one that exists. Returns null for an order that cannot be invoiced
     * (not approved, or cancelled).
     */
    public function generateFor(SalesOrder|int $order): ?SalesInvoice
    {
        $id = $order instanceof SalesOrder ? (int) $order->getKey() : $order;

        $existing = $this->existingFor($id);

        if ($existing !== null) {
            return $existing;
        }

        $fresh = SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)->find($id);

        if ($fresh === null || ! $fresh->isApproved() || $fresh->isCancelled()) {
            return null;
        }

        try {
            return DB::transaction(fn () => $this->make($fresh), 3);
        } catch (InvoiceAlreadyMade $e) {
            return $this->existingFor($id);   // null if the order stopped being invoiceable meanwhile
        } catch (UniqueConstraintViolationException) {
            return $this->existingFor($id);   // the unique key said no: somebody else made it
        } catch (Throwable $e) {
            $this->recordFailure($id, $e);

            throw $e;
        }
    }

    private function make(SalesOrder $fresh): SalesInvoice
    {
        $today = now()->startOfDay();

        // The number first, before anything is locked or written.
        $number = $this->numbers->next(DocumentType::SalesInvoice, (int) $fresh->location_id, $today);

        $order = SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($fresh->getKey())->lockForUpdate()->firstOrFail();

        $made = SalesInvoice::withoutGlobalScopes(Tenancy::SCOPES)->where('sales_order_id', $order->getKey())->where('source_key', SalesInvoice::FULL)->lockForUpdate()->first();

        if ($made !== null) {
            throw new InvoiceAlreadyMade((int) $made->getKey());   // rolls the transaction back, and the number with it
        }

        if (! $order->isApproved() || $order->isCancelled()) {
            throw new InvoiceAlreadyMade(0);
        }

        $creditDays = (int) (Ledger::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($order->customer_ledger_id)->value('credit_days') ?? 0);
        $copy = [
            'customer_ledger_id', 'assigned_to', 'price_list_id', 'price_date', 'party_name', 'contact_person', 'mobile', 'email', 'bill_line1', 'bill_line2', 'bill_city', 'bill_pin_code',
            'customer_gstin', 'ship_line1', 'ship_line2', 'ship_city', 'ship_pin_code', 'place_of_supply_state_id', 'supply_type', 'supplier_gstin', 'supplier_state_code',
            'customer_po_number', 'customer_po_date', 'terms', 'gross_total', 'discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'round_off', 'grand_total',
        ];

        $invoice = new SalesInvoice;
        $invoice->forceFill(array_intersect_key($order->getAttributes(), array_flip($copy)));
        $invoice->forceFill([
            'company_id' => (int) $order->company_id, 'location_id' => (int) $order->location_id, 'invoice_number' => $number, 'invoice_date' => $today->toDateString(),
            'due_date' => $today->copy()->addDays($creditDays)->toDateString(), 'sales_order_id' => $order->getKey(), 'source_key' => SalesInvoice::FULL,
            'status' => SalesInvoice::ISSUED, 'remarks' => $order->remarks, 'created_by' => $order->approved_by, 'updated_by' => $order->approved_by,
        ])->save();

        $columns = ['line_no', 'item_id', 'unit_id', 'item_name', 'hsn_code', 'quantity', 'rate_id', 'rate_source', 'list_rate', 'rate', 'rate_overridden', 'override_reason',
            'discount_percent', 'gross_amount', 'discount_amount', 'taxable_amount', 'gst_rate', 'cgst_amount', 'sgst_amount', 'igst_amount', 'line_total'];

        foreach ($order->items()->get() as $line) {
            $copyLine = new SalesInvoiceItem;
            $copyLine->forceFill(array_intersect_key($line->getAttributes(), array_flip($columns)));
            $copyLine->forceFill([
                'company_id' => (int) $order->company_id, 'sales_invoice_id' => $invoice->getKey(), 'sales_order_item_id' => $line->getKey(),
                'created_by' => $order->approved_by, 'updated_by' => $order->approved_by,
                ...$this->costOf($line, (int) $order->location_id),
            ])->save();

            $line->forceFill(['invoiced_quantity' => $line->quantity])->save();   // in full: invoicing in parts later moves this by less
        }

        $from = $order->state;
        $order->forceFill(['state' => SalesOrderState::Invoiced->value, 'invoiced_at' => now(), 'invoice_attempted_at' => now(), 'invoice_error' => null])->save();

        $entry = new SalesOrderStateEntry;
        $entry->forceFill([
            'company_id' => (int) $order->company_id, 'sales_order_id' => $order->getKey(), 'from_state' => $from->value, 'to_state' => SalesOrderState::Invoiced->value,
            'comment' => "Invoice {$invoice->invoice_number} made", 'actor_id' => null, 'created_at' => now(),
        ])->save();

        return $invoice->refresh();
    }

    /**
     * The cost of the line as it stands today, taken once and never recalculated (FORMULAS.md `line.cost_amount`). An item with no cost, or a
     * unit the item cannot convert, leaves the cost blank — unknown is not zero, and a missing cost never stops an invoice being made.
     *
     * @return array{unit_cost: string|null, cost_amount: string|null, cost_source: string|null}
     */
    private function costOf(SalesOrderItem $line, int $locationId): array
    {
        $none = ['unit_cost' => null, 'cost_amount' => null, 'cost_source' => null];

        $item = Item::withoutGlobalScopes(Tenancy::SCOPES)->with('uoms')->find($line->item_id);
        $unit = Unit::withoutGlobalScopes(Tenancy::SCOPES)->find($line->unit_id);

        if ($item === null || $unit === null) {
            return $none;
        }

        try {
            $cost = $this->costs->unitCost($item, $unit, $locationId);
        } catch (UomException) {
            return $none;
        }

        if ($cost === null) {
            return $none;
        }

        return [
            'unit_cost' => $cost['unit_cost'],
            'cost_amount' => bcadd(bcmul((string) $line->quantity, $cost['unit_cost'], 7), '0.005', 2),
            'cost_source' => $cost['source'],
        ];
    }

    private function existingFor(int $orderId): ?SalesInvoice
    {
        return SalesInvoice::withoutGlobalScopes(Tenancy::SCOPES)->where('sales_order_id', $orderId)->where('source_key', SalesInvoice::FULL)->first();
    }

    /** Keep what went wrong where somebody can see it (never a secret: the message of a database or code error only). */
    private function recordFailure(int $orderId, Throwable $e): void
    {
        SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($orderId)->update([
            'invoice_attempted_at' => now(), 'invoice_error' => Str::limit(class_basename($e).': '.$e->getMessage(), 480, '…'),
        ]);
    }
}
