<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One move of a deal between stages — the first entry is its conversion from the lead: from where to where, who (or the
 * system, once quotations and orders exist), when and why. Append-only; written only by DealService.
 *
 * @property int $id
 * @property int $deal_id
 * @property DealStage|null $from_stage
 * @property DealStage $to_stage
 * @property int|null $lost_reason_id
 * @property string|null $comment
 * @property int|null $actor_id
 * @property Carbon $created_at
 */
class DealStageEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'deal_stage_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_stage' => DealStage::class,
            'to_stage' => DealStage::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Deal stage history is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Deal stage history is append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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
            $this->from_stage === null => 'Deal created from the lead',
            $this->to_stage === DealStage::Lost => 'Marked as lost',
            $this->from_stage === DealStage::Lost => 'Re-opened as '.$this->to_stage->label(),
            default => $this->from_stage->label().' → '.$this->to_stage->label(),
        };
    }
}
