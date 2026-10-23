<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Where leads come from and how far each source takes them: the leads made in the period, by their stage today. */
final class LeadSourceAnalysis extends Report
{
    public function key(): string
    {
        return 'lead-source-analysis';
    }

    public function title(): string
    {
        return 'Lead source analysis';
    }

    public function description(): string
    {
        return 'For each source: leads made, how many are qualified, became deals, were won or lost, and the share won.';
    }

    public function group(): string
    {
        return 'Leads';
    }

    public function permission(): string
    {
        return 'lead.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'person', 'team'];
    }

    public function dateLabel(): string
    {
        return 'Lead date';
    }

    public function note(): string
    {
        return 'Leads are counted by the stage they are at today, not the stage they reached on the way. “Won” means the lead’s deal was won; the value of orders is in the Sales order register.';
    }

    public function columns(): array
    {
        return [
            new C('source', 'Source'), new C('total', 'Leads', C::NUMBER), new C('open', 'Still open', C::NUMBER),
            new C('qualified', 'Qualified or further', C::NUMBER), new C('deals', 'Became deals', C::NUMBER),
            new C('won', 'Won', C::NUMBER), new C('lost', 'Lost', C::NUMBER), new C('won_percent', 'Won %', C::PERCENT),
            new C('expected_value', 'Expected value', C::MONEY),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(LeadAccess::class)->restrict(Lead::query(), $user)
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->selectRaw("coalesce(lead_sources.name, '(no source)') as source")
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(leads.stage in ('new','contacted')) as open")
            ->selectRaw("sum(leads.stage in ('qualified','deal','quotation','won')) as qualified")
            ->selectRaw("sum(leads.stage in ('deal','quotation','won')) as deals")
            ->selectRaw("sum(leads.stage = 'won') as won")
            ->selectRaw("sum(leads.stage = 'lost') as lost")
            ->selectRaw("round(100 * sum(leads.stage = 'won') / count(*), 1) as won_percent")
            ->selectRaw('coalesce(sum(leads.expected_value), 0) as expected_value')
            ->groupBy('leads.lead_source_id', 'lead_sources.name');

        $this->between($query, 'leads.lead_date', $input);
        $input->branch === null || $query->where('leads.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('leads.assigned_to', $input->peopleIds);

        return $query->orderByDesc(DB::raw('count(*)'))->orderBy('leads.lead_source_id');
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(leads.stage = 'won'), 0) as won");
        $total = (int) ($t->total ?? 0);

        return ['Leads' => number_format($total), 'Won' => number_format((int) ($t->won ?? 0)), 'Won %' => $total === 0 ? '—' : number_format(100 * (int) $t->won / $total, 1).' %'];
    }
}
