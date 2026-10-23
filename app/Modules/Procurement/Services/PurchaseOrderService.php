<?php

namespace App\Modules\Procurement\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\UomConverter;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes and changes purchase orders — the one door for their data.
 *
 * An order goes to an **approved, active supplier** (LedgerLookup) for **approved, active items** (ItemLookup), each in a unit the item has, to be
 * received at one branch the person works in. The quantity is also kept in the item's base unit (UomConverter, exact), and the item's PO tolerance
 * is copied onto the line so a later change of the item does not move what was agreed. GST is not worked out on purchase orders yet: the total
 * is quantity × rate. Numbering takes the PO number first, in the same transaction.
 *
 * What has been received is never stored on the order: it is worked out from the goods receipts that are pending or approved (`received()`), so a
 * rejected or draft receipt reserves nothing and there is one place to look.
 *
 * Input: ['location_id', 'po_date' (today), 'expected_date' (today + the supplier's lead time), 'supplier_ledger_id', 'terms', 'remarks',
 *         'items' => [['item_id', 'unit_id', 'quantity', 'rate'], …]]
 */
final class PurchaseOrderService
{
    public const MAX_LINES = 60;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly LedgerLookup $ledgers,
        private readonly ItemLookup $items,
        private readonly UomConverter $units,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function create(array $input, User $actor): PurchaseOrder
    {
        $data = $this->validated($input, $actor, null);

        return DB::transaction(function () use ($data, $actor) {
            $number = $this->numbers->next(DocumentType::PurchaseOrder, $data['location_id'], now()->startOfDay());

            $po = new PurchaseOrder;
            $po->forceFill([
                'company_id' => (int) $this->tenant->activeCompanyId(), 'location_id' => $data['location_id'], 'po_number' => $number,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ] + $data['header']);
            $po->save();

            $this->writeLines($po, $data['lines'], $actor);

            activity('purchase_orders')->causedBy($actor)->performedOn($po)->event('created')->withProperties(['total' => (string) $po->total_amount])->log('created');

            return $po->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(PurchaseOrder $order, array $input, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $input, $actor) {
            $fresh = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! $fresh->isEditableByWorkflow() || $fresh->isCancelled()) {
                throw new FieldRuleException('A purchase order that is waiting for approval, approved or cancelled cannot be changed.');
            }

            $data = $this->validated($input, $actor, $fresh);

            $fresh->items()->get()->each->forceDelete();   // outright, not soft: the old lines must free their unique keys (line number, source line)
            $fresh->forceFill(['location_id' => $data['location_id'], 'updated_by' => $actor->getKey()] + $data['header'])->save();
            $this->writeLines($fresh, $data['lines'], $actor);

            activity('purchase_orders')->causedBy($actor)->performedOn($fresh)->event('updated')->withProperties(['total' => (string) $fresh->total_amount])->log('updated');

            return $fresh->refresh();
        }, 3);
    }

    /**
     * Withdraw an order. Not while it waits for a decision, and not while receipts stand against it.
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function cancel(PurchaseOrder $order, string $reason, User $actor): PurchaseOrder
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Please say why the order is being cancelled (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($order, $reason, $actor) {
            $fresh = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->assertBranch($fresh->location_id, (int) $fresh->company_id, $actor);

            if ($fresh->isCancelled()) {
                throw new FieldRuleException('This order has already been cancelled.');
            }

            if ($fresh->isPendingApproval()) {
                throw new FieldRuleException('This order is waiting for approval: wait for the decision before cancelling it.');
            }

            if (DB::table('grns')->where('purchase_order_id', $fresh->getKey())->whereIn('approval_status', ['pending', 'approved'])->exists()) {
                throw new FieldRuleException('Goods have been received (or are being received) against this order, so it cannot be cancelled.');
            }

            $fresh->forceFill(['state' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_by' => $actor->getKey()])->save();

            activity('purchase_orders')->causedBy($actor)->performedOn($fresh)->event('cancelled')->withProperties(['reason' => $reason])->log('cancelled');

            return $fresh->refresh();
        });
    }

    /**
     * Base-unit quantity taken so far against each line by receipts that are pending or approved.
     *
     * @return array<int, string> purchase order line id => quantity (3 decimals)
     */
    public function received(PurchaseOrder $order, ?int $exceptGrnId = null): array
    {
        $rows = DB::table('grn_items as i')->join('grns as g', 'g.id', '=', 'i.grn_id')
            ->where('g.purchase_order_id', $order->getKey())->whereIn('g.approval_status', ['pending', 'approved'])
            ->when($exceptGrnId !== null, fn ($q) => $q->where('g.id', '<>', $exceptGrnId))
            ->groupBy('i.purchase_order_item_id')->selectRaw('i.purchase_order_item_id as line, sum(i.base_received) as quantity')->pluck('quantity', 'line');

        $received = [];

        foreach ($order->items()->get(['id']) as $line) {
            $received[(int) $line->id] = bcadd((string) ($rows[$line->id] ?? '0'), '0', 3);
        }

        return $received;
    }

    /** The most that may be received on a line: the ordered quantity plus the item's tolerance, less what has been received (3 decimals, never below zero). */
    public function remaining(PurchaseOrderItem $line, string $received): string
    {
        $factor = bcadd('1', bcdiv((string) $line->tolerance_percent, '100', 6), 6);
        $allowed = bcmul((string) $line->base_quantity, $factor, 3);   // truncated: the tolerance never rounds in the supplier's favour
        $left = bcsub($allowed, $received, 3);

        return bccomp($left, '0', 3) < 0 ? '0.000' : $left;
    }

    /** Why a purchase order cannot be sent for approval right now, or null. */
    public function problemBeforeSubmit(PurchaseOrder $order): ?string
    {
        if ($this->ledgers->usable((int) $order->supplier_ledger_id, LedgerType::Supplier) === null) {
            return 'The supplier is no longer an approved, active supplier.';
        }

        foreach ($order->items()->get() as $line) {
            if ($this->items->usable((int) $line->item_id) === null) {
                return "Line {$line->line_no} ({$line->item_name}) is no longer an approved, active item.";
            }
        }

        return null;
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array{location_id: int, header: array<string, mixed>, lines: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function validated(array $input, User $actor, ?PurchaseOrder $editing): array
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();
        $today = now()->toDateString();

        // branch
        $locationId = is_numeric($input['location_id'] ?? null) ? (int) $input['location_id'] : 0;
        $location = Location::query()->whereKey($locationId)->where('status', 'active')->first();
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if ($location === null || ($reach !== null && ! in_array($locationId, $reach, true))) {
            $errors['location_id'] = 'Choose a branch you work in.';
        }

        // supplier
        $supplier = is_numeric($input['supplier_ledger_id'] ?? null) ? $this->ledgers->usable((int) $input['supplier_ledger_id'], LedgerType::Supplier) : null;

        if ($supplier === null) {
            $errors['supplier_ledger_id'] = 'Choose an approved, active supplier.';
        }

        // dates
        $poDate = (string) ($input['po_date'] ?? '') === '' ? $today : (string) $input['po_date'];
        $lead = max(0, (int) ($supplier->lead_time_days ?? 0));
        $expected = (string) ($input['expected_date'] ?? '') === '' ? now()->addDays($lead)->toDateString() : (string) $input['expected_date'];

        foreach (['po_date' => $poDate, 'expected_date' => $expected] as $field => $value) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                $errors[$field] = 'Enter a valid date.';
            }
        }

        if (! isset($errors['po_date']) && $poDate > $today) {
            $errors['po_date'] = 'The order date cannot be in the future.';
        }

        if (! isset($errors['po_date']) && ! isset($errors['expected_date']) && $expected < $poDate) {
            $errors['expected_date'] = 'The expected date cannot be before the order date.';
        }

        $terms = trim((string) ($input['terms'] ?? ''));
        $remarks = trim((string) ($input['remarks'] ?? ''));

        if (mb_strlen($terms) > 2000) {
            $errors['terms'] = 'Terms can be at most 2000 characters.';
        }

        if (mb_strlen($remarks) > 1000) {
            $errors['remarks'] = 'Remarks can be at most 1000 characters.';
        }

        // lines
        $rows = array_values((array) ($input['items'] ?? []));
        $lines = [];
        $total = '0.00';

        if ($rows === []) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($rows) > self::MAX_LINES) {
            $errors['items'] = 'An order can have at most '.self::MAX_LINES.' lines.';
        }

        foreach (array_slice($rows, 0, self::MAX_LINES) as $i => $row) {
            $row = (array) $row;
            $key = "items.$i";
            $item = is_numeric($row['item_id'] ?? null) ? $this->items->usable((int) $row['item_id']) : null;

            if ($item === null) {
                $errors["$key.item_id"] = 'Choose an approved, active item.';

                continue;
            }

            $unit = is_numeric($row['unit_id'] ?? null) ? $this->unitOf($item, (int) $row['unit_id']) : null;

            if ($unit === null) {
                $errors["$key.unit_id"] = 'Choose one of the units this item has.';

                continue;
            }

            $quantity = trim((string) ($row['quantity'] ?? ''));
            $rate = trim((string) ($row['rate'] ?? ''));

            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1 || bccomp($quantity, '0', 3) <= 0) {
                $errors["$key.quantity"] = 'Enter a quantity greater than zero, with up to 3 decimals.';
            } elseif (! $this->units->accepts($unit, $quantity)) {
                $errors["$key.quantity"] = "{$unit->name} cannot be split: enter a whole number.";
            }

            if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $rate) !== 1) {
                $errors["$key.rate"] = 'Enter the rate per unit (a number, up to 4 decimals).';
            }

            if (isset($errors["$key.quantity"]) || isset($errors["$key.rate"])) {
                continue;
            }

            $quantity = bcadd($quantity, '0', 3);
            $amount = bcadd(bcmul($quantity, $rate, 7), '0.005', 2);
            $total = bcadd($total, $amount, 2);

            $lines[] = [
                'item_id' => $item->getKey(), 'unit_id' => $unit->getKey(), 'item_name' => $item->name, 'quantity' => $quantity,
                'base_quantity' => $this->units->toBase($item, $quantity, $unit), 'rate' => bcadd($rate, '0', 4), 'amount' => $amount,
                'tolerance_percent' => (string) $item->po_tolerance_percent,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'location_id' => $locationId,
            'header' => [
                'po_date' => $poDate, 'expected_date' => $expected, 'supplier_ledger_id' => $supplier->getKey(), 'supplier_name' => $supplier->name,
                'terms' => $terms === '' ? null : $terms, 'remarks' => $remarks === '' ? null : $remarks, 'total_amount' => $total,
            ],
            'lines' => $lines,
        ];
    }

    private function unitOf(Item $item, int $unitId): ?Unit
    {
        if ($unitId === (int) $item->base_unit_id) {
            return $item->baseUnit;
        }

        return $item->uoms->firstWhere('unit_id', $unitId)?->unit;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(PurchaseOrder $po, array $lines, User $actor): void
    {
        foreach ($lines as $i => $l) {
            $line = new PurchaseOrderItem;
            $line->forceFill([
                'company_id' => (int) $po->company_id, 'purchase_order_id' => $po->getKey(), 'line_no' => $i + 1,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ] + $l)->save();
        }
    }

    /** @throws FieldRuleException */
    private function assertBranch(int $locationId, int $companyId, User $actor): void
    {
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if ($reach !== null && ! in_array($locationId, $reach, true)) {
            throw new FieldRuleException('This is in a branch you do not work in.');
        }
    }
}
