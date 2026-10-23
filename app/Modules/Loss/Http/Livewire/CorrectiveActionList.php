<?php

namespace App\Modules\Loss\Http\Livewire;

use App\Models\User;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Corrective and preventive actions: mine, overdue, waiting to be verified, or all of them. Someone who may only work their own actions (a CAPA owner
 * without `corrective_action.view`) sees only theirs. Actions are worked from their incident's page.
 */
#[Title('Corrective actions')]
class CorrectiveActionList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    /** mine | overdue | to_verify | open | all */
    #[Url(as: 'show')]
    public string $show = 'open';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->can('corrective_action.view') || $user->can('corrective_action.edit')), 403);

        if (! $user->can('corrective_action.view')) {
            $this->show = 'mine';
        }
    }

    public function updatingShow(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $onlyMine = ! $user->can('corrective_action.view') || $this->show === 'mine';

        $actions = CorrectiveAction::query()
            ->when($onlyMine, fn (Builder $q) => $q->where('owner_id', $user->getKey()))
            ->when($this->show === 'overdue', fn (Builder $q) => $q->whereIn('state', ['open', 'in_progress'])->where('due_date', '<', now()->toDateString()))
            ->when($this->show === 'to_verify', fn (Builder $q) => $q->where('state', 'done'))
            ->when(in_array($this->show, ['open', 'mine'], true), fn (Builder $q) => $q->whereIn('state', ['open', 'in_progress', 'done']))
            ->with(['incident:id,incident_number,title', 'owner:id,name', 'location:id,name'])
            ->orderBy('due_date')->orderBy('id')->paginate(self::PER_PAGE);

        return view('loss.actions', [
            'actions' => $actions,
            'tabs' => $user->can('corrective_action.view')
                ? ['open' => 'Open', 'mine' => 'Mine', 'overdue' => 'Overdue', 'to_verify' => 'To verify', 'all' => 'All']
                : ['mine' => 'Mine'],
            'canSeeIncidents' => Gate::allows('viewAny', LossIncident::class),
        ]);
    }
}
