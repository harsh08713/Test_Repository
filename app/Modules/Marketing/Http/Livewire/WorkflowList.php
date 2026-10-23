<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Services\WorkflowDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The workflows: what starts each, whether it is on, and how its recent runs went. */
#[Title('Workflows')]
class WorkflowList extends Component
{
    use WithPagination;

    #[Url(as: 'state')]
    public string $state = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Workflow::class);
    }

    public function updating(string $property): void
    {
        $property === 'state' && $this->resetPage();
    }

    public function render(): View
    {
        $workflows = Workflow::query()->with('runner:id,name')
            ->withCount(['runs as runs_ok' => fn (Builder $q) => $q->where('status', 'ok'), 'runs as runs_failed' => fn (Builder $q) => $q->where('status', 'failed')])
            ->when(in_array($this->state, ['draft', 'active', 'paused'], true), fn (Builder $q) => $q->where('state', $this->state))
            ->orderByDesc('id')->paginate(20);

        return view('marketing.workflows.list', ['workflows' => $workflows, 'triggers' => WorkflowDefinition::TRIGGERS, 'canCreate' => Gate::allows('create', Workflow::class)]);
    }
}
