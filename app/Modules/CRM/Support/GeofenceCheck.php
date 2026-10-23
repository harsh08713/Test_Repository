<?php

namespace App\Modules\CRM\Support;

use App\Modules\CRM\Enums\ViolationReason;
use App\Modules\CRM\Models\RouteStop;

/**
 * The server's verdict on one reported position against one route plan. Immutable. `stop`, `distanceM` and `radiusM`
 * describe the stop that came closest to qualifying (they are set whenever a position could be measured, accepted or not).
 */
final readonly class GeofenceCheck
{
    public function __construct(
        public bool $allowed,
        public ?ViolationReason $reason,
        public string $message,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $accuracyM = null,
        public ?RouteStop $stop = null,
        public ?float $distanceM = null,
        public ?int $radiusM = null,
    ) {}
}
