<?php

namespace App\Modules\Masters\Http\Livewire\Items;

use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Shared\Enums\ApprovalStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The company's items, filterable by stage, group and status; search matches code, name, aliases and HSN. */
#[Title('Items')]
class ItemList extends Component
{
    use WithPagination;

    /** A stage value, or "returned" for items an approver sent back. */
    #[Url(as: 'stage')]
    public string $stage = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'group')]
    public string $group = '';

    #[Url(as: 'status')]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Item::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['stage', 'search', 'group', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function toggleStatus(int $id): void
    {
        $item = Item::query()->findOrFail($id);
        Gate::authorize('changeStatus', $item);

        $item->forceFill(['status' => $item->status === 'active' ? 'inactive' : 'active'])->save();
    }

    public function render(): View
    {
        $stage = ItemStage::tryFrom($this->stage);

        $items = Item::query()
            ->with(['itemGroup:id,name', 'baseUnit:id,code'])
            ->when($stage !== null, fn ($q) => $q->where('workflow_stage', $stage->value))
            ->when($this->stage === 'returned', fn ($q) => $q->where('approval_status', ApprovalStatus::Rejected->value))
            ->when(ctype_digit($this->group), fn ($q) => $q->where('item_group_id', (int) $this->group))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('hsn_code', 'like', $like)
                    ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', $like)));
            })
            ->orderBy('name')
            ->paginate(15);

        return view('masters.items.list', [
            'items' => $items,
            'stages' => ItemStage::cases(),
            'groups' => ItemGroup::query()->orderBy('name')->pluck('name', 'id')->all(),
            'canCreate' => Gate::allows('create', Item::class),
        ]);
    }
}
