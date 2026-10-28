<?php

namespace App\Modules\CRM\Support;

use App\Modules\CRM\Enums\DealStage;

/**
 * An advisory estimate of a deal's chance of being won, from how the company's own closed deals ended. It sits beside the salesperson's figure and never replaces it.
 * `samples` and `won` are the closed deals it rests on (the ones that ever reached this stage); `confidence` says how much that is.
 */
final readonly class DealForecast
{
    public const THIN = 'thin';

    public const FAIR = 'fair';

    public const GOOD = 'good';

    public function __construct(
        public DealStage $stage,
        /** The estimate, per cent, between 1 and 99 (the range a deal's own probability keeps). */
        public int $percent,
        /** Closed deals that ever reached this stage. */
        public int $samples,
        /** Of those, the ones that were won. */
        public int $won,
        /** The stage's usual chance, per cent, which the estimate starts from. */
        public int $prior,
        public string $confidence,
        /** The deal's expected value × the estimate, as a decimal string. */
        public string $weighted,
    ) {}

    public function confidenceLabel(): string
    {
        return match ($this->confidence) {
            self::GOOD => 'Good history',
            self::FAIR => 'Fair history',
            default => 'Thin history',
        };
    }

    public function badge(): string
    {
        return match ($this->confidence) {
            self::GOOD => 'bg-emerald-100 text-emerald-800',
            self::FAIR => 'bg-amber-100 text-amber-800',
            default => 'bg-slate-100 text-slate-700',
        };
    }
}
