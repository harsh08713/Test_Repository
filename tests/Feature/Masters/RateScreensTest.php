<?php

use App\Modules\Masters\Http\Livewire\PriceListList;
use App\Modules\Masters\Http\Livewire\Rates\RateForm;
use App\Modules\Masters\Http\Livewire\Rates\RateList;
use App\Modules\Masters\Http\Livewire\Rates\RateShow;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Http\Livewire\ApprovalPanel;
use App\Modules\Shared\Http\Livewire\Comments;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->globexBranch = makeLocation($this->globex, 'GLB');

    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->ahdAccounts = makeUser(['Accounts User'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);

    $this->item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'MS Hex Bolt M10']], 'approved');
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Who can open what
// ======================================================================================================

it('lets each role open only the rate screens it may use', function () {
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);
    $approved = makeRate($this->acme, $this->accounts, $this->item, $this->mum, [], 'approved', $this->approver);

    $status = fn ($user, string $route, $rate = null) => $this->actingAs($user)->get($rate ? route($route, $rate) : route($route))->status();

    // Accounts maintains rates: starts them, edits drafts, and cannot edit an approved one
    expect($status($this->accounts, 'masters.rates.index'))->toBe(200)->and($status($this->accounts, 'masters.rates.create'))->toBe(200)
        ->and($status($this->accounts, 'masters.rates.edit', $draft))->toBe(200)->and($status($this->accounts, 'masters.rates.edit', $approved))->toBe(403)
        ->and($status($this->accounts, 'masters.rates.show', $approved))->toBe(200);

    // the Company Admin too
    expect($status($this->admin, 'masters.rates.create'))->toBe(200)->and($status($this->admin, 'masters.rates.edit', $draft))->toBe(200);

    // everyone who works with prices can look, and nobody else can change anything
    foreach ([$this->purchase, $this->operations, $this->manager, $this->approver] as $user) {
        expect($status($user, 'masters.rates.index'))->toBe(200)->and($status($user, 'masters.rates.show', $approved))->toBe(200)
            ->and($status($user, 'masters.rates.create'))->toBe(403)->and($status($user, 'masters.rates.edit', $draft))->toBe(403);
    }

    // a Tele-caller has no business with rates
    foreach (['masters.rates.index', 'masters.rates.create'] as $route) {
        expect($status($this->telecaller, $route))->toBe(403);
    }
    expect($status($this->telecaller, 'masters.rates.show', $approved))->toBe(403);
});

it('keeps guests out and other companies’ rates unreachable', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);

    $this->get(route('masters.rates.index'))->assertRedirect(route('login'));
    $this->get(route('masters.rates.show', $rate))->assertRedirect(route('login'));
    $this->get(route('masters.rates.lookup'))->assertRedirect(route('login'));

    foreach (['masters.rates.show', 'masters.rates.edit'] as $route) {
        $this->actingAs($this->globexAdmin)->get(route($route, $rate))->assertNotFound();
    }
});

it('keeps a branch’s rates to the people who work in that branch', function () {
    $ahdRate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);
    $mumRate = makeRate($this->acme, $this->accounts, $this->item, $this->mum);

    $this->actingAs($this->ahdAccounts)->get(route('masters.rates.show', $ahdRate))->assertOk();
    $this->actingAs($this->ahdAccounts)->get(route('masters.rates.show', $mumRate))->assertNotFound();
    $this->actingAs($this->ahdAccounts)->get(route('masters.rates.edit', $mumRate))->assertNotFound();

    Livewire::actingAs($this->ahdAccounts)->test(RateList::class)->assertSee('AHD Branch')->assertDontSee('MUM Branch');
});

// ======================================================================================================
// The list
// ======================================================================================================

it('lists rates and filters them by branch, price list, approval, status and the day they are in force', function () {
    $dealer = makePriceList($this->acme);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '125.5', 'effective_from' => '2026-04-01', 'effective_to' => '2026-09-30'], 'approved', $this->approver);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '131', 'effective_from' => '2026-10-01'], 'approved', $this->approver);
    makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['rate' => '140', 'price_list_id' => (string) $dealer->id], 'submitted');
    makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['rate' => '99.99', 'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31']);   // a draft

    $list = Livewire::actingAs($this->accounts)->test(RateList::class)
        ->assertSee('MS Hex Bolt M10')->assertSee('125.50')->assertSee('131.00')->assertSee('140.00')->assertSee('99.99');

    $list->set('branch', (string) $this->mum->id)->assertSee('140.00')->assertSee('99.99')->assertDontSee('125.50')
        ->set('branch', '')->set('list', 'standard')->assertDontSee('140.00')->assertSee('131.00')
        ->set('list', (string) $dealer->id)->assertSee('140.00')->assertDontSee('131.00')
        ->set('list', '')->set('approval', 'approved')->assertSee('131.00')->assertDontSee('140.00')->assertDontSee('99.99')
        ->set('approval', 'draft')->assertSee('99.99')->assertDontSee('131.00')
        ->set('approval', '')->set('on', '2026-10-12')->assertSee('131.00')->assertDontSee('125.50')->assertSee('140.00')   // 125.50 ended 30 Sep; 99.99 ended in 2025
        ->set('on', '2026-05-01')->assertSee('125.50')->assertDontSee('131.00')
        ->set('on', 'not a date')->assertSee('125.50')->assertSee('131.00');   // nonsense is ignored, not an error
});

it('searches by the item’s code, name or alias', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '125.5']);
    $other = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Brass Washer'], 'aliases' => [['id' => '', 'alias' => 'shim ring']]], 'approved');
    makeRate($this->acme, $this->accounts, $other, $this->ahd, ['rate' => '2.75']);

    Livewire::actingAs($this->accounts)->test(RateList::class)
        ->set('search', 'Hex')->assertSee('125.50')->assertDontSee('2.75')
        ->set('search', 'shim')->assertSee('2.75')->assertDontSee('125.50')
        ->set('search', $other->code)->assertSee('2.75')->assertDontSee('125.50')
        ->set('search', '%')->assertDontSee('125.50')->assertDontSee('2.75');   // a wildcard is just a character
});

it('shows buttons only for what the person may do', function () {
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);

    Livewire::actingAs($this->accounts)->test(RateList::class)->assertSee('Add rate')->assertSeeHtml('wire:click="toggleStatus('.$draft->id.')"')->assertSee('Edit');
    Livewire::actingAs($this->operations)->test(RateList::class)->assertDontSee('Add rate')->assertDontSeeHtml('wire:click="toggleStatus(')->assertDontSee('Edit');
});

it('lets someone with edit rights switch a rate on and off from the list, and nobody else', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);

    Livewire::actingAs($this->operations)->test(RateList::class)->call('toggleStatus', $rate->id)->assertForbidden();
    expect($rate->fresh()->status)->toBe('active');

    Livewire::actingAs($this->accounts)->test(RateList::class)->call('toggleStatus', $rate->id);
    expect($rate->fresh()->status)->toBe('inactive');

    Livewire::actingAs($this->accounts)->test(RateList::class)->call('toggleStatus', $rate->id);
    expect($rate->fresh()->status)->toBe('active')->and($rate->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});

// ======================================================================================================
// The form
// ======================================================================================================

it('adds a rate through the form, starting from the item’s base unit', function () {
    $form = Livewire::actingAs($this->accounts)->test(RateForm::class)
        ->set('rate.item_id', (string) $this->item->id)
        ->assertSet('rate.unit_id', (string) $this->item->base_unit_id)
        ->set('rate.location_id', (string) $this->ahd->id)
        ->set('rate.rate', '125.50')
        ->set('rate.effective_from', '2026-10-01')
        ->call('save')
        ->assertHasNoErrors();

    $rate = Rate::query()->firstOrFail();

    $form->assertRedirect(route('masters.rates.show', $rate));
    expect($rate->rate)->toBe('125.5000')->and($rate->approval_status)->toBe(ApprovalStatus::Draft)->and($rate->created_by)->toBe($this->accounts->id)
        ->and($rate->company_id)->toBe($this->acme->id);
});

it('offers an item’s own units in the form, and narrows the item list as you type', function () {
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Brass Washer']], 'approved');
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Half-done item']], 'inventory');   // not approved: never offered

    Livewire::actingAs($this->accounts)->test(RateForm::class)
        ->assertSee('MS Hex Bolt M10')->assertSee('Brass Washer')->assertDontSee('Half-done item')
        ->assertSee('Choose the item first')
        ->set('itemSearch', 'brass')->assertSee('Brass Washer')->assertDontSee('MS Hex Bolt M10')
        ->set('itemSearch', '')->set('rate.item_id', (string) $this->item->id)
        ->assertSee('Numbers (NOS) — base unit')->assertSee('Box (BOX) — 1 = 100');
});

it('shows what is wrong, field by field, and saves nothing', function () {
    Livewire::actingAs($this->accounts)->test(RateForm::class)
        ->call('save')
        ->assertHasErrors(['rate.item_id', 'rate.location_id', 'rate.unit_id', 'rate.rate', 'rate.effective_from'])
        ->assertSee('Some details need attention');

    Livewire::actingAs($this->accounts)->test(RateForm::class)
        ->set('rate.item_id', (string) $this->item->id)->set('rate.location_id', (string) $this->ahd->id)
        ->set('rate.rate', '-3')->set('rate.effective_from', '2026-10-01')->set('rate.effective_to', '2026-09-01')
        ->call('save')
        ->assertHasErrors(['rate.rate', 'rate.effective_to'])->assertHasNoErrors(['rate.item_id', 'rate.location_id']);

    expect(Rate::query()->count())->toBe(0);
});

it('refuses an overlapping period on the form and says which rate it clashes with', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01'], 'approved', $this->approver);

    Livewire::actingAs($this->accounts)->test(RateForm::class)
        ->set('rate.item_id', (string) $this->item->id)->set('rate.location_id', (string) $this->ahd->id)
        ->set('rate.rate', '140')->set('rate.effective_from', '2026-12-01')
        ->call('save')
        ->assertHasErrors(['rate.effective_from'])->assertSee('overlap another rate')->assertSee('from 1 Apr 2026');

    expect(Rate::query()->count())->toBe(1);
});

it('ignores forged ids: another company’s item or branch, and a branch the person does not work in', function () {
    $foreign = makeItem($this->globex, makeUser(['Purchase User'], [$this->globex]), [], 'approved');

    Livewire::actingAs($this->accounts)->test(RateForm::class)
        ->set('rate.item_id', (string) $foreign->id)->set('rate.location_id', (string) $this->globexBranch->id)->set('rate.unit_id', (string) $foreign->base_unit_id)
        ->set('rate.rate', '10')->set('rate.effective_from', '2026-10-01')
        ->call('save')->assertHasErrors(['rate.item_id', 'rate.location_id']);

    Livewire::actingAs($this->ahdAccounts)->test(RateForm::class)
        ->set('rate.item_id', (string) $this->item->id)->set('rate.location_id', (string) $this->mum->id)
        ->set('rate.rate', '10')->set('rate.effective_from', '2026-10-01')
        ->call('save')->assertHasErrors(['rate.location_id']);

    expect(Rate::query()->count())->toBe(0);
});

it('edits a draft, but refuses the form for a rate that is locked, someone else’s branch or a person who may not edit', function () {
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);
    $waiting = makeRate($this->acme, $this->accounts, $this->item, $this->mum, [], 'submitted');

    Livewire::actingAs($this->accounts)->test(RateForm::class, ['editing' => $draft])
        ->assertSet('rate.rate', '125.5')->set('rate.rate', '130')->call('save')->assertHasNoErrors();
    expect($draft->fresh()->rate)->toBe('130.0000');

    Livewire::actingAs($this->accounts)->test(RateForm::class, ['editing' => $waiting])->assertForbidden();
    Livewire::actingAs($this->operations)->test(RateForm::class, ['editing' => $draft])->assertForbidden();
    Livewire::actingAs($this->purchase)->test(RateForm::class)->assertForbidden();
});

it('keeps the id of the rate being edited out of the browser’s hands', function () {
    $mine = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);
    $other = makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['effective_from' => '2027-01-01']);

    expect(fn () => Livewire::actingAs($this->accounts)->test(RateForm::class, ['editing' => $mine])->set('rateId', $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('copies a rate as a new draft without its dates', function () {
    $dealer = makePriceList($this->acme);
    $source = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '125.5', 'price_list_id' => (string) $dealer->id, 'remarks' => 'old note'], 'approved', $this->approver);
    $mumbai = makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['effective_from' => '2028-01-01']);

    Livewire::actingAs($this->accounts)->withQueryParams(['copy' => (string) $source->id])->test(RateForm::class)
        ->assertSet('rate.item_id', (string) $this->item->id)->assertSet('rate.location_id', (string) $this->ahd->id)
        ->assertSet('rate.price_list_id', (string) $dealer->id)->assertSet('rate.rate', '125.5')
        ->assertSet('rate.effective_from', '')->assertSet('rate.effective_to', '')->assertSet('rate.remarks', '');

    // a copy of a rate in a branch the person cannot see is not offered
    Livewire::actingAs($this->ahdAccounts)->withQueryParams(['copy' => (string) $mumbai->id])->test(RateForm::class)
        ->assertSet('rate.item_id', '');
});

it('locks who and where an approved rate is for when it is re-opened for correction', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $rate])->call('openDialog', 'reopen')->set('reason', 'Typo in the price')->call('reopen');

    Livewire::actingAs($this->accounts)->test(RateForm::class, ['editing' => $rate->fresh()])
        ->assertSee('has been approved before')
        ->set('rate.location_id', (string) $this->mum->id)->call('save')->assertHasErrors(['rate.location_id']);
});

// ======================================================================================================
// The rate page
// ======================================================================================================

it('shows a rate, whether it is in force today and, if not, why', function () {
    $current = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01'], 'approved', $this->approver);
    $upcoming = makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['effective_from' => '2027-01-01'], 'approved', $this->approver);
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['effective_from' => '2026-04-01', 'effective_to' => '2026-12-31']);

    Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $current])
        ->assertSee('MS Hex Bolt M10')->assertSee('125.50')->assertSee('In force today')->assertSee('excluding GST');
    Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $upcoming])->assertSee('it starts on 1 Jan 2027');
    Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $draft])->assertSee('it is not approved yet')->assertSee('(this rate)');
});

it('lists the other rates for the same item, branch and unit, and only those', function () {
    $first = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '100', 'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31'], 'approved', $this->approver);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '111.11', 'effective_from' => '2026-04-01'], 'approved', $this->approver);
    makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['rate' => '222.22'], 'approved', $this->approver);

    Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $first])->assertSee('111.11')->assertDontSee('222.22');
});

it('lets the approver decide a submitted rate on its page, and not the person who submitted it', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'submitted');

    Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, ['type' => 'rate', 'id' => $rate->id])->assertDontSee('Your decision is needed');

    Livewire::actingAs($this->approver)->test(ApprovalPanel::class, ['type' => 'rate', 'id' => $rate->id])
        ->assertSee('Your decision is needed')->set('comment', 'Matches the price circular')->call('approve');

    expect($rate->fresh()->approval_status)->toBe(ApprovalStatus::Approved)->and($rate->fresh()->approved_by)->toBe($this->approver->id);
});

it('lets the approver reject a rate with a reason, and the maker correct and resubmit it', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'submitted');

    Livewire::actingAs($this->approver)->test(ApprovalPanel::class, ['type' => 'rate', 'id' => $rate->id])->call('reject')->assertHasErrors(['comment']);
    Livewire::actingAs($this->approver)->test(ApprovalPanel::class, ['type' => 'rate', 'id' => $rate->id])->set('comment', 'Freight is not included')->call('reject');

    expect($rate->fresh()->approval_status)->toBe(ApprovalStatus::Rejected);

    Livewire::actingAs($this->accounts)->test(RateForm::class, ['editing' => $rate->fresh()])->set('rate.rate', '131')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, ['type' => 'rate', 'id' => $rate->id])->call('submit');

    expect($rate->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('refuses to submit a draft that has come to overlap a live rate, from the page', function () {
    // the draft was started first; only then did another rate for the same slot go live, so the draft now clashes
    $clash = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2027-01-01', 'rate' => '150']);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01'], 'approved', $this->approver);

    Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $clash])->assertSee('does not overlap another rate');
    Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, ['type' => 'rate', 'id' => $clash->id])->call('submit')->assertHasErrors(['approval']);

    expect($clash->fresh()->approval_status)->toBe(ApprovalStatus::Draft);
});

it('ends an approved rate from its page, with a reason, and records it', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01'], 'approved', $this->approver);

    $page = Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $rate])
        ->call('openDialog', 'end')->assertSee('Last day the rate applies');

    $page->call('endEarly')->assertHasErrors(['on', 'reason']);
    $page->set('endOn', '2026-10-01')->set('reason', 'Wrong day')->call('endEarly')->assertHasErrors(['on']);   // yesterday
    $page->set('endOn', '2026-11-30')->set('reason', 'New price list from December')->call('endEarly')->assertHasNoErrors()->assertRedirect(route('masters.rates.show', $rate));

    expect($rate->fresh()->effective_to->toDateString())->toBe('2026-11-30')->and($rate->fresh()->approval_status)->toBe(ApprovalStatus::Approved);

    Livewire::actingAs($this->admin)->test(RateShow::class, ['rate' => $rate])->assertSee('Ended early')->assertSee('New price list from December');
    Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $rate])->assertDontSee('Ended early');   // Accounts may not read the audit trail
});

it('re-opens an approved rate with a reason and takes the person to the form', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);

    $page = Livewire::actingAs($this->accounts)->test(RateShow::class, ['rate' => $rate])->call('openDialog', 'reopen')->assertSee('leaves a gap in pricing');
    $page->call('reopen')->assertHasErrors(['reason']);
    $page->set('reason', 'Typed the wrong price')->call('reopen')->assertRedirect(route('masters.rates.edit', $rate));

    expect($rate->fresh()->approval_status)->toBe(ApprovalStatus::Draft);
});

it('refuses every change to a rate from someone who may only look', function () {
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);
    $approved = makeRate($this->acme, $this->accounts, $this->item, $this->mum, [], 'approved', $this->approver);

    foreach ([$this->operations, $this->purchase, $this->manager] as $user) {
        foreach (['toggleStatus', 'delete'] as $action) {
            Livewire::actingAs($user)->test(RateShow::class, ['rate' => $draft])->call($action)->assertForbidden();
        }
        foreach (['reopen', 'end'] as $dialog) {
            Livewire::actingAs($user)->test(RateShow::class, ['rate' => $approved])->call('openDialog', $dialog)->assertForbidden();
        }
        Livewire::actingAs($user)->test(RateShow::class, ['rate' => $approved])
            ->assertDontSeeHtml('wire:click="toggleStatus"')->assertDontSeeHtml("openDialog('end')")->assertDontSeeHtml("openDialog('reopen')")->assertDontSee('Copy as new rate');
    }

    expect($draft->fresh()->status)->toBe('active')->and(Rate::query()->count())->toBe(2);
});

it('deletes a rate that was never approved, and refuses to delete one that was', function () {
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);
    $approved = makeRate($this->acme, $this->accounts, $this->item, $this->mum, [], 'approved', $this->approver);

    Livewire::actingAs($this->admin)->test(RateShow::class, ['rate' => $draft])->call('delete')->assertRedirect(route('masters.rates.index'));
    expect(Rate::query()->find($draft->id))->toBeNull();

    // approved: locked, so not even offered a button, and the action itself refuses
    Livewire::actingAs($this->admin)->test(RateShow::class, ['rate' => $approved])->assertDontSeeHtml('wire:click="delete"')->call('delete')->assertForbidden();
    expect(Rate::query()->find($approved->id))->not->toBeNull();

    // approved once and then re-opened: it is a draft again, but it may already have been used, so it can only be switched off
    inCompany($this->acme, fn () => app(ApprovalService::class)->reopen($approved->fresh(), $this->accounts, 'Correction'));
    Livewire::actingAs($this->admin)->test(RateShow::class, ['rate' => $approved->fresh()])->assertDontSeeHtml('wire:click="delete"')->call('delete')->assertHasErrors(['delete']);
    expect(Rate::query()->find($approved->id))->not->toBeNull();
});

it('lets the people who work on rates comment on them, and leaves view-only roles to read', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);

    foreach ([$this->accounts, $this->approver] as $user) {
        Livewire::actingAs($user)->test(Comments::class, ['type' => 'rate', 'id' => $rate->id])->set('body', 'Is this per piece or per box?')->call('add')->assertHasNoErrors();
    }
    expect(Comment::query()->count())->toBe(2);

    foreach ([$this->operations, $this->manager, $this->purchase] as $user) {
        Livewire::actingAs($user)->test(Comments::class, ['type' => 'rate', 'id' => $rate->id])->set('body', 'Looks odd')->call('add')->assertForbidden();
    }

    expect(Comment::query()->count())->toBe(2)->and($rate->fresh()->rate)->toBe('125.5000');
});

// ======================================================================================================
// The look-up endpoint
// ======================================================================================================

it('serves the rate that applies, as JSON, to anyone who may see rates', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '125.5'], 'approved', $this->approver);
    $query = ['item' => $this->item->id, 'location' => $this->ahd->id, 'unit' => $this->item->base_unit_id, 'date' => '2026-10-12'];

    foreach ([$this->accounts, $this->manager, $this->operations] as $user) {
        $this->actingAs($user)->getJson(route('masters.rates.lookup', $query))->assertOk()
            ->assertJsonPath('data.rate', '125.5000')->assertJsonPath('data.rate_id', $rate->id)->assertJsonPath('data.source', 'standard')
            ->assertJsonPath('data.effective_from', '2026-04-01')->assertJsonPath('data.as_of', '2026-10-12');
    }

    $this->actingAs($this->telecaller)->getJson(route('masters.rates.lookup', $query))->assertForbidden();
});

it('answers 422 with the reason when there is no rate, and never returns an unapproved one', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['rate' => '777.5']);   // a draft
    $query = ['item' => $this->item->id, 'location' => $this->mum->id, 'unit' => $this->item->base_unit_id, 'date' => '2026-10-12'];

    $response = $this->actingAs($this->manager)->getJson(route('masters.rates.lookup', $query));

    $response->assertStatus(422)->assertJsonPath('reason', 'not_approved')->assertJsonMissingPath('data');
    expect($response->getContent())->not->toContain('777');

    $this->actingAs($this->manager)->getJson(route('masters.rates.lookup', [...$query, 'date' => '2026-02-30']))->assertStatus(422)->assertJsonValidationErrors(['date']);
    $this->actingAs($this->manager)->getJson(route('masters.rates.lookup', ['item' => $this->item->id]))->assertStatus(422)->assertJsonValidationErrors(['location', 'unit']);
    $this->actingAs($this->manager)->getJson(route('masters.rates.lookup', [...$query, 'unit' => unitId($this->acme, 'KG')]))->assertStatus(422)->assertJsonPath('reason', 'unit_not_allowed');
});

it('applies the caller’s company and branches to the look-up, whatever ids are sent', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->mum, [], 'approved', $this->approver);
    $query = ['item' => $this->item->id, 'location' => $this->mum->id, 'unit' => $this->item->base_unit_id, 'date' => '2026-10-12'];
    $ahdManager = makeUser(['Sales Manager'], [$this->acme], [$this->ahd]);
    $globexManager = makeUser(['Sales Manager'], [$this->globex]);
    $theirs = makePriceList($this->globex, 'X', 'Theirs');

    $this->actingAs($ahdManager)->getJson(route('masters.rates.lookup', $query))->assertStatus(422)->assertJsonPath('reason', 'location_not_available');
    $this->actingAs($globexManager)->getJson(route('masters.rates.lookup', $query))->assertStatus(422)->assertJsonPath('reason', 'item_not_available');
    $this->actingAs($this->manager)->getJson(route('masters.rates.lookup', [...$query, 'price_list' => $theirs->id]))
        ->assertStatus(422)->assertJsonPath('reason', 'price_list_not_available');
});

// ======================================================================================================
// Price lists and navigation
// ======================================================================================================

it('lets Accounts keep price lists, everyone else only look, and protects a list that has rates', function () {
    $this->actingAs($this->accounts)->get(route('masters.price-lists'))->assertOk();
    $this->actingAs($this->operations)->get(route('masters.price-lists'))->assertOk();
    $this->actingAs($this->telecaller)->get(route('masters.price-lists'))->assertForbidden();

    Livewire::actingAs($this->accounts)->test(PriceListList::class)->call('create')
        ->set('values.code', 'dealer')->set('values.name', 'Dealer price list')->call('save')->assertHasNoErrors();
    $list = PriceList::query()->firstOrFail();
    expect($list->code)->toBe('DEALER')->and($list->company_id)->toBe($this->acme->id);

    Livewire::actingAs($this->operations)->test(PriceListList::class)->call('create')->assertForbidden();
    Livewire::actingAs($this->operations)->test(PriceListList::class)->call('toggleStatus', $list->id)->assertForbidden();

    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['price_list_id' => (string) $list->id]);

    // Accounts has no delete right at all; an administrator who does is stopped by the rate that uses it
    Livewire::actingAs($this->accounts)->test(PriceListList::class)->call('delete', $list->id)->assertForbidden();
    Livewire::actingAs($this->admin)->test(PriceListList::class)->call('delete', $list->id)->assertHasErrors(['list']);
    expect(PriceList::query()->count())->toBe(1);
});

it('links Rates and Price lists in the menu for those who may see them', function () {
    foreach ([$this->accounts, $this->operations, $this->manager, $this->purchase] as $user) {
        $page = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $page->assertSee('href="'.route('masters.rates.index').'"', false)->assertSee('href="'.route('masters.price-lists').'"', false);
    }

    $this->actingAs($this->telecaller)->get(route('dashboard'))->assertOk()
        ->assertDontSee('href="'.route('masters.rates.index').'"', false)->assertDontSee('href="'.route('masters.price-lists').'"', false);
});
