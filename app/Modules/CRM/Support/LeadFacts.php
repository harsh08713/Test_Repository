<?php

namespace App\Modules\CRM\Support;

use Carbon\CarbonImmutable;

/** What a lead's follow-ups say about it, counted once (LeadScorer::facts) and shared by the score and the advice. */
final readonly class LeadFacts
{
    public function __construct(
        /** Follow-ups that were done. */
        public int $done,
        /** Of those, the ones whose outcome means the person was actually reached. */
        public int $connected,
        /** Follow-ups still planned. */
        public int $pending,
        /** Planned follow-ups whose time has passed. */
        public int $overdue,
        public ?CarbonImmutable $lastDoneAt,
    ) {}
}
