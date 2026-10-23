<?php

use App\Models\User;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\LedgerAddress;
use App\Modules\Masters\Models\LedgerBankDetail;
use App\Modules\Masters\Models\LedgerContact;
use App\Modules\Masters\Models\PaymentMethod;
use App\Modules\Masters\Services\LedgerService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);

    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
});

function saveLedger($test, string $type = 'customer', array $overrides = [], ?User $by = null, ?Ledger $editing = null, $company = null): Ledger
{
    $company ??= $test->acme;
    $by ??= $test->accounts;

    return inCompany($company, fn () => app(LedgerService::class)->save(LedgerType::from($type), ledgerInput($company, $type, $overrides), $editing, $by));
}

/** The validation messages a save would give, keyed by field path. */
function ledgerErrors($test, string $type = 'customer', array $overrides = [], ?User $by = null, ?Ledger $editing = null, $company = null): array
{
    try {
        saveLedger($test, $type, $overrides, $by, $editing, $company);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// ======================================================================================================
// Creating a ledger
// ======================================================================================================

it('saves a customer as a draft with an auto-generated code, its company and its author', function () {
    $ledger = saveLedger($this, 'customer', ['ledger' => ['name' => '  Shree   Ganesh  Engineering ', 'email' => 'Accounts@Example.TEST', 'credit_days' => '30', 'credit_limit' => '250000.5']]);

    expect($ledger)->code->toBe('CUS-00001')->name->toBe('Shree Ganesh Engineering')->email->toBe('accounts@example.test')
        ->ledger_type->toBe(LedgerType::Customer)->company_id->toBe($this->acme->id)->created_by->toBe($this->accounts->id)
        ->approval_status->toBe(ApprovalStatus::Draft)->status->toBe('active')
        ->credit_days->toBe(30)->credit_limit->toBe('250000.50')->customer_kind->toBe('b2c');
});

it('numbers codes per type and never reuses one, even a deleted ledger’s', function () {
    $first = saveLedger($this, 'customer', ['ledger' => ['name' => 'A']]);
    $second = saveLedger($this, 'customer', ['ledger' => ['name' => 'B']]);
    $supplier = saveLedger($this, 'supplier', ['ledger' => ['name' => 'C']]);

    $second->delete();
    $third = saveLedger($this, 'customer', ['ledger' => ['name' => 'D']]);

    expect([$first->code, $second->code, $third->code, $supplier->code])->toBe(['CUS-00001', 'CUS-00002', 'CUS-00003', 'SUP-00001']);
});

it('accepts a code typed in, upper-cased, and refuses a clash', function () {
    $ledger = saveLedger($this, 'customer', ['ledger' => ['code' => ' shree/001 ']]);

    expect($ledger->code)->toBe('SHREE/001')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['code' => 'SHREE/001', 'name' => 'Other']]))->toHaveKey('ledger.code')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['code' => 'bad code!', 'name' => 'Other']]))->toHaveKey('ledger.code');
});

it('lets the same code exist in two companies', function () {
    saveLedger($this, 'customer', ['ledger' => ['code' => 'X-1']]);

    expect(saveLedger($this, 'customer', ['ledger' => ['code' => 'X-1']], makeUser(['Accounts User'], [$this->globex]), null, $this->globex)->code)->toBe('X-1');
});

it('keeps names unique within a type (ignoring case) but lets a party be both customer and supplier', function () {
    saveLedger($this, 'customer', ['ledger' => ['name' => 'Acme Traders']]);

    expect(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'ACME   traders']]))->toHaveKey('ledger.name')
        ->and(ledgerErrors($this, 'supplier', ['ledger' => ['name' => 'Acme Traders']]))->toBe([]);
});

it('fixes the ledger type for life', function () {
    $ledger = saveLedger($this, 'customer');

    expect(fn () => app(LedgerService::class)->save(LedgerType::Supplier, ledgerInput($this->acme, 'supplier'), $ledger, $this->accounts))
        ->toThrow(LogicException::class, 'cannot be changed');

    $ledger->ledger_type = LedgerType::Supplier;
    expect(fn () => $ledger->save())->toThrow(LogicException::class);
});

// ======================================================================================================
// Type rules: group, fields
// ======================================================================================================

it('files each type under its own part of the chart of accounts', function (string $type, string $allowed, string $refused) {
    expect(ledgerErrors($this, $type, ['ledger' => ['account_group_id' => (string) groupId($this->acme, $allowed)]]))->toBe([])
        ->and(ledgerErrors($this, $type, ['ledger' => ['name' => 'Elsewhere', 'account_group_id' => (string) groupId($this->acme, $refused)]]))->toHaveKey('ledger.account_group_id');
})->with([
    'customer' => ['customer', '1160', '2230'],
    'supplier' => ['supplier', '2230', '1160'],
    'bank' => ['bank', '1110', '1120'],
    'cash' => ['cash', '1120', '1110'],
    'expense' => ['expense', '4100', '1160'],
]);

it('accepts a bank overdraft account for a bank ledger, and any group for a general ledger', function () {
    expect(ledgerErrors($this, 'bank', ['ledger' => ['account_group_id' => (string) groupId($this->acme, '2310')]]))->toBe([])
        ->and(ledgerErrors($this, 'general', ['ledger' => ['name' => 'G1', 'account_group_id' => (string) groupId($this->acme, '3100')]]))->toBe([])
        ->and(ledgerErrors($this, 'general', ['ledger' => ['name' => 'G2', 'account_group_id' => (string) groupId($this->acme, '2210')]]))->toBe([]);
});

it('accepts a group the company added beneath the right standard group', function () {
    $custom = (new AccountGroup(['code' => 'NORTH', 'name' => 'North region debtors', 'parent_id' => groupId($this->acme, '1160'), 'nature' => 'asset']))
        ->forceFill(['company_id' => $this->acme->id]);
    $custom->save();

    expect(ledgerErrors($this, 'customer', ['ledger' => ['account_group_id' => (string) $custom->id]]))->toBe([])
        ->and(ledgerErrors($this, 'supplier', ['ledger' => ['account_group_id' => (string) $custom->id]]))->toHaveKey('ledger.account_group_id');
});

it('refuses another company’s group, an unknown one, and a deactivated one for a new ledger', function () {
    expect(ledgerErrors($this, 'customer', ['ledger' => ['account_group_id' => (string) groupId($this->globex, '1160')]]))->toHaveKey('ledger.account_group_id')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['account_group_id' => '999999']]))->toHaveKey('ledger.account_group_id')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['account_group_id' => '']]))->toHaveKey('ledger.account_group_id');

    $custom = (new AccountGroup(['code' => 'OLD', 'name' => 'Old debtors', 'parent_id' => groupId($this->acme, '1160'), 'nature' => 'asset', 'status' => 'inactive']))
        ->forceFill(['company_id' => $this->acme->id]);
    $custom->save();

    expect(ledgerErrors($this, 'customer', ['ledger' => ['account_group_id' => (string) $custom->id]]))->toHaveKey('ledger.account_group_id');
});

it('lets an existing ledger keep a group that has since been deactivated', function () {
    $custom = (new AccountGroup(['code' => 'SOON', 'name' => 'Soon closed', 'parent_id' => groupId($this->acme, '1160'), 'nature' => 'asset']))
        ->forceFill(['company_id' => $this->acme->id]);
    $custom->save();

    $ledger = saveLedger($this, 'customer', ['ledger' => ['account_group_id' => (string) $custom->id]]);
    $custom->update(['status' => 'inactive']);

    expect(ledgerErrors($this, 'customer', ['ledger' => ['account_group_id' => (string) $custom->id, 'remarks' => 'edited']], null, $ledger))->toBe([]);
});

it('drops fields that do not belong to the type instead of storing them', function () {
    $supplier = saveLedger($this, 'supplier', ['ledger' => ['customer_kind' => 'b2b', 'credit_limit' => '5000', 'lead_time_days' => '12', 'credit_days' => '45']]);
    $customer = saveLedger($this, 'customer', ['ledger' => ['lead_time_days' => '12', 'credit_limit' => '5000']]);
    $bank = saveLedger($this, 'bank', ['ledger' => ['credit_days' => '30', 'payment_preferences' => ['CASH']]]);

    expect($supplier)->customer_kind->toBeNull()->credit_limit->toBeNull()->lead_time_days->toBe(12)->credit_days->toBe(45)
        ->and($customer)->lead_time_days->toBeNull()->credit_limit->toBe('5000.00')
        ->and($bank)->credit_days->toBeNull()->payment_preferences->toBeNull();
});

it('checks the numeric fields', function (string $field, string $value) {
    expect(ledgerErrors($this, 'customer', ['ledger' => [$field => $value]]))->toHaveKey("ledger.{$field}");
})->with([
    'negative credit days' => ['credit_days', '-1'],
    'too many credit days' => ['credit_days', '366'],
    'fractional credit days' => ['credit_days', '2.5'],
    'text credit days' => ['credit_days', 'thirty'],
    'negative limit' => ['credit_limit', '-5'],
    'three decimals' => ['credit_limit', '10.123'],
    'huge limit' => ['credit_limit', '99999999999999999'],
]);

it('requires the customer kind', function () {
    expect(ledgerErrors($this, 'customer', ['ledger' => ['customer_kind' => '']]))->toHaveKey('ledger.customer_kind')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['customer_kind' => 'b2x']]))->toHaveKey('ledger.customer_kind');
});

// ======================================================================================================
// GSTIN and PAN
// ======================================================================================================

it('requires a GSTIN for a B2B customer, and only checks one if given for a B2C customer', function () {
    expect(ledgerErrors($this, 'customer', ['ledger' => ['customer_kind' => 'b2b', 'gstin' => '']]))->toHaveKey('ledger.gstin')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['customer_kind' => 'b2c', 'gstin' => '']]))->toBe([])
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'Retail', 'customer_kind' => 'b2c', 'gstin' => '24AAAAA0000A1Z1']]))->toHaveKey('ledger.gstin');
});

it('verifies the GSTIN check character, not just its shape', function () {
    $good = gstinFor('24', 'AAACF1234A');
    $bad = substr($good, 0, 14).($good[14] === 'A' ? 'B' : 'A');

    expect(ledgerErrors($this, 'customer', ['ledger' => ['customer_kind' => 'b2b', 'gstin' => strtolower($good)]]))->toBe([])
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'X', 'customer_kind' => 'b2b', 'gstin' => $bad]]))->toHaveKey('ledger.gstin');
});

it('does not let two customers share a GSTIN, but lets the same party be a supplier too', function () {
    $gstin = gstinFor('24', 'AAACF1234A');
    saveLedger($this, 'customer', ['ledger' => ['customer_kind' => 'b2b', 'gstin' => $gstin]]);

    expect(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'Twin', 'customer_kind' => 'b2b', 'gstin' => $gstin]]))->toHaveKey('ledger.gstin')
        ->and(ledgerErrors($this, 'supplier', ['ledger' => ['gstin' => $gstin]]))->toBe([]);
});

it('frees a GSTIN when the ledger that held it is deleted', function () {
    $gstin = gstinFor('24', 'AAACF1234A');
    $first = saveLedger($this, 'customer', ['ledger' => ['customer_kind' => 'b2b', 'gstin' => $gstin]]);
    $first->delete();

    expect(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'Replacement', 'customer_kind' => 'b2b', 'gstin' => $gstin]]))->toBe([]);
});

it('checks that the PAN matches the one inside the GSTIN', function () {
    $gstin = gstinFor('24', 'AAACF1234A');

    expect(ledgerErrors($this, 'customer', ['ledger' => ['customer_kind' => 'b2b', 'gstin' => $gstin, 'pan' => 'aaacf1234a']]))->toBe([])
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'Y', 'customer_kind' => 'b2b', 'gstin' => $gstin, 'pan' => 'AAACB5678B']]))->toHaveKey('ledger.pan')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'Z', 'pan' => 'not a pan']]))->toHaveKey('ledger.pan');
});

it('checks that the GSTIN belongs to the state of the billing address', function () {
    $maharashtra = gstinFor('27', 'AAACB5678B');

    // billing address is in Gujarat (the default fixture) — a Maharashtra GSTIN does not fit
    expect(ledgerErrors($this, 'customer', ['ledger' => ['customer_kind' => 'b2b', 'gstin' => $maharashtra]]))->toHaveKey('ledger.gstin');

    $addresses = [[...ledgerInput($this->acme)['addresses'][0], 'state_id' => (string) stateId($this->acme, '27')]];
    expect(ledgerErrors($this, 'customer', ['ledger' => ['customer_kind' => 'b2b', 'gstin' => $maharashtra], 'addresses' => $addresses]))->toBe([]);
});

it('checks an address’s own GSTIN against that address’s state and the PAN', function () {
    $pan = 'AAACF1234A';
    $wrongState = gstinFor('27', $pan);
    $wrongPan = gstinFor('24', 'AAACB5678B');
    $address = ledgerInput($this->acme)['addresses'][0];

    expect(ledgerErrors($this, 'customer', ['ledger' => ['pan' => $pan], 'addresses' => [[...$address, 'gstin' => gstinFor('24', $pan)]]]))->toBe([])
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'P', 'pan' => $pan], 'addresses' => [[...$address, 'gstin' => $wrongState]]]))->toHaveKey('addresses.0.gstin')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'Q', 'pan' => $pan], 'addresses' => [[...$address, 'gstin' => $wrongPan]]]))->toHaveKey('addresses.0.gstin');
});

// ======================================================================================================
// Payment preferences
// ======================================================================================================

it('stores payment preferences as the company’s own method codes', function () {
    $ledger = saveLedger($this, 'customer', ['ledger' => ['payment_preferences' => ['neft', 'UPI', 'NEFT']]]);

    expect($ledger->payment_preferences)->toBe(['NEFT', 'UPI']);
});

it('refuses payment methods that do not exist, are inactive, or belong to another company', function () {
    PaymentMethod::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('code', 'IMPS')->update(['status' => 'inactive']);
    (new PaymentMethod(['code' => 'BARTER', 'name' => 'Barter']))->forceFill(['company_id' => $this->globex->id])->save();

    foreach (['IMPS', 'BARTER', 'WHATEVER'] as $code) {
        expect(ledgerErrors($this, 'customer', ['ledger' => ['name' => "N{$code}", 'payment_preferences' => [$code]]]))->toHaveKey('ledger.payment_preferences.0');
    }
});

// ======================================================================================================
// Addresses
// ======================================================================================================

function addressRow($company, array $overrides = []): array
{
    return [...ledgerInput($company)['addresses'][0], ...$overrides];
}

it('requires what an address needs', function (string $field, string $value) {
    expect(ledgerErrors($this, 'customer', ['addresses' => [addressRow($this->acme, [$field => $value])]]))->toHaveKey("addresses.0.{$field}");
})->with([
    'line 1' => ['line1', ''],
    'state' => ['state_id', ''],
    'PIN code' => ['pin_code', ''],
    'short PIN' => ['pin_code', '3800'],
    'PIN with a leading zero' => ['pin_code', '080001'],
    'unknown state' => ['state_id', '999999'],
]);

it('refuses another company’s state or a district of a different state', function () {
    $surat = (new District(['state_id' => stateId($this->acme, '24'), 'name' => 'Surat']))->forceFill(['company_id' => $this->acme->id]);
    $surat->save();
    $globexDistrict = (new District(['state_id' => stateId($this->globex, '24'), 'name' => 'Surat']))->forceFill(['company_id' => $this->globex->id]);
    $globexDistrict->save();

    expect(ledgerErrors($this, 'customer', ['addresses' => [addressRow($this->acme, ['state_id' => (string) stateId($this->globex)])]]))->toHaveKey('addresses.0.state_id')
        ->and(ledgerErrors($this, 'customer', ['addresses' => [addressRow($this->acme, ['district_id' => (string) $surat->id])]]))->toBe([])
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'D2'], 'addresses' => [addressRow($this->acme, ['district_id' => (string) $surat->id, 'state_id' => (string) stateId($this->acme, '27')])]]))->toHaveKey('addresses.0.district_id')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'D3'], 'addresses' => [addressRow($this->acme, ['district_id' => (string) $globexDistrict->id])]]))->toHaveKey('addresses.0.district_id');
});

it('needs each address to be billing, shipping or both', function () {
    expect(ledgerErrors($this, 'customer', ['addresses' => [addressRow($this->acme, ['is_billing' => false, 'is_shipping' => false])]]))->toHaveKey('addresses.0.is_billing');
});

it('makes a lone billing address the default one without being told', function () {
    $ledger = saveLedger($this, 'customer', ['addresses' => [addressRow($this->acme, ['is_default_billing' => false, 'is_default_shipping' => false])]]);

    expect($ledger->addresses()->first())->is_default_billing->toBeTrue()->is_default_shipping->toBeTrue();
});

it('wants a default among several addresses of a kind, and only one', function () {
    $a = addressRow($this->acme, ['label' => 'A', 'is_default_billing' => false, 'is_default_shipping' => false]);
    $b = addressRow($this->acme, ['label' => 'B', 'is_default_billing' => false, 'is_default_shipping' => false]);

    expect(ledgerErrors($this, 'customer', ['addresses' => [$a, $b]]))->toHaveKey('addresses')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'Two'], 'addresses' => [[...$a, 'is_default_billing' => true, 'is_default_shipping' => true], [...$b, 'is_default_billing' => true]]]))->toHaveKey('addresses')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'One'], 'addresses' => [[...$a, 'is_default_billing' => true, 'is_default_shipping' => true], $b]]))->toBe([]);
});

it('does not let an address be the default of a kind it is not', function () {
    $shippingOnly = addressRow($this->acme, ['is_billing' => false, 'is_shipping' => true, 'is_default_billing' => true, 'is_default_shipping' => true]);

    expect(ledgerErrors($this, 'customer', ['addresses' => [$shippingOnly]]))->toHaveKey('addresses.0.is_default_billing');
});

it('caps the number of addresses and contacts', function () {
    $many = array_fill(0, LedgerService::MAX_ROWS + 1, addressRow($this->acme, ['is_default_billing' => false, 'is_default_shipping' => false]));

    expect(ledgerErrors($this, 'customer', ['addresses' => $many]))->toHaveKey('addresses');
});

// ======================================================================================================
// Contacts
// ======================================================================================================

it('normalises mobile numbers and makes a lone contact the primary one', function () {
    $ledger = saveLedger($this, 'customer', ['contacts' => [['id' => '', 'name' => 'Ravi', 'designation' => '', 'phone' => '', 'mobile' => '+91 98765-43210', 'email' => 'RAVI@Example.test', 'is_primary' => false]]]);

    expect($ledger->contacts()->first())->mobile->toBe('9876543210')->email->toBe('ravi@example.test')->is_primary->toBeTrue();
});

it('wants a contact to have a name and some way to be reached', function () {
    expect(ledgerErrors($this, 'customer', ['contacts' => [['id' => '', 'name' => '', 'designation' => '', 'phone' => '', 'mobile' => '9876543210', 'email' => '', 'is_primary' => true]]]))->toHaveKey('contacts.0.name')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'N'], 'contacts' => [['id' => '', 'name' => 'Ravi', 'designation' => '', 'phone' => '', 'mobile' => '', 'email' => '', 'is_primary' => true]]]))->toHaveKey('contacts.0.mobile')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'M'], 'contacts' => [['id' => '', 'name' => 'Ravi', 'designation' => '', 'phone' => '', 'mobile' => '12345', 'email' => '', 'is_primary' => true]]]))->toHaveKey('contacts.0.mobile')
        ->and(ledgerErrors($this, 'customer', ['ledger' => ['name' => 'E'], 'contacts' => [['id' => '', 'name' => 'Ravi', 'designation' => '', 'phone' => '', 'mobile' => '', 'email' => 'not-an-email', 'is_primary' => true]]]))->toHaveKey('contacts.0.email');
});

it('allows only one primary contact', function () {
    $c = fn ($name) => ['id' => '', 'name' => $name, 'designation' => '', 'phone' => '', 'mobile' => '9876543210', 'email' => '', 'is_primary' => true];

    expect(ledgerErrors($this, 'customer', ['contacts' => [$c('A'), $c('B')]]))->toHaveKey('contacts');
});

// ======================================================================================================
// Editing and the rows of other ledgers
// ======================================================================================================

it('updates a ledger and its rows in place, adds new ones and removes the ones left out', function () {
    $ledger = saveLedger($this, 'customer', ['addresses' => [addressRow($this->acme, ['label' => 'First'])]]);
    $first = $ledger->addresses()->first();
    $contact = $ledger->contacts()->first();

    $input = ledgerInput($this->acme, 'customer', [
        'ledger' => ['name' => 'Renamed', 'remarks' => 'edited'],
        'addresses' => [
            addressRow($this->acme, ['id' => (string) $first->id, 'label' => 'First (moved)', 'line1' => '99 New Road']),
            addressRow($this->acme, ['label' => 'Second', 'is_default_billing' => false, 'is_default_shipping' => false, 'is_billing' => false]),
        ],
        'contacts' => [],   // the only contact is removed
    ]);

    $saved = inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Customer, $input, $ledger, $this->accounts));

    expect($saved)->name->toBe('Renamed')->code->toBe($ledger->code)->updated_by->toBe($this->accounts->id)
        ->and($saved->addresses()->pluck('label')->all())->toBe(['First (moved)', 'Second'])
        ->and($saved->addresses()->first()->id)->toBe($first->id)
        ->and($saved->addresses()->first()->line1)->toBe('99 New Road')
        ->and($saved->contacts()->count())->toBe(0)
        ->and(LedgerContact::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->whereKey($contact->id)->first()->trashed())->toBeTrue();
});

it('keeps the code when an edit leaves it blank', function () {
    $ledger = saveLedger($this, 'customer');

    $saved = inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Customer, ledgerInput($this->acme, 'customer', ['ledger' => ['code' => '']]), $ledger, $this->accounts));

    expect($saved->code)->toBe($ledger->code);
});

it('refuses a row id that belongs to another ledger and changes nothing', function () {
    $mine = saveLedger($this, 'customer', ['ledger' => ['name' => 'Mine']]);
    $theirs = saveLedger($this, 'customer', ['ledger' => ['name' => 'Theirs']]);
    $theirAddress = $theirs->addresses()->first();
    $theirContact = $theirs->contacts()->first();

    foreach ([
        ['addresses' => [addressRow($this->acme, ['id' => (string) $theirAddress->id, 'line1' => 'Hijacked'])]],
        ['contacts' => [['id' => (string) $theirContact->id, 'name' => 'Hijacked', 'designation' => '', 'phone' => '', 'mobile' => '9876543210', 'email' => '', 'is_primary' => true]]],
    ] as $tampered) {
        $threw = false;
        try {
            inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Customer, ledgerInput($this->acme, 'customer', ['ledger' => ['name' => 'Mine edited'], ...$tampered]), $mine, $this->accounts));
        } catch (ValidationException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    }

    expect($mine->fresh()->name)->toBe('Mine')                          // the whole save rolled back
        ->and($theirAddress->fresh()->line1)->toBe('12 Ashram Road')
        ->and($theirContact->fresh()->name)->toBe('Meera Shah');
});

it('refuses a row id that does not exist at all', function () {
    $ledger = saveLedger($this, 'customer');

    expect(ledgerErrors($this, 'customer', ['addresses' => [addressRow($this->acme, ['id' => '999999'])]], null, $ledger))->not->toBe([]);
});

it('writes only into the company it runs for', function () {
    saveLedger($this, 'customer');
    saveLedger($this, 'customer', ['ledger' => ['name' => 'Globex customer']], makeUser(['Accounts User'], [$this->globex]), null, $this->globex);

    $ids = fn ($model, $company) => $model::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->count();

    expect($ids(Ledger::class, $this->acme))->toBe(1)->and($ids(Ledger::class, $this->globex))->toBe(1)
        ->and($ids(LedgerAddress::class, $this->acme))->toBe(1)->and($ids(LedgerAddress::class, $this->globex))->toBe(1);
});

// ======================================================================================================
// Bank details: the sensitive part
// ======================================================================================================

it('stores the bank account encrypted, with its last four digits and a keyed hash, and masks it', function () {
    $ledger = saveLedger($this, 'bank');
    $bank = $ledger->bankDetail;
    $raw = DB::table('ledger_bank_details')->where('ledger_id', $ledger->id)->first();

    expect($bank->account_number)->toBe('50100123456789')
        ->and($raw->account_number)->not->toContain('50100123456789')->and($raw->account_number)->not->toBe('50100123456789')
        ->and($raw->account_last4)->toBe('6789')
        ->and($raw->account_number_hash)->toBe(LedgerBankDetail::hashNumber('50100123456789'))->and($raw->account_number_hash)->not->toContain('50100123456789')
        ->and($bank->masked())->toBe('XXXXXX6789')
        ->and($bank->toArray())->not->toHaveKey('account_number')->not->toHaveKey('account_number_hash');
});

it('requires the bank details to be complete and well formed', function (string $field, string $value) {
    $bank = [...ledgerInput($this->acme, 'bank')['bank'], $field => $value];

    expect(ledgerErrors($this, 'bank', ['bank' => $bank]))->toHaveKey("bank.{$field}");
})->with([
    'no bank name' => ['bank_name', ''],
    'no account number' => ['account_number', ''],
    'short account number' => ['account_number', '12345678'],
    'letters in the number' => ['account_number', '5010012345AB89'],
    'no IFSC' => ['ifsc', ''],
    'bad IFSC' => ['ifsc', 'HDFC1001234'],
    'unknown account type' => ['account_type', 'mystery'],
]);

it('accepts an account number typed with spaces and an IFSC in lower case', function () {
    $ledger = saveLedger($this, 'bank', ['bank' => [...ledgerInput($this->acme, 'bank')['bank'], 'account_number' => '5010 0123 4567 89', 'ifsc' => 'hdfc0001234']]);

    expect($ledger->bankDetail)->ifsc->toBe('HDFC0001234')->account_last4->toBe('6789');
});

it('keeps the account number when an edit leaves it blank, and replaces it when one is given', function () {
    $ledger = saveLedger($this, 'bank');
    $originalHash = $ledger->bankDetail->account_number_hash;

    $blank = [...ledgerInput($this->acme, 'bank')['bank'], 'account_number' => '', 'branch' => 'Ellisbridge'];
    $saved = inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Bank, ledgerInput($this->acme, 'bank', ['bank' => $blank]), $ledger, $this->accounts));
    expect($saved->bankDetail)->branch->toBe('Ellisbridge')->account_number->toBe('50100123456789')->account_number_hash->toBe($originalHash);

    $changed = [...$blank, 'account_number' => '60200987654321'];
    $saved = inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Bank, ledgerInput($this->acme, 'bank', ['bank' => $changed]), $saved, $this->accounts));
    expect($saved->bankDetail)->account_number->toBe('60200987654321')->account_last4->toBe('4321')
        ->and($saved->bankDetail->account_number_hash)->not->toBe($originalHash);
});

it('never lets the account number, its ciphertext or its hash reach the audit log', function () {
    $ledger = saveLedger($this, 'bank');
    saveLedger($this, 'bank', ['ledger' => ['name' => 'Second bank'], 'bank' => [...ledgerInput($this->acme, 'bank')['bank'], 'account_number' => '70300111122223']]);

    $dump = ActivityLog::withoutGlobalScopes()->get()->map(fn ($a) => json_encode([$a->properties, $a->description]))->implode(' ');
    $raw = DB::table('ledger_bank_details')->where('ledger_id', $ledger->id)->first();

    expect($dump)->not->toContain('50100123456789')->not->toContain('70300111122223')
        ->not->toContain($raw->account_number)->not->toContain($raw->account_number_hash)
        ->and($dump)->toContain('bank_details_changed')->toContain('6789');
});

it('records a change of account as an event carrying only the last four digits', function () {
    $ledger = saveLedger($this, 'bank');
    $event = ActivityLog::withoutGlobalScopes()->where('event', 'bank_details_changed')->where('subject_id', $ledger->id)->firstOrFail();

    expect($event->properties->all())->toMatchArray(['account_last4' => '6789', 'ifsc' => 'HDFC0001234', 'created' => true])
        ->and($event->causer_id)->toBe($this->accounts->id)->and($event->company_id)->toBe($this->acme->id);

    // saving again without changing the account records no new event
    inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Bank, ledgerInput($this->acme, 'bank', ['bank' => [...ledgerInput($this->acme, 'bank')['bank'], 'account_number' => '']]), $ledger, $this->accounts));
    expect(ActivityLog::withoutGlobalScopes()->where('event', 'bank_details_changed')->count())->toBe(1);
});

it('ignores bank details from someone who may not see them, and never removes the ones on file', function () {
    $ledger = saveLedger($this, 'supplier', ['bank' => ledgerInput($this->acme, 'bank')['bank']]);   // Accounts may
    expect($ledger->bankDetail()->exists())->toBeTrue();

    // Purchase can edit suppliers but holds no sensitive permission: their form carries no bank section.
    $edit = ledgerInput($this->acme, 'supplier', ['ledger' => ['remarks' => 'by purchase'], 'bank' => null]);
    $saved = inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Supplier, $edit, $ledger, $this->purchase));
    expect($saved->bankDetail)->not->toBeNull()->account_number->toBe('50100123456789');

    // ...and even a hostile payload cannot alter it
    $hostile = ledgerInput($this->acme, 'supplier', ['bank' => [...ledgerInput($this->acme, 'bank')['bank'], 'account_number' => '99999999999999', 'ifsc' => 'ICIC0009999']]);
    $saved = inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Supplier, $hostile, $saved, $this->purchase));
    expect($saved->bankDetail)->account_number->toBe('50100123456789')->ifsc->toBe('HDFC0001234');

    // and they cannot create one either
    $fresh = saveLedger($this, 'supplier', ['ledger' => ['name' => 'No bank'], 'bank' => ledgerInput($this->acme, 'bank')['bank']], $this->purchase);
    expect($fresh->bankDetail()->exists())->toBeFalse();
});

it('does not keep bank details for cash and expense ledgers', function () {
    $cash = saveLedger($this, 'cash', ['bank' => ledgerInput($this->acme, 'bank')['bank']]);
    $expense = saveLedger($this, 'expense', ['bank' => ledgerInput($this->acme, 'bank')['bank']]);

    expect($cash->bankDetail()->exists())->toBeFalse()->and($expense->bankDetail()->exists())->toBeFalse();
});

it('removes the bank account when the form no longer has one, for someone allowed to', function () {
    $ledger = saveLedger($this, 'supplier', ['bank' => ledgerInput($this->acme, 'bank')['bank']]);

    $saved = inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Supplier, ledgerInput($this->acme, 'supplier', ['bank' => null]), $ledger, $this->accounts));

    expect($saved->bankDetail()->exists())->toBeFalse();

    // and putting one back restores the same row rather than tripping the unique key
    $again = inCompany($this->acme, fn () => app(LedgerService::class)->save(LedgerType::Supplier, ledgerInput($this->acme, 'supplier', ['bank' => ledgerInput($this->acme, 'bank')['bank']]), $saved, $this->accounts));
    expect($again->bankDetail()->exists())->toBeTrue()
        ->and(LedgerBankDetail::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->where('ledger_id', $ledger->id)->count())->toBe(1);
});

it('lets the same bank account number sit on two ledgers without exposing it', function () {
    $a = saveLedger($this, 'bank', ['ledger' => ['name' => 'Bank A']]);
    $b = saveLedger($this, 'bank', ['ledger' => ['name' => 'Bank B']]);

    // the keyed hash is what a future duplicate check compares
    expect($a->bankDetail->account_number_hash)->toBe($b->bankDetail->account_number_hash);
});
