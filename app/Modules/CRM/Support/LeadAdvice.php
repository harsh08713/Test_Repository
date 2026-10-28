<?php

namespace App\Modules\CRM\Support;

use Carbon\CarbonImmutable;

/** What to do next about a lead and a sentence or two on where it stands — advice for a person, never an instruction to the system. */
final readonly class LeadAdvice
{
    /** @param  list<string>  $actions  the most useful next steps, most pressing first (at most three) */
    public function __construct(
        public array $actions,
        public string $summary,
        /** When to be back in touch by, if nothing is planned. Null when a follow-up is already planned, or the lead is closed. */
        public ?CarbonImmutable $contactBy,
    ) {}
}
