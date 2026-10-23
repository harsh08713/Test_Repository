<?php

namespace App\Modules\Maintenance\Http\Livewire;

use App\Models\User;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Maintenance\Services\MaintenanceOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Report a breakdown, or plan a preventive or corrective job: which asset, what, how urgent, when a breakdown stopped it, who does it, and a checklist. */
#[Title('Report or plan work')]
class WorkOrderForm extends Component
{
    #[Url(as: 'asset')]
    public string $assetId = '';

    #[Url(as: 'type')]
    public string $type = 'breakdown';

    public string $priority = 'normal';

    public string $title = '';

    public string $description = '';

    public string $dueOn = '';

    public string $stoppedAt = '';

    public string $assignedTo = '';

    public string $checklist = '';

    public function mount(): void
    {
        Gate::authorize('create', MaintenanceOrder::class);

        $this->type = array_key_exists($this->type, MaintenanceOrder::TYPES) ? $this->type : 'breakdown';
        $this->assetId = ctype_digit($this->assetId) && Asset::query()->where('status', 'active')->whereKey((int) $this->assetId)->exists() ? $this->assetId : '';
        $this->stoppedAt = now()->format('Y-m-d\TH:i');
    }

    public function save(MaintenanceOrderService $service): void
    {
        $this->resetErrorBag();

        try {
            Gate::authorize('create', MaintenanceOrder::class);
            $order = $service->create([
                'asset_id' => $this->assetId, 'type' => $this->type, 'priority' => $this->priority, 'title' => $this->title, 'description' => $this->description, 'due_on' => $this->dueOn,
                'stopped_at' => $this->type === 'breakdown' ? $this->stoppedAt : '', 'assigned_to' => $this->assignedTo, 'checklist' => $this->checklist,
            ], Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        session()->flash('status', "Work order {$order->order_number} is made.");
        $this->redirectRoute('maintenance.orders.show', $order, navigate: false);
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('maintenance.order-form', [
            'assets' => Asset::query()->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name'])->mapWithKeys(fn ($a) => [$a->id => $a->code.' · '.$a->name])->all(),
            'types' => MaintenanceOrder::TYPES, 'priorities' => MaintenanceOrder::PRIORITIES,
            'technicians' => $user instanceof User && $user->can('maintenance_order.assign')
                ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->filter(fn (User $u) => $u->can('maintenance_order.execute'))->mapWithKeys(fn ($u) => [$u->id => $u->name])->all() : [],
        ]);
    }
}
