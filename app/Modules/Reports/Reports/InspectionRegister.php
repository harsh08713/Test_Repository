<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Inspections with the lot, the batch, how many checks failed and how it ended. Read from the inspections and their results — the same rows the inspection page shows. */
final class InspectionRegister extends Report
{
    public function key(): string
    {
        return 'inspection-register';
    }

    public function title(): string
    {
        return 'Inspections';
    }

    public function description(): string
    {
        return 'Every inspection you may see: the item, the batch, the stage, the size of the lot, how many checks failed, and whether the lot was accepted, rejected or accepted with a deviation.';
    }

    public function group(): string
    {
        return 'Quality';
    }

    public function permission(): string
    {
        return 'inspection.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Started';
    }

    public function choiceLabel(): string
    {
        return 'State';
    }

    public function choices(): array
    {
        return Inspection::STATES;
    }

    public function note(): string
    {
        return 'Quantities are in the item\'s base unit. "Failed checks" counts the checks outside their limits; a lot accepted with a deviation had at least one.';
    }

    public function columns(): array
    {
        return [
            new C('inspection_number', 'Inspection'), new C('created_at', 'Started', C::DATE), new C('item_name', 'Item'), new C('batch_no', 'Batch'), new C('branch', 'Branch'),
            new C('stage_label', 'Stage'), new C('lot_text', 'Lot'), new C('failed_checks', 'Failed checks', C::NUMBER), new C('state_label', 'State'), new C('inspector', 'Inspector'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = Inspection::query()
            ->leftJoin('locations as l', 'l.id', '=', 'inspections.location_id')
            ->leftJoin('batches as b', 'b.id', '=', 'inspections.batch_id')
            ->leftJoin('users as u', 'u.id', '=', 'inspections.inspector_id')
            ->select(['inspections.id', 'inspections.inspection_number', 'inspections.created_at', 'inspections.item_name', 'inspections.stage', 'inspections.lot_quantity', 'inspections.state', 'b.batch_no', 'l.name as branch', 'u.name as inspector'])
            ->selectRaw('(select count(*) from inspection_results r where r.inspection_id = inspections.id and r.passed = 0) as failed_checks');

        $this->between($query, 'inspections.created_at', $input);
        $input->branch === null || $query->where('inspections.location_id', $input->branch);
        $input->choice === null || $query->where('inspections.state', $input->choice);

        return $query->orderByDesc('inspections.created_at')->orderByDesc('inspections.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['stage_label'] = ['incoming' => 'Incoming goods', 'in_process' => 'In process', 'final' => 'Final'][(string) $row->getRawOriginal('stage')] ?? (string) $row->getRawOriginal('stage');
        $out['state_label'] = Inspection::STATES[(string) $row->getRawOriginal('state')] ?? (string) $row->getRawOriginal('state');
        $out['lot_text'] = rtrim(rtrim(number_format((float) $row->getAttribute('lot_quantity'), 3, '.', ''), '0'), '.') ?: '0';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(inspections.state in ('accepted', 'deviation')), 0) as accepted, coalesce(sum(inspections.state = 'rejected'), 0) as rejected, coalesce(sum(inspections.state in ('open', 'failed')), 0) as waiting");

        return ['Inspections' => number_format((int) ($t->total ?? 0)), 'Accepted' => number_format((int) ($t->accepted ?? 0)), 'Rejected' => number_format((int) ($t->rejected ?? 0)), 'In progress or waiting' => number_format((int) ($t->waiting ?? 0))];
    }
}
