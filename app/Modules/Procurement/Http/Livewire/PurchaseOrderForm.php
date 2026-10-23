<?php

namespace App\Modules\Procurement\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Make (or change a draft of) a purchase order: the supplier, the branch it is for, and the items with their units, quantities and rates. Only an
 * approved, active supplier and approved, active items can be picked; PurchaseOrderService checks every value again whatever was sent.
 */
#[Title('Purchase order')]
class PurchaseOrderForm extends Component
{
    #[Locked]
    public ?int $orderId = null;

    public string $locationId = '';

    public string $supplierId = '';

    public string $supplierLabel = '';

    public string $supplierSearch = '';

    public string $poDate = '';

    public string $expectedDate = '';

    public string $terms = '';

    public string $remarks = '';

    /** @var list<array<string, string>> */
    public array $rows = [];

    public function mount(?int $editing = null): void
    {
        $this->poDate = now()->toDateString();

        if ($editing !== null) {
            $order = PurchaseOrder::query()->with(['items', 'supplier:id,code,name'])->findOrFail($editing);
            Gate::authorize('update', $order);

            $this->orderId = $order->id;
            $this->locationId = (string) $order->location_id;
            $this->supplierId = (string) $order->supplier_ledger_id;
            $this->supplierLabel = trim($order->supplier?->code.' · '.$order->supplier_name);
            $this->poDate = $order->po_date->toDateString();
            $this->expectedDate = $order->expected_date->toDateString();
            $this->terms = (string) $order->terms;
            $this->remarks = (string) $order->remarks;
            $this->rows = $order->items->map(fn ($l) => [
                'key' => Str::random(6), 'item_id' => (string) $l->item_id, 'item_label' => $l->item_name, 'search' => '', 'unit_id' => (string) $l->unit_id,
                'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'), 'rate' => rtrim(rtrim((string) $l->rate, '0'), '.'),
            ])->all();

            return;
        }

        Gate::authorize('create', PurchaseOrder::class);
        $this->rows = [$this->blank()];
    }

    /** @return array<string, string> */
    private function blank(): array
    {
        return ['key' => Str::random(6), 'item_id' => '', 'item_label' => '', 'search' => '', 'unit_id' => '', 'quantity' => '', 'rate' => ''];
    }

    public function addRow(): void
    {
        if (count($this->rows) < PurchaseOrderService::MAX_LINES) {
            $this->rows[] = $this->blank();
        }
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows) ?: [$this->blank()];
    }

    public function pickItem(int $index, int $id): void
    {
        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null && isset($this->rows[$index])) {
            $this->rows[$index] = [...$this->rows[$index], 'item_id' => (string) $item->id, 'item_label' => trim($item->code.' · '.$item->name), 'unit_id' => (string) $item->base_unit_id, 'search' => ''];
        }
    }

    public function clearItem(int $index): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index] = [...$this->rows[$index], 'item_id' => '', 'item_label' => '', 'unit_id' => '', 'search' => ''];
        }
    }

    public function pickSupplier(int $id): void
    {
        $supplier = app(LedgerLookup::class)->usable($id, LedgerType::Supplier);

        if ($supplier !== null) {
            $this->supplierId = (string) $supplier->id;
            $this->supplierLabel = trim($supplier->code.' · '.$supplier->name);
            $this->supplierSearch = '';
        }
    }

    public function clearSupplier(): void
    {
        $this->supplierId = $this->supplierLabel = '';
    }

    public function save(PurchaseOrderService $service): void
    {
        $this->resetErrorBag();

        $input = [
            'location_id' => $this->locationId, 'supplier_ledger_id' => $this->supplierId, 'po_date' => $this->poDate, 'expected_date' => $this->expectedDate,
            'terms' => $this->terms, 'remarks' => $this->remarks, 'items' => $this->rows,
        ];

        try {
            if ($this->orderId !== null) {
                $order = PurchaseOrder::query()->findOrFail($this->orderId);
                Gate::authorize('update', $order);
                $order = $service->update($order, $input, Auth::user());
            } else {
                Gate::authorize('create', PurchaseOrder::class);
                $order = $service->create($input, Auth::user());
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', "Purchase order {$order->po_number} saved. Send it for approval when it is ready.");
        $this->redirectRoute('procurement.orders.show', $order, navigate: false);
    }

    public function render(): View
    {
        $lookup = app(ItemLookup::class);
        $itemUnits = [];
        $results = [];
        $total = '0.00';

        foreach ($this->rows as $i => $row) {
            if (ctype_digit($row['item_id'] ?? '') && ($item = $lookup->usable((int) $row['item_id'])) !== null) {
                $itemUnits[$i] = collect([$item->baseUnit, ...$item->uoms->map->unit])->filter()->unique('id')->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->code})"])->all();
            }

            if (trim($row['search'] ?? '') !== '') {
                $results[$i] = $lookup->search($row['search'], 6)->map(fn ($h) => ['id' => $h->id, 'label' => trim($h->code.' · '.$h->name)])->all();
            }

            if (preg_match('/^\d+(\.\d+)?$/', $row['quantity'] ?? '') === 1 && preg_match('/^\d+(\.\d+)?$/', $row['rate'] ?? '') === 1) {
                $total = bcadd($total, bcmul($row['quantity'], $row['rate'], 2), 2);
            }
        }

        return view('procurement.order-form', [
            'branches' => Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'supplierResults' => trim($this->supplierSearch) === '' ? collect() : app(LedgerLookup::class)->search(LedgerType::Supplier, $this->supplierSearch, 6),
            'itemUnits' => $itemUnits,
            'results' => $results,
            'total' => $total,
            'editing' => $this->orderId !== null,
            'max' => PurchaseOrderService::MAX_LINES,
        ]);
    }
}
