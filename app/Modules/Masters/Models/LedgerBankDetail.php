<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\LedgerChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;

/**
 * The bank account of a ledger. The account number is sensitive: encrypted at rest, never serialised, kept out of
 * the audit log (a change is recorded as an event that carries only the last four digits) and shown in full only
 * to people holding `ledger.view_sensitive`.
 *
 * @property int $ledger_id
 * @property string $bank_name
 * @property string|null $branch
 * @property string|null $account_holder
 * @property string|null $account_type
 * @property string $account_number
 * @property string $account_last4
 * @property string $account_number_hash
 * @property string $ifsc
 */
#[Fillable(['bank_name', 'branch', 'account_holder', 'account_type', 'ifsc', 'status', 'remarks'])]
#[UsePolicy(LedgerChildPolicy::class)]
class LedgerBankDetail extends TenantModel
{
    use ValidatesParentCompany;

    protected $hidden = ['account_number', 'account_number_hash'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted'];
    }

    /** Keyed hash of a bank account number: stable for the same number, useless without the application key. */
    public static function hashNumber(string $number): string
    {
        return hash_hmac('sha256', preg_replace('/\s+/', '', $number) ?? $number, (string) config('app.key'));
    }

    /** "XXXXXX1234": all anyone without the sensitive permission ever sees. */
    public function masked(): string
    {
        return str_repeat('X', 6).$this->account_last4;
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
        return 'ledger_bank_details';
    }

    public function getActivitylogOptions(): LogOptions
    {
        // The number, its ciphertext and its hash never reach the audit trail.
        return LogOptions::defaults()
            ->useLogName($this->auditLogName())
            ->logAll()
            ->logExcept(['updated_at', 'updated_by', 'account_number', 'account_number_hash'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
