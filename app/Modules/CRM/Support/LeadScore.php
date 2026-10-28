<?php

namespace App\Modules\CRM\Support;

/**
 * A lead's score and the reasons for it: each signal with the points it earned, the points it could have earned and a plain sentence saying why. The factors add up to
 * the score — nothing is hidden in it. A closed lead (won or lost) is not scored.
 */
final readonly class LeadScore
{
    public const HOT = 'hot';

    public const WARM = 'warm';

    public const COLD = 'cold';

    /** @param  list<array{key: string, label: string, points: int, max: int, detail: string}>  $factors */
    public function __construct(
        public bool $closed,
        public int $score,
        public string $band,
        public array $factors,
        public int $earned,
        public int $available,
    ) {}

    public static function closed(): self
    {
        return new self(true, 0, self::COLD, [], 0, 0);
    }

    public function bandLabel(): string
    {
        return match ($this->band) {
            self::HOT => 'Hot',
            self::WARM => 'Warm',
            default => 'Cold',
        };
    }

    public function badge(): string
    {
        return match ($this->band) {
            self::HOT => 'bg-rose-100 text-rose-800',
            self::WARM => 'bg-amber-100 text-amber-800',
            default => 'bg-sky-100 text-sky-800',
        };
    }
}
