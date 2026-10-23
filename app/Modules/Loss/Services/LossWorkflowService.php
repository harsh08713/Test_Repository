<?php

namespace App\Modules\Loss\Services;

use App\Models\User;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\WorkflowState;
use App\Modules\Industry\Models\WorkflowTransition;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossImpactLine;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RootCauseAnalysis;
use App\Modules\Loss\Models\WorkflowEvent;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a loss incident through its workflow — the template's own, or the default (Draft → Submitted → Triage → Investigation → Action required →
 * Verification → Closed, with Rejected, On hold and Reopened) — at the template **version the incident was made under**. The only way `state` changes.
 *
 * A move must be one of the workflow's transitions from the current state; the actor must hold the permission it names; a move that requires a
 * comment needs one; a move that requires approval needs `loss_incident.approve` **and** someone other than the reporter (maker–checker). On top,
 * some states have conditions that make the record honest:
 *
 *  - **investigation** needs an investigator;
 *  - **action_required** needs a root-cause analysis;
 *  - **verification** needs at least one corrective action, and none still open or in progress;
 *  - **closed** needs every corrective action verified or rejected, and every current impact figure validated or rejected.
 *
 * Every move is written to `workflow_events` (append-only) and the audit log; reaching a final state stamps `closed_at`, re-opening clears it.
 */
final class LossWorkflowService
{
    /** @var array<string, array{states: Collection<int, WorkflowState>, transitions: Collection<int, WorkflowTransition>}> template:version => workflow (system data: it does not change during a request) */
    private array $memo = [];

    public function __construct(private readonly IndustryTemplateService $templates) {}

    /** The state a new incident starts in. */
    public function initialState(IndustryTemplate $template, int $version): string
    {
        $state = $this->templates->workflow($template, $version)['states']->firstWhere('is_initial', true);

        return $state instanceof WorkflowState ? $state->code : 'draft';
    }

    /** @return Collection<int, WorkflowState> */
    public function states(LossIncident $incident): Collection
    {
        return $this->workflowOf($incident)['states'];
    }

    public function isFinal(LossIncident $incident): bool
    {
        return (bool) $this->states($incident)->firstWhere('code', $incident->state)?->is_final;
    }

    public function stateName(LossIncident $incident, ?string $code = null): string
    {
        $code ??= $incident->state;

        $state = $this->states($incident)->firstWhere('code', $code);

        return $state instanceof WorkflowState ? $state->name : $code;
    }

    /**
     * The moves this person may make from where the incident stands (the conditions are checked when the move is made, and say why if not met).
     *
     * @return Collection<int, WorkflowTransition>
     */
    public function moves(LossIncident $incident, User $user): Collection
    {
        return $this->workflowOf($incident)['transitions']
            ->where('from_state', $incident->state)
            ->filter(fn (WorkflowTransition $t) => $t->required_permission === null || $user->can($t->required_permission))
            ->filter(fn (WorkflowTransition $t) => ! $t->requires_approval || ((int) $incident->reporter_id !== (int) $user->getKey() && $user->can('loss_incident.approve')))
            ->values();
    }

    /** @throws ValidationException */
    public function transition(LossIncident $incident, string $toState, ?string $comment, User $actor): LossIncident
    {
        $comment = trim((string) $comment);

        return DB::transaction(function () use ($incident, $toState, $comment, $actor) {
            $fresh = LossIncident::query()->lockForUpdate()->findOrFail($incident->getKey());

            $move = $this->workflowOf($fresh)['transitions']->first(fn (WorkflowTransition $t) => $t->from_state === $fresh->state && $t->to_state === $toState);

            if (! $move instanceof WorkflowTransition) {
                throw ValidationException::withMessages(['workflow' => 'That move is not possible from '.$this->stateName($fresh).'.']);
            }

            if ($move->required_permission !== null && ! $actor->can($move->required_permission)) {
                throw ValidationException::withMessages(['workflow' => 'You are not allowed to make that move.']);
            }

            if ($move->requires_approval && (! $actor->can('loss_incident.approve') || (int) $fresh->reporter_id === (int) $actor->getKey())) {
                throw ValidationException::withMessages(['workflow' => 'This move needs approval by someone other than the person who reported the incident.']);
            }

            if ($move->requires_comment && (mb_strlen($comment) < 3 || mb_strlen($comment) > 1000)) {
                throw ValidationException::withMessages(['comment' => 'Please say why (3 to 1000 characters).']);
            }

            if (($problem = $this->conditionFor($fresh, $toState)) !== null) {
                throw ValidationException::withMessages(['workflow' => $problem]);
            }

            $from = $fresh->state;
            $final = (bool) $this->states($fresh)->firstWhere('code', $toState)?->is_final;

            $fresh->forceFill([
                'state' => $toState,
                'closed_at' => $final ? now() : ($toState === 'reopened' ? null : $fresh->closed_at),
                'updated_by' => $actor->getKey(),
            ])->save();

            WorkflowEvent::record($fresh, $from, $toState, $move->action, $actor, $comment === '' ? null : $comment);
            activity('loss_incidents')->causedBy($actor)->performedOn($fresh)->event('moved')
                ->withProperties(['from' => $from, 'to' => $toState, 'action' => $move->action, 'comment' => $comment === '' ? null : $comment])->log('moved');

            return $fresh;
        });
    }

    /** Why the incident cannot go to this state right now, or null. */
    public function conditionFor(LossIncident $incident, string $toState): ?string
    {
        $actions = CorrectiveAction::withoutGlobalScopes(Tenancy::SCOPES)->where('loss_incident_id', $incident->getKey());

        return match ($toState) {
            'investigation' => $incident->investigator_id === null ? 'Name an investigator first.' : null,
            'action_required' => RootCauseAnalysis::withoutGlobalScopes(Tenancy::SCOPES)->where('loss_incident_id', $incident->getKey())->doesntExist()
                ? 'Record the root cause first.' : null,
            'verification' => match (true) {
                (clone $actions)->doesntExist() => 'Add at least one corrective or preventive action first.',
                (clone $actions)->whereIn('state', ['open', 'in_progress'])->exists() => 'Some actions are not done yet.',
                default => null,
            },
            'closed' => match (true) {
                (clone $actions)->whereNotIn('state', ['verified', 'rejected'])->exists() => 'Every action has to be verified (or rejected) before the incident is closed.',
                LossImpactLine::withoutGlobalScopes(Tenancy::SCOPES)->where('loss_incident_id', $incident->getKey())->whereNull('superseded_at')->where('validation_state', 'pending')->exists() => 'Every figure has to be validated or rejected before the incident is closed.',
                default => null,
            },
            default => null,
        };
    }

    /** @return array{states: Collection<int, WorkflowState>, transitions: Collection<int, WorkflowTransition>} */
    private function workflowOf(LossIncident $incident): array
    {
        $key = $incident->industry_template_id.':'.$incident->template_version;

        return $this->memo[$key] ??= $this->templates->workflow(IndustryTemplate::query()->findOrFail($incident->industry_template_id), (int) $incident->template_version);
    }
}
