<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Services\LossFigures;
use App\Modules\Reports\Reports\Concerns\ResolvesSpan;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every loss incident you may see with its **four values kept apart** (actual, exposure, opportunity are never added). Figures follow LossFigures exactly: a duplicate or a
 * rejected incident counts for nothing (shown blank), only a validated, current line is actual loss, a superseded line counts for nothing. The summary above the table is
 * LossFigures itself, so it cannot disagree with the dashboard.
 */
final class LossIncidentRegister extends Report
{
    use ResolvesSpan;

    public function key(): string
    {
        return 'loss-incident-register';
    }

    public function title(): string
    {
        return 'Loss incident register';
    }

    public function description(): string
    {
        return 'Every loss incident with its industry, category, branch, severity and state, and its actual loss, exposure and opportunity side by side — never added together.';
    }

    public function group(): string
    {
        return 'Loss Intelligence';
    }

    public function permission(): string
    {
        return 'loss_incident.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Occurred';
    }

    public function choiceLabel(): string
    {
        return 'Show';
    }

    public function choices(): array
    {
        return ['counted' => 'Counted (not a duplicate, not rejected)', 'duplicates' => 'Duplicates of another incident', 'rejected' => 'Rejected'];
    }

    public function note(): string
    {
        return 'Actual loss is only what a second person has validated. Exposure and opportunity are estimates and are never part of the loss. Duplicates and rejected incidents count for nothing.';
    }

    public function columns(): array
    {
        return [
            new C('incident_number', 'Incident'), new C('occurred_at', 'Occurred', C::DATETIME), new C('industry', 'Industry'), new C('category', 'Category'), new C('branch', 'Branch'),
            new C('severity', 'Severity'), new C('state', 'State'), new C('title', 'Title'), new C('item', 'Item'),
            new C('actual_loss', 'Actual loss', C::MONEY), new C('estimated_exposure', 'Exposure', C::MONEY), new C('opportunity', 'Opportunity', C::MONEY), new C('counted', 'Counted'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $counts = "loss_incidents.primary_incident_id is null and loss_incidents.state <> 'rejected'";
        $sum = fn (string $type, string $validation) => "case when {$counts} then (select coalesce(sum(l.amount), 0) from loss_impact_lines l where l.loss_incident_id = loss_incidents.id and l.impact_type = '{$type}' and {$validation} and l.superseded_at is null) end";

        $query = LossIncident::query()
            ->leftJoin('industry_templates as t', 't.id', '=', 'loss_incidents.industry_template_id')
            ->leftJoin('loss_categories as c', 'c.id', '=', 'loss_incidents.loss_category_id')
            ->leftJoin('locations as b', 'b.id', '=', 'loss_incidents.location_id')
            ->leftJoin('items as it', 'it.id', '=', 'loss_incidents.item_id')
            ->select(['loss_incidents.id', 'loss_incidents.incident_number', 'loss_incidents.occurred_at', 'loss_incidents.severity', 'loss_incidents.state', 'loss_incidents.title',
                'loss_incidents.primary_incident_id', 't.name as industry', 'c.name as category', 'b.name as branch', 'it.name as item'])
            ->selectRaw($sum('actual', "l.validation_state = 'validated'").' as actual_loss')
            ->selectRaw($sum('exposure', "l.validation_state <> 'rejected'").' as estimated_exposure')
            ->selectRaw($sum('opportunity', "l.validation_state <> 'rejected'").' as opportunity');

        $this->between($query, 'loss_incidents.occurred_at', $input, true);
        $input->branch === null || $query->where('loss_incidents.location_id', $input->branch);

        match ($input->choice) {
            'counted' => $query->whereNull('loss_incidents.primary_incident_id')->where('loss_incidents.state', '<>', 'rejected'),
            'duplicates' => $query->whereNotNull('loss_incidents.primary_incident_id'),
            'rejected' => $query->where('loss_incidents.state', 'rejected'),
            default => null,
        };

        return $query->orderByDesc('loss_incidents.occurred_at')->orderByDesc('loss_incidents.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['state'] = (string) $row->getRawOriginal('state');
        $out['severity'] = (string) $row->getRawOriginal('severity');
        $out['counted'] = $row->getAttribute('primary_incident_id') === null && $out['state'] !== 'rejected' ? 'Yes' : 'No';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        [$from, $to] = $this->span($input);
        $f = app(LossFigures::class)->forPeriod($from, $to, null, $input->branch);

        return ['Incidents counted' => number_format($f['incidents']), 'Actual loss (₹)' => number_format((float) $f['actual_loss'], 2), 'Exposure (₹)' => number_format((float) $f['estimated_exposure'], 2), 'Opportunity (₹)' => number_format((float) $f['recoverable_opportunity'], 2)];
    }
}
