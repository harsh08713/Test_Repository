<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * Production orders with what was planned, made and rejected, and the yield. The quantities are summed from the order's output entries — the same entries `ProductionFigures`
 * reads — so this list and the order page cannot disagree (a test holds them equal).
 */
final class ProductionOrderRegister extends Report
{
    public function key(): string
    {
        return 'production-order-register';
    }

    public function title(): string
    {
        return 'Production orders';
    }

    public function description(): string
    {
        return 'Every production order you may see: the item, the branch, the state, what was planned, made and rejected, and the yield.';
    }

    public function group(): string
    {
        return 'Production';
    }

    public function permission(): string
    {
        return 'production_order.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Planned start';
    }

    public function choiceLabel(): string
    {
        return 'State';
    }

    public function choices(): array
    {
        return ProductionOrder::STATES;
    }

    public function note(): string
    {
        return 'Quantities are in the item\'s base unit. Yield is good units ÷ (good + rejected) over everything reported so far; it is blank until something is reported.';
    }

    public function columns(): array
    {
        return [
            new C('order_number', 'Order'), new C('item_name', 'Item'), new C('branch', 'Branch'), new C('planned_start', 'Start', C::DATE), new C('planned_end', 'Finish by', C::DATE),
            new C('state_label', 'State'), new C('planned_text', 'Planned'), new C('good_text', 'Made'), new C('rejected_text', 'Rejected'), new C('yield_text', 'Yield'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = ProductionOrder::query()
            ->leftJoin('locations as l', 'l.id', '=', 'production_orders.location_id')
            ->select(['production_orders.id', 'production_orders.order_number', 'production_orders.item_name', 'production_orders.planned_start', 'production_orders.planned_end', 'production_orders.planned_quantity', 'production_orders.state', 'l.name as branch'])
            ->selectRaw('(select coalesce(sum(o.good_quantity), 0) from production_outputs o where o.production_order_id = production_orders.id) as good')
            ->selectRaw('(select coalesce(sum(o.rejected_quantity), 0) from production_outputs o where o.production_order_id = production_orders.id) as rejected');

        $this->between($query, 'production_orders.planned_start', $input);
        $input->branch === null || $query->where('production_orders.location_id', $input->branch);
        $input->choice === null || $query->where('production_orders.state', $input->choice);

        return $query->orderByDesc('production_orders.planned_start')->orderByDesc('production_orders.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $trim = fn (mixed $v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') ?: '0';
        $good = bcadd((string) $row->getAttribute('good'), '0', 3);
        $rejected = bcadd((string) $row->getAttribute('rejected'), '0', 3);
        $made = bcadd($good, $rejected, 3);

        $out['state_label'] = ProductionOrder::STATES[(string) $row->getRawOriginal('state')] ?? (string) $row->getRawOriginal('state');
        $out['planned_text'] = $trim($row->getAttribute('planned_quantity'));
        $out['good_text'] = $trim($good);
        $out['rejected_text'] = $trim($rejected);
        $out['yield_text'] = bccomp($made, '0', 3) > 0 ? bcadd(bcdiv(bcmul($good, '100', 6), $made, 6), '0.005', 2).'%' : '—';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(production_orders.state in ('released', 'in_progress')), 0) as open_orders, coalesce(sum(production_orders.state = 'completed'), 0) as done");

        return ['Orders' => number_format((int) ($t->total ?? 0)), 'Open' => number_format((int) ($t->open_orders ?? 0)), 'Completed' => number_format((int) ($t->done ?? 0))];
    }
}
