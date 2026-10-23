<?php

use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Http\Livewire\Ledgers\LedgerShow;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\LedgerBankDetail;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\LedgerService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Http\Livewire\ApprovalInbox;
use App\Modules\Shared\Http\Livewire\ApprovalPanel;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);

    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->globexAccounts = makeUser(['Accounts User'], [$this->globex]);
    $this->approvals = app(ApprovalService::class);
});

// ======================================================================================================
// Ready to submit?
// ======================================================================================================

it('will not submit a customer or supplier that is not complete', function (string $type) {
    $ledger = makeLedger($this->acme, $this->accounts, $type, ['addresses' => [], 'contacts' => []]);

    expect(fn () => $this->approvals->submit($ledger, $this->accounts))
        ->toThrow(ApprovalStateException::class, 'at least one billing address; at least one contact');

    expect($ledger->fresh()->approval_status)->toBe(ApprovalStatus::Draft)->and(Approval::query()->count())->toBe(0);
})->with(['customer', 'supplier']);

it('will not submit a shipping-only party, because it has no billing address', function () {
    $address = [...ledgerInput($this->acme)['addresses'][0], 'is_billing' => false, 'is_shipping' => true, 'is_default_billing' => false];
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', ['addresses' => [$address]]);

    expect(fn () => $this->approvals->submit($ledger, $this->accounts))->toThrow(ApprovalStateException::class, 'billing address');
});

it('will not submit a bank ledger without its account, nor a B2B customer that lost its GSTIN', function () {
    $bank = makeLedger($this->acme, $this->accounts, 'bank', ['bank' => null]);
    expect(fn () => $this->approvals->submit($bank, $this->accounts))->toThrow(ApprovalStateException::class, 'the bank account details');

    $customer = makeLedger($this->acme, $this->accounts, 'customer');
    $customer->forceFill(['customer_kind' => 'b2b'])->save();   // as if edited around the form
    expect(fn () => $this->approvals->submit($customer, $this->accounts))->toThrow(ApprovalStateException::class, 'a GSTIN');
});

it('lets cash, expense and general ledgers go straight to approval', function (string $type) {
    $ledger = makeLedger($this->acme, $this->accounts, $type);

    $this->approvals->submit($ledger, $this->accounts);

    expect($ledger->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
})->with(['cash', 'expense', 'general']);

// ======================================================================================================
// The workflow
// ======================================================================================================

it('takes a supplier from Purchase through submission to approval by Accounts', function () {
    $ledger = makeLedger($this->acme, $this->purchase, 'supplier');

    Livewire::actingAs($this->purchase)->test(ApprovalPanel::class, ['type' => 'ledger', 'id' => $ledger->id])->call('submit')->assertHasNoErrors();
    expect($ledger->fresh()->approval_status)->toBe(ApprovalStatus::Pending);

    // the submitter cannot approve their own work (they hold no approve right anyway)
    Livewire::actingAs($this->purchase)->test(ApprovalPanel::class, ['type' => 'ledger', 'id' => $ledger->id])->call('approve')->assertForbidden();

    Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, ['type' => 'ledger', 'id' => $ledger->id])->set('comment', 'GST details checked')->call('approve')->assertHasNoErrors();

    $ledger = $ledger->fresh();
    expect($ledger)->approval_status->toBe(ApprovalStatus::Approved)->approved_by->toBe($this->accounts->id)
        ->and($ledger->isUsable())->toBeTrue()
        ->and(Approval::query()->where('approvable_id', $ledger->id)->firstOrFail())->comment->toBe('GST details checked')->acted_by->toBe($this->accounts->id);
});

it('keeps maker–checker: Accounts cannot approve a ledger it created or submitted', function () {
    $own = makeLedger($this->acme, $this->accounts, 'customer');
    $this->approvals->submit($own, $this->accounts);

    expect(fn () => $this->approvals->approve($own->fresh(), $this->accounts))->toThrow(AuthorizationException::class, 'created or submitted');

    // but the Approver (or another Accounts user) can
    $this->approvals->approve($own->fresh(), $this->approver);
    expect($own->fresh()->isApproved())->toBeTrue();
});

it('lets only those with the approve permission decide', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');
    $this->approvals->submit($ledger, $this->accounts);

    foreach ([$this->operations, $this->purchase, $this->telecaller] as $user) {
        expect(fn () => $this->approvals->approve($ledger->fresh(), $user))->toThrow(AuthorizationException::class);
    }

    // through the screen: those who can see the ledger are refused, and a Tele-caller cannot even open the panel
    foreach ([$this->operations, $this->purchase] as $user) {
        Livewire::actingAs($user)->test(ApprovalPanel::class, ['type' => 'ledger', 'id' => $ledger->id])->call('approve')->assertForbidden();
    }
    Livewire::actingAs($this->telecaller)->test(ApprovalPanel::class, ['type' => 'ledger', 'id' => $ledger->id])->assertForbidden();

    expect($ledger->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('does not let someone from another company approve it', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');
    $this->approvals->submit($ledger, $this->accounts);
    $outsider = makeUser(['Approver'], [$this->globex]);

    expect(fn () => $this->approvals->approve($ledger->fresh(), $outsider))->toThrow(AuthorizationException::class);
    Livewire::actingAs($outsider)->test(ApprovalPanel::class, ['type' => 'ledger', 'id' => $ledger->id])->assertNotFound();
});

it('locks a pending ledger and keeps it out of transactions', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');
    $this->approvals->submit($ledger, $this->accounts);

    $this->actingAs($this->accounts);
    expect($this->accounts->can('update', $ledger->fresh()))->toBeFalse()
        ->and($this->accounts->can('delete', $ledger->fresh()))->toBeFalse()
        ->and(Ledger::query()->usable()->whereKey($ledger->id)->exists())->toBeFalse()
        ->and(app(LedgerLookup::class)->usable($ledger->id))->toBeNull();
});

it('refuses to approve a ledger whose content changed after it was submitted', function (string $change) {
    $ledger = makeLedger($this->acme, $this->accounts, 'bank', ['ledger' => ['payment_preferences' => []]]);
    $ledger->forceFill(['gstin' => null])->save();
    $this->approvals->submit($ledger, $this->accounts);

    match ($change) {
        'name' => $ledger->forceFill(['name' => 'Sneaky rename'])->save(),
        'group' => $ledger->forceFill(['account_group_id' => groupId($this->acme, '2310')])->save(),
        'bank number' => app(TenantContext::class)->withCompany($this->acme->id, fn () => app(LedgerService::class)->save(
            LedgerType::Bank, ledgerInput($this->acme, 'bank', ['ledger' => ['name' => $ledger->name], 'bank' => [...ledgerInput($this->acme, 'bank')['bank'], 'account_number' => '99900011122233']]),
            $ledger->fresh(), $this->accounts)),
        'IFSC' => $ledger->bankDetail->forceFill(['ifsc' => 'ICIC0000001'])->save(),
    };

    expect($this->approvals->changedSinceSubmission($ledger->fresh()))->toBeTrue()
        ->and(fn () => $this->approvals->approve($ledger->fresh(), $this->approver))->toThrow(ApprovalStateException::class, 'changed after it was submitted');
})->with(['name', 'group', 'bank number', 'IFSC']);

it('refuses to approve a ledger after an address or contact changed', function (string $change) {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');
    $this->approvals->submit($ledger, $this->accounts);

    match ($change) {
        'address edited' => $ledger->addresses()->first()->forceFill(['line1' => 'Somewhere else'])->save(),
        'address removed' => $ledger->addresses()->first()->delete(),
        'contact edited' => $ledger->contacts()->first()->forceFill(['mobile' => '9000000000'])->save(),
        'contact removed' => $ledger->contacts()->first()->delete(),
    };

    expect(fn () => $this->approvals->approve($ledger->fresh(), $this->approver))->toThrow(ApprovalStateException::class, 'changed after it was submitted');
})->with(['address edited', 'address removed', 'contact edited', 'contact removed']);

it('does not count switching a ledger off, or its remarks, as a change to what was approved', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');
    $this->approvals->submit($ledger, $this->accounts);

    $ledger->forceFill(['status' => 'inactive', 'remarks' => 'on hold'])->save();
    $this->approvals->approve($ledger->fresh(), $this->approver);

    expect($ledger->fresh())->isApproved()->toBeTrue()->and($ledger->fresh()->isUsable())->toBeFalse();   // approved, but off
});

it('never puts the bank account number in the fingerprint', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'bank');
    $parts = (new ReflectionMethod($ledger, 'approvalFingerprintParts'))->invoke($ledger);

    expect(json_encode($parts))->not->toContain('50100123456789')->and($parts['bank'])->toHaveKey('account_number_hash');
});

it('returns a rejected ledger to its owner with the reason and keeps the history through resubmission', function () {
    $ledger = makeLedger($this->acme, $this->purchase, 'supplier');
    $this->approvals->submit($ledger, $this->purchase);
    $this->approvals->reject($ledger->fresh(), $this->accounts, 'Add the PAN before we can approve');

    $ledger = $ledger->fresh();
    expect($ledger->approval_status)->toBe(ApprovalStatus::Rejected)->and($this->purchase->can('update', $ledger))->toBeTrue();

    $this->approvals->submit($ledger, $this->purchase);
    $this->approvals->approve($ledger->fresh(), $this->accounts);

    $rows = Approval::query()->where('approvable_id', $ledger->id)->orderBy('cycle')->get();
    expect($rows->pluck('cycle')->all())->toBe([1, 2])
        ->and($rows[0])->status->value->toBe('rejected')->comment->toBe('Add the PAN before we can approve')
        ->and($rows[1]->status->value)->toBe('approved')
        ->and($ledger->fresh()->isUsable())->toBeTrue();
});

it('lists a submitted ledger in the approver’s inbox, linking to its page', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer');
    $this->approvals->submit($ledger, $this->accounts);

    Livewire::actingAs($this->approver)->test(ApprovalInbox::class)->assertSee('Ledger CUS-00001')->assertSeeHtml(route('masters.ledgers.show', $ledger));
    Livewire::actingAs($this->accounts)->test(ApprovalInbox::class)->assertDontSee('Ledger CUS-00001');   // their own submission
});

// ======================================================================================================
// Re-opening an approved ledger
// ======================================================================================================

it('takes an approved ledger back to draft, with a recorded reason, and keeps its approval history', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', approve: true, approver: $this->approver);

    $this->approvals->reopen($ledger, $this->accounts, '  Credit limit entered wrongly ');

    $ledger = $ledger->fresh();
    expect($ledger)->approval_status->toBe(ApprovalStatus::Draft)->approved_by->toBeNull()->approved_at->toBeNull()
        ->and($ledger->isUsable())->toBeFalse()
        ->and(Approval::query()->where('approvable_id', $ledger->id)->count())->toBe(1);          // the approval stays on record

    $entry = ActivityLog::withoutGlobalScopes()->where('event', 'reopened')->firstOrFail();
    expect($entry->causer_id)->toBe($this->accounts->id)->and($entry->company_id)->toBe($this->acme->id)
        ->and($entry->properties['comment'])->toBe('Credit limit entered wrongly')
        ->and($entry->properties['old']['approval_status'])->toBe('approved')->and($entry->properties['attributes']['approval_status'])->toBe('draft');
});

it('needs a reason, and an approved ledger', function () {
    $approved = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'A']], approve: true, approver: $this->approver);
    $draft = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'B']]);
    $pending = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'C']]);
    $this->approvals->submit($pending, $this->accounts);

    expect(fn () => $this->approvals->reopen($approved, $this->accounts, '   '))->toThrow(ApprovalStateException::class, 'say why')
        ->and($approved->fresh()->isApproved())->toBeTrue();

    foreach ([$draft, $pending] as $notApproved) {
        expect(fn () => $this->approvals->reopen($notApproved->fresh(), $this->accounts, 'Why not'))->toThrow(ApprovalStateException::class, 'Only an approved document');
    }

    // the same request twice is a no-op the second time
    $this->approvals->reopen($approved, $this->accounts, 'First');
    expect(fn () => $this->approvals->reopen($approved->fresh(), $this->accounts, 'Second'))->toThrow(ApprovalStateException::class);
});

it('puts a re-opened ledger through a fresh approval cycle before it can be used again', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', approve: true, approver: $this->approver);
    $this->approvals->reopen($ledger, $this->accounts, 'Correct the contact');

    $ledger->contacts()->first()->forceFill(['mobile' => '9811122233'])->save();
    $this->approvals->submit($ledger->fresh(), $this->accounts);
    $this->approvals->approve($ledger->fresh(), $this->approver);

    expect(Approval::query()->where('approvable_id', $ledger->id)->pluck('cycle')->all())->toBe([1, 2])
        ->and($ledger->fresh()->isUsable())->toBeTrue();
});

it('lets only someone who may edit that kind of ledger re-open it', function () {
    $customer = makeLedger($this->acme, $this->accounts, 'customer', approve: true, approver: $this->approver);
    $supplier = makeLedger($this->acme, $this->purchase, 'supplier', approve: true, approver: $this->approver);

    $can = fn ($user, $ledger) => $user->can('reopen', $ledger->fresh());

    expect($can($this->accounts, $customer))->toBeTrue()->and($can($this->accounts, $supplier))->toBeTrue()
        ->and($can($this->purchase, $supplier))->toBeTrue()                  // Purchase owns suppliers
        ->and($can($this->purchase, $customer))->toBeFalse()                 // but not customers
        ->and($can($this->operations, $supplier))->toBeFalse()->and($can($this->approver, $customer))->toBeFalse()
        ->and($can($this->telecaller, $customer))->toBeFalse()
        ->and(makeUser(['Company Admin'], [$this->globex])->can('reopen', $customer->fresh()))->toBeFalse();   // another company
});

// ======================================================================================================
// Only approved ledgers can be found
// ======================================================================================================

function lookupIds(string $type = '', string $q = '', int $limit = 20): array
{
    return app(LedgerLookup::class)->search($type === '' ? null : LedgerType::from($type), $q, $limit)->pluck('id')->all();
}

it('finds only approved, active, undeleted ledgers of the current company', function () {
    $usable = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Usable']], approve: true, approver: $this->approver);
    $draft = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Draft one']]);
    $pending = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Pending one']]);
    $this->approvals->submit($pending, $this->accounts);
    $rejected = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Rejected one']]);
    $this->approvals->submit($rejected, $this->accounts);
    $this->approvals->reject($rejected->fresh(), $this->approver, 'No');
    $inactive = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Inactive one']], approve: true, approver: $this->approver);
    $inactive->forceFill(['status' => 'inactive'])->save();
    $reopened = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Reopened one']], approve: true, approver: $this->approver);
    $this->approvals->reopen($reopened, $this->accounts, 'Fixing');
    $deleted = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Deleted one']], approve: true, approver: $this->approver);
    $deleted->delete();
    $foreign = makeLedger($this->globex, $this->globexAccounts, 'customer', ['ledger' => ['name' => 'Foreign one']], approve: true, approver: makeUser(['Approver'], [$this->globex]));

    $this->actingAs($this->accounts);

    expect(lookupIds())->toBe([$usable->id])
        ->and(app(LedgerLookup::class)->usable($usable->id))->not->toBeNull();

    foreach ([$draft, $pending, $rejected, $inactive, $reopened, $deleted, $foreign] as $unusable) {
        expect(app(LedgerLookup::class)->usable($unusable->id))->toBeNull("{$unusable->name} must not be usable");
    }
});

it('narrows by type and by what is typed, and caps the number returned', function () {
    $customer = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Shree Traders', 'customer_kind' => 'b2b', 'gstin' => gstinFor('24', 'AAACF1234A')]], approve: true, approver: $this->approver);
    $supplier = makeLedger($this->acme, $this->accounts, 'supplier', ['ledger' => ['name' => 'Shree Steel']], approve: true, approver: $this->approver);
    $bank = makeLedger($this->acme, $this->accounts, 'bank', ['ledger' => ['name' => 'HDFC Current']], approve: true, approver: $this->approver);

    $this->actingAs($this->accounts);

    expect(lookupIds('customer'))->toBe([$customer->id])
        ->and(lookupIds('', 'shree'))->toBe([$supplier->id, $customer->id])      // by name: "Shree Steel" before "Shree Traders"
        ->and(lookupIds('supplier', 'shree'))->toBe([$supplier->id])
        ->and(lookupIds('', 'SUP-00001'))->toBe([$supplier->id])
        ->and(lookupIds('', gstinFor('24', 'AAACF1234A')))->toBe([$customer->id])
        ->and(lookupIds('', 'nothing like this'))->toBe([])
        ->and(lookupIds('', '', 2))->toHaveCount(2)
        ->and(lookupIds('', '', 10_000))->toHaveCount(3)      // capped at 50, and only three exist
        ->and($bank->id)->not->toBeIn(lookupIds('customer'));
});

it('treats % and _ in what is typed as ordinary characters', function () {
    makeLedger($this->acme, $this->accounts, 'general', ['ledger' => ['name' => 'Plain account']], approve: true, approver: $this->approver);
    $odd = makeLedger($this->acme, $this->accounts, 'general', ['ledger' => ['name' => 'Rebate 5% special']], approve: true, approver: $this->approver);

    $this->actingAs($this->accounts);

    expect(lookupIds('', '%'))->toBe([$odd->id])->and(lookupIds('', '_'))->toBe([]);
});

it('serves the look-up as JSON, never including bank details', function () {
    $bank = makeLedger($this->acme, $this->accounts, 'bank', ['ledger' => ['name' => 'HDFC Current']], approve: true, approver: $this->approver);
    $customer = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Shree Traders', 'credit_days' => '30', 'credit_limit' => '100000']], approve: true, approver: $this->approver);
    makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Shree Draft']]);

    $response = $this->actingAs($this->operations)->getJson(route('masters.ledgers.lookup', ['q' => 'shree', 'type' => 'customer']))->assertOk();

    expect($response->json('data'))->toBe([['id' => $customer->id, 'code' => 'CUS-00001', 'name' => 'Shree Traders', 'type' => 'customer', 'gstin' => null, 'credit_days' => 30, 'credit_limit' => '100000.00']]);

    $all = $this->actingAs($this->operations)->getJson(route('masters.ledgers.lookup'))->assertOk()->getContent();
    expect($all)->not->toContain('50100123456789')->not->toContain('account_number')->not->toContain('ifsc')->not->toContain('HDFC0001234')
        ->and($bank->name)->toBe('HDFC Current');
});

it('validates the look-up request and limits who may use it', function () {
    $this->actingAs($this->accounts)->getJson(route('masters.ledgers.lookup', ['type' => 'wizard']))->assertStatus(422)->assertJsonValidationErrors('type');
    $this->actingAs($this->accounts)->getJson(route('masters.ledgers.lookup', ['limit' => 500]))->assertStatus(422)->assertJsonValidationErrors('limit');
    $this->actingAs($this->accounts)->getJson(route('masters.ledgers.lookup', ['q' => str_repeat('x', 101)]))->assertStatus(422);

    $this->actingAs($this->telecaller)->getJson(route('masters.ledgers.lookup'))->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->getJson(route('masters.ledgers.lookup'))->assertUnauthorized();
});

it('does not let the caller choose the company for a look-up', function () {
    makeLedger($this->globex, $this->globexAccounts, 'customer', ['ledger' => ['name' => 'Globex customer']], approve: true, approver: makeUser(['Approver'], [$this->globex]));
    makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Acme customer']], approve: true, approver: $this->approver);

    $names = fn ($url) => collect($this->actingAs($this->accounts)->getJson($url)->assertOk()->json('data'))->pluck('name')->all();

    expect($names(route('masters.ledgers.lookup', ['company_id' => $this->globex->id])))->toBe(['Acme customer'])
        ->and($names(route('masters.ledgers.lookup').'?q=Globex'))->toBe([]);
});

it('keeps unapproved ledgers off the page of someone who may view them but marks them clearly', function () {
    $draft = makeLedger($this->acme, $this->accounts, 'customer');

    // viewing is separate from using: the record is visible, flagged as unavailable
    Livewire::actingAs($this->operations)->test(LedgerShow::class, ['ledger' => $draft])
        ->assertSee('Draft')->assertSee('Not available in transactions');
});

it('keeps the bank detail model from serialising its secrets', function () {
    $ledger = makeLedger($this->acme, $this->accounts, 'bank');
    $bank = LedgerBankDetail::withoutGlobalScopes()->where('ledger_id', $ledger->id)->firstOrFail();

    expect(json_encode($bank))->not->toContain('50100123456789')->not->toContain($bank->account_number_hash)
        ->and(json_encode($ledger->load('bankDetail')))->not->toContain('50100123456789');
});
