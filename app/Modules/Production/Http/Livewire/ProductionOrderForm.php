<?php

namespace App\Modules\Production\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Plan (or change a draft of) a production order: the bill of materials, how much, where and when. The components it will require are copied from the bill when it is saved. */
#[Title('Production order')]
class ProductionOrderForm extends Component
{
    #[Locked]
    public ?int $orderId = null;

    #[Url(as: 'bom')]
    public string $bomId = '';

    public string $locationId = '';

    public string $plannedQuantity = '';

    public string $plannedStart = '';

    public string $plannedEnd = '';

    public string $remarks = '';

    public function mount(?int $editing = null): void
    {
        $this->plannedStart = now()->toDateString();

        if ($editing !== null) {
            $order = ProductionOrder::query()->findOrFail($editing);
            Gate::authorize('update', $order);

            $this->orderId = $order->id;
            $this->bomId = (string) $order->bom_id;
            $this->locationId = (string) $order->location_id;
            $this->plannedQuantity = rtrim(rtrim((string) $order->planned_quantity, '0'), '.');
            $this->plannedStart = $order->planned_start->toDateString();
            $this->plannedEnd = $order->planned_end->toDateString();
            $this->remarks = (string) $order->remarks;

            return;
        }

        Gate::authorize('create', ProductionOrder::class);
        $this->bomId = ctype_digit($this->bomId) && Bom::query()->usable()->whereKey((int) $this->bomId)->exists() ? $this->bomId : '';
    }

    public function save(ProductionOrderService $service): void
    {
        $this->resetErrorBag();
        $input = ['location_id' => $this->locationId, 'bom_id' => $this->bomId, 'planned_quantity' => $this->plannedQuantity, 'planned_start' => $this->plannedStart, 'planned_end' => $this->plannedEnd, 'remarks' => $this->remarks];

        try {
            if ($this->orderId !== null) {
                $order = ProductionOrder::query()->findOrFail($this->orderId);
                Gate::authorize('update', $order);
                $order = $service->update($order, $input, Auth::user());
            } else {
                Gate::authorize('create', ProductionOrder::class);
                $order = $service->create($input, Auth::user());
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', "Production order {$order->order_number} saved. Release it when it is ready to start.");
        $this->redirectRoute('production.orders.show', $order, navigate: false);
    }

    public function render(): View
    {
        return view('production.order-form', [
            'boms' => Bom::query()->usable()->with('item:id,name')->orderBy('code')->get()->mapWithKeys(fn (Bom $b) => [$b->id => $b->code.' · '.$b->item?->name])->all(),
            'branches' => Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'editing' => $this->orderId !== null,
        ]);
    }
}
