<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Workspace\Enums\SlaKind;
use App\Modules\Workspace\Models\SlaClock;
use Illuminate\Database\Eloquent\Builder;

/** For each SLA policy: how many things it timed, how many were answered in time, answered late, are still running or ran out unanswered. */
final class SlaCompliance extends Report
{
    public function key(): string
    {
        return 'sla-compliance';
    }

    public function title(): string
    {
        return 'SLA compliance';
    }

    public function description(): string
    {
        return 'For each policy: things timed, answered on time, answered late, still running and past their time — and the share on time.';
    }

    public function group(): string
    {
        return 'Control';
    }

    public function permission(): string
    {
        return 'sla_policy.view';
    }

    public function filters(): array
    {
        return ['date', 'person', 'team', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Clock started';
    }

    public function choiceLabel(): string
    {
        return 'Kind';
    }

    public function choices(): array
    {
        return SlaKind::options();
    }

    public function note(): string
    {
        return 'On time % = answered on time ÷ (answered + past their time). Clocks still running within their time, and clocks stopped because the lead was closed, handed over or the follow-up or task was called off or moved, are not counted either way.';
    }

    public function columns(): array
    {
        return [
            new C('policy', 'Policy'), new C('kind_label', 'Limits'), new C('timed', 'Timed', C::NUMBER), new C('on_time', 'Answered on time', C::NUMBER), new C('late', 'Answered late', C::NUMBER),
            new C('open_late', 'Past their time, not answered', C::NUMBER), new C('running', 'Running within time', C::NUMBER), new C('stopped', 'Stopped', C::NUMBER), new C('on_time_percent', 'On time %', C::PERCENT),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $visible = app(SalesScope::class)->visibleUserIds($user);

        $query = SlaClock::query()
            ->join('sla_policies', 'sla_policies.id', '=', 'sla_clocks.sla_policy_id')
            ->selectRaw('sla_policies.id as policy_id, sla_policies.name as policy, sla_clocks.kind as kind')
            ->selectRaw('count(*) as timed')
            ->selectRaw("sum(sla_clocks.state = 'met' and sla_clocks.breached_at is null) as on_time")
            ->selectRaw("sum(sla_clocks.state = 'met' and sla_clocks.breached_at is not null) as late")
            ->selectRaw("sum(sla_clocks.state = 'running' and sla_clocks.breached_at is not null) as open_late")
            ->selectRaw("sum(sla_clocks.state = 'running' and sla_clocks.breached_at is null) as running")
            ->selectRaw("sum(sla_clocks.state = 'stopped') as stopped")
            ->selectRaw("round(100 * sum(sla_clocks.state = 'met' and sla_clocks.breached_at is null) / nullif(sum(sla_clocks.state = 'met') + sum(sla_clocks.state = 'running' and sla_clocks.breached_at is not null), 0), 1) as on_time_percent")
            ->groupBy('sla_policies.id', 'sla_policies.name', 'sla_clocks.kind');

        $visible === null || $query->whereIn('sla_clocks.owner_id', $visible);
        $this->between($query, 'sla_clocks.started_at', $input, true);
        $input->peopleIds === null || $query->whereIn('sla_clocks.owner_id', $input->peopleIds);
        $input->choice === null || $query->where('sla_clocks.kind', $input->choice);

        return $query->orderBy('sla_clocks.kind')->orderBy('sla_policies.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['kind_label'] = SlaKind::tryFrom((string) $row->getRawOriginal('kind'))?->short();

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals(
            $this->query($input, $user),
            "count(*) as timed, coalesce(sum(sla_clocks.state = 'met' and sla_clocks.breached_at is null), 0) as on_time, coalesce(sum(sla_clocks.state = 'met' and sla_clocks.breached_at is not null), 0) as late, coalesce(sum(sla_clocks.state = 'running' and sla_clocks.breached_at is not null), 0) as open_late",
        );
        $measured = (int) ($t->on_time ?? 0) + (int) ($t->late ?? 0) + (int) ($t->open_late ?? 0);

        return [
            'Things timed' => number_format((int) ($t->timed ?? 0)), 'On time' => number_format((int) ($t->on_time ?? 0)),
            'Missed' => number_format((int) ($t->late ?? 0) + (int) ($t->open_late ?? 0)),
            'On time %' => $measured === 0 ? '—' : number_format(100 * (int) $t->on_time / $measured, 1).' %',
        ];
    }
}
