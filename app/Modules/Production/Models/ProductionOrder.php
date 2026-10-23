<?php

namespace App\Modules\Production\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Production\Policies\ProductionOrderPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Make `planned_quantity` of an item at one branch from one bill of materials. Its `state` says where it stands; what has been issued and produced is **worked out** from the entries
 * (ProductionFigures), never stored on the order. Changed only through ProductionOrderService.
 *
 * @property string $order_number
 * @property int $location_id
 * @property int $bom_id
 * @property int $item_id
 * @property string $item_name
 * @property string $planned_quantity
 * @property Carbon $planned_start
 * @property Carbon $planned_end
 * @property string $state draft|released|in_progress|completed|cancelled
 * @property Carbon|null $released_at
 * @property Carbon|null $completed_at
 * @property string|null $completion_note
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
#[UsePolicy(ProductionOrderPolicy::class)]
class ProductionOrder extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    public const STATES = ['draft' => 'Draft', 'released' => 'Released', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    protected $attributes = ['state' => 'draft'];

    protected static function booted(): void
    {
        static::updating(function (self $order) {
            if (in_array((string) $order->getRawOriginal('state'), ['completed', 'cancelled'], true)) {
                throw new LogicException('A completed or cancelled production order does not change.');
            }
        });

        static::deleting(fn () => throw new LogicException('A production order is never deleted: cancel it instead.'));
    }

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:3', 'planned_start' => 'date:Y-m-d', 'planned_end' => 'date:Y-m-d',
            'released_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bom, $this> */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return HasMany<ProductionComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(ProductionComponent::class)->orderBy('line_no');
    }

    /** @return HasMany<ProductionIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ProductionIssue::class)->orderBy('id');
    }

    /** @return HasMany<ProductionOutput, $this> */
    public function outputs(): HasMany
    {
        return $this->hasMany(ProductionOutput::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->state, ['released', 'in_progress'], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->state, ['completed', 'cancelled'], true);
    }

    protected function companyScopedParents(): array
    {
        return ['bom_id' => Bom::class, 'item_id' => Item::class];
    }
}
