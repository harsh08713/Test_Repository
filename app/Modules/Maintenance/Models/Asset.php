<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Finance\Models\CostCenter;
use App\Modules\Maintenance\Policies\AssetPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something that is maintained. Never deleted (its history stays): it is made inactive instead.
 *
 * @property string $code
 * @property int $location_id
 * @property string $name
 * @property string $category machine|vehicle|instrument|building|other
 * @property string|null $make_model
 * @property string|null $serial_no
 * @property Carbon|null $installed_on
 * @property string $criticality low|medium|high
 * @property int|null $cost_center_id
 * @property string $status active|inactive
 */
#[Fillable(['code', 'name', 'category', 'make_model', 'serial_no', 'installed_on', 'criticality', 'cost_center_id', 'status', 'remarks'])]
#[UsePolicy(AssetPolicy::class)]
class Asset extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    public const CATEGORIES = ['machine' => 'Machine', 'vehicle' => 'Vehicle', 'instrument' => 'Instrument', 'building' => 'Building or utility', 'other' => 'Other'];

    public const CRITICALITY = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High: stops production'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('An asset is never deleted: make it inactive.'));
    }

    protected function casts(): array
    {
        return ['installed_on' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return HasMany<MaintenancePlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class)->orderBy('next_due_on');
    }

    /** @return HasMany<MaintenanceOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(MaintenanceOrder::class)->orderByDesc('id');
    }

    protected function companyScopedParents(): array
    {
        return ['cost_center_id' => CostCenter::class];
    }
}
