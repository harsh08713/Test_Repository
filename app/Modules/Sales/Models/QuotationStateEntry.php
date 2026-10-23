<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One move of a quotation revision between states — the first entry is its making. Append-only; written only by QuotationService.
 *
 * @property int $id
 * @property int $quotation_id
 * @property QuotationState|null $from_state
 * @property QuotationState $to_state
 * @property string|null $comment
 * @property int|null $actor_id
 * @property Carbon $created_at
 */
class QuotationStateEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'quotation_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['from_state' => QuotationState::class, 'to_state' => QuotationState::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quotation history is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Quotation history is append-only and cannot be deleted.'));
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
