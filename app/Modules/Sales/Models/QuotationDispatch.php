<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One time a quotation revision was sent to the customer: by e-mail through the mail transport the company has configured, or
 * recorded as handed over some other way. A failed attempt is kept, with a message that never carries a secret. Append-only.
 *
 * @property int $id
 * @property int $quotation_id
 * @property string $channel email|manual
 * @property string|null $to_email
 * @property string|null $cc_email
 * @property string|null $subject
 * @property string $status sent|failed
 * @property string|null $error
 * @property string|null $pdf_sha256
 * @property int|null $actor_id
 * @property Carbon $created_at
 */
class QuotationDispatch extends Model
{
    use BelongsToCompany;

    protected $table = 'quotation_dispatches';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The dispatch log is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('The dispatch log is append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
