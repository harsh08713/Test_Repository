<?php

namespace App\Modules\Finance\Http\Livewire;

use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\CostCenter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Budgets')]
class BudgetList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'cc')]
    public string $costCenter = '';

    #[Url(as: 'approval')]
    public string $approval = '';

    #[Url(as: 'year')]
    public string $year = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Budget::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['costCenter', 'approval', 'year'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $budgets = Budget::query()->with(['costCenter:id,name', 'category:id,name'])
            ->when(ctype_digit($this->costCenter), fn (Builder $q) => $q->where('cost_center_id', (int) $this->costCenter))
            ->when(in_array($this->approval, ['draft', 'pending', 'approved', 'rejected'], true), fn (Builder $q) => $q->where('approval_status', $this->approval))
            ->when(preg_match('/^\d{4}$/', $this->year) === 1, fn (Builder $q) => $q->whereYear('period_start', (int) $this->year))
            ->orderByDesc('period_start')->orderBy('cost_center_id')->paginate(self::PER_PAGE);

        return view('finance.budgets', [
            'budgets' => $budgets,
            'costCenters' => CostCenter::query()->orderBy('name')->pluck('name', 'id')->all(),
            'canCreate' => Gate::allows('create', Budget::class),
        ]);
    }
}
