<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Support\LeadAdvice;
use App\Modules\CRM\Support\LeadFacts;
use App\Modules\CRM\Support\LeadScore;
use App\Modules\Shared\Support\Format;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Next steps and a plain summary for a lead, from what is stored about it (brief 6.2: "suggest next actions, follow-up timing"; "summarize CRM activities from available stored data").
 * **Fixed rules, no AI**: the same record gives the same advice. It reads, it never writes — nothing here plans a follow-up, moves a stage or sends a message; it tells the person what
 * would be sensible and they decide.
 *
 * The steps, most pressing first (at most three are offered):
 *  1. a planned follow-up whose time has passed;
 *  2. nothing planned at all;
 *  3. nobody has ever reached the lead (and it is more than a day old);
 *  4. no contact for two weeks or more;
 *  5. details missing that stop the lead being worked (no mobile number);
 *  6. a stage that no longer fits what has happened (spoken to but still New; qualified but nothing started; qualified with no value);
 * and a **contact-by date** when nothing is planned: a hot lead within a day, a warm one within three, a cold one within a week.
 */
final class LeadAdvisor
{
    public function advise(Lead $lead, LeadScore $score, LeadFacts $facts, ?CarbonInterface $now = null): LeadAdvice
    {
        $now = CarbonImmutable::instance($now ?? now());
        $summary = $this->summary($lead, $facts);

        if ($score->closed) {
            return new LeadAdvice([], $summary, null);
        }

        $actions = [];
        $entered = CarbonImmutable::instance($lead->lead_date)->startOfDay();
        $enteredDays = max(0, (int) $entered->diffInDays($now->startOfDay(), true));

        if ($facts->overdue > 0) {
            $due = $lead->next_followup_at !== null ? CarbonImmutable::instance($lead->next_followup_at) : null;
            $late = $due === null ? null : max(0, (int) $due->startOfDay()->diffInDays($now->startOfDay(), true));
            $actions[] = ($facts->overdue === 1 ? 'A planned follow-up has' : "{$facts->overdue} planned follow-ups have").' passed its time'
                .($late === null || $late === 0 ? '' : " (the next was due {$late} day".($late === 1 ? '' : 's').' ago)').' — do it, or move it to a day you will keep.';
        }

        if ($facts->pending === 0) {
            $actions[] = 'Nothing is planned for this lead. Plan the next follow-up'.($lead->last_contacted_at === null ? ' — it has not been contacted yet.' : '.');
        }

        if ($lead->last_contacted_at === null && $enteredDays >= 1 && $facts->done === 0) {
            $actions[] = 'Nobody has reached this lead yet — it came in '.($enteredDays === 1 ? 'yesterday' : "{$enteredDays} days ago").'. Call it today.';
        }

        if ($lead->last_contacted_at !== null) {
            $quiet = max(0, (int) CarbonImmutable::instance($lead->last_contacted_at)->startOfDay()->diffInDays($now->startOfDay(), true));

            if ($quiet >= 14) {
                $actions[] = "The last contact was {$quiet} days ago — get back in touch before the lead goes cold.";
            }
        }

        if (blank($lead->mobile)) {
            $actions[] = 'There is no mobile number. Find it: the lead cannot be qualified without one.';
        }

        if ($lead->stage === LeadStage::New && $facts->connected > 0) {
            $actions[] = 'You have spoken to this lead — move it to Contacted.';
        } elseif ($lead->stage === LeadStage::Qualified) {
            $actions[] = ($lead->expected_value === null || bccomp((string) $lead->expected_value, '0', 2) <= 0)
                ? 'The lead is qualified but has no expected value — estimate it so the pipeline can be weighed.'
                : 'The lead is qualified — convert it to a deal, or quote it.';
        }

        $days = match ($score->band) {
            LeadScore::HOT => 1,
            LeadScore::WARM => 3,
            default => 7,
        };

        return new LeadAdvice(array_slice($actions, 0, 3), $summary, $facts->pending === 0 ? $now->startOfDay()->addDays($days) : null);
    }

    /** Where the lead stands, in sentences built only from what is stored. */
    public function summary(Lead $lead, LeadFacts $facts): string
    {
        $parts = ["Entered {$lead->lead_date->format('j M Y')} through ".$lead->entry_channel->label().($lead->source ? ' from '.$lead->source->name : '').'.'];

        if ($facts->done === 0 && $facts->pending === 0) {
            $parts[] = 'No follow-up has been planned or done.';
        } else {
            $parts[] = ($facts->done === 0 ? 'No follow-up done yet' : "{$facts->done} follow-up".($facts->done === 1 ? '' : 's')." done ({$facts->connected} reached the person)")
                .($facts->pending > 0 ? ", {$facts->pending} planned" : '').'.';
        }

        $parts[] = $lead->last_contacted_at === null ? 'Never contacted.' : 'Last contacted '.$lead->last_contacted_at->format('j M Y').'.';
        $parts[] = 'Stage: '.$lead->stage->label().'.';

        if ($lead->expected_value !== null && bccomp((string) $lead->expected_value, '0', 2) > 0) {
            $parts[] = 'Expected value '.Format::inr($lead->expected_value).'.';
        }

        return implode(' ', $parts);
    }
}
