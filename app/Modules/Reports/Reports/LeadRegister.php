<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Every lead the person may see, with its source, owner and stage. */
final class LeadRegister extends Report
{
    public function key(): string
    {
        return 'lead-register';
    }

    public function title(): string
    {
        return 'Lead register';
    }

    public function description(): string
    {
        return 'Every lead you may see, with its source, branch, owner, stage and expected value.';
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
        return ['date', 'branch', 'person', 'team', 'source', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Lead date';
    }

    public function choiceLabel(): string
    {
        return 'Stage';
    }

    public function choices(): array
    {
        return collect(LeadStage::cases())->mapWithKeys(fn (LeadStage $s) => [$s->value => $s->label()])->all();
    }

    public function columns(): array
    {
        return [
            new C('lead_number', 'Lead'), new C('lead_date', 'Date', C::DATE), new C('name', 'Name'), new C('mobile', 'Mobile'),
            new C('source', 'Source'), new C('branch', 'Branch'), new C('owner', 'Owner'), new C('stage', 'Stage'),
            new C('kind', 'Kind'), new C('expected_value', 'Expected value', C::MONEY), new C('next_followup_at', 'Next follow-up', C::DATETIME),
            new C('last_contacted_at', 'Last contacted', C::DATETIME),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(LeadAccess::class)->restrict(Lead::query(), $user)
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->leftJoin('locations', 'locations.id', '=', 'leads.location_id')
            ->leftJoin('users', 'users.id', '=', 'leads.assigned_to')
            ->select([
                'leads.id', 'leads.lead_number', 'leads.lead_date', 'leads.name', 'leads.mobile', 'leads.stage', 'leads.lead_type',
                'leads.expected_value', 'leads.next_followup_at', 'leads.last_contacted_at',
                'lead_sources.name as source', 'locations.name as branch', 'users.name as owner',
            ]);

        $this->between($query, 'leads.lead_date', $input);
        $input->branch === null || $query->where('leads.location_id', $input->branch);
        $input->source === null || $query->where('leads.lead_source_id', $input->source);
        $input->peopleIds === null || $query->whereIn('leads.assigned_to', $input->peopleIds);
        $input->choice === null || $query->where('leads.stage', $input->choice);

        return $query->orderByDesc('leads.lead_date')->orderByDesc('leads.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['stage'] = LeadStage::tryFrom((string) $row->getRawOriginal('stage'))?->label();
        $out['owner'] ??= 'In the pool';
        $out['kind'] = $row->getRawOriginal('lead_type') === 'existing' ? 'Inquiry from a customer' : 'New lead';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $rows = $this->totals($this->query($input, $user), 'count(*) as total, coalesce(sum(leads.expected_value), 0) as value');

        return ['Leads' => number_format((int) ($rows->total ?? 0)), 'Expected value' => '₹ '.number_format((float) ($rows->value ?? 0), 2)];
    }
}
