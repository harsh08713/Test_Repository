<?php

namespace App\Modules\CRM\Support;

/**
 * How far a target has got. Amounts are exact decimal strings; a percentage has one decimal and may exceed 100.
 * A percentage is null when the target has no value (or no quantity) to measure against.
 */
final readonly class TargetProgress
{
    public function __construct(
        public ?string $targetValue,
        public string $achievedValue,
        public ?string $valuePercent,
        public ?string $targetQuantity,
        public string $achievedQuantity,
        public ?string $quantityPercent,
        public bool $live,
    ) {}
}
