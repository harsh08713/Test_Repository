<?php

namespace App\Modules\Finance\Http\Livewire;

use App\Modules\Finance\Models\Budget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Budget')]
class BudgetShow extends Component
{
    #[Locked]
    public int $budgetId = 0;

    public function mount(Budget $budget): void
    {
        Gate::authorize('view', $budget);

        $this->budgetId = $budget->id;
    }

    #[Computed]
    public function budgetModel(): Budget
    {
        $budget = Budget::query()->with(['costCenter', 'category'])->findOrFail($this->budgetId);
        Gate::authorize('view', $budget);

        return $budget;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->budgetModel);
    }

    public function render(): View
    {
        $budget = $this->budgetModel();

        return view('finance.budget-show', ['budget' => $budget, 'canEdit' => Gate::allows('update', $budget)]);
    }
}
