<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionComponent;
use App\Modules\Production\Models\ProductionIssue;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOutput;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production orders — the one door for their data and for everything that moves stock because of them.
 *
 *  - **Making one** (draft): an approved, active bill of materials, a quantity, dates and a branch the person works in. The components it requires are **copied from the bill** then
 *    (quantity × planned ÷ the bill's output, plus the wastage), so a later change of the bill moves nothing already planned. The order number is taken first, in the transaction.
 *  - **Releasing** puts it on the shop floor; the bill and its items must still be approved and active. The first issue or output makes it *in progress*.
 *  - **Issuing material** takes a component out of stock at the order's branch (`StockService::issue`, once per entry), up to what is required plus the company's tolerance.
 *  - **Reporting output** receives the good units into stock — at the material cost that went in, spread over the good units made so far — and records rejects with a reason. Up to the
 *    planned quantity plus the tolerance. A batch-tracked item needs a batch number (and an expiry if it has one).
 *  - **Completing** needs output; short of the plan it needs a note. **Cancelling** is only before any material was issued.
 *  - Every issue and output carries an `entry_key` (made when the form opened), so a double click or a retried request is one entry. The order row is locked first for every change.
 */
final class ProductionOrderService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly ItemLookup $items,
        private readonly StockService $stock,
        private readonly ProductionFigures $figures,
    ) {}

    /**
     * @param  array<string, mixed>  $input  location_id, bom_id, planned_quantity, planned_start, planned_end, remarks
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): ProductionOrder
    {
        $data = $this->validated($input, $actor);

        return DB::transaction(function () use ($data, $actor) {
            $number = $this->numbers->next(DocumentType::ProductionOrder, $data['location_id'], now()->startOfDay());

            $order = new ProductionOrder;
            $order->forceFill([
                'company_id' => (int) $this->tenant->activeCompanyId(), 'location_id' => $data['location_id'], 'order_number' => $number, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ] + $data['header'])->save();
            $this->writeComponents($order, $data['bom'], $data['quantity']);

            activity('production_orders')->causedBy($actor)->performedOn($order)->event('created')->withProperties(['quantity' => $data['quantity']])->log('created');

            return $order->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(ProductionOrder $order, array $input, User $actor): ProductionOrder
    {
        return DB::transaction(function () use ($order, $input, $actor) {
            $fresh = $this->lock($order);

            if ($fresh->state !== 'draft') {
                throw new FieldRuleException('Only a draft production order can be changed.');
            }

            $data = $this->validated($input, $actor);

            $fresh->components()->get()->each->forceDelete();
            $fresh->forceFill(['location_id' => $data['location_id'], 'updated_by' => $actor->getKey()] + $data['header'])->save();
            $this->writeComponents($fresh, $data['bom'], $data['quantity']);

            activity('production_orders')->causedBy($actor)->performedOn($fresh)->event('updated')->log('updated');

            return $fresh->refresh();
        }, 3);
    }

    /** @throws FieldRuleException */
    public function release(ProductionOrder $order, User $actor): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $fresh = $this->lock($order);
            $this->assertBranch((int) $fresh->location_id, (int) $fresh->company_id, $actor);

            if ($fresh->state !== 'draft') {
                throw new FieldRuleException('Only a draft can be released.');
            }

            $bom = Bom::query()->find($fresh->bom_id);

            if ($bom === null || ! $bom->isUsable()) {
                throw new FieldRuleException('The bill of materials is no longer approved and active, so the order cannot be released.');
            }

            foreach ($fresh->components()->get() as $c) {
                if ($this->items->usable((int) $c->item_id) === null) {
                    throw new FieldRuleException("{$c->item_name} is no longer an approved, active item, so the order cannot be released.");
                }
            }

            if ($this->items->usable((int) $fresh->item_id) === null) {
                throw new FieldRuleException("{$fresh->item_name} is no longer an approved, active item, so the order cannot be released.");
            }

            $fresh->forceFill(['state' => 'released', 'released_at' => now(), 'updated_by' => $actor->getKey()])->save();
            activity('production_orders')->causedBy($actor)->performedOn($fresh)->event('released')->log('released');

            return $fresh->refresh();
        });
    }

    /**
     * Take material for a component out of stock.
     *
     * @param  numeric-string|string  $quantity  base units
     *
     * @throws ValidationException
     * @throws FieldRuleException
     * @throws StockException
     */
    public function issue(ProductionOrder $order, int $componentId, ?int $batchId, string $quantity, string $entryKey, User $actor): ProductionIssue
    {
        $quantity = $this->quantity($quantity, 'quantity');
        $entryKey = $this->key($entryKey);

        return DB::transaction(function () use ($order, $componentId, $batchId, $quantity, $entryKey, $actor) {
            $fresh = $this->lock($order);
            $this->assertBranch((int) $fresh->location_id, (int) $fresh->company_id, $actor);

            if (($again = ProductionIssue::query()->where('production_order_id', $fresh->id)->where('entry_key', $entryKey)->first()) !== null) {
                return $again;
            }

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('Material can be issued only to a released order that is not finished.');
            }

            $component = ProductionComponent::query()->where('production_order_id', $fresh->id)->find($componentId);

            if ($component === null) {
                throw ValidationException::withMessages(['component' => 'That is not a component of this order.']);
            }

            $line = collect($this->figures->forOrder($fresh)['components'])->firstWhere('id', $component->id);

            if (bccomp($quantity, (string) $line['allowed_more'], 3) > 0) {
                throw ValidationException::withMessages(['quantity' => $this->plain((string) $line['allowed_more']) === '0'
                    ? "{$component->item_name} has already been issued as much as the order allows."
                    : 'You can issue at most '.$this->plain((string) $line['allowed_more'])." more of {$component->item_name} (what is required plus the allowance)."]);
            }

            $item = Item::query()->find($component->item_id);
            $batch = $batchId === null ? null : Batch::query()->where('item_id', $component->item_id)->find($batchId);

            if ($item === null || ($batchId !== null && $batch === null)) {
                throw ValidationException::withMessages(['batch' => 'That batch is not a batch of this item.']);
            }

            $issue = new ProductionIssue;
            $issue->forceFill([
                'company_id' => $fresh->company_id, 'production_order_id' => $fresh->id, 'production_component_id' => $component->id, 'item_id' => $component->item_id,
                'batch_id' => $batch?->getKey(), 'quantity' => $quantity, 'entry_key' => $entryKey, 'issued_by' => $actor->getKey(), 'issued_at' => now(),
            ])->save();

            $this->stock->issue($item, (int) $fresh->location_id, $quantity, $batch, 'production_issue', (int) $issue->id, 1, $actor);

            if ($fresh->state === 'released') {
                $fresh->forceFill(['state' => 'in_progress', 'updated_by' => $actor->getKey()])->save();
            }

            return $issue;
        }, 3);
    }

    /**
     * Report what was made. Good units go into stock; rejects are recorded with a reason and cost nothing in stock (their material was issued and stays charged to the order).
     *
     * @param  array<string, mixed>  $input  good_quantity, rejected_quantity, reject_reason, batch_no, mfg_date, expiry_date
     *
     * @throws ValidationException
     * @throws FieldRuleException
     * @throws StockException
     */
    public function recordOutput(ProductionOrder $order, array $input, string $entryKey, User $actor): ProductionOutput
    {
        $entryKey = $this->key($entryKey);
        $good = trim((string) ($input['good_quantity'] ?? '')) === '' ? '0' : trim((string) $input['good_quantity']);
        $rejected = trim((string) ($input['rejected_quantity'] ?? '')) === '' ? '0' : trim((string) $input['rejected_quantity']);
        $reason = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['reject_reason'] ?? '')));
        $errors = [];

        foreach (['good_quantity' => $good, 'rejected_quantity' => $rejected] as $field => $value) {
            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $value) !== 1) {
                $errors[$field] = 'Enter a quantity (a number, up to 3 decimals), or leave it empty.';
            }
        }

        if ($errors === [] && bccomp($good, '0', 3) <= 0 && bccomp($rejected, '0', 3) <= 0) {
            $errors['good_quantity'] = 'Enter how many were made, or how many were rejected.';
        }

        if ($errors === [] && bccomp($rejected, '0', 3) > 0 && (mb_strlen($reason) < 3 || mb_strlen($reason) > 300)) {
            $errors['reject_reason'] = 'Say why they were rejected (3 to 300 characters).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $good = bcadd($good, '0', 3);
        $rejected = bcadd($rejected, '0', 3);

        return DB::transaction(function () use ($order, $input, $good, $rejected, $reason, $entryKey, $actor) {
            $fresh = $this->lock($order);
            $this->assertBranch((int) $fresh->location_id, (int) $fresh->company_id, $actor);

            if (($again = ProductionOutput::query()->where('production_order_id', $fresh->id)->where('entry_key', $entryKey)->first()) !== null) {
                return $again;
            }

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('Output can be reported only on a released order that is not finished.');
            }

            $figures = $this->figures->forOrder($fresh);

            if (bccomp(bcadd($good, $rejected, 3), (string) $figures['allowed_more_output'], 3) > 0) {
                throw ValidationException::withMessages(['good_quantity' => 'You can report at most '.$this->plain((string) $figures['allowed_more_output']).' more (the planned quantity plus the allowance).']);
            }

            $item = Item::query()->findOrFail($fresh->item_id);
            $batch = null;
            $unitCost = '0.0000';

            if (bccomp($good, '0', 3) > 0) {
                if (! DB::table('production_issues')->where('production_order_id', $fresh->id)->exists()) {
                    throw new FieldRuleException('Issue the material first: the finished goods are valued at the material that went into them.');
                }

                $totalGood = bcadd((string) $figures['good'], $good, 3);
                $unitCost = bcadd(bcdiv((string) $figures['material_value'], $totalGood, 8), '0.00005', 4);
                $batch = $this->batchFor($item, $input);
            }

            $output = new ProductionOutput;
            $output->forceFill([
                'company_id' => $fresh->company_id, 'production_order_id' => $fresh->id, 'good_quantity' => $good, 'rejected_quantity' => $rejected,
                'reject_reason' => bccomp($rejected, '0', 3) > 0 ? $reason : null, 'batch_id' => $batch?->getKey(), 'unit_cost' => $unitCost,
                'entry_key' => $entryKey, 'recorded_by' => $actor->getKey(), 'recorded_at' => now(),
            ])->save();

            if (bccomp($good, '0', 3) > 0) {
                $this->stock->receive($item, (int) $fresh->location_id, $good, $unitCost, $batch, 'production_output', (int) $output->id, 1, $actor);
            }

            if ($fresh->state === 'released') {
                $fresh->forceFill(['state' => 'in_progress', 'updated_by' => $actor->getKey()])->save();
            }

            return $output;
        }, 3);
    }

    /**
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function complete(ProductionOrder $order, string $note, User $actor): ProductionOrder
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        return DB::transaction(function () use ($order, $note, $actor) {
            $fresh = $this->lock($order);
            $this->assertBranch((int) $fresh->location_id, (int) $fresh->company_id, $actor);

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('Only a released order that is not finished can be completed.');
            }

            $figures = $this->figures->forOrder($fresh);

            if (bccomp((string) $figures['good'], '0', 3) <= 0) {
                throw new FieldRuleException('Nothing has been reported as made yet. Report the output first, or cancel the order if no material was issued.');
            }

            if (bccomp((string) $figures['good'], (string) $figures['planned'], 3) < 0 && (mb_strlen($note) < 3 || mb_strlen($note) > 500)) {
                throw ValidationException::withMessages(['note' => 'Less was made than planned: say why the order is being closed short (3 to 500 characters).']);
            }

            $fresh->forceFill(['state' => 'completed', 'completed_at' => now(), 'completion_note' => $note === '' ? null : mb_substr($note, 0, 500), 'updated_by' => $actor->getKey()])->save();
            activity('production_orders')->causedBy($actor)->performedOn($fresh)->event('completed')->withProperties(['good' => $figures['good'], 'rejected' => $figures['rejected']])->log('completed');

            return $fresh->refresh();
        });
    }

    /**
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function cancel(ProductionOrder $order, string $reason, User $actor): ProductionOrder
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Please say why the order is being cancelled (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($order, $reason, $actor) {
            $fresh = $this->lock($order);
            $this->assertBranch((int) $fresh->location_id, (int) $fresh->company_id, $actor);

            if ($fresh->isFinished()) {
                throw new FieldRuleException('This order is already finished.');
            }

            if (DB::table('production_issues')->where('production_order_id', $fresh->id)->exists() || DB::table('production_outputs')->where('production_order_id', $fresh->id)->exists()) {
                throw new FieldRuleException('Material has been issued (or output reported) on this order, so it cannot be cancelled: complete it instead.');
            }

            $fresh->forceFill(['state' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_by' => $actor->getKey()])->save();
            activity('production_orders')->causedBy($actor)->performedOn($fresh)->event('cancelled')->withProperties(['reason' => $reason])->log('cancelled');

            return $fresh->refresh();
        });
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    private function lock(ProductionOrder $order): ProductionOrder
    {
        return ProductionOrder::query()->lockForUpdate()->findOrFail($order->getKey());
    }

    /** @throws FieldRuleException */
    private function assertBranch(int $locationId, int $companyId, User $actor): void
    {
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if ($reach !== null && ! in_array($locationId, $reach, true)) {
            throw new FieldRuleException('That is a branch you do not work in.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{location_id: int, bom: Bom, quantity: string, header: array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function validated(array $input, User $actor): array
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();

        $locationId = is_numeric($input['location_id'] ?? null) ? (int) $input['location_id'] : 0;
        $location = Location::query()->whereKey($locationId)->where('status', 'active')->first();
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if ($location === null || ($reach !== null && ! in_array($locationId, $reach, true))) {
            $errors['location_id'] = 'Choose a branch you work in.';
        }

        $bom = is_numeric($input['bom_id'] ?? null) ? Bom::query()->with('item')->find((int) $input['bom_id']) : null;

        if ($bom === null || ! $bom->isUsable()) {
            $errors['bom_id'] = 'Choose an approved, active bill of materials.';
        } elseif ($this->items->usable((int) $bom->item_id) === null) {
            $errors['bom_id'] = 'The item this bill makes is no longer an approved, active item.';
        }

        $quantity = trim((string) ($input['planned_quantity'] ?? ''));

        if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1 || bccomp($quantity, '0', 3) <= 0) {
            $errors['planned_quantity'] = 'Enter how much to make: greater than zero, up to 3 decimals.';
        }

        $start = (string) ($input['planned_start'] ?? '') === '' ? now()->toDateString() : (string) $input['planned_start'];
        $end = (string) ($input['planned_end'] ?? '') === '' ? $start : (string) $input['planned_end'];

        foreach (['planned_start' => $start, 'planned_end' => $end] as $field => $value) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                $errors[$field] = 'Enter a valid date.';
            }
        }

        if (! isset($errors['planned_start']) && ! isset($errors['planned_end']) && $end < $start) {
            $errors['planned_end'] = 'The finish date cannot be before the start date.';
        }

        $remarks = trim((string) ($input['remarks'] ?? ''));

        if (mb_strlen($remarks) > 1000) {
            $errors['remarks'] = 'Remarks can be at most 1000 characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'location_id' => $locationId, 'bom' => $bom, 'quantity' => bcadd($quantity, '0', 3),
            'header' => [
                'bom_id' => $bom->getKey(), 'item_id' => $bom->item_id, 'item_name' => $bom->item->name, 'planned_quantity' => bcadd($quantity, '0', 3),
                'planned_start' => $start, 'planned_end' => $end, 'remarks' => $remarks === '' ? null : $remarks,
            ],
        ];
    }

    /** Copy what the bill requires for this quantity: quantity × planned ÷ output, plus wastage, rounded to the nearest thousandth. */
    private function writeComponents(ProductionOrder $order, Bom $bom, string $planned): void
    {
        $factor = bcdiv($planned, (string) $bom->output_quantity, 12);

        foreach ($bom->components()->with('item:id,name')->get() as $n => $line) {
            $required = bcadd(bcmul(bcmul((string) $line->quantity, bcadd('1', bcdiv((string) $line->scrap_percent, '100', 8), 8), 8), $factor, 8), '0.0005', 3);

            $row = new ProductionComponent;
            $row->forceFill([
                'company_id' => $order->company_id, 'production_order_id' => $order->getKey(), 'line_no' => $n + 1, 'item_id' => $line->item_id,
                'item_name' => $line->item->name, 'required_quantity' => $required,
            ])->save();
        }
    }

    /**
     * The batch the good units go into: found by number, or made (with its dates). Null for an item not tracked by batch.
     *
     * @param  array<string, mixed>  $input
     */
    private function batchFor(Item $item, array $input): ?Batch
    {
        if ($item->tracking !== 'batch') {
            return null;
        }

        $no = strtoupper(trim((string) ($input['batch_no'] ?? '')));
        $expiry = trim((string) ($input['expiry_date'] ?? ''));
        $mfg = trim((string) ($input['mfg_date'] ?? ''));

        if ($no === '' || mb_strlen($no) > 40) {
            throw ValidationException::withMessages(['batch_no' => "{$item->name} is tracked by batch: enter the batch number."]);
        }

        if ($item->has_expiry && $expiry === '') {
            throw ValidationException::withMessages(['expiry_date' => "{$item->name} expires: enter the expiry date."]);
        }

        foreach (['mfg_date' => $mfg, 'expiry_date' => $expiry] as $field => $value) {
            if ($value !== '' && (($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) === false || $d->format('Y-m-d') !== $value)) {
                throw ValidationException::withMessages([$field => 'Enter a valid date.']);
            }
        }

        $batch = Batch::query()->where('item_id', $item->getKey())->where('batch_no', $no)->first();

        if ($batch !== null) {
            if ($expiry !== '' && $batch->expiry_date?->toDateString() !== $expiry) {
                throw ValidationException::withMessages(['expiry_date' => "Batch {$no} already exists with a different expiry date."]);
            }

            return $batch;
        }

        $batch = new Batch;
        $batch->forceFill([
            'company_id' => (int) $item->company_id, 'item_id' => $item->getKey(), 'batch_no' => $no, 'mfg_date' => $mfg === '' ? now()->toDateString() : $mfg,
            'expiry_date' => $expiry === '' ? null : $expiry, 'status' => 'active',
        ])->save();

        return $batch;
    }

    /** @throws ValidationException */
    private function quantity(string $quantity, string $field): string
    {
        $quantity = trim($quantity);

        if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1 || bccomp($quantity, '0', 3) <= 0) {
            throw ValidationException::withMessages([$field => 'Enter a quantity greater than zero, with up to 3 decimals.']);
        }

        return bcadd($quantity, '0', 3);
    }

    /** @throws ValidationException */
    private function key(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_\-]{8,40}$/', $key) !== 1) {
            throw ValidationException::withMessages(['entry' => 'This form is out of date: reload the page and try again.']);
        }

        return $key;
    }

    private function plain(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }
}
