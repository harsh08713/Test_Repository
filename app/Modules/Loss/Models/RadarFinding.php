<?php

namespace App\Modules\Loss\Models;

use App\Models\User;
use App\Modules\Loss\Policies\RadarFindingPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something the Profit Leakage Radar noticed that looks like a loss — a proposal, never a loss figure. It is made and refreshed only by
 * `RadarService::sweep()`; a person turns it into a loss incident (the incident carries the same source) or dismisses it with a reason.
 *
 * @property int $id
 * @property int $company_id
 * @property int $location_id
 * @property string $detector
 * @property string $source_type
 * @property int $source_id
 * @property string $title
 * @property string $severity low|medium|high
 * @property string $impact_type actual|exposure
 * @property string $estimated_amount
 * @property array<string, mixed> $evidence
 * @property string $state open|reported|dismissed|resolved
 * @property Carbon $first_detected_at
 * @property Carbon $last_seen_at
 * @property int|null $loss_incident_id
 * @property int|null $dismissed_by
 * @property Carbon|null $dismissed_at
 * @property string|null $dismiss_reason
 */
#[UsePolicy(RadarFindingPolicy::class)]
class RadarFinding extends Model
{
    use BelongsToCompany, BelongsToLocation;

    public const STATES = ['open' => 'Open', 'reported' => 'Reported as an incident', 'dismissed' => 'Dismissed', 'resolved' => 'No longer applies'];

    protected $attributes = ['state' => 'open'];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2', 'evidence' => 'array', 'first_detected_at' => 'datetime', 'last_seen_at' => 'datetime', 'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LossIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(LossIncident::class, 'loss_incident_id');
    }

    /** @return BelongsTo<User, $this> */
    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    /** The loss-report form with this finding's source filled in (a person still checks and saves it). */
    public function reportUrl(): string
    {
        return route('loss.incidents.create', ['source' => $this->source_type, 'id' => $this->source_id]);
    }
}
