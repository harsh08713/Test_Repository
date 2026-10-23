<?php

namespace App\Modules\Industry\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kind of loss an industry template tracks, for one template version.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $default_formula_key FORMULAS.md §4
 * @property string $default_severity
 */
#[Fillable(['industry_template_id', 'version', 'parent_id', 'code', 'name', 'default_formula_key', 'default_severity', 'is_active'])]
class LossCategory extends Model
{
    protected $table = 'loss_categories';

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
