<?php

namespace App\Modules\Loss\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Procurement\Models\GrnItem;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionFigures;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Facades\DB;

/**
 * The operational records a loss can be reported from — scrap or stock written off, an expired batch, goods rejected on receipt, an approved sales
 * return — and what each says about the loss. Each is looked up through the person's own scopes (company and branches), so an id that points
 * elsewhere is simply not found. What it returns is a suggestion for the form; the person can change any of it.
 */
final class LossSources
{
    public const TYPES = ['stock_movement' => 'Stock written off', 'batch' => 'Expired batch', 'grn_item' => 'Goods rejected on receipt', 'sales_return' => 'Sales return',
        'production_order' => 'Excess material on a production order', 'grn_price' => 'Bought above the standard cost',
    ];

    /**
     * @return array{type: string, id: int, label: string, location_id: int, item_id: int|null, batch_id: int|null, quantity: string|null, unit_id: int|null, title: string, impact: array{impact_type: string, formula_key: string, inputs: array<string, string>, assumptions: string}}|null
     */
    public function describe(string $type, int $id): ?array
    {
        return match ($type) {
            'stock_movement' => $this->movement($id),
            'batch' => $this->batch($id),
            'grn_item' => $this->rejection($id),
            'sales_return' => $this->salesReturn($id),
            'production_order' => $this->productionOrder($id),
            'grn_price' => $this->priceVariance($id),
            default => null,
        };
    }

    /** @return array<string, mixed>|null what the record says (the shape of describe()) */
    private function movement(int $id): ?array
    {
        $m = StockMovement::query()->with(['item:id,name,base_unit_id', 'batch:id,batch_no'])->whereKey($id)
            ->whereIn('movement_type', [StockMovement::SCRAP, StockMovement::ADJUSTMENT_OUT])->first();

        if ($m === null || $m->item === null) {
            return null;
        }

        $quantity = ltrim((string) $m->quantity, '-');

        return [
            'type' => 'stock_movement', 'id' => $m->id, 'label' => "{$m->label()} of {$m->item->name} on ".$m->moved_at->format('d-m-Y'),
            'location_id' => $m->location_id, 'item_id' => $m->item_id, 'batch_id' => $m->batch_id, 'quantity' => $quantity, 'unit_id' => $m->item->base_unit_id,
            'title' => "{$m->label()}: {$m->item->name}",
            'impact' => [
                'impact_type' => 'actual', 'formula_key' => 'material_cost', 'inputs' => ['quantity' => $this->plain($quantity), 'unit_cost' => $this->plain((string) $m->unit_cost, 4)],
                'assumptions' => 'Valued at the moving-average cost the stock left at ('.$m->reason.').',
            ],
        ];
    }

    /** @return array<string, mixed>|null what the record says (the shape of describe()) */
    private function batch(int $id): ?array
    {
        $b = Batch::query()->with('item:id,name,base_unit_id')->whereKey($id)->first();

        if ($b === null || ! $b->isExpired() || $b->item === null) {
            return null;
        }

        $stock = DB::table('stock_balances')->where('batch_id', $b->id)->where('quantity', '>', 0)->orderByDesc('quantity')->get(['location_id', 'quantity', 'value']);

        if ($stock->isEmpty()) {
            return null;
        }

        $quantity = bcadd((string) $stock->sum('quantity'), '0', 3);
        $value = bcadd((string) $stock->sum('value'), '0', 2);

        return [
            'type' => 'batch', 'id' => $b->id, 'label' => "Batch {$b->batch_no} of {$b->item->name}, expired ".$b->expiry_date?->format('d-m-Y'),
            'location_id' => (int) $stock->first()->location_id, 'item_id' => $b->item_id, 'batch_id' => $b->id, 'quantity' => $quantity, 'unit_id' => $b->item->base_unit_id,
            'title' => "Expired stock: {$b->item->name}, batch {$b->batch_no}",
            'impact' => [
                'impact_type' => 'actual', 'formula_key' => 'expiry',
                'inputs' => ['quantity' => $this->plain($quantity), 'unit_cost' => $this->plain(bcdiv($value, $quantity, 4), 4)],
                'assumptions' => 'The batch’s stock on hand at its average cost; it can still be sold to nobody.',
            ],
        ];
    }

    /** @return array<string, mixed>|null what the record says (the shape of describe()) */
    private function rejection(int $id): ?array
    {
        $line = GrnItem::query()->with(['grn', 'item:id,name,base_unit_id'])->whereKey($id)->where('rejected_quantity', '>', 0)->first();

        if ($line === null || $line->grn === null || ! $line->grn->isApproved() || $line->item === null) {
            return null;
        }

        $baseRejected = bcsub((string) $line->base_received, (string) $line->base_accepted, 3);

        return [
            'type' => 'grn_item', 'id' => $line->id, 'label' => "Rejected on receipt {$line->grn->grn_number}: {$line->item_name}",
            'location_id' => $line->grn->location_id, 'item_id' => $line->item_id, 'batch_id' => null, 'quantity' => $baseRejected, 'unit_id' => $line->item->base_unit_id,
            'title' => "Goods rejected on receipt: {$line->item_name}",
            'impact' => [
                'impact_type' => 'exposure', 'formula_key' => 'material_cost',
                'inputs' => ['quantity' => $this->plain($baseRejected), 'unit_cost' => $this->plain((string) $line->unit_cost, 4)],
                'assumptions' => 'At the order’s price. An exposure until the supplier’s credit or replacement is settled'.($line->reject_reason ? " ({$line->reject_reason})" : '').'.',
            ],
        ];
    }

    /** @return array<string, mixed>|null what the record says (the shape of describe()) */
    private function salesReturn(int $id): ?array
    {
        $r = SalesReturn::query()->whereKey($id)->first();

        if ($r === null || ! $r->isApproved()) {
            return null;
        }

        return [
            'type' => 'sales_return', 'id' => $r->id, 'label' => "{$r->typeLabel()} {$r->return_number} for {$r->party_name}",
            'location_id' => $r->location_id, 'item_id' => null, 'batch_id' => null, 'quantity' => null, 'unit_id' => null,
            'title' => "{$r->typeLabel()} {$r->return_number}: {$r->party_name}",
            'impact' => [
                'impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => (string) $r->taxable_total],
                'assumptions' => "The taxable value credited to the customer on {$r->return_number}. Reason given: {$r->reason}",
            ],
        ];
    }

    /**
     * A completed production order that used more material than the standard for what it made (good plus rejected output): each component's excess,
     * valued at that component's average issue cost, added up. A component that used less than the standard does not offset another.
     *
     * @return array<string, mixed>|null
     */
    private function productionOrder(int $id): ?array
    {
        $order = ProductionOrder::query()->whereKey($id)->where('state', 'completed')->first();

        if ($order === null) {
            return null;
        }

        $figures = app(ProductionFigures::class)->forOrder($order);
        $total = '0';
        $parts = [];

        foreach ($figures['components'] as $c) {
            if (bccomp($c['variance'], '0', 3) <= 0) {
                continue;
            }

            $issued = DB::table('production_issues')->where('production_component_id', $c['id'])->select('id');
            $value = ltrim((string) DB::table('stock_movements')->where('company_id', $order->company_id)->where('source_type', 'production_issue')->where('movement_type', 'issue')
                ->whereIn('source_id', $issued)->sum('value'), '-');

            if (bccomp($c['issued'], '0', 3) <= 0) {
                continue;
            }

            $excess = bcadd(bcmul(bcdiv($value, $c['issued'], 8), $c['variance'], 6), '0', 6);
            $total = bcadd($total, $excess, 6);
            $parts[] = $this->plain($c['variance']).' extra '.$c['item_name'];
        }

        $amount = bcadd($total, '0.005', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return [
            'type' => 'production_order', 'id' => $order->id, 'label' => "Production order {$order->order_number} for {$order->item_name}",
            'location_id' => $order->location_id, 'item_id' => $order->item_id, 'batch_id' => null, 'quantity' => null, 'unit_id' => null,
            'title' => "Excess material on {$order->order_number}: {$order->item_name}",
            'impact' => [
                'impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => $amount],
                'assumptions' => 'Material issued above the standard for the output made (good plus rejected), valued at each component’s average issue cost: '.implode(', ', $parts).'.',
            ],
        ];
    }

    /**
     * A goods receipt line bought above the item’s *current* standard cost: the accepted quantity times the difference.
     *
     * @return array<string, mixed>|null
     */
    private function priceVariance(int $id): ?array
    {
        $line = GrnItem::query()->with(['grn', 'item:id,name,base_unit_id,standard_cost'])->whereKey($id)->where('base_accepted', '>', 0)->first();

        if ($line === null || $line->grn === null || ! $line->grn->isApproved() || $line->item === null || $line->item->standard_cost === null) {
            return null;
        }

        $excess = bcsub((string) $line->unit_cost, (string) $line->item->standard_cost, 4);

        if (bccomp($excess, '0', 4) <= 0) {
            return null;
        }

        return [
            'type' => 'grn_price', 'id' => $line->id, 'label' => "Receipt {$line->grn->grn_number}: {$line->item_name} bought at ₹".$this->plain((string) $line->unit_cost, 4),
            'location_id' => $line->grn->location_id, 'item_id' => $line->item_id, 'batch_id' => null, 'quantity' => (string) $line->base_accepted, 'unit_id' => $line->item->base_unit_id,
            'title' => "Bought above standard cost: {$line->item_name}",
            'impact' => [
                'impact_type' => 'actual', 'formula_key' => 'material_cost', 'inputs' => ['quantity' => $this->plain((string) $line->base_accepted), 'unit_cost' => $this->plain($excess, 4)],
                'assumptions' => 'The accepted quantity times the price paid above the item’s current standard cost of ₹'.$this->plain((string) $line->item->standard_cost, 4).' (the standard as it is today, not as it was on the receipt date).',
            ],
        ];
    }

    private function plain(string $number, int $scale = 3): string
    {
        $n = bcadd($number, '0', $scale);

        return str_contains($n, '.') ? (rtrim(rtrim($n, '0'), '.') ?: '0') : $n;
    }
}
