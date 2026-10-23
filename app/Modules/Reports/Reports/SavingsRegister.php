<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Loss\Models\SavingsMeasurement;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Savings measurements: what each corrective action saved by the loss records, who verified it in Finance, and whether the loss has come back. */
final class SavingsRegister extends Report
{
    public function key(): string
    {
        return 'verified-savings';
    }

    public function title(): string
    {
        return 'Verified savings';
    }

    public function description(): string
    {
        return 'Each corrective action’s measured saving: the loss before and after, scaled for volume or days, the cost, the net saving, and its Finance decision. Starts on the ones Finance has verified.';
    }

    public function group(): string
    {
        return 'Loss Intelligence';
    }

    public function permission(): string
    {
        return 'savings_measurement.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Window ends';
    }

    public function choiceLabel(): string
    {
        return 'Status';
    }

    public function choices(): array
    {
        return ['verified' => 'Finance-verified', 'submitted' => 'Waiting for Finance', 'draft' => 'Drafts', 'rejected' => 'Rejected', 'recurred' => 'Verified, but the loss has come back'];
    }

    public function defaultChoice(): string
    {
        return 'verified';
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'Only a measurement Finance verified counts as a saving. It is separate from the “verified saving” of a corrective action in the action register, and the two are never added.';
    }

    public function columns(): array
    {
        return [
            new C('action_number', 'Action'), new C('category', 'Loss'), new C('branch', 'Branch'), new C('measure_from', 'Window from', C::DATE), new C('measure_to', 'Window to', C::DATE),
            new C('baseline_loss', 'Baseline loss', C::MONEY), new C('normalised_baseline', 'Baseline scaled', C::MONEY), new C('measure_loss', 'Loss after', C::MONEY),
            new C('implementation_cost', 'Cost', C::MONEY), new C('net_saving', 'Net saving', C::MONEY), new C('state', 'Status'), new C('decided_at', 'Decided', C::DATETIME), new C('recurred_at', 'Recurred', C::DATETIME),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = SavingsMeasurement::query()
            ->join('corrective_actions as a', 'a.id', '=', 'savings_measurements.corrective_action_id')
            ->join('loss_categories as c', 'c.id', '=', 'savings_measurements.loss_category_id')
            ->join('locations as l', 'l.id', '=', 'savings_measurements.location_id')
            ->select(['savings_measurements.id', 'savings_measurements.measure_from', 'savings_measurements.measure_to', 'savings_measurements.baseline_loss', 'savings_measurements.normalised_baseline',
                'savings_measurements.measure_loss', 'savings_measurements.implementation_cost', 'savings_measurements.net_saving', 'savings_measurements.state', 'savings_measurements.decided_at',
                'savings_measurements.recurred_at', 'a.action_number', 'c.name as category', 'l.name as branch']);

        $this->between($query, 'savings_measurements.measure_to', $input);
        $input->branch === null || $query->where('savings_measurements.location_id', $input->branch);

        match ($input->choice) {
            'recurred' => $query->where('savings_measurements.state', 'verified')->whereNotNull('savings_measurements.recurred_at'),
            'verified', 'submitted', 'draft', 'rejected' => $query->where('savings_measurements.state', $input->choice),
            default => null,
        };

        return $query->orderByDesc('savings_measurements.id');
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(case when savings_measurements.state = 'verified' then savings_measurements.net_saving end), 0) as verified, coalesce(sum(savings_measurements.state = 'verified' and savings_measurements.recurred_at is not null), 0) as recurred");

        return ['Measurements' => number_format((int) ($t->total ?? 0)), 'Finance-verified net saving (₹)' => number_format((float) ($t->verified ?? 0), 2), 'Recurred' => number_format((int) ($t->recurred ?? 0))];
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['state'] = (string) $row->getRawOriginal('state');

        return $out;
    }
}
