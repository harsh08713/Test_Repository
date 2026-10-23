<?php

namespace App\Modules\CRM\Models;

use App\Modules\CRM\Enums\StopType;
use App\Modules\CRM\Policies\RoutePlanChildPolicy;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stop on a route plan: an area, a block, a PIN code or a customer, in a planned order, optionally with a visit
 * window and a place on the map. A stop with coordinates is a geofence (centre + radius); one without is a reminder.
 *
 * @property int $route_plan_id
 * @property int $sequence
 * @property StopType $stop_type
 * @property string $label
 * @property int|null $block_id
 * @property string|null $pin_code
 * @property int|null $ledger_id
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $radius_m
 * @property string|null $visit_from
 * @property string|null $visit_to
 * @property string $visit_status
 */
#[Fillable(['sequence', 'stop_type', 'label', 'pin_code', 'latitude', 'longitude', 'radius_m', 'visit_from', 'visit_to', 'visit_status', 'remarks'])]
#[UsePolicy(RoutePlanChildPolicy::class)]
class RouteStop extends TenantModel
{
    use ValidatesParentCompany;

    protected $table = 'route_plan_stops';

    /** A stop has no active/inactive status (it is removed from the plan, and has a visit status of its own). */
    protected $attributes = [];

    protected function casts(): array
    {
        return [
            'stop_type' => StopType::class,
            'sequence' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_m' => 'integer',
        ];
    }

    /** @return BelongsTo<RoutePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(RoutePlan::class, 'route_plan_id');
    }

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    /** @return BelongsTo<Ledger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    /** Does the stop have a place on the map (so that it can act as a geofence)? */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    protected function companyScopedParents(): array
    {
        return ['route_plan_id' => RoutePlan::class, 'block_id' => Block::class, 'ledger_id' => Ledger::class];
    }

    protected function auditLogName(): string
    {
        return 'route_plan_stops';
    }
}
