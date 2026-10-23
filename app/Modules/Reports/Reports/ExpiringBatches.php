<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\StockQuery;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock on hand whose batch has expired or is about to, with the value at risk. The value at risk is `StockQuery::expiryExposure`'s own figure (FORMULAS.md `expiry_exposure`) for the
 * same window, so this list adds up to what the expiry watch and the industry KPI say.
 */
final class ExpiringBatches extends Report
{
    public function key(): string
    {
        return 'expiring-batches';
    }

    public function title(): string
    {
        return 'Expired and expiring stock';
    }

    public function description(): string
    {
        return 'Every batch with stock on hand that has expired or expires within the window you choose, by branch, with its quantity and value at risk.';
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
        return ['branch', 'choice'];
    }

    public function choiceLabel(): string
    {
        return 'Expires';
    }

    public function choices(): array
    {
        return ['expired' => 'Already expired', '30' => 'Expired or within 30 days', '60' => 'Expired or within 60 days', '90' => 'Expired or within 90 days', '180' => 'Expired or within 180 days'];
    }

    public function defaultChoice(): string
    {
        return '30';
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'Quantities are in the item\'s base unit. Value is what the stock is carried at (moving average). "Days left" below zero means it has already expired.';
    }

    public function columns(): array
    {
        return [
            new C('item_code', 'Code'), new C('item_name', 'Item'), new C('batch_no', 'Batch'), new C('branch', 'Branch'), new C('expiry_date', 'Expires', C::DATE),
            new C('days_left', 'Days left', C::NUMBER), new C('quantity_text', 'Quantity'), new C('unit', 'Unit'), new C('value', 'Value', C::MONEY),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $today = now()->toDateString();
        $query = StockBalance::query()->where('stock_balances.quantity', '>', 0)
            ->join('batches as b', fn ($j) => $j->on('b.id', '=', 'stock_balances.batch_id')->whereNull('b.deleted_at'))
            ->join('items as it', 'it.id', '=', 'stock_balances.item_id')
            ->join('units as u', 'u.id', '=', 'it.base_unit_id')
            ->join('locations as l', 'l.id', '=', 'stock_balances.location_id')
            ->whereNotNull('b.expiry_date')
            ->select(['stock_balances.id', 'stock_balances.quantity', 'stock_balances.value', 'b.batch_no', 'b.expiry_date', 'it.code as item_code', 'it.name as item_name', 'u.code as unit', 'l.name as branch'])
            ->selectRaw('datediff(b.expiry_date, ?) as days_left', [$today]);

        $input->branch === null || $query->where('stock_balances.location_id', $input->branch);

        if ($input->choice === 'expired') {
            $query->where('b.expiry_date', '<', $today);
        } else {
            $query->where('b.expiry_date', '<=', now()->addDays((int) ($input->choice ?? $this->defaultChoice()))->toDateString());
        }

        return $query->orderBy('b.expiry_date')->orderBy('stock_balances.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['quantity_text'] = rtrim(rtrim(number_format((float) $row->getRawOriginal('quantity'), 3, '.', ''), '0'), '.') ?: '0';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(*) as batches, coalesce(sum(stock_balances.value), 0) as value');
        $days = $input->choice === 'expired' ? 0 : (int) ($input->choice ?? $this->defaultChoice());
        $exposure = app(StockQuery::class)->expiryExposure($days, $input->branch);

        return ['Batches' => number_format((int) ($t->batches ?? 0)), 'Value at risk (₹)' => number_format((float) ($t->value ?? 0), 2), 'Expiry exposure for this window (₹)' => number_format((float) $exposure, 2)];
    }
}
