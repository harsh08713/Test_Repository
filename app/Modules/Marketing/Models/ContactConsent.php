<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Policies\ConsentPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One thing a person said about being contacted on one channel: opted in, or opted out, where it was recorded and by whom. The newest event for an
 * address and channel is the answer; the older ones are kept. Append-only; written only by ConsentService.
 *
 * @property int $id
 * @property Channel $channel
 * @property string $address
 * @property string $status opted_in | opted_out
 * @property string $source manual | form | import | unsubscribe | reply | provider
 * @property string|null $note
 * @property int|null $recorded_by
 * @property Carbon $created_at
 */
#[UsePolicy(ConsentPolicy::class)]
class ContactConsent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['channel' => Channel::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent history is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Consent history is append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
