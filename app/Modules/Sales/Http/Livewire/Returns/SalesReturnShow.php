<?php

namespace App\Modules\Sales\Http\Livewire\Returns;

use App\Modules\Loss\Models\LossIncident;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One return or credit note: what comes back, at what price and tax, its cost, where it stands — with the shared approval panel. */
#[Title('Return or credit note')]
class SalesReturnShow extends Component
{
    #[Locked]
    public int $returnId = 0;

    public function mount(SalesReturn $salesReturn): void
    {
        Gate::authorize('view', $salesReturn);

        $this->returnId = $salesReturn->id;
    }

    #[Computed]
    public function returnModel(): SalesReturn
    {
        // the whole invoice, not a column list: its policy reads its owner and branch
        $return = SalesReturn::query()->with(['invoice', 'customer', 'owner:id,name', 'location:id,name,code', 'items.unit:id,code,name'])->findOrFail($this->returnId);
        Gate::authorize('view', $return);

        return $return;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->returnModel);
    }

    public function render(): View
    {
        $return = $this->returnModel();

        return view('sales.returns.show', [
            'return' => $return,
            'invoice' => $return->invoice,
            'canViewInvoice' => Gate::allows('view', $return->invoice),
            'canEdit' => Gate::allows('update', $return),
            'canReportLoss' => Gate::allows('create', LossIncident::class),
            'history' => Gate::allows('viewAudit', $return)
                ? ActivityLog::query()->with('causer')->where('subject_type', $return->getMorphClass())->where('subject_id', $return->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
