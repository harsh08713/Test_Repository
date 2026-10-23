<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One message of a campaign's sequence: which template, and how many hours after the previous step (or, for the first, after the member joined).
 * The steps are part of what the approver signed, so they cannot change once the campaign has left draft.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $position
 * @property int $delay_hours
 * @property int $message_template_id
 */
class CampaignStep extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        $guard = function (self $step): void {
            $status = DB::table('campaigns')->where('id', $step->campaign_id)->value('approval_status');

            if (! in_array($status, ['draft', 'rejected'], true)) {
                throw new LogicException('The steps of a campaign that has been sent for approval do not change.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<MessageTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
