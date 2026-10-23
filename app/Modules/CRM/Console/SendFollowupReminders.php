<?php

namespace App\Modules\CRM\Console;

use App\Models\User;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Notifications\FollowupDueNotification;
use App\Modules\CRM\Notifications\FollowupOverdueNotification;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\Heartbeat;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Support\Format;
use App\Modules\Workspace\Enums\SlaKind;
use App\Modules\Workspace\Services\SlaPolicies;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reminds people of their planned follow-ups, and tells their manager about the ones left overdue — in-app and by
 * e-mail, once each.
 *
 * Run by the scheduler every five minutes for every company.
 *
 *  - **Reminder**: a follow-up is due for its reminder when it is planned to happen within the company's lead time
 *    (`followups.remind_minutes_before`, 30 minutes by default) — or is already late — and has not been reminded yet.
 *    Follow-ups more than a day late are left alone: after an outage, a flood of stale reminders helps nobody.
 *  - **Escalation**: a follow-up still planned `followups.escalate_after_hours` (24 by default; 0 = off) after it was
 *    due is reported to the manager of the team its person is on, once. Ones more than 30 days late are left alone.
 *
 * Each is *claimed* with a single conditional UPDATE before anything is sent, so two overlapping runs (or a retried one)
 * can never tell anybody twice; a follow-up that is rescheduled is re-armed for both.
 */
class SendFollowupReminders extends Command
{
    protected $signature = 'crm:followup-reminders';

    protected $description = 'Remind people of the follow-ups that are coming up, and tell managers about overdue ones (in-app and e-mail), once each';

    /** Longest lead time a company can set, in minutes; also how far ahead the candidates are fetched. */
    private const MAX_LEAD_MINUTES = 1440;

    private const BATCH = 2000;

    public function handle(SettingsService $settings): int
    {
        $now = now();

        $this->info('Follow-up reminders sent: '.$this->remind($settings, $now));
        $this->info('Overdue follow-ups escalated: '.$this->escalate($settings, $now));
        app(Heartbeat::class)->beat('crm:followup-reminders');

        return self::SUCCESS;
    }

    private function remind(SettingsService $settings, Carbon $now): int
    {
        $sent = 0;
        $leadTimes = [];

        // System work across every company: tenancy scopes are lifted on purpose, soft-deletes are not.
        $candidates = Followup::withoutGlobalScopes(Tenancy::SCOPES)
            ->where('status', FollowupStatus::Pending->value)
            ->whereNull('reminder_sent_at')
            ->where('scheduled_at', '<=', $now->copy()->addMinutes(self::MAX_LEAD_MINUTES))
            ->where('scheduled_at', '>=', $now->copy()->subDay())
            ->orderBy('scheduled_at')
            ->limit(self::BATCH)
            ->get(['id', 'company_id', 'subject_type', 'subject_id', 'scheduled_at', 'type', 'assigned_to']);

        foreach ($candidates as $followup) {
            $companyId = (int) $followup->getAttribute('company_id');
            $leadTimes[$companyId] ??= max(0, min(self::MAX_LEAD_MINUTES, (int) $settings->get('followups.remind_minutes_before', $companyId)));

            if ($followup->scheduled_at->gt($now->copy()->addMinutes($leadTimes[$companyId]))) {
                continue;   // not yet within this company's lead time
            }

            // Claim it first: whoever wins this UPDATE sends the reminder, and nobody else does.
            if (! $this->claim($followup, 'reminder_sent_at', $now)) {
                continue;
            }

            $lead = $this->openLead($followup);
            $person = User::query()->where('is_active', true)->find($followup->assigned_to);

            if ($lead === null || $person === null) {
                continue;   // nothing to act on, or nobody to tell
            }

            $person->notify(new FollowupDueNotification(
                what: $followup->type->label(),
                with: $lead->title(),
                when: Format::dateTime($followup->scheduled_at),
                late: $followup->scheduled_at->lt($now),
                url: route('crm.leads.show', $lead->getKey(), false),
            ));

            $sent++;
        }

        return $sent;
    }

    private function escalate(SettingsService $settings, Carbon $now): int
    {
        $sent = 0;
        $thresholds = [];
        app(SlaPolicies::class)->forget();   // the policies in force now, not the ones this process saw before

        $candidates = Followup::withoutGlobalScopes(Tenancy::SCOPES)
            ->where('status', FollowupStatus::Pending->value)
            ->whereNull('escalated_at')
            ->where('scheduled_at', '<=', $now->copy()->subHour())      // the shortest threshold a company can set
            ->where('scheduled_at', '>=', $now->copy()->subDays(30))
            ->orderBy('scheduled_at')
            ->limit(self::BATCH)
            ->get(['id', 'company_id', 'subject_type', 'subject_id', 'scheduled_at', 'type', 'assigned_to', 'created_at']);

        foreach ($candidates as $followup) {
            $companyId = (int) $followup->getAttribute('company_id');
            $thresholds[$companyId] ??= max(0, (int) $settings->get('followups.escalate_after_hours', $companyId));

            if ($thresholds[$companyId] === 0 || $followup->scheduled_at->gt($now->copy()->subHours($thresholds[$companyId]))) {
                continue;   // escalation is off for this company, or it is not late enough yet
            }

            // a company that times its follow-ups with an SLA policy is told by the SLA sweep (once, in the breach log): this older alert stands down
            if (app(SlaPolicies::class)->covers($companyId, SlaKind::Followup, $followup->created_at)) {
                continue;
            }

            if (! $this->claim($followup, 'escalated_at', $now)) {
                continue;
            }

            $lead = $this->openLead($followup);
            $person = User::query()->find($followup->assigned_to);
            $manager = $this->managerOf((int) $followup->assigned_to, $companyId, $now);

            if ($lead === null || $person === null || $manager === null) {
                continue;   // nothing to act on, or the person is on no team (nobody to tell)
            }

            $manager->notify(new FollowupOverdueNotification(
                person: $person->name,
                what: $followup->type->label(),
                with: $lead->title(),
                when: Format::dateTime($followup->scheduled_at),
                url: route('crm.leads.show', $lead->getKey(), false),
            ));

            $sent++;
        }

        return $sent;
    }

    /** Stamp $column on a follow-up that is still planned and still unstamped. True for the one run that got there first. */
    private function claim(Followup $followup, string $column, Carbon $now): bool
    {
        return Followup::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($followup->getKey())
            ->whereNull($column)->where('status', FollowupStatus::Pending->value)
            ->update([$column => $now]) === 1;
    }

    /** The lead a follow-up belongs to, if it is still being worked. */
    private function openLead(Followup $followup): ?Lead
    {
        if ($followup->subject_type !== (new Lead)->getMorphClass()) {
            return null;
        }

        $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $followup->getAttribute('company_id'))->find($followup->subject_id);

        return $lead !== null && $lead->isOpen() ? $lead : null;
    }

    /** The active manager of the active team a person is on today — never the person themselves. */
    private function managerOf(int $userId, int $companyId, Carbon $now): ?User
    {
        $teamId = SalesTeamMember::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('user_id', $userId)
            ->where('joined_on', '<=', $now->toDateString())
            ->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $now->toDateString()))
            ->value('sales_team_id');

        if ($teamId === null) {
            return null;
        }

        $managerId = SalesTeam::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('status', 'active')
            ->whereKey($teamId)->value('manager_user_id');

        if ($managerId === null || (int) $managerId === $userId) {
            return null;
        }

        return User::query()->where('is_active', true)->find($managerId);
    }
}
