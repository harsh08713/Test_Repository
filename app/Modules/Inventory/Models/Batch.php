<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Policies\BatchPolicy;
use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A batch / lot of an item that is tracked by batch: its number, when it was made and when it expires. Created when stock of it first arrives.
 *
 * @property int $item_id
 * @property string $batch_no
 * @property string|null $lot_no
 * @property Carbon|null $mfg_date
 * @property Carbon|null $expiry_date
 * @property string $status active|quarantine|expired
 * @property Carbon|null $expiry_alerted_at
 */
#[Fillable(['item_id', 'batch_no', 'lot_no', 'mfg_date', 'expiry_date', 'status'])]
#[UsePolicy(BatchPolicy::class)]
class Batch extends TenantModel
{
    use ValidatesParentCompany;

    protected function casts(): array
    {
        return ['mfg_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d', 'expiry_alerted_at' => 'datetime'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return HasMany<StockBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** Has it run out (by date or by status)? A batch like that may be scrapped but not issued or moved. */
    public function isExpired(): bool
    {
        return $this->status === 'expired' || ($this->expiry_date !== null && $this->expiry_date->toDateString() < now()->toDateString());
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    protected function companyScopedParents(): array
    {
        return ['item_id' => Item::class];
    }

    protected function auditLogName(): string
    {
        return 'batches';
    }
}
