<?php

namespace App\Modules\Inventory\Http\Livewire;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The stock ledger: every movement in or out, newest first, with its cost and the document or reason behind it. */
#[Title('Stock ledger')]
class StockLedgerList extends Component
{
    use WithPagination;

    public const PER_PAGE = 30;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Batch::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'type', 'branch'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        $movements = StockMovement::query()
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->whereHas('item', fn ($i) => $i->where('name', 'like', $like)->orWhere('code', 'like', $like)))
            ->when(array_key_exists($this->type, StockMovement::LABELS), fn (Builder $q) => $q->where('movement_type', $this->type))
            ->when(ctype_digit($this->branch), fn (Builder $q) => $q->where('location_id', (int) $this->branch))
            ->with(['item:id,code,name,base_unit_id', 'item.baseUnit:id,code', 'batch:id,batch_no', 'location:id,name,code', 'creator:id,name'])
            ->orderByDesc('id')->paginate(self::PER_PAGE);

        return view('inventory.movements', [
            'movements' => $movements,
            'types' => StockMovement::LABELS,
            'canReportLoss' => Gate::allows('create', LossIncident::class),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }
}
