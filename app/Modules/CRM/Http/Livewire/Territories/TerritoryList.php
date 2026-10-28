<?php

namespace App\Modules\CRM\Http\Livewire\Territories;

use App\Models\User;
use App\Modules\CRM\Models\Territory;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The company's territories — those of the branches the person works in, and the company-wide ones — with their owners and how much each covers. */
#[Title('Territories')]
class TerritoryList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Territory::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(TenantContext $tenant): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $reach = $tenant->accessibleLocationIdsFor($user, (int) $tenant->activeCompanyId());

        $territories = Territory::query()
            ->with(['owner:id,name', 'branch:id,name'])
            ->withCount('areas')
            ->when($reach !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('location_id')->orWhereIn('location_id', $reach)))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(15);

        return view('crm.territories.list', ['territories' => $territories, 'canCreate' => Gate::allows('create', Territory::class)]);
    }
}
