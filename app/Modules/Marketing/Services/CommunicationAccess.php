<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Marketing\Models\Communication;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose messages a person may read. A message is about a person (a lead or a customer), and what it says is that person's business with us — so it
 * is shown only to someone who may see that lead (`LeadAccess`: their own, their team's, everyone's for `sales.scope_all`) or that customer
 * (`ledger.view`). The same rule limits the message log, a customer's page and a lead's page.
 */
final class CommunicationAccess
{
    public function __construct(private readonly LeadAccess $leads) {}

    /**
     * @param  Builder<Communication>  $query
     * @return Builder<Communication>
     */
    public function restrict(Builder $query, User $user): Builder
    {
        $table = $query->getModel()->getTable();
        $leads = $this->leads->restrict(Lead::query(), $user)->select('leads.id');
        $customers = $user->can('ledger.view');

        return $query->where(function (Builder $q) use ($table, $leads, $customers) {
            $q->where(fn (Builder $w) => $w->where("{$table}.subject_type", 'lead')->whereIn("{$table}.subject_id", $leads));
            $customers && $q->orWhere("{$table}.subject_type", 'ledger');
        });
    }
}
