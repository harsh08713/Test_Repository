<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Masters\Models\Item;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\GrnItem;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Puts the accepted goods of an **approved** goods receipt into stock — **exactly once, however many times it is asked for**.
 *
 * It is asked for when the receipt is approved (the DocumentApproved listener), by the retry command, and by anyone who calls `post()` again; every
 * one of those is safe: inside a transaction the receipt's row is locked and `posted_at` is checked again, so a second caller finds it done; and
 * `unique(company, 'grn', receipt, line, 'receipt')` on the stock ledger is the backstop that stops a duplicate movement even if both failed.
 * A failure rolls everything back (no half-posted receipt), is written on the receipt (`post_error`) where somebody sees it, and is retried by
 * `procurement:post-grns`.
 *
 * For a batch-tracked item the batch is found or made here, with the dates the receipt carried. Rejected quantities never reach stock.
 */
final class GrnPoster
{
    public function __construct(private readonly StockService $stock) {}

    /** Post the receipt's stock. Returns false when there was nothing to do (not approved, or already posted). */
    public function post(Grn|int $grn): bool
    {
        $id = $grn instanceof Grn ? (int) $grn->getKey() : $grn;

        $current = Grn::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($id)->first(['id', 'approval_status', 'posted_at']);

        if ($current === null || ! $current->isApproved() || $current->posted_at !== null) {
            return false;
        }

        try {
            return DB::transaction(fn () => $this->make($id), 3);
        } catch (Throwable $e) {
            Grn::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($id)->update(['post_attempted_at' => now(), 'post_error' => Str::limit(class_basename($e).': '.$e->getMessage(), 480, '…')]);

            throw $e;
        }
    }

    private function make(int $id): bool
    {
        $grn = Grn::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($id)->lockForUpdate()->firstOrFail();

        if ($grn->posted_at !== null || ! $grn->isApproved()) {
            return false;   // another caller got there first
        }

        foreach ($grn->items()->withoutGlobalScopes(Tenancy::SCOPES)->get() as $line) {
            if (bccomp((string) $line->base_accepted, '0', 3) <= 0) {
                continue;
            }

            $item = Item::withoutGlobalScopes(Tenancy::SCOPES)->with(['baseUnit', 'uoms.unit'])->findOrFail($line->item_id);
            $batch = $item->tracking === 'batch' ? $this->batchFor($grn, $item, $line) : null;

            $this->stock->receive($item, (int) $grn->location_id, (string) $line->base_accepted, (string) $line->unit_cost, $batch, 'grn', (int) $grn->getKey(), (int) $line->getKey());
        }

        $grn->forceFill(['posted_at' => now(), 'post_attempted_at' => now(), 'post_error' => null])->save();

        return true;
    }

    private function batchFor(Grn $grn, Item $item, GrnItem $line): Batch
    {
        $batch = Batch::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $grn->company_id)->where('item_id', $item->getKey())->where('batch_no', $line->batch_no)->first();

        if ($batch !== null) {
            if ($line->expiry_date !== null && $batch->expiry_date?->toDateString() !== $line->expiry_date->toDateString()) {
                throw new RuntimeException("Batch {$line->batch_no} of {$item->name} already exists with a different expiry date.");
            }

            return $batch;
        }

        $batch = new Batch;
        $batch->forceFill([
            'company_id' => (int) $grn->company_id, 'item_id' => $item->getKey(), 'batch_no' => $line->batch_no, 'lot_no' => $line->lot_no,
            'mfg_date' => $line->mfg_date, 'expiry_date' => $line->expiry_date, 'status' => 'active',
        ])->save();

        return $batch;
    }
}
