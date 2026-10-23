<?php

namespace App\Modules\Industry\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One company override of a template (today `kpi_thresholds`: {kpi code: {green, amber, red}}).
 *
 * @property array<string, mixed>|null $value
 */
#[Fillable(['industry_template_id', 'key', 'value', 'updated_by'])]
class IndustrySetting extends Model
{
    use BelongsToCompany;

    protected $table = 'industry_settings';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
