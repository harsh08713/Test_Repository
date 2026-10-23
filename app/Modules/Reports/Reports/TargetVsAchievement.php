<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\TargetAchievement;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/** Each target against what approved sales orders have achieved so far. Achievement is an actual; the target is the goal. */
final class TargetVsAchievement extends Report
{
    public function key(): string
    {
        return 'target-vs-achievement';
    }

    public function title(): string
    {
        return 'Target vs achievement';
    }

    public function description(): string
    {
        return 'Every target you may see with what has been achieved: approved sales orders, net of tax, for the period.';
    }

    public function group(): string
    {
        return 'Sales';
    }

    public function permission(): string
    {
        return 'target.view';
    }

    public function filters(): array
    {
        return ['branch', 'person', 'choice'];
    }

    public function choiceLabel(): string
    {
        return 'Period';
    }

    public function choices(): array
    {
        return ['current' => 'Running now', 'upcoming' => 'Not started', 'past' => 'Finished'];
    }

    public function defaultChoice(): string
    {
        return 'current';
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'ACTUAL: achievement counts approved, uncancelled sales orders on their order date, net of tax, credited to the salesperson (a team target adds its members for the days they were on the team). Returns and credit notes do not exist yet.';
    }

    public function columns(): array
    {
        return [
            new C('subject', 'For'), new C('kind', 'Person or team'), new C('period', 'Period'), new C('period_start', 'From', C::DATE), new C('period_end', 'To', C::DATE),
            new C('item_group', 'Item group'), new C('branch', 'Branch'),
            new C('target_value', 'Target', C::MONEY), new C('achieved_value', 'Achieved', C::MONEY), new C('value_percent', 'Achieved %', C::PERCENT),
            new C('target_quantity', 'Target quantity', C::NUMBER), new C('achieved_quantity', 'Achieved quantity', C::NUMBER), new C('quantity_percent', 'Quantity %', C::PERCENT),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $scope = app(SalesScope::class);
        $today = now()->toDateString();

        $query = SalesTarget::query()->with(['user:id,name', 'team:id,name', 'itemGroup:id,name', 'location:id,name'])
            ->when(! $scope->seesEveryone($user), function ($q) use ($user, $scope) {
                $teamIds = SalesTeam::query()->where('manager_user_id', $user->getKey())->pluck('id')
                    ->merge(SalesTeamMember::query()->where('user_id', $user->getKey())->whereNull('left_on')->pluck('sales_team_id'))
                    ->unique()->values()->all();

                $q->where(fn ($w) => $w->whereIn('user_id', $scope->visibleUserIds($user) ?? [])->orWhereIn('sales_team_id', $teamIds));
            });

        $input->branch === null || $query->where('location_id', $input->branch);
        $input->person === null || $query->where('user_id', $input->person);

        match ($input->choice) {
            'current' => $query->where('period_start', '<=', $today)->where('period_end', '>=', $today),
            'upcoming' => $query->where('period_start', '>', $today),
            'past' => $query->where('period_end', '<', $today),
            default => null,
        };

        return $query->orderByDesc('period_start')->orderBy('id');
    }

    public function present(object $row): array
    {
        if (! $row instanceof SalesTarget) {
            throw new InvalidArgumentException('A target row is expected.');
        }

        $progress = app(TargetAchievement::class)->progress($row);

        return [
            'subject' => $row->subjectName(), 'kind' => $row->user_id !== null ? 'Person' : 'Team', 'period' => ucfirst($row->period_type->value),
            'period_start' => $row->period_start->toDateString(), 'period_end' => $row->period_end->toDateString(),
            'item_group' => $row->itemGroup->name ?? 'All items', 'branch' => $row->location->name ?? 'All branches',
            'target_value' => $row->target_value, 'achieved_value' => $progress->achievedValue, 'value_percent' => $progress->valuePercent,
            'target_quantity' => $row->target_quantity, 'achieved_quantity' => $progress->achievedQuantity, 'quantity_percent' => $progress->quantityPercent,
        ];
    }
}
