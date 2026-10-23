<?php

namespace App\Modules\CRM\Http\Livewire\Targets;

use App\Modules\CRM\Enums\TargetPeriod;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\TargetService;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Support\FinancialYear;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Set a target for a person or a team for a month or a quarter. The period is chosen as a year and a month (or a financial
 * year and a quarter) so a day is never typed; every rule — including "no overlapping duplicates" — lives in TargetService.
 */
#[Title('Target')]
class TargetForm extends Component
{
    use ScopesToPeople;

    #[Locked]
    public ?int $targetId = null;

    /** @var array<string, mixed> */
    public array $target = [];

    /** The calendar year of a monthly target, or the year the financial year starts for a quarterly one. */
    public string $year = '';

    /** The month (1–12) of a monthly target, or the quarter (1–4) of a quarterly one. */
    public string $slot = '';

    public function mount(?SalesTarget $editing = null): void
    {
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->targetId = $editing->id;
            $this->target = [
                'subject' => $editing->user_id !== null ? 'user' : 'team',
                'user_id' => (string) ($editing->user_id ?? ''), 'sales_team_id' => (string) ($editing->sales_team_id ?? ''),
                'period_type' => $editing->period_type->value,
                'target_value' => $editing->target_value === null ? '' : rtrim(rtrim($editing->target_value, '0'), '.'),
                'target_quantity' => $editing->target_quantity === null ? '' : rtrim(rtrim($editing->target_quantity, '0'), '.'),
                'item_group_id' => (string) ($editing->item_group_id ?? ''), 'location_id' => (string) ($editing->location_id ?? ''),
                'allow_overlap' => $editing->allow_overlap, 'status' => $editing->status, 'remarks' => (string) ($editing->remarks ?? ''),
            ];

            $fy = $this->financialYearStartMonth();
            if ($editing->period_type === TargetPeriod::Monthly) {
                $this->year = (string) $editing->period_start->year;
                $this->slot = (string) $editing->period_start->month;
            } else {
                $this->year = (string) FinancialYear::startYear($editing->period_start, $fy);
                $this->slot = (string) (intdiv(($editing->period_start->month - $fy + 12) % 12, 3) + 1);
            }

            return;
        }

        Gate::authorize('create', SalesTarget::class);

        $now = now();
        $this->target = [
            'subject' => 'user', 'user_id' => '', 'sales_team_id' => '', 'period_type' => TargetPeriod::Monthly->value,
            'target_value' => '', 'target_quantity' => '', 'item_group_id' => '', 'location_id' => '',
            'allow_overlap' => false, 'status' => 'active', 'remarks' => '',
        ];
        $this->year = (string) $now->year;
        $this->slot = (string) $now->month;
    }

    /** Switching between monthly and quarterly changes what the period boxes mean: start from the current one. */
    public function updatedTarget(mixed $value, string $key): void
    {
        if ($key !== 'period_type') {
            return;
        }

        $now = now();
        $fy = $this->financialYearStartMonth();

        if ($value === TargetPeriod::Quarterly->value) {
            $this->year = (string) FinancialYear::startYear($now, $fy);
            $this->slot = (string) (intdiv(($now->month - $fy + 12) % 12, 3) + 1);
        } else {
            $this->year = (string) $now->year;
            $this->slot = (string) $now->month;
        }
    }

    public function save(TargetService $service): void
    {
        $user = $this->actor();

        $editing = $this->targetId !== null ? SalesTarget::query()->findOrFail($this->targetId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? SalesTarget::class);

        $input = $this->target;
        $input['period_start'] = $this->periodStart();

        try {
            $service->save($editing, ['target' => $input], $user);
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $editing ? 'Target saved.' : 'Target set.');
        $this->redirectRoute('field.targets.index', navigate: false);
    }

    public function render(SalesScope $scope): View
    {
        $user = $this->actor();
        $managed = $scope->managedUserIds($user);
        $people = $this->visiblePeople();
        // a person is targeted by the people who manage them, never by themselves (unless they see everyone)
        $people = $managed === null ? $people : array_intersect_key($people, array_flip($managed));

        $teams = SalesTeam::query()
            ->when(! $scope->seesEveryone($user), fn ($q) => $q->where('manager_user_id', $user->getKey()))
            ->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();

        $fy = $this->financialYearStartMonth();
        $quarters = [];
        foreach ([1, 2, 3, 4] as $q) {
            $first = Carbon::create(2001, $fy, 1)->addMonths(($q - 1) * 3);
            $quarters[$q] = "Q{$q} ({$first->format('M')}–{$first->copy()->addMonths(2)->format('M')})";
        }

        return view('crm.targets.form', [
            'editing' => $this->targetId !== null,
            'people' => $people,
            'teams' => $teams,
            'groups' => ItemGroup::query()->orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'quarters' => $quarters,
            'months' => collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2001, $m, 1)->format('F')])->all(),
            'quarterly' => ($this->target['period_type'] ?? '') === TargetPeriod::Quarterly->value,
            'preview' => $this->describe(),
        ]);
    }

    private function financialYearStartMonth(): int
    {
        return (int) (DB::table('companies')->where('id', app(TenantContext::class)->activeCompanyId())->value('financial_year_start_month') ?: 4);
    }

    /** The first day of the chosen month or quarter (or '' while the boxes are incomplete). */
    private function periodStart(): string
    {
        if (! ctype_digit($this->year) || ! ctype_digit($this->slot) || (int) $this->year < 2000 || (int) $this->year > 2100) {
            return '';
        }

        $slot = (int) $this->slot;

        if (($this->target['period_type'] ?? '') === TargetPeriod::Quarterly->value) {
            return $slot >= 1 && $slot <= 4 ? Carbon::create((int) $this->year, $this->financialYearStartMonth(), 1)->addMonths(($slot - 1) * 3)->toDateString() : '';
        }

        return $slot >= 1 && $slot <= 12 ? Carbon::create((int) $this->year, $slot, 1)->toDateString() : '';
    }

    /** "Q3 FY26-27 (Oct–Dec 2026)" for what is chosen, so the person sees what they are setting. */
    private function describe(): string
    {
        $start = $this->periodStart();
        $type = TargetPeriod::tryFrom((string) ($this->target['period_type'] ?? ''));

        return $start === '' || $type === null ? '' : $type->describe(Carbon::parse($start), $this->financialYearStartMonth());
    }
}
