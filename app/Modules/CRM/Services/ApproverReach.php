<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Models\Approval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which of someone else's inquiries and deals an approver may open. Approving is not working a record, so an approver is
 * outside the owner's reach (LeadAccess, DealAccess) — yet has to see what they are asked to decide, and afterwards what they
 * decided. So a holder of the form's `approve` permission may *open* (never edit) a document that is waiting for approval, and
 * anyone may open a document whose approval they have themselves decided.
 *
 * This only widens reading, and only inside the company and branches the person works in: the tenant scopes still apply.
 */
final class ApproverReach
{
    /** May $user open this document on account of its approval? */
    public function involves(User $user, ApprovableDocument&Model $document, string $approvePermission): bool
    {
        if ($document->approvalStatus() === ApprovalStatus::Pending && $user->can($approvePermission)) {
            return true;
        }

        return $this->hasDecided($user, $document);
    }

    /** Has $user decided a level of this document's approval? */
    public function hasDecided(User $user, Model $document): bool
    {
        return Approval::query()
            ->where('approvable_type', $document->getMorphClass())->where('approvable_id', $document->getKey())
            ->where('acted_by', $user->getKey())
            ->exists();
    }

    /**
     * Add "…or it is one this person may open for its approval" to a query that is already being narrowed by reach. Call it
     * inside the `where(function ($q) {…})` group that holds the other alternatives.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $group  the OR group to add to
     */
    public function widen(Builder $group, User $user, string $approvePermission): void
    {
        $table = $group->getModel()->getTable();
        $morph = $group->getModel()->getMorphClass();

        if ($user->can($approvePermission)) {
            $group->orWhere("{$table}.approval_status", ApprovalStatus::Pending->value);
        }

        $group->orWhereExists(fn (QueryBuilder $decided) => $decided->selectRaw('1')->from('approvals')
            ->whereColumn('approvals.approvable_id', "{$table}.id")
            ->where('approvals.approvable_type', $morph)
            ->where('approvals.acted_by', $user->getKey()));
    }

    /**
     * Which of these documents may $user open on account of approval — for a page of rows, in one query.
     *
     * @param  iterable<Model>  $documents
     * @return array<int, true> id => true
     */
    public function openable(User $user, iterable $documents, string $approvePermission): array
    {
        $documents = is_array($documents) ? array_values($documents) : iterator_to_array($documents, false);

        if ($documents === []) {
            return [];
        }

        $rows = [];
        $pending = [];
        $canApprove = $user->can($approvePermission);

        foreach ($documents as $document) {
            $rows[] = (int) $document->getKey();

            if ($canApprove && $document instanceof ApprovableDocument && $document->approvalStatus() === ApprovalStatus::Pending) {
                $pending[(int) $document->getKey()] = true;
            }
        }

        $decided = Approval::query()->where('approvable_type', $documents[0]->getMorphClass())->whereIn('approvable_id', $rows)
            ->where('acted_by', $user->getKey())->distinct()->pluck('approvable_id');

        foreach ($decided as $id) {
            $pending[(int) $id] = true;
        }

        return $pending;
    }
}
