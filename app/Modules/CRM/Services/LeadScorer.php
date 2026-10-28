<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Support\LeadFacts;
use App\Modules\CRM\Support\LeadScore;
use App\Modules\CRM\Support\ScoringConfig;
use App\Modules\Shared\Services\SettingsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Scores a lead from its own record with fixed, explainable rules (brief 6.2: "configurable lead scoring based on explicit business signals such as source, response
 * history, requirement completeness, engagement and time since last contact"). **There is no AI here**: the same record always gives the same score, every point has a
 * sentence saying where it came from, and the company decides the weights (Company settings → Lead scoring). It is advice — nothing here moves a lead, changes an owner
 * or sends a message.
 *
 * Six signals, each earning up to its weight:
 *  - **source**       the company's percentage for the lead's source (a referral is worth more than a spreadsheet);
 *  - **completeness** six details present: mobile, e-mail, contact person, a real requirement, an address (PIN, street or city), an expected value;
 *  - **engagement**   conversations that reached the person (follow-ups done whose outcome counts as a contact): none 0, one 40 %, two 70 %, three or more 100 %;
 *                     follow-ups done that never reached anyone earn 10 %;
 *  - **recency**      days since the last contact (3 days or less 100 %, 7 → 75 %, 14 → 50 %, 30 → 25 %, older 0); a lead never contacted counts from the day it was entered, at half;
 *  - **stage**        New 0, Contacted ⅓, Qualified ⅔, Deal or Quotation all of it;
 *  - **value**        the expected value against the company's threshold: all of it at the threshold, half at half.
 *
 * score = points earned ÷ points available × 100, rounded. Hot / warm / cold are the company's two cut-offs.
 */
final class LeadScorer
{
    public function __construct(private readonly SettingsService $settings) {}

    public function config(int $companyId): ScoringConfig
    {
        $int = fn (string $key): int => (int) $this->settings->get("scoring.{$key}", $companyId);

        return new ScoringConfig(
            enabled: (bool) $this->settings->get('scoring.enabled', $companyId),
            hotFrom: $int('hot_from'), warmFrom: $int('warm_from'),
            maxSource: $int('max_source'), maxCompleteness: $int('max_completeness'), maxEngagement: $int('max_engagement'),
            maxRecency: $int('max_recency'), maxStage: $int('max_stage'), maxValue: $int('max_value'),
            valueThreshold: $int('value_threshold'),
            sourcePercent: ScoringConfig::parseSources((string) $this->settings->get('scoring.source_points', $companyId)),
        );
    }

    /** One query over the lead's follow-ups. */
    public function facts(Lead $lead, ?CarbonInterface $now = null): LeadFacts
    {
        $now ??= now();

        $row = Followup::query()
            ->leftJoin('followup_outcomes as outcome', 'outcome.id', '=', 'followups.outcome_id')
            ->where('followups.subject_type', $lead->getMorphClass())
            ->where('followups.subject_id', $lead->getKey())
            ->toBase()
            ->selectRaw(
                "coalesce(sum(followups.status = 'done'), 0) as done,"
                ."coalesce(sum(followups.status = 'done' and outcome.counts_as_contact = 1), 0) as connected,"
                ."coalesce(sum(followups.status = 'pending'), 0) as pending,"
                ."coalesce(sum(followups.status = 'pending' and followups.scheduled_at < ?), 0) as overdue,"
                ."max(case when followups.status = 'done' then followups.completed_at end) as last_done",
                [$now->toDateTimeString()],
            )
            ->first();

        return new LeadFacts(
            (int) ($row->done ?? 0), (int) ($row->connected ?? 0), (int) ($row->pending ?? 0), (int) ($row->overdue ?? 0),
            ($row->last_done ?? null) === null ? null : CarbonImmutable::parse((string) $row->last_done),
        );
    }

    public function score(Lead $lead, LeadFacts $facts, ScoringConfig $config, ?CarbonInterface $now = null): LeadScore
    {
        if ($lead->stage === LeadStage::Won || $lead->stage === LeadStage::Lost) {
            return LeadScore::closed();
        }

        $now = CarbonImmutable::instance($now ?? now());

        $factors = [
            $this->source($lead, $config),
            $this->completeness($lead, $config),
            $this->engagement($facts, $config),
            $this->recency($lead, $config, $now),
            $this->stage($lead, $config),
            $this->value($lead, $config),
        ];

        $earned = array_sum(array_column($factors, 'points'));
        $available = $config->available();
        $score = $available === 0 ? 0 : (int) round(100 * $earned / $available);
        $band = $score >= $config->hotFrom ? LeadScore::HOT : ($score >= $config->warmFrom ? LeadScore::WARM : LeadScore::COLD);

        return new LeadScore(false, $score, $band, $factors, $earned, $available);
    }

    /** @return array{key: string, label: string, points: int, max: int, detail: string} */
    private function source(Lead $lead, ScoringConfig $config): array
    {
        // read by id, never from whatever relation a screen happened to load: a page that loads `source:id,name` has no code, and would score the same lead differently
        $source = LeadSource::query()->find($lead->lead_source_id, ['id', 'code', 'name']);
        $percent = $config->sourcePercent[strtoupper((string) $source?->code)] ?? 0;

        return $this->factor('source', 'Where it came from', (int) round($config->maxSource * $percent / 100), $config->maxSource,
            ($source->name ?? 'Unknown source').($percent > 0 ? " — worth {$percent}% here" : ' — not rated'));
    }

    /** @return array{key: string, label: string, points: int, max: int, detail: string} */
    private function completeness(Lead $lead, ScoringConfig $config): array
    {
        $has = [
            'a mobile number' => filled($lead->mobile),
            'an e-mail address' => filled($lead->email),
            'a contact person' => filled($lead->contact_person),
            'a requirement' => mb_strlen(trim((string) $lead->requirement)) >= 10,
            'an address' => filled($lead->pin_code) || filled($lead->address_line1) || filled($lead->city),
            'an expected value' => $lead->expected_value !== null && bccomp((string) $lead->expected_value, '0', 2) > 0,
        ];
        $present = count(array_filter($has));
        $missing = array_keys(array_filter($has, fn (bool $yes) => ! $yes));

        return $this->factor('completeness', 'Details filled in', (int) round($config->maxCompleteness * $present / count($has)), $config->maxCompleteness,
            "{$present} of ".count($has).($missing === [] ? ' — nothing missing' : ' — missing '.implode(', ', $missing)));
    }

    /** @return array{key: string, label: string, points: int, max: int, detail: string} */
    private function engagement(LeadFacts $facts, ScoringConfig $config): array
    {
        $share = match (true) {
            $facts->connected >= 3 => 1.0,
            $facts->connected === 2 => 0.7,
            $facts->connected === 1 => 0.4,
            $facts->done > 0 => 0.1,
            default => 0.0,
        };

        $detail = $facts->done === 0
            ? 'No follow-up has been done yet'
            : $this->count($facts->connected, 'conversation').' reached the person, from '.$this->count($facts->done, 'follow-up').' done';

        return $this->factor('engagement', 'Conversations held', (int) round($config->maxEngagement * $share), $config->maxEngagement, $detail);
    }

    /** @return array{key: string, label: string, points: int, max: int, detail: string} */
    private function recency(Lead $lead, ScoringConfig $config, CarbonImmutable $now): array
    {
        $contacted = $lead->last_contacted_at !== null;
        $since = CarbonImmutable::instance($contacted ? $lead->last_contacted_at : $lead->lead_date)->startOfDay();
        $days = max(0, (int) $since->diffInDays($now->startOfDay(), true));

        $share = match (true) {
            $days <= 3 => 1.0,
            $days <= 7 => 0.75,
            $days <= 14 => 0.5,
            $days <= 30 => 0.25,
            default => 0.0,
        };
        $contacted || $share *= 0.5;

        return $this->factor('recency', 'How recent the contact is', (int) round($config->maxRecency * $share), $config->maxRecency,
            $contacted ? 'Last contacted '.$this->ago($days) : 'Never contacted — entered '.$this->ago($days));
    }

    /** @return array{key: string, label: string, points: int, max: int, detail: string} */
    private function stage(Lead $lead, ScoringConfig $config): array
    {
        $share = match ($lead->stage) {
            LeadStage::Contacted => 1 / 3,
            LeadStage::Qualified => 2 / 3,
            LeadStage::Deal, LeadStage::Quotation => 1.0,
            default => 0.0,
        };

        return $this->factor('stage', 'How far it has progressed', (int) round($config->maxStage * $share), $config->maxStage, $lead->stage->label());
    }

    /** @return array{key: string, label: string, points: int, max: int, detail: string} */
    private function value(Lead $lead, ScoringConfig $config): array
    {
        $value = $lead->expected_value === null ? '0' : (string) $lead->expected_value;
        $positive = bccomp($value, '0', 2) > 0;
        $threshold = (string) $config->valueThreshold;

        $points = match (true) {
            ! $positive => 0,
            $config->valueThreshold === 0 || bccomp($value, $threshold, 2) >= 0 => $config->maxValue,
            bccomp($value, bcdiv($threshold, '2', 2), 2) >= 0 => (int) round($config->maxValue / 2),
            default => 0,
        };

        return $this->factor('value', 'Expected value', $points, $config->maxValue, $positive ? '₹'.number_format((float) $value, 0).' against ₹'.number_format($config->valueThreshold, 0) : 'No expected value entered');
    }

    /** @return array{key: string, label: string, points: int, max: int, detail: string} */
    private function factor(string $key, string $label, int $points, int $max, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'points' => max(0, min($max, $points)), 'max' => $max, 'detail' => $detail];
    }

    private function count(int $n, string $noun): string
    {
        return "{$n} {$noun}".($n === 1 ? '' : 's');
    }

    private function ago(int $days): string
    {
        return $days === 0 ? 'today' : ($days === 1 ? 'yesterday' : "{$days} days ago");
    }
}
