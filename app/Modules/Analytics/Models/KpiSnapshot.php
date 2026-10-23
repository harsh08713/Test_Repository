<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The value of an industry KPI on a day, kept by `analytics:snapshots`. `location_key` 0 = the whole company; a snapshot taken again for the same day replaces
 * the first (idempotent). Because it is company-wide it is shown only to people who work in every branch of the company.
 *
 * @property int $company_id
 * @property int $location_key
 * @property int $industry_template_id
 * @property int $kpi_definition_id
 * @property int $kpi_version
 * @property string $period_type
 * @property Carbon $period_start
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property Carbon $source_freshness_at
 * @property Carbon $computed_at
 */
#[Fillable([])]
class KpiSnapshot extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'kpi_snapshots';

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'value' => 'decimal:4', 'numerator' => 'decimal:4', 'denominator' => 'decimal:4', 'source_freshness_at' => 'datetime', 'computed_at' => 'datetime'];
    }
}
