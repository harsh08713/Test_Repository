<?php

namespace App\Modules\Loss\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What an investigation found: by **five whys** (a chain of whys ending in the root cause) or by **fishbone** (a cause category — man, machine,
 * method, material, measurement, environment — and contributing factors). Evidence is attached to the incident.
 *
 * @property int $loss_incident_id
 * @property string $method five_whys|fishbone
 * @property string|null $cause_category
 * @property string $root_cause
 * @property string|null $findings
 * @property list<string>|null $whys
 * @property list<string>|null $contributing_factors
 * @property int $analysed_by
 * @property Carbon $analysed_at
 */
#[Fillable([])]
class RootCauseAnalysis extends Model
{
    use BelongsToCompany;

    public const METHODS = ['five_whys' => '5 Whys', 'fishbone' => 'Fishbone (cause categories)'];

    public const CATEGORIES = [
        'man' => 'People', 'machine' => 'Machine', 'method' => 'Method', 'material' => 'Material', 'measurement' => 'Measurement', 'environment' => 'Environment', 'other' => 'Other',
    ];

    protected $table = 'root_cause_analyses';

    protected function casts(): array
    {
        return ['whys' => 'array', 'contributing_factors' => 'array', 'analysed_at' => 'datetime'];
    }

    /** @return BelongsTo<LossIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(LossIncident::class, 'loss_incident_id');
    }

    /** @return BelongsTo<User, $this> */
    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analysed_by');
    }
}
