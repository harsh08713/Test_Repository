<?php

namespace App\Modules\Finance\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Budget')]
class BudgetForm extends Component
{
    #[Locked]
    public ?int $budgetId = null;

    public string $costCenterId = '';

    public string $categoryId = '';

    public string $month = '';

    public string $amount = '';

    public string $remarks = '';

    public function mount(?int $editing = null): void
    {
        $this->month = now()->format('Y-m');

        if ($editing !== null) {
            $budget = Budget::query()->findOrFail($editing);
            Gate::authorize('update', $budget);

            $this->budgetId = $budget->id;
            $this->costCenterId = (string) $budget->cost_center_id;
            $this->categoryId = (string) $budget->loss_category_id;
            $this->month = $budget->period_start->format('Y-m');
            $this->amount = (string) $budget->amount;
            $this->remarks = (string) $budget->remarks;

            return;
        }

        Gate::authorize('create', Budget::class);
    }

    public function save(BudgetService $service): void
    {
        $this->resetErrorBag();

        try {
            $budget = $service->save([
                'cost_center_id' => $this->costCenterId, 'loss_category_id' => $this->categoryId, 'month' => $this->month, 'amount' => $this->amount, 'remarks' => $this->remarks,
            ], Auth::user(), $this->budgetId === null ? null : Budget::query()->findOrFail($this->budgetId));
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', 'Budget saved. Send it for approval when it is ready.');
        $this->redirectRoute('finance.budgets.show', $budget, navigate: false);
    }

    public function render(IndustryTemplateService $templates): View
    {
        return view('finance.budget-form', [
            'editing' => $this->budgetId !== null,
            'costCenters' => CostCenter::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'categories' => LossCategory::query()->whereIn('industry_template_id', $templates->enabled()->pluck('id'))->with('template:id,name')->orderBy('industry_template_id')->orderBy('name')->get()
                ->mapWithKeys(fn ($c) => [$c->id => $c->template?->name.' · '.$c->name])->all(),
        ]);
    }
}
