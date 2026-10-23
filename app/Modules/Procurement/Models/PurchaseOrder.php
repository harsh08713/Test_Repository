<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Masters\Models\Ledger;
use App\Modules\Procurement\Policies\PurchaseOrderPolicy;
use App\Modules\Procurement\Services\PurchaseOrderService;
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
 * An order to a supplier for goods, to be received at one branch. An approval document: pending and approved orders never change (only
 * cancelling moves one). What has been received against it is worked out from the goods receipts, never stored on the order.
 *
 * @property string $po_number
 * @property Carbon $po_date
 * @property Carbon $expected_date
 * @property int $location_id
 * @property int $supplier_ledger_id
 * @property string $supplier_name
 * @property string $state open|cancelled
 * @property string|null $terms
 * @property string $total_amount
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
#[UsePolicy(PurchaseOrderPolicy::class)]
class PurchaseOrder extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    private const MOVING_COLUMNS = ['approval_status', 'approved_by', 'approved_at', 'state', 'cancelled_at', 'cancel_reason', 'updated_by', 'updated_at'];

    /** An order has no active/inactive `status`: its state says where it stands. */
    protected $attributes = ['state' => 'open', 'total_amount' => '0.00'];

    protected static function booted(): void
    {
        static::updating(function (self $po) {
            $approval = (string) $po->getRawOriginal('approval_status');

            if (in_array($approval, ['pending', 'approved'], true) && array_diff(array_keys($po->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new LogicException('A purchase order that is waiting for approval or approved cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('A purchase order is never deleted: cancel it instead.'));
    }

    protected function casts(): array
    {
        return ['po_date' => 'date:Y-m-d', 'expected_date' => 'date:Y-m-d', 'total_amount' => 'decimal:2', 'cancelled_at' => 'datetime'];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::PurchaseOrder;
    }

    public function approvalUrl(): ?string
    {
        return route('procurement.orders.show', $this);
    }

    public function approvalTitle(): string
    {
        return "Purchase order {$this->po_number} — {$this->supplier_name}";
    }

    /** Anyone holding `purchase_order.approve` (other than the maker) decides. */
    public function defaultApproverRole(): ?string
    {
        return null;
    }

    /** @throws ApprovalStateException */
    public function beforeSubmitForApproval(): void
    {
        if ($this->state !== 'open') {
            throw ApprovalStateException::notReady('A cancelled purchase order cannot be sent for approval.');
        }

        if ($this->items()->doesntExist()) {
            throw ApprovalStateException::notReady('Add at least one item before sending the order for approval.');
        }

        if (($problem = app(PurchaseOrderService::class)->problemBeforeSubmit($this)) !== null) {
            throw ApprovalStateException::notReady($problem);
        }
    }

    /** @return BelongsTo<Ledger, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'supplier_ledger_id');
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('line_no');
    }

    /** @return HasMany<Grn, $this> */
    public function grns(): HasMany
    {
        return $this->hasMany(Grn::class)->orderByDesc('id');
    }

    public function isCancelled(): bool
    {
        return $this->state === 'cancelled';
    }

    /** May goods be received against it: approved and not cancelled? */
    public function isReceivable(): bool
    {
        return $this->isApproved() && ! $this->isCancelled();
    }

    protected function companyScopedParents(): array
    {
        return ['supplier_ledger_id' => Ledger::class];
    }
}
