<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Contracts\AchievementSource;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Support\TargetProgress;

/**
 * Turns a target and the orders credited to people into "how far have we got" — exact decimal arithmetic, never floats.
 * What counts as achieved is the AchievementSource's business (see its contract for the policy: approved orders, net of
 * tax, cancellations out, returns in the period they are recorded).
 *
 * A **team** target adds up its members, but only for the days each was on the team: someone who joined mid-month brings
 * the orders from their joining day on, and someone who left takes none from the day after. The team history is kept
 * for this reason (members are never deleted).
 */
final class TargetAchievement
{
    public function __construct(private readonly AchievementSource $source) {}

    public function progress(SalesTarget $target): TargetProgress
    {
        $from = $target->period_start->toDateString();
        $to = $target->period_end->toDateString();
        $value = '0.00';
        $quantity = '0.000';

        foreach ($this->windows($target, $from, $to) as [$userId, $windowFrom, $windowTo]) {
            $got = $this->source->achieved($userId, $windowFrom, $windowTo, $target->item_group_id, $target->location_id);
            $value = bcadd($value, $got['value'], 2);
            $quantity = bcadd($quantity, $got['quantity'], 3);
        }

        return new TargetProgress(
            targetValue: $target->target_value,
            achievedValue: $value,
            valuePercent: $this->percent($value, $target->target_value),
            targetQuantity: $target->target_quantity,
            achievedQuantity: $quantity,
            quantityPercent: $this->percent($quantity, $target->target_quantity),
            live: $this->source->isLive(),
        );
    }

    /**
     * Who to ask about, and for which days: a person's own period, or each team member's days on the team inside it.
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function windows(SalesTarget $target, string $from, string $to): array
    {
        if ($target->user_id !== null) {
            return [[(int) $target->user_id, $from, $to]];
        }

        $windows = [];

        $members = SalesTeamMember::query()
            ->where('sales_team_id', $target->sales_team_id)
            ->where('joined_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $from))
            ->get();

        foreach ($members as $member) {
            $windowFrom = max($from, $member->joined_on->toDateString());
            $windowTo = $member->left_on === null ? $to : min($to, $member->left_on->toDateString());

            if ($windowFrom <= $windowTo) {
                $windows[] = [(int) $member->user_id, $windowFrom, $windowTo];
            }
        }

        return $windows;
    }

    /** achieved ÷ target × 100, to one decimal, half up; null when there is nothing to measure against. */
    private function percent(string $achieved, ?string $target): ?string
    {
        if ($target === null || bccomp($target, '0', 3) <= 0) {
            return null;
        }

        $ratio = bcdiv(bcmul($achieved, '100', 8), $target, 8);

        return bcadd(bcadd($ratio, '0.05', 8), '0', 1);
    }
}
