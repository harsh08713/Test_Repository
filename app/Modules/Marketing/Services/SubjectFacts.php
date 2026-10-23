<?php

namespace App\Modules\Marketing\Services;

use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Turns "this lead / deal / quotation / order" into the same handful of facts, so a workflow's conditions and actions do not care which it is, and finds
 * the occurrences of each trigger. Reads only — and only inside one company.
 *
 * Facts: `type`, `id`, `company_id`, `owner_id`, `source_id`, `stage` (always the *lead's* stage, when there is a lead), `branch`, `value`, `number`, `name`,
 * `email`, `mobile`, `lead_id`, `task_type` (the morph alias a task about it takes) and `url`.
 */
final class SubjectFacts
{
    /**
     * @return array<string, mixed>|null null when the record is gone or is another company's
     */
    public function load(string $type, int $id, int $companyId): ?array
    {
        $scope = Tenancy::SCOPES;

        return match ($type) {
            'lead' => $this->lead(Lead::withoutGlobalScopes($scope)->where('company_id', $companyId)->find($id)),
            'deal' => $this->deal(Deal::withoutGlobalScopes($scope)->where('company_id', $companyId)->find($id)),
            'quotation' => $this->quotation(Quotation::withoutGlobalScopes($scope)->where('company_id', $companyId)->find($id)),
            'order' => $this->order(SalesOrder::withoutGlobalScopes($scope)->where('company_id', $companyId)->find($id)),
            default => null,
        };
    }

    /**
     * The occurrences of a trigger for a company, newest last: `[trigger_key, subject_type, subject_id]`. `$since` is when the workflow was switched on
     * (older events never run), `$limit` the most to look at in one sweep. `$ignoreSince` is for a dry run, which may look at recent history.
     *
     * @param  array<string, int>  $params
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public function occurrences(string $trigger, int $companyId, \DateTimeInterface $since, array $params, int $limit, bool $ignoreSince = false): array
    {
        $scope = Tenancy::SCOPES;
        $after = $ignoreSince ? now()->subDays(60) : $since;

        return match ($trigger) {
            'lead_created' => Lead::withoutGlobalScopes($scope)->where('company_id', $companyId)->where('created_at', '>=', $after)->orderBy('id')->limit($limit)->pluck('id')
                ->map(fn ($id) => ["lead:{$id}:created", 'lead', (int) $id])->all(),
            'stage_changed' => DB::table('lead_stage_history')->where('company_id', $companyId)->where('created_at', '>=', $after)->whereNotNull('from_stage')->orderBy('id')->limit($limit)->get(['id', 'lead_id'])
                ->map(fn ($r) => ["stage:{$r->id}", 'lead', (int) $r->lead_id])->all(),
            'followup_overdue' => Followup::withoutGlobalScopes($scope)->where('company_id', $companyId)->where('subject_type', 'lead')->where('status', 'pending')
                ->where('scheduled_at', '<=', now()->subHours($params['after_hours'] ?? 24))->where('scheduled_at', '>=', $ignoreSince ? now()->subDays(60) : max($after, now()->subDays(14)))
                ->orderBy('id')->limit($limit)->get(['id', 'subject_id'])->map(fn ($f) => ["followup:{$f->id}:overdue", 'lead', (int) $f->subject_id])->all(),
            'deal_won', 'deal_lost' => DB::table('deal_stage_history')->where('company_id', $companyId)->where('to_stage', $trigger === 'deal_won' ? 'won' : 'lost')
                ->where('created_at', '>=', $after)->orderBy('id')->limit($limit)->get(['id', 'deal_id'])->map(fn ($r) => ["dealstage:{$r->id}", 'deal', (int) $r->deal_id])->all(),
            'quotation_expiring' => Quotation::withoutGlobalScopes($scope)->where('company_id', $companyId)->where('state', 'sent')
                ->whereBetween('valid_until', [now()->toDateString(), now()->addDays($params['days_before'] ?? 3)->toDateString()])
                ->orderBy('id')->limit($limit)->get(['id', 'valid_until'])->map(fn ($q) => ["qt:{$q->id}:".$q->valid_until->toDateString(), 'quotation', (int) $q->id])->all(),
            'order_approved' => SalesOrder::withoutGlobalScopes($scope)->where('company_id', $companyId)->where('approval_status', 'approved')->where('approved_at', '>=', $after)
                ->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id) => ["so:{$id}:approved", 'order', (int) $id])->all(),
            default => [],
        };
    }

    /** The record itself (whole, never a column list), for a permission check; null when it is gone or another company's. */
    public function model(string $type, int $id, int $companyId): ?Model
    {
        $class = ['lead' => Lead::class, 'deal' => Deal::class, 'quotation' => Quotation::class, 'order' => SalesOrder::class][$type] ?? null;

        return $class === null ? null : $class::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->find($id);
    }

    /** @return array<string, mixed>|null */
    private function lead(?Lead $lead): ?array
    {
        return $lead === null ? null : [
            'type' => 'lead', 'id' => (int) $lead->getKey(), 'company_id' => (int) $lead->company_id, 'owner_id' => $lead->assigned_to === null ? null : (int) $lead->assigned_to,
            'source_id' => (int) $lead->lead_source_id, 'stage' => $lead->stage->value, 'branch' => (int) $lead->location_id, 'value' => (string) ($lead->expected_value ?? '0'),
            'number' => $lead->lead_number, 'name' => $lead->name, 'email' => $lead->email, 'mobile' => $lead->mobile, 'lead_id' => (int) $lead->getKey(), 'task_type' => 'lead',
            'url' => route('crm.leads.show', $lead->getKey(), false),
        ];
    }

    /** @return array<string, mixed>|null */
    private function deal(?Deal $deal): ?array
    {
        if ($deal === null) {
            return null;
        }

        $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->find($deal->lead_id);

        return [
            'type' => 'deal', 'id' => (int) $deal->getKey(), 'company_id' => (int) $deal->company_id, 'owner_id' => (int) $deal->assigned_to,
            'source_id' => $lead?->lead_source_id, 'stage' => $lead?->stage->value, 'branch' => (int) $deal->location_id, 'value' => (string) ($deal->expected_value ?? '0'),
            'number' => $deal->deal_number, 'name' => $deal->party_name, 'email' => $deal->email, 'mobile' => $deal->mobile, 'lead_id' => $deal->lead_id, 'task_type' => 'deal',
            'url' => route('crm.deals.show', $deal->getKey(), false),
        ];
    }

    /** @return array<string, mixed>|null */
    private function quotation(?Quotation $q): ?array
    {
        if ($q === null) {
            return null;
        }

        $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->find($q->lead_id);

        return [
            'type' => 'quotation', 'id' => (int) $q->getKey(), 'company_id' => (int) $q->company_id, 'owner_id' => $lead?->assigned_to === null ? null : (int) $lead->assigned_to,
            'source_id' => $lead?->lead_source_id, 'stage' => $lead?->stage->value, 'branch' => (int) $q->location_id, 'value' => (string) $q->grand_total,
            'number' => $q->quotation_number, 'name' => $q->party_name, 'email' => $q->email, 'mobile' => $q->mobile, 'lead_id' => $q->lead_id, 'task_type' => 'quotation',
            'url' => route('sales.quotations.show', $q->getKey(), false),
        ];
    }

    /** @return array<string, mixed>|null */
    private function order(?SalesOrder $o): ?array
    {
        if ($o === null) {
            return null;
        }

        $lead = $o->lead_id === null ? null : Lead::withoutGlobalScopes(Tenancy::SCOPES)->find($o->lead_id);

        return [
            'type' => 'order', 'id' => (int) $o->getKey(), 'company_id' => (int) $o->company_id, 'owner_id' => (int) $o->assigned_to,
            'source_id' => $lead?->lead_source_id, 'stage' => $lead?->stage->value, 'branch' => (int) $o->location_id, 'value' => (string) $o->taxable_total,
            'number' => $o->so_number, 'name' => $o->party_name, 'email' => $o->email, 'mobile' => $o->mobile, 'lead_id' => $o->lead_id, 'task_type' => 'sales_order',
            'url' => route('sales.orders.show', $o->getKey(), false),
        ];
    }
}
