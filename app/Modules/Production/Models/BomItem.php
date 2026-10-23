<?php

namespace App\Modules\Production\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One component line of a bill of materials. The lines of a bill that is waiting for approval or approved never change.
 *
 * @property int $bom_id
 * @property int $line_no
 * @property int $item_id
 * @property string $quantity
 * @property string $scrap_percent
 */
#[Fillable(['quantity', 'scrap_percent', 'remarks'])]
class BomItem extends TenantModel
{
    protected $attributes = [];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $status = (string) DB::table('boms')->where('id', $line->bom_id)->value('approval_status');

            if (in_array($status, ['pending', 'approved'], true)) {
                throw new LogicException('The components of a bill that is waiting for approval or approved cannot be changed.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'scrap_percent' => 'decimal:2'];
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
}
