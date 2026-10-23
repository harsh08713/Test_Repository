<?php

namespace App\Modules\Finance\Http\Livewire;

use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Finance\Services\BudgetVsActual;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Budget against actual')]
class VarianceReport extends Component
{
    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'cc')]
    public string $costCenter = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Budget::class);

        if ($this->from === '' || $this->to === '') {
            $this->from = now()->startOfMonth()->toDateString();
            $this->to = now()->toDateString();
        }
    }

    public function render(BudgetVsActual $report): View
    {
        $from = $this->date($this->from) ?? now()->startOfMonth();
        $to = $this->date($this->to) ?? now();

        return view('finance.variance', [
            'rows' => $report->forPeriod($from, $to, ctype_digit($this->costCenter) ? (int) $this->costCenter : null),
            'costCenters' => CostCenter::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    private function date(string $value): ?Carbon
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? Carbon::instance($parsed) : null;
    }
}
