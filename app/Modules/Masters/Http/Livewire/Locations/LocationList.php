<?php

namespace App\Modules\Masters\Http\Livewire\Locations;

use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** Branches of the active company that the user may see. */
#[Title('Branches')]
class LocationList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Location::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function toggleStatus(int $id): void
    {
        $location = Location::query()->findOrFail($id);
        Gate::authorize('update', $location);

        $location->update(['status' => $location->status === 'active' ? 'inactive' : 'active']);
    }

    public function delete(int $id): void
    {
        $location = Location::query()->findOrFail($id);
        Gate::authorize('delete', $location);

        if (DB::table('number_series')->where('location_id', $location->id)->exists()) {
            $this->addError('list', 'Documents have been numbered for this branch, so it cannot be deleted. Deactivate it instead.');

            return;
        }

        if (($users = DB::table('location_user')->where('location_id', $location->id)->count()) > 0) {
            $this->addError('list', "This branch is assigned to {$users} user(s). Remove it from their access first, or deactivate the branch instead.");

            return;
        }

        $location->delete();
        session()->flash('status', 'Branch deleted.');
    }

    public function render(): View
    {
        $locations = Location::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")
                ->orWhere('city', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderBy('code')
            ->paginate(15);

        return view('masters.locations.list', ['locations' => $locations, 'canCreate' => Gate::allows('create', Location::class)]);
    }
}
