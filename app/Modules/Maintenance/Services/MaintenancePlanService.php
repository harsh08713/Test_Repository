<?php

namespace App\Modules\Maintenance\Services;

use App\Models\User;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenancePlan;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preventive schedules — the one door for their data: this job, on this asset, every N days / weeks / months, with an optional checklist (one task per line). The first due date is
 * given and may not be in the past. Work orders are made from it by `MaintenanceOrderService::generateDue()`; when one is completed the schedule moves on from the day it was done.
 *
 * Input: ['code', 'asset_id', 'name', 'checklist', 'every_n', 'unit', 'lead_days', 'estimated_hours', 'next_due_on', 'remarks']
 */
final class MaintenancePlanService
{
    public const MAX_TASKS = 40;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): MaintenancePlan
    {
        $data = $this->validated($input, $actor, null);

        return DB::transaction(function () use ($data, $actor) {
            $plan = new MaintenancePlan;
            $plan->forceFill(['company_id' => (int) $this->tenant->activeCompanyId(), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()] + $data)->save();
            activity('maintenance_plans')->causedBy($actor)->performedOn($plan)->event('created')->log('created');

            return $plan->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(MaintenancePlan $plan, array $input, User $actor): MaintenancePlan
    {
        $data = $this->validated($input, $actor, $plan);

        return DB::transaction(function () use ($plan, $data, $actor) {
            $fresh = MaintenancePlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $fresh->forceFill(['updated_by' => $actor->getKey()] + $data)->save();
            activity('maintenance_plans')->causedBy($actor)->performedOn($fresh)->event('updated')->log('updated');

            return $fresh->refresh();
        });
    }

    public function setStatus(MaintenancePlan $plan, bool $active, User $actor): MaintenancePlan
    {
        $fresh = MaintenancePlan::query()->lockForUpdate()->findOrFail($plan->getKey());
        $fresh->forceFill(['status' => $active ? 'active' : 'inactive', 'updated_by' => $actor->getKey()])->save();
        activity('maintenance_plans')->causedBy($actor)->performedOn($fresh)->event($active ? 'activated' : 'deactivated')->log($active ? 'activated' : 'deactivated');

        return $fresh->refresh();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $input, User $actor, ?MaintenancePlan $editing): array
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();

        $code = strtoupper(trim((string) ($input['code'] ?? '')));

        if (preg_match('/^[A-Z0-9][A-Z0-9\-_\/]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'Give the schedule a code of 2 to 30 letters, digits, dashes or slashes (for example PM-PRESS-01).';
        } elseif (MaintenancePlan::query()->where('code', $code)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing?->getKey()))->exists()) {
            $errors['code'] = 'There is already a schedule with that code.';
        }

        $asset = is_numeric($input['asset_id'] ?? null) ? Asset::query()->where('status', 'active')->find((int) $input['asset_id']) : null;
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if ($asset === null || ($reach !== null && ! in_array((int) $asset->location_id, $reach, true))) {
            $errors['asset_id'] = 'Choose an active asset at a branch you work in.';
        }

        $name = trim((string) ($input['name'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['name'] = 'Name the job (2 to 150 characters).';
        }

        $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), preg_split('/\R/', (string) ($input['checklist'] ?? '')) ?: []), fn ($l) => $l !== ''));

        if (count($lines) > self::MAX_TASKS || collect($lines)->contains(fn ($l) => mb_strlen($l) > 200)) {
            $errors['checklist'] = 'A checklist can have at most '.self::MAX_TASKS.' lines of up to 200 characters.';
        }

        $every = (string) ($input['every_n'] ?? '');

        if (preg_match('/^\d{1,3}$/', $every) !== 1 || (int) $every < 1) {
            $errors['every_n'] = 'Enter how often: a whole number from 1 to 999.';
        }

        $unit = (string) ($input['unit'] ?? '');

        if (! array_key_exists($unit, MaintenancePlan::UNITS)) {
            $errors['unit'] = 'Choose days, weeks or months.';
        }

        $lead = trim((string) ($input['lead_days'] ?? '')) === '' ? '3' : trim((string) $input['lead_days']);

        if (preg_match('/^\d{1,2}$/', $lead) !== 1 || (int) $lead > 60) {
            $errors['lead_days'] = 'Enter how many days ahead to make the work order: 0 to 60.';
        }

        $hours = trim((string) ($input['estimated_hours'] ?? ''));

        if ($hours !== '' && (preg_match('/^\d{1,3}(\.\d)?$/', $hours) !== 1 || bccomp($hours, '0', 1) <= 0)) {
            $errors['estimated_hours'] = 'Enter the hours it takes (up to one decimal), or leave it empty.';
        }

        $due = trim((string) ($input['next_due_on'] ?? ''));

        if (($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $due)) === false || $d->format('Y-m-d') !== $due) {
            $errors['next_due_on'] = 'Enter the date it first falls due.';
        } elseif ($due < now()->toDateString() && ($editing === null || $editing->next_due_on->toDateString() !== $due)) {
            $errors['next_due_on'] = 'The due date cannot be in the past.';
        }

        $remarks = trim((string) ($input['remarks'] ?? ''));

        if (mb_strlen($remarks) > 1000) {
            $errors['remarks'] = 'Remarks can be at most 1000 characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'code' => $code, 'asset_id' => $asset->getKey(), 'name' => $name, 'checklist' => $lines === [] ? null : implode("\n", $lines), 'every_n' => (int) $every, 'unit' => $unit,
            'lead_days' => (int) $lead, 'estimated_hours' => $hours === '' ? null : bcadd($hours, '0', 1), 'next_due_on' => $due, 'remarks' => $remarks === '' ? null : $remarks,
        ];
    }
}
