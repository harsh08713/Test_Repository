<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\LedgerChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billing and/or shipping address of a ledger. Belongs to the ledger's approval: it is edited only while the
 * ledger itself is editable, and is covered by the ledger's approval fingerprint.
 *
 * @property int $ledger_id
 * @property bool $is_billing
 * @property bool $is_shipping
 * @property bool $is_default_billing
 * @property bool $is_default_shipping
 * @property string $line1
 * @property string|null $line2
 * @property string|null $city
 * @property int $state_id
 * @property int|null $district_id
 * @property string $pin_code
 * @property string|null $gstin
 */
#[Fillable([
    'label', 'is_billing', 'is_shipping', 'is_default_billing', 'is_default_shipping', 'line1', 'line2', 'city',
    'state_id', 'district_id', 'pin_code', 'gstin', 'phone', 'status', 'remarks',
])]
#[UsePolicy(LedgerChildPolicy::class)]
class LedgerAddress extends TenantModel
{
    use ValidatesParentCompany;

    protected function casts(): array
    {
        return [
            'is_billing' => 'boolean',
            'is_shipping' => 'boolean',
            'is_default_billing' => 'boolean',
            'is_default_shipping' => 'boolean',
        ];
    }

    /** @return BelongsTo<Ledger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

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

    protected function companyScopedParents(): array
    {
        return ['ledger_id' => Ledger::class, 'state_id' => State::class, 'district_id' => District::class];
    }

    protected function auditLogName(): string
    {
        return 'ledger_addresses';
    }
}
