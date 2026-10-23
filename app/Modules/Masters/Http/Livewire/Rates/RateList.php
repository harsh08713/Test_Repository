<?php

namespace App\Modules\Masters\Http\Livewire\Rates;

use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The company's rates (only the branches this person works in), filterable by branch, price list, approval state,
 * status and the date a rate must be in force on; search matches the item's code, name and aliases.
 */
#[Title('Rates')]
class RateList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    /** '' = every price list, 'standard' = rates with no price list, otherwise a price list id. */
    #[Url(as: 'list')]
    public string $list = '';

    #[Url(as: 'approval')]
    public string $approval = '';

    #[Url(as: 'status')]
    public string $status = '';

    /** Show only rates in force on this day (YYYY-MM-DD). */
    #[Url(as: 'on')]
    public string $on = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Rate::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'branch', 'list', 'approval', 'status', 'on'], true)) {
            $this->resetPage();
        }
    }

    public function toggleStatus(int $id): void
    {
        $rate = Rate::query()->findOrFail($id);
        Gate::authorize('changeStatus', $rate);

        $rate->forceFill(['status' => $rate->status === 'active' ? 'inactive' : 'active'])->save();
    }

    public function render(): View
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->on) === 1 && Carbon::hasFormat($this->on, 'Y-m-d') ? $this->on : null;

        $rates = Rate::query()
            ->with(['item:id,code,name', 'location:id,code,name', 'unit:id,code,name', 'priceList:id,code,name'])
            ->when(ctype_digit($this->branch), fn ($q) => $q->where('location_id', (int) $this->branch))
            ->when($this->list === 'standard', fn ($q) => $q->whereNull('price_list_id'))
            ->when(ctype_digit($this->list), fn ($q) => $q->where('price_list_id', (int) $this->list))
            ->when(ApprovalStatus::tryFrom($this->approval) !== null, fn ($q) => $q->where('approval_status', $this->approval))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($q) => $q->where('status', $this->status))
            ->when($day !== null, fn ($q) => $q->effectiveOn($day))
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $q->whereHas('item', fn ($i) => $i->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)
                    ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', $like))));
            })
            ->orderBy(Item::query()->select('name')->whereColumn('items.id', 'rates.item_id')->limit(1))
            ->orderBy('location_id')
            ->orderByDesc('effective_from')
            ->paginate(15);

        return view('masters.rates.list', [
            'rates' => $rates,
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'priceLists' => PriceList::query()->orderBy('name')->pluck('name', 'id')->all(),
            'approvals' => ApprovalStatus::cases(),
            'today' => now()->toDateString(),
            'canCreate' => Gate::allows('create', Rate::class),
        ]);
    }
}
