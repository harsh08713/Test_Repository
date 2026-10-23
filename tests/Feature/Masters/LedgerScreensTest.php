<?php

use App\Modules\Masters\Http\Livewire\Ledgers\LedgerForm;
use App\Modules\Masters\Http\Livewire\Ledgers\LedgerList;
use App\Modules\Masters\Http\Livewire\Ledgers\LedgerShow;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\LedgerBankDetail;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Services\ApprovalService;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);

    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
});

/** Fill the new-ledger form's blank rows with valid values. */
function fillCustomerForm($component, array $ledger = [])
{
    $component->set('ledger.name', $ledger['name'] ?? 'Shree Ganesh Engineering')
        ->set('ledger.customer_kind', 'b2c')
        ->set('addresses.0.line1', '12 Ashram Road')
        ->set('addresses.0.state_id', (string) stateId(test()->acme))
        ->set('addresses.0.pin_code', '380009')
        ->set('contacts.0.name', 'Meera Shah')
        ->set('contacts.0.mobile', '9876543210');

    foreach ($ledger as $key => $value) {
        $component->set("ledger.{$key}", $value);
    }

    return $component;
}

// ======================================================================================================
// Who can open what
// ======================================================================================================

it('lets each role open only the ledger screens it may use', function () {
    $supplier = makeLedger($this->acme, $this->purchase, 'supplier');
    $customer = makeLedger($this->acme, $this->accounts, 'customer');

    // Accounts: everything
    foreach (['customer', 'supplier', 'bank', 'cash', 'expense', 'general'] as $type) {
        $this->actingAs($this->accounts)->get(route('masters.ledgers.create', $type))->assertOk();
    }
    $this->actingAs($this->accounts)->get(route('masters.ledgers.index'))->assertOk();
    $this->actingAs($this->accounts)->get(route('masters.ledgers.edit', $customer))->assertOk();

    // Purchase onboards suppliers only
    $this->actingAs($this->purchase)->get(route('masters.ledgers.create', 'supplier'))->assertOk();
    $this->actingAs($this->purchase)->get(route('masters.ledgers.edit', $supplier))->assertOk();
    foreach (['customer', 'bank', 'cash', 'expense', 'general'] as $type) {
        $this->actingAs($this->purchase)->get(route('masters.ledgers.create', $type))->assertForbidden();
    }
    $this->actingAs($this->purchase)->get(route('masters.ledgers.edit', $customer))->assertForbidden();
    $this->actingAs($this->purchase)->get(route('masters.ledgers.show', $customer))->assertOk();   // may look, not touch

    // Operations can look but never change
    $this->actingAs($this->operations)->get(route('masters.ledgers.index'))->assertOk();
    $this->actingAs($this->operations)->get(route('masters.ledgers.show', $customer))->assertOk();
    $this->actingAs($this->operations)->get(route('masters.ledgers.create', 'supplier'))->assertForbidden();
    $this->actingAs($this->operations)->get(route('masters.ledgers.edit', $supplier))->assertForbidden();

    // Tele-callers have no business here at all
    $this->actingAs($this->telecaller)->get(route('masters.ledgers.index'))->assertForbidden();
    $this->actingAs($this->telecaller)->get(route('masters.ledgers.show', $customer))->assertForbidden();

    // an unknown type is not a page
    $this->actingAs($this->accounts)->get('/masters/ledgers/create/vendor')->assertNotFound();
});

it('sends guests to the sign-in page', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');

    $this->get(route('masters.ledgers.index'))->assertRedirect(route('login'));
    $this->get(route('masters.ledgers.show', $ledger))->assertRedirect(route('login'));
    $this->get(route('masters.ledgers.lookup'))->assertRedirect(route('login'));
});

it('shows each role only the "Add ledger" choices it may use', function () {
    Livewire::actingAs($this->accounts)->test(LedgerList::class)->assertSeeHtml('/masters/ledgers/create/customer')->assertSeeHtml('/masters/ledgers/create/general');

    Livewire::actingAs($this->purchase)->test(LedgerList::class)
        ->assertSeeHtml('/masters/ledgers/create/supplier')->assertDontSeeHtml('/masters/ledgers/create/customer');

    Livewire::actingAs($this->operations)->test(LedgerList::class)->assertDontSee('Add ledger');
});

it('lists Ledgers in the sidebar for those who can view them', function () {
    $link = fn (string $route) => 'href="'.route($route).'"';

    $this->actingAs($this->accounts)->get(route('dashboard'))->assertSeeHtml($link('masters.ledgers.index'))->assertSeeHtml($link('masters.payment-methods'));
    $this->actingAs($this->telecaller)->get(route('dashboard'))->assertDontSeeHtml($link('masters.ledgers.index'))->assertDontSeeHtml($link('masters.payment-methods'));
});

// ======================================================================================================
// The form
// ======================================================================================================

it('creates a customer through the form and lands on its page as a draft', function () {
    $component = fillCustomerForm(Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['type' => 'customer']));
    $component->call('save')->assertHasNoErrors();

    $ledger = Ledger::query()->firstOrFail();
    $component->assertRedirect(route('masters.ledgers.show', $ledger));

    expect($ledger)->name->toBe('Shree Ganesh Engineering')->code->toBe('CUS-00001')->company_id->toBe($this->acme->id)
        ->created_by->toBe($this->accounts->id)->approval_status->toBe(ApprovalStatus::Draft)
        ->and($ledger->addresses()->count())->toBe(1)->and($ledger->contacts()->count())->toBe(1);
});

it('shows what is wrong, field by field, and saves nothing', function () {
    Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['type' => 'customer'])
        ->set('ledger.customer_kind', 'b2b')
        ->call('save')
        ->assertHasErrors(['ledger.name', 'ledger.gstin', 'addresses.0.line1', 'addresses.0.state_id', 'addresses.0.pin_code', 'contacts.0.name'])
        ->assertSee('A GSTIN is required for a B2B customer.');

    expect(Ledger::query()->count())->toBe(0);
});

it('does not let the browser change the type, the ledger being edited or what the user may see', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');
    $component = Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['editing' => $ledger]);

    foreach (['type' => 'supplier', 'ledgerId' => 999, 'canSensitive' => true, 'bankSummary' => 'x'] as $property => $value) {
        expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

it('creates a supplier for Purchase, but refuses them every other type even if the page is reached another way', function () {
    $this->actingAs($this->purchase);

    Livewire::test(LedgerForm::class, ['type' => 'supplier'])
        ->set('ledger.name', 'Ahmedabad Steel')->set('addresses.0.line1', 'GIDC')->set('addresses.0.state_id', (string) stateId($this->acme))
        ->set('addresses.0.pin_code', '382445')->set('contacts.0.name', 'Kiran')->set('contacts.0.mobile', '9822012345')
        ->call('save')->assertHasNoErrors();

    expect(Ledger::query()->firstOrFail())->ledger_type->value->toBe('supplier')->code->toBe('SUP-00001');

    Livewire::test(LedgerForm::class, ['type' => 'customer'])->assertForbidden();
    Livewire::test(LedgerForm::class, ['type' => 'bank'])->assertForbidden();
});

it('edits a draft and keeps its code and company', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');

    Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['editing' => $ledger])
        ->assertSet('ledger.name', 'Shree Ganesh Customer')->assertSet('addresses.0.line1', '12 Ashram Road')->assertSet('contacts.0.name', 'Meera Shah')
        ->set('ledger.name', 'Renamed Customer')->set('addresses.0.line1', '99 New Road')
        ->call('save')->assertHasNoErrors();

    expect($ledger->fresh())->name->toBe('Renamed Customer')->code->toBe('CUS-00001')
        ->and($ledger->addresses()->first()->line1)->toBe('99 New Road');
});

it('locks editing once a ledger is pending or approved, and unlocks it again if rejected', function () {
    $pending = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'P']]);
    $approved = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'A']], approve: true, approver: $this->approver);
    app(ApprovalService::class)->submit($pending, $this->accounts);

    foreach ([$pending, $approved] as $locked) {
        $this->actingAs($this->accounts)->get(route('masters.ledgers.edit', $locked))->assertForbidden();
        Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['editing' => $locked])->assertForbidden();
    }

    app(ApprovalService::class)->reject($pending->fresh(), $this->approver, 'Please add the GST certificate details.');
    $this->actingAs($this->accounts)->get(route('masters.ledgers.edit', $pending))->assertOk();
});

it('keeps another company’s ledgers out of reach', function () {
    $theirs = makeLedger($this->acme, $this->accounts, 'customer');

    foreach (['masters.ledgers.show', 'masters.ledgers.edit'] as $route) {
        $this->actingAs($this->globexAdmin)->get(route($route, $theirs))->assertNotFound();
    }

    // handed straight to a component, the policy still refuses
    Livewire::actingAs($this->globexAdmin)->test(LedgerForm::class, ['editing' => $theirs])->assertForbidden();
    Livewire::actingAs($this->globexAdmin)->test(LedgerShow::class, ['ledger' => $theirs])->assertForbidden();
});

it('fills state, district and city from a known PIN code, and says so when it cannot', function () {
    $component = Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['type' => 'customer']);

    $component->set('addresses.0.pin_code', '395003')
        ->assertSet('addresses.0.state_id', (string) stateId($this->acme))
        ->assertSet('addresses.0.district_id', (string) $this->geo['surat']->id)
        ->assertSet('addresses.0.city', 'Surat')->assertSee('State and district filled from the PIN code master.');

    $component->set('addresses.0.pin_code', '560001')->assertSee('is not in the geography master');
    $component->set('addresses.0.pin_code', '382110')->assertSee('covers more than one district or block');
});

it('clears the district when the state changes', function () {
    Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['type' => 'customer'])
        ->set('addresses.0.state_id', (string) stateId($this->acme))->set('addresses.0.district_id', (string) $this->geo['surat']->id)
        ->set('addresses.0.state_id', (string) stateId($this->acme, '27'))->assertSet('addresses.0.district_id', '');
});

it('adds, removes and re-defaults addresses and contacts, within limits', function () {
    $component = Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['type' => 'customer'])
        ->call('addAddress')->call('addContact')
        ->assertCount('addresses', 2)->assertCount('contacts', 2)
        ->set('addresses.1.is_billing', true)
        ->call('setDefault', 1, 'billing')
        ->assertSet('addresses.0.is_default_billing', false)->assertSet('addresses.1.is_default_billing', true)
        ->call('setPrimary', 1)->assertSet('contacts.0.is_primary', false)->assertSet('contacts.1.is_primary', true);

    $component->call('removeAddress', 0)->call('removeContact', 0)->assertCount('addresses', 1)->assertCount('contacts', 1);

    for ($i = 0; $i < 40; $i++) {
        $component->call('addAddress');
    }
    $component->assertCount('addresses', 25);

    $component->call('setDefault', 0, 'weekly')->assertNotFound();
});

it('shows the bank section, with the full number, only to someone who may see it', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'supplier', ['bank' => ledgerInput($this->acme, 'bank')['bank']]);

    Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['editing' => $ledger])
        ->assertSet('bank.account_number', '50100123456789')->assertSee('Account number');

    $blind = Livewire::actingAs($this->purchase)->test(LedgerForm::class, ['editing' => $ledger])
        ->assertSet('bank.account_number', '')->assertDontSee('50100123456789')->assertDontSee('Account number')
        ->assertSee('Bank account details are restricted')->assertSee('XXXXXX6789');

    expect(json_encode($blind->get('bank')))->not->toContain('50100123456789');

    // saving as that user leaves the account exactly as it was
    $blind->set('ledger.remarks', 'purchase note')->call('save')->assertHasNoErrors();
    expect(LedgerBankDetail::query()->firstOrFail())->account_number->toBe('50100123456789')
        ->and($ledger->fresh()->remarks)->toBe('purchase note');
});

it('requires the account on a bank ledger and asks for it', function () {
    Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['type' => 'bank'])
        ->assertSet('hasBank', true)->assertSee('required before approval')
        ->set('ledger.name', 'HDFC Current')->call('save')->assertHasErrors(['bank.bank_name', 'bank.account_number', 'bank.ifsc']);
});

it('lets an optional bank account be added and removed on a supplier', function () {
    $component = Livewire::actingAs($this->accounts)->test(LedgerForm::class, ['type' => 'supplier'])->assertSet('hasBank', false);

    $component->call('toggleBank')->assertSet('hasBank', true)->call('toggleBank')->assertSet('hasBank', false);
});

// ======================================================================================================
// The list
// ======================================================================================================

it('lists, filters and searches the company’s ledgers', function () {
    makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Alpha Traders', 'legal_name' => 'Alpha Trading LLP']]);
    makeLedger($this->acme, $this->accounts, 'supplier', ['ledger' => ['name' => 'Beta Steel']], approve: true, approver: $this->approver);
    makeLedger($this->acme, $this->accounts, 'cash', ['ledger' => ['name' => 'Petty cash', 'status' => 'inactive']]);
    makeLedger($this->globex, makeUser(['Accounts User'], [$this->globex]), 'customer', ['ledger' => ['name' => 'Globex Only']]);

    Livewire::actingAs($this->accounts)->test(LedgerList::class)
        ->assertSee('Alpha Traders')->assertSee('Beta Steel')->assertSee('Petty cash')->assertDontSee('Globex Only')
        ->set('type', 'supplier')->assertSee('Beta Steel')->assertDontSee('Alpha Traders')
        ->set('type', '')->set('search', 'trading llp')->assertSee('Alpha Traders')->assertDontSee('Beta Steel')
        ->set('search', 'SUP-00001')->assertSee('Beta Steel')->assertDontSee('Petty cash')
        ->set('search', '')->set('approval', 'approved')->assertSee('Beta Steel')->assertDontSee('Alpha Traders')
        ->set('approval', '')->set('status', 'inactive')->assertSee('Petty cash')->assertDontSee('Beta Steel');
});

it('finds a ledger by GSTIN and pages the results', function () {
    $gstin = gstinFor('24', 'AAACF1234A');
    makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Gst Customer', 'customer_kind' => 'b2b', 'gstin' => $gstin]]);

    foreach (range(1, 16) as $n) {
        makeLedger($this->acme, $this->accounts, 'general', ['ledger' => ['name' => sprintf('General %02d', $n)]]);
    }

    Livewire::actingAs($this->accounts)->test(LedgerList::class)
        ->assertSee('General 01')->assertDontSee('General 16')      // 17 ledgers over pages of 15, ordered by name
        ->set('search', $gstin)->assertSee('Gst Customer')->assertDontSee('General 01');
});

it('ignores nonsense in the filters rather than failing', function () {
    makeLedger($this->acme, $this->accounts, 'customer');

    Livewire::actingAs($this->accounts)->test(LedgerList::class)
        ->set('type', 'wizard')->set('approval', 'maybe')->set('status', 'sleeping')->set('search', "%_\\' OR 1=1 --")
        ->assertOk();
});

it('switches a ledger on and off from the list, for those who may', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', approve: true, approver: $this->approver);

    Livewire::actingAs($this->accounts)->test(LedgerList::class)->call('toggleStatus', $ledger->id);
    expect($ledger->fresh()->status)->toBe('inactive');

    Livewire::actingAs($this->operations)->test(LedgerList::class)->call('toggleStatus', $ledger->id)->assertForbidden();
    Livewire::actingAs($this->purchase)->test(LedgerList::class)->call('toggleStatus', $ledger->id)->assertForbidden();   // a customer: not theirs
    expect($ledger->fresh()->status)->toBe('inactive');
});

// ======================================================================================================
// The ledger page
// ======================================================================================================

it('shows a ledger’s details, addresses and contacts', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['legal_name' => 'Shree Ganesh Engineering Pvt Ltd', 'credit_days' => '30', 'credit_limit' => '250000', 'payment_preferences' => ['NEFT', 'UPI']]]);

    Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $ledger])
        ->assertSee('Shree Ganesh Customer')->assertSee('CUS-00001')->assertSee('Shree Ganesh Engineering Pvt Ltd')
        ->assertSee('₹ 250,000.00')->assertSee('NEFT, UPI')->assertSee('12 Ashram Road')->assertSee('380009')->assertSee('Meera Shah')->assertSee('9876543210')
        ->assertSee('Draft')->assertSee('Not available in transactions');
});

it('masks the bank account number for everyone but those with the sensitive permission', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'bank');

    Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $ledger])->assertSee('50100123456789')->assertSee('HDFC0001234');

    foreach ([$this->purchase, $this->operations, $this->approver] as $user) {
        Livewire::actingAs($user)->test(LedgerShow::class, ['ledger' => $ledger])
            ->assertSee('XXXXXX6789')->assertDontSee('50100123456789')->assertSee('masked for your role');
    }
});

it('says what a draft still needs before it can be submitted', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', ['addresses' => [], 'contacts' => []]);

    Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $ledger])
        ->assertSee('still needs')->assertSee('at least one billing address')->assertSee('at least one contact');
});

it('shows the history only to those who may read audit trails', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', approve: true, approver: $this->approver);

    // (the approval panel has its own "history"; the audit card is the one with this id)
    Livewire::actingAs($this->admin)->test(LedgerShow::class, ['ledger' => $ledger])->assertSeeHtml('id="s-history"')->assertSee('Submitted')->assertSee('Approved');
    Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $ledger])->assertDontSeeHtml('id="s-history"');
});

it('deletes a draft, but not one that has ever been approved', function () {
    $draft = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Draft only']]);
    $used = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Once approved']], approve: true, approver: $this->approver);
    app(ApprovalService::class)->reopen($used, $this->accounts, 'Correcting the credit limit');

    // Accounts can create and approve but holds no delete permission by default
    Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $draft])->call('delete')->assertForbidden();

    Livewire::actingAs($this->admin)->test(LedgerShow::class, ['ledger' => $draft])->call('delete')->assertRedirect(route('masters.ledgers.index'));
    expect(Ledger::query()->whereKey($draft->id)->exists())->toBeFalse();

    Livewire::actingAs($this->admin)->test(LedgerShow::class, ['ledger' => $used->fresh()])->call('delete')->assertHasErrors(['delete']);
    expect(Ledger::query()->whereKey($used->id)->exists())->toBeTrue();

    Livewire::actingAs($this->operations)->test(LedgerShow::class, ['ledger' => $used->fresh()])->call('delete')->assertForbidden();
});

it('re-opens an approved ledger with a reason, and the ledger stops being usable until approved again', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', approve: true, approver: $this->approver);
    expect($ledger->isUsable())->toBeTrue();

    $component = Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $ledger])
        ->assertSee('Re-open for editing')->call('openReopen')->assertSet('reopenOpen', true)
        ->set('reason', '')->call('reopen')->assertHasErrors(['reason'])
        ->set('reason', 'Credit limit was entered wrongly')->call('reopen')->assertRedirect(route('masters.ledgers.edit', $ledger));

    expect($ledger->fresh())->approval_status->toBe(ApprovalStatus::Draft)->approved_by->toBeNull()
        ->and(Ledger::query()->usable()->whereKey($ledger->id)->exists())->toBeFalse();

    // the people who may not, cannot even open the dialog
    $fresh = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Second']], approve: true, approver: $this->approver);
    foreach ([$this->operations, $this->purchase, $this->approver] as $user) {
        Livewire::actingAs($user)->test(LedgerShow::class, ['ledger' => $fresh])->call('openReopen')->assertForbidden();
    }
});

it('only offers re-opening on an approved ledger', function () {
    $draft = makeLedger($this->acme, $this->accounts, 'customer');

    Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $draft])->assertDontSee('Re-open for editing')->call('openReopen')->assertForbidden();
});

it('deactivates and reactivates an approved ledger without re-approval', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', approve: true, approver: $this->approver);

    Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $ledger])->call('toggleStatus');
    expect($ledger->fresh())->status->toBe('inactive')->approval_status->toBe(ApprovalStatus::Approved)
        ->and($ledger->fresh()->isUsable())->toBeFalse();

    Livewire::actingAs($this->accounts)->test(LedgerShow::class, ['ledger' => $ledger->fresh()])->call('toggleStatus');
    expect($ledger->fresh()->isUsable())->toBeTrue();
});

it('embeds the approval panel and comments on the page', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');

    $this->actingAs($this->accounts)->get(route('masters.ledgers.show', $ledger))->assertOk()->assertSee('Submit for approval')->assertSee('Comments');
});
