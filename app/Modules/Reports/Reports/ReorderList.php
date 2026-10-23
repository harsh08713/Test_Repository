<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Procurement\Services\ReorderSuggestions;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use stdClass;

/** What to order now: items whose stock (counting what is still due) is at or below the reorder level, with the quantity to bring it up to the maximum and the supplier last used. ReorderSuggestions' own figures; nothing is ordered. */
final class ReorderList extends Report
{
    public function key(): string
    {
        return 'reorder-list';
    }

    public function title(): string
    {
        return 'Reorder list';
    }

    public function description(): string
    {
        return 'Items and branches to order for: stock on hand and on order, the reorder level, the suggested quantity and the supplier last used with its lead time.';
    }

    public function group(): string
    {
        return 'Procurement';
    }

    public function permission(): string
    {
        return 'purchase_order.view';
    }

    public function filters(): array
    {
        return ['branch'];
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            new C('item_code', 'Code'), new C('item_name', 'Item'), new C('location_name', 'Branch'), new C('on_hand', 'On hand'), new C('on_order', 'On order'), new C('reorder_level', 'Reorder level'),
            new C('suggested', 'Suggested'), new C('supplier_name', 'Supplier'), new C('lead_time_days', 'Lead time (days)', C::NUMBER), new C('arrives_by', 'Arrives by', C::DATE),
        ];
    }

    /** @return Collection<int, stdClass> */
    public function collection(ReportInput $input, User $user): Collection
    {
        $company = (int) app(TenantContext::class)->activeCompanyId();
        $trim = fn (string $q) => rtrim(rtrim($q, '0'), '.') ?: '0';

        return collect(app(ReorderSuggestions::class)->forCompany($company, $input->branch))->map(fn (array $r): stdClass => $this->asRow([
            ...$r, 'on_hand' => $trim($r['on_hand']), 'on_order' => $trim($r['on_order']), 'reorder_level' => $trim($r['reorder_level']), 'suggested' => $trim($r['suggested']),
        ]))->values();
    }

    public function summary(ReportInput $input, User $user): array
    {
        return ['Items to order' => number_format($this->collection($input, $user)->count())];
    }
}
