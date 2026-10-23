<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Notifications\QuotationExpiryNotification;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Support\Format;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * What the scheduler does about quotation validity, every day for every company:
 *
 *  - **Expire**: a quotation that was sent and not answered by the end of its valid-until date becomes Expired (the deal falls back to
 *    Negotiation if nothing else of it stands) and its owner is told, once.
 *  - **Warn**: a sent quotation within `quotation.expiry_warning_days` of its last day (2 by default; 0 = off) tells its owner, once.
 *
 * Each warning is *claimed* with one conditional UPDATE (`WHERE expiry_warned_at IS NULL`) before anything is sent, so overlapping or
 * retried runs never tell anybody twice; an expiry is a locked state change that only one run can make.
 */
final class QuotationExpiry
{
    private const BATCH = 1000;

    private const MAX_WARNING_DAYS = 30;

    public function __construct(
        private readonly QuotationService $quotations,
        private readonly SettingsService $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{expired: int, warned: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();

        return ['expired' => $this->expire($now), 'warned' => $this->warn($now)];
    }

    private function expire(Carbon $now): int
    {
        $done = 0;

        $due = Quotation::withoutGlobalScopes(Tenancy::SCOPES)
            ->where('state', QuotationState::Sent->value)->where('valid_until', '<', $now->toDateString())
            ->orderBy('valid_until')->limit(self::BATCH)->get(['id', 'company_id']);

        foreach ($due as $row) {
            $expired = $this->tenant->withCompany((int) $row->getAttribute('company_id'), fn () => $this->quotations->expire($row));

            if ($expired === null) {
                continue;   // answered or revised since this run looked
            }

            $this->tell($expired, expired: true);
            $done++;
        }

        return $done;
    }

    private function warn(Carbon $now): int
    {
        $done = 0;
        $limits = [];

        $candidates = Quotation::withoutGlobalScopes(Tenancy::SCOPES)
            ->where('state', QuotationState::Sent->value)->whereNull('expiry_warned_at')
            ->where('valid_until', '>=', $now->toDateString())->where('valid_until', '<=', $now->copy()->addDays(self::MAX_WARNING_DAYS)->toDateString())
            ->orderBy('valid_until')->limit(self::BATCH)->get(['id', 'company_id', 'valid_until']);

        foreach ($candidates as $row) {
            $companyId = (int) $row->getAttribute('company_id');
            $limits[$companyId] ??= max(0, min(self::MAX_WARNING_DAYS, (int) $this->settings->get('quotation.expiry_warning_days', $companyId)));

            if ($limits[$companyId] === 0 || $row->valid_until->gt($now->copy()->addDays($limits[$companyId])->startOfDay())) {
                continue;   // warnings are off for this company, or it is not close enough yet
            }

            // Claim it first: whoever wins this UPDATE sends the warning, and nobody else does.
            $claimed = Quotation::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($row->getKey())
                ->where('state', QuotationState::Sent->value)->whereNull('expiry_warned_at')
                ->update(['expiry_warned_at' => $now]);

            if ($claimed !== 1) {
                continue;
            }

            $quotation = Quotation::withoutGlobalScopes(Tenancy::SCOPES)->find($row->getKey());

            if ($quotation !== null && $this->tell($quotation, expired: false)) {
                $done++;
            }
        }

        return $done;
    }

    /** Tell the person who owns the quotation (the owner of its lead). */
    private function tell(Quotation $quotation, bool $expired): bool
    {
        $ownerId = Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($quotation->lead_id)->value('assigned_to');
        $owner = $ownerId === null ? null : User::query()->where('is_active', true)->find($ownerId);

        if ($owner === null) {
            return false;
        }

        $owner->notify(new QuotationExpiryNotification(
            expired: $expired, title: $quotation->displayNumber().' — '.$quotation->party_name, validUntil: Format::date($quotation->valid_until),
            total: Format::inr($quotation->grand_total), url: route('sales.quotations.show', $quotation->getKey(), false),
        ));

        return true;
    }
}
