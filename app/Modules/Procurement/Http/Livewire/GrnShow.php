<?php

namespace App\Modules\Procurement\Http\Livewire;

use App\Modules\Loss\Models\LossIncident;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Services\GrnPoster;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/** One goods receipt: what arrived, accepted and rejected, whether it is in stock yet, and the approval panel. */
#[Title('Goods receipt')]
class GrnShow extends Component
{
    #[Locked]
    public int $grnId = 0;

    public function mount(Grn $grn): void
    {
        Gate::authorize('view', $grn);

        $this->grnId = $grn->id;
    }

    #[Computed]
    public function grnModel(): Grn
    {
        $grn = Grn::query()->with(['purchaseOrder', 'items.unit:id,code,name', 'location:id,name,code'])->findOrFail($this->grnId);
        Gate::authorize('view', $grn);

        return $grn;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->grnModel);
    }

    /** Post the stock of an approved receipt whose posting failed at the time. Asking twice is harmless: it is posted once. */
    public function postNow(GrnPoster $poster): void
    {
        $grn = $this->grnModel();
        Gate::authorize('approve', $grn);   // whoever may approve receipts may put an approved one into stock

        try {
            $poster->post($grn);
        } catch (Throwable) {
            $this->addError('workflow', 'The stock could not be posted just now; the reason is shown on the page and it will be tried again automatically.');

            return;
        }

        unset($this->grnModel);
        session()->flash('status', 'The goods are in stock.');
    }

    public function render(): View
    {
        $grn = $this->grnModel();

        return view('procurement.grn-show', [
            'grn' => $grn,
            'canViewOrder' => $grn->purchaseOrder !== null && Gate::allows('view', $grn->purchaseOrder),
            'canEdit' => Gate::allows('update', $grn),
            'canReportLoss' => Gate::allows('create', LossIncident::class),
            'canPost' => $grn->isApproved() && $grn->posted_at === null && Gate::allows('approve', $grn),
            'history' => Gate::allows('viewAudit', $grn)
                ? ActivityLog::query()->with('causer')->where('subject_type', $grn->getMorphClass())->where('subject_id', $grn->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
