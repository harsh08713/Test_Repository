<?php

namespace App\Modules\Masters\Support;

/**
 * The rate RateService found, and where it came from. Immutable; amounts are strings (never floats).
 *
 * `source` is `price_list` when the requested price list had its own rate and `standard` when the standard
 * (no price list) rate was used instead — so a quotation can say which one it quoted.
 */
final readonly class RateQuote
{
    public function __construct(
        public int $rateId,
        public int $itemId,
        public int $locationId,
        public int $unitId,
        public ?int $priceListId,
        public string $rate,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $asOf,
        public string $source,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rate_id' => $this->rateId,
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'unit_id' => $this->unitId,
            'price_list_id' => $this->priceListId,
            'rate' => $this->rate,
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
            'as_of' => $this->asOf,
            'source' => $this->source,
        ];
    }
}
