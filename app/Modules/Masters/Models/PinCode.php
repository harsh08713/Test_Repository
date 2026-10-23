<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\GeographyPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One locality of a PIN code. A PIN may appear on several rows (different post offices, blocks).
 *
 * @property string $pin_code
 * @property int $state_id
 * @property int $district_id
 * @property int|null $block_id
 * @property string $area '' means the PIN as a whole
 */
#[Fillable(['pin_code', 'state_id', 'district_id', 'block_id', 'area', 'status', 'remarks'])]
#[UsePolicy(GeographyPolicy::class)]
class PinCode extends TenantModel
{
    use ValidatesParentCompany;

    protected $attributes = [
        'status' => 'active',
        'area' => '',
    ];

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    protected function companyScopedParents(): array
    {
        return ['state_id' => State::class, 'district_id' => District::class, 'block_id' => Block::class];
    }
}
