<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One change of hands of a lead: from whom, to whom, who did it, when and why. Append-only, written only by
 * LeadTransferService — the lead's complete transfer history is these rows in order.
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $from_user_id
 * @property int $to_user_id
 * @property int|null $from_team_id
 * @property int|null $to_team_id
 * @property string $reason
 * @property int $transferred_by
 * @property Carbon $created_at
 */
class LeadTransfer extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lead transfer history is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Lead transfer history is append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
