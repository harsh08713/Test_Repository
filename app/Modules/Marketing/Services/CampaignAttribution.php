<?php

namespace App\Modules\Marketing\Services;

use App\Modules\CRM\Models\Lead;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Which campaign a lead came from. Three ways, kept apart in `leads.attribution`:
 *
 *  - **manual**: the person entering the lead chose the campaign;
 *  - **link**: the lead form was opened from a campaign link (`?campaign=CP/…&utm_source=…`), which fills the campaign and the UTM fields;
 *  - **inferred**: a lead whose mobile number or e-mail address matches somebody a campaign wrote to in the last 30 days is credited to that campaign
 *    (the most recent one), and that campaign member is marked as having responded.
 *
 * Only what was captured is stored: UTM keys are limited to the five standard ones and each value to 100 characters of plain text.
 */
final class CampaignAttribution
{
    public const WINDOW_DAYS = 30;

    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Credit a lead to a campaign by hand or by link, and keep its UTM values.
     *
     * @param  array<string, mixed>  $utm
     */
    public function tag(Lead $lead, ?int $campaignId, array $utm, string $how = 'manual'): Lead
    {
        $campaign = $campaignId === null ? null : Campaign::query()->whereKey($campaignId)->first();
        $clean = $this->cleanUtm($utm);

        if ($campaign === null && $clean === []) {
            return $lead;
        }

        // UTM values with no campaign of their own must not wipe a campaign the lead was already credited to (an inferred one)
        $lead->forceFill([
            'campaign_id' => $campaign === null ? $lead->campaign_id : $campaign->getKey(), 'utm' => $clean === [] ? null : $clean,
            'attribution' => $campaign === null ? $lead->attribution : $how,
        ])->saveQuietly();

        $campaign !== null && $this->credit($campaign->getKey(), $lead);

        return $lead;
    }

    /**
     * A new lead that nobody tagged: credit it to the campaign that wrote to the same person lately, if there is one. Runs when a lead is made (an
     * observer), for any way a lead can arrive.
     */
    public function inferFor(Lead $lead): void
    {
        if ($lead->campaign_id !== null) {
            return;
        }

        $mobile = ContactNormalizer::mobile($lead->mobile);
        $email = ContactNormalizer::email($lead->email);

        if ($mobile === null && $email === null) {
            return;
        }

        $member = CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $lead->company_id)->whereNotNull('last_sent_at')
            ->where('last_sent_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where(fn ($q) => $q->when($mobile !== null, fn ($w) => $w->orWhere('mobile', $mobile))->when($email !== null, fn ($w) => $w->orWhere('email', $email)))
            ->orderByDesc('last_sent_at')->first();

        if ($member === null) {
            return;
        }

        $lead->forceFill(['campaign_id' => $member->campaign_id, 'attribution' => 'inferred'])->saveQuietly();
        $this->credit($member->campaign_id, $lead, $member);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    public function cleanUtm(array $raw): array
    {
        $out = [];

        foreach (self::UTM_KEYS as $key) {
            $value = is_scalar($raw[$key] ?? null) ? trim((string) preg_replace('/[^\p{L}\p{N} _.\-]+/u', '', (string) $raw[$key])) : '';
            $value === '' || $out[$key] = mb_substr($value, 0, 100);
        }

        return $out;
    }

    /** Mark the campaign's member (the one given, or whoever has this lead's contact details) as having responded, with this lead. */
    private function credit(int $campaignId, Lead $lead, ?CampaignMember $member = null): void
    {
        $mobile = ContactNormalizer::mobile($lead->mobile);
        $email = ContactNormalizer::email($lead->email);

        $member ??= CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->where('campaign_id', $campaignId)->where('company_id', $lead->company_id)
            ->where(fn ($q) => $q->when($mobile !== null, fn ($w) => $w->orWhere('mobile', $mobile))->when($email !== null, fn ($w) => $w->orWhere('email', $email)))->first();

        if ($member === null) {
            return;
        }

        CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($member->getKey())->update([
            'lead_id' => DB::raw('coalesce(lead_id, '.(int) $lead->getKey().')'),
            'responded_at' => DB::raw('coalesce(responded_at, now())'),
            // the columns that read `status` come first: in one UPDATE the later assignments see the earlier ones' new values
            'exit_reason' => DB::raw("case when status = 'active' then 'responded' else exit_reason end"),
            'next_due_at' => DB::raw("case when status = 'active' then null else next_due_at end"),
            'status' => DB::raw("case when status = 'active' then 'exited' else status end"), 'updated_at' => now(),
        ]);
    }
}
