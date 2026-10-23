<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Every hand-over of a lead from one salesperson (or the pool) to another, with who did it and why. */
final class LeadTransferHistory extends Report
{
    public function key(): string
    {
        return 'lead-transfer-history';
    }

    public function title(): string
    {
        return 'Lead transfer history';
    }

    public function description(): string
    {
        return 'Who handed which lead to whom, who did it, when and why — for the leads you may see.';
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
        return 'Handed over';
    }

    public function note(): string
    {
        return '“Person” and “team” match the salesperson the lead went to or came from.';
    }

    public function columns(): array
    {
        return [
            new C('created_at', 'When', C::DATETIME), new C('lead_number', 'Lead'), new C('lead_name', 'Name'), new C('branch', 'Branch'),
            new C('from_owner', 'From'), new C('to_owner', 'To'), new C('from_team', 'From team'), new C('to_team', 'To team'),
            new C('by', 'Done by'), new C('reason', 'Reason'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $visible = app(LeadAccess::class)->restrict(Lead::query(), $user)->select('leads.id');

        $query = LeadTransfer::query()
            ->whereIn('lead_transfers.lead_id', $visible)
            ->join('leads', 'leads.id', '=', 'lead_transfers.lead_id')
            ->leftJoin('locations', 'locations.id', '=', 'leads.location_id')
            ->leftJoin('users as from_u', 'from_u.id', '=', 'lead_transfers.from_user_id')
            ->leftJoin('users as to_u', 'to_u.id', '=', 'lead_transfers.to_user_id')
            ->leftJoin('users as by_u', 'by_u.id', '=', 'lead_transfers.transferred_by')
            ->leftJoin('sales_teams as from_t', 'from_t.id', '=', 'lead_transfers.from_team_id')
            ->leftJoin('sales_teams as to_t', 'to_t.id', '=', 'lead_transfers.to_team_id')
            ->select([
                'lead_transfers.id', 'lead_transfers.created_at', 'lead_transfers.reason',
                'leads.lead_number', 'leads.name as lead_name', 'locations.name as branch',
                'from_u.name as from_owner', 'to_u.name as to_owner', 'from_t.name as from_team', 'to_t.name as to_team', 'by_u.name as by',
            ]);

        $this->between($query, 'lead_transfers.created_at', $input, true);
        $input->branch === null || $query->where('leads.location_id', $input->branch);
        $input->peopleIds === null || $query->where(fn ($q) => $q->whereIn('lead_transfers.from_user_id', $input->peopleIds)->orWhereIn('lead_transfers.to_user_id', $input->peopleIds));

        return $query->orderByDesc('lead_transfers.created_at')->orderByDesc('lead_transfers.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['from_owner'] ??= 'The pool';
        $out['to_owner'] ??= 'The pool';

        return $out;
    }
}
