<?php

namespace App\Modules\Procurement\Http\Livewire;

use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\ReorderSuggestions;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Items at or below their reorder level at a branch (counting what is still due on approved orders), with a suggested quantity. Only a suggestion. */
#[Title('Reorder suggestions')]
class ReorderList extends Component
{
    #[Url(as: 'branch')]
    public string $branch = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', PurchaseOrder::class);
    }

    public function render(ReorderSuggestions $suggestions, TenantContext $tenant): View
    {
        $branches = Location::query()->orderBy('name')->pluck('name', 'id')->all();   // only the branches this person works in
        $branch = ctype_digit($this->branch) && isset($branches[(int) $this->branch]) ? (int) $this->branch : null;

        $rows = array_values(array_filter(
            $suggestions->forCompany((int) $tenant->activeCompanyId(), $branch),
            fn (array $r) => isset($branches[$r['location_id']]),
        ));

        return view('procurement.reorder', ['rows' => $rows, 'branches' => $branches, 'canOrder' => Gate::allows('create', PurchaseOrder::class)]);
    }
}
