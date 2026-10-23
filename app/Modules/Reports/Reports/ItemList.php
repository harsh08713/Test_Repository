<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Masters\Models\Item;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/** The item master with its stock parameters by branch (minimum, reorder level, maximum). */
final class ItemList extends Report
{
    public function key(): string
    {
        return 'item-list';
    }

    public function title(): string
    {
        return 'Item list with stock parameters';
    }

    public function description(): string
    {
        return 'Items with their group, unit, HSN and GST rate, and the minimum, reorder and maximum stock set for each branch.';
    }

    public function group(): string
    {
        return 'Masters';
    }

    public function permission(): string
    {
        return 'item.view';
    }

    public function filters(): array
    {
        return ['branch', 'choice'];
    }

    public function choiceLabel(): string
    {
        return 'Approval';
    }

    public function choices(): array
    {
        return ['approved' => 'Approved', 'pending' => 'Waiting for approval', 'draft' => 'Draft', 'rejected' => 'Rejected'];
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'An item with stock parameters in several branches has one row for each branch; an item with none has one row with the branch columns empty.';
    }

    public function columns(): array
    {
        return [
            new C('code', 'Code'), new C('name', 'Item'), new C('item_group', 'Group'), new C('unit', 'Base unit'), new C('hsn_code', 'HSN'), new C('gst_rate', 'GST %', C::PERCENT),
            new C('approval', 'Approval'), new C('active', 'Active'), new C('branch', 'Branch'),
            new C('minimum_stock', 'Minimum', C::NUMBER), new C('reorder_level', 'Reorder level', C::NUMBER), new C('maximum_stock', 'Maximum', C::NUMBER),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $reach = app(TenantContext::class)->accessibleLocationIds();

        $query = Item::query()
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->leftJoin('units', 'units.id', '=', 'items.base_unit_id')
            ->leftJoin('item_location_parameters as p', function ($j) use ($input, $reach) {
                $j->on('p.item_id', '=', 'items.id')->whereNull('p.deleted_at');
                $input->branch === null || $j->where('p.location_id', '=', $input->branch);
                $reach === null || $j->whereIn('p.location_id', $reach);
            })
            ->leftJoin('locations', 'locations.id', '=', 'p.location_id')
            ->select([
                'items.id', 'items.code', 'items.name', 'items.hsn_code', 'items.gst_rate', 'items.approval_status', 'items.status',
                'item_groups.name as item_group', 'units.name as unit', 'locations.name as branch', 'p.minimum_stock', 'p.reorder_level', 'p.maximum_stock',
            ])
            ->selectRaw('p.id as parameter_id');

        $input->choice === null || $query->where('items.approval_status', $input->choice);

        return $query->orderBy('items.code')->orderBy('items.id')->orderBy('p.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['approval'] = ucfirst((string) $row->getRawOriginal('approval_status'));
        $out['active'] = $row->getRawOriginal('status') === 'active' ? 'Yes' : 'No';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(distinct items.id) as items, count(p.id) as parameters');

        return ['Items' => number_format((int) ($t->items ?? 0)), 'Branch stock settings' => number_format((int) ($t->parameters ?? 0))];
    }
}
