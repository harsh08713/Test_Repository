<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Models\WorkflowRun;
use App\Modules\Marketing\Services\WorkflowDefinition;
use App\Modules\Marketing\Services\WorkflowEngine;
use App\Modules\Marketing\Services\WorkflowService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** One workflow: what it does, its versions, a test against real records, and its runs (with a retry for a failed one). */
#[Title('Workflow')]
class WorkflowShow extends Component
{
    use WithPagination;

    #[Locked]
    public int $workflowId = 0;

    #[Url(as: 'runs')]
    public string $runs = '';

    /** The record chosen for the test: "type:id". */
    public string $sample = '';

    /** @var array<string, mixed>|null */
    public ?array $tested = null;

    public function mount(Workflow $workflow): void
    {
        Gate::authorize('view', $workflow);

        $this->workflowId = $workflow->getKey();
    }

    public function updating(string $property): void
    {
        $property === 'runs' && $this->resetPage();
    }

    public function activate(WorkflowService $service): void
    {
        $this->operate(fn (Workflow $w) => $service->activate($w, $this->actor()), 'The workflow is on. It acts on what happens from now.');
    }

    public function pause(WorkflowService $service): void
    {
        $this->operate(fn (Workflow $w) => $service->pause($w, $this->actor()), 'The workflow is paused.');
    }

    public function resume(WorkflowService $service): void
    {
        $this->operate(fn (Workflow $w) => $service->resume($w, $this->actor()), 'The workflow is on again. What happened while it was paused is not run.');
    }

    public function toggleDryRun(WorkflowService $service): void
    {
        $this->operate(fn (Workflow $w) => $service->setDryRun($w, ! $w->dry_run, $this->actor()), 'Changed.');
    }

    public function retryRun(int $id, WorkflowEngine $engine): void
    {
        $workflow = $this->workflow();
        Gate::authorize('operate', $workflow);
        $run = WorkflowRun::query()->where('workflow_id', $workflow->getKey())->findOrFail($id);

        try {
            $engine->retry($run, $this->actor());
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        session()->flash('status', 'Tried again.');
    }

    public function test(WorkflowEngine $engine): void
    {
        $workflow = $this->workflow();
        Gate::authorize('view', $workflow);
        $this->resetErrorBag();

        if (preg_match('/^(lead|deal|quotation|order):(\d+)$/', $this->sample, $m) !== 1) {
            $this->addError('sample', 'Choose a record to test with.');
            $this->tested = null;

            return;
        }

        $result = $engine->preview($workflow, $workflow->current->definition ?? [], $m[1], (int) $m[2], $this->actor());
        unset($result['facts']);
        $this->tested = $result;
    }

    public function render(WorkflowEngine $engine): View
    {
        $workflow = $this->workflow();
        $workflow->load(['current', 'runner:id,name', 'versions.saver:id,name']);
        $definition = $workflow->current->definition ?? ['params' => [], 'conditions' => [], 'actions' => []];
        $defs = new WorkflowDefinition;

        $runs = WorkflowRun::query()->where('workflow_id', $workflow->getKey())
            ->when(in_array($this->runs, ['ok', 'failed', 'skipped', 'dry_run', 'running'], true), fn (Builder $q) => $q->where('status', $this->runs))
            ->orderByDesc('id')->paginate(15);

        return view('marketing.workflows.show', [
            'workflow' => $workflow, 'definition' => $definition, 'trigger' => WorkflowDefinition::TRIGGERS[$workflow->trigger] ?? null,
            'conditionText' => array_map(fn (array $c) => $defs->describeCondition($c), $definition['conditions'] ?? []),
            'actionText' => array_map(fn (array $a) => WorkflowDefinition::ACTIONS[$a['type']] ?? $a['type'], $definition['actions'] ?? []),
            'runRows' => $runs, 'samples' => $engine->samples($workflow, $this->actor()),
            'can' => ['edit' => Gate::allows('update', $workflow), 'operate' => Gate::allows('operate', $workflow)],
        ]);
    }

    private function workflow(): Workflow
    {
        return Workflow::query()->findOrFail($this->workflowId);
    }

    private function operate(\Closure $step, string $said): void
    {
        $workflow = $this->workflow();
        Gate::authorize('operate', $workflow);
        $this->resetErrorBag();

        try {
            $step($workflow);
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        session()->flash('status', $said);
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
