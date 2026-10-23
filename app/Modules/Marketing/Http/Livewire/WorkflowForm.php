<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Services\WorkflowDefinition;
use App\Modules\Marketing\Services\WorkflowService;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Location;
use App\Modules\Workspace\Enums\TaskPriority;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The workflow builder: a trigger, conditions and actions picked from fixed lists. Saving writes a new version; the person who saves is the person it
 * runs as. The screen only holds what was picked; the service checks all of it again.
 */
#[Title('Workflow')]
class WorkflowForm extends Component
{
    /** @var array{name: string, description: string, trigger: string} */
    public array $w = ['name' => '', 'description' => '', 'trigger' => 'lead_created'];

    /** @var array<string, string> */
    public array $params = [];

    /** @var list<array{field: string, op: string, value: mixed}> */
    public array $conditions = [];

    /** @var list<array<string, mixed>> */
    public array $actions = [];

    #[Locked]
    public int $workflowId = 0;

    public function mount(?int $editing = null): void
    {
        if ($editing === null) {
            Gate::authorize('create', Workflow::class);
            $this->actions = [$this->blankAction()];

            return;
        }

        $workflow = Workflow::query()->with('current')->findOrFail($editing);
        Gate::authorize('update', $workflow);

        $this->workflowId = $workflow->getKey();
        $this->w = ['name' => $workflow->name, 'description' => (string) $workflow->description, 'trigger' => $workflow->trigger];
        $definition = $workflow->current->definition ?? [];
        $this->params = array_map('strval', $definition['params'] ?? []);
        $this->conditions = array_map(fn (array $c) => ['field' => $c['field'], 'op' => $c['op'], 'value' => is_array($c['value']) ? array_map('strval', $c['value']) : (string) $c['value']], $definition['conditions'] ?? []);
        $this->actions = array_map(fn (array $a) => array_map(fn ($v) => is_scalar($v) ? (string) $v : $v, $a), $definition['actions'] ?? []) ?: [$this->blankAction()];
    }

    public function updatedWTrigger(): void
    {
        $this->params = [];
    }

    public function addCondition(): void
    {
        count($this->conditions) < WorkflowDefinition::MAX_CONDITIONS && $this->conditions[] = ['field' => 'stage', 'op' => 'in', 'value' => []];
    }

    public function removeCondition(int $i): void
    {
        unset($this->conditions[$i]);
        $this->conditions = array_values($this->conditions);
    }

    public function updatedConditions(mixed $value, string $key): void
    {
        // changing what is checked starts its value afresh
        if (preg_match('/^(\d+)\.field$/', $key, $m) === 1) {
            $this->conditions[(int) $m[1]]['value'] = $value === 'value' ? '' : [];
            $this->conditions[(int) $m[1]]['op'] = $value === 'value' ? 'gte' : 'in';
        }
    }

    public function addAction(): void
    {
        count($this->actions) < WorkflowDefinition::MAX_ACTIONS && $this->actions[] = $this->blankAction();
    }

    public function removeAction(int $i): void
    {
        unset($this->actions[$i]);
        $this->actions = array_values($this->actions);
    }

    public function save(WorkflowService $service): mixed
    {
        $user = $this->actor();
        $workflow = $this->workflowId === 0 ? null : Workflow::query()->findOrFail($this->workflowId);
        Gate::authorize($workflow === null ? 'create' : 'update', $workflow ?? Workflow::class);
        $this->resetErrorBag();

        $input = [...$this->w, 'params' => $this->params, 'conditions' => $this->conditions, 'actions' => $this->actions];
        $saved = $workflow === null ? $service->create($input, $user) : $service->update($workflow, $input, $user);

        session()->flash('status', $workflow === null ? 'Workflow saved. Test it on a real record, then switch it on.' : 'Saved as a new version. It now runs as you.');

        return $this->redirectRoute('marketing.workflows.show', $saved, navigate: true);
    }

    public function render(): View
    {
        $user = $this->actor();
        $trigger = WorkflowDefinition::TRIGGERS[$this->w['trigger']] ?? WorkflowDefinition::TRIGGERS['lead_created'];

        return view('marketing.workflows.form', [
            'editing' => $this->workflowId !== 0,
            'triggers' => WorkflowDefinition::TRIGGERS,
            'trigger' => $trigger,
            'fields' => WorkflowDefinition::FIELDS,
            'actionTypes' => WorkflowDefinition::ACTIONS,
            'stages' => collect(LeadStage::cases())->mapWithKeys(fn (LeadStage $s) => [$s->value => $s->label()])->all(),
            'sources' => LeadSource::query()->orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'people' => ReportInput::people($user),
            'templates' => MessageTemplate::query()->orderBy('name')->get(['id', 'name', 'channel', 'approval_status', 'status']),
            'priorities' => TaskPriority::options(),
        ]);
    }

    /** @return array<string, mixed> */
    private function blankAction(): array
    {
        return ['type' => 'create_task', 'title' => '', 'assignee' => 'owner', 'due_hours' => '24', 'hours' => '24', 'priority' => 'normal', 'message' => '', 'user_id' => '', 'reason' => '', 'template_id' => ''];
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
