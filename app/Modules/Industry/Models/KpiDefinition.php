<?php

namespace App\Modules\Industry\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A KPI of an industry template for one template version (FORMULAS.md §5): value = numerator ÷ denominator × multiplier.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $formula_key
 * @property string $unit
 * @property string $direction higher_better | lower_better
 * @property numeric-string|null $threshold_green
 * @property numeric-string|null $threshold_amber
 * @property numeric-string|null $threshold_red
 */
#[Fillable(['industry_template_id', 'version', 'code', 'name', 'formula_key', 'numerator_desc', 'denominator_desc', 'unit', 'multiplier', 'direction', 'threshold_green', 'threshold_amber', 'threshold_red', 'is_active'])]
class KpiDefinition extends Model
{
    protected $table = 'kpi_definitions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<IndustryTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(IndustryTemplate::class, 'industry_template_id');
    }
}
