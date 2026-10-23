<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Loss\Models\RadarFinding;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** What the Profit Leakage Radar noticed: each finding with its estimate, its state, the incident it became (if any) and why it was dismissed (if it was). Estimates, never losses. */
final class RadarFindingRegister extends Report
{
    public function key(): string
    {
        return 'radar-findings';
    }

    public function title(): string
    {
        return 'Leakage radar findings';
    }

    public function description(): string
    {
        return 'Every possible loss the Radar proposed, largest first: what it found, the estimate, whether a person reported it as an incident, dismissed it, or it no longer applies.';
    }

    public function group(): string
    {
        return 'Loss Intelligence';
    }

    public function permission(): string
    {
        return 'radar_finding.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'First seen';
    }

    public function choiceLabel(): string
    {
        return 'Status';
    }

    public function choices(): array
    {
        return ['open' => 'Open (waiting for a person)', 'reported' => 'Reported as an incident', 'dismissed' => 'Dismissed', 'resolved' => 'No longer applies'];
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
        return 'These are estimates by the Radar, worked out by the same loss formulas a person would use. None is a loss figure until it is reported as an incident and its figure is validated; they are never added to the loss reports.';
    }

    public function columns(): array
    {
        return [
            new C('detector', 'Detector'), new C('title', 'Finding'), new C('branch', 'Branch'), new C('severity', 'Severity'), new C('impact_type', 'Kind of figure'),
            new C('estimated_amount', 'Estimate', C::MONEY), new C('state', 'Status'), new C('first_detected_at', 'First seen', C::DATETIME), new C('incident_number', 'Incident'),
            new C('dismiss_reason', 'Why dismissed'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = RadarFinding::query()
            ->join('locations as l', 'l.id', '=', 'radar_findings.location_id')
            ->leftJoin('loss_incidents as i', 'i.id', '=', 'radar_findings.loss_incident_id')
            ->select(['radar_findings.id', 'radar_findings.detector', 'radar_findings.title', 'radar_findings.severity', 'radar_findings.impact_type', 'radar_findings.estimated_amount',
                'radar_findings.state', 'radar_findings.first_detected_at', 'radar_findings.dismiss_reason', 'l.name as branch', 'i.incident_number']);

        $this->between($query, 'radar_findings.first_detected_at', $input, true);
        $input->branch === null || $query->where('radar_findings.location_id', $input->branch);
        in_array($input->choice, array_keys($this->choices()), true) && $query->where('radar_findings.state', $input->choice);   // nothing asked, nothing narrowed

        return $query->orderByRaw("field(radar_findings.severity, 'high', 'medium', 'low')")->orderByDesc('radar_findings.estimated_amount')->orderBy('radar_findings.id');
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(*) as total, coalesce(sum(radar_findings.estimated_amount), 0) as estimate');

        return ['Findings' => number_format((int) ($t->total ?? 0)), 'Estimated (₹, not a loss)' => number_format((float) ($t->estimate ?? 0), 2)];
    }
}
