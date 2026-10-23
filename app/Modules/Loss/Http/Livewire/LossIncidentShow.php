<?php

namespace App\Modules\Loss\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossImpactLine;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RootCauseAnalysis;
use App\Modules\Loss\Models\WorkflowEvent;
use App\Modules\Loss\Services\CapaService;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Loss\Services\LossSources;
use App\Modules\Loss\Services\LossWorkflowService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One loss incident and everything done about it: its moves through the workflow, who investigates and owns it, containment, its figures (kept
 * apart by kind, recalculated by supersession, validated by someone else), root causes, corrective and preventive actions, and its history.
 * Every action authorises against the incident and hands the rule to the service.
 */
#[Title('Loss incident')]
class LossIncidentShow extends Component
{
    #[Locked]
    public int $incidentId = 0;

    /** '' or the dialog that is open: move, impact, recalc, reject-line, rca, action, complete, verify, send-back, reject-action */
    public string $dialog = '';

    /** The workflow state a move goes to, or the line / action a dialog is about. */
    #[Locked]
    public string $target = '';

    public string $comment = '';

    public string $investigatorId = '';

    public string $ownerId = '';

    public string $containment = '';

    /** @var array<string, mixed> */
    public array $line = [];

    /** @var array<string, mixed> */
    public array $rca = [];

    /** @var array<string, mixed> */
    public array $capa = [];

    public string $resultAmount = '';

    public function mount(LossIncident $incident): void
    {
        Gate::authorize('view', $incident);

        $this->incidentId = $incident->id;
        $this->investigatorId = (string) $incident->investigator_id;
        $this->ownerId = (string) $incident->owner_id;
        $this->containment = (string) $incident->containment_notes;
    }

    #[Computed]
    public function incidentModel(): LossIncident
    {
        $incident = LossIncident::query()->with(['template', 'category', 'department', 'costCenter:id,name', 'item:id,code,name', 'batch:id,batch_no', 'unit:id,code', 'location:id,name',
            'reporter:id,name', 'investigator:id,name', 'owner:id,name', 'primary'])->findOrFail($this->incidentId);
        Gate::authorize('view', $incident);

        return $incident;
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    // ---- dialogs --------------------------------------------------------------------------------------------------

    public function openDialog(string $which, string $target = ''): void
    {
        abort_unless(in_array($which, ['move', 'impact', 'recalc', 'reject-line', 'rca', 'action', 'complete', 'verify', 'send-back', 'reject-action'], true), 404);
        $this->resetErrorBag();
        $this->dialog = $which;
        $this->target = $target;
        $this->comment = '';
        $this->resultAmount = '';

        if ($which === 'impact') {
            $this->line = ['impact_type' => 'actual', 'formula_key' => 'material_cost', 'inputs' => [], 'assumptions' => '', 'confidence_pct' => '', 'target_period' => ''];
        } elseif ($which === 'recalc') {
            $old = LossImpactLine::query()->where('loss_incident_id', $this->incidentId)->findOrFail((int) $target);
            $this->line = ['impact_type' => $old->impact_type, 'formula_key' => $old->formula_key, 'inputs' => $old->inputs, 'assumptions' => (string) $old->assumptions,
                'confidence_pct' => (string) $old->confidence_pct, 'target_period' => (string) $old->target_period];
        } elseif ($which === 'rca') {
            $this->rca = ['method' => 'five_whys', 'whys' => ['', '', '', '', ''], 'cause_category' => '', 'contributing_factors' => '', 'root_cause' => '', 'findings' => ''];
        } elseif ($which === 'action') {
            $this->capa = ['action_type' => 'corrective', 'title' => '', 'description' => '', 'owner_id' => '', 'approver_id' => '', 'due_date' => now()->addDays(14)->toDateString(),
                'expected_benefit_amount' => '', 'baseline_value' => '', 'root_cause_analysis_id' => ''];
        }
    }

    public function closeDialog(): void
    {
        $this->dialog = $this->target = '';
        $this->resetErrorBag();
    }

    // ---- workflow, assignment, containment ----------------------------------------------------------------------

    public function makeMove(LossWorkflowService $workflow): void
    {
        $this->attempt(fn () => $workflow->transition($this->incidentModel(), $this->target, $this->comment, $this->actor()), 'Moved to '.$workflow->stateName($this->incidentModel(), $this->target).'.');
    }

    public function saveAssignment(LossIncidentService $service): void
    {
        $this->attempt(fn () => $service->assign($this->incidentModel(), ctype_digit($this->investigatorId) ? (int) $this->investigatorId : null,
            ctype_digit($this->ownerId) ? (int) $this->ownerId : null, $this->actor()), 'Saved who investigates and who owns it.');
    }

    public function saveContainment(LossIncidentService $service): void
    {
        $this->attempt(fn () => $service->contain($this->incidentModel(), $this->containment, $this->actor()), 'Containment recorded.');
    }

    // ---- figures -----------------------------------------------------------------------------------------------------

    public function addImpact(LossCalculationService $calc): void
    {
        $this->attempt(fn () => $calc->addLine($this->incidentModel(), $this->line, $this->actor()), 'Figure added. Someone else validates it.', 'line');
    }

    public function recalculateLine(LossCalculationService $calc): void
    {
        $old = LossImpactLine::query()->where('loss_incident_id', $this->incidentId)->findOrFail((int) $this->target);
        $this->attempt(fn () => $calc->recalculate($old, $this->line, $this->actor()), 'Recalculated. The earlier figure is kept, marked as replaced.', 'line');
    }

    public function validateFigure(int $lineId, LossCalculationService $calc): void
    {
        $line = LossImpactLine::query()->where('loss_incident_id', $this->incidentId)->findOrFail($lineId);
        $this->attempt(fn () => $calc->validateLine($line, $this->actor()), 'Figure validated.');
    }

    public function rejectFigure(LossCalculationService $calc): void
    {
        $line = LossImpactLine::query()->where('loss_incident_id', $this->incidentId)->findOrFail((int) $this->target);
        $this->attempt(fn () => $calc->rejectLine($line, $this->comment, $this->actor()), 'Figure rejected.');
    }

    // ---- root cause and actions ----------------------------------------------------------------------------------

    public function saveRootCause(LossIncidentService $service): void
    {
        $input = [...$this->rca, 'contributing_factors' => preg_split('/\R/', (string) ($this->rca['contributing_factors'] ?? '')) ?: []];
        $this->attempt(fn () => $service->recordRootCause($this->incidentModel(), $input, $this->actor()), 'Root cause recorded.', 'rca');
    }

    public function addAction(CapaService $capa): void
    {
        $this->attempt(fn () => $capa->create($this->incidentModel(), $this->capa, $this->actor()), 'Action added.', 'capa');
    }

    public function startAction(int $actionId, CapaService $capa): void
    {
        $this->attempt(fn () => $capa->start($this->action($actionId), $this->actor()), 'Action started.');
    }

    public function completeAction(CapaService $capa): void
    {
        $this->attempt(fn () => $capa->complete($this->action((int) $this->target), $this->comment, $this->actor()), 'Action done: it is waiting to be verified.');
    }

    public function verifyAction(CapaService $capa): void
    {
        $this->attempt(fn () => $capa->verify($this->action((int) $this->target), $this->resultAmount, $this->comment, $this->actor()), 'Action verified; its saving now counts.');
    }

    public function sendBackAction(CapaService $capa): void
    {
        $this->attempt(fn () => $capa->sendBack($this->action((int) $this->target), $this->comment, $this->actor()), 'Action sent back.');
    }

    public function rejectAction(CapaService $capa): void
    {
        $this->attempt(fn () => $capa->reject($this->action((int) $this->target), $this->comment, $this->actor()), 'Action rejected.');
    }

    private function action(int $id): CorrectiveAction
    {
        return CorrectiveAction::query()->where('loss_incident_id', $this->incidentId)->findOrFail($id);
    }

    /**
     * Run a service call and show what it says: field messages under the fields (prefixed with the form's array), anything else above the page.
     *
     * @param  callable(): mixed  $call
     */
    private function attempt(callable $call, string $done, string $prefix = ''): void
    {
        $this->resetErrorBag();

        try {
            $call();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $field = in_array($key, ['comment', 'reason', 'completion_notes', 'verification_notes'], true) ? 'comment'
                    : ($key === 'verified_result_amount' ? 'resultAmount' : ($prefix !== '' && ! in_array($key, ['workflow', 'line', 'incident', 'state'], true) ? "{$prefix}.{$key}" : 'workflow'));
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('workflow', 'You are not allowed to do that.');

            return;
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        unset($this->incidentModel);
        $this->dialog = $this->target = '';
        session()->flash('status', $done);
    }

    public function render(LossWorkflowService $workflow, LossCalculationService $calc): View
    {
        $incident = $this->incidentModel();
        $user = $this->actor();
        $lines = $incident->impactLines()->with(['creator:id,name', 'validator:id,name'])->get();
        $people = User::query()->where('is_active', true)->whereHas('companies', fn ($q) => $q->where('companies.id', $incident->company_id))->orderBy('name')->get();

        return view('loss.show', [
            'incident' => $incident,
            'stateName' => $workflow->stateName($incident),
            'isFinal' => $workflow->isFinal($incident),
            'moves' => $workflow->moves($incident, $user),
            'states' => $workflow->states($incident),
            'lines' => $lines,
            'totals' => collect(LossImpactLine::TYPES)->map(fn ($label, $type) => [
                'label' => $label,
                'amount' => bcadd((string) $lines->filter(fn ($l) => $l->impact_type === $type && $l->isCurrent() && $l->validation_state !== 'rejected')->sum('amount'), '0', 2),
                'validated' => bcadd((string) $lines->filter(fn ($l) => $l->impact_type === $type && $l->isCurrent() && $l->validation_state === 'validated')->sum('amount'), '0', 2),
            ])->all(),
            'analyses' => $incident->rootCauses()->with('analyst:id,name')->get(),
            'actions' => $incident->actions()->with(['owner:id,name', 'approver:id,name'])->get(),
            'events' => WorkflowEvent::query()->where('subject_type', $incident->getMorphClass())->where('subject_id', $incident->id)->with('actor:id,name')->orderByDesc('id')->get(),
            'history' => Gate::allows('viewAudit', $incident)
                ? ActivityLog::query()->with('causer')->where('subject_type', $incident->getMorphClass())->where('subject_id', $incident->id)->latest('id')->limit(40)->get()
                : collect(),
            'investigators' => $people->filter(fn (User $u) => $u->can('loss_incident.view')),
            'actionOwners' => $people->filter(fn (User $u) => $u->can('corrective_action.view') || $u->can('corrective_action.edit')),
            'verifiers' => $people->filter(fn (User $u) => $u->can('corrective_action.approve')),
            'formulas' => $calc->formulas(),
            'types' => LossImpactLine::TYPES,
            'methods' => RootCauseAnalysis::METHODS,
            'categories' => RootCauseAnalysis::CATEGORIES,
            'sourceLabel' => $incident->source_type ? (app(LossSources::class)->describe($incident->source_type, (int) $incident->source_id)['label'] ?? LossSources::TYPES[$incident->source_type] ?? $incident->source_type) : null,
            'can' => [
                'edit' => Gate::allows('update', $incident),
                'assign' => Gate::allows('assign', $incident),
                'investigate' => Gate::allows('investigate', $incident),
                'validate' => Gate::allows('validateImpact', $incident),
                'addAction' => ! $workflow->isFinal($incident) && Gate::allows('create', CorrectiveAction::class),
            ],
            'me' => $user->getKey(),
        ]);
    }
}
