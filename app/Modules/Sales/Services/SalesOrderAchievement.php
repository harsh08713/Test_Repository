<?php

namespace App\Modules\Sales\Services;

use App\Modules\CRM\Contracts\AchievementSource;
use App\Modules\Masters\Models\ItemUom;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;

/**
 * What a salesperson has achieved, from sales orders — the policy the AchievementSource contract describes, now live:
 *
 *  - only **approved** orders count; a **cancelled** order does not (and an approved order cancelled later stops counting at once);
 *  - an order counts on its **order date**, for the salesperson credited with it (`assigned_to`);
 *  - the value is the lines' amount **net of tax** (after discount, before GST);
 *  - an order counts **in full when approved**, invoiced or not — a partly invoiced order does not count less;
 *  - a quantity counts in each item's **base unit** (the unit's conversion factor is applied);
 *  - the target's item group filters the order *lines* and its branch filters the order's branch;
 *  - **returns and credit notes** do not exist yet; when they do they reduce the period in which they are recorded.
 */
final class SalesOrderAchievement implements AchievementSource
{
    public function achieved(int $userId, string $from, string $to, ?int $itemGroupId, ?int $locationId): array
    {
        $rows = SalesOrderItem::withoutGlobalScopes(Tenancy::SCOPES)
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('items', 'items.id', '=', 'sales_order_items.item_id')
            ->where('sales_orders.company_id', $this->companyId())
            ->where('sales_orders.assigned_to', $userId)
            ->where('sales_orders.approval_status', 'approved')->where('sales_orders.state', '<>', 'cancelled')
            ->whereNull('sales_orders.deleted_at')->whereNull('sales_order_items.deleted_at')
            ->whereBetween('sales_orders.order_date', [$from, $to])
            ->when($locationId !== null, fn ($q) => $q->where('sales_orders.location_id', $locationId))
            ->when($itemGroupId !== null, fn ($q) => $q->where('items.item_group_id', $itemGroupId))
            ->get(['sales_order_items.item_id', 'sales_order_items.unit_id', 'sales_order_items.quantity', 'sales_order_items.taxable_amount', 'items.base_unit_id']);

        $factors = $rows->isEmpty() ? collect() : ItemUom::withoutGlobalScopes(Tenancy::SCOPES)->whereIn('item_id', $rows->pluck('item_id')->unique())
            ->get(['item_id', 'unit_id', 'factor'])->mapWithKeys(fn ($u) => [$u->item_id.':'.$u->unit_id => (string) $u->factor]);

        $value = '0.00';
        $quantity = '0.000';

        foreach ($rows as $row) {
            $value = bcadd($value, (string) $row->taxable_amount, 2);
            $factor = (int) $row->unit_id === (int) $row->getAttribute('base_unit_id') ? '1' : ($factors->get($row->item_id.':'.$row->unit_id) ?? '1');
            $quantity = bcadd($quantity, bcmul((string) $row->quantity, $factor, 6), 3);
        }

        return ['value' => $value, 'quantity' => $quantity];
    }

    public function isLive(): bool
    {
        return true;
    }

    /** The company whose orders are asked about: the one the tenant scope is working in. */
    private function companyId(): int
    {
        return (int) app(TenantContext::class)->activeCompanyId();
    }
}
