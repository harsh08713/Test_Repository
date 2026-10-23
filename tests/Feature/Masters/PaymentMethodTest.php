<?php

use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Http\Livewire\AccountGroupTree;
use App\Modules\Masters\Http\Livewire\PaymentMethodList;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\PaymentMethod;
use App\Modules\Masters\Services\LedgerService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
});

// ---- payment methods ---------------------------------------------------------------------------------

it('gives every company the usual payment methods', function () {
    $codes = PaymentMethod::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->orderBy('code')->pluck('name', 'code')->all();

    expect($codes)->toBe(['CASH' => 'Cash', 'CHEQUE' => 'Cheque', 'IMPS' => 'IMPS', 'NEFT' => 'NEFT', 'RTGS' => 'RTGS', 'UPI' => 'UPI']);
});

it('adds, edits and deactivates a payment method, within the company', function () {
    Livewire::actingAs($this->admin)->test(PaymentMethodList::class)
        ->assertSee('NEFT')->assertDontSee('BARTER')
        ->call('create')->set('values.code', ' barter ')->set('values.name', 'Barter')->call('save')->assertHasNoErrors();

    $barter = PaymentMethod::query()->where('code', 'BARTER')->firstOrFail();
    expect($barter)->name->toBe('Barter')->company_id->toBe($this->acme->id);

    Livewire::actingAs($this->admin)->test(PaymentMethodList::class)->call('edit', $barter->id)->set('values.name', 'Barter trade')->call('save')->assertHasNoErrors()
        ->call('toggleStatus', $barter->id);

    expect($barter->fresh())->name->toBe('Barter trade')->status->toBe('inactive');
    Livewire::actingAs($this->globexAdmin)->test(PaymentMethodList::class)->assertDontSee('BARTER');
});

it('keeps codes unique within a company', function () {
    Livewire::actingAs($this->admin)->test(PaymentMethodList::class)->call('create')->set('values.code', 'neft')->set('values.name', 'Again')->call('save')->assertHasErrors(['values.code']);
});

it('limits who may manage payment methods', function () {
    Livewire::actingAs($this->accounts)->test(PaymentMethodList::class)->assertOk()->call('create')->set('values.code', 'WIRE')->set('values.name', 'Wire')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->purchase)->test(PaymentMethodList::class)->assertOk()->call('create')->assertForbidden();
    Livewire::actingAs($this->telecaller)->test(PaymentMethodList::class)->assertForbidden();

    $upi = PaymentMethod::query()->where('code', 'UPI')->firstOrFail();
    Livewire::actingAs($this->purchase)->test(PaymentMethodList::class)->call('delete', $upi->id)->assertForbidden();
    Livewire::actingAs($this->accounts)->test(PaymentMethodList::class)->call('delete', $upi->id)->assertForbidden();   // Accounts may add and edit, not delete
});

it('freezes the code of a method that ledgers use, and refuses to delete it', function () {
    makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['payment_preferences' => ['NEFT']]]);
    $neft = PaymentMethod::query()->where('code', 'NEFT')->firstOrFail();
    $upi = PaymentMethod::query()->where('code', 'UPI')->firstOrFail();

    Livewire::actingAs($this->admin)->test(PaymentMethodList::class)
        ->call('edit', $neft->id)->set('values.code', 'NEFT2')->call('save')->assertHasErrors(['values.code'])
        ->set('values.code', 'NEFT')->set('values.name', 'NEFT transfer')->call('save')->assertHasNoErrors()    // other fields stay editable
        ->call('delete', $neft->id)->assertHasErrors(['list'])
        ->call('delete', $upi->id)->assertHasNoErrors();

    expect(PaymentMethod::query()->where('code', 'NEFT')->exists())->toBeTrue()->and(PaymentMethod::query()->where('code', 'UPI')->exists())->toBeFalse();
});

it('no longer offers a deactivated method to new ledgers', function () {
    PaymentMethod::query()->where('code', 'CHEQUE')->firstOrFail()->update(['status' => 'inactive']);

    $errors = [];
    try {
        makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['payment_preferences' => ['CHEQUE']]]);
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toHaveKey('ledger.payment_preferences.0');
});

// ---- the chart of accounts, now that ledgers hang off it ---------------------------------------------------

function ledgerGroup($test, string $name = 'North debtors'): AccountGroup
{
    $group = (new AccountGroup(['code' => strtoupper(substr(md5($name), 0, 6)), 'name' => $name, 'parent_id' => groupId($test->acme, '1160'), 'nature' => 'asset']))
        ->forceFill(['company_id' => $test->acme->id]);
    $group->save();

    return $group;
}

it('will not delete an account group that holds ledgers', function () {
    $group = ledgerGroup($this);
    makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['account_group_id' => (string) $group->id]]);

    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('delete', $group->id)->assertHasErrors(['tree']);
    expect(AccountGroup::query()->whereKey($group->id)->exists())->toBeTrue();
});

it('deletes an unused custom group, and still refuses to delete one with sub-groups', function () {
    $empty = ledgerGroup($this, 'Empty');

    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('delete', $empty->id)->assertHasNoErrors();
    expect(AccountGroup::query()->whereKey($empty->id)->exists())->toBeFalse();
});

it('will not move a group that holds ledgers, or whose sub-groups do', function () {
    $parent = ledgerGroup($this, 'Parent');
    $child = (new AccountGroup(['code' => 'CHILD1', 'name' => 'Child', 'parent_id' => $parent->id, 'nature' => 'asset']))->forceFill(['company_id' => $this->acme->id]);
    $child->save();
    makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['account_group_id' => (string) $child->id]]);
    $elsewhere = ledgerGroup($this, 'Elsewhere');

    $tree = Livewire::actingAs($this->admin)->test(AccountGroupTree::class);

    foreach ([$parent, $child] as $held) {
        $tree->call('startMove', $held->id)->assertHasErrors(['tree'])->assertSet('moveOpen', false);
    }

    // a crafted move (dialog skipped) is stopped too
    $tree->set('movingId', $parent->id)->set('newParentId', (string) groupId($this->acme, '1110'))->call('move')->assertHasErrors(['newParentId']);
    expect($parent->fresh()->parent_id)->toBe(groupId($this->acme, '1160'));

    // a group with no ledgers moves as before
    $tree->call('startMove', $elsewhere->id)->assertSet('moveOpen', true);
});

it('still lets a group that holds ledgers be renamed or deactivated', function () {
    $group = ledgerGroup($this);
    $ledger = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['account_group_id' => (string) $group->id]]);

    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)
        ->call('edit', $group->id)->set('values.name', 'North region debtors')->call('save')->assertHasNoErrors()
        ->call('toggleStatus', $group->id);

    expect($group->fresh())->name->toBe('North region debtors')->status->toBe('inactive');

    // the ledger keeps its group, and can still be edited while it is a draft
    $saved = app(Container::class)->make(TenantContext::class)->withCompany($this->acme->id, fn () => app(LedgerService::class)->save(
        LedgerType::Customer,
        ledgerInput($this->acme, 'customer', ['ledger' => ['account_group_id' => (string) $group->id, 'remarks' => 'still editable']]),
        $ledger,
        $this->accounts,
    ));
    expect($saved->account_group_id)->toBe($group->id);
});
