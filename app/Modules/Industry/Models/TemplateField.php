<?php

namespace App\Modules\Industry\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra field a template adds to a loss incident, for one template version. Values are validated on the
 * server from this row (IndustryTemplateService::validateFields), never trusted from the browser.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string $type text | number | decimal | date | datetime | select | multiselect | boolean
 * @property string|null $unit
 * @property list<string>|null $options
 * @property array<string, mixed>|null $validation_rules
 * @property bool $is_required
 * @property int $sort_order
 * @property string|null $group
 */
#[Fillable(['industry_template_id', 'version', 'key', 'label', 'type', 'unit', 'options', 'validation_rules', 'is_required', 'sort_order', 'group'])]
class TemplateField extends Model
{
    protected $table = 'template_fields';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['options' => 'array', 'validation_rules' => 'array', 'is_required' => 'boolean'];
    }

    /** @return BelongsTo<IndustryTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(IndustryTemplate::class, 'industry_template_id');
    }
}
