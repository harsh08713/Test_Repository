<?php

namespace App\Modules\CRM\Models;

use App\Modules\CRM\Enums\CoverageKind;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of what a territory covers: a state, a district, a block, a PIN code or a customer. A unit of the map belongs to one territory only (the
 * database's unique key on `ref`). Written and removed only by TerritoryService, which also writes the change to the territory's audit trail — the row
 * itself is removed for real, since a soft-deleted row would still hold the key.
 *
 * @property int $territory_id
 * @property CoverageKind $kind
 * @property int|null $state_id
 * @property int|null $district_id
 * @property int|null $block_id
 * @property string|null $pin_code
 * @property int|null $ledger_id
 * @property string $ref
 */
class TerritoryArea extends TenantModel
{
    /** A piece of coverage has no active/inactive status: it is there or it is not. */
    protected $attributes = [];

    protected function casts(): array
    {
        return ['kind' => CoverageKind::class];
    }

    /** @return BelongsTo<Territory, $this> */
    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    /** @return BelongsTo<Ledger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    /** What this piece of coverage is called on a screen: the state, district or block name, the PIN code, or the customer (with its code). */
    public function label(): string
    {
        return match ($this->kind) {
            CoverageKind::State => (string) $this->state?->name,
            CoverageKind::District => (string) $this->district?->name,
            CoverageKind::Block => (string) $this->block?->name,
            CoverageKind::PinCode => (string) $this->pin_code,
            CoverageKind::Customer => trim($this->ledger?->name.' '.($this->ledger === null ? '' : "({$this->ledger->code})")),
        };
    }

    protected function auditLogName(): string
    {
        return 'territories';
    }
}
