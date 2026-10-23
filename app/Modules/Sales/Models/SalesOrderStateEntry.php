<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One move of a sales order between states — the first entry is its making. Append-only; written only by SalesOrderService and the invoice
 * generator.
 *
 * @property int $id
 * @property int $sales_order_id
 * @property SalesOrderState|null $from_state
 * @property SalesOrderState $to_state
 * @property string|null $comment
 * @property int|null $actor_id
 * @property Carbon $created_at
 */
class SalesOrderStateEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_order_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['from_state' => SalesOrderState::class, 'to_state' => SalesOrderState::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sales order history is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Sales order history is append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function label(): string
    {
        return $this->from_state === null ? 'Made' : $this->from_state->label().' → '.$this->to_state->label();
    }
}
