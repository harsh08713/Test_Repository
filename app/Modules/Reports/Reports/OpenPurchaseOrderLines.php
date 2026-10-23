<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Support\Collection;
use stdClass;

/**
 * What is still to arrive on approved, open purchase orders: for every line, what was ordered, what has been received (by `PurchaseOrderService::received`, the one place that works it out —
 * pending and approved receipts count), what is left, and how late the order is. A collection report: the open orders of the branches you work in, never more than a few hundred lines.
 */
final class OpenPurchaseOrderLines extends Report
{
    public function key(): string
    {
        return 'open-purchase-order-lines';
    }

    public function title(): string
    {
        return 'Open purchase order lines';
    }

    public function description(): string
    {
        return 'Every line of an approved, open purchase order that has not been fully received: ordered, received, left to come, and the days past the expected date.';
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
        return ['branch', 'choice'];
    }

    public function choiceLabel(): string
    {
        return 'Show';
    }

    public function choices(): array
    {
        return ['all' => 'All lines still to come', 'late' => 'Only the late ones (past the expected date)'];
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'Quantities are in the item\'s base unit. A line counts as received once receipts that are waiting or approved cover the ordered quantity; the supplier\'s tolerance is not counted as "left to come".';
    }

    public function columns(): array
    {
        return [
            new C('po_number', 'Order'), new C('supplier', 'Supplier'), new C('branch', 'Branch'), new C('expected_date', 'Expected', C::DATE), new C('days_late', 'Days late', C::NUMBER),
            new C('item_name', 'Item'), new C('ordered', 'Ordered'), new C('received', 'Received'), new C('left', 'Left to come'),
        ];
    }

    /** @return Collection<int, stdClass> */
    public function collection(ReportInput $input, User $user): Collection
    {
        $service = app(PurchaseOrderService::class);
        $today = now()->startOfDay();
        $rows = collect();

        $orders = PurchaseOrder::query()->where('state', 'open')->where('approval_status', 'approved')->with(['items', 'location:id,name'])
            ->when($input->branch !== null, fn ($q) => $q->where('location_id', $input->branch))->orderBy('expected_date')->orderBy('id')->limit(500)->get();

        foreach ($orders as $order) {
            $received = $service->received($order);
            $late = $order->expected_date->lt($today) ? (int) $order->expected_date->diffInDays($today) : 0;

            if ($input->choice === 'late' && $late === 0) {
                continue;
            }

            foreach ($order->items as $line) {
                $got = $received[$line->id] ?? '0.000';
                $left = bcsub((string) $line->base_quantity, $got, 3);

                if (bccomp($left, '0', 3) <= 0) {
                    continue;
                }

                $rows->push($this->asRow([
                    'po_number' => $order->po_number, 'supplier' => $order->supplier_name, 'branch' => $order->location?->name, 'expected_date' => $order->expected_date->toDateString(), 'days_late' => $late,
                    'item_name' => $line->item_name, 'ordered' => $this->trim((string) $line->base_quantity), 'received' => $this->trim($got), 'left' => $this->trim($left),
                ]));
            }
        }

        return $rows->values();
    }

    private function trim(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }

    public function summary(ReportInput $input, User $user): array
    {
        $rows = $this->collection($input, $user);

        return ['Lines to come' => number_format($rows->count()), 'Orders' => number_format($rows->pluck('po_number')->unique()->count()), 'Late orders' => number_format($rows->where('days_late', '>', 0)->pluck('po_number')->unique()->count())];
    }
}
