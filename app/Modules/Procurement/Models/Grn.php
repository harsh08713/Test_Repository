<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Masters\Models\Ledger;
use App\Modules\Procurement\Policies\GrnPolicy;
use App\Modules\Procurement\Services\GrnService;
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
 * A goods received note: what actually arrived against a purchase order, line by line, accepted and rejected. An approval document; once it is
 * approved its accepted quantities are posted to stock exactly once (GrnPoster). Pending and approved ones never change.
 *
 * @property string $grn_number
 * @property Carbon $grn_date
 * @property int $location_id
 * @property int $purchase_order_id
 * @property int $supplier_ledger_id
 * @property string $supplier_name
 * @property string|null $supplier_invoice_no
 * @property Carbon|null $posted_at
 * @property Carbon|null $post_attempted_at
 * @property string|null $post_error
 */
#[UsePolicy(GrnPolicy::class)]
class Grn extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    protected $table = 'grns';

    private const MOVING_COLUMNS = ['approval_status', 'approved_by', 'approved_at', 'posted_at', 'post_attempted_at', 'post_error', 'updated_by', 'updated_at'];

    /** A receipt has no active/inactive `status`: its approval says where it stands. */
    protected $attributes = [];

    protected static function booted(): void
    {
        static::updating(function (self $grn) {
            $approval = (string) $grn->getRawOriginal('approval_status');

            if (in_array($approval, ['pending', 'approved'], true) && array_diff(array_keys($grn->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new LogicException('A goods receipt that is waiting for approval or approved cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('A goods receipt is never deleted.'));
    }

    protected function casts(): array
    {
        return ['grn_date' => 'date:Y-m-d', 'posted_at' => 'datetime', 'post_attempted_at' => 'datetime'];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Grn;
    }

    public function approvalUrl(): ?string
    {
        return route('procurement.grns.show', $this);
    }

    public function approvalTitle(): string
    {
        return "Goods receipt {$this->grn_number} — {$this->supplier_name}";
    }

    public function defaultApproverRole(): ?string
    {
        return null;
    }

    /** @throws ApprovalStateException */
    public function beforeSubmitForApproval(): void
    {
        if ($this->items()->doesntExist()) {
            throw ApprovalStateException::notReady('Add at least one line before sending this for approval.');
        }

        if (($problem = app(GrnService::class)->problemBeforeSubmit($this)) !== null) {
            throw ApprovalStateException::notReady($problem);
        }
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Ledger, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'supplier_ledger_id');
    }

    /** @return HasMany<GrnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GrnItem::class)->orderBy('line_no');
    }

    protected function companyScopedParents(): array
    {
        return ['purchase_order_id' => PurchaseOrder::class, 'supplier_ledger_id' => Ledger::class];
    }
}
