<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Deals by stage, probability and expected close date. The weighted value is a forecast, never a result. */
final class DealPipeline extends Report
{
    public function key(): string
    {
        return 'deal-pipeline';
    }

    public function title(): string
    {
        return 'Deal pipeline';
    }

    public function description(): string
    {
        return 'Deals by stage with probability, expected value and expected close date. Starts on the deals still open.';
    }

    public function group(): string
    {
        return 'Pipeline';
    }

    public function permission(): string
    {
        return 'deal.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'person', 'team', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Expected close';
    }

    public function choiceLabel(): string
    {
        return 'Stage';
    }

    public function choices(): array
    {
        return ['open' => 'Still open', ...collect(DealStage::cases())->mapWithKeys(fn (DealStage $s) => [$s->value => $s->label()])->all()];
    }

    public function defaultChoice(): string
    {
        return 'open';
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'FORECAST: “Weighted value” is the expected value × the deal’s probability. It is an estimate of what may be won, not a result — approved sales orders are the actual (Sales order register).';
    }

    public function columns(): array
    {
        return [
            new C('deal_number', 'Deal'), new C('party_name', 'Customer'), new C('owner', 'Owner'), new C('branch', 'Branch'), new C('stage', 'Stage'),
            new C('probability', 'Probability', C::PERCENT), new C('expected_value', 'Expected value', C::MONEY), new C('weighted_value', 'Weighted value (forecast)', C::MONEY),
            new C('expected_close_date', 'Expected close', C::DATE), new C('overdue_days', 'Days past close date', C::NUMBER), new C('approval', 'Approval'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(DealAccess::class)->restrict(Deal::query(), $user)
            ->leftJoin('users', 'users.id', '=', 'deals.assigned_to')
            ->leftJoin('locations', 'locations.id', '=', 'deals.location_id')
            ->select([
                'deals.id', 'deals.deal_number', 'deals.party_name', 'deals.stage', 'deals.probability', 'deals.expected_value', 'deals.expected_close_date', 'deals.approval_status',
                'users.name as owner', 'locations.name as branch',
            ])
            ->selectRaw('round(deals.expected_value * deals.probability / 100, 2) as weighted_value')
            ->selectRaw("case when deals.stage not in ('won','lost') and deals.expected_close_date < curdate() then datediff(curdate(), deals.expected_close_date) end as overdue_days");

        $this->between($query, 'deals.expected_close_date', $input);
        $input->branch === null || $query->where('deals.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('deals.assigned_to', $input->peopleIds);

        match ($input->choice) {
            null => null,
            'open' => $query->whereNotIn('deals.stage', ['won', 'lost']),
            default => $query->where('deals.stage', $input->choice),
        };

        return $query->orderBy('deals.expected_close_date')->orderBy('deals.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['stage'] = DealStage::tryFrom((string) $row->getRawOriginal('stage'))?->label();
        $out['approval'] = ucfirst((string) $row->getRawOriginal('approval_status'));

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(*) as total, coalesce(sum(deals.expected_value), 0) as value, coalesce(sum(round(deals.expected_value * deals.probability / 100, 2)), 0) as weighted');

        return [
            'Deals' => number_format((int) ($t->total ?? 0)), 'Expected value' => '₹ '.number_format((float) ($t->value ?? 0), 2),
            'Weighted value (forecast)' => '₹ '.number_format((float) ($t->weighted ?? 0), 2),
        ];
    }
}
