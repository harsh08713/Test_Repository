<?php

namespace App\Modules\Procurement\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One purchase order: its lines with what has been received and what is still allowed, its receipts, the approval panel, and cancelling. */
#[Title('Purchase order')]
class PurchaseOrderShow extends Component
{
    #[Locked]
    public int $orderId = 0;

    public bool $cancelling = false;

    public string $reason = '';

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        Gate::authorize('view', $purchaseOrder);

        $this->orderId = $purchaseOrder->id;
    }

    #[Computed]
    public function orderModel(): PurchaseOrder
    {
        $order = PurchaseOrder::query()->with(['items.unit:id,code,name', 'location:id,name,code', 'supplier'])->findOrFail($this->orderId);
        Gate::authorize('view', $order);

        return $order;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->orderModel);
    }

    public function openCancel(): void
    {
        Gate::authorize('cancel', $this->orderModel());
        $this->cancelling = true;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function closeCancel(): void
    {
        $this->cancelling = false;
        $this->resetErrorBag();
    }

    public function cancelOrder(PurchaseOrderService $service): void
    {
        $order = $this->orderModel();
        Gate::authorize('cancel', $order);
        $this->resetErrorBag();

        try {
            $service->cancel($order, $this->reason, Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        session()->flash('status', 'The purchase order is cancelled.');
        $this->redirectRoute('procurement.orders.show', $this->orderId, navigate: false);
    }

    public function render(PurchaseOrderService $service): View
    {
        $order = $this->orderModel();
        $received = $service->received($order);

        return view('procurement.order-show', [
            'order' => $order,
            'received' => $received,
            'remaining' => $order->items->mapWithKeys(fn ($l) => [$l->id => $service->remaining($l, $received[$l->id] ?? '0.000')])->all(),
            'grns' => Grn::query()->where('purchase_order_id', $order->id)->orderByDesc('id')->get(),
            'canEdit' => Gate::allows('update', $order) && ! $order->isCancelled(),
            'canCancel' => Gate::allows('cancel', $order),
            'canReceive' => Gate::allows('receive', $order),
            'history' => Gate::allows('viewAudit', $order)
                ? ActivityLog::query()->with('causer')->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
