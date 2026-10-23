<?php

namespace App\Modules\Loss\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One figure of an incident's money, of one kind: **actual** loss, estimated **exposure** or recoverable **opportunity** — never added together.
 * Worked out by a named formula (FORMULAS.md §4) whose key, version and inputs are kept with it. Its amount never changes: a recalculation marks
 * it superseded and adds a new line; only its validation and supersession move. It is never deleted.
 *
 * @property int $id
 * @property int $company_id
 * @property int $loss_incident_id
 * @property string $impact_type actual|exposure|opportunity
 * @property string $formula_key
 * @property int $formula_version
 * @property array<string, string> $inputs
 * @property string|null $quantity
 * @property string|null $unit_cost
 * @property string $currency
 * @property string $amount
 * @property int|null $confidence_pct
 * @property string|null $target_period
 * @property string|null $assumptions
 * @property string $validation_state pending|validated|rejected
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property Carbon|null $superseded_at
 * @property int|null $superseded_by_id
 * @property int|null $created_by
 */
#[Fillable([])]
class LossImpactLine extends Model
{
    use BelongsToCompany;

    public const TYPES = ['actual' => 'Actual loss', 'exposure' => 'Estimated exposure', 'opportunity' => 'Recoverable opportunity'];

    /** What may still be written once a line exists. */
    private const MOVING_COLUMNS = ['validation_state', 'validated_by', 'validated_at', 'superseded_at', 'superseded_by_id', 'updated_at'];

    protected $attributes = ['currency' => 'INR', 'validation_state' => 'pending'];

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            if (array_diff(array_keys($line->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new LogicException('An impact line’s figures never change: recalculate it, which supersedes it with a new line.');
            }
        });

        static::deleting(fn () => throw new LogicException('An impact line is never deleted: supersede or reject it.'));
    }

    protected function casts(): array
    {
        return [
            'inputs' => 'array', 'quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'amount' => 'decimal:2', 'confidence_pct' => 'integer',
            'validated_at' => 'datetime', 'superseded_at' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->impact_type] ?? $this->impact_type;
    }

    /** @return BelongsTo<LossIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(LossIncident::class, 'loss_incident_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
