<?php

use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Http\Livewire\Items\ItemForm;
use App\Modules\Masters\Http\Livewire\Items\ItemList;
use App\Modules\Masters\Http\Livewire\Items\ItemShow;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\ItemService;
use App\Modules\Masters\Services\ItemWorkflow;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Http\Livewire\Comments;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->inventory = makeUser(['Inventory User'], [$this->acme]);
    $this->ahdInventory = makeUser(['Inventory User'], [$this->acme], [$this->ahd]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
});

// ======================================================================================================
// Who can open what
// ======================================================================================================

it('lets each department open only the screens that are its turn', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'In Purchase']]);
    $inventory = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'In Inventory']], 'inventory');
    $submitted = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Waiting']], 'submitted');

    $status = fn ($user, string $route, $item = null) => $this->actingAs($user)->get($item ? route($route, $item) : route($route))->status();

    // Purchase starts items and edits them while they are in Purchase
    expect($status($this->purchase, 'masters.items.create'))->toBe(200)->and($status($this->purchase, 'masters.items.edit', $draft))->toBe(200)
        ->and($status($this->purchase, 'masters.items.edit', $inventory))->toBe(403)->and($status($this->purchase, 'masters.items.edit', $submitted))->toBe(403);

    // Inventory completes them in its stage only, and does not start items
    expect($status($this->inventory, 'masters.items.edit', $inventory))->toBe(200)->and($status($this->inventory, 'masters.items.edit', $draft))->toBe(403)
        ->and($status($this->inventory, 'masters.items.create'))->toBe(403)->and($status($this->inventory, 'masters.items.edit', $submitted))->toBe(403);

    // Accounts approves; it neither starts nor edits
    expect($status($this->accounts, 'masters.items.show', $submitted))->toBe(200)->and($status($this->accounts, 'masters.items.create'))->toBe(403)
        ->and($status($this->accounts, 'masters.items.edit', $draft))->toBe(403)->and($status($this->accounts, 'masters.items.edit', $inventory))->toBe(403);

    // The Company Admin holds both stage permissions, and still only gets the stage the item is in
    expect($status($this->admin, 'masters.items.edit', $draft))->toBe(200)->and($status($this->admin, 'masters.items.edit', $inventory))->toBe(200)
        ->and($status($this->admin, 'masters.items.edit', $submitted))->toBe(403);
});

it('lets Operations view items and comment on them, but every attempt to change one is refused', function () {
    $items = [
        'draft' => makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Op draft']]),
        'inventory' => makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Op inventory']], 'inventory'),
        'submitted' => makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Op submitted']], 'submitted'),
        'approved' => makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Op approved']], 'approved'),
    ];

    $this->actingAs($this->operations)->get(route('masters.items.index'))->assertOk();
    $this->actingAs($this->operations)->get(route('masters.items.create'))->assertForbidden();

    foreach ($items as $stage => $item) {
        $this->actingAs($this->operations)->get(route('masters.items.show', $item))->assertOk();
        $this->actingAs($this->operations)->get(route('masters.items.edit', $item))->assertForbidden();

        // through the components too, not only through the URL
        Livewire::actingAs($this->operations)->test(ItemForm::class, ['editing' => $item])->assertForbidden();
        // (a refused action leaves a component unusable, so each attempt gets a fresh one)
        foreach (['sendToInventory', 'submitForApproval', 'toggleStatus', 'delete'] as $action) {
            Livewire::actingAs($this->operations)->test(ItemShow::class, ['item' => $item])->call($action)->assertForbidden();
        }
        foreach (['approve', 'reject', 'reopen', 'return'] as $dialog) {
            Livewire::actingAs($this->operations)->test(ItemShow::class, ['item' => $item])->call('openDialog', $dialog)->assertForbidden();
        }

        // and it can comment
        Livewire::actingAs($this->operations)->test(Comments::class, ['type' => 'item', 'id' => $item->id])->set('body', "Looks fine at {$stage}")->call('add')->assertHasNoErrors();
    }

    expect(Comment::query()->count())->toBe(4)->and($items['approved']->fresh()->name)->toBe('Op approved')
        ->and($items['draft']->fresh()->workflow_stage)->toBe(ItemStage::PurchaseDraft);
});

it('shows Operations no buttons that would change the item', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');

    Livewire::actingAs($this->operations)->test(ItemShow::class, ['item' => $item])
        ->assertSee('Waiting for approval')->assertSee('cannot change or approve it')
        // the buttons themselves ("Approve" alone would also match the "Approved" step on the progress tracker)
        ->assertDontSeeHtml("openDialog('approve')")->assertDontSeeHtml("openDialog('reject')")->assertDontSeeHtml("openDialog('reopen')")->assertDontSeeHtml("openDialog('return')")
        ->assertDontSeeHtml('wire:click="submitForApproval"')->assertDontSeeHtml('wire:click="sendToInventory"')
        ->assertDontSeeHtml('wire:click="toggleStatus"')->assertDontSeeHtml('wire:click="delete"');
});

it('keeps guests out and other companies’ items unreachable', function () {
    $item = makeItem($this->acme, $this->purchase);

    $this->get(route('masters.items.index'))->assertRedirect(route('login'));
    $this->get(route('masters.items.show', $item))->assertRedirect(route('login'));
    $this->get(route('masters.items.lookup'))->assertRedirect(route('login'));
});

it('does not let anyone see another company’s item', function () {
    $item = makeItem($this->acme, $this->purchase);

    foreach (['masters.items.show', 'masters.items.edit'] as $route) {
        $this->actingAs($this->globexAdmin)->get(route($route, $item))->assertNotFound();
    }

    Livewire::actingAs($this->globexAdmin)->test(ItemForm::class, ['editing' => $item])->assertForbidden();
    Livewire::actingAs($this->globexAdmin)->test(ItemShow::class, ['item' => $item])->assertForbidden();
});

it('lists Items in the sidebar for those who can see them', function () {
    $link = 'href="'.route('masters.items.index').'"';

    $this->actingAs($this->operations)->get(route('dashboard'))->assertSeeHtml($link);
    $this->actingAs($this->telecaller)->get(route('dashboard'))->assertSeeHtml($link);                    // Tele-callers look items up
    $this->actingAs(makeUser([], [$this->acme]))->get(route('dashboard'))->assertDontSeeHtml($link);      // someone with no rights does not even see the link
});

// ======================================================================================================
// The Purchase form
// ======================================================================================================

it('starts an item through the Purchase form and lands on its page, still a draft', function () {
    $component = Livewire::actingAs($this->purchase)->test(ItemForm::class)
        ->set('item.name', 'MS Hex Bolt M10')->set('item.item_group_id', (string) itemGroupId($this->acme))->set('item.base_unit_id', (string) unitId($this->acme, 'NOS'))
        ->set('item.hsn_code', '7318')->set('item.gst_rate', '18')->set('item.po_tolerance_percent', '5')
        ->call('addUom')->set('uoms.0.unit_id', (string) unitId($this->acme, 'BOX'))->set('uoms.0.factor', '100')
        ->call('savePurchase')->assertHasNoErrors();

    $item = Item::query()->firstOrFail();
    $component->assertRedirect(route('masters.items.show', $item));

    expect($item)->name->toBe('MS Hex Bolt M10')->workflow_stage->toBe(ItemStage::PurchaseDraft)->created_by->toBe($this->purchase->id)
        ->and($item->uoms()->count())->toBe(1);
});

it('shows what is wrong on the Purchase form, field by field', function () {
    Livewire::actingAs($this->purchase)->test(ItemForm::class)
        ->set('item.hsn_code', '12')->set('item.gst_rate', '17')->call('addUom')->set('uoms.0.factor', '0')
        ->call('savePurchase')->assertHasErrors(['item.name', 'item.item_group_id', 'item.base_unit_id', 'item.hsn_code', 'item.gst_rate', 'uoms.0.unit_id', 'uoms.0.factor']);

    expect(Item::query()->count())->toBe(0);
});

it('previews each unit conversion in words as it is typed', function () {
    Livewire::actingAs($this->purchase)->test(ItemForm::class)
        ->set('item.base_unit_id', (string) unitId($this->acme, 'NOS'))->call('addUom')
        ->set('uoms.0.unit_id', (string) unitId($this->acme, 'BOX'))->set('uoms.0.factor', '12')
        ->assertSee('1 Box = 12 Numbers');
});

it('caps the number of unit rows, and removes them', function () {
    $component = Livewire::actingAs($this->purchase)->test(ItemForm::class);

    for ($i = 0; $i < 15; $i++) {
        $component->call('addUom');
    }
    $component->assertCount('uoms', 10)->call('removeUom', 0)->assertCount('uoms', 9);
});

it('does not let the browser change which section it edits, or which item', function () {
    $item = makeItem($this->acme, $this->purchase);
    $component = Livewire::actingAs($this->purchase)->test(ItemForm::class, ['editing' => $item]);

    foreach (['section' => 'inventory', 'itemId' => 999, 'approvedBefore' => true] as $property => $value) {
        expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

it('does not let the Purchase screen save the Inventory part, nor the reverse', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'D']]);
    $inventory = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'I']], 'inventory');

    Livewire::actingAs($this->purchase)->test(ItemForm::class, ['editing' => $draft])->set('inventory.code', 'HACK-1')->call('saveInventory')->assertForbidden();
    Livewire::actingAs($this->inventory)->test(ItemForm::class, ['editing' => $inventory])->set('item.name', 'Renamed')->call('savePurchase')->assertForbidden();

    expect($draft->fresh()->code)->toBeNull()->and($inventory->fresh()->name)->toBe('I');
});

it('refuses a save that arrives after the item has moved on', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Moves on']]);
    $component = Livewire::actingAs($this->purchase)->test(ItemForm::class, ['editing' => $item])->set('item.description', 'late change');

    app(ItemWorkflow::class)->sendToInventory($item->fresh(), $this->purchase);   // someone else hands it over meanwhile

    $component->call('savePurchase')->assertForbidden();
    expect($item->fresh()->description)->toBeNull();
});

it('locks the base unit on the form once the item has been approved', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Locked unit']], 'approved');
    app(ItemWorkflow::class)->reopen($item, $this->admin, 'Rework', ItemStage::PurchaseDraft);

    Livewire::actingAs($this->purchase)->test(ItemForm::class, ['editing' => $item->fresh()])
        ->assertSet('approvedBefore', true)->assertSee('has been approved before')->assertSeeHtml('disabled')
        ->set('item.base_unit_id', (string) unitId($this->acme, 'KG'))->call('savePurchase')->assertHasErrors(['item.base_unit_id']);

    expect($item->fresh()->base_unit_id)->toBe(unitId($this->acme, 'NOS'));
});

// ======================================================================================================
// The Inventory form
// ======================================================================================================

it('fills in the Inventory part through its form', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Washer']], 'inventory');
    $item->forceFill(['code' => null])->save();

    Livewire::actingAs($this->inventory)->test(ItemForm::class, ['editing' => $item->fresh()])
        ->assertSet('section', 'inventory')->assertSee('From Purchase')->assertSee('1 Box = 100 Numbers')
        ->call('suggestCode')->assertSet('inventory.code', 'ITM-00001')
        ->call('addAlias')->set('aliases.0.alias', 'flat washer')
        ->call('addLocation')->set('locations.0.location_id', (string) $this->ahd->id)->set('locations.0.minimum_stock', '10')->set('locations.0.reorder_level', '25')->set('locations.0.maximum_stock', '100')
        ->call('saveInventory')->assertHasNoErrors();

    expect($item->fresh())->code->toBe('ITM-00001')->and($item->aliases()->pluck('alias')->all())->toBe(['flat washer'])
        ->and($item->locationParameters()->first())->reorder_level->toBe('25.000');
});

it('shows stock-level mistakes on the row they are on', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Levels']], 'inventory');

    Livewire::actingAs($this->inventory)->test(ItemForm::class, ['editing' => $item])
        ->call('addLocation')->set('locations.0.location_id', (string) $this->ahd->id)
        ->set('locations.0.minimum_stock', '50')->set('locations.0.reorder_level', '20')->set('locations.0.maximum_stock', '10')
        ->call('saveInventory')->assertHasErrors(['locations.0.minimum_stock', 'locations.0.reorder_level'])
        ->assertSee('minimum stock cannot be more than the reorder level');
});

it('shows a branch-restricted user only their branch, and a save leaves the others alone', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Scoped'], 'locations' => [
        ['id' => '', 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '1', 'reorder_level' => '2', 'maximum_stock' => '3'],
        ['id' => '', 'location_id' => (string) $this->mum->id, 'minimum_stock' => '10', 'reorder_level' => '20', 'maximum_stock' => '30'],
    ]], 'inventory');

    $component = Livewire::actingAs($this->ahdInventory)->test(ItemForm::class, ['editing' => $item])
        ->assertCount('locations', 1)->assertSet('locations.0.location_id', (string) $this->ahd->id)->assertDontSee('Mumbai')
        ->set('locations.0.maximum_stock', '9')->call('saveInventory')->assertHasNoErrors();

    expect($item->locationParameters()->count())->toBe(2)
        ->and($item->locationParameters()->where('location_id', $this->mum->id)->first())->maximum_stock->toBe('30.000')
        ->and($item->locationParameters()->where('location_id', $this->ahd->id)->first())->maximum_stock->toBe('9.000');
});

it('adds, removes and caps alias and branch rows', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Rows']], 'inventory');
    $component = Livewire::actingAs($this->inventory)->test(ItemForm::class, ['editing' => $item]);

    for ($i = 0; $i < 25; $i++) {
        $component->call('addAlias');
    }
    $component->assertCount('aliases', 20)->call('removeAlias', 0)->assertCount('aliases', 19);

    $component->call('addLocation')->assertCount('locations', 1)->call('removeLocation', 0)->assertCount('locations', 0);
});

// ======================================================================================================
// The item page and its workflow buttons
// ======================================================================================================

it('drives the whole workflow from the item page, each person doing their step', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'By the book']]);

    Livewire::actingAs($this->purchase)->test(ItemShow::class, ['item' => $item])->assertSee('Send to Inventory')->assertDontSee('Submit for approval')
        ->call('sendToInventory')->assertHasNoErrors();
    expect($item->fresh()->workflow_stage)->toBe(ItemStage::InventoryPending);

    app(ItemService::class);
    inCompany($this->acme, fn () => app(ItemService::class)->saveInventoryStage($item->fresh(), inventoryInput('By the book'), $this->inventory));

    Livewire::actingAs($this->inventory)->test(ItemShow::class, ['item' => $item->fresh()])->assertSee('Submit for approval')->assertSee('Return to Purchase')
        ->call('submitForApproval')->assertHasNoErrors();
    expect($item->fresh()->workflow_stage)->toBe(ItemStage::ApprovalPending);

    Livewire::actingAs($this->accounts)->test(ItemShow::class, ['item' => $item->fresh()])->assertSee('Waiting for approval')->assertSee('Approve')
        ->call('openDialog', 'approve')->set('comment', 'All good')->call('approve')->assertHasNoErrors();

    expect($item->fresh())->workflow_stage->toBe(ItemStage::Approved)->isUsable()->toBeTrue();
});

it('says what is missing instead of sending an incomplete item on', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['hsn_code' => '', 'gst_rate' => '']]);

    Livewire::actingAs($this->purchase)->test(ItemShow::class, ['item' => $item])
        ->assertSee('it still needs')->assertSee('an HSN code; a GST rate')
        ->call('sendToInventory')->assertHasErrors(['workflow'])->assertSee('Complete the Purchase details');

    expect($item->fresh()->workflow_stage)->toBe(ItemStage::PurchaseDraft);
});

it('returns an item to Purchase with a reason', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'inventory');

    Livewire::actingAs($this->inventory)->test(ItemShow::class, ['item' => $item])
        ->call('openDialog', 'return')->assertSet('dialog', 'return')
        ->set('comment', '')->call('returnToPurchase')->assertHasErrors(['comment'])
        ->set('comment', 'Base unit should be Kg')->call('returnToPurchase')->assertHasNoErrors();

    expect($item->fresh()->workflow_stage)->toBe(ItemStage::PurchaseDraft);
});

it('rejects with a reason and the stage to send it back to, and shows the reason to whoever picks it up', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'To be rejected']], 'submitted');

    $show = Livewire::actingAs($this->accounts)->test(ItemShow::class, ['item' => $item])
        ->call('openDialog', 'reject')->assertSee('Send it back to')
        ->set('comment', '')->call('reject')->assertHasErrors(['comment'])
        ->set('comment', 'HSN does not match the group')->set('returnTo', 'purchase_draft')->call('reject')->assertHasNoErrors();

    expect($item->fresh())->workflow_stage->toBe(ItemStage::PurchaseDraft)->approval_status->toBe(ApprovalStatus::Rejected);

    Livewire::actingAs($this->purchase)->test(ItemShow::class, ['item' => $item->fresh()])
        ->assertSee('Returned to Purchase')->assertSee('Sent back to')->assertSee('HSN does not match the group')->assertSee('Edit Purchase details');
});

it('refuses a tampered return stage', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');

    Livewire::actingAs($this->accounts)->test(ItemShow::class, ['item' => $item])
        ->call('openDialog', 'reject')->set('comment', 'No')->set('returnTo', 'approved')->call('reject');

    // the item is exactly where it was: nothing was rejected, nothing was approved
    expect($item->fresh())->workflow_stage->toBe(ItemStage::ApprovalPending)->approval_status->toBe(ApprovalStatus::Pending)
        ->and(Approval::query()->where('approvable_id', $item->id)->where('status', 'rejected')->count())->toBe(0);
});

it('only shows the approval buttons to the approver the item is routed to', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'submitted');
    $approverRole = makeUser(['Approver'], [$this->acme]);

    Livewire::actingAs($this->accounts)->test(ItemShow::class, ['item' => $item])->assertSee('Reject…');
    Livewire::actingAs($approverRole)->test(ItemShow::class, ['item' => $item])->assertDontSee('Reject…')->assertSee('not be the designated approver');
    Livewire::actingAs($this->admin)->test(ItemShow::class, ['item' => $item])->assertDontSee('Reject…');
});

it('re-opens an approved item to the stage chosen, only offering the stages the person runs', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Re-open me']], 'approved');

    Livewire::actingAs($this->purchase)->test(ItemShow::class, ['item' => $item])
        ->call('openDialog', 'reopen')->assertSet('returnTo', 'purchase_draft')->assertSee('Purchase — name')->assertDontSee('Inventory — code')
        ->set('comment', 'Fix the GST rate')->call('reopen')->assertHasNoErrors();

    expect($item->fresh())->workflow_stage->toBe(ItemStage::PurchaseDraft)->and($item->fresh()->isUsable())->toBeFalse();

    $second = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Re-open me too']], 'approved');
    Livewire::actingAs($this->purchase)->test(ItemShow::class, ['item' => $second])->set('returnTo', 'inventory_pending')->set('comment', 'Not mine to reopen to Inventory')->call('reopen')->assertForbidden();
});

it('switches an approved item off and on without re-approval', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'approved');

    Livewire::actingAs($this->inventory)->test(ItemShow::class, ['item' => $item])->call('toggleStatus');
    expect($item->fresh())->status->toBe('inactive')->approval_status->toBe(ApprovalStatus::Approved)->and($item->fresh()->isUsable())->toBeFalse();

    Livewire::actingAs($this->inventory)->test(ItemShow::class, ['item' => $item->fresh()])->call('toggleStatus');
    expect($item->fresh()->isUsable())->toBeTrue();
});

it('deletes a draft but never an item that was approved', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Draft']]);
    $used = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Used']], 'approved');
    app(ItemWorkflow::class)->reopen($used, $this->admin, 'Rework', ItemStage::PurchaseDraft);

    Livewire::actingAs($this->purchase)->test(ItemShow::class, ['item' => $draft])->call('delete')->assertForbidden();   // Purchase holds no delete right
    Livewire::actingAs($this->admin)->test(ItemShow::class, ['item' => $draft])->call('delete')->assertRedirect(route('masters.items.index'));
    Livewire::actingAs($this->admin)->test(ItemShow::class, ['item' => $used->fresh()])->call('delete')->assertForbidden();

    expect(Item::query()->whereKey($draft->id)->exists())->toBeFalse()->and(Item::query()->whereKey($used->id)->exists())->toBeTrue();
});

it('shows the stage history and the approval decisions on the page', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Documented']], 'submitted');
    app(ItemWorkflow::class)->reject($item->fresh(), $this->accounts, 'Fix the alias', ItemStage::InventoryPending);

    Livewire::actingAs($this->operations)->test(ItemShow::class, ['item' => $item->fresh()])
        ->assertSee('Stage history')->assertSee('Started by Purchase')->assertSee('Sent to Inventory')->assertSee('Submitted for approval')
        ->assertSee('Rejected — returned to Inventory')->assertSee('Fix the alias')->assertSee('Approval decisions')->assertSee('Round 1');
});

// ======================================================================================================
// The list and the look-up endpoint
// ======================================================================================================

it('lists items and filters them by stage, group, status and search', function () {
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Alpha draft', 'hsn_code' => '1111']]);
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Beta inventory', 'item_group_id' => (string) itemGroupId($this->acme, 'PKG', 'Packing')], 'aliases' => [['id' => '', 'alias' => 'carton']]], 'inventory');
    $approved = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Gamma approved', 'hsn_code' => '7318']], 'approved');
    $returned = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Delta returned']], 'submitted');
    app(ItemWorkflow::class)->reject($returned->fresh(), $this->accounts, 'No', ItemStage::PurchaseDraft);
    $approved->forceFill(['status' => 'inactive'])->save();
    makeItem($this->globex, makeUser(['Purchase User'], [$this->globex]), ['item' => ['name' => 'Foreign only']]);

    $packing = itemGroupId($this->acme, 'PKG', 'Packing');

    Livewire::actingAs($this->operations)->test(ItemList::class)
        ->assertSee('Alpha draft')->assertSee('Beta inventory')->assertSee('Gamma approved')->assertSee('Delta returned')->assertDontSee('Foreign only')
        ->set('stage', 'inventory_pending')->assertSee('Beta inventory')->assertDontSee('Alpha draft')
        ->set('stage', 'returned')->assertSee('Delta returned')->assertSee('Returned to Purchase')->assertDontSee('Beta inventory')
        ->set('stage', '')->set('group', (string) $packing)->assertSee('Beta inventory')->assertDontSee('Alpha draft')
        ->set('group', '')->set('search', 'carton')->assertSee('Beta inventory')->assertDontSee('Alpha draft')       // by alias
        ->set('search', '7318')->assertSee('Gamma approved')->assertDontSee('Alpha draft')                          // by HSN
        ->set('search', '')->set('status', 'inactive')->assertSee('Gamma approved')->assertDontSee('Alpha draft')
        ->set('status', '')->set('search', "%_\\' OR 1=1 --")->assertOk();
});

it('lets those who may change an item do it from the list, and nobody else', function () {
    $item = makeItem($this->acme, $this->purchase, [], 'approved');

    Livewire::actingAs($this->inventory)->test(ItemList::class)->call('toggleStatus', $item->id);
    expect($item->fresh()->status)->toBe('inactive');

    Livewire::actingAs($this->operations)->test(ItemList::class)->call('toggleStatus', $item->id)->assertForbidden();
    Livewire::actingAs($this->accounts)->test(ItemList::class)->call('toggleStatus', $item->id)->assertForbidden();
});

it('serves the item look-up as JSON: approved items only, with their units', function () {
    $bolt = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Hex Bolt'], 'aliases' => [['id' => '', 'alias' => 'fastener']], 'inventory' => ['code' => 'BOLT-1']], 'approved');
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Hex Draft']]);
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Hex Waiting']], 'submitted');

    $response = $this->actingAs($this->operations)->getJson(route('masters.items.lookup', ['q' => 'hex']))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0'))->toMatchArray(['id' => $bolt->id, 'code' => 'BOLT-1', 'name' => 'Hex Bolt', 'hsn_code' => '7318', 'gst_rate' => '18.00'])
        ->and($response->json('data.0.units.0'))->toMatchArray(['code' => 'BOX', 'factor' => '100.000000'])
        ->and($this->getJson(route('masters.items.lookup', ['q' => 'fastener']))->json('data.0.name'))->toBe('Hex Bolt');
});

it('validates the item look-up, limits who may use it and never takes the company from the request', function () {
    makeItem($this->globex, makeUser(['Purchase User'], [$this->globex]), ['item' => ['name' => 'Globex bolt']], 'approved');
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Acme bolt']], 'approved');

    $this->actingAs($this->operations)->getJson(route('masters.items.lookup', ['limit' => 500]))->assertStatus(422)->assertJsonValidationErrors('limit');
    $this->actingAs($this->operations)->getJson(route('masters.items.lookup', ['q' => str_repeat('x', 101)]))->assertStatus(422);

    $names = collect($this->actingAs($this->operations)->getJson(route('masters.items.lookup', ['company_id' => $this->globex->id]))->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Acme bolt']);

    $this->app['auth']->forgetGuards();
    $this->getJson(route('masters.items.lookup'))->assertUnauthorized();
});

it('keeps unapproved items out of the look-up even for someone who can see them', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Hidden draft']]);

    $this->actingAs($this->purchase)->get(route('masters.items.show', $draft))->assertOk();
    expect($this->getJson(route('masters.items.lookup', ['q' => 'hidden']))->json('data'))->toBe([]);

    // the id of an unapproved item is no more usable than its name
    expect(app(ItemLookup::class)->usable($draft->id))->toBeNull();
});

it('keeps the tenant out of the model: the item scope follows the signed-in company', function () {
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Acme item']]);
    makeItem($this->globex, makeUser(['Purchase User'], [$this->globex]), ['item' => ['name' => 'Globex item']]);

    $this->actingAs($this->purchase);

    expect(Item::query()->pluck('name')->all())->toBe(['Acme item'])
        ->and(Item::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(2);
});
