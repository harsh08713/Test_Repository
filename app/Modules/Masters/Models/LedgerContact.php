<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\LedgerChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person to deal with at a customer or supplier. Covered by the ledger's approval.
 *
 * @property int $ledger_id
 * @property string $name
 * @property string|null $designation
 * @property string|null $phone
 * @property string|null $mobile
 * @property string|null $email
 * @property bool $is_primary
 */
#[Fillable(['name', 'designation', 'phone', 'mobile', 'email', 'is_primary', 'status', 'remarks'])]
#[UsePolicy(LedgerChildPolicy::class)]
class LedgerContact extends TenantModel
{
    use ValidatesParentCompany;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Ledger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    protected function companyScopedParents(): array
    {
        return ['ledger_id' => Ledger::class];
    }

    protected function auditLogName(): string
    {
        return 'ledger_contacts';
    }
}
