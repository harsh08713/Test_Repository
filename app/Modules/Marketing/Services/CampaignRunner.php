<?php

namespace App\Modules\Marketing\Services;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\CampaignStep;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sends the steps of running campaigns, on a schedule (`marketing:run`, every five minutes). For each member whose next step is due it:
 *
 *  1. looks at **why the sequence should end** — the person responded, the lead has moved on to a stage the campaign lists as an exit, the record is
 *     gone, they opted out or never agreed to marketing — and if so records the reason and stops;
 *  2. otherwise **holds back** if the address has already had its limit of marketing messages this week (it is tried again tomorrow, no message is made);
 *  3. otherwise asks `CommunicationService` for the message with the key `c{campaign}:m{member}:s{step}` — the same step for the same member can never
 *     produce two messages, however often this runs or crashes — and **then** moves the member on to the next step (or finishes them).
 *
 * The message is made before the member is moved on, and the move is a conditional UPDATE on the step the member was at: a crash between the two
 * leaves a message that already exists (which the next run finds by its key) and a member that is moved on once.
 */
final class CampaignRunner
{
    private const BATCH = 200;

    public function __construct(
        private readonly CommunicationService $messages,
        private readonly ConsentService $consent,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{sent: int, exited: int, held: int, completed: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $result = ['sent' => 0, 'exited' => 0, 'held' => 0, 'completed' => 0];

        $this->tenant->withoutTenancy(function () use ($now, &$result) {
            $campaigns = Campaign::withoutGlobalScopes(Tenancy::SCOPES)->where('state', Campaign::RUNNING)->where('start_date', '<=', $now->toDateString())->orderBy('id')->get();

            foreach ($campaigns as $campaign) {
                $steps = CampaignStep::withoutGlobalScopes(Tenancy::SCOPES)->where('campaign_id', $campaign->getKey())->orderBy('position')->with('template')->get()->keyBy('position');

                $members = CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->where('campaign_id', $campaign->getKey())->where('status', 'active')
                    ->where('next_due_at', '<=', $now)->orderBy('next_due_at')->orderBy('id')->limit(self::BATCH)->get();

                foreach ($members as $member) {
                    $this->handle($campaign, $member, $steps, $now, $result);
                }

                $this->finishIfDone($campaign, $now) && $result['completed']++;
            }
        });

        return $result;
    }

    /**
     * @param  Collection<int, CampaignStep>  $steps
     * @param  array{sent: int, exited: int, held: int, completed: int}  $result
     */
    private function handle(Campaign $campaign, CampaignMember $member, $steps, Carbon $now, array &$result): void
    {
        $step = $steps->get($member->next_step);

        if ($step === null || $step->template === null) {
            $this->leave($member, 'completed', null, $member->next_step, $result);

            return;
        }

        if ($member->responded_at !== null) {
            $this->leave($member, 'exited', 'responded', $member->next_step, $result);

            return;
        }

        $lead = $member->lead_id === null ? null : Lead::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->find($member->lead_id);

        if ($member->lead_id !== null && ($lead === null || $lead->trashed())) {
            $this->leave($member, 'exited', 'record_gone', $member->next_step, $result);

            return;
        }

        if ($lead !== null && in_array($lead->stage->value, $campaign->exit_stages ?? [], true)) {
            $this->leave($member, 'exited', 'stage_reached', $member->next_step, $result);

            return;
        }

        $template = $step->template;
        $address = $template->channel->value === 'email' ? $member->email : $member->mobile;
        $check = $this->consent->check($template->channel, $address, $template->purpose, (int) $campaign->company_id);

        if (! $check['ok']) {
            $this->leave($member, 'exited', $check['reason'] === 'Opted out.' ? 'opted_out' : 'no_consent', $member->next_step, $result);

            return;
        }

        if ($this->messages->capReached($template, $address)) {
            CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($member->getKey())->where('status', 'active')->where('next_step', $member->next_step)
                ->update(['next_due_at' => $now->copy()->addDay(), 'updated_at' => now()]);
            $result['held']++;

            return;
        }

        try {
            $this->messages->queue($template, [
                'name' => $member->name, 'address' => $address, 'subject_type' => $member->subject_type, 'subject_id' => $member->subject_id,
                'values' => ['owner_name' => (string) $campaign->owner?->name, 'reference' => (string) $lead?->lead_number],
            ], "c{$campaign->getKey()}:m{$member->getKey()}:s{$member->next_step}", $campaign, $member);
        } catch (FieldRuleException) {
            // the template was switched off since the campaign was approved: wait, try again in an hour
            CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($member->getKey())->where('status', 'active')->where('next_step', $member->next_step)
                ->update(['next_due_at' => $now->copy()->addHour(), 'updated_at' => now()]);
            $result['held']++;

            return;
        }

        $result['sent']++;
        $next = $steps->get($member->next_step + 1);

        $moved = CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($member->getKey())->where('status', 'active')->where('next_step', $member->next_step)
            ->update($next === null
                ? ['status' => 'completed', 'next_due_at' => null, 'last_sent_at' => $now, 'updated_at' => now()]
                : ['next_step' => $member->next_step + 1, 'next_due_at' => $now->copy()->addHours($next->delay_hours), 'last_sent_at' => $now, 'updated_at' => now()]);

        $moved === 1 || $result['sent']--;   // another run moved them on first: that run counted the message
    }

    /** @param array{sent: int, exited: int, held: int, completed: int} $result */
    private function leave(CampaignMember $member, string $status, ?string $reason, int $atStep, array &$result): void
    {
        $left = CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($member->getKey())->where('status', 'active')->where('next_step', $atStep)
            ->update(['status' => $status, 'exit_reason' => $reason, 'next_due_at' => null, 'updated_at' => now()]);

        $left === 1 && $status === 'exited' && $result['exited']++;
    }

    /** A campaign is done when its end date has passed or nobody is left in it. */
    private function finishIfDone(Campaign $campaign, Carbon $now): bool
    {
        $ended = $campaign->end_date !== null && $campaign->end_date->lt($now->copy()->startOfDay());
        $anyone = CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->where('campaign_id', $campaign->getKey())->where('status', 'active')->exists();

        if (! $ended && $anyone) {
            return false;
        }

        $done = Campaign::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($campaign->getKey())->where('state', Campaign::RUNNING)->update(['state' => Campaign::COMPLETED, 'completed_at' => $now]);

        if ($done === 1 && $ended) {
            CampaignMember::withoutGlobalScopes(Tenancy::SCOPES)->where('campaign_id', $campaign->getKey())->where('status', 'active')->update(['status' => 'exited', 'exit_reason' => 'campaign_ended', 'next_due_at' => null, 'updated_at' => now()]);
        }

        return $done === 1;
    }
}
