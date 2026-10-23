<?php

namespace App\Modules\Industry\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This company uses this template, on this version." company_id is never mass-assignable.
 *
 * @property int $company_id
 * @property int $industry_template_id
 * @property int $version_pinned
 */
#[Fillable(['industry_template_id', 'version_pinned', 'enabled_by', 'enabled_at'])]
class CompanyIndustryTemplate extends Model
{
    use BelongsToCompany;

    protected $table = 'company_industry_templates';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled_at' => 'datetime'];
    }

    /** @return BelongsTo<IndustryTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(IndustryTemplate::class, 'industry_template_id');
    }
}
