<?php

namespace App\Modules\Loss\Services;

use App\Models\User;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RootCauseAnalysis;
use App\Modules\Loss\Models\WorkflowEvent;
use App\Modules\Loss\Notifications\LossAlertNotification;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The one door for corrective and preventive actions (CAPA).
 *
 *  open ──start──▶ in_progress ──complete──▶ done ──verify──▶ verified
 *    │                 ▲                        │
 *    └──reject──▶ rejected ◀──reject── (open / in progress / done)      done ──send back──▶ in_progress
 *
 *  - Made on an incident that is still open, by someone holding `corrective_action.create`; numbered first. The owner must be able to work actions in
 *    this company; a named approver must hold `corrective_action.approve`.
 *  - Its **owner** (or someone who may edit actions) starts it and says it is done, with notes.
 *  - Someone holding `corrective_action.approve` who is **not its owner** (and is its named approver, if there is one) verifies it and records the
 *    **verified result** — the realised saving, counted only from then on — or sends it back, or rejects it, with a reason.
 *  - Every move is in `workflow_events` and the audit log.
 *  - **Overdue** is not a state: `escalateOverdue()` (the scheduler) tells the owner and approver once when an open or in-progress action passes its due date.
 */
final class CapaService
{
    public function __construct(private readonly TenantContext $tenant, private readonly NumberSeriesService $numbers, private readonly LossWorkflowService $workflow) {}

    /**
     * @param  array<string, mixed>  $input  action_type, title, description, owner_id, approver_id, due_date, expected_benefit_amount, baseline_value, root_cause_analysis_id
     *
     * @throws ValidationException
     */
    public function create(LossIncident $incident, array $input, User $actor): CorrectiveAction
    {
        Gate::forUser($actor)->authorize('create', CorrectiveAction::class);
        Gate::forUser($actor)->authorize('view', $incident);

        if ($this->workflow->isFinal($incident)) {
            throw ValidationException::withMessages(['incident' => 'This incident is closed: re-open it before adding actions.']);
        }

        $data = $this->validated($incident, $input);

        return DB::transaction(function () use ($incident, $data, $actor) {
            $number = $this->numbers->next(DocumentType::CorrectiveAction, (int) $incident->location_id, now()->startOfDay());

            $action = new CorrectiveAction;
            $action->forceFill([
                'company_id' => (int) $incident->company_id, 'location_id' => (int) $incident->location_id, 'loss_incident_id' => $incident->getKey(),
                'action_number' => $number, 'state' => 'open', 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ] + $data)->save();

            WorkflowEvent::record($action, null, 'open', 'created', $actor);

            return $action;
        });
    }

    public function start(CorrectiveAction $action, User $actor): CorrectiveAction
    {
        Gate::forUser($actor)->authorize('work', $action);

        return $this->move($action, ['open'], 'in_progress', 'start', $actor);
    }

    public function complete(CorrectiveAction $action, string $notes, User $actor): CorrectiveAction
    {
        Gate::forUser($actor)->authorize('work', $action);
        $notes = $this->reason($notes, 'completion_notes', 'Say what was done (3 to 2000 characters).');

        return $this->move($action, ['open', 'in_progress'], 'done', 'complete', $actor, $notes, ['completed_at' => now(), 'completion_notes' => $notes]);
    }

    /** Verify a done action and record what it actually saved (0 if nothing): the realised saving. */
    public function verify(CorrectiveAction $action, string $resultAmount, string $notes, User $actor): CorrectiveAction
    {
        Gate::forUser($actor)->authorize('verify', $action);
        $notes = $this->reason($notes, 'verification_notes', 'Say how it was verified (3 to 2000 characters).');
        $resultAmount = trim($resultAmount);

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $resultAmount) !== 1) {
            throw ValidationException::withMessages(['verified_result_amount' => 'Enter the verified saving in rupees (0 if there was none).']);
        }

        return $this->move($action, ['done'], 'verified', 'verify', $actor, $notes, [
            'verified_by' => $actor->getKey(), 'verified_at' => now(), 'verification_notes' => $notes, 'verified_result_amount' => bcadd($resultAmount, '0', 2),
        ]);
    }

    public function sendBack(CorrectiveAction $action, string $reason, User $actor): CorrectiveAction
    {
        Gate::forUser($actor)->authorize('verify', $action);

        return $this->move($action, ['done'], 'in_progress', 'send_back', $actor, $this->reason($reason, 'reason', 'Say why it goes back (3 to 2000 characters).'), ['completed_at' => null]);
    }

    public function reject(CorrectiveAction $action, string $reason, User $actor): CorrectiveAction
    {
        Gate::forUser($actor)->authorize('verify', $action);

        return $this->move($action, ['open', 'in_progress', 'done'], 'rejected', 'reject', $actor, $this->reason($reason, 'reason', 'Say why it is rejected (3 to 2000 characters).'));
    }

    /**
     * Tell the owner and approver of every action that has passed its due date while still open or in progress — once each (claimed by a
     * conditional UPDATE, so overlapping runs never send twice). Returns how many were escalated.
     */
    public function escalateOverdue(): int
    {
        $sent = 0;
        $due = CorrectiveAction::withoutGlobalScopes(Tenancy::SCOPES)->whereIn('state', ['open', 'in_progress'])->whereNull('escalated_at')
            ->where('due_date', '<', now()->toDateString())->orderBy('due_date')->limit(500)->get();

        foreach ($due as $action) {
            $claimed = CorrectiveAction::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($action->getKey())->whereNull('escalated_at')->update(['escalated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $this->tenant->withCompany((int) $action->company_id, function () use ($action) {
                $note = new LossAlertNotification('capa_overdue', "{$action->action_number} — {$action->title}",
                    "Corrective action {$action->action_number} was due on {$action->due_date->format('d-m-Y')} and is not done.", route('loss.actions.index', ['show' => 'overdue']));

                foreach (array_unique(array_filter([$action->owner_id, $action->approver_id])) as $userId) {
                    User::query()->find($userId)?->notify($note);
                }
            });

            $sent++;
        }

        return $sent;
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function move(CorrectiveAction $action, array $from, string $to, string $verb, User $actor, ?string $reason = null, array $extra = []): CorrectiveAction
    {
        return DB::transaction(function () use ($action, $from, $to, $verb, $actor, $reason, $extra) {
            $fresh = CorrectiveAction::query()->lockForUpdate()->findOrFail($action->getKey());

            if (! in_array($fresh->state, $from, true)) {
                throw ValidationException::withMessages(['state' => 'This action is '.strtolower($fresh->stateLabel()).': that cannot be done now.']);
            }

            $was = $fresh->state;
            $fresh->forceFill(['state' => $to, 'updated_by' => $actor->getKey()] + $extra)->save();   // audited by the model, field by field
            WorkflowEvent::record($fresh, $was, $to, $verb, $actor, $reason);

            return $fresh;
        });
    }

    private function reason(string $text, string $field, string $message): string
    {
        $text = trim($text);

        if (mb_strlen($text) < 3 || mb_strlen($text) > 2000) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(LossIncident $incident, array $input): array
    {
        $errors = [];
        $text = fn (string $k) => trim((string) ($input[$k] ?? ''));
        $int = fn (string $k) => is_numeric($input[$k] ?? null) ? (int) $input[$k] : null;
        $companyId = (int) $incident->company_id;

        $type = $text('action_type');
        if (! in_array($type, ['corrective', 'preventive'], true)) {
            $errors['action_type'] = 'Choose corrective or preventive.';
        }

        if (mb_strlen($text('title')) < 3 || mb_strlen($text('title')) > 200) {
            $errors['title'] = 'Give the action a title (3 to 200 characters).';
        }

        if (mb_strlen($text('description')) > 5000) {
            $errors['description'] = 'At most 5000 characters.';
        }

        $owner = ($id = $int('owner_id')) === null ? null : User::query()->where('is_active', true)->find($id);
        if ($owner === null || ! $this->tenant->userCanAccessCompany($owner, $companyId) || ! ($owner->can('corrective_action.view') || $owner->can('corrective_action.edit'))) {
            $errors['owner_id'] = 'Choose an owner in this company who can work on corrective actions.';
        }

        $approverId = $int('approver_id');
        if ($approverId !== null) {
            $approver = User::query()->where('is_active', true)->find($approverId);

            if ($approver === null || ! $this->tenant->userCanAccessCompany($approver, $companyId) || ! $approver->can('corrective_action.approve') || $approverId === $owner?->getKey()) {
                $errors['approver_id'] = 'Choose an approver (not the owner) who may verify corrective actions.';
            }
        }

        $due = $text('due_date');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $due);
        if ($parsed === false || $parsed->format('Y-m-d') !== $due || $due < now()->toDateString()) {
            $errors['due_date'] = 'Enter a due date from today on.';
        }

        foreach (['expected_benefit_amount', 'baseline_value'] as $field) {
            if ($text($field) !== '' && preg_match('/^\d{1,13}(\.\d{1,2})?$/', $text($field)) !== 1) {
                $errors[$field] = 'Enter an amount in rupees.';
            }
        }

        $rcaId = $int('root_cause_analysis_id');
        if ($rcaId !== null && ! RootCauseAnalysis::query()->whereKey($rcaId)->where('loss_incident_id', $incident->getKey())->exists()) {
            $errors['root_cause_analysis_id'] = 'That analysis is not one of this incident’s.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $money = fn (string $k) => $text($k) === '' ? null : bcadd($text($k), '0', 2);

        return [
            'action_type' => $type, 'title' => $text('title'), 'description' => $text('description') ?: null, 'owner_id' => $owner->getKey(), 'approver_id' => $approverId,
            'due_date' => $due, 'expected_benefit_amount' => $money('expected_benefit_amount'), 'baseline_value' => $money('baseline_value'), 'root_cause_analysis_id' => $rcaId,
        ];
    }
}
