<?php

namespace App\Modules\Quality\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Quality\Models\InspectionResult;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inspections — the one door for their data and for what they do to a batch.
 *
 *  - **Starting** takes an approved, active plan, a branch the person works in and (for an item tracked by batch) the batch. The plan's checks are copied as the results to fill in. The batch is
 *    put **on hold** (quarantine) for the time being: nobody can issue or move it. A batch already on hold because of an earlier failed inspection is taken over, so a re-inspection can release
 *    it; one on hold for any other reason is refused. Only one inspection of a batch is open at a time.
 *  - **Recording** writes measurements and pass / fail checks; whether each *passed* is worked out here from the limits, never typed. Allowed while the inspection is open.
 *  - **Completing** needs every check recorded. Everything within limits → *accepted* and the hold is released; any check outside → *failed*, and the batch stays on hold.
 *  - **Deciding a failed inspection:** *reject* (raises a non-conformance report; the batch stays on hold) or *accept with a deviation* (a reason of at least 10 characters, by someone other
 *    than the inspector; the batch is released). **Cancelling** an open inspection releases its hold.
 *  - Every change takes the inspection's row lock first.
 */
final class InspectionService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly ItemLookup $items,
        private readonly BatchHoldService $holds,
        private readonly NonConformanceService $ncrs,
    ) {}

    /**
     * @param  array<string, mixed>  $input  inspection_plan_id, location_id, lot_quantity, batch_id, source_type (grn|production_order|manual), source_id, source_label
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function start(array $input, User $actor): Inspection
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();

        $plan = is_numeric($input['inspection_plan_id'] ?? null) ? InspectionPlan::query()->with('item')->find((int) $input['inspection_plan_id']) : null;

        if ($plan === null || ! $plan->isUsable()) {
            $errors['inspection_plan_id'] = 'Choose an approved, active inspection plan.';
        }

        $item = $plan === null ? null : $this->items->usable((int) $plan->item_id);

        if ($plan !== null && $item === null) {
            $errors['inspection_plan_id'] = 'The item of this plan is no longer an approved, active item.';
        }

        $locationId = is_numeric($input['location_id'] ?? null) ? (int) $input['location_id'] : 0;
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if (Location::query()->whereKey($locationId)->where('status', 'active')->doesntExist() || ($reach !== null && ! in_array($locationId, $reach, true))) {
            $errors['location_id'] = 'Choose a branch you work in.';
        }

        $lot = trim((string) ($input['lot_quantity'] ?? ''));

        if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $lot) !== 1 || bccomp($lot, '0', 3) <= 0) {
            $errors['lot_quantity'] = 'Enter the size of the lot: greater than zero, up to 3 decimals.';
        }

        $sourceType = (string) ($input['source_type'] ?? 'manual');
        $sourceType = in_array($sourceType, ['grn', 'production_order', 'manual'], true) ? $sourceType : 'manual';
        $sourceLabel = mb_substr(trim((string) ($input['source_label'] ?? '')), 0, 60);

        $batch = null;
        $batchId = is_numeric($input['batch_id'] ?? null) ? (int) $input['batch_id'] : 0;

        if ($item !== null && $item->tracking === 'batch') {
            $batch = $batchId === 0 ? null : Batch::query()->where('item_id', $item->getKey())->find($batchId);

            if ($batch === null) {
                $errors['batch_id'] = "{$item->name} is tracked by batch: choose the batch to inspect.";
            }
        } elseif ($batchId !== 0) {
            $errors['batch_id'] = 'This item is not tracked by batch.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($plan, $item, $locationId, $lot, $sourceType, $sourceLabel, $input, $batch, $actor) {
            $number = $this->numbers->next(DocumentType::Inspection, $locationId, now()->startOfDay());
            $held = false;

            if ($batch !== null) {
                $batch = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());

                if (Inspection::query()->where('batch_id', $batch->getKey())->whereIn('state', ['open', 'failed'])->lockForUpdate()->value('id') !== null) {
                    throw new FieldRuleException("Batch {$batch->batch_no} already has an inspection in progress or waiting for a decision.");
                }

                if ($batch->status === 'active') {
                    $held = $this->holds->hold($batch, 'Inspection '.$number.' started', $actor);
                } elseif ($this->holds->heldByQuality($batch, lock: true)) {
                    $held = true;   // a re-inspection takes over the hold a failed inspection left
                } else {
                    throw new FieldRuleException("Batch {$batch->batch_no} is expired or on hold for another reason, so it cannot be inspected here.");
                }
            }

            $inspection = new Inspection;
            $inspection->forceFill([
                'company_id' => (int) $this->tenant->activeCompanyId(), 'location_id' => $locationId, 'inspection_number' => $number, 'inspection_plan_id' => $plan->getKey(),
                'item_id' => $item->getKey(), 'item_name' => $item->name, 'stage' => $plan->stage, 'source_type' => $sourceType,
                'source_id' => $sourceType !== 'manual' && is_numeric($input['source_id'] ?? null) ? (int) $input['source_id'] : null, 'source_label' => $sourceLabel === '' ? null : $sourceLabel,
                'batch_id' => $batch?->getKey(), 'held_batch' => $held, 'lot_quantity' => bcadd($lot, '0', 3), 'inspector_id' => $actor->getKey(),
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();

            foreach ($plan->parameters()->get() as $p) {
                $result = new InspectionResult;
                $result->forceFill([
                    'company_id' => $inspection->company_id, 'inspection_id' => $inspection->getKey(), 'line_no' => $p->line_no, 'name' => $p->name, 'kind' => $p->kind, 'unit_text' => $p->unit_text,
                    'min_value' => $p->min_value, 'max_value' => $p->max_value, 'is_critical' => $p->is_critical,
                ])->save();
            }

            activity('inspections')->causedBy($actor)->performedOn($inspection)->event('started')->withProperties(['batch_held' => $held])->log('started');

            return $inspection->refresh();
        }, 3);
    }

    /**
     * Write measurements and checks. `$values` is result id => ['value' => '12.5'] for a measurement or ['check' => 'pass'|'fail'] for a check, with an optional 'note'. A blank value leaves
     * the check not yet recorded. Whether each passed is worked out here.
     *
     * @param  array<int|string, array<string, mixed>>  $values
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function record(Inspection $inspection, array $values, User $actor): Inspection
    {
        return DB::transaction(function () use ($inspection, $values) {
            $fresh = $this->lock($inspection);

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('Results can be recorded only while the inspection is in progress.');
            }

            $errors = [];
            $changes = [];

            foreach ($fresh->results()->get() as $result) {
                if (! array_key_exists($result->id, $values) && ! array_key_exists((string) $result->id, $values)) {
                    continue;
                }

                $in = (array) ($values[$result->id] ?? $values[(string) $result->id]);
                $note = trim((string) ($in['note'] ?? ''));

                if (mb_strlen($note) > 200) {
                    $errors["results.{$result->id}.note"] = 'A note can be at most 200 characters.';

                    continue;
                }

                $measured = null;
                $passed = null;

                if ($result->kind === 'measure') {
                    $value = trim((string) ($in['value'] ?? ''));

                    if ($value !== '') {
                        if (preg_match('/^-?\d{1,11}(\.\d{1,4})?$/', $value) !== 1) {
                            $errors["results.{$result->id}.value"] = 'Enter a number (up to 4 decimals).';

                            continue;
                        }

                        $measured = bcadd($value, '0', 4);
                        $passed = ($result->min_value === null || bccomp($measured, (string) $result->min_value, 4) >= 0) && ($result->max_value === null || bccomp($measured, (string) $result->max_value, 4) <= 0);
                    }
                } else {
                    $check = (string) ($in['check'] ?? '');

                    if (! in_array($check, ['', 'pass', 'fail'], true)) {
                        $errors["results.{$result->id}.check"] = 'Choose pass or fail.';

                        continue;
                    }

                    $passed = $check === '' ? null : $check === 'pass';
                }

                $changes[] = [$result, $measured, $passed, $note === '' ? null : $note];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($changes as [$result, $measured, $passed, $note]) {
                $result->forceFill(['measured_value' => $measured, 'passed' => $passed, 'note' => $note])->save();
            }

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function complete(Inspection $inspection, User $actor): Inspection
    {
        return DB::transaction(function () use ($inspection, $actor) {
            $fresh = $this->lock($inspection);

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('Only an inspection in progress can be completed.');
            }

            $results = $fresh->results()->get();

            if ($results->contains(fn (InspectionResult $r) => $r->passed === null)) {
                throw ValidationException::withMessages(['results' => 'Record every check before completing the inspection.']);
            }

            $failed = $results->contains(fn (InspectionResult $r) => $r->passed === false);

            $fresh->forceFill(['state' => $failed ? 'failed' : 'accepted', 'completed_at' => now(), 'inspector_id' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();

            if (! $failed && $fresh->held_batch) {
                $this->releaseBatch($fresh, 'Inspection '.$fresh->inspection_number.' passed', $actor);
            }

            activity('inspections')->causedBy($actor)->performedOn($fresh)->event($failed ? 'failed' : 'accepted')->log($failed ? 'failed' : 'accepted');

            return $fresh->refresh();
        });
    }

    /**
     * Decide a failed inspection: 'reject' or 'deviation' (accept anyway, with a reason).
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function decide(Inspection $inspection, string $decision, string $reason, User $actor): Inspection
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (! in_array($decision, ['reject', 'deviation'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose to reject the lot or to accept it with a deviation.']);
        }

        if ($decision === 'deviation' && (mb_strlen($reason) < 10 || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => 'Say why the failed lot is accepted anyway (10 to 500 characters).']);
        }

        if ($decision === 'reject' && mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'The reason can be at most 500 characters.']);
        }

        return DB::transaction(function () use ($inspection, $decision, $reason, $actor) {
            $fresh = $this->lock($inspection);

            if ($fresh->state !== 'failed') {
                throw new FieldRuleException('Only a failed inspection waiting for a decision can be decided.');
            }

            if ($decision === 'deviation' && (int) $fresh->inspector_id === (int) $actor->getKey()) {
                throw new FieldRuleException('The person who did the inspection cannot accept its failure: ask someone else.');
            }

            $fresh->forceFill([
                'state' => $decision === 'reject' ? 'rejected' : 'deviation', 'decided_by' => $actor->getKey(), 'decided_at' => now(),
                'decision_reason' => $reason === '' ? null : $reason, 'updated_by' => $actor->getKey(),
            ])->save();

            if ($decision === 'reject') {
                $this->ncrs->fromInspection($fresh->refresh(), $actor);
            } elseif ($fresh->held_batch) {
                $this->releaseBatch($fresh, 'Accepted with a deviation: '.$reason, $actor);
            }

            activity('inspections')->causedBy($actor)->performedOn($fresh)->event($decision === 'reject' ? 'rejected' : 'deviation')->withProperties(['reason' => $reason])->log($decision === 'reject' ? 'rejected' : 'deviation');

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function cancel(Inspection $inspection, string $reason, User $actor): Inspection
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Please say why the inspection is being cancelled (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($inspection, $reason, $actor) {
            $fresh = $this->lock($inspection);

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('Only an inspection in progress can be cancelled.');
            }

            $fresh->forceFill(['state' => 'cancelled', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_reason' => $reason, 'updated_by' => $actor->getKey()])->save();

            if ($fresh->held_batch) {
                $this->releaseBatch($fresh, 'Inspection '.$fresh->inspection_number.' cancelled', $actor);
            }

            activity('inspections')->causedBy($actor)->performedOn($fresh)->event('cancelled')->withProperties(['reason' => $reason])->log('cancelled');

            return $fresh->refresh();
        });
    }

    private function lock(Inspection $inspection): Inspection
    {
        return Inspection::query()->lockForUpdate()->findOrFail($inspection->getKey());
    }

    private function releaseBatch(Inspection $inspection, string $reason, User $actor): void
    {
        $batch = Batch::query()->find($inspection->batch_id);

        if ($batch !== null) {
            $this->holds->release($batch, $reason, $actor, (int) $inspection->getKey());
        }
    }
}
