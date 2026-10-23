<?php

namespace App\Modules\Masters\Services;

use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\State;
use Illuminate\Support\Collection;

/** Result of a PIN code lookup. `state`, `district` and `block` are set only when the PIN maps to exactly one. */
final class PinLookup
{
    /**
     * @param  Collection<int, PinCode>  $localities  every row for the PIN, for a "pick the locality" prompt
     */
    public function __construct(
        public readonly string $pin,
        public readonly bool $found,
        public readonly ?State $state,
        public readonly ?District $district,
        public readonly ?Block $block,
        public readonly Collection $localities,
    ) {}

    /** True when the PIN is known but maps to more than one district or block. */
    public function isAmbiguous(): bool
    {
        return $this->found && ($this->district === null || $this->block === null);
    }
}
