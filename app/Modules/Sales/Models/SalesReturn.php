<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Sales\Policies\SalesReturnPolicy;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A sales return (goods come back) or a credit note (money is credited, goods stay) against one issued invoice. Both take quantity off the
 * invoice's lines at the price and tax those lines carried, and both reduce net revenue the same way; they differ for stock, which arrives with
 * Phase L3. An approval document: while it is pending or approved it never changes, and it is never deleted.
 *
 * @property string $return_number
 * @property Carbon $return_date
 * @property string $return_type return|credit_note
 * @property string $reason
 * @property int $location_id
 * @property int $sales_invoice_id
 * @property int $customer_ledger_id
 * @property int $assigned_to
 * @property string $party_name
 * @property string $supply_type
 * @property string $gross_total
 * @property string $discount_total
 * @property string $taxable_total
 * @property string $cgst_total
 * @property string $sgst_total
 * @property string $igst_total
 * @property string $round_off
 * @property string $grand_total
 * @property string|null $cost_total null when any returned line has no known cost
 */
#[UsePolicy(SalesReturnPolicy::class)]
class SalesReturn extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    public const RETURN = 'return';

    public const CREDIT_NOTE = 'credit_note';

    private const MOVING_COLUMNS = ['approval_status', 'approved_by', 'approved_at', 'updated_by', 'updated_at'];

    /** A return has no active/inactive `status`: its approval says where it stands. */
    protected $attributes = [
        'gross_total' => '0.00',
        'discount_total' => '0.00',
        'taxable_total' => '0.00',
        'cgst_total' => '0.00',
        'sgst_total' => '0.00',
        'igst_total' => '0.00',
        'round_off' => '0.00',
        'grand_total' => '0.00',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $return) {
            $approval = (string) $return->getRawOriginal('approval_status');

            if (in_array($approval, ['pending', 'approved'], true) && array_diff(array_keys($return->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new LogicException('A sales return that is waiting for approval or approved cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('A sales return is never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'return_date' => 'date:Y-m-d',
            'gross_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::SalesReturn;
    }

    public function approvalUrl(): ?string
    {
        return route('sales.returns.show', $this);
    }

    public function approvalTitle(): string
    {
        return "{$this->typeLabel()} {$this->return_number} — {$this->party_name}";
    }

    /** Anyone holding `sales_return.approve` (other than the maker) decides; there is no fixed approver role. */
    public function defaultApproverRole(): ?string
    {
        return null;
    }

    /**
     * Before it goes for approval the lines are checked against what the invoice still allows, under a lock on the invoice, so two returns
     * that each fit alone cannot both be submitted when together they do not.
     *
     * @throws ApprovalStateException
     */
    public function beforeSubmitForApproval(): void
    {
        if ($this->items()->doesntExist()) {
            throw ApprovalStateException::notReady('Add at least one line before sending this for approval.');
        }

        if (($problem = app(SalesReturnService::class)->problemBeforeSubmit($this)) !== null) {
            throw ApprovalStateException::notReady($problem);
        }
    }

    // ---- relations ----------------------------------------------------------------------------------------------

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /** @return BelongsTo<Ledger, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'customer_ledger_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<SalesReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class)->orderBy('line_no');
    }

    // ---- what it is ---------------------------------------------------------------------------------------------

    public function isCreditNote(): bool
    {
        return $this->return_type === self::CREDIT_NOTE;
    }

    public function typeLabel(): string
    {
        return $this->isCreditNote() ? 'Credit note' : 'Sales return';
    }

    protected function companyScopedParents(): array
    {
        return ['sales_invoice_id' => SalesInvoice::class, 'customer_ledger_id' => Ledger::class];
    }
}
