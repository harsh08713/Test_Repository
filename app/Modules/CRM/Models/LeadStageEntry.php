<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One move of a lead between stages: from where to where, who (or the system, after a follow-up), when and why.
 * Append-only; written only by LeadService.
 *
 * @property int $id
 * @property int $lead_id
 * @property LeadStage|null $from_stage
 * @property LeadStage $to_stage
 * @property int|null $lost_reason_id
 * @property string|null $comment
 * @property int|null $actor_id
 * @property Carbon $created_at
 */
class LeadStageEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'lead_stage_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_stage' => LeadStage::class,
            'to_stage' => LeadStage::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lead stage history is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Lead stage history is append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<LostReason, $this> */
    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(LostReason::class);
    }

    public function label(): string
    {
        return match (true) {
            $this->from_stage === null => 'Lead created',
            $this->to_stage === LeadStage::Lost => 'Marked as lost',
            $this->from_stage === LeadStage::Lost => 'Re-opened as '.$this->to_stage->label(),
            default => $this->from_stage->label().' → '.$this->to_stage->label(),
        };
    }
}
