<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Quality\Models\NonConformance;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Non-conformance reports with severity, what was decided, and the loss incident and corrective action they are linked to. */
final class NonConformanceRegister extends Report
{
    public function key(): string
    {
        return 'ncr-register';
    }

    public function title(): string
    {
        return 'Non-conformances';
    }

    public function description(): string
    {
        return 'Every non-conformance report you may see: the item and batch, how serious, what was decided about the goods, and the loss incident and corrective action it led to.';
    }

    public function group(): string
    {
        return 'Quality';
    }

    public function permission(): string
    {
        return 'non_conformance.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Raised';
    }

    public function choiceLabel(): string
    {
        return 'State';
    }

    public function choices(): array
    {
        return NonConformance::STATES;
    }

    public function note(): string
    {
        return 'Quantities are in the item\'s base unit. A critical report cannot be closed without a corrective action.';
    }

    public function columns(): array
    {
        return [
            new C('ncr_number', 'Report'), new C('created_at', 'Raised', C::DATE), new C('item_name', 'Item'), new C('batch_no', 'Batch'), new C('branch', 'Branch'), new C('quantity_text', 'Quantity'),
            new C('severity_label', 'Severity'), new C('state_label', 'State'), new C('disposition_label', 'Decision'), new C('incident_number', 'Loss incident'), new C('action_number', 'Corrective action'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = NonConformance::query()
            ->leftJoin('locations as l', 'l.id', '=', 'non_conformances.location_id')
            ->leftJoin('batches as b', 'b.id', '=', 'non_conformances.batch_id')
            ->leftJoin('loss_incidents as li', 'li.id', '=', 'non_conformances.loss_incident_id')
            ->leftJoin('corrective_actions as ca', 'ca.id', '=', 'non_conformances.corrective_action_id')
            ->select(['non_conformances.id', 'non_conformances.ncr_number', 'non_conformances.created_at', 'non_conformances.item_name', 'non_conformances.quantity_affected', 'non_conformances.severity', 'non_conformances.state', 'non_conformances.disposition', 'b.batch_no', 'l.name as branch', 'li.incident_number', 'ca.action_number']);

        $this->between($query, 'non_conformances.created_at', $input);
        $input->branch === null || $query->where('non_conformances.location_id', $input->branch);
        $input->choice === null || $query->where('non_conformances.state', $input->choice);

        return $query->orderByDesc('non_conformances.created_at')->orderByDesc('non_conformances.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['severity_label'] = NonConformance::SEVERITIES[(string) $row->getRawOriginal('severity')] ?? (string) $row->getRawOriginal('severity');
        $out['state_label'] = NonConformance::STATES[(string) $row->getRawOriginal('state')] ?? (string) $row->getRawOriginal('state');
        $out['disposition_label'] = NonConformance::DISPOSITIONS[(string) $row->getRawOriginal('disposition')] ?? '—';
        $out['quantity_text'] = rtrim(rtrim(number_format((float) $row->getAttribute('quantity_affected'), 3, '.', ''), '0'), '.') ?: '0';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(non_conformances.state <> 'closed'), 0) as open_reports, coalesce(sum(non_conformances.severity = 'critical'), 0) as critical, coalesce(sum(non_conformances.state <> 'closed' and non_conformances.corrective_action_id is null), 0) as no_action");

        return ['Reports' => number_format((int) ($t->total ?? 0)), 'Not closed' => number_format((int) ($t->open_reports ?? 0)), 'Critical' => number_format((int) ($t->critical ?? 0)), 'Not closed and without a corrective action' => number_format((int) ($t->no_action ?? 0))];
    }
}
