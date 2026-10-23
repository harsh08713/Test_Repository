<?php

namespace App\Modules\Loss\Models;

use App\Models\User;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Loss\Policies\SavingsMeasurementPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A measurement of what a corrective action really saved (see the migration and docs/savings.md). Written only by `SavingsService`.
 * It is evidence: once submitted its figures never change (the model refuses), a verified one changes only to say it recurred, and nothing is deleted.
 *
 * @property int $id
 * @property int $company_id
 * @property int $location_id
 * @property int $corrective_action_id
 * @property int $loss_category_id
 * @property int|null $item_id
 * @property Carbon $baseline_from
 * @property Carbon $baseline_to
 * @property Carbon $measure_from
 * @property Carbon $measure_to
 * @property string $baseline_loss
 * @property string $measure_loss
 * @property string|null $volume_unit
 * @property string|null $baseline_volume
 * @property string|null $measure_volume
 * @property string $method volume|days
 * @property string $normalised_baseline
 * @property string $gross_saving
 * @property string $implementation_cost
 * @property string $net_saving
 * @property string|null $evidence
 * @property string $state draft|submitted|verified|rejected
 * @property int $created_by
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_notes
 * @property Carbon|null $recurred_at
 * @property int|null $recurrence_incident_id
 */
#[UsePolicy(SavingsMeasurementPolicy::class)]
class SavingsMeasurement extends Model
{
    use BelongsToCompany, BelongsToLocation;

    public const STATES = ['draft' => 'Draft', 'submitted' => 'Waiting for Finance', 'verified' => 'Finance-verified', 'rejected' => 'Rejected'];

    /** What a submitted or decided measurement may not change. */
    private const EVIDENCE = [
        'company_id', 'location_id', 'corrective_action_id', 'loss_category_id', 'item_id', 'baseline_from', 'baseline_to', 'measure_from', 'measure_to', 'baseline_loss', 'measure_loss',
        'volume_unit', 'baseline_volume', 'measure_volume', 'method', 'normalised_baseline', 'gross_saving', 'implementation_cost', 'net_saving', 'evidence', 'created_by',
    ];

    protected $attributes = ['state' => 'draft', 'implementation_cost' => '0.00'];

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            $was = (string) $m->getRawOriginal('state');

            if ($was === 'draft') {
                return;
            }

            if ($m->isDirty(self::EVIDENCE)) {
                throw new LogicException('A measurement that has been submitted cannot be changed.');
            }

            $allowed = ($was === 'submitted' && in_array($m->state, ['verified', 'rejected'], true)) || ($was === 'rejected' && $m->state === 'draft');

            if ($m->isDirty('state') && ! $allowed) {
                throw new LogicException('A decided measurement cannot change its state.');
            }
        });

        static::deleting(function () {
            throw new LogicException('A savings measurement is never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'baseline_from' => 'date:Y-m-d', 'baseline_to' => 'date:Y-m-d', 'measure_from' => 'date:Y-m-d', 'measure_to' => 'date:Y-m-d',
            'baseline_loss' => 'decimal:2', 'measure_loss' => 'decimal:2', 'baseline_volume' => 'decimal:3', 'measure_volume' => 'decimal:3', 'normalised_baseline' => 'decimal:2',
            'gross_saving' => 'decimal:2', 'implementation_cost' => 'decimal:2', 'net_saving' => 'decimal:2',
            'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'recurred_at' => 'datetime',
        ];
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    /** @return BelongsTo<CorrectiveAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(CorrectiveAction::class, 'corrective_action_id');
    }

    /** @return BelongsTo<LossCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LossCategory::class, 'loss_category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<LossIncident, $this> */
    public function recurrenceIncident(): BelongsTo
    {
        return $this->belongsTo(LossIncident::class, 'recurrence_incident_id');
    }
}
