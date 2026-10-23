<?php

namespace App\Modules\Masters\Services;

use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Rules\IndianPinCode;
use Illuminate\Support\Collection;

/**
 * Resolves a PIN code against the company's geography master to pre-fill state, district and block.
 *
 * Only unambiguous parts are filled: if a PIN spans two districts the district is left empty (and the
 * candidates are returned), rather than guessing. The query is tenant-scoped like every other read.
 */
final class PinCodeLookup
{
    public function resolve(string $pin): PinLookup
    {
        $pin = trim($pin);

        if (! IndianPinCode::isValid($pin)) {
            return new PinLookup($pin, false, null, null, null, collect());
        }

        $rows = PinCode::query()
            ->where('pin_code', $pin)
            ->where('status', 'active')
            ->with(['state', 'district', 'block'])
            ->orderBy('area')
            ->get();

        if ($rows->isEmpty()) {
            return new PinLookup($pin, false, null, null, null, collect());
        }

        return new PinLookup(
            pin: $pin,
            found: true,
            state: $this->only($rows->map(fn (PinCode $r): ?State => $r->state)),
            district: $this->only($rows->map(fn (PinCode $r): ?District => $r->district)),
            // A block is only certain when every locality of the PIN names the same one.
            block: $rows->contains(fn (PinCode $r) => $r->block_id === null) ? null : $this->only($rows->map(fn (PinCode $r): ?Block => $r->block)),
            localities: $rows,
        );
    }

    /**
     * The single distinct model in the collection, or null when there are none or several.
     *
     * @template TModel of State|District|Block
     *
     * @param  Collection<int, TModel|null>  $models
     * @return TModel|null
     */
    private function only(Collection $models): State|District|Block|null
    {
        $distinct = $models->filter()->unique(fn (State|District|Block $m) => $m->getKey());

        return $distinct->count() === 1 ? $distinct->first() : null;
    }
}
