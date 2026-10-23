<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Enums\FollowupType;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Follow-ups planned and done, with what came of each, and which are overdue. */
final class FollowupRegister extends Report
{
    public function key(): string
    {
        return 'followup-register';
    }

    public function title(): string
    {
        return 'Follow-up register and outcomes';
    }

    public function description(): string
    {
        return 'Every follow-up you may see — planned, done or overdue — with its owner, what it was about and the outcome. Pick “Overdue” for the ones that have slipped.';
    }

    public function group(): string
    {
        return 'Leads';
    }

    public function permission(): string
    {
        return 'followup.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'person', 'team', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Scheduled';
    }

    public function choices(): array
    {
        return ['planned' => 'Planned (not done yet)', 'overdue' => 'Overdue', 'escalated' => 'Overdue and escalated to the manager', 'done' => 'Done', 'cancelled' => 'Called off'];
    }

    public function note(): string
    {
        return 'Overdue means planned and past its time. The SLA compliance report arrives with Phase 14; this is its follow-up half.';
    }

    public function columns(): array
    {
        return [
            new C('scheduled_at', 'Scheduled', C::DATETIME), new C('type', 'Type'), new C('about', 'About'), new C('number', 'Record'),
            new C('owner', 'Owner'), new C('branch', 'Branch'), new C('state', 'State'), new C('outcome', 'Outcome'),
            new C('completed_at', 'Done at', C::DATETIME), new C('days_overdue', 'Days overdue', C::NUMBER), new C('notes', 'Notes'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = Followup::query()->whereIn('followups.id', app(FollowupAgenda::class)->query($user)->select('followups.id'))
            ->leftJoin('leads', fn ($j) => $j->on('leads.id', '=', 'followups.subject_id')->where('followups.subject_type', '=', 'lead'))
            ->leftJoin('deals', fn ($j) => $j->on('deals.id', '=', 'followups.subject_id')->where('followups.subject_type', '=', 'deal'))
            ->leftJoin('quotations', fn ($j) => $j->on('quotations.id', '=', 'followups.subject_id')->where('followups.subject_type', '=', 'quotation'))
            ->leftJoin('followup_outcomes', 'followup_outcomes.id', '=', 'followups.outcome_id')
            ->leftJoin('users', 'users.id', '=', 'followups.assigned_to')
            ->leftJoin('locations', 'locations.id', '=', 'followups.location_id')
            ->whereNull('followups.deleted_at')
            ->select([
                'followups.id', 'followups.scheduled_at', 'followups.type', 'followups.status', 'followups.completed_at', 'followups.notes', 'followups.escalated_at',
                'followups.subject_type',
                'users.name as owner', 'locations.name as branch', 'followup_outcomes.name as outcome',
            ])
            ->selectRaw('coalesce(leads.name, deals.party_name, quotations.party_name) as about')
            ->selectRaw('coalesce(leads.lead_number, deals.deal_number, quotations.quotation_number) as number')
            ->selectRaw("case when followups.status = 'pending' and followups.scheduled_at < ? then datediff(?, followups.scheduled_at) end as days_overdue", [now()->toDateTimeString(), now()->toDateTimeString()]);

        $this->between($query, 'followups.scheduled_at', $input, true);
        $input->branch === null || $query->where('followups.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('followups.assigned_to', $input->peopleIds);

        match ($input->choice) {
            'planned' => $query->where('followups.status', 'pending'),
            'overdue' => $query->where('followups.status', 'pending')->where('followups.scheduled_at', '<', now()),
            'escalated' => $query->where('followups.status', 'pending')->whereNotNull('followups.escalated_at'),
            'done' => $query->where('followups.status', 'done'),
            'cancelled' => $query->where('followups.status', 'cancelled'),
            default => null,
        };

        return $query->orderByDesc('followups.scheduled_at')->orderByDesc('followups.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $status = (string) $row->getRawOriginal('status');
        $out['type'] = FollowupType::tryFrom((string) $row->getRawOriginal('type'))?->label() ?? $row->getRawOriginal('type');
        $out['state'] = match (true) {
            $status === 'done' => 'Done',
            $status === 'cancelled' => 'Called off',
            $row->days_overdue !== null && $row->escalated_at !== null => 'Overdue, escalated',
            $row->days_overdue !== null => 'Overdue',
            default => 'Planned',
        };

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(followups.status = 'done'), 0) as done, coalesce(sum(followups.status = 'pending' and followups.scheduled_at < now()), 0) as overdue, coalesce(sum(followups.status = 'pending' and followups.scheduled_at >= now()), 0) as planned");
        $total = (int) ($t->total ?? 0);
        $done = (int) ($t->done ?? 0);

        return [
            'Follow-ups' => number_format($total), 'Done' => number_format($done), 'Planned' => number_format((int) ($t->planned ?? 0)),
            'Overdue' => number_format((int) ($t->overdue ?? 0)), 'Done %' => $total === 0 ? '—' : number_format(100 * $done / $total, 1).' %',
        ];
    }
}
