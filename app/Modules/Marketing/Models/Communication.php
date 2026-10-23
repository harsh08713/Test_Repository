<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Policies\CommunicationPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A message the system sent, or refused to send, and why. `sent` means the mail server accepted it; nothing here claims it was delivered or read
 * (that needs a provider's report). The words are kept as they went out. Written only by CommunicationService; the words, the address and the
 * idempotency key never change.
 *
 * @property int $id
 * @property int|null $campaign_id
 * @property int|null $campaign_member_id
 * @property int|null $message_template_id
 * @property int|null $workflow_run_id
 * @property Channel $channel
 * @property string $purpose
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $to_name
 * @property string $to_address
 * @property string|null $subject
 * @property string $body
 * @property string $status queued | sending | sent | failed | suppressed | not_connected | cancelled
 * @property string|null $failure
 * @property int $attempts
 * @property string $idempotency_key
 * @property Carbon $queued_at
 * @property Carbon|null $sent_at
 * @property string|null $provider_ref the provider's own reference (WhatsApp, SMS)
 * @property Carbon|null $delivered_at what the provider reported later, if it did
 * @property Carbon|null $read_at
 */
#[UsePolicy(CommunicationPolicy::class)]
class Communication extends Model
{
    use BelongsToCompany;

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SUPPRESSED = 'suppressed';

    public const NOT_CONNECTED = 'not_connected';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['channel' => Channel::class, 'queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $c) {
            foreach (['company_id', 'channel', 'purpose', 'to_address', 'subject', 'body', 'idempotency_key', 'subject_type', 'subject_id', 'queued_at'] as $fixed) {
                if ($c->isDirty($fixed)) {
                    throw new LogicException('A message is a record: only how it went can change, not what it says or who it is for.');
                }
            }
        });

        static::deleting(fn () => throw new LogicException('Messages are kept as a record and cannot be deleted.'));
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What to print for the state of a message: honest about how far we know it got. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::QUEUED => 'Waiting to be sent',
            self::SENDING => 'Being sent',
            self::SENT => match (true) {
                $this->read_at !== null => 'Read',
                $this->delivered_at !== null => 'Delivered',
                $this->channel === Channel::Email => 'Handed to the mail server',
                default => 'Handed to '.$this->channel->label().' (not yet reported delivered)',
            },
            self::FAILED => 'Failed',
            self::SUPPRESSED => 'Not sent (consent or limit)',
            self::NOT_CONNECTED => 'Not sent (channel not connected)',
            default => 'Called off',
        };
    }
}
