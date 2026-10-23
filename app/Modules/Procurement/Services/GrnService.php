<?php

namespace App\Modules\Procurement\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\UomConverter;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\GrnItem;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes and changes goods received notes — the one door for their data.
 *
 * A receipt is made against an **approved, open** purchase order, at the order's branch. For each order line it says how much arrived **accepted**
 * and **rejected** (a rejection needs a reason), and, for an item tracked by batch, which batch (with its expiry date when the item expires).
 *
 * **Tolerance** (FORMULAS.md `grn.max_qty`): the quantity received — accepted and rejected together, in base units — may not exceed the ordered
 * quantity plus the line's PO tolerance, less what other receipts that are **pending or approved** have already taken. A draft or rejected receipt
 * reserves nothing. It is checked when the receipt is saved and again, under a lock on the order, when it is sent for approval, so two receipts
 * that each fit alone cannot both go through.
 *
 * The cost of an accepted unit is the order's rate converted to the base unit. Stock is posted only when the receipt is **approved**, once
 * (GrnPoster); nothing here touches stock.
 *
 * Input: ['grn_date', 'supplier_invoice_no', 'remarks', 'lines' => [order line id => ['accepted', 'rejected', 'reject_reason', 'batch_no', 'lot_no', 'mfg_date', 'expiry_date']]]
 */
final class GrnService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly PurchaseOrderService $orders,
        private readonly UomConverter $units,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function create(PurchaseOrder $order, array $input, User $actor): Grn
    {
        return DB::transaction(function () use ($order, $input, $actor) {
            $number = $this->numbers->next(DocumentType::Grn, (int) $order->location_id, now()->startOfDay());
            $po = $this->lockedOrder($order, $actor);
            $data = $this->validated($po, $input, null);

            $grn = new Grn;
            $grn->forceFill([
                'company_id' => (int) $po->company_id, 'location_id' => (int) $po->location_id, 'grn_number' => $number, 'purchase_order_id' => $po->getKey(),
                'supplier_ledger_id' => $po->supplier_ledger_id, 'supplier_name' => $po->supplier_name, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ] + $data['header']);
            $grn->save();

            $this->writeLines($grn, $data['lines'], $actor);

            activity('grns')->causedBy($actor)->performedOn($grn)->event('created')->withProperties(['order' => $po->po_number])->log('created');

            return $grn->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(Grn $grn, array $input, User $actor): Grn
    {
        return DB::transaction(function () use ($grn, $input, $actor) {
            $fresh = Grn::query()->lockForUpdate()->findOrFail($grn->getKey());

            if (! $fresh->isEditableByWorkflow()) {
                throw new FieldRuleException('A goods receipt that is waiting for approval or approved cannot be changed.');
            }

            $po = $this->lockedOrder($fresh->purchaseOrder, $actor);
            $data = $this->validated($po, $input, (int) $fresh->getKey());

            $fresh->items()->get()->each->forceDelete();   // outright, not soft: the old lines must free their unique keys (line number, source line)
            $fresh->forceFill(['updated_by' => $actor->getKey()] + $data['header'])->save();
            $this->writeLines($fresh, $data['lines'], $actor);

            activity('grns')->causedBy($actor)->performedOn($fresh)->event('updated')->log('updated');

            return $fresh->refresh();
        }, 3);
    }

    /** Why a receipt cannot be sent for approval right now, or null. Locks the order so the answer holds until the submission commits. */
    public function problemBeforeSubmit(Grn $grn): ?string
    {
        $po = PurchaseOrder::query()->lockForUpdate()->find($grn->purchase_order_id);

        if ($po === null || ! $po->isReceivable()) {
            return 'The purchase order has been cancelled, so nothing can be received against it.';
        }

        $received = $this->orders->received($po, (int) $grn->getKey());

        foreach ($grn->items()->with('orderLine')->get() as $line) {
            $order = $line->orderLine;

            if ($order !== null && bccomp((string) $line->base_received, $this->orders->remaining($order, $received[$order->getKey()] ?? '0.000'), 3) > 0) {
                return "Line {$line->line_no} ({$line->item_name}) receives more than the order allows: other receipts have taken part of it.";
            }
        }

        return null;
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /** @throws FieldRuleException */
    private function lockedOrder(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());
        $reach = $this->tenant->accessibleLocationIdsFor($actor, (int) $po->company_id);

        if ($reach !== null && ! in_array((int) $po->location_id, $reach, true)) {
            throw new FieldRuleException('This is in a branch you do not work in.');
        }

        if (! $po->isReceivable()) {
            throw new FieldRuleException($po->isCancelled() ? 'This purchase order has been cancelled, so nothing can be received against it.' : 'This purchase order has not been approved yet.');
        }

        return $po;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function validated(PurchaseOrder $po, array $input, ?int $exceptGrnId): array
    {
        $errors = [];
        $today = now()->toDateString();

        $date = (string) ($input['grn_date'] ?? '') === '' ? $today : (string) $input['grn_date'];
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $errors['grn_date'] = 'Enter a valid date.';
        } elseif ($date < $po->po_date->toDateString() || $date > $today) {
            $errors['grn_date'] = 'The date must be between the order date and today.';
        }

        $invoiceNo = trim((string) ($input['supplier_invoice_no'] ?? ''));
        $remarks = trim((string) ($input['remarks'] ?? ''));

        if (mb_strlen($invoiceNo) > 60) {
            $errors['supplier_invoice_no'] = 'The supplier’s invoice number can be at most 60 characters.';
        }

        if (mb_strlen($remarks) > 1000) {
            $errors['remarks'] = 'Remarks can be at most 1000 characters.';
        }

        $received = $this->orders->received($po, $exceptGrnId);
        $orderLines = $po->items()->get()->keyBy('id');
        $lines = [];

        foreach ((array) ($input['lines'] ?? []) as $lineId => $row) {
            $row = (array) $row;
            $key = "lines.$lineId";
            $accepted = trim((string) ($row['accepted'] ?? ''));
            $rejected = trim((string) ($row['rejected'] ?? ''));

            if (($accepted === '' || $accepted === '0') && ($rejected === '' || $rejected === '0')) {
                continue;   // nothing arrived on this line
            }

            $orderLine = $orderLines->get((int) $lineId);

            if (! $orderLine instanceof PurchaseOrderItem) {
                $errors[$key] = 'That is not a line of this purchase order.';

                continue;
            }

            $item = Item::query()->with(['baseUnit', 'uoms.unit'])->find($orderLine->item_id);
            $unit = $orderLine->unit;

            if ($item === null || $unit === null) {
                $errors[$key] = 'This item can no longer be found.';

                continue;
            }

            $accepted = $accepted === '' ? '0' : $accepted;
            $rejected = $rejected === '' ? '0' : $rejected;

            foreach (['accepted' => $accepted, 'rejected' => $rejected] as $name => $value) {
                if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $value) !== 1) {
                    $errors["$key.$name"] = 'Enter a quantity, with up to 3 decimals.';
                } elseif (! $this->units->accepts($unit, $value)) {
                    $errors["$key.$name"] = "{$unit->name} cannot be split: enter a whole number.";
                }
            }

            if (isset($errors["$key.accepted"]) || isset($errors["$key.rejected"])) {
                continue;
            }

            $accepted = bcadd($accepted, '0', 3);
            $rejected = bcadd($rejected, '0', 3);
            $baseAccepted = $this->units->toBase($item, $accepted, $unit);
            $baseReceived = bcadd($baseAccepted, $this->units->toBase($item, $rejected, $unit), 3);

            $left = $this->orders->remaining($orderLine, $received[$orderLine->getKey()] ?? '0.000');

            if (bccomp($baseReceived, $left, 3) > 0) {
                $errors[$key] = 'The order allows only '.rtrim(rtrim($left, '0'), '.').' more of '.$orderLine->item_name.' (ordered '.rtrim(rtrim((string) $orderLine->base_quantity, '0'), '.').", tolerance {$orderLine->tolerance_percent}%).";

                continue;
            }

            $reason = trim((string) ($row['reject_reason'] ?? ''));

            if (bccomp($rejected, '0', 3) > 0 && (mb_strlen($reason) < 3 || mb_strlen($reason) > 500)) {
                $errors["$key.reject_reason"] = 'Please say why it was rejected (3 to 500 characters).';
            }

            $batch = $this->batchFields($item, $row, bccomp($accepted, '0', 3) > 0, $key, $errors);

            $lines[] = [
                'purchase_order_item_id' => $orderLine->getKey(), 'item_id' => $item->getKey(), 'unit_id' => $unit->getKey(), 'item_name' => $orderLine->item_name,
                'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected, 'base_accepted' => $baseAccepted, 'base_received' => $baseReceived,
                'unit_cost' => bcadd(bcdiv((string) $orderLine->rate, $this->units->factor($item, $unit), 10), '0.00005', 4),
                'reject_reason' => bccomp($rejected, '0', 3) > 0 ? $reason : null,
            ] + $batch;
        }

        if ($lines === [] && $errors === []) {
            $errors['lines'] = 'Enter what arrived on at least one line.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'header' => ['grn_date' => $date, 'supplier_invoice_no' => $invoiceNo === '' ? null : $invoiceNo, 'remarks' => $remarks === '' ? null : $remarks],
            'lines' => $lines,
        ];
    }

    /**
     * The batch fields of a line, checked against how the item is tracked.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $errors
     * @return array{batch_no: string|null, lot_no: string|null, mfg_date: string|null, expiry_date: string|null}
     */
    private function batchFields(Item $item, array $row, bool $accepting, string $key, array &$errors): array
    {
        $none = ['batch_no' => null, 'lot_no' => null, 'mfg_date' => null, 'expiry_date' => null];
        $batchNo = trim((string) ($row['batch_no'] ?? ''));

        if ($item->tracking !== 'batch') {
            if ($batchNo !== '') {
                $errors["$key.batch_no"] = "{$item->name} is not tracked by batch: leave the batch blank.";
            }

            return $none;
        }

        if (! $accepting) {
            return $none;   // nothing is stocked, so no batch is made
        }

        if ($batchNo === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/ ]{0,59}$/', $batchNo) !== 1) {
            $errors["$key.batch_no"] = 'Enter the batch number (letters, digits and . _ - / only, up to 60).';

            return $none;
        }

        $dates = [];

        foreach (['mfg_date', 'expiry_date'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            $dates[$field] = $value === '' ? null : $value;

            if ($value !== '') {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                    $errors["$key.$field"] = 'Enter a valid date.';
                    $dates[$field] = null;
                }
            }
        }

        if ($item->has_expiry && $dates['expiry_date'] === null && ! isset($errors["$key.expiry_date"])) {
            $errors["$key.expiry_date"] = "{$item->name} expires: enter the expiry date of this batch.";
        }

        if ($dates['expiry_date'] !== null && $dates['expiry_date'] < now()->toDateString()) {
            $errors["$key.expiry_date"] = 'This batch has already expired: reject it rather than stock it.';
        }

        if ($dates['mfg_date'] !== null && $dates['expiry_date'] !== null && $dates['expiry_date'] <= $dates['mfg_date']) {
            $errors["$key.expiry_date"] = 'The expiry date must be after the date of manufacture.';
        }

        // a batch that already exists keeps its dates: a receipt cannot quietly move them
        $existing = Batch::query()->where('item_id', $item->getKey())->where('batch_no', $batchNo)->first();

        if ($existing !== null && $dates['expiry_date'] !== null && $existing->expiry_date?->toDateString() !== $dates['expiry_date']) {
            $errors["$key.expiry_date"] = "Batch {$batchNo} already exists with expiry ".($existing->expiry_date?->format('d-m-Y') ?? 'none').'. Use another batch number if this is a different batch.';
        }

        $lot = trim((string) ($row['lot_no'] ?? ''));

        if (mb_strlen($lot) > 60) {
            $errors["$key.lot_no"] = 'The lot number can be at most 60 characters.';
        }

        return ['batch_no' => $batchNo, 'lot_no' => $lot === '' ? null : $lot] + $dates;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(Grn $grn, array $lines, User $actor): void
    {
        foreach ($lines as $i => $l) {
            $line = new GrnItem;
            $line->forceFill(['company_id' => (int) $grn->company_id, 'grn_id' => $grn->getKey(), 'line_no' => $i + 1, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()] + $l)->save();
        }
    }
}
