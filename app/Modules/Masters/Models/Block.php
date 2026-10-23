<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\GeographyPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $district_id
 * @property string $name
 */
#[Fillable(['district_id', 'name', 'status', 'remarks'])]
#[UsePolicy(GeographyPolicy::class)]
class Block extends TenantModel
{
    use ValidatesParentCompany;

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    protected function companyScopedParents(): array
    {
        return ['district_id' => District::class];
    }
}
