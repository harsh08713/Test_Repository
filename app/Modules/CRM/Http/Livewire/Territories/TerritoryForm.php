<?php

namespace App\Modules\CRM\Http\Livewire\Territories;

use App\Models\User;
use App\Modules\CRM\Models\Territory;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\CRM\Services\TerritoryService;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Start a territory or change one: its code, name, branch, owner and whether it is switched on. Every rule lives in TerritoryService. */
#[Title('Territory')]
class TerritoryForm extends Component
{
    #[Locked]
    public ?int $territoryId = null;

    /** @var array<string, mixed> */
    public array $territory = [];

    public function mount(?Territory $editing = null): void
    {
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->territoryId = $editing->id;
            $this->territory = [
                'code' => $editing->code, 'name' => $editing->name, 'location_id' => (string) ($editing->location_id ?? ''),
                'owner_user_id' => (string) ($editing->owner_user_id ?? ''), 'status' => $editing->status, 'remarks' => (string) ($editing->remarks ?? ''),
            ];

            return;
        }

        Gate::authorize('create', Territory::class);

        $this->territory = ['code' => '', 'name' => '', 'location_id' => '', 'owner_user_id' => '', 'status' => 'active', 'remarks' => ''];
    }

    public function save(TerritoryService $service): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $editing = $this->territoryId !== null ? Territory::query()->findOrFail($this->territoryId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? Territory::class);

        $this->resetErrorBag();

        try {
            $saved = $service->save($editing, $this->territory, $user);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError("territory.{$field}", $messages[0]);
            }

            return;
        }

        session()->flash('status', $editing ? 'Territory saved.' : 'Territory added. Give it its coverage next.');
        $this->redirectRoute('field.territories.show', $saved, navigate: false);
    }

    public function render(SalesTeamService $teams, TenantContext $tenant): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $reach = $tenant->accessibleLocationIdsFor($user, (int) $tenant->activeCompanyId());
        $branchId = (string) ($this->territory['location_id'] ?? '');

        return view('crm.territories.form', [
            'editing' => $this->territoryId !== null,
            'branches' => Location::query()->where('status', 'active')->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))->orderBy('name')->pluck('name', 'id')->all(),
            'owners' => ctype_digit($branchId) ? $teams->staffInBranch((int) $branchId) : $teams->staff(),
        ]);
    }
}
