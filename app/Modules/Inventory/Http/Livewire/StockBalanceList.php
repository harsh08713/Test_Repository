<?php

namespace App\Modules\Inventory\Http\Livewire;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** What is on hand now, per branch, item and batch, with its value at moving-average cost. Only the branches the person works in. */
#[Title('Stock on hand')]
class StockBalanceList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Batch::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'branch'], true)) {
            $this->resetPage();
        }
    }

    /** @return Builder<StockBalance> */
    private function base(): Builder
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        return StockBalance::query()->where('quantity', '>', 0)
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->whereHas('item', fn ($i) => $i->where('name', 'like', $like)->orWhere('code', 'like', $like)))
            ->when(ctype_digit($this->branch), fn (Builder $q) => $q->where('location_id', (int) $this->branch));
    }

    public function render(): View
    {
        $rows = $this->base()->with(['item:id,code,name,base_unit_id', 'item.baseUnit:id,code', 'batch:id,batch_no,expiry_date,status', 'location:id,name,code'])
            ->orderBy('item_id')->orderBy('location_id')->orderBy('batch_key')->paginate(self::PER_PAGE);

        return view('inventory.stock', [
            'rows' => $rows,
            'value' => (string) $this->base()->sum('value'),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'canEnter' => Gate::allows('create', Batch::class),
        ]);
    }
}
