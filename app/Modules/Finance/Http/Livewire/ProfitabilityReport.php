<?php

namespace App\Modules\Finance\Http\Livewire;

use App\Models\User;
use App\Modules\Finance\Services\Profitability;
use App\Modules\Shared\Support\FinancialYear;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Profitability')]
class ProfitabilityReport extends Component
{
    #[Url(as: 'by')]
    public string $by = 'product';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('profitability.view'), 403);

        if ($this->from === '' || $this->to === '') {
            [$start] = FinancialYear::range(now());
            $this->from = $start->toDateString();
            $this->to = now()->toDateString();
        }
    }

    public function render(Profitability $profit): View
    {
        $by = array_key_exists($this->by, Profitability::DIMENSIONS) ? $this->by : 'product';
        $rows = $profit->by($by, $this->date($this->from) ?? now()->startOfYear(), $this->date($this->to) ?? now());

        $sum = fn (string $k) => array_reduce($rows, fn (string $c, array $r) => bcadd($c, $r[$k], 2), '0.00');
        $net = $sum('net_revenue');
        $profitTotal = $sum('gross_profit');

        return view('finance.profitability', [
            'by' => $by, 'dimensions' => Profitability::DIMENSIONS, 'rows' => $rows,
            'totals' => ['net_revenue' => $net, 'cogs' => $sum('cogs'), 'gross_profit' => $profitTotal, 'actual_loss' => $sum('actual_loss'),
                'margin_pct' => bccomp($net, '0', 2) === 0 ? null : bcadd(bcmul(bcdiv($profitTotal, $net, 8), '100', 6), bccomp($profitTotal, '0', 2) < 0 ? '-0.005' : '0.005', 2)],
            'incomplete' => array_sum(array_column($rows, 'lines_without_cost')),
        ]);
    }

    private function date(string $value): ?Carbon
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? Carbon::instance($parsed) : null;
    }
}
