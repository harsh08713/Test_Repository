<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Makes and changes budgets — the one door for their data. A budget is for one **active cost centre**, one **month**, optionally one **loss category**
 * of an industry template the company uses (none = every category together), and an amount in rupees (not negative). There is one budget for each
 * cost centre, category and month: a second is refused. It is an approval document: draft or sent-back ones change here; pending and approved ones are
 * locked (the model refuses) until re-opened through the approval workflow.
 */
final class BudgetService
{
    public function __construct(private readonly IndustryTemplateService $templates) {}

    /**
     * @param  array<string, mixed>  $input  cost_center_id, loss_category_id, month (YYYY-MM), amount, remarks
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function save(array $input, User $actor, ?Budget $editing = null): Budget
    {
        Gate::forUser($actor)->authorize($editing === null ? 'create' : 'update', $editing ?? Budget::class);

        $errors = [];
        $costCenter = is_numeric($input['cost_center_id'] ?? null) ? CostCenter::query()->where('status', 'active')->find((int) $input['cost_center_id']) : null;

        if ($costCenter === null) {
            $errors['cost_center_id'] = 'Choose an active cost centre.';
        }

        $categoryId = is_numeric($input['loss_category_id'] ?? null) ? (int) $input['loss_category_id'] : null;

        if ($categoryId !== null) {
            $enabled = $this->templates->enabled()->pluck('id');
            $ok = LossCategory::query()->whereKey($categoryId)->whereIn('industry_template_id', $enabled)->exists();

            if (! $ok) {
                $errors['loss_category_id'] = 'Choose a loss category of an industry template your company uses.';
            }
        }

        $month = trim((string) ($input['month'] ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month);

        if ($date === false || $date->format('Y-m') !== $month) {
            $errors['month'] = 'Choose the month.';
        }

        $amount = trim((string) ($input['amount'] ?? ''));

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) !== 1) {
            $errors['amount'] = 'Enter the budget in rupees (not negative, up to 2 decimals).';
        }

        $remarks = trim((string) ($input['remarks'] ?? ''));

        if (mb_strlen($remarks) > 1000) {
            $errors['remarks'] = 'Remarks can be at most 1000 characters.';
        }

        if ($errors === [] && Budget::query()->where('cost_center_id', $costCenter->getKey())->where('category_key', $categoryId ?? 0)
            ->where('period_start', $date->format('Y-m-01'))->when($editing !== null, fn ($q) => $q->whereKeyNot($editing->getKey()))->exists()) {
            $errors['month'] = 'This cost centre already has a budget for that month and category: change that one.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($editing, $costCenter, $categoryId, $date, $amount, $remarks, $actor) {
            $budget = $editing === null ? new Budget : Budget::query()->lockForUpdate()->findOrFail($editing->getKey());

            if ($editing !== null && ! $budget->isEditableByWorkflow()) {
                throw new FieldRuleException('A budget that is waiting for approval or approved cannot be changed: re-open it first.');
            }

            $budget->forceFill([
                'cost_center_id' => $costCenter->getKey(), 'loss_category_id' => $categoryId, 'period_start' => $date->format('Y-m-01'),
                'amount' => bcadd($amount, '0', 2), 'remarks' => $remarks === '' ? null : $remarks, 'updated_by' => $actor->getKey(),
            ] + ($editing === null ? ['created_by' => $actor->getKey()] : []))->save();

            return $budget->refresh();
        });
    }
}
