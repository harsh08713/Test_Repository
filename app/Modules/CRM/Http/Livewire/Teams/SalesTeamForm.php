<?php

namespace App\Modules\CRM\Http\Livewire\Teams;

use App\Models\User;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Start a team or change one: its code, name, manager and whether it is switched on. Every rule lives in SalesTeamService. */
#[Title('Sales team')]
class SalesTeamForm extends Component
{
    #[Locked]
    public ?int $teamId = null;

    /** @var array<string, mixed> */
    public array $team = [];

    public function mount(?SalesTeam $editing = null): void
    {
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->teamId = $editing->id;
            $this->team = [
                'code' => $editing->code, 'name' => $editing->name, 'manager_user_id' => (string) $editing->manager_user_id,
                'status' => $editing->status, 'remarks' => (string) ($editing->remarks ?? ''),
            ];

            return;
        }

        Gate::authorize('create', SalesTeam::class);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        // A manager starts a team of their own; someone who sees everyone chooses.
        $this->team = [
            'code' => '', 'name' => '', 'status' => 'active', 'remarks' => '',
            'manager_user_id' => app(SalesScope::class)->seesEveryone($user) ? '' : (string) $user->getKey(),
        ];
    }

    public function save(SalesTeamService $service): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $editing = $this->teamId !== null ? SalesTeam::query()->findOrFail($this->teamId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? SalesTeam::class);

        $saved = $service->saveTeam($editing, ['team' => $this->team], $user);

        session()->flash('status', $editing ? 'Team saved.' : 'Team started. Add its members next.');
        $this->redirectRoute('field.teams.show', $saved, navigate: false);
    }

    public function render(SalesTeamService $service, SalesScope $scope): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $staff = $service->staff();

        return view('crm.teams.form', [
            'editing' => $this->teamId !== null,
            // someone who does not see everyone can only be their own team's manager
            'managers' => $scope->seesEveryone($user) ? $staff : array_intersect_key($staff, [$user->getKey() => true]),
        ]);
    }
}
