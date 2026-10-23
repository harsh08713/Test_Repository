<?php

namespace App\Modules\Procurement\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Models\Item;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Receive goods against an approved purchase order (or change a draft receipt): per order line, how much was accepted and rejected, and the
 * batch for an item tracked by batch. GrnService checks every value — the tolerance above all — whatever was sent.
 */
#[Title('Goods receipt')]
class GrnForm extends Component
{
    #[Locked]
    public int $orderId = 0;

    #[Locked]
    public ?int $grnId = null;

    public string $grnDate = '';

    public string $supplierInvoiceNo = '';

    public string $remarks = '';

    /** @var array<int|string, array<string, string>> order line id => what was typed */
    public array $lines = [];

    public function mount(?int $order = null, ?int $editing = null): void
    {
        $this->grnDate = now()->toDateString();

        if ($editing !== null) {
            $grn = Grn::query()->with('items')->findOrFail($editing);
            Gate::authorize('update', $grn);

            $this->grnId = $grn->id;
            $this->orderId = $grn->purchase_order_id;
            $this->grnDate = $grn->grn_date->toDateString();
            $this->supplierInvoiceNo = (string) $grn->supplier_invoice_no;
            $this->remarks = (string) $grn->remarks;
            $this->blankLines(PurchaseOrder::query()->findOrFail($this->orderId));

            $plain = fn ($v) => $v === null ? '' : (rtrim(rtrim((string) $v, '0'), '.') ?: '0');
            foreach ($grn->items as $l) {
                $this->lines[$l->purchase_order_item_id] = [
                    'accepted' => $plain($l->accepted_quantity), 'rejected' => $plain($l->rejected_quantity), 'reject_reason' => (string) $l->reject_reason,
                    'batch_no' => (string) $l->batch_no, 'lot_no' => (string) $l->lot_no, 'mfg_date' => (string) $l->mfg_date?->toDateString(), 'expiry_date' => (string) $l->expiry_date?->toDateString(),
                ];
            }

            return;
        }

        $po = PurchaseOrder::query()->findOrFail((int) $order);
        Gate::authorize('receive', $po);
        $this->orderId = $po->id;
        $this->blankLines($po);
    }

    private function blankLines(PurchaseOrder $po): void
    {
        foreach ($po->items()->get(['id']) as $line) {
            $this->lines[$line->id] = ['accepted' => '', 'rejected' => '', 'reject_reason' => '', 'batch_no' => '', 'lot_no' => '', 'mfg_date' => '', 'expiry_date' => ''];
        }
    }

    public function save(GrnService $service): void
    {
        $this->resetErrorBag();
        $input = ['grn_date' => $this->grnDate, 'supplier_invoice_no' => $this->supplierInvoiceNo, 'remarks' => $this->remarks, 'lines' => $this->lines];

        try {
            if ($this->grnId !== null) {
                $grn = Grn::query()->findOrFail($this->grnId);
                Gate::authorize('update', $grn);
                $grn = $service->update($grn, $input, Auth::user());
            } else {
                $po = PurchaseOrder::query()->findOrFail($this->orderId);
                Gate::authorize('receive', $po);
                $grn = $service->create($po, $input, Auth::user());
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', "Goods receipt {$grn->grn_number} saved as a draft. Send it for approval: the stock is added when it is approved.");
        $this->redirectRoute('procurement.grns.show', $grn, navigate: false);
    }

    public function render(PurchaseOrderService $orders): View
    {
        $po = PurchaseOrder::query()->with(['items.unit:id,code,name', 'location:id,name'])->findOrFail($this->orderId);
        $received = $orders->received($po, $this->grnId);

        return view('procurement.grn-form', [
            'po' => $po,
            'remaining' => $po->items->mapWithKeys(fn ($l) => [$l->id => $orders->remaining($l, $received[$l->id] ?? '0.000')])->all(),
            'items' => Item::query()->whereIn('id', $po->items->pluck('item_id'))->get(['id', 'tracking', 'has_expiry', 'base_unit_id'])->keyBy('id'),
            'editing' => $this->grnId !== null,
        ]);
    }
}
