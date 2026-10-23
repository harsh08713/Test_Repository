<?php

namespace App\Modules\Reports\Http\Livewire;

use App\Models\User;
use App\Modules\Reports\Services\SalesDashboard;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The sales dashboard: leads, follow-ups, targets, deals, quotations and approved sales for a period and branch, each limited to what the person may see. */
class SalesDashboardPanel extends Component
{
    public const PERIODS = [
        'today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last30' => 'Last 30 days', 'quarter' => 'This quarter', 'year' => 'This year',
    ];

    #[Url(as: 'period')]
    public string $period = 'month';

    #[Url(as: 'branch')]
    public string $branch = '';

    public function render(SalesDashboard $dashboard): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $period = array_key_exists($this->period, self::PERIODS) ? $this->period : 'month';
        [$from, $to] = $this->window($period);

        $branches = Location::query()->orderBy('name')->pluck('name', 'id')->all();
        $branch = ctype_digit($this->branch) && array_key_exists((int) $this->branch, $branches) ? (int) $this->branch : null;

        return view('reports.dashboard', [
            'data' => $dashboard->build($user, $from, $to, $branch),
            'periods' => self::PERIODS,
            'branches' => $branches,
            'periodLabel' => self::PERIODS[$period],
            'canReports' => $user->can('report.view'),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function window(string $period): array
    {
        $today = Carbon::today();

        return match ($period) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'week' => [$today->copy()->startOfWeek()->toDateString(), $today->toDateString()],
            'last30' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
            'quarter' => [$today->copy()->startOfQuarter()->toDateString(), $today->toDateString()],
            'year' => [$today->copy()->startOfYear()->toDateString(), $today->toDateString()],
            default => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
        };
    }
}
