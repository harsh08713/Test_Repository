<?php

namespace App\Modules\Quality\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformance;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Non-conformance reports — the one door. One is raised by hand, or by a **rejected inspection** (with the failed checks in its words, critical if a critical check failed, and the lot's
 * quantity). A person then decides what is to be done with the goods (*disposition*); *use as is* is a deviation, so it needs `inspection.deviate` and releases the lot's hold. It can be
 * linked to the **loss incident** and the **corrective action** it led to (the same company's, and the action must belong to the incident), and is **closed** once decided — a
 * critical one only with a corrective action linked. A closed report is a record.
 */
final class NonConformanceService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly ItemLookup $items,
        private readonly BatchHoldService $holds,
    ) {}

    /**
     * @param  array<string, mixed>  $input  location_id, item_id, batch_id, quantity_affected, severity, description
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): NonConformance
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();

        $locationId = is_numeric($input['location_id'] ?? null) ? (int) $input['location_id'] : 0;
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if (Location::query()->whereKey($locationId)->where('status', 'active')->doesntExist() || ($reach !== null && ! in_array($locationId, $reach, true))) {
            $errors['location_id'] = 'Choose a branch you work in.';
        }

        $item = is_numeric($input['item_id'] ?? null) ? $this->items->usable((int) $input['item_id']) : null;

        if ($item === null) {
            $errors['item_id'] = 'Choose an approved, active item.';
        }

        $batch = null;
        $batchId = is_numeric($input['batch_id'] ?? null) ? (int) $input['batch_id'] : 0;

        if ($batchId !== 0) {
            $batch = $item === null ? null : Batch::query()->where('item_id', $item->getKey())->find($batchId);

            if ($batch === null) {
                $errors['batch_id'] = 'That is not a batch of this item.';
            }
        }

        $quantity = trim((string) ($input['quantity_affected'] ?? '')) === '' ? '0' : trim((string) $input['quantity_affected']);

        if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1) {
            $errors['quantity_affected'] = 'Enter how much is affected (a number, up to 3 decimals), or leave it empty.';
        }

        $severity = (string) ($input['severity'] ?? '');

        if (! array_key_exists($severity, NonConformance::SEVERITIES)) {
            $errors['severity'] = 'Choose minor, major or critical.';
        }

        $description = trim((string) ($input['description'] ?? ''));

        if (mb_strlen($description) < 10 || mb_strlen($description) > 2000) {
            $errors['description'] = 'Describe what did not meet its requirement (10 to 2000 characters).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->make($locationId, $item->getKey(), $item->name, $batch?->getKey(), bcadd($quantity, '0', 3), $severity, $description, null, $actor);
    }

    /** Raised by InspectionService when a failed inspection is rejected. */
    public function fromInspection(Inspection $inspection, User $actor): NonConformance
    {
        $failed = $inspection->results()->where('passed', false)->get();
        $critical = $failed->contains(fn ($r) => $r->is_critical);
        $words = $failed->map(function ($r) {
            $trim = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
            $limits = $r->kind === 'measure' ? ' (allowed '.($r->min_value !== null ? 'from '.$trim($r->min_value) : '').($r->min_value !== null && $r->max_value !== null ? ' ' : '').($r->max_value !== null ? 'up to '.$trim($r->max_value) : '').($r->unit_text ? ' '.$r->unit_text : '').')' : '';

            return $r->name.($r->kind === 'measure' ? ': measured '.$trim($r->measured_value).$limits : ': failed').($r->is_critical ? ' [critical]' : '');
        })->implode('; ');

        return $this->make((int) $inspection->location_id, (int) $inspection->item_id, $inspection->item_name, $inspection->batch_id, (string) $inspection->lot_quantity, $critical ? 'critical' : 'major',
            'Rejected at inspection '.$inspection->inspection_number.'. '.$words.'.', (int) $inspection->getKey(), $actor);
    }

    /**
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function disposition(NonConformance $ncr, string $disposition, string $note, User $actor): NonConformance
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if (! array_key_exists($disposition, NonConformance::DISPOSITIONS)) {
            throw ValidationException::withMessages(['disposition' => 'Choose what is to be done with the goods.']);
        }

        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Say how it is to be done, or why (3 to 500 characters).']);
        }

        if ($disposition === 'use_as_is' && ! $actor->can('inspection.deviate')) {
            throw new FieldRuleException('Using goods as they are is a deviation: it needs the right to accept a failed lot.');
        }

        return DB::transaction(function () use ($ncr, $disposition, $note, $actor) {
            $fresh = NonConformance::query()->lockForUpdate()->findOrFail($ncr->getKey());

            if ($fresh->state === 'closed') {
                throw new FieldRuleException('A closed report cannot be decided again.');
            }

            $fresh->forceFill(['state' => 'decided', 'disposition' => $disposition, 'disposition_note' => $note, 'dispositioned_by' => $actor->getKey(), 'dispositioned_at' => now(), 'updated_by' => $actor->getKey()])->save();

            if ($disposition === 'use_as_is' && $fresh->batch_id !== null && ($batch = Batch::query()->find($fresh->batch_id)) !== null) {
                $this->holds->release($batch, 'Used as is under '.$fresh->ncr_number.': '.$note, $actor, $fresh->inspection_id, (int) $fresh->getKey());
            }

            activity('non_conformances')->causedBy($actor)->performedOn($fresh)->event('dispositioned')->withProperties(['disposition' => $disposition])->log('dispositioned');

            return $fresh->refresh();
        });
    }

    /**
     * Link the loss incident and/or the corrective action. A corrective action brings its incident with it; if both are given they must belong together.
     *
     * @throws FieldRuleException
     */
    public function link(NonConformance $ncr, ?int $lossIncidentId, ?int $correctiveActionId, User $actor): NonConformance
    {
        $action = $correctiveActionId === null ? null : CorrectiveAction::query()->find($correctiveActionId);
        $incidentId = $lossIncidentId ?? $action?->loss_incident_id;

        if ($correctiveActionId !== null && $action === null) {
            throw new FieldRuleException('That corrective action was not found.');
        }

        if ($incidentId !== null && LossIncident::query()->whereKey($incidentId)->doesntExist()) {
            throw new FieldRuleException('That loss incident was not found.');
        }

        if ($action !== null && $lossIncidentId !== null && (int) $action->loss_incident_id !== $lossIncidentId) {
            throw new FieldRuleException('That corrective action belongs to a different loss incident.');
        }

        return DB::transaction(function () use ($ncr, $incidentId, $action, $actor) {
            $fresh = NonConformance::query()->lockForUpdate()->findOrFail($ncr->getKey());

            if ($fresh->state === 'closed') {
                throw new FieldRuleException('A closed report cannot be changed.');
            }

            $fresh->forceFill(['loss_incident_id' => $incidentId, 'corrective_action_id' => $action?->getKey(), 'updated_by' => $actor->getKey()])->save();
            activity('non_conformances')->causedBy($actor)->performedOn($fresh)->event('linked')->withProperties(['loss_incident_id' => $incidentId, 'corrective_action_id' => $action?->getKey()])->log('linked');

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function close(NonConformance $ncr, string $note, User $actor): NonConformance
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Say how it was resolved (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($ncr, $note, $actor) {
            $fresh = NonConformance::query()->lockForUpdate()->findOrFail($ncr->getKey());

            if ($fresh->state !== 'decided') {
                throw new FieldRuleException('Decide what is to be done with the goods before closing the report.');
            }

            if ($fresh->severity === 'critical' && $fresh->corrective_action_id === null) {
                throw new FieldRuleException('A critical non-conformance needs a corrective action linked before it can be closed.');
            }

            $fresh->forceFill(['state' => 'closed', 'closed_by' => $actor->getKey(), 'closed_at' => now(), 'closing_note' => $note, 'updated_by' => $actor->getKey()])->save();
            activity('non_conformances')->causedBy($actor)->performedOn($fresh)->event('closed')->log('closed');

            return $fresh->refresh();
        });
    }

    private function make(int $locationId, int $itemId, string $itemName, ?int $batchId, string $quantity, string $severity, string $description, ?int $inspectionId, User $actor): NonConformance
    {
        return DB::transaction(function () use ($locationId, $itemId, $itemName, $batchId, $quantity, $severity, $description, $inspectionId, $actor) {
            $number = $this->numbers->next(DocumentType::NonConformance, $locationId, now()->startOfDay());

            $ncr = new NonConformance;
            $ncr->forceFill([
                'company_id' => (int) $this->tenant->activeCompanyId(), 'location_id' => $locationId, 'ncr_number' => $number, 'inspection_id' => $inspectionId, 'item_id' => $itemId,
                'item_name' => $itemName, 'batch_id' => $batchId, 'quantity_affected' => $quantity, 'severity' => $severity, 'description' => $description,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();

            activity('non_conformances')->causedBy($actor)->performedOn($ncr)->event('created')->withProperties(['severity' => $severity])->log('created');

            return $ncr->refresh();
        }, 3);
    }
}
