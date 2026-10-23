<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Masters\Models\Ledger;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * What to order: for every item and branch with stock levels set, the ones whose stock on hand (plus what is still due on approved orders) is at or
 * below the reorder level. The suggested quantity brings stock up to the maximum; the supplier is the one the item was last ordered from, and the
 * order-by date allows for that supplier's lead time. It only suggests: nothing is ordered.
 */
final class ReorderSuggestions
{
    /**
     * @return list<array{item_id: int, item_code: string|null, item_name: string, location_id: int, location_name: string, on_hand: string, on_order: string, reorder_level: string, maximum_stock: string, suggested: string, supplier_id: int|null, supplier_name: string|null, lead_time_days: int|null, arrives_by: string|null}>
     */
    public function forCompany(int $companyId, ?int $locationId = null): array
    {
        $params = DB::table('item_location_parameters as p')
            ->join('items as i', 'i.id', '=', 'p.item_id')->join('locations as l', 'l.id', '=', 'p.location_id')
            ->where('p.company_id', $companyId)->whereNull('p.deleted_at')->whereNull('i.deleted_at')
            ->where('i.approval_status', 'approved')->where('i.status', 'active')
            ->when($locationId !== null, fn ($q) => $q->where('p.location_id', $locationId))
            ->orderBy('i.name')->orderBy('l.name')
            ->get(['p.item_id', 'p.location_id', 'p.reorder_level', 'p.maximum_stock', 'i.name as item_name', 'i.code as item_code', 'l.name as location_name']);

        $rows = [];

        foreach ($params as $p) {
            $onHand = bcadd((string) DB::table('stock_balances')->where('company_id', $companyId)->where('item_id', $p->item_id)->where('location_id', $p->location_id)->sum('quantity'), '0', 3);
            $onOrder = $this->onOrder($companyId, (int) $p->item_id, (int) $p->location_id);

            if (bccomp(bcadd($onHand, $onOrder, 3), (string) $p->reorder_level, 3) > 0) {
                continue;   // enough, counting what is coming
            }

            $suggested = bcsub((string) $p->maximum_stock, bcadd($onHand, $onOrder, 3), 3);

            if (bccomp($suggested, '0', 3) <= 0) {
                continue;
            }

            $supplier = $this->lastSupplier($companyId, (int) $p->item_id);
            $lead = $supplier?->lead_time_days;

            $rows[] = [
                'item_id' => (int) $p->item_id, 'item_code' => $p->item_code, 'item_name' => (string) $p->item_name,
                'location_id' => (int) $p->location_id, 'location_name' => (string) $p->location_name,
                'on_hand' => $onHand, 'on_order' => $onOrder, 'reorder_level' => bcadd((string) $p->reorder_level, '0', 3), 'maximum_stock' => bcadd((string) $p->maximum_stock, '0', 3),
                'suggested' => $suggested, 'supplier_id' => $supplier?->id, 'supplier_name' => $supplier?->name, 'lead_time_days' => $lead === null ? null : (int) $lead,
                // if the order goes out today, the goods can be here by this date
                'arrives_by' => $lead === null ? null : now()->addDays((int) $lead)->toDateString(),
            ];
        }

        return $rows;
    }

    /** Base quantity still due at a branch on approved, open orders (ordered less received, never below zero). */
    private function onOrder(int $companyId, int $itemId, int $locationId): string
    {
        $lines = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('o.company_id', $companyId)->where('o.location_id', $locationId)->where('i.item_id', $itemId)
            ->where('o.approval_status', 'approved')->where('o.state', 'open')->whereNull('o.deleted_at')->whereNull('i.deleted_at')
            ->get(['i.id', 'i.base_quantity']);

        $due = '0.000';

        foreach ($lines as $line) {
            $received = (string) DB::table('grn_items as g')->join('grns as h', 'h.id', '=', 'g.grn_id')
                ->where('g.purchase_order_item_id', $line->id)->whereIn('h.approval_status', ['pending', 'approved'])->sum('g.base_received');
            $left = bcsub((string) $line->base_quantity, $received, 3);
            $due = bccomp($left, '0', 3) > 0 ? bcadd($due, $left, 3) : $due;
        }

        return $due;
    }

    private function lastSupplier(int $companyId, int $itemId): ?Ledger
    {
        $ledgerId = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('o.company_id', $companyId)->where('i.item_id', $itemId)->where('o.approval_status', 'approved')->where('o.state', 'open')
            ->whereNull('o.deleted_at')->whereNull('i.deleted_at')
            ->orderByDesc('o.po_date')->orderByDesc('o.id')->value('o.supplier_ledger_id');

        return $ledgerId === null ? null : Ledger::withoutGlobalScopes(Tenancy::SCOPES)->find($ledgerId);
    }
}
