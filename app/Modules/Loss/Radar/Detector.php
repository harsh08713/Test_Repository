<?php

namespace App\Modules\Loss\Radar;

use Carbon\CarbonInterface;

/**
 * One way the Radar looks for a loss. A detector knows *which records* look like a loss and nothing else: it touches no data, works out no money and
 * creates nothing — `RadarService` describes each record through `LossSources`, values it through `LossCalculationService` and keeps the finding.
 * Records are found through the company's own scopes (the sweep runs inside one company).
 */
interface Detector
{
    /** A short stable key kept on every finding (with the source it found, it makes the finding unique). */
    public function key(): string;

    /** One sentence: what this detector looks at and why it counts as a loss. Shown beside each finding. */
    public function method(): string;

    /**
     * @return iterable<int, array{0: string, 1: int}> pairs of a LossSources type and a record id
     */
    public function candidates(CarbonInterface $now, int $lookbackDays): iterable;
}
