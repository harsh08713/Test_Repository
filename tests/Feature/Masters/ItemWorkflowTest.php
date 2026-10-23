<?php

use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemStageEntry;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\ItemService;
use App\Modules\Masters\Services\ItemWorkflow;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->inventory = makeUser(['Inventory User'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);

    $this->workflow = app(ItemWorkflow::class);
    $this->approvals = app(ApprovalService::class);
});

/** The events recorded for an item, oldest first. */
function events(Item $item): array
{
    return ItemStageEntry::query()->where('item_id', $item->id)->orderBy('id')->pluck('event')->all();
}

// ======================================================================================================
// The happy path
// ======================================================================================================

it('takes an item from Purchase through Inventory to approval, recording every step', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Hex Bolt']]);
    expect($item)->workflow_stage->toBe(ItemStage::PurchaseDraft);

    $this->workflow->sendToInventory($item, $this->purchase);
    expect($item->fresh())->workflow_stage->toBe(ItemStage::InventoryPending)->approval_status->toBe(ApprovalStatus::Draft);

    inCompany($this->acme, fn () => app(ItemService::class)->saveInventoryStage($item->fresh(), inventoryInput('Hex Bolt'), $this->inventory));
    $this->workflow->submit($item->fresh(), $this->inventory);
    expect($item->fresh())->workflow_stage->toBe(ItemStage::ApprovalPending)->approval_status->toBe(ApprovalStatus::Pending)
        ->and($item->fresh()->isUsable())->toBeFalse();

    $this->workflow->approve($item->fresh(), $this->accounts, 'Rates and HSN verified');
    $item = $item->fresh();
    expect($item)->workflow_stage->toBe(ItemStage::Approved)->approval_status->toBe(ApprovalStatus::Approved)->approved_by->toBe($this->accounts->id)
        ->and($item->isUsable())->toBeTrue();

    $history = ItemStageEntry::query()->where('item_id', $item->id)->orderBy('id')->get();
    expect($history->pluck('event')->all())->toBe(['created', 'sent_to_inventory', 'submitted', 'approved'])
        ->and($history->pluck('actor_id')->all())->toBe([$this->purchase->id, $this->purchase->id, $this->inventory->id, $this->accounts->id])
        ->and($history->pluck('to_stage')->map->value->all())->toBe(['purchase_draft', 'inventory_pending', 'approval_pending', 'approved'])
        ->and($history->last()->comment)->toBe('Rates and HSN verified')
        ->and(Approval::query()->where('approvable_id', $item->id)->count())->toBe(1);
});

it('will not send an incomplete item to Inventory, and says what is missing', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['hsn_code' => '', 'gst_rate' => '']]);

    expect(fn () => $this->workflow->sendToInventory($item, $this->purchase))->toThrow(ApprovalStateException::class, 'an HSN code; a GST rate')
        ->and($item->fresh()->workflow_stage)->toBe(ItemStage::PurchaseDraft)->and(events($item))->toBe(['created']);
});

it('will not submit an item before Purchase has handed it over, or before it has a code', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Still with Purchase']]);
    expect(fn () => $this->workflow->submit($draft, $this->inventory))->toThrow(ApprovalStateException::class, 'from the Inventory stage');

    $noCode = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'No code'], 'inventory' => ['code' => '']], 'inventory');
    expect(fn () => $this->workflow->submit($noCode, $this->inventory))->toThrow(ApprovalStateException::class, 'an item code')
        ->and($noCode->fresh()->workflow_stage)->toBe(ItemStage::InventoryPending)->and(Approval::query()->count())->toBe(0);
});

it('does the stage moves only from the stage they belong to', function () {
    $inventory = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'In Inventory']], 'inventory');
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'In Purchase']]);

    expect(fn () => $this->workflow->sendToInventory($inventory, $this->purchase))->toThrow(ApprovalStateException::class, 'Only an item in the Purchase stage')
        ->and(fn () => $this->workflow->returnToPurchase($draft, $this->inventory, 'why'))->toThrow(ApprovalStateException::class, 'Only an item in the Inventory stage');
});

it('sends an item that Inventory cannot complete back to Purchase, with the reason on record', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Wrong unit']], 'inventory');

    expect(fn () => $this->workflow->returnToPurchase($item, $this->inventory, '  '))->toThrow(ApprovalStateException::class, 'say why');

    $this->workflow->returnToPurchase($item, $this->inventory, 'The base unit should be Kg');
    $item = $item->fresh();
    $entry = ItemStageEntry::query()->where('item_id', $item->id)->latest('id')->first();

    expect($item->workflow_stage)->toBe(ItemStage::PurchaseDraft)
        ->and($entry)->event->toBe('returned_to_purchase')->comment->toBe('The base unit should be Kg')->actor_id->toBe($this->inventory->id)
        ->and($this->purchase->can('editPurchase', $item))->toBeTrue()->and($this->inventory->can('editInventory', $item))->toBeFalse();

    // Purchase fixes it and sends it on again
    $this->workflow->sendToInventory($item, $this->purchase);
    expect($item->fresh()->workflow_stage)->toBe(ItemStage::InventoryPending)->and(events($item))->toBe(['created', 'sent_to_inventory', 'returned_to_purchase', 'sent_to_inventory']);
});

// ======================================================================================================
// Rejection
// ======================================================================================================

it('returns a rejected item to the stage the approver names, with the comment and history', function (ItemStage $returnTo, string $owner) {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => "Back to {$owner}"]], 'submitted');

    expect(fn () => $this->workflow->reject($item->fresh(), $this->accounts, '', $returnTo))->toThrow(ApprovalStateException::class, 'comment is required');

    $this->workflow->reject($item->fresh(), $this->accounts, 'The GST rate is wrong', $returnTo);
    $item = $item->fresh();
    $entry = ItemStageEntry::query()->where('item_id', $item->id)->latest('id')->first();

    expect($item)->workflow_stage->toBe($returnTo)->approval_status->toBe(ApprovalStatus::Rejected)->and($item->isReturned())->toBeTrue()
        ->and($item->stageLabel())->toBe("Returned to {$owner}")
        ->and($entry)->event->toBe('rejected')->comment->toBe('The GST rate is wrong')->actor_id->toBe($this->accounts->id)->to_stage->toBe($returnTo)
        ->and($entry->label())->toBe("Rejected — returned to {$owner}");

    // only that department can pick it up
    $canEdit = fn ($user) => [$user->can('editPurchase', $item), $user->can('editInventory', $item)];
    expect($canEdit($this->purchase))->toBe([$returnTo === ItemStage::PurchaseDraft, false])
        ->and($canEdit($this->inventory))->toBe([false, $returnTo === ItemStage::InventoryPending]);
})->with([
    'to Purchase' => [ItemStage::PurchaseDraft, 'Purchase'],
    'to Inventory' => [ItemStage::InventoryPending, 'Inventory'],
]);

it('refuses to send a rejected item anywhere but back to Purchase or Inventory', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');

    expect(fn () => $this->workflow->reject($item, $this->accounts, 'No', ItemStage::Approved))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->workflow->reject($item, $this->accounts, 'No', ItemStage::ApprovalPending))->toThrow(InvalidArgumentException::class)
        ->and($item->fresh()->workflow_stage)->toBe(ItemStage::ApprovalPending);
});

it('runs a second round after a rejection, keeping both rounds on record', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Two rounds']], 'submitted');
    $this->workflow->reject($item->fresh(), $this->accounts, 'Alias clashes', ItemStage::InventoryPending);

    // Inventory fixes its part and resubmits straight away
    inCompany($this->acme, fn () => app(ItemService::class)->saveInventoryStage($item->fresh(), inventoryInput('Two rounds', ['inventory' => ['code' => $item->code], 'aliases' => [['id' => '', 'alias' => 'second round']]]), $this->inventory));
    $this->workflow->submit($item->fresh(), $this->inventory);
    $this->workflow->approve($item->fresh(), $this->accounts);

    $rows = Approval::query()->where('approvable_id', $item->id)->orderBy('cycle')->get();
    expect($item->fresh())->workflow_stage->toBe(ItemStage::Approved)
        ->and($rows->pluck('cycle')->all())->toBe([1, 2])->and($rows[0]->status->value)->toBe('rejected')->and($rows[0]->comment)->toBe('Alias clashes')
        ->and(events($item))->toBe(['created', 'sent_to_inventory', 'submitted', 'rejected', 'submitted', 'approved']);
});

it('walks the full loop: rejected to Purchase, sent on, submitted, approved', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Full loop']], 'submitted');
    $this->workflow->reject($item->fresh(), $this->accounts, 'HSN is wrong', ItemStage::PurchaseDraft);

    inCompany($this->acme, fn () => app(ItemService::class)->savePurchaseStage($item->fresh(), itemInput($this->acme, ['item' => ['name' => 'Full loop', 'hsn_code' => '731816']]), $this->purchase));
    $this->workflow->sendToInventory($item->fresh(), $this->purchase);
    $this->workflow->submit($item->fresh(), $this->inventory);
    $this->workflow->approve($item->fresh(), $this->accounts);

    expect($item->fresh())->hsn_code->toBe('731816')->workflow_stage->toBe(ItemStage::Approved)
        ->and(events($item))->toBe(['created', 'sent_to_inventory', 'submitted', 'rejected', 'sent_to_inventory', 'submitted', 'approved']);
});

// ======================================================================================================
// The stage stays right whichever way the approval engine is driven
// ======================================================================================================

it('keeps the stage and its history right when the approval engine is driven directly, as the inbox does', function () {
    $approved = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Through the inbox']], 'submitted');
    $rejected = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Rejected in the inbox']], 'submitted');

    $this->actingAs($this->accounts);
    $this->approvals->approve($approved->fresh(), $this->accounts);
    $this->approvals->reject($rejected->fresh(), $this->accounts, 'Not right');

    expect($approved->fresh())->workflow_stage->toBe(ItemStage::Approved)->and($approved->fresh()->isUsable())->toBeTrue()
        ->and($rejected->fresh())->workflow_stage->toBe(ItemStage::InventoryPending)->approval_status->toBe(ApprovalStatus::Rejected)   // default: back to the stage that submitted
        ->and(events($approved))->toBe(['created', 'sent_to_inventory', 'submitted', 'approved'])
        ->and(ItemStageEntry::query()->where('item_id', $rejected->id)->latest('id')->first())->event->toBe('rejected')->actor_id->toBe($this->accounts->id);

    // and a direct re-open lands back in Purchase unless told otherwise
    $this->approvals->reopen($approved->fresh(), $this->accounts, 'Fix the HSN');
    expect($approved->fresh())->workflow_stage->toBe(ItemStage::PurchaseDraft)->approval_status->toBe(ApprovalStatus::Draft);
});

it('refuses a stage that does not fit the approval status', function (ItemStage $stage) {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => "Invariant {$stage->value}"]]);

    // the approval status is left alone, so nothing can excuse the mismatch
    expect(fn () => $item->forceFill(['workflow_stage' => $stage])->save())->toThrow(LogicException::class, 'does not fit')
        ->and($item->fresh()->workflow_stage)->toBe(ItemStage::PurchaseDraft);
})->with([
    'approved stage on a draft' => [ItemStage::Approved],
    'approval stage on a draft' => [ItemStage::ApprovalPending],
]);

it('re-derives the stage when the approval status changes, rather than trusting what was set alongside it', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Self-correcting']]);

    // a draft being marked pending is, by definition, awaiting approval, whatever stage the caller also set
    $item->forceFill(['workflow_stage' => ItemStage::PurchaseDraft, 'approval_status' => ApprovalStatus::Pending])->save();

    expect($item->fresh()->workflow_stage)->toBe(ItemStage::ApprovalPending);
});

it('keeps the stage history append-only', function () {
    $item = makeItem($this->acme, $this->purchase);
    $entry = ItemStageEntry::query()->where('item_id', $item->id)->firstOrFail();

    expect(fn () => $entry->forceFill(['comment' => 'edited'])->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'append-only');
});

// ======================================================================================================
// Re-opening an approved item
// ======================================================================================================

it('re-opens an approved item to Purchase or Inventory, with a reason, and it stops being usable', function (ItemStage $to, string $who) {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => "Reopen {$who}"]], 'approved');
    expect($item->isUsable())->toBeTrue();

    expect(fn () => $this->workflow->reopen($item, $this->admin, '  ', $to))->toThrow(ApprovalStateException::class, 'say why');

    $this->workflow->reopen($item, $this->admin, 'Customs asked for another HSN', $to);
    $item = $item->fresh();
    $entry = ItemStageEntry::query()->where('item_id', $item->id)->latest('id')->first();

    expect($item)->workflow_stage->toBe($to)->approval_status->toBe(ApprovalStatus::Draft)->approved_by->toBeNull()
        ->and($item->isUsable())->toBeFalse()->and(app(ItemLookup::class)->usable($item->id))->toBeNull()
        ->and($entry)->event->toBe('reopened')->comment->toBe('Customs asked for another HSN')->actor_id->toBe($this->admin->id)
        ->and(Approval::query()->where('approvable_id', $item->id)->count())->toBe(1);   // the earlier approval stays on record
})->with([
    'to Purchase' => [ItemStage::PurchaseDraft, 'Purchase'],
    'to Inventory' => [ItemStage::InventoryPending, 'Inventory'],
]);

it('only re-opens an approved item, and not to a stage that is not a working stage', function () {
    $submitted = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Waiting']], 'submitted');
    $approved = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Done']], 'approved');

    expect(fn () => $this->workflow->reopen($submitted, $this->admin, 'Why', ItemStage::PurchaseDraft))->toThrow(ApprovalStateException::class, 'Only an approved document')
        ->and(fn () => $this->workflow->reopen($approved, $this->admin, 'Why', ItemStage::Approved))->toThrow(InvalidArgumentException::class);
});

it('goes through Inventory again after a re-open to Inventory, and is usable again once approved', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Corrected']], 'approved');
    $this->workflow->reopen($item, $this->inventory, 'Add an alias', ItemStage::InventoryPending);

    inCompany($this->acme, fn () => app(ItemService::class)->saveInventoryStage($item->fresh(), inventoryInput('Corrected', ['inventory' => ['code' => $item->code], 'aliases' => [['id' => '', 'alias' => 'corrected thing']]]), $this->inventory));
    $this->workflow->submit($item->fresh(), $this->inventory);
    $this->workflow->approve($item->fresh(), $this->accounts);

    expect($item->fresh()->isUsable())->toBeTrue()->and(Approval::query()->where('approvable_id', $item->id)->pluck('cycle')->all())->toBe([1, 2]);
});

// ======================================================================================================
// What an approver signs off on
// ======================================================================================================

it('refuses to approve an item that changed after it was submitted', function (string $change) {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => "Changed {$change}"], 'aliases' => [['id' => '', 'alias' => 'orig alias']], 'locations' => [
        ['id' => '', 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '1', 'reorder_level' => '2', 'maximum_stock' => '3'],
    ]], 'submitted');

    match ($change) {
        'name' => $item->forceFill(['name' => 'Sneaky'])->save(),
        'hsn' => $item->forceFill(['hsn_code' => '999999'])->save(),
        'code' => $item->forceFill(['code' => 'SNEAK-1'])->save(),
        'unit factor' => $item->uoms()->first()->forceFill(['factor' => 7])->save(),
        'alias' => $item->aliases()->first()->forceFill(['alias' => 'new alias'])->save(),
        'stock level' => $item->locationParameters()->first()->forceFill(['maximum_stock' => 99])->save(),
    };

    expect(fn () => $this->workflow->approve($item->fresh(), $this->accounts))->toThrow(ApprovalStateException::class, 'changed after it was submitted')
        ->and($item->fresh()->workflow_stage)->toBe(ItemStage::ApprovalPending);
})->with(['name', 'hsn', 'code', 'unit factor', 'alias', 'stock level']);

it('does not count switching the item off, or its remarks, as a change', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');
    $item->forceFill(['status' => 'inactive', 'remarks' => 'on hold'])->save();

    $this->workflow->approve($item->fresh(), $this->accounts);

    expect($item->fresh())->isApproved()->toBeTrue()->and($item->fresh()->isUsable())->toBeFalse();
});

// ======================================================================================================
// Who decides: routing
// ======================================================================================================

it('routes items to the Accounts role by default, and no one else may decide', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');

    expect($item->defaultApproverRole())->toBe('Accounts User')
        ->and($this->approvals->canAct($this->accounts, $item))->toBeTrue()
        ->and($this->approvals->canAct($this->approver, $item))->toBeFalse()          // holds the permission, but items are not routed to them
        ->and($this->approvals->canAct($this->admin, $item))->toBeFalse()
        ->and($this->approvals->canAct($this->operations, $item))->toBeFalse();

    expect(fn () => $this->workflow->approve($item->fresh(), $this->approver))->toThrow(AuthorizationException::class, 'not an approver')
        ->and(fn () => $this->workflow->approve($item->fresh(), $this->operations))->toThrow(AuthorizationException::class);
});

it('keeps maker–checker: whoever submitted an item cannot approve it', function () {
    $both = makeUser(['Inventory User', 'Accounts User'], [$this->acme]);
    $item = makeItem($this->acme, $this->purchase, [], 'inventory', $both);
    $this->workflow->submit($item->fresh(), $both);

    expect(fn () => $this->workflow->approve($item->fresh(), $both))->toThrow(AuthorizationException::class, 'created or submitted');
});

it('says so at submission when nobody in the company can decide an item', function () {
    // an Inventory user exists, but nobody holds the Accounts role
    $company = makeCompany('LONELY');
    seedDefaults($company);
    $buyer = makeUser(['Purchase User'], [$company]);

    $item = makeItem($company, $buyer, [], 'inventory');
    Role::findByName('Accounts User')->revokePermissionTo('item.approve');
    $accountant = makeUser(['Accounts User'], [$company]);

    expect(fn () => $this->workflow->submit($item, makeUser(['Inventory User'], [$company])))->toThrow(ApprovalStateException::class, 'nobody in this company holds it');
    expect($accountant->exists)->toBeTrue();
});

it('follows the company setting when items are routed to Operations, but only if Operations has been given the approval permission', function () {
    app(SettingsService::class)->set('approvals.item_approver', 'operations', $this->acme->id);
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Ops routed']], 'inventory');

    expect($item->defaultApproverRole())->toBe('Operations User')
        ->and(fn () => $this->workflow->submit($item, $this->inventory))->toThrow(ApprovalStateException::class, '"Operations User" role');   // by default Operations may only comment

    Role::findByName('Operations User')->givePermissionTo('item.approve');         // the company's explicit decision
    $this->workflow->submit($item->fresh(), $this->inventory);

    expect($this->approvals->canAct($this->operations, $item->fresh()))->toBeTrue()->and($this->approvals->canAct($this->accounts, $item->fresh()))->toBeFalse();
    $this->workflow->approve($item->fresh(), $this->operations);
    expect($item->fresh()->isUsable())->toBeTrue();
});

it('uses configured approval levels instead of the default routing', function () {
    (new ApprovalLevel(['document_type' => 'item', 'level' => 1, 'approver_role' => 'Approver']))->forceFill(['company_id' => $this->acme->id])->save();
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');

    expect($this->approvals->canAct($this->approver, $item))->toBeTrue()->and($this->approvals->canAct($this->accounts, $item))->toBeFalse();
});

it('keeps another company’s routing out of it', function () {
    app(SettingsService::class)->set('approvals.item_approver', 'operations', $this->globex->id);
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Acme item']], 'inventory');

    expect($item->defaultApproverRole())->toBe('Accounts User');
});

// ======================================================================================================
// Who may do what at each stage (policy)
// ======================================================================================================

it('gives each department only its own stage', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'P']]);
    $inventory = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'I']], 'inventory');
    $submitted = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'S']], 'submitted');
    $approved = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'A']], 'approved');

    $may = fn ($user, string $ability, Item $item) => $user->can($ability, $item);

    // Purchase: stage 1 only
    expect($may($this->purchase, 'editPurchase', $draft))->toBeTrue()->and($may($this->purchase, 'sendToInventory', $draft))->toBeTrue()
        ->and($may($this->purchase, 'editPurchase', $inventory))->toBeFalse()->and($may($this->purchase, 'editInventory', $inventory))->toBeFalse()
        ->and($may($this->purchase, 'update', $submitted))->toBeFalse()->and($may($this->purchase, 'update', $approved))->toBeFalse();

    // Inventory: stage 2 only
    expect($may($this->inventory, 'editInventory', $inventory))->toBeTrue()->and($may($this->inventory, 'submit', $inventory))->toBeTrue()
        ->and($may($this->inventory, 'returnToPurchase', $inventory))->toBeTrue()
        ->and($may($this->inventory, 'update', $draft))->toBeFalse()->and($may($this->inventory, 'submit', $draft))->toBeFalse()
        ->and($may($this->inventory, 'update', $submitted))->toBeFalse();

    // Accounts: decides items awaiting approval, edits nothing
    expect($may($this->accounts, 'approve', $submitted))->toBeTrue()->and($may($this->accounts, 'approve', $draft))->toBeFalse()
        ->and($may($this->accounts, 'approve', $approved))->toBeFalse()
        ->and($may($this->accounts, 'update', $draft))->toBeFalse()->and($may($this->accounts, 'update', $inventory))->toBeFalse();
});

it('lets Operations view and comment on an item, and nothing else', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');

    expect($this->operations->can('view', $item))->toBeTrue()->and($this->operations->can('comment', $item))->toBeTrue();

    foreach (['update', 'editPurchase', 'editInventory', 'sendToInventory', 'returnToPurchase', 'submit', 'approve', 'reopen', 'changeStatus', 'delete'] as $ability) {
        expect($this->operations->can($ability, $item))->toBeFalse("Operations must not be able to {$ability}");
    }
    expect($this->operations->can('create', Item::class))->toBeFalse();
});

it('lets the sales-side roles read items and nothing more', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'approved');

    // Tele-callers read items too (they look them up while talking to customers): a Phase 1 default
    foreach (['Sales Manager', 'BDE / Field Salesperson', 'Tele-caller'] as $role) {
        $user = makeUser([$role], [$this->acme]);

        expect($user->can('view', $item))->toBeTrue("$role should read items")
            ->and($user->can('update', $item))->toBeFalse()->and($user->can('create', Item::class))->toBeFalse()
            ->and($user->can('approve', $item))->toBeFalse()->and($user->can('comment', $item))->toBe(false);
    }
});

it('only lets the people who run a stage re-open an approved item to it', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'approved');
    $can = fn ($user, ?ItemStage $to) => $user->can('reopen', [$item, $to]);

    expect($can($this->purchase, ItemStage::PurchaseDraft))->toBeTrue()->and($can($this->purchase, ItemStage::InventoryPending))->toBeFalse()
        ->and($can($this->inventory, ItemStage::InventoryPending))->toBeTrue()->and($can($this->inventory, ItemStage::PurchaseDraft))->toBeFalse()
        ->and($can($this->admin, ItemStage::PurchaseDraft))->toBeTrue()->and($can($this->admin, ItemStage::InventoryPending))->toBeTrue()
        ->and($can($this->accounts, null))->toBeFalse()->and($can($this->operations, null))->toBeFalse()
        ->and($this->purchase->can('reopen', makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Not approved']])))->toBeFalse();
});

it('only deletes an item that was never approved, and only for those allowed to delete', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Delete me']]);
    $reopened = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Once approved']], 'approved');
    $this->workflow->reopen($reopened, $this->admin, 'Rework', ItemStage::PurchaseDraft);

    expect($this->admin->can('delete', $draft))->toBeTrue()->and($this->purchase->can('delete', $draft))->toBeFalse()   // Purchase may not delete
        ->and($this->admin->can('delete', $reopened->fresh()))->toBeFalse();                                            // it was approved once
});

it('keeps every other company’s items out of reach of the policy', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');
    $foreignAccounts = makeUser(['Accounts User'], [$this->globex]);
    $foreignAdmin = makeUser(['Company Admin'], [$this->globex]);

    foreach (['view', 'update', 'approve', 'reopen', 'changeStatus'] as $ability) {
        expect($foreignAccounts->can($ability, $item))->toBeFalse()->and($foreignAdmin->can($ability, $item))->toBeFalse();
    }
});

// ======================================================================================================
// Look-up: only approved, active items
// ======================================================================================================

it('finds only approved, active, undeleted items of the current company', function () {
    $usable = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Usable bolt'], 'aliases' => [['id' => '', 'alias' => 'fastener']]], 'approved');
    $unusable = [
        'draft' => makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Draft bolt']]),
        'inventory' => makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Inventory bolt']], 'inventory'),
        'submitted' => makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Submitted bolt']], 'submitted'),
    ];
    $rejected = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Rejected bolt']], 'submitted');
    $this->workflow->reject($rejected->fresh(), $this->accounts, 'No', ItemStage::PurchaseDraft);
    $inactive = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Inactive bolt']], 'approved');
    $inactive->forceFill(['status' => 'inactive'])->save();
    $reopened = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Reopened bolt']], 'approved');
    $this->workflow->reopen($reopened, $this->admin, 'Rework', ItemStage::InventoryPending);
    $deleted = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Deleted bolt']], 'approved');
    $deleted->delete();
    $foreign = makeItem($this->globex, makeUser(['Purchase User'], [$this->globex]), ['item' => ['name' => 'Foreign bolt']], 'approved');

    $this->actingAs($this->operations);
    $lookup = app(ItemLookup::class);

    expect($lookup->search()->pluck('id')->all())->toBe([$usable->id])
        ->and($lookup->usable($usable->id))->not->toBeNull();

    foreach ([...$unusable, 'rejected' => $rejected, 'inactive' => $inactive, 'reopened' => $reopened, 'deleted' => $deleted, 'foreign' => $foreign] as $label => $item) {
        expect($lookup->usable($item->id))->toBeNull("{$label} must not be usable");
    }
});

it('finds items by code, name, alias or HSN and presents their units', function () {
    $bolt = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Hex Bolt', 'hsn_code' => '7318'], 'aliases' => [['id' => '', 'alias' => 'fastener']], 'inventory' => ['code' => 'BOLT-1']], 'approved');
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Copper Wire', 'hsn_code' => '7408'], 'inventory' => ['code' => 'WIRE-1']], 'approved');

    $this->actingAs($this->operations);
    $names = fn (string $q) => app(ItemLookup::class)->search($q)->pluck('name')->all();

    expect($names('bolt'))->toBe(['Hex Bolt'])->and($names('BOLT-1'))->toBe(['Hex Bolt'])->and($names('fasten'))->toBe(['Hex Bolt'])
        ->and($names('7408'))->toBe(['Copper Wire'])->and($names(''))->toBe(['Copper Wire', 'Hex Bolt'])->and($names('nothing'))->toBe([]);

    $present = app(ItemLookup::class)->present(app(ItemLookup::class)->usable($bolt->id));
    expect($present)->toMatchArray(['id' => $bolt->id, 'code' => 'BOLT-1', 'name' => 'Hex Bolt', 'hsn_code' => '7318', 'gst_rate' => '18.00', 'po_tolerance_percent' => '5.00'])
        ->and($present['base_unit']['code'])->toBe('NOS')->and($present['units'])->toHaveCount(1)->and($present['units'][0])->toMatchArray(['code' => 'BOX', 'factor' => '100.000000']);
});

it('treats % and _ in what is typed as ordinary characters', function () {
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Plain']], 'approved');
    $odd = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Grade 100% steel']], 'approved');

    $this->actingAs($this->operations);

    expect(app(ItemLookup::class)->search('%')->pluck('id')->all())->toBe([$odd->id])->and(app(ItemLookup::class)->search('_')->pluck('id')->all())->toBe([]);
});
