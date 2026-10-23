<?php

namespace App\Modules\Marketing\Models;

use App\Modules\CRM\Models\Lead;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person (a lead or a customer) on a campaign, chosen when it was launched. The name and the contact details are copied at that moment: the
 * campaign writes to what it was approved to write to. Written only by CampaignService and CampaignRunner.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string $subject_type lead | ledger
 * @property int $subject_id
 * @property string $name
 * @property string|null $email
 * @property string|null $mobile
 * @property string $status active | completed | exited | suppressed
 * @property string|null $exit_reason
 * @property int $next_step
 * @property Carbon|null $next_due_at
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $responded_at
 * @property int|null $lead_id
 */
class CampaignMember extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['next_due_at' => 'datetime', 'last_sent_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
