<?php

namespace App\Modules\Production\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Production\Policies\BomPolicy;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A bill of materials: the components that make `output_quantity` of a finished item (all in base units). An approval master: once waiting or approved it never changes — a change
 * is a new bill (a new code), and the old one is made inactive. Only an approved, active bill can start a production order.
 *
 * @property string $code
 * @property int $item_id
 * @property string $output_quantity
 * @property string $status active|inactive
 */
#[Fillable(['code', 'output_quantity', 'status', 'remarks'])]
#[UsePolicy(BomPolicy::class)]
class Bom extends TenantModel implements ApprovableDocument
{
    use Approvable, ValidatesParentCompany;

    private const MOVING_COLUMNS = ['approval_status', 'approved_by', 'approved_at', 'status', 'updated_by', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $bom) {
            $approval = (string) $bom->getRawOriginal('approval_status');

            if (in_array($approval, ['pending', 'approved'], true) && array_diff(array_keys($bom->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new LogicException('A bill of materials that is waiting for approval or approved cannot be changed: make a new one and make this one inactive.');
            }
        });
    }

    protected function casts(): array
    {
        return ['output_quantity' => 'decimal:3'];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Bom;
    }

    public function approvalUrl(): ?string
    {
        return route('production.boms.show', $this);
    }

    public function approvalTitle(): string
    {
        $this->loadMissing('item:id,name');

        return "Bill of materials {$this->code} — {$this->item?->name}";
    }

    public function defaultApproverRole(): ?string
    {
        return null;
    }

    /** @throws ApprovalStateException */
    public function beforeSubmitForApproval(): void
    {
        if ($this->components()->doesntExist()) {
            throw ApprovalStateException::notReady('Add at least one component before sending the bill for approval.');
        }
    }

    /** @return array<string, mixed> */
    protected function approvalFingerprintParts(): array
    {
        return [
            'bom' => collect($this->getAttributes())->only(['company_id', 'code', 'item_id', 'output_quantity', 'remarks'])->all(),
            'components' => $this->components()->get()->map(fn (BomItem $l) => [$l->line_no, $l->item_id, (string) $l->quantity, (string) $l->scrap_percent])->all(),
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return HasMany<BomItem, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(BomItem::class)->orderBy('line_no');
    }

    /** An approved, active bill is the only kind a production order may use. */
    public function isUsable(): bool
    {
        return $this->isApproved() && $this->status === 'active';
    }

    protected function companyScopedParents(): array
    {
        return ['item_id' => Item::class];
    }
}
