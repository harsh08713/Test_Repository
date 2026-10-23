<?php

namespace App\Modules\CRM\Http\Livewire\Targets;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\TargetAchievement;
use App\Modules\CRM\Services\TargetService;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Targets and how far each has got. A person sees their own and their team's; a manager those of the people and teams
 * they manage; someone who sees everyone sees all. "Achieved" comes from approved sales orders (none exist yet).
 */
#[Title('Targets')]
class TargetList extends Component
{
    use ScopesToPeople, WithPagination;

    /** current | upcoming | past | '' (all) */
    #[Url(as: 'when')]
    public string $when = 'current';

    #[Url(as: 'person')]
    public string $person = '';

    #[Url(as: 'group')]
    public string $group = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    #[Url(as: 'status')]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', SalesTarget::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['when', 'person', 'group', 'branch', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function delete(int $id, TargetService $service): void
    {
        $target = SalesTarget::query()->findOrFail($id);
        Gate::authorize('delete', $target);

        try {
            $service->delete($target);
        } catch (FieldRuleException $e) {
            $this->addError('list', $e->getMessage());

            return;
        }

        session()->flash('status', 'Target removed.');
    }

    public function render(SalesScope $scope, TargetAchievement $achievement): View
    {
        $user = $this->actor();
        $today = now()->toDateString();

        $targets = SalesTarget::query()
            ->with(['user:id,name', 'team:id,name', 'itemGroup:id,name', 'location:id,name'])
            ->when(! $scope->seesEveryone($user), function ($q) use ($user, $scope) {
                $teamIds = SalesTeam::query()->where('manager_user_id', $user->getKey())->pluck('id')
                    ->merge(SalesTeamMember::query()->where('user_id', $user->getKey())->whereNull('left_on')->pluck('sales_team_id'))
                    ->unique()->values()->all();

                $q->where(fn ($w) => $w->whereIn('user_id', $scope->visibleUserIds($user) ?? [])->orWhereIn('sales_team_id', $teamIds));
            })
            ->when($this->when === 'current', fn ($q) => $q->where('period_start', '<=', $today)->where('period_end', '>=', $today))
            ->when($this->when === 'upcoming', fn ($q) => $q->where('period_start', '>', $today))
            ->when($this->when === 'past', fn ($q) => $q->where('period_end', '<', $today))
            ->when(ctype_digit($this->person), fn ($q) => $q->where('user_id', (int) $this->person))
            ->when(ctype_digit($this->group), fn ($q) => $q->where('item_group_id', (int) $this->group))
            ->when(ctype_digit($this->branch), fn ($q) => $q->where('location_id', (int) $this->branch))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('period_start')
            ->orderBy('id')
            ->paginate(15);

        $fyStart = (int) (DB::table('companies')->where('id', app(TenantContext::class)->activeCompanyId())->value('financial_year_start_month') ?: 4);

        return view('crm.targets.list', [
            'targets' => $targets,
            'progress' => collect($targets->items())->mapWithKeys(fn (SalesTarget $t) => [$t->id => $achievement->progress($t)])->all(),
            'people' => $this->visiblePeople(),
            'groups' => ItemGroup::query()->orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'fyStart' => $fyStart,
            'today' => $today,
            'canCreate' => Gate::allows('create', SalesTarget::class),
        ]);
    }
}
