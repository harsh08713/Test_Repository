<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Enums\LeadType;
use App\Modules\CRM\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The filters a lead list offers — text search, owner, branch, source, when the next follow-up is due, lead date range —
 * applied to a query. The leads screen and the mobile API both use it, so "overdue" or a search for a phone number mean
 * the same thing wherever they are asked.
 *
 * It only ever **narrows** a query: whose leads a person may see is LeadAccess::restrict(), applied first by the caller, and
 * no filter value can widen it. Anything that is not a recognisable value is ignored.
 */
final class LeadFilters
{
    /**
     * @param  Builder<Lead>  $query
     * @param  array{search?: string, owner?: string, branch?: string, source?: string, due?: string, from?: string, to?: string, type?: string}  $filters
     *                                                                                                                                                      `owner` is a user id or 'none' (the unassigned pool); `due` is 'overdue', 'today', 'week' or 'none'; `type` is 'new' or 'existing'
     * @return Builder<Lead>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $valid = fn (string $day) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && Carbon::hasFormat($day, 'Y-m-d');
        $term = trim($filters['search'] ?? '');
        $type = LeadType::tryFrom($filters['type'] ?? '');
        $owner = $filters['owner'] ?? '';
        $branch = $filters['branch'] ?? '';
        $source = $filters['source'] ?? '';
        $from = $filters['from'] ?? '';
        $to = $filters['to'] ?? '';
        $due = $filters['due'] ?? '';
        $now = now();

        return $query
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $digits = preg_replace('/\D+/', '', $term) ?? '';

                $q->where(function (Builder $w) use ($like, $digits) {
                    $w->where('leads.name', 'like', $like)->orWhere('leads.lead_number', 'like', $like)
                        ->orWhere('leads.contact_person', 'like', $like)->orWhere('leads.city', 'like', $like)->orWhere('leads.email', 'like', $like);

                    if (strlen($digits) >= 4) {
                        $w->orWhere('leads.mobile', 'like', '%'.substr($digits, -10).'%');
                    }
                });
            })
            ->when($type !== null, fn (Builder $q) => $q->where('leads.lead_type', $type?->value))
            ->when(ctype_digit($owner), fn (Builder $q) => $q->where('leads.assigned_to', (int) $owner))
            ->when($owner === 'none', fn (Builder $q) => $q->whereNull('leads.assigned_to'))
            ->when(ctype_digit($branch), fn (Builder $q) => $q->where('leads.location_id', (int) $branch))
            ->when(ctype_digit($source), fn (Builder $q) => $q->where('leads.lead_source_id', (int) $source))
            ->when($valid($from), fn (Builder $q) => $q->where('leads.lead_date', '>=', $from))
            ->when($valid($to), fn (Builder $q) => $q->where('leads.lead_date', '<=', $to))
            ->when($due === 'overdue', fn (Builder $q) => $q->open()->where('leads.next_followup_at', '<', $now))
            ->when($due === 'today', fn (Builder $q) => $q->open()->whereBetween('leads.next_followup_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]))
            ->when($due === 'week', fn (Builder $q) => $q->open()->whereBetween('leads.next_followup_at', [$now->copy()->startOfDay(), $now->copy()->addDays(7)->endOfDay()]))
            ->when($due === 'none', fn (Builder $q) => $q->open()->whereNull('leads.next_followup_at'));
    }
}
