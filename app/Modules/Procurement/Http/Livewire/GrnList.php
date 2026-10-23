<?php

namespace App\Modules\Procurement\Http\Livewire;

use App\Modules\Procurement\Models\Grn;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Goods receipts of the branches the person works in, newest first. A receipt is started from its purchase order. */
#[Title('Goods receipts')]
class GrnList extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'approval')]
    public string $approval = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Grn::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'approval'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        $grns = Grn::query()
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('grn_number', 'like', $like)->orWhere('supplier_name', 'like', $like)->orWhere('supplier_invoice_no', 'like', $like)))
            ->when(in_array($this->approval, ['draft', 'pending', 'approved', 'rejected'], true), fn (Builder $q) => $q->where('approval_status', $this->approval))
            ->with(['purchaseOrder:id,po_number', 'location:id,name'])->orderByDesc('id')->paginate(self::PER_PAGE);

        return view('procurement.grn-list', ['grns' => $grns]);
    }
}
