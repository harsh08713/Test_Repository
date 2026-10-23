<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\ApproverReach;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose quotations a person may see and work — the one rule the policy, the lists, the API and the PDF download share.
 *
 * A quotation belongs to whoever owns the lead it comes from (so it follows a hand-over of the lead or of its deal and never
 * has an owner of its own to keep in step):
 *  - **Work** (change it, send it, record the customer's answer, revise it): that owner, the people who manage them, and holders
 *    of `sales.scope_all` (SalesScope).
 *  - **See** (open it): everyone who may work it, and an approver for a quotation waiting for their decision or one they decided.
 *
 * The company and branch boundaries are not decided here: every query already runs through the tenant scopes.
 */
final class QuotationAccess
{
    public function __construct(private readonly SalesScope $scope, private readonly ApproverReach $approvers) {}

    public function sees(User $user, Quotation $quotation): bool
    {
        return $this->works($user, $quotation) || $this->approvers->involves($user, $quotation, 'quotation.approve');
    }

    public function works(User $user, Quotation $quotation): bool
    {
        return $this->scope->sees($user, $this->ownerOf($quotation));
    }

    /** May $user act on this quotation's owner's behalf — the owner or the people who manage them? */
    public function manages(User $user, Quotation $quotation): bool
    {
        $owner = $this->ownerOf($quotation);

        return $this->scope->manages($user, $owner) || $owner === (int) $user->getKey();
    }

    /** Who owns the quotation: the owner of its lead (0 when the lead has none, which nobody's reach includes). */
    public function ownerOf(Quotation $quotation): int
    {
        return (int) Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($quotation->lead_id)->value('assigned_to');
    }

    /**
     * Which of these quotations $user may open — the answer for a page of rows in a few queries. Same rule as sees().
     *
     * @param  iterable<Quotation>  $quotations
     * @return array<int, true> quotation id => true
     */
    public function openable(User $user, iterable $quotations): array
    {
        $quotations = is_array($quotations) ? array_values($quotations) : iterator_to_array($quotations, false);

        if ($quotations === []) {
            return [];
        }

        $visible = $this->scope->visibleUserIds($user);
        $owners = Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereIn('id', array_unique(array_map(fn (Quotation $q) => (int) $q->lead_id, $quotations)))
            ->pluck('assigned_to', 'id');

        $open = [];
        $rest = [];

        foreach ($quotations as $quotation) {
            if ($visible === null || in_array((int) $owners->get($quotation->lead_id), $visible, true)) {
                $open[(int) $quotation->getKey()] = true;
            } else {
                $rest[] = $quotation;
            }
        }

        foreach (array_keys($this->approvers->openable($user, $rest, 'quotation.approve')) as $id) {
            $open[$id] = true;
        }

        return $open;
    }

    /**
     * Limit a quotation query to what $user may see.
     *
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    public function restrict(Builder $query, User $user): Builder
    {
        $visible = $this->scope->visibleUserIds($user);

        if ($visible === null) {
            return $query;   // everyone's
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($visible, $user, $table) {
            $q->whereIn("{$table}.lead_id", Lead::withoutGlobalScopes(Tenancy::SCOPES)->select('id')->whereIn('assigned_to', $visible));

            // …and the quotations it falls to this person to approve
            $this->approvers->widen($q, $user, 'quotation.approve');
        });
    }
}
