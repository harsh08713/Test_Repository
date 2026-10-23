<?php

namespace App\Modules\Industry\Models;

use App\Modules\Industry\Policies\IndustryTemplatePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A system-wide industry template (Automotive, Pharma…). Shared by every company; a company only enables it
 * and keeps its own overrides in industry_settings. Child rows carry the version they belong to.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $version
 * @property string|null $description
 * @property string|null $icon
 * @property string $status
 */
#[Fillable(['code', 'name', 'version', 'description', 'icon', 'status', 'is_system'])]
#[UsePolicy(IndustryTemplatePolicy::class)]
class IndustryTemplate extends Model
{
    protected $attributes = ['status' => 'active', 'version' => 1, 'is_system' => true];

    /** @return HasMany<KpiDefinition, $this> */
    public function kpis(): HasMany
    {
        return $this->hasMany(KpiDefinition::class);
    }

    /** @return HasMany<LossCategory, $this> */
    public function lossCategories(): HasMany
    {
        return $this->hasMany(LossCategory::class);
    }

    /** @return HasMany<TemplateField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class);
    }

    /** @return HasMany<TemplateReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(TemplateReport::class);
    }
}
