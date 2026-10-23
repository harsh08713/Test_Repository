<?php

namespace App\Modules\CRM\Http\Livewire\Teams;

use App\Models\User;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Services\SalesScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The teams the signed-in person runs (every team for someone who sees everyone), with their managers and head counts. */
#[Title('Sales teams')]
class SalesTeamList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', SalesTeam::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(SalesScope $scope): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $teams = SalesTeam::query()
            ->with('manager:id,name')
            ->withCount('currentMembers')
            ->when(! $scope->seesEveryone($user), fn ($q) => $q->where('manager_user_id', $user->getKey()))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(15);

        return view('crm.teams.list', ['teams' => $teams, 'canCreate' => Gate::allows('create', SalesTeam::class)]);
    }
}
