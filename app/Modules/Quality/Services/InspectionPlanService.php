<?php

namespace App\Modules\Quality\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Quality\Models\InspectionPlanParameter;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes and changes inspection plans — the one door for their data. A plan is for an **approved, active item** at one stage and lists what to check: a measurement between limits
 * (either limit may be left open, not both) or a pass / fail check, each optionally *critical*. A plan that is waiting for approval or approved never changes (the model refuses):
 * a change is a new plan, and the old one is made inactive.
 *
 * Input: ['code', 'name', 'item_id', 'stage', 'remarks', 'items' => [['name', 'kind', 'unit_text', 'min_value', 'max_value', 'is_critical', 'method'], …]]
 */
final class InspectionPlanService
{
    public const MAX_LINES = 40;

    public function __construct(private readonly TenantContext $tenant, private readonly ItemLookup $items) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): InspectionPlan
    {
        $data = $this->validated($input, null);

        return DB::transaction(function () use ($data, $actor) {
            $plan = new InspectionPlan;
            $plan->forceFill(['company_id' => (int) $this->tenant->activeCompanyId(), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()] + $data['header']);
            $plan->save();
            $this->writeLines($plan, $data['lines']);

            activity('inspection_plans')->causedBy($actor)->performedOn($plan)->event('created')->log('created');

            return $plan->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(InspectionPlan $plan, array $input, User $actor): InspectionPlan
    {
        return DB::transaction(function () use ($plan, $input, $actor) {
            $fresh = InspectionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if (! $fresh->isEditableByWorkflow()) {
                throw new FieldRuleException('An inspection plan that is waiting for approval or approved cannot be changed: make a new one.');
            }

            $data = $this->validated($input, $fresh);
            $fresh->parameters()->get()->each->forceDelete();
            $fresh->forceFill(['updated_by' => $actor->getKey()] + $data['header'])->save();
            $this->writeLines($fresh, $data['lines']);

            activity('inspection_plans')->causedBy($actor)->performedOn($fresh)->event('updated')->log('updated');

            return $fresh->refresh();
        });
    }

    /** @throws FieldRuleException */
    public function setStatus(InspectionPlan $plan, bool $active, User $actor): InspectionPlan
    {
        $fresh = InspectionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

        if ($fresh->isPendingApproval()) {
            throw new FieldRuleException('This plan is waiting for approval: wait for the decision first.');
        }

        $fresh->forceFill(['status' => $active ? 'active' : 'inactive', 'updated_by' => $actor->getKey()])->save();
        activity('inspection_plans')->causedBy($actor)->performedOn($fresh)->event($active ? 'activated' : 'deactivated')->log($active ? 'activated' : 'deactivated');

        return $fresh->refresh();
    }

    /** Why a plan cannot be sent for approval right now, or null. */
    public function problemBeforeSubmit(InspectionPlan $plan): ?string
    {
        return $this->items->usable((int) $plan->item_id) === null ? 'The item is no longer an approved, active item.' : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?InspectionPlan $editing): array
    {
        $errors = [];
        $code = strtoupper(trim((string) ($input['code'] ?? '')));

        if (preg_match('/^[A-Z0-9][A-Z0-9\-_\/]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'Give the plan a code of 2 to 30 letters, digits, dashes or slashes (for example QP-BOLT-IN).';
        } elseif (InspectionPlan::query()->where('code', $code)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing?->getKey()))->exists()) {
            $errors['code'] = 'There is already a plan with that code.';
        }

        $name = trim((string) ($input['name'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['name'] = 'Name the plan (2 to 150 characters).';
        }

        $item = is_numeric($input['item_id'] ?? null) ? $this->items->usable((int) $input['item_id']) : null;

        if ($item === null) {
            $errors['item_id'] = 'Choose an approved, active item.';
        }

        $stage = (string) ($input['stage'] ?? '');

        if (! array_key_exists($stage, InspectionPlan::STAGES)) {
            $errors['stage'] = 'Choose the stage: incoming goods, in process or final.';
        }

        $remarks = trim((string) ($input['remarks'] ?? ''));

        if (mb_strlen($remarks) > 1000) {
            $errors['remarks'] = 'Remarks can be at most 1000 characters.';
        }

        $rows = array_values((array) ($input['items'] ?? []));
        $lines = [];
        $names = [];

        if ($rows === []) {
            $errors['items'] = 'Add at least one thing to check.';
        } elseif (count($rows) > self::MAX_LINES) {
            $errors['items'] = 'A plan can have at most '.self::MAX_LINES.' checks.';
        }

        foreach (array_slice($rows, 0, self::MAX_LINES) as $i => $row) {
            $row = (array) $row;
            $key = "items.$i";
            $lineName = trim((string) ($row['name'] ?? ''));
            $kind = (string) ($row['kind'] ?? 'measure');
            $unit = trim((string) ($row['unit_text'] ?? ''));
            $min = trim((string) ($row['min_value'] ?? ''));
            $max = trim((string) ($row['max_value'] ?? ''));
            $method = trim((string) ($row['method'] ?? ''));

            if ($lineName === '' || mb_strlen($lineName) > 120) {
                $errors["$key.name"] = 'Say what is checked (up to 120 characters).';

                continue;
            }

            if (isset($names[mb_strtolower($lineName)])) {
                $errors["$key.name"] = 'This is already checked on the plan.';

                continue;
            }

            $names[mb_strtolower($lineName)] = true;

            if (! in_array($kind, ['measure', 'check'], true)) {
                $errors["$key.kind"] = 'Choose a measurement or a pass / fail check.';

                continue;
            }

            if (mb_strlen($unit) > 20 || mb_strlen($method) > 200) {
                $errors["$key.name"] = 'The unit can be at most 20 characters and the method 200.';

                continue;
            }

            if ($kind === 'measure') {
                foreach (['min_value' => $min, 'max_value' => $max] as $field => $value) {
                    if ($value !== '' && preg_match('/^-?\d{1,11}(\.\d{1,4})?$/', $value) !== 1) {
                        $errors["$key.$field"] = 'Enter a number (up to 4 decimals), or leave it empty.';
                    }
                }

                if (isset($errors["$key.min_value"]) || isset($errors["$key.max_value"])) {
                    continue;
                }

                if ($min === '' && $max === '') {
                    $errors["$key.min_value"] = 'Give a lowest or a highest allowed value (or both).';

                    continue;
                }

                if ($min !== '' && $max !== '' && bccomp($min, $max, 4) > 0) {
                    $errors["$key.max_value"] = 'The highest value cannot be below the lowest.';

                    continue;
                }
            } else {
                $min = $max = '';
            }

            $lines[] = [
                'name' => $lineName, 'kind' => $kind, 'unit_text' => $unit === '' ? null : $unit, 'min_value' => $min === '' ? null : bcadd($min, '0', 4),
                'max_value' => $max === '' ? null : bcadd($max, '0', 4), 'is_critical' => filter_var($row['is_critical'] ?? false, FILTER_VALIDATE_BOOLEAN), 'method' => $method === '' ? null : $method,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['header' => ['code' => $code, 'name' => $name, 'item_id' => $item->getKey(), 'stage' => $stage, 'remarks' => $remarks === '' ? null : $remarks], 'lines' => $lines];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(InspectionPlan $plan, array $lines): void
    {
        foreach ($lines as $n => $line) {
            $row = new InspectionPlanParameter;
            $row->forceFill(['company_id' => $plan->company_id, 'inspection_plan_id' => $plan->getKey(), 'line_no' => $n + 1] + $line)->save();
        }
    }
}
