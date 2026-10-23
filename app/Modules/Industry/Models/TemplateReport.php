<?php

namespace App\Modules\Industry\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report an industry template recommends, for one template version.
 *
 * @property string $report_key
 * @property string $name
 */
#[Fillable(['industry_template_id', 'version', 'report_key', 'name', 'config'])]
class TemplateReport extends Model
{
    protected $table = 'template_reports';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    /** @return BelongsTo<IndustryTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(IndustryTemplate::class, 'industry_template_id');
    }
}
