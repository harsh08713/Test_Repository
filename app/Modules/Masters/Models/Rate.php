<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\RatePolicy;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What one unit of an item costs at one branch for a period, optionally in a price list.
 *
 * A rate goes through the approval workflow and only an **approved, active** rate inside its period is ever
 * returned to a transaction (see RateService). Periods are inclusive at both ends and an empty `effective_to` means
 * "until further notice". Two pending or approved rates for the same item, branch, unit and price list may not
 * overlap — checked on save and again, under a lock on the item, when the rate is submitted — so on any date there
 * is at most one rate to find. Rates are exclusive of GST (the item carries the GST rate).
 *
 * @property int $item_id
 * @property int $location_id
 * @property int $unit_id
 * @property int|null $price_list_id
 * @property string $rate
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
#[Fillable(['rate', 'effective_from', 'effective_to', 'status', 'remarks'])]
#[UsePolicy(RatePolicy::class)]
class Rate extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    /** Decimal places of a stored rate. */
    public const SCALE = 4;

    /** Beyond what any real price is: keeps the value inside the column and out of overflow territory. */
    public const MAX = '99999999999.9999';

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:'.self::SCALE,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Rate;
    }

    public function approvalUrl(): ?string
    {
        return route('masters.rates.show', $this);
    }

    public function approvalTitle(): string
    {
        $this->loadMissing(['item:id,name', 'location:id,name', 'unit:id,name']);

        return trim("Rate — {$this->item?->name} · {$this->location?->name} · per {$this->unit?->name}");
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    protected function companyScopedParents(): array
    {
        return ['item_id' => Item::class, 'unit_id' => Unit::class, 'price_list_id' => PriceList::class];
    }

    /**
     * Rates whose period contains $date (both ends inclusive).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where($this->qualifyColumn('effective_from'), '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('effective_to'))->orWhere($this->qualifyColumn('effective_to'), '>=', $date));
    }

    /**
     * The rates of one slot: the same item, branch, unit and price list (null = the standard list).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInSlot(Builder $query, int $itemId, int $locationId, int $unitId, ?int $priceListId): Builder
    {
        return $query->where($this->qualifyColumn('item_id'), $itemId)
            ->where($this->qualifyColumn('location_id'), $locationId)
            ->where($this->qualifyColumn('unit_id'), $unitId)
            ->when(
                $priceListId === null,
                fn (Builder $q) => $q->whereNull($this->qualifyColumn('price_list_id')),
                fn (Builder $q) => $q->where($this->qualifyColumn('price_list_id'), $priceListId),
            );
    }

    /** True for an approved, active rate: the ones a transaction may be offered (when the date also fits). */
    public function isUsable(): bool
    {
        return $this->isApproved() && $this->status === 'active';
    }

    /** upcoming | current | expired — where $date (default today) falls against this rate's period. */
    public function periodState(?string $date = null): string
    {
        $date ??= now()->toDateString();

        return match (true) {
            $this->effective_from->toDateString() > $date => 'upcoming',
            $this->effective_to !== null && $this->effective_to->toDateString() < $date => 'expired',
            default => 'current',
        };
    }

    /** The rate for people: at least two decimals, up to four if the price needs them ("1,250.00", "0.0750"). */
    public function rateLabel(): string
    {
        [$whole, $decimals] = explode('.', rtrim(rtrim(bcadd((string) $this->rate, '0', self::SCALE), '0'), '.').'.');

        return number_format((float) $whole).'.'.str_pad($decimals, 2, '0');
    }

    /** "1 Oct 2026 – 31 Mar 2027" or "from 1 Oct 2026". */
    public function periodLabel(): string
    {
        $from = $this->effective_from->format('j M Y');

        return $this->effective_to === null ? "from {$from}" : "{$from} – {$this->effective_to->format('j M Y')}";
    }

    /**
     * The other pending or approved rates for the same item, branch, unit and price list whose period overlaps this
     * one (whether they are active or not: switching one back on must not create an overlap). Drafts and rejected
     * rates are not live, so they never conflict.
     *
     * @return Collection<int, static>
     */
    public function conflicting(): Collection
    {
        if ($this->getAttribute('effective_from') === null) {
            return new Collection;   // nothing to compare yet
        }

        return static::conflictsFor(
            $this->item_id, $this->location_id, $this->unit_id, $this->price_list_id,
            $this->effective_from->toDateString(), $this->effective_to?->toDateString(), $this->exists ? (int) $this->getKey() : null,
        );
    }

    /**
     * The same question asked before a rate exists (the form validates with it).
     *
     * @return Collection<int, static>
     */
    public static function conflictsFor(int $itemId, int $locationId, int $unitId, ?int $priceListId, string $from, ?string $to, ?int $ignoreId = null): Collection
    {
        return static::query()
            ->inSlot($itemId, $locationId, $unitId, $priceListId)
            ->whereIn('approval_status', ['pending', 'approved'])
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->when($to !== null, fn (Builder $q) => $q->where('effective_from', '<=', $to))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * What stands between this rate and an approver. It must be about something that can actually be sold: an
     * approved, active item, in a unit the item has, at an active branch, in an active price list, in a period that
     * does not collide with another live rate.
     *
     * @return list<string>
     */
    public function missingForApproval(): array
    {
        $missing = [];

        $item = Item::withoutGlobalScopes(Tenancy::SCOPES)->with('uoms')->find($this->item_id);
        if ($item === null || (int) $item->company_id !== (int) $this->company_id || ! $item->isUsable()) {
            $missing[] = 'an item that is approved and active';
        } elseif ((int) $this->unit_id !== (int) $item->base_unit_id && $item->uoms->doesntContain(fn ($row) => (int) $row->unit_id === (int) $this->unit_id)) {
            $missing[] = 'a unit the item has (its base unit or one of its conversions)';
        }

        $location = $this->location()->withoutGlobalScopes(Tenancy::SCOPES)->first();
        if ($location === null || $location->status !== 'active') {
            $missing[] = 'an active branch';
        }

        if ($this->unit()->withoutGlobalScopes(Tenancy::SCOPES)->value('status') !== 'active') {
            $missing[] = 'an active unit';
        }

        if ($this->price_list_id !== null && $this->priceList()->withoutGlobalScopes(Tenancy::SCOPES)->value('status') !== 'active') {
            $missing[] = 'an active price list';
        }

        foreach ($this->conflicting() as $other) {
            $missing[] = 'a period that does not overlap another rate ('.$other->periodLabel().', '.strtolower($other->approval_status->label()).') — end-date that one first, or change these dates';
        }

        return $missing;
    }

    /** Check under a lock on the item, so two rates for the same item cannot both slip past the overlap check. */
    public function beforeSubmitForApproval(): void
    {
        Item::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($this->item_id)->lockForUpdate()->first();

        $missing = $this->missingForApproval();

        if ($missing !== []) {
            throw ApprovalStateException::notReady('This rate cannot be submitted yet. It needs: '.implode('; ', $missing).'.');
        }
    }

    /**
     * What an approver signs off on: the price, who it is for and when. Whether the rate is switched on, and its
     * remarks, are not part of it. Normalised so the same rate always hashes the same, however it was loaded.
     *
     * @return array<string, mixed>
     */
    protected function approvalFingerprintParts(): array
    {
        $own = Arr::except($this->getAttributes(), [
            'id', 'approval_status', 'approved_by', 'approved_at', 'status', 'remarks',
            'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at',
        ]);

        $own['rate'] = bcadd((string) $own['rate'], '0', self::SCALE);
        $own['effective_from'] = substr((string) $own['effective_from'], 0, 10);
        $own['effective_to'] = isset($own['effective_to']) ? substr((string) $own['effective_to'], 0, 10) : null;

        return $own;
    }
}
