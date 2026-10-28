<?php

namespace App\Modules\CRM\Support;

/**
 * How a company scores a lead (Company settings → Lead scoring): which signals count and how much. The six weights are shares of one whole — the score is the
 * points a lead earns out of the points available, shown out of 100 — so any weights make sense, and a weight of 0 switches a signal off.
 */
final readonly class ScoringConfig
{
    /** @param  array<string, int>  $sourcePercent  lead source code => percent (0–100) of the source weight */
    public function __construct(
        public bool $enabled,
        public int $hotFrom,
        public int $warmFrom,
        public int $maxSource,
        public int $maxCompleteness,
        public int $maxEngagement,
        public int $maxRecency,
        public int $maxStage,
        public int $maxValue,
        public int $valueThreshold,
        public array $sourcePercent,
    ) {}

    /**
     * "REFERENCE=100, WEBSITE=60" as a map. What cannot be read is ignored (a typo costs one source its points, never the whole setting), a percentage is
     * brought into 0–100, and a code is upper-cased.
     *
     * @return array<string, int>
     */
    public static function parseSources(string $text): array
    {
        $map = [];

        foreach (preg_split('/[,;\n]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (preg_match('/^\s*([A-Za-z0-9._\-]{1,30})\s*=\s*(-?\d{1,4})\s*$/', $pair, $found) === 1) {
                $map[strtoupper($found[1])] = max(0, min(100, (int) $found[2]));
            }
        }

        return $map;
    }

    /** Points the six signals can earn together. */
    public function available(): int
    {
        return $this->maxSource + $this->maxCompleteness + $this->maxEngagement + $this->maxRecency + $this->maxStage + $this->maxValue;
    }
}
