<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Corrective and preventive actions: who owns each, when it is due, whether it is overdue, and the saving a second person verified. */
final class CorrectiveActionRegister extends Report
{
    public function key(): string
    {
        return 'corrective-action-register';
    }

    public function title(): string
    {
        return 'Corrective and preventive actions';
    }

    public function description(): string
    {
        return 'Every action with its incident, owner, due date, state and verified saving. Starts on the ones that are open or past their date.';
    }

    public function group(): string
    {
        return 'Loss Intelligence';
    }

    public function permission(): string
    {
        return 'corrective_action.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Due';
    }

    public function choiceLabel(): string
    {
        return 'Show';
    }

    public function choices(): array
    {
        return ['overdue' => 'Overdue (open or in progress, past the due date)', 'live' => 'Open, in progress or done and waiting to be verified', 'verified' => 'Verified', 'rejected' => 'Rejected'];
    }

    public function defaultChoice(): string
    {
        return 'overdue';
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'The saving is the result a second person verified; an action that is not verified has no saving, however large the expected benefit.';
    }

    public function columns(): array
    {
        return [
            new C('action_number', 'Action'), new C('incident_number', 'Incident'), new C('action_type', 'Kind'), new C('title', 'What'), new C('owner', 'Owner'), new C('due_date', 'Due', C::DATE),
            new C('state', 'State'), new C('days_overdue', 'Days overdue', C::NUMBER), new C('expected_benefit_amount', 'Expected benefit', C::MONEY), new C('verified_result_amount', 'Verified saving', C::MONEY), new C('verified_at', 'Verified on', C::DATETIME),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = CorrectiveAction::query()
            ->join('loss_incidents as i', 'i.id', '=', 'corrective_actions.loss_incident_id')
            ->leftJoin('users as o', 'o.id', '=', 'corrective_actions.owner_id')
            ->select(['corrective_actions.id', 'corrective_actions.action_number', 'corrective_actions.action_type', 'corrective_actions.title', 'corrective_actions.due_date', 'corrective_actions.state',
                'corrective_actions.expected_benefit_amount', 'corrective_actions.verified_result_amount', 'corrective_actions.verified_at', 'i.incident_number', 'o.name as owner'])
            ->selectRaw("case when corrective_actions.state in ('open','in_progress') and corrective_actions.due_date < ? then datediff(?, corrective_actions.due_date) end as days_overdue", [now()->toDateString(), now()->toDateString()]);

        $this->between($query, 'corrective_actions.due_date', $input);
        $input->branch === null || $query->where('corrective_actions.location_id', $input->branch);

        match ($input->choice) {
            'overdue' => $query->whereIn('corrective_actions.state', ['open', 'in_progress'])->where('corrective_actions.due_date', '<', now()->toDateString()),
            'live' => $query->whereIn('corrective_actions.state', ['open', 'in_progress', 'done']),
            'verified' => $query->where('corrective_actions.state', 'verified'),
            'rejected' => $query->where('corrective_actions.state', 'rejected'),
            default => null,
        };

        return $query->orderBy('corrective_actions.due_date')->orderBy('corrective_actions.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['state'] = (string) $row->getRawOriginal('state');
        $out['action_type'] = (string) $row->getRawOriginal('action_type');

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $today = now()->toDateString();
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(corrective_actions.state in ('open','in_progress') and corrective_actions.due_date < '{$today}'), 0) as overdue, coalesce(sum(corrective_actions.verified_result_amount), 0) as saved");

        return ['Actions' => number_format((int) ($t->total ?? 0)), 'Overdue' => number_format((int) ($t->overdue ?? 0)), 'Verified saving (₹)' => number_format((float) ($t->saved ?? 0), 2)];
    }
}
