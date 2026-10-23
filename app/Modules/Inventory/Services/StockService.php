<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Finance\Models\ItemCostEntry;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one door for stock: every change of quantity is a row of the stock ledger (StockMovement), and the balance it leaves is kept in step in
 * the same transaction. Nothing else writes either table.
 *
 *  - Quantities are in the item's **base unit**; callers convert (UomConverter) before they come here.
 *  - **Moving average.** Each balance row (branch × item × batch) is its own pool: a receipt adds quantity and value, an issue / scrap /
 *    adjustment-out / transfer-out takes value out at that pool's average, and when the last unit leaves the whole remaining value leaves with
 *    it, so no rounding residue is left behind. A transfer arrives at exactly the value that left.
 *  - **No negative stock**: asking for more than is on hand is refused (and the database holds a CHECK as the backstop).
 *  - **Tracking.** An item tracked by batch needs a batch on every movement; one that is not must have none. A batch that has expired, or is in
 *    quarantine, cannot be issued or transferred — it can be scrapped.
 *  - **Once per document line.** A movement that names its source (`receive()` from a goods receipt) is made once: asking again returns the first
 *    one, and `unique(company, source type, source id, line, movement type)` is the backstop.
 *  - A person's own entries are checked against the branches they work in; a document posting (no person) is checked by the document.
 */
final class StockService
{
    /** A deadlock is retried this many times when the movement is the outermost transaction (a nested one cannot retry). */
    private const ATTEMPTS = 3;

    public function __construct(private readonly TenantContext $tenant) {}

    /** Post a receipt (goods received, from a document line). Idempotent per source line. */
    public function receive(Item $item, int $locationId, string $quantity, string $unitCost, ?Batch $batch, string $sourceType, int $sourceId, int $sourceLineId, ?User $actor = null): StockMovement
    {
        return $this->apply($item, $locationId, $batch, StockMovement::RECEIPT, $this->positive($quantity), $unitCost, null, $actor, null, [$sourceType, $sourceId, $sourceLineId]);
    }

    /** Post an issue (material used by a document line, e.g. a production order). Idempotent per source line. Takes value out at the pool's average. */
    public function issue(Item $item, int $locationId, string $quantity, ?Batch $batch, string $sourceType, int $sourceId, int $sourceLineId, ?User $actor = null): StockMovement
    {
        return $this->apply($item, $locationId, $batch, StockMovement::ISSUE, '-'.$this->positive($quantity), null, null, $actor, null, [$sourceType, $sourceId, $sourceLineId]);
    }

    /** Stock found that the books did not have. The cost is the one given, else the pool's average, else the item's standard cost. */
    public function adjustIn(Item $item, int $locationId, string $quantity, ?string $unitCost, ?Batch $batch, string $reason, User $actor): StockMovement
    {
        $reason = $this->reason($reason);
        $cost = $unitCost ?? $this->fallbackCost($item, $locationId, $batch);

        return $this->apply($item, $locationId, $batch, StockMovement::ADJUSTMENT_IN, $this->positive($quantity), $cost, null, $actor, $reason);
    }

    /** Stock lost or missing at a count. */
    public function adjustOut(Item $item, int $locationId, string $quantity, ?Batch $batch, string $reason, User $actor): StockMovement
    {
        return $this->apply($item, $locationId, $batch, StockMovement::ADJUSTMENT_OUT, '-'.$this->positive($quantity), null, null, $actor, $this->reason($reason));
    }

    /** Stock written off (damaged, expired…). Allowed for an expired batch: that is what scrapping is for. */
    public function scrap(Item $item, int $locationId, string $quantity, ?Batch $batch, string $reason, User $actor): StockMovement
    {
        return $this->apply($item, $locationId, $batch, StockMovement::SCRAP, '-'.$this->positive($quantity), null, null, $actor, $this->reason($reason));
    }

    /**
     * Move stock between two branches; it arrives at the value that left.
     *
     * @return array{out: StockMovement, in: StockMovement}
     */
    public function transfer(Item $item, int $fromLocationId, int $toLocationId, string $quantity, ?Batch $batch, string $reason, User $actor): array
    {
        if ($fromLocationId === $toLocationId) {
            throw new StockException('Choose a different branch to move the stock to.');
        }

        $reason = $this->reason($reason);

        return DB::transaction(function () use ($item, $fromLocationId, $toLocationId, $quantity, $batch, $reason, $actor) {
            $out = $this->apply($item, $fromLocationId, $batch, StockMovement::TRANSFER_OUT, '-'.$this->positive($quantity), null, null, $actor, $reason);
            $in = $this->apply($item, $toLocationId, $batch, StockMovement::TRANSFER_IN, $this->positive($quantity), (string) $out->unit_cost, ltrim((string) $out->value, '-'), $actor, $reason);

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Make every balance of the company again from its ledger (a check, or a repair after a bug). Returns the number of rows that were wrong.
     */
    public function rebuildBalances(int $companyId): int
    {
        return DB::transaction(function () use ($companyId) {
            $truth = DB::table('stock_movements')->where('company_id', $companyId)
                ->selectRaw('location_id, item_id, coalesce(batch_id, 0) as batch_key, max(batch_id) as batch_id, sum(quantity) as quantity, sum(value) as value')
                ->groupBy('location_id', 'item_id', DB::raw('coalesce(batch_id, 0)'))->get()
                ->keyBy(fn (object $r) => "{$r->location_id}:{$r->item_id}:{$r->batch_key}");

            $fixed = 0;

            foreach (DB::table('stock_balances')->where('company_id', $companyId)->lockForUpdate()->get() as $row) {
                $key = "{$row->location_id}:{$row->item_id}:{$row->batch_key}";
                $want = $truth->pull($key);
                $quantity = $want === null ? '0.000' : bcadd((string) $want->quantity, '0', 3);
                $value = $want === null ? '0.00' : bcadd((string) $want->value, '0', 2);

                if (bccomp((string) $row->quantity, $quantity, 3) !== 0 || bccomp((string) $row->value, $value, 2) !== 0) {
                    DB::table('stock_balances')->where('id', $row->id)->update(['quantity' => $quantity, 'value' => $value]);
                    $fixed++;
                }
            }

            foreach ($truth as $want) {   // in the ledger, missing from the balances
                DB::table('stock_balances')->insert([
                    'company_id' => $companyId, 'location_id' => $want->location_id, 'item_id' => $want->item_id, 'batch_key' => $want->batch_key,
                    'batch_id' => $want->batch_id, 'quantity' => bcadd((string) $want->quantity, '0', 3), 'value' => bcadd((string) $want->value, '0', 2),
                ]);
                $fixed++;
            }

            return $fixed;
        });
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /**
     * @param  string  $signedQuantity  + in, - out
     * @param  string|null  $unitCost  required for an increase unless $fixedValue says what arrived
     * @param  string|null  $fixedValue  the value of an increase when it is known (a transfer arrives at the value that left)
     * @param  array{0: string, 1: int, 2: int}|null  $source
     *
     * @throws StockException
     */
    private function apply(Item $item, int $locationId, ?Batch $batch, string $type, string $signedQuantity, ?string $unitCost, ?string $fixedValue, ?User $actor, ?string $reason, ?array $source = null): StockMovement
    {
        $companyId = (int) $item->company_id;
        $out = bccomp($signedQuantity, '0', 3) < 0;
        $quantity = ltrim($signedQuantity, '-');

        $this->check($item, $locationId, $batch, $type, $actor);

        return DB::transaction(function () use ($item, $companyId, $locationId, $batch, $type, $signedQuantity, $quantity, $out, $unitCost, $fixedValue, $actor, $reason, $source) {
            if ($source !== null && ($again = $this->existing($companyId, $type, $source)) !== null) {
                return $again;
            }

            $batchKey = $batch?->getKey() ?? 0;
            $key = ['company_id' => $companyId, 'location_id' => $locationId, 'item_id' => $item->getKey(), 'batch_key' => $batchKey];

            // Take the balance row's exclusive lock straight away: INSERT … ON DUPLICATE KEY UPDATE takes it whether the row is new or not.
            // An INSERT IGNORE would take only a *shared* lock on an existing row, the FOR UPDATE below would have to upgrade it, and two
            // requests doing that at once deadlock (found by tests/Concurrency/StockConcurrencyTest).
            DB::table('stock_balances')->upsert([[...$key, 'batch_id' => $batch?->getKey(), 'quantity' => 0, 'value' => 0]], ['company_id', 'location_id', 'item_id', 'batch_key'], ['batch_id']);
            $balance = DB::table('stock_balances')->where($key)->lockForUpdate()->first();

            if ($out) {
                if (bccomp($quantity, (string) $balance->quantity, 3) > 0) {
                    throw new StockException('Only '.$this->plain((string) $balance->quantity)." of {$item->name} is on hand there; you asked for ".$this->plain($quantity).'.');
                }

                $whole = bccomp($quantity, (string) $balance->quantity, 3) === 0;
                $outValue = $whole ? bcadd((string) $balance->value, '0', 2) : $this->round2(bcdiv(bcmul((string) $balance->value, $quantity, 6), (string) $balance->quantity, 8));
                $cost = bccomp((string) $balance->quantity, '0', 3) > 0 ? $this->round4(bcdiv((string) $balance->value, (string) $balance->quantity, 10)) : '0.0000';
                $value = '-'.$outValue;
            } else {
                $cost = $this->cost($unitCost);
                $value = $fixedValue !== null ? bcadd($fixedValue, '0', 2) : $this->round2(bcmul($quantity, $cost, 7));
            }

            $movement = new StockMovement;
            $movement->forceFill([
                'company_id' => $companyId, 'location_id' => $locationId, 'item_id' => $item->getKey(), 'batch_id' => $batch?->getKey(), 'movement_type' => $type,
                'quantity' => $signedQuantity, 'unit_cost' => $cost, 'value' => $value,
                'source_type' => $source[0] ?? null, 'source_id' => $source[1] ?? null, 'source_line_id' => $source[2] ?? null,
                'reason' => $reason, 'created_by' => $actor?->getKey(), 'moved_at' => now(),
            ]);

            try {
                $movement->save();
            } catch (UniqueConstraintViolationException) {
                if ($source !== null && ($again = $this->existing($companyId, $type, $source)) !== null) {
                    return $again;
                }

                throw new StockException('That stock entry was already made.');
            }

            $newQuantity = bcadd((string) $balance->quantity, $signedQuantity, 3);
            $newValue = bcadd((string) $balance->value, $value, 2);
            DB::table('stock_balances')->where('id', $balance->id)->update(['quantity' => $newQuantity, 'value' => $newValue]);

            // A receipt moves the moving average: write down what it became.
            if ($type === StockMovement::RECEIPT && bccomp($newQuantity, '0', 3) > 0) {
                $before = bccomp((string) $balance->quantity, '0', 3) > 0 ? bcadd(bcdiv((string) $balance->value, (string) $balance->quantity, 10), '0.00005', 4) : null;
                ItemCostEntry::record($companyId, (int) $item->getKey(), $locationId, 'moving_average', bcadd(bcdiv($newValue, $newQuantity, 10), '0.00005', 4), $before,
                    $movement->source_type, $movement->source_id, $actor?->getKey());
            }

            return $movement;
        }, self::ATTEMPTS);
    }

    /** @throws StockException */
    private function check(Item $item, int $locationId, ?Batch $batch, string $type, ?User $actor): void
    {
        if ($actor !== null) {
            $reach = $this->tenant->accessibleLocationIdsFor($actor, (int) $item->company_id);

            if ($reach !== null && ! in_array($locationId, $reach, true)) {
                throw new StockException('That is a branch you do not work in.');
            }
        }

        if ($item->tracking === 'batch' && $batch === null) {
            throw new StockException("{$item->name} is tracked by batch: choose the batch.");
        }

        if ($item->tracking !== 'batch' && $batch !== null) {
            throw new StockException("{$item->name} is not tracked by batch, so it has no batches.");
        }

        if ($batch !== null) {
            if ((int) $batch->item_id !== (int) $item->getKey() || (int) $batch->company_id !== (int) $item->company_id) {
                throw new StockException('That batch is not a batch of this item.');
            }

            $blocked = in_array($type, [StockMovement::ISSUE, StockMovement::TRANSFER_OUT, StockMovement::PURCHASE_RETURN], true);

            if ($blocked && ! $batch->isUsable()) {
                throw new StockException("Batch {$batch->batch_no} of {$item->name} has expired or is in quarantine, so it cannot be issued or moved. Scrap it instead.");
            }
        }

        if (! DB::table('locations')->where('id', $locationId)->where('company_id', $item->company_id)->whereNull('deleted_at')->exists()) {
            throw new StockException('That branch does not belong to this company.');
        }
    }

    /** @param  array{0: string, 1: int, 2: int}  $source */
    private function existing(int $companyId, string $type, array $source): ?StockMovement
    {
        return StockMovement::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('movement_type', $type)
            ->where('source_type', $source[0])->where('source_id', $source[1])->where('source_line_id', $source[2])->first();
    }

    private function fallbackCost(Item $item, int $locationId, ?Batch $batch): string
    {
        $row = DB::table('stock_balances')->where(['company_id' => $item->company_id, 'location_id' => $locationId, 'item_id' => $item->getKey(), 'batch_key' => $batch?->getKey() ?? 0])->first();

        if ($row !== null && bccomp((string) $row->quantity, '0', 3) > 0) {
            return $this->round4(bcdiv((string) $row->value, (string) $row->quantity, 10));
        }

        if ($item->standard_cost !== null) {
            return bcadd((string) $item->standard_cost, '0', 4);
        }

        throw new StockException("Enter a cost for {$item->name}: there is no stock to average and the item has no standard cost.");
    }

    /** @throws StockException */
    private function positive(string $quantity): string
    {
        $quantity = trim($quantity);

        if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1 || bccomp($quantity, '0', 3) <= 0) {
            throw new StockException('Enter a quantity greater than zero, with up to 3 decimals.');
        }

        return bcadd($quantity, '0', 3);
    }

    /** @throws StockException */
    private function cost(?string $cost): string
    {
        $cost = trim((string) $cost);

        if (preg_match('/^\d{1,14}(\.\d{1,4})?$/', $cost) !== 1) {
            throw new StockException('Enter a cost per unit (a number, up to 4 decimals).');
        }

        return bcadd($cost, '0', 4);
    }

    /** @throws StockException */
    private function reason(string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new StockException('Please say why (3 to 500 characters).');
        }

        return $reason;
    }

    private function round2(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }

    private function round4(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }

    private function plain(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }
}
