<?php

namespace App\Modules\Quality\Services;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Quality\Models\BatchHold;
use Illuminate\Support\Facades\DB;

/**
 * Putting a batch on hold (status `quarantine`: stock of it cannot be issued, sold or moved — only scrapped) and releasing it, with the reason written down each time.
 * The change of status is a conditional update, so two people holding or releasing the same batch at once change it once and write one record; a batch that has expired is
 * left alone (it is not "on hold", it has run out).
 */
final class BatchHoldService
{
    public function hold(Batch $batch, string $reason, ?User $actor, ?int $inspectionId = null, ?int $ncrId = null): bool
    {
        return $this->move($batch, 'active', 'quarantine', 'hold', $reason, $actor, $inspectionId, $ncrId);
    }

    public function release(Batch $batch, string $reason, ?User $actor, ?int $inspectionId = null, ?int $ncrId = null): bool
    {
        return $this->move($batch, 'quarantine', 'active', 'release', $reason, $actor, $inspectionId, $ncrId);
    }

    /**
     * Is the batch on hold because of a hold this module made (as opposed to a status set some other way)? With `$lock` the reads are locking reads, which see what another
     * transaction has just committed (a plain read inside a transaction is a snapshot from when it began).
     */
    public function heldByQuality(Batch $batch, bool $lock = false): bool
    {
        $holds = BatchHold::query()->where('batch_id', $batch->getKey())->orderByDesc('id');
        $last = ($lock ? $holds->lockForUpdate() : $holds)->value('action');
        $status = Batch::query()->whereKey($batch->getKey())->when($lock, fn ($q) => $q->lockForUpdate())->value('status');

        return $status === 'quarantine' && $last === 'hold';
    }

    private function move(Batch $batch, string $from, string $to, string $action, string $reason, ?User $actor, ?int $inspectionId, ?int $ncrId): bool
    {
        return DB::transaction(function () use ($batch, $from, $to, $action, $reason, $actor, $inspectionId, $ncrId) {
            $moved = DB::table('batches')->where('id', $batch->getKey())->where('company_id', $batch->company_id)->where('status', $from)->update(['status' => $to, 'updated_at' => now()]);

            if ($moved !== 1) {
                return false;
            }

            $row = new BatchHold;
            $row->forceFill([
                'company_id' => $batch->company_id, 'batch_id' => $batch->getKey(), 'action' => $action, 'reason' => mb_substr($reason, 0, 300),
                'inspection_id' => $inspectionId, 'non_conformance_id' => $ncrId, 'by_user' => $actor?->getKey(), 'at' => now(),
            ])->save();

            activity('batches')->causedBy($actor)->performedOn($batch)->event($action === 'hold' ? 'held' : 'released')->withProperties(['reason' => $reason])->log($action === 'hold' ? 'held' : 'released');

            return true;
        });
    }
}
