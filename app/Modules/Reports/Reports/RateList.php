<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Masters\Models\Rate;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** The rate master by branch, price list and period. */
final class RateList extends Report
{
    public function key(): string
    {
        return 'rate-list';
    }

    public function title(): string
    {
        return 'Rate list by branch';
    }

    public function description(): string
    {
        return 'Rates for the branches you work in, by item, unit and price list, with the period each applies to. Starts on the rates in force today.';
    }

    public function group(): string
    {
        return 'Masters';
    }

    public function permission(): string
    {
        return 'rate.view';
    }

    public function filters(): array
    {
        return ['branch', 'choice'];
    }

    public function choiceLabel(): string
    {
        return 'Show';
    }

    public function choices(): array
    {
        return ['current' => 'In force today (approved and active)', 'upcoming' => 'Starting later', 'expired' => 'Ended', 'pending' => 'Waiting for approval', 'draft' => 'Draft or rejected'];
    }

    public function defaultChoice(): string
    {
        return 'current';
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            new C('item_code', 'Item code'), new C('item_name', 'Item'), new C('unit', 'Unit'), new C('branch', 'Branch'), new C('price_list', 'Price list'),
            new C('rate', 'Rate', C::MONEY), new C('effective_from', 'From', C::DATE), new C('effective_to', 'To', C::DATE), new C('approval', 'Approval'), new C('active', 'Active'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $today = now()->toDateString();

        $query = Rate::query()
            ->join('items', 'items.id', '=', 'rates.item_id')
            ->leftJoin('units', 'units.id', '=', 'rates.unit_id')
            ->leftJoin('locations', 'locations.id', '=', 'rates.location_id')
            ->leftJoin('price_lists', 'price_lists.id', '=', 'rates.price_list_id')
            ->select([
                'rates.id', 'rates.rate', 'rates.effective_from', 'rates.effective_to', 'rates.approval_status', 'rates.status',
                'items.code as item_code', 'items.name as item_name', 'units.name as unit', 'locations.name as branch',
            ])
            ->selectRaw("coalesce(price_lists.name, 'Standard') as price_list");

        $input->branch === null || $query->where('rates.location_id', $input->branch);

        match ($input->choice) {
            'current' => $query->where('rates.approval_status', 'approved')->where('rates.status', 'active')->where('rates.effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('rates.effective_to')->orWhere('rates.effective_to', '>=', $today)),
            'upcoming' => $query->where('rates.approval_status', 'approved')->where('rates.effective_from', '>', $today),
            'expired' => $query->where('rates.effective_to', '<', $today),
            'pending' => $query->where('rates.approval_status', 'pending'),
            'draft' => $query->whereIn('rates.approval_status', ['draft', 'rejected']),
            default => null,
        };

        return $query->orderBy('items.code')->orderBy('locations.name')->orderBy('rates.effective_from')->orderBy('rates.id');
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
        $t = $this->totals($this->query($input, $user), 'count(*) as total, count(distinct rates.item_id) as items');

        return ['Rates' => number_format((int) ($t->total ?? 0)), 'Items priced' => number_format((int) ($t->items ?? 0))];
    }
}
