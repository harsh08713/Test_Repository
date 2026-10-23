<?php

namespace App\Modules\Shared\Services;

use App\Models\User;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\ApprovalLevelStatus;
use App\Modules\Shared\Enums\ApprovalMode;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Events\ApprovalRequested;
use App\Modules\Shared\Events\DocumentApproved;
use App\Modules\Shared\Events\DocumentRejected;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * The shared approval engine.
 *
 *  - Levels come from `approval_levels` (per company + document type). With none configured the document has
 *    one implicit level: the role the document type routes to by default (`defaultApproverRole()`, e.g. items →
 *    Accounts), or — when it names none — anyone holding `{form}.approve`.
 *  - Levels are decided in order (default) or all at once, per company + document type (ApprovalMode).
 *    A rejection at any level rejects the document and cancels the levels still waiting.
 *  - Every submission creates a new `cycle` of rows, so history survives resubmission.
 *  - The document is fingerprinted at submission; if it changed afterwards it cannot be approved — it has to
 *    be rejected (returning it to its owner) and resubmitted.
 *  - Nobody has an implicit bypass. Deciding a level you were not designated for needs the explicit
 *    `approval.override` permission, and is recorded on the row and in the audit log as an override.
 *  - Everything runs in a transaction with the document row locked, so two approvers cannot decide the
 *    same level twice. Events fire only after the transaction commits.
 */
final class ApprovalService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Send a draft (or previously rejected) document for approval.
     *
     * @return Collection<int, Approval> the approval rows created for this submission
     */
    public function submit(ApprovableDocument&Model $document, ?User $by = null): Collection
    {
        $by ??= $this->actingUser();

        return DB::transaction(function () use ($document, $by) {
            $this->lockAndRefresh($document);

            $status = $document->approvalStatus();
            if ($status !== ApprovalStatus::Draft && $status !== ApprovalStatus::Rejected) {
                throw ApprovalStateException::notSubmittable($status->value);
            }

            $document->beforeSubmitForApproval();

            $cycle = $this->latestCycle($document) + 1;
            $parallel = $this->modeFor($document) === ApprovalMode::Parallel;
            $fingerprint = $document->approvalFingerprint();
            $levels = $this->levelsFor($document);
            $firstLevel = $levels[0]['level'];

            $rows = collect($levels)->map(function (array $level) use ($document, $by, $cycle, $parallel, $fingerprint, $firstLevel) {
                $actionableNow = $parallel || $level['level'] === $firstLevel;

                $approval = new Approval;
                $approval->forceFill([
                    'company_id' => $document->approvalCompanyId(),
                    'approvable_type' => $document->getMorphClass(),
                    'approvable_id' => $document->getKey(),
                    'document_type' => $document->approvalDocumentType()->value,
                    'cycle' => $cycle,
                    'level' => $level['level'],
                    'level_name' => $level['name'],
                    'approver_role' => $level['approver_role'],
                    'approver_user_id' => $level['approver_user_id'],
                    'status' => ApprovalLevelStatus::Pending->value,
                    'is_parallel' => $parallel,
                    'document_hash' => $fingerprint,
                    'due_hours' => $level['due_hours'],
                    'due_at' => $actionableNow && $level['due_hours'] !== null ? now()->addHours($level['due_hours']) : null,
                    'submitted_by' => $by->getKey(),
                ])->save();

                return $approval;
            })->values();

            $document->forceFill([
                'approval_status' => ApprovalStatus::Pending,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->log($document, $by, 'submitted', $status, ApprovalStatus::Pending, [
                'cycle' => $cycle,
                'mode' => $parallel ? ApprovalMode::Parallel->value : ApprovalMode::Sequential->value,
            ]);

            foreach ($rows as $row) {
                if ($parallel || $row->level === $firstLevel) {
                    ApprovalRequested::dispatch($document, $row);
                }
            }

            return $rows;
        });
    }

    /** Approve a level the user may decide. When the last level approves, the document becomes Approved. */
    public function approve(ApprovableDocument&Model $document, User $by, ?string $comment = null): Approval
    {
        return DB::transaction(function () use ($document, $by, $comment) {
            $this->lockAndRefresh($document);

            $actionable = $this->actionableLevels($document);
            if ($actionable->isEmpty()) {
                throw ApprovalStateException::nothingPending();
            }

            $this->assertUnchangedSinceSubmission($document, $actionable->first());

            [$approval, $override] = $this->chooseLevel($by, $document, $actionable);
            $this->decide($approval, ApprovalLevelStatus::Approved, $by, $comment, $override);

            if ($this->actionableLevels($document)->isEmpty()) {
                $document->forceFill([
                    'approval_status' => ApprovalStatus::Approved,
                    'approved_by' => $by->getKey(),
                    'approved_at' => now(),
                ])->save();

                $this->log($document, $by, 'approved', ApprovalStatus::Pending, ApprovalStatus::Approved, [
                    'level' => $approval->level,
                    'comment' => $comment,
                    'override' => $override,
                ]);

                DocumentApproved::dispatch($document, $by, $comment);

                return $approval;
            }

            $this->log($document, $by, 'level_approved', ApprovalStatus::Pending, ApprovalStatus::Pending, [
                'level' => $approval->level,
                'comment' => $comment,
                'override' => $override,
            ]);

            // Sequential: the next level has just become actionable — start its clock and tell its approvers.
            if (! $approval->is_parallel) {
                $next = $this->actionableLevels($document)->first();

                if ($next->due_hours !== null && $next->due_at === null) {
                    $next->forceFill(['due_at' => now()->addHours($next->due_hours)])->save();
                }

                ApprovalRequested::dispatch($document, $next);
            }

            return $approval;
        });
    }

    /** Reject at a level the user may decide: the document returns to its owner and the other levels are cancelled. */
    public function reject(ApprovableDocument&Model $document, User $by, string $comment): Approval
    {
        if (trim($comment) === '') {
            throw ApprovalStateException::commentRequired();
        }

        return DB::transaction(function () use ($document, $by, $comment) {
            $this->lockAndRefresh($document);

            $actionable = $this->actionableLevels($document);
            if ($actionable->isEmpty()) {
                throw ApprovalStateException::nothingPending();
            }

            [$approval, $override] = $this->chooseLevel($by, $document, $actionable);
            $this->decide($approval, ApprovalLevelStatus::Rejected, $by, $comment, $override);

            Approval::withoutGlobalScopes()
                ->where('approvable_type', $approval->approvable_type)
                ->where('approvable_id', $approval->approvable_id)
                ->where('cycle', $approval->cycle)
                ->where('status', ApprovalLevelStatus::Pending->value)
                ->update(['status' => ApprovalLevelStatus::Cancelled->value, 'updated_at' => now()]);

            $document->forceFill([
                'approval_status' => ApprovalStatus::Rejected,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->log($document, $by, 'rejected', ApprovalStatus::Pending, ApprovalStatus::Rejected, [
                'level' => $approval->level,
                'comment' => $comment,
                'override' => $override,
            ]);

            DocumentRejected::dispatch($document, $by, $comment);

            return $approval;
        });
    }

    /**
     * Take an approved document back to draft so it can be corrected and approved again.
     *
     * Approved documents are locked, and this is the only way to change one. It is deliberate and visible: a reason
     * is mandatory, the old approval rows stay in the history, the step is audited, and until the document is
     * approved again it drops out of every transaction picker. Whether the user may do it is the caller's policy
     * check (`reopen`); this method guards only the state.
     */
    public function reopen(ApprovableDocument&Model $document, User $by, string $reason): void
    {
        if (trim($reason) === '') {
            throw ApprovalStateException::reasonRequired();
        }

        DB::transaction(function () use ($document, $by, $reason) {
            $this->lockAndRefresh($document);

            $status = $document->approvalStatus();
            if ($status !== ApprovalStatus::Approved) {
                throw ApprovalStateException::notReopenable($status->value);
            }

            $document->forceFill([
                'approval_status' => ApprovalStatus::Draft,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->log($document, $by, 'reopened', ApprovalStatus::Approved, ApprovalStatus::Draft, ['comment' => trim($reason)]);
        });
    }

    /**
     * The document's full approval history, oldest submission first, level by level.
     *
     * @return Collection<int, Approval>
     */
    public function history(ApprovableDocument&Model $document): Collection
    {
        return Approval::query()
            ->whereMorphedTo('approvable', $document)
            ->with(['actor', 'submitter'])
            ->orderBy('cycle')
            ->orderBy('level')
            ->get();
    }

    /**
     * Can this user decide the document right now? Pass a specific level to ask about just that one.
     * (non-throwing)
     */
    public function canAct(User $user, ApprovableDocument&Model $document, ?Approval $approval = null): bool
    {
        $levels = $approval !== null ? collect([$approval]) : $this->actionableLevels($document);

        return $levels->contains(fn (Approval $level) => $this->evaluate($user, $document, $level)['reason'] === null);
    }

    /** True when the document was edited after it was submitted, so it can no longer be approved as it stands. */
    public function changedSinceSubmission(ApprovableDocument&Model $document): bool
    {
        $level = $this->actionableLevels($document)->first();

        return $level !== null
            && $level->document_hash !== null
            && ! hash_equals($level->document_hash, $document->approvalFingerprint());
    }

    /**
     * Approval inbox: for each document, the level this user may decide right now, if any.
     *
     * @return Collection<int, Approval>
     */
    public function pendingFor(User $user): Collection
    {
        $pending = Approval::query()
            ->where('status', ApprovalLevelStatus::Pending->value)
            ->with(['approvable', 'submitter'])
            ->orderBy('level')
            ->get();

        return $pending
            ->groupBy(fn (Approval $a) => $a->approvable_type.':'.$a->approvable_id)
            ->map(function (Collection $levels) use ($user) {
                $document = $levels->first()->approvable;

                if (! $document instanceof ApprovableDocument) {
                    return null;
                }

                $actionable = $levels->first()->is_parallel ? $levels : $levels->take(1);

                return $actionable->first(fn (Approval $a) => $this->evaluate($user, $document, $a)['reason'] === null);
            })
            ->filter()
            ->sortBy('created_at')
            ->values();
    }

    /**
     * Users designated to decide this level (used to notify them). Override-only users are not notified.
     *
     * @return Collection<int, User>
     */
    public function eligibleApprovers(Approval $approval): Collection
    {
        try {
            $query = match (true) {
                $approval->approver_user_id !== null => User::query()->whereKey($approval->approver_user_id),
                $approval->approver_role !== null => User::role($approval->approver_role),
                default => User::permission($approval->document_type->permissionKey().'.approve'),
            };
        } catch (RoleDoesNotExist|PermissionDoesNotExist) {
            return new Collection;
        }

        return $query->where('is_active', true)->get()
            ->filter(fn (User $user) => $this->tenant->userCanAccessCompany($user, $approval->company_id))
            ->reject(fn (User $user) => $this->isSelfApproval($user, $approval, $approval->approvable))
            ->values();
    }

    /** @return list<array{level: int, name: string|null, approver_role: string|null, approver_user_id: int|null, due_hours: int|null}> */
    private function levelsFor(ApprovableDocument&Model $document): array
    {
        $configured = ApprovalLevel::withoutGlobalScopes()
            ->where('company_id', $document->approvalCompanyId())
            ->where('document_type', $document->approvalDocumentType()->value)
            ->orderBy('level')
            ->get();

        if ($configured->isEmpty()) {
            return [['level' => 1, 'name' => null, 'approver_role' => $document->defaultApproverRole(), 'approver_user_id' => null, 'due_hours' => null]];
        }

        return $configured->map(fn (ApprovalLevel $l) => [
            'level' => $l->level,
            'name' => $l->name,
            'approver_role' => $l->approver_role,
            'approver_user_id' => $l->approver_user_id,
            'due_hours' => $l->due_hours,
        ])->values()->all();
    }

    private function modeFor(ApprovableDocument&Model $document): ApprovalMode
    {
        $value = $this->settings->get(ApprovalMode::settingKey($document->approvalDocumentType()), $document->approvalCompanyId());

        return ApprovalMode::tryFrom((string) $value) ?? ApprovalMode::Sequential;
    }

    private function latestCycle(ApprovableDocument&Model $document): int
    {
        return (int) Approval::withoutGlobalScopes()
            ->where('approvable_type', $document->getMorphClass())
            ->where('approvable_id', $document->getKey())
            ->max('cycle');
    }

    /**
     * The pending levels that may be decided right now: the lowest one when sequential, all of them when parallel.
     *
     * @return Collection<int, Approval>
     */
    private function actionableLevels(ApprovableDocument&Model $document): Collection
    {
        $pending = Approval::withoutGlobalScopes()
            ->where('approvable_type', $document->getMorphClass())
            ->where('approvable_id', $document->getKey())
            ->where('cycle', $this->latestCycle($document))
            ->where('status', ApprovalLevelStatus::Pending->value)
            ->orderBy('level')
            ->get();

        return $pending->isNotEmpty() && $pending->first()->is_parallel ? $pending : $pending->take(1);
    }

    private function assertUnchangedSinceSubmission(ApprovableDocument&Model $document, Approval $level): void
    {
        if ($level->document_hash !== null && ! hash_equals($level->document_hash, $document->approvalFingerprint())) {
            throw ApprovalStateException::changedSinceSubmission();
        }
    }

    /**
     * Pick the level this user is deciding. A level they are designated for wins over one they could only
     * decide by override.
     *
     * @param  Collection<int, Approval>  $actionable
     * @return array{0: Approval, 1: bool} the level and whether it is decided by override
     */
    private function chooseLevel(User $user, ApprovableDocument&Model $document, Collection $actionable): array
    {
        $firstReason = null;
        $overrideChoice = null;

        foreach ($actionable as $level) {
            $result = $this->evaluate($user, $document, $level);

            if ($result['reason'] !== null) {
                $firstReason ??= $result['reason'];

                continue;
            }

            if (! $result['override']) {
                return [$level, false];
            }

            $overrideChoice ??= $level;
        }

        if ($overrideChoice !== null) {
            return [$overrideChoice, true];
        }

        throw new AuthorizationException($firstReason ?? 'You cannot decide this approval.');
    }

    private function decide(Approval $approval, ApprovalLevelStatus $status, User $by, ?string $comment, bool $override): void
    {
        $approval->forceFill([
            'status' => $status->value,
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'acted_by' => $by->getKey(),
            'acted_at' => now(),
            'overridden' => $override,
        ])->save();
    }

    /** @return array{reason: string|null, override: bool} reason is null when the user may decide this level */
    private function evaluate(User $user, ApprovableDocument&Model $document, Approval $approval): array
    {
        if (! $user->is_active) {
            return ['reason' => 'Your account is deactivated.', 'override' => false];
        }

        if (! $this->tenant->userCanAccessCompany($user, $document->approvalCompanyId())) {
            return ['reason' => 'You do not have access to this document\'s company.', 'override' => false];
        }

        $designated = match (true) {
            $approval->approver_user_id !== null => (int) $approval->approver_user_id === (int) $user->getKey(),
            $approval->approver_role !== null => $user->hasRole($approval->approver_role),
            default => $user->can($approval->document_type->permissionKey().'.approve'),
        };

        $override = ! $designated && $user->can('approval.override');

        if (! $designated && ! $override) {
            return ['reason' => 'You are not an approver for this level.', 'override' => false];
        }

        if ($this->isSelfApproval($user, $approval, $document)) {
            return ['reason' => 'You cannot approve a document you created or submitted.', 'override' => false];
        }

        return ['reason' => null, 'override' => $override];
    }

    private function isSelfApproval(User $user, Approval $approval, ?Model $document): bool
    {
        if (! $this->settings->get('approvals.prevent_self_approval', (int) $approval->company_id)) {
            return false;
        }

        return (int) $approval->submitted_by === (int) $user->getKey()
            || ($document !== null && (int) $document->getAttribute('created_by') === (int) $user->getKey());
    }

    /** Re-read the row under a lock so concurrent decisions serialise; the instance is refreshed in place. */
    private function lockAndRefresh(Model $document): void
    {
        $fresh = $document->newQueryWithoutScopes()->lockForUpdate()->findOrFail($document->getKey());

        if (method_exists($fresh, 'trashed') && $fresh->trashed()) {
            throw new ApprovalStateException('A deleted document cannot be sent for or decided in approval.');
        }

        $document->setRawAttributes($fresh->getAttributes(), true);
    }

    private function actingUser(): User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : throw new AuthorizationException('Sign in to submit a document for approval.');
    }

    /** @param array<string, mixed> $extra */
    private function log(Model $document, User $by, string $event, ApprovalStatus $from, ApprovalStatus $to, array $extra = []): void
    {
        activity('approval')
            ->causedBy($by)
            ->performedOn($document)
            ->event($event)
            ->withProperties([
                'old' => ['approval_status' => $from->value],
                'attributes' => ['approval_status' => $to->value],
                ...$extra,
            ])
            ->log($event);
    }
}
