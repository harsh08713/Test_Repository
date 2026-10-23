<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** What stock is on hand and what it is carried at, by item and branch (the stock balances summed over batches). */
final class StockValuation extends Report
{
    public function key(): string
    {
        return 'stock-valuation';
    }

    public function title(): string
    {
        return 'Stock valuation';
    }

    public function description(): string
    {
        return 'Quantity on hand, the average cost and the value of the stock, by item and branch, summed over its batches.';
    }

    public function group(): string
    {
        return 'Inventory';
    }

    public function permission(): string
    {
        return 'stock.view';
    }

    public function filters(): array
    {
        return ['branch'];
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'Valued at moving-average cost where the company uses it. Quantities are in the item\'s base unit. An item with no stock is not listed.';
    }

    public function columns(): array
    {
        return [new C('item_code', 'Code'), new C('item_name', 'Item'), new C('branch', 'Branch'), new C('quantity_text', 'On hand'), new C('unit', 'Unit'), new C('average_cost', 'Average cost', C::MONEY), new C('value', 'Value', C::MONEY)];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = StockBalance::query()
            ->join('items as it', 'it.id', '=', 'stock_balances.item_id')
            ->join('units as u', 'u.id', '=', 'it.base_unit_id')
            ->join('locations as l', 'l.id', '=', 'stock_balances.location_id')
            ->groupBy('stock_balances.item_id', 'stock_balances.location_id', 'it.code', 'it.name', 'u.code', 'l.name')
            ->havingRaw('sum(stock_balances.quantity) <> 0')
            ->select(['stock_balances.item_id', 'stock_balances.location_id', 'it.code as item_code', 'it.name as item_name', 'u.code as unit', 'l.name as branch'])
            ->selectRaw('sum(stock_balances.quantity) as quantity, sum(stock_balances.value) as value, case when sum(stock_balances.quantity) > 0 then sum(stock_balances.value) / sum(stock_balances.quantity) end as average_cost');

        $input->branch === null || $query->where('stock_balances.location_id', $input->branch);

        return $query->orderBy('it.name')->orderBy('stock_balances.item_id')->orderBy('stock_balances.location_id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['quantity_text'] = rtrim(rtrim(number_format((float) $row->getAttribute('quantity'), 3, '.', ''), '0'), '.') ?: '0';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $base = StockBalance::query()->when($input->branch !== null, fn ($q) => $q->where('stock_balances.location_id', $input->branch));
        $t = $this->totals($base, 'coalesce(sum(stock_balances.value), 0) as value, count(distinct stock_balances.item_id) as items');

        return ['Items with stock' => number_format((int) ($t->items ?? 0)), 'Stock value (₹)' => number_format((float) ($t->value ?? 0), 2)];
    }
}
