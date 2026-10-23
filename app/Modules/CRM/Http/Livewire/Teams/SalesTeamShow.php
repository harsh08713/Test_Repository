<?php

namespace App\Modules\CRM\Http\Livewire\Teams;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Services\SalesTeamService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** A team: its manager, who is on it now, who has been, and the controls to add and remove people. */
#[Title('Sales team')]
class SalesTeamShow extends Component
{
    #[Locked]
    public int $teamId = 0;

    public string $newMember = '';

    public string $joinedOn = '';

    public function mount(SalesTeam $team): void
    {
        Gate::authorize('view', $team);

        $this->teamId = $team->id;
        $this->joinedOn = now()->toDateString();
    }

    #[Computed]
    public function teamModel(): SalesTeam
    {
        $team = SalesTeam::query()->with(['manager:id,name', 'members.user:id,name'])->findOrFail($this->teamId);
        Gate::authorize('view', $team);

        return $team;
    }

    public function addMember(SalesTeamService $service): void
    {
        $team = $this->teamModel();
        Gate::authorize('manageMembers', $team);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->resetErrorBag();

        try {
            $service->addMember($team, (int) $this->newMember, $this->joinedOn, $user);
        } catch (FieldRuleException $e) {
            $this->addError('newMember', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            // the service names its fields "user_id" and "joined_on"; this form calls them newMember and joinedOn
            $this->addError('newMember', $e->validator->errors()->first('user_id') ?: $e->validator->errors()->first('joined_on'));

            return;
        }

        $this->newMember = '';
        $this->joinedOn = now()->toDateString();
        unset($this->teamModel);
        session()->flash('status', 'Added to the team.');
    }

    public function removeMember(int $memberId, SalesTeamService $service): void
    {
        $team = $this->teamModel();
        Gate::authorize('manageMembers', $team);

        $member = SalesTeamMember::query()->where('sales_team_id', $team->getKey())->findOrFail($memberId);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $service->removeMember($member, null, $user);
        } catch (FieldRuleException $e) {
            $this->addError('members', $e->getMessage());

            return;
        }

        unset($this->teamModel);
        session()->flash('status', 'Taken off the team as of today.');
    }

    public function delete(): void
    {
        $team = $this->teamModel();
        Gate::authorize('delete', $team);

        if ($this->deleteBlocker($team) !== null) {
            $this->addError('delete', (string) $this->deleteBlocker($team));

            return;
        }

        $team->delete();
        session()->flash('status', 'Team deleted.');
        $this->redirectRoute('field.teams.index', navigate: false);
    }

    /** Why this team must be switched off rather than deleted, or null when deleting is fine. */
    private function deleteBlocker(SalesTeam $team): ?string
    {
        if ($team->members()->exists()) {
            return 'This team has had members, and their history is kept, so it cannot be deleted. Switch it off instead.';
        }

        if (SalesTarget::query()->where('sales_team_id', $team->getKey())->exists()) {
            return 'Targets have been set for this team, so it cannot be deleted. Switch it off instead.';
        }

        return null;
    }

    public function render(SalesTeamService $service): View
    {
        $team = $this->teamModel();

        $onTeam = SalesTeamMember::query()->whereNull('left_on')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return view('crm.teams.show', [
            'team' => $team,
            'canEdit' => Gate::allows('update', $team),
            'canManage' => Gate::allows('manageMembers', $team),
            'canDelete' => Gate::allows('delete', $team) && $this->deleteBlocker($team) === null,
            // who can still be added: active people who are on no team and are not this team's manager
            'candidates' => array_diff_key($service->staff(), array_flip($onTeam), [(int) $team->manager_user_id => true]),
            'today' => now()->toDateString(),
        ]);
    }
}
