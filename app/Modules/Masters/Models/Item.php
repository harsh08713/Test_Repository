<?php

namespace App\Modules\Masters\Models;

use App\Models\User;
use App\Modules\Finance\Models\ItemCostEntry;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Policies\ItemPolicy;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * The item master, filled in by three departments in turn:
 *
 *   1. Purchase   — name, group, base unit, unit conversions, PO tolerance, HSN and GST rate
 *   2. Inventory  — item code, alias names, stock levels per branch
 *   3. Approval   — Accounts by default (configurable per company); Operations may view and comment only
 *
 * `workflow_stage` says whose turn it is; `approval_status` is the shared approval engine's state. The two are
 * kept consistent *here*, whichever screen or service drives the engine (the item page, the approval inbox, a
 * future API): submitting moves the item to "awaiting approval", approving to "approved", and a rejection or
 * re-opening sends it back to the stage that was named (default: Inventory after a rejection, Purchase after a
 * re-opening). Every move is written to the item's stage history from the one place below, so no path can skip it.
 *
 * Only an approved, active item may be used by a transaction (see ItemLookup).
 *
 * @property string|null $code
 * @property string $name
 * @property string|null $description
 * @property int $item_group_id
 * @property int|null $brand_id
 * @property int|null $industry_template_id the industry the product belongs to (revenue by industry); null = unassigned
 * @property string $tracking none|batch
 * @property bool $has_expiry
 * @property int|null $shelf_life_days
 * @property string|null $standard_cost per base unit; what an invoice line takes as its cost
 * @property int $base_unit_id
 * @property string $po_tolerance_percent
 * @property string|null $hsn_code
 * @property string|null $gst_rate
 * @property ItemStage $workflow_stage
 */
#[Fillable(['code', 'name', 'description', 'item_group_id', 'brand_id', 'industry_template_id', 'base_unit_id', 'po_tolerance_percent', 'hsn_code', 'gst_rate', 'standard_cost', 'status', 'remarks'])]
#[UsePolicy(ItemPolicy::class)]
class Item extends TenantModel implements ApprovableDocument
{
    use Approvable, ValidatesParentCompany;

    protected $attributes = [
        'status' => 'active',
        'po_tolerance_percent' => 0,
        'workflow_stage' => 'purchase_draft',
        'tracking' => 'none',
        'has_expiry' => false,
    ];

    /** Where a rejection / re-opening sends the item, when the caller names one. Not stored; read when the status changes. */
    public ?ItemStage $returnStage = null;

    /** Who and why, for the stage history row written when the stage changes. Not stored. */
    public ?User $stageActor = null;

    public ?string $stageComment = null;

    /** True from the moment the row is being inserted until its stage history entry is written. Not stored. */
    private bool $inserting = false;

    protected function casts(): array
    {
        return [
            'workflow_stage' => ItemStage::class,
            'po_tolerance_percent' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'standard_cost' => 'decimal:4',
            'has_expiry' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if ($item->exists && $item->isDirty('approval_status')) {
                $from = ApprovalStatus::tryFrom((string) $item->getRawOriginal('approval_status'));

                $stage = match ($item->approval_status) {
                    ApprovalStatus::Pending => ItemStage::ApprovalPending,
                    ApprovalStatus::Approved => ItemStage::Approved,
                    ApprovalStatus::Rejected => $item->returnStage ?? ItemStage::InventoryPending,
                    ApprovalStatus::Draft => $from === ApprovalStatus::Approved ? ($item->returnStage ?? ItemStage::PurchaseDraft) : null,
                };

                if ($stage !== null) {
                    $item->workflow_stage = $stage;
                }
            }

            // Whatever got us here, the two states must agree: a stage the approval engine would never produce is a bug.
            if (! $item->workflow_stage->fits($item->approval_status)) {
                throw new LogicException("Item stage {$item->workflow_stage->value} does not fit approval status {$item->approval_status->value}.");
            }
        });

        // What the item costs is evidence: every change of the standard cost is written to the cost history.
        static::saved(function (self $item) {
            // inside `saved` the original attributes are not synced yet, so a changed (or newly set) cost is still dirty; wasChanged() is false for an insert
            if ($item->isDirty('standard_cost')) {
                $previous = $item->getRawOriginal('standard_cost');
                ItemCostEntry::record(
                    (int) $item->company_id, (int) $item->getKey(), null, 'standard', $item->standard_cost === null ? null : (string) $item->standard_cost,
                    $previous === null ? null : bcadd((string) $previous, '0', 4), 'item', (int) $item->getKey(), Auth::id(),
                );
            }
        });

        // Flag the insert itself. Neither wasRecentlyCreated (it stays true on the instance that created the row, however
        // often that instance is saved afterwards) nor the original attributes (a new model already carries its defaults) can tell.
        static::creating(function (self $item) {
            $item->inserting = true;
        });

        static::saved(function (self $item) {
            $created = $item->inserting;
            $item->inserting = false;

            if (! $created && ! $item->wasChanged('workflow_stage')) {
                return;
            }

            $from = $created ? null : ItemStage::tryFrom((string) $item->getRawOriginal('workflow_stage'));

            ItemStageEntry::record($item, $from, $item->workflow_stage, $item->stageActor ?? (Auth::user() instanceof User ? Auth::user() : null), $item->stageComment);

            $item->returnStage = $item->stageActor = $item->stageComment = null;
        });
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Item;
    }

    public function approvalUrl(): ?string
    {
        return route('masters.items.show', $this);
    }

    /** Items go to the department the company routes them to; Accounts unless the company changed the setting. */
    public function defaultApproverRole(): ?string
    {
        $route = app(SettingsService::class)->get('approvals.item_approver', $this->approvalCompanyId());

        return $route === 'operations' ? 'Operations User' : 'Accounts User';
    }

    // ---- relations ------------------------------------------------------------------------------------------

    /** @return BelongsTo<ItemGroup, $this> */
    public function itemGroup(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class);
    }

    /** @return BelongsTo<IndustryTemplate, $this> */
    public function industryTemplate(): BelongsTo
    {
        return $this->belongsTo(IndustryTemplate::class, 'industry_template_id');
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /** @return HasMany<ItemUom, $this> */
    public function uoms(): HasMany
    {
        return $this->hasMany(ItemUom::class)->orderBy('id');
    }

    /** @return HasMany<ItemAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(ItemAlias::class)->orderBy('alias');
    }

    /** @return HasMany<ItemLocationParameter, $this> */
    public function locationParameters(): HasMany
    {
        return $this->hasMany(ItemLocationParameter::class)->orderBy('location_id');
    }

    /** @return HasMany<ItemStageEntry, $this> */
    public function stageHistory(): HasMany
    {
        return $this->hasMany(ItemStageEntry::class)->orderBy('id');
    }

    protected function companyScopedParents(): array
    {
        return ['item_group_id' => ItemGroup::class, 'brand_id' => Brand::class, 'base_unit_id' => Unit::class];
    }

    // ---- state ----------------------------------------------------------------------------------------------

    /** Has this item ever been approved? From then on its identity (code, base unit, existing conversions) is frozen. */
    public function everApproved(): bool
    {
        return $this->exists && $this->approvals()->where('status', 'approved')->exists();
    }

    /** True for approved and active items: the ones a transaction may use. */
    public function isUsable(): bool
    {
        return $this->isApproved() && $this->status === 'active';
    }

    /** Sent back by an approver (or re-opened) and waiting for rework in its current stage. */
    public function isReturned(): bool
    {
        return $this->approval_status === ApprovalStatus::Rejected;
    }

    /** "Purchase", "Inventory", "Awaiting approval", "Approved" — or "Returned to Purchase" after a rejection. */
    public function stageLabel(): string
    {
        return $this->isReturned() && $this->workflow_stage->isWorkStage()
            ? 'Returned to '.$this->workflow_stage->label()
            : $this->workflow_stage->label();
    }

    // ---- readiness ------------------------------------------------------------------------------------------

    /** @return list<string> what Purchase still has to fill in before the item can go to Inventory */
    public function missingForPurchaseStage(): array
    {
        $missing = [];

        foreach ([
            'name' => 'a name', 'item_group_id' => 'an item group', 'base_unit_id' => 'a base unit',
            'hsn_code' => 'an HSN code', 'gst_rate' => 'a GST rate',
        ] as $field => $label) {
            if ($this->getAttribute($field) === null || $this->getAttribute($field) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /** @return list<string> everything still needed before the item can be submitted for approval */
    public function missingForApproval(): array
    {
        $missing = $this->missingForPurchaseStage();

        if ($this->code === null || $this->code === '') {
            $missing[] = 'an item code (Inventory assigns it)';
        }

        return $missing;
    }

    /** The item may only be submitted from the Inventory stage, once both stages are complete and someone can decide it. */
    public function beforeSubmitForApproval(): void
    {
        if ($this->workflow_stage !== ItemStage::InventoryPending) {
            throw ApprovalStateException::notReady('An item is submitted for approval from the Inventory stage, after Purchase has completed its part.');
        }

        $missing = $this->missingForApproval();

        if ($missing !== []) {
            throw ApprovalStateException::notReady('Complete the item before submitting it: '.implode('; ', $missing).'.');
        }

        $this->assertSomeoneCanApprove();
    }

    /**
     * With no approval levels configured, items are routed to a role (Accounts by default). If nobody holds that
     * role *and* the approve permission, the item would wait for ever: say so now, while the person can still do something.
     */
    private function assertSomeoneCanApprove(): void
    {
        $configured = ApprovalLevel::withoutGlobalScopes()
            ->where('company_id', $this->approvalCompanyId())->where('document_type', DocumentType::Item->value)->exists();

        if ($configured) {
            return;   // an administrator set the route up explicitly; that is theirs to get right
        }

        $role = (string) $this->defaultApproverRole();
        $tenant = app(TenantContext::class);

        try {
            $someone = User::query()->role($role)->permission('item.approve')->where('is_active', true)->get()
                ->contains(fn (User $user) => $tenant->userCanAccessCompany($user, $this->approvalCompanyId()) && $user->getKey() !== $this->created_by);
        } catch (RoleDoesNotExist|PermissionDoesNotExist) {
            $someone = false;
        }

        if (! $someone) {
            throw ApprovalStateException::notReady("Items are routed to the \"{$role}\" role for approval, but nobody in this company holds it together with the item approval permission. Ask an administrator to set that up (Administration → Roles & permissions, or Approval levels).");
        }
    }

    /**
     * An approver signs off on the item and the three child lists. Whether it is active, its remarks and which
     * stage it is in are not part of what is approved.
     *
     * @return array<string, mixed>
     */
    protected function approvalFingerprintParts(): array
    {
        $own = Arr::except($this->getAttributes(), [
            'id', 'approval_status', 'approved_by', 'approved_at', 'status', 'remarks', 'workflow_stage',
            'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at',
        ]);

        $strip = ['id', 'item_id', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at'];

        return [
            'item' => $own,
            'uoms' => $this->uoms()->get()->map(fn (ItemUom $u) => Arr::except($u->getAttributes(), $strip))->all(),
            'aliases' => $this->aliases()->get()->map(fn (ItemAlias $a) => Arr::except($a->getAttributes(), $strip))->all(),
            'locations' => $this->locationParameters()->get()->map(fn (ItemLocationParameter $p) => Arr::except($p->getAttributes(), $strip))->all(),
        ];
    }
}
