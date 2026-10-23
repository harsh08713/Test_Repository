<?php

namespace App\Modules\Masters\Services;

use App\Models\User;
use App\Modules\Masters\Enums\RateFailure;
use App\Modules\Masters\Exceptions\RateNotFoundException;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Support\RateQuote;
use App\Modules\Shared\Models\Location;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The one way a transaction finds what an item costs.
 *
 * `getRate()` returns a rate only if it is **approved, active and effective on the date asked**, for an approved,
 * active item, in a unit the item has, at a branch the caller may use, in the caller's company. Anything else is a
 * `RateNotFoundException` that says why — it never quietly falls back to an unapproved, expired or other branch's
 * rate, never converts between units, and never guesses.
 *
 * Which rate wins:
 *  1. The requested price list's own rate; if it has none and `$fallbackToStandard` is true, the standard rate
 *     (the quote says which one it used). With no price list asked for, only the standard rate is considered.
 *  2. Periods are inclusive at both ends ("effective on 31 Mar" includes a rate ending 31 Mar).
 *  3. Overlapping live rates for one slot cannot be created, so at most one rate qualifies. Should data ever
 *     contain two anyway, the tie is broken the same way every time — the later `effective_from`, then the more
 *     recently approved, then the higher id — rather than arbitrarily.
 *
 * Changing a looked-up rate on a quotation or order is a separate, permissioned and audited act: `recordOverride()`.
 */
final class RateService
{
    /**
     * @throws RateNotFoundException
     * @throws InvalidArgumentException when the date cannot be read
     */
    public function getRate(
        Item|int $item,
        Location|int $location,
        Unit|int $uom,
        CarbonInterface|string|null $date = null,
        PriceList|int|null $priceList = null,
        bool $fallbackToStandard = true,
    ): RateQuote {
        $day = $this->day($date);

        $itemModel = Item::query()->usable()->with('uoms')->find($item instanceof Item ? $item->getKey() : $item);
        if ($itemModel === null) {
            throw new RateNotFoundException(RateFailure::ItemNotAvailable, 'That item is not available: it is not approved, is switched off, or does not exist.');
        }

        // Location carries the branch scope on its own id, so a branch the caller does not work in simply is not found.
        $branch = Location::query()->where('status', 'active')->find($location instanceof Location ? $location->getKey() : $location);
        if ($branch === null) {
            throw new RateNotFoundException(RateFailure::LocationNotAvailable, 'That branch is not available: it is inactive, or not one you work in.');
        }

        $unitId = $uom instanceof Unit ? $uom->getKey() : $uom;
        $unit = Unit::query()->where('status', 'active')->find($unitId);
        if ($unit === null || ((int) $unit->getKey() !== (int) $itemModel->base_unit_id && $itemModel->uoms->doesntContain(fn ($row) => (int) $row->unit_id === (int) $unit->getKey()))) {
            throw new RateNotFoundException(RateFailure::UnitNotAllowed, "{$itemModel->name} is not sold in that unit: use its base unit or one of its conversions.");
        }

        $list = null;
        if ($priceList !== null) {
            $list = PriceList::query()->where('status', 'active')->find($priceList instanceof PriceList ? $priceList->getKey() : $priceList);
            if ($list === null) {
                throw new RateNotFoundException(RateFailure::PriceListNotAvailable, 'That price list is not available: it is inactive, or does not exist.');
            }
        }

        $listId = $list?->getKey();
        $found = $this->eligible($itemModel->getKey(), $branch->getKey(), $unit->getKey(), $listId, $day);
        $source = $listId === null ? 'standard' : 'price_list';

        if ($found === null && $listId !== null && $fallbackToStandard) {
            $listId = null;
            $source = 'standard';
            $found = $this->eligible($itemModel->getKey(), $branch->getKey(), $unit->getKey(), null, $day);
        }

        if ($found === null) {
            throw $this->explain($itemModel, $branch, $unit, $listId, $day);
        }

        return new RateQuote(
            rateId: $found->getKey(),
            itemId: $itemModel->getKey(),
            locationId: $branch->getKey(),
            unitId: $unit->getKey(),
            priceListId: $found->price_list_id,
            rate: bcadd((string) $found->rate, '0', Rate::SCALE),
            effectiveFrom: $found->effective_from->toDateString(),
            effectiveTo: $found->effective_to?->toDateString(),
            asOf: $day,
            source: $source,
        );
    }

    /**
     * Record that someone priced a line differently from the rate this service returned. Needs the dedicated
     * `rate.override` permission and a reason; writes an audit event on the rate holding the original and the
     * overridden value, the reason and the document it was done on. It does not change the rate.
     *
     * @return string the overridden rate, normalised to the stored precision
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function recordOverride(RateQuote $original, string $newRate, string $reason, User $by, ?Model $context = null): string
    {
        if (! $by->can('rate.override')) {
            throw new AuthorizationException('You are not allowed to change a rate on a quotation or order.');
        }

        $data = Validator::make(
            ['rate' => trim($newRate), 'reason' => trim($reason)],
            [
                'rate' => ['bail', 'required', 'regex:/^\d{1,11}(\.\d{1,'.Rate::SCALE.'})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '0', Rate::SCALE) > 0 ? null : $fail('The rate must be more than zero.')],
                'reason' => ['required', 'string', 'min:3', 'max:1000'],
            ],
            ['rate.regex' => 'Enter the rate as a number with at most '.Rate::SCALE.' decimals.', 'reason.required' => 'Please say why the rate is being changed.', 'reason.min' => 'Please say why the rate is being changed.'],
        )->validate();

        // The rate the quote came from must still be one this person can see (their company and branches).
        $rate = Rate::query()->find($original->rateId);
        if ($rate === null) {
            throw new AuthorizationException('That rate is not available to you.');
        }

        $new = bcadd($data['rate'], '0', Rate::SCALE);
        $old = bcadd($original->rate, '0', Rate::SCALE);

        if (bccomp($new, $old, Rate::SCALE) === 0) {
            throw ValidationException::withMessages(['rate' => 'That is the same as the rate on the master, so nothing is being overridden.']);
        }

        activity('rate')
            ->causedBy($by)
            ->performedOn($rate)
            ->event('overridden')
            ->withProperties([
                'old' => ['rate' => $old],
                'attributes' => ['rate' => $new],
                'reason' => $data['reason'],
                'as_of' => $original->asOf,
                'item_id' => $original->itemId,
                'location_id' => $original->locationId,
                'unit_id' => $original->unitId,
                'price_list_id' => $original->priceListId,
                'document' => $context === null ? null : ['type' => $context->getMorphClass(), 'id' => $context->getKey()],
            ])
            ->log('overridden');

        return $new;
    }

    // ---- internals --------------------------------------------------------------------------------------------

    /** The rate that qualifies in one slot on one day, by the tie-break above, or null. */
    private function eligible(int $itemId, int $locationId, int $unitId, ?int $priceListId, string $day): ?Rate
    {
        return Rate::query()
            ->usable()
            ->inSlot($itemId, $locationId, $unitId, $priceListId)
            ->effectiveOn($day)
            ->orderByDesc('effective_from')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Say *why* nothing qualified, from what exists in the slot. Never mentions an amount. */
    private function explain(Item $item, Location $branch, Unit $unit, ?int $priceListId, string $day): RateNotFoundException
    {
        $what = "{$item->name} per {$unit->name} at {$branch->name}".($priceListId !== null ? ' in that price list' : '');
        $on = Carbon::parse($day)->format('j M Y');

        $all = Rate::query()->inSlot($item->getKey(), $branch->getKey(), $unit->getKey(), $priceListId)->get(['approval_status', 'status', 'effective_from', 'effective_to']);
        $live = $all->filter(fn (Rate $r) => $r->isApproved() && $r->status === 'active');

        $next = $live->filter(fn (Rate $r) => $r->effective_from->toDateString() > $day)->min(fn (Rate $r) => $r->effective_from->toDateString());
        if ($next !== null) {
            return new RateNotFoundException(RateFailure::NotYetEffective, "There is no rate for {$what} on {$on}. The next approved rate starts on ".Carbon::parse($next)->format('j M Y').'.');
        }

        $ended = $live->filter(fn (Rate $r) => $r->effective_to !== null && $r->effective_to->toDateString() < $day)->max(fn (Rate $r) => $r->effective_to?->toDateString());
        if ($ended !== null) {
            return new RateNotFoundException(RateFailure::Expired, "There is no rate for {$what} on {$on}. The latest approved rate ended on ".Carbon::parse($ended)->format('j M Y').'.');
        }

        if ($all->contains(fn (Rate $r) => $r->isApproved() && $r->status !== 'active')) {
            return new RateNotFoundException(RateFailure::Inactive, "There is no rate for {$what} on {$on}: the rate that covers it is switched off.");
        }

        if ($all->contains(fn (Rate $r) => ! $r->isApproved())) {
            return new RateNotFoundException(RateFailure::NotApproved, "There is no approved rate for {$what} on {$on}. A rate has been entered but is not approved yet.");
        }

        return new RateNotFoundException(RateFailure::NoRate, "There is no rate for {$what} on {$on}. Ask Accounts to add one.");
    }

    private function day(CarbonInterface|string|null $date): string
    {
        if ($date === null) {
            return now()->toDateString();
        }

        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('The date must be written as YYYY-MM-DD.');
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw new InvalidArgumentException('The date is not a real calendar date.');
        }

        // createFromFormat rolls 2026-02-31 over to March: refuse it rather than look up the wrong day.
        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('The date is not a real calendar date.');
        }

        return $date;
    }
}
