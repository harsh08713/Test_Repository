<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Policies\LedgerPolicy;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use LogicException;

/**
 * The central ledger master: customers, suppliers, banks, cash, expense and general accounts in one table, told
 * apart by `ledger_type`. It goes through the approval workflow and only an approved, active ledger may be
 * picked by a transaction (see `scopeUsable()` and LedgerLookup).
 *
 * This is the party/account *master*; it posts nothing and is not a double-entry ledger.
 *
 * @property LedgerType $ledger_type
 * @property string $name
 * @property string|null $legal_name
 * @property int $account_group_id
 * @property string|null $customer_kind b2b|b2c
 * @property string|null $gstin
 * @property string|null $pan
 * @property string|null $email
 * @property string|null $phone
 * @property int|null $credit_days
 * @property string|null $credit_limit
 * @property int|null $lead_time_days
 * @property list<string>|null $payment_preferences
 */
#[Fillable([
    'code', 'name', 'legal_name', 'account_group_id', 'customer_kind', 'gstin', 'pan', 'email', 'phone',
    'credit_days', 'credit_limit', 'lead_time_days', 'payment_preferences', 'status', 'remarks',
])]
#[UsePolicy(LedgerPolicy::class)]
class Ledger extends TenantModel implements ApprovableDocument
{
    use Approvable, ValidatesParentCompany;

    protected function casts(): array
    {
        return [
            'ledger_type' => LedgerType::class,
            'credit_days' => 'integer',
            'credit_limit' => 'decimal:2',
            'lead_time_days' => 'integer',
            'payment_preferences' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // What a ledger is decides its fields, its account group and who may handle it: it is fixed for life.
        static::updating(function (self $ledger) {
            if ($ledger->isDirty('ledger_type')) {
                throw new LogicException('The type of a ledger cannot be changed.');
            }
        });
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Ledger;
    }

    public function approvalUrl(): ?string
    {
        return route('masters.ledgers.show', $this);
    }

    /** @return BelongsTo<AccountGroup, $this> */
    public function accountGroup(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class);
    }

    /** @return HasMany<LedgerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(LedgerAddress::class)->orderBy('id');
    }

    /** @return HasMany<LedgerContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(LedgerContact::class)->orderBy('id');
    }

    /** @return HasOne<LedgerBankDetail, $this> */
    public function bankDetail(): HasOne
    {
        return $this->hasOne(LedgerBankDetail::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, LedgerType $type): Builder
    {
        return $query->where($this->qualifyColumn('ledger_type'), $type->value);
    }

    /** True for approved and active ledgers: the ones a transaction may pick. */
    public function isUsable(): bool
    {
        return $this->isApproved() && $this->status === 'active';
    }

    protected function companyScopedParents(): array
    {
        return ['account_group_id' => AccountGroup::class];
    }

    /**
     * An approver signs off on the ledger and everything filed under it: its addresses, contacts and bank
     * details. Whether it is active, and its remarks, are not part of what is approved.
     *
     * @return array<string, mixed>
     */
    protected function approvalFingerprintParts(): array
    {
        $own = Arr::except($this->getAttributes(), [
            'id', 'approval_status', 'approved_by', 'approved_at', 'status', 'remarks',
            'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at',
        ]);

        $strip = ['id', 'ledger_id', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at'];

        return [
            'ledger' => $own,
            'addresses' => $this->addresses()->get()->map(fn (LedgerAddress $a) => Arr::except($a->getAttributes(), $strip))->all(),
            'contacts' => $this->contacts()->get()->map(fn (LedgerContact $c) => Arr::except($c->getAttributes(), $strip))->all(),
            // Never the number itself: its keyed hash changes exactly when the number does.
            'bank' => ($bank = $this->bankDetail()->first()) === null ? null : Arr::only($bank->getAttributes(), [
                'bank_name', 'branch', 'account_holder', 'account_type', 'account_number_hash', 'ifsc',
            ]),
        ];
    }

    /** A party must be complete before an approver is asked to sign it off. */
    public function beforeSubmitForApproval(): void
    {
        $missing = $this->missingForApproval();

        if ($missing !== []) {
            throw ApprovalStateException::notReady('Complete the ledger before submitting it: '.implode('; ', $missing).'.');
        }
    }

    /** @return list<string> what is still needed before this ledger can be sent for approval */
    public function missingForApproval(): array
    {
        $missing = [];
        $type = $this->ledger_type;

        if ($type === LedgerType::Customer && $this->customer_kind === 'b2b' && ($this->gstin === null || $this->gstin === '')) {
            $missing[] = 'a GSTIN (required for a B2B customer)';
        }

        if ($type->isParty()) {
            $addresses = $this->addresses()->where('status', 'active')->get();

            if ($addresses->where('is_default_billing', true)->isEmpty()) {
                $missing[] = 'at least one billing address';
            }

            if ($this->contacts()->where('status', 'active')->doesntExist()) {
                $missing[] = 'at least one contact';
            }
        }

        if ($type->requiresBankDetails() && ! $this->bankDetail()->exists()) {
            $missing[] = 'the bank account details';
        }

        return $missing;
    }
}
