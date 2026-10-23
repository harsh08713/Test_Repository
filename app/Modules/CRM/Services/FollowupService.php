<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\FollowupType;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plans follow-ups on a lead and records what came of them. The single gate for follow-up data (the lead page, the
 * calendar and later the mobile API share it); it does not decide who may (LeadPolicy::followUp and FollowupPolicy do).
 *
 *  - **Plan** (`schedule`): a time in the future, a type, and who is to do it — the lead's owner by default; otherwise the
 *    person planning it or someone they manage, who must work in the lead's branch. Only an open lead gets new follow-ups.
 *  - **Done** (`complete`): needs an outcome from the master. An outcome that "needs a next follow-up" (Call Back Later,
 *    Meeting Fixed) is refused without a date for it, and the next one is planned in the same step, linked to this one.
 *    An outcome that "counts as contact" stamps the lead as contacted now and moves a *new* lead to *contacted*.
 *  - **Log** (`log`): something that was just done without having been planned — recorded as done, with the same rules.
 *  - **Move** (`reschedule`) and **call off** (`cancel`, with a reason) apply to planned follow-ups only; a follow-up that
 *    is done is a record of what happened and never changes.
 *
 * After every change the lead's "next follow-up" is brought back in step. Reminding the person, and telling their
 * manager when a follow-up has been left overdue, is the scheduler's job (`crm:followup-reminders`): moving a follow-up
 * re-arms both.
 */
final class FollowupService
{
    /** How far ahead a follow-up may be planned. */
    public const MAX_DAYS_AHEAD = 730;

    public function __construct(
        private readonly SalesScope $scope,
        private readonly SalesTeamService $teams,
        private readonly LeadService $leads,
    ) {}

    /**
     * Who a follow-up on this lead may be given to by $actor: themselves, the lead's owner and the people they manage —
     * as long as they work in the lead's branch.
     *
     * @return array<int, string> id => name
     */
    public function assignable(Lead $lead, User $actor): array
    {
        $inBranch = $this->teams->staffInBranch((int) $lead->location_id);
        $managed = $this->scope->managedUserIds($actor);

        if ($managed === null) {
            return $inBranch;   // someone who sees everyone may give it to anyone who works in the branch
        }

        return array_intersect_key($inBranch, array_flip([...$managed, (int) $actor->getKey(), (int) $lead->assigned_to]));
    }

    /**
     * Plan a follow-up. Input: ['plan' => [scheduled_at, type, assigned_to, notes]].
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function schedule(Lead $lead, array $input, User $actor, ?Followup $after = null): Followup
    {
        $this->assertOpen($lead, 'so nothing more can be planned for it');

        $plan = (array) ($input['plan'] ?? []);
        $when = $this->parseWhen($plan['scheduled_at'] ?? null);
        $assignee = trim((string) ($plan['assigned_to'] ?? ''));
        $assigneeId = $assignee === '' ? (int) ($lead->assigned_to ?? $actor->getKey()) : (ctype_digit($assignee) ? (int) $assignee : 0);

        $data = Validator::make(['plan' => [
            'scheduled_at' => $when?->format('Y-m-d H:i:s'), 'type' => trim((string) ($plan['type'] ?? '')), 'notes' => $this->text($plan['notes'] ?? null),
        ]], [
            'plan.scheduled_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'plan.type' => ['required', Rule::in(FollowupType::values())],
            'plan.notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'plan.scheduled_at.required' => 'Choose the date and time of the follow-up.',
            'plan.type.required' => 'Choose how you will follow up.',
        ], ['plan.type' => 'type'])->validate()['plan'];

        $this->assertFuture($when, 'plan.scheduled_at', 'A planned follow-up must be in the future. To record something already done, use “log what was done”.');

        if (! array_key_exists($assigneeId, $this->assignable($lead, $actor))) {
            throw ValidationException::withMessages(['plan.assigned_to' => 'Choose yourself, the lead’s owner or someone on a team you manage who works in the lead’s branch.']);
        }

        return DB::transaction(function () use ($lead, $data, $assigneeId, $actor, $after) {
            $followup = $this->newFollowup($lead, $actor);
            $followup->fill(['scheduled_at' => $data['scheduled_at'], 'type' => $data['type'], 'notes' => $data['notes'] ?? null]);
            $followup->forceFill(['assigned_to' => $assigneeId, 'status' => FollowupStatus::Pending->value, 'previous_id' => $after?->getKey()])->save();

            $this->leads->refreshNextFollowup($lead);

            return $followup->refresh();
        });
    }

    /**
     * Record what came of a planned follow-up. Input: ['done' => [outcome_id, notes, next_at, next_type]].
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function complete(Followup $followup, array $input, User $actor): Followup
    {
        $done = (array) ($input['done'] ?? []);

        return DB::transaction(function () use ($followup, $done, $actor) {
            $fresh = Followup::query()->lockForUpdate()->findOrFail($followup->getKey());

            if (! $fresh->isPending()) {
                throw new FieldRuleException('This follow-up is already '.strtolower($fresh->status->label()).'.');
            }

            $lead = $this->leadOf($fresh);
            [$outcome, $notes, $nextAt, $nextType] = $this->outcomeOf($done, 'done', $fresh->type, $lead);

            $fresh->forceFill([
                'status' => FollowupStatus::Done->value, 'outcome_id' => $outcome->getKey(), 'completed_at' => now(), 'completed_by' => $actor->getKey(),
                'notes' => $this->joinNotes($fresh->notes, $notes), 'updated_by' => $actor->getKey(),
            ])->save();

            $this->afterDone($lead, $fresh, $outcome, $nextAt, $nextType, $actor);

            return $fresh->refresh();
        });
    }

    /**
     * Record a follow-up that was just done without having been planned. Input: ['log' => [type, outcome_id, notes, next_at, next_type]].
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function log(Lead $lead, array $input, User $actor): Followup
    {
        $this->assertOpen($lead, 'so nothing more can be recorded on it');

        $log = (array) ($input['log'] ?? []);
        $type = FollowupType::tryFrom(trim((string) ($log['type'] ?? '')));

        if ($type === null) {
            throw ValidationException::withMessages(['log.type' => 'Choose how you followed up.']);
        }

        return DB::transaction(function () use ($lead, $log, $type, $actor) {
            [$outcome, $notes, $nextAt, $nextType] = $this->outcomeOf($log, 'log', $type, $lead);

            $followup = $this->newFollowup($lead, $actor);
            $followup->fill(['scheduled_at' => now(), 'type' => $type->value, 'notes' => $notes]);
            $followup->forceFill([
                'assigned_to' => $actor->getKey(), 'status' => FollowupStatus::Done->value, 'outcome_id' => $outcome->getKey(),
                'completed_at' => now(), 'completed_by' => $actor->getKey(),
            ])->save();

            $this->afterDone($lead, $followup, $outcome, $nextAt, $nextType, $actor);

            return $followup->refresh();
        });
    }

    /**
     * Move a planned follow-up to another time (and re-arm its reminder).
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function reschedule(Followup $followup, ?string $when, User $actor): Followup
    {
        $at = $this->parseWhen($when);

        if ($at === null) {
            throw ValidationException::withMessages(['when' => 'Choose the new date and time.']);
        }

        $this->assertFuture($at, 'when', 'The new time must be in the future.');

        return DB::transaction(function () use ($followup, $at, $actor) {
            $fresh = Followup::query()->lockForUpdate()->findOrFail($followup->getKey());

            if (! $fresh->isPending()) {
                throw new FieldRuleException('Only a planned follow-up can be moved.');
            }

            // a new time is a new promise: it is reminded about again, and escalated again if it is missed again
            $fresh->forceFill(['scheduled_at' => $at, 'reminder_sent_at' => null, 'escalated_at' => null, 'updated_by' => $actor->getKey()])->save();
            $this->leads->refreshNextFollowup($this->leadOf($fresh));

            return $fresh->refresh();
        });
    }

    /**
     * Call a planned follow-up off.
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function cancel(Followup $followup, string $reason, User $actor): Followup
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Please say why the follow-up is being called off (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($followup, $reason, $actor) {
            $fresh = Followup::query()->lockForUpdate()->findOrFail($followup->getKey());

            if (! $fresh->isPending()) {
                throw new FieldRuleException('Only a planned follow-up can be called off.');
            }

            $fresh->forceFill([
                'status' => FollowupStatus::Cancelled->value, 'notes' => $this->joinNotes($fresh->notes, "Called off: {$reason}"), 'updated_by' => $actor->getKey(),
            ])->save();

            $this->leads->refreshNextFollowup($this->leadOf($fresh));

            return $fresh->refresh();
        });
    }

    // ---- internals --------------------------------------------------------------------------------------------

    /**
     * Read and check an outcome with its optional next step.
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: FollowupOutcome, 1: string|null, 2: Carbon|null, 3: FollowupType}
     */
    private function outcomeOf(array $fields, string $prefix, FollowupType $type, Lead $lead): array
    {
        $outcomeId = trim((string) ($fields['outcome_id'] ?? ''));
        $notes = $this->text($fields['notes'] ?? null);
        $nextRaw = trim((string) ($fields['next_at'] ?? ''));
        $nextAt = $this->parseWhen($nextRaw);
        $nextType = FollowupType::tryFrom(trim((string) ($fields['next_type'] ?? ''))) ?? $type;

        $outcome = ctype_digit($outcomeId) ? FollowupOutcome::query()->where('status', 'active')->find((int) $outcomeId) : null;

        if ($outcome === null) {
            throw ValidationException::withMessages(["{$prefix}.outcome_id" => 'Choose what came of it.']);
        }

        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw ValidationException::withMessages(["{$prefix}.notes" => 'The notes may not be longer than 2000 characters.']);
        }

        if ($nextRaw !== '' && $nextAt === null) {
            throw ValidationException::withMessages(["{$prefix}.next_at" => 'Enter the next follow-up as a valid date and time.']);
        }

        if ($outcome->needs_next_followup && $nextAt === null) {
            throw ValidationException::withMessages(["{$prefix}.next_at" => "“{$outcome->name}” needs a date for the next follow-up."]);
        }

        if ($nextAt !== null) {
            $this->assertFuture($nextAt, "{$prefix}.next_at", 'The next follow-up must be in the future.');

            if (! $lead->isOpen()) {
                throw ValidationException::withMessages(["{$prefix}.next_at" => 'This lead is '.strtolower($lead->stage->label()).', so nothing more can be planned for it.']);
            }
        }

        return [$outcome, $notes, $nextAt, $nextType];
    }

    /** What a finished follow-up does to its lead, and the next step if one was given. */
    private function afterDone(Lead $lead, Followup $followup, FollowupOutcome $outcome, ?Carbon $nextAt, FollowupType $nextType, User $actor): void
    {
        if ($outcome->counts_as_contact) {
            Lead::query()->whereKey($lead->getKey())->update(['last_contacted_at' => now()]);

            // The first real contact is what makes a new lead "contacted": nobody has to remember to move it.
            if ($lead->stage === LeadStage::New) {
                $lead = $this->leads->changeStage($lead, LeadStage::Contacted, $actor, "After a follow-up: {$outcome->name}");
            }
        }

        if ($nextAt !== null) {
            $next = $this->newFollowup($lead, $actor);
            $next->fill(['scheduled_at' => $nextAt, 'type' => $nextType->value]);
            $next->forceFill(['assigned_to' => $followup->assigned_to, 'status' => FollowupStatus::Pending->value, 'previous_id' => $followup->getKey()])->save();
        }

        $this->leads->refreshNextFollowup($lead);
    }

    private function newFollowup(Lead $lead, User $actor): Followup
    {
        $followup = new Followup;
        $followup->forceFill([
            'company_id' => (int) $lead->company_id, 'location_id' => $lead->location_id,
            'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->getKey(),
            'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
        ]);

        return $followup;
    }

    private function leadOf(Followup $followup): Lead
    {
        $subject = $followup->subject()->first();

        if (! $subject instanceof Lead) {
            throw new FieldRuleException('The record this follow-up belongs to is no longer available.');
        }

        return $subject;
    }

    private function assertOpen(Lead $lead, string $consequence): void
    {
        if (! $lead->isOpen()) {
            throw new FieldRuleException('This lead is '.strtolower($lead->stage->label()).", {$consequence}.");
        }
    }

    private function assertFuture(?Carbon $when, string $field, string $message): void
    {
        if ($when === null) {
            return;
        }

        if ($when->lt(now()->subMinute())) {
            throw ValidationException::withMessages([$field => $message]);
        }

        if ($when->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages([$field => 'That is too far ahead: plan within the next two years.']);
        }
    }

    /** A date and time as the browser's date-time box (or an API) sends it, or null when it is not one. */
    public function parseWhen(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        $text = is_string($value) ? trim($value) : '';

        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            if ($text !== '' && Carbon::hasFormat($text, $format)) {
                $parsed = Carbon::createFromFormat($format, $text);

                // createFromFormat rolls 2026-02-31 over to March: refuse it rather than plan the wrong day
                if ($parsed !== null && $parsed->format($format) === $text) {
                    return $parsed->seconds(0);
                }
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function joinNotes(?string $existing, ?string $more): ?string
    {
        $existing = $existing === null || trim($existing) === '' ? null : trim($existing);

        return match (true) {
            $more === null => $existing,
            $existing === null => $more,
            default => $existing."\n".$more,
        };
    }
}
