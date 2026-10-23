<?php

use App\Modules\Masters\Enums\RateFailure;
use App\Modules\Masters\Exceptions\RateNotFoundException;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\RateService;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->item = makeItem($this->acme, $this->purchase, [], 'approved');   // base unit NOS, 1 BOX = 100 NOS
    $this->nos = $this->item->base_unit_id;
    $this->box = unitId($this->acme, 'BOX');

    $this->service = app(RateService::class);

    // what a transaction would do: ask inside the company, and get either a quote or the reason there is none
    $this->quote = fn (...$args) => inCompany($this->acme, fn () => $this->service->getRate(...$args));
    $this->failure = function (callable $call): ?RateFailure {
        try {
            $call();
        } catch (RateNotFoundException $e) {
            return $e->reason;
        }

        return null;
    };
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// The happy path
// ======================================================================================================

it('returns the approved rate in force, and says where it came from', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '125.5', 'effective_from' => '2026-04-01'], 'approved', $this->approver);

    $quote = ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12');

    expect($quote->rateId)->toBe($rate->id)->and($quote->rate)->toBe('125.5000')
        ->and($quote->itemId)->toBe($this->item->id)->and($quote->locationId)->toBe($this->ahd->id)->and($quote->unitId)->toBe($this->nos)
        ->and($quote->priceListId)->toBeNull()->and($quote->source)->toBe('standard')
        ->and($quote->effectiveFrom)->toBe('2026-04-01')->and($quote->effectiveTo)->toBeNull()->and($quote->asOf)->toBe('2026-10-12')
        ->and($quote->toArray())->toMatchArray(['rate' => '125.5000', 'rate_id' => $rate->id, 'source' => 'standard']);
});

it('takes ids or models, a string, a Carbon date, or today', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-10-01'], 'approved', $this->approver);

    $byId = ($this->quote)($this->item->id, $this->ahd->id, $this->nos, '2026-10-12');
    $byModel = ($this->quote)($this->item, $this->ahd, Unit::query()->find($this->nos), Carbon::parse('2026-10-12 23:59:59'));
    $today = ($this->quote)($this->item, $this->ahd, $this->nos);

    expect($byModel->rateId)->toBe($byId->rateId)->and($today->rateId)->toBe($byId->rateId)->and($today->asOf)->toBe('2026-10-12');
});

it('counts both ends of the period', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01', 'effective_to' => '2026-09-30'], 'approved', $this->approver);

    $on = fn (string $day) => ($this->quote)($this->item, $this->ahd, $this->nos, $day)->effectiveFrom;

    expect($on('2026-04-01'))->toBe('2026-04-01')->and($on('2026-09-30'))->toBe('2026-04-01')->and($on('2026-07-15'))->toBe('2026-04-01');
});

it('says a rate has not started yet, or has ended, and never hands it out', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01', 'effective_to' => '2026-09-30'], 'approved', $this->approver);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2027-01-01'], 'approved', $this->approver);

    $reason = fn (string $day) => ($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, $day));

    expect($reason('2026-03-31'))->toBe(RateFailure::NotYetEffective)
        ->and($reason('2026-10-01'))->toBe(RateFailure::NotYetEffective)      // a gap, and the next rate starts in 2027
        ->and($reason('2026-12-31'))->toBe(RateFailure::NotYetEffective);

    try {
        ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12');
    } catch (RateNotFoundException $e) {
        expect($e->getMessage())->toContain('The next approved rate starts on 1 Jan 2027');
    }

    // with nothing further on, the same gap reads as "ended"
    Rate::query()->where('effective_from', '2027-01-01')->update(['effective_from' => '2026-06-01', 'effective_to' => '2026-06-30']);
    expect($reason('2026-10-12'))->toBe(RateFailure::Expired);
});

// ======================================================================================================
// Only approved rates
// ======================================================================================================

it('ignores a rate until it is approved: draft, submitted and rejected are all refused', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);
    $find = fn () => ($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12'));

    expect($find())->toBe(RateFailure::NotApproved);                                           // draft

    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($rate, $this->accounts));
    expect($find())->toBe(RateFailure::NotApproved);                                           // waiting

    inCompany($this->acme, fn () => app(ApprovalService::class)->reject($rate->fresh(), $this->approver, 'Check the price'));
    expect($find())->toBe(RateFailure::NotApproved);                                           // rejected

    inCompany($this->acme, function () use ($rate) {
        app(ApprovalService::class)->submit($rate->fresh(), $this->accounts);
        app(ApprovalService::class)->approve($rate->fresh(), $this->approver);
    });
    expect($find())->toBeNull();                                                               // approved
});

it('stops offering a rate the moment it is re-opened', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    expect(($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12')->rateId)->toBe($rate->id);

    inCompany($this->acme, fn () => app(ApprovalService::class)->reopen($rate->fresh(), $this->accounts, 'Wrong price'));

    expect(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12')))->toBe(RateFailure::NotApproved);
});

it('does not offer a rate that is switched off, and offers it again when it is switched on', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    $find = fn () => ($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12'));

    $rate->forceFill(['status' => 'inactive'])->save();
    expect($find())->toBe(RateFailure::Inactive);

    $rate->forceFill(['status' => 'active'])->save();
    expect($find())->toBeNull();
});

it('does not offer the rate of an item that is no longer usable', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    $find = fn () => ($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12'));

    expect($find())->toBeNull();

    $this->item->forceFill(['status' => 'inactive'])->save();
    expect($find())->toBe(RateFailure::ItemNotAvailable);

    $this->item->forceFill(['status' => 'active'])->save();
    inCompany($this->acme, fn () => app(ApprovalService::class)->reopen($this->item->fresh(), $this->accounts, 'Needs a correction'));
    expect($find())->toBe(RateFailure::ItemNotAvailable);

    $this->item->refresh()->delete();
    expect($find())->toBe(RateFailure::ItemNotAvailable);

    expect(($this->failure)(fn () => ($this->quote)(999999, $this->ahd, $this->nos, '2026-10-12')))->toBe(RateFailure::ItemNotAvailable);
});

it('does not offer a deleted rate', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    $rate->delete();

    expect(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12')))->toBe(RateFailure::NoRate);
});

// ======================================================================================================
// Branch, unit, price list
// ======================================================================================================

it('returns the rate of the branch asked for, never another branch’s', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '100'], 'approved', $this->approver);
    makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['rate' => '110'], 'approved', $this->approver);

    expect(($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12')->rate)->toBe('100.0000')
        ->and(($this->quote)($this->item, $this->mum, $this->nos, '2026-10-12')->rate)->toBe('110.0000');

    $other = makeLocation($this->acme, 'SRT');
    expect(($this->failure)(fn () => ($this->quote)($this->item, $other, $this->nos, '2026-10-12')))->toBe(RateFailure::NoRate);
});

it('refuses a branch the caller does not work in, an inactive one, and another company’s', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->mum, [], 'approved', $this->approver);
    $ahdOnly = makeUser(['Sales Manager'], [$this->acme], [$this->ahd]);
    $this->actingAs($ahdOnly);

    expect(($this->failure)(fn () => $this->service->getRate($this->item, $this->mum, $this->nos, '2026-10-12')))->toBe(RateFailure::LocationNotAvailable)
        ->and(($this->failure)(fn () => $this->service->getRate($this->item, $this->globexBranch, $this->nos, '2026-10-12')))->toBe(RateFailure::LocationNotAvailable)
        ->and(($this->failure)(fn () => $this->service->getRate($this->item, 999999, $this->nos, '2026-10-12')))->toBe(RateFailure::LocationNotAvailable);

    $this->mum->forceFill(['status' => 'inactive'])->save();
    $everywhere = makeUser(['Sales Manager'], [$this->acme]);
    $this->actingAs($everywhere);

    expect(($this->failure)(fn () => $this->service->getRate($this->item, $this->mum, $this->nos, '2026-10-12')))->toBe(RateFailure::LocationNotAvailable);
});

it('wants exactly the unit asked for: a rate per Nos is not a rate per Box, and no conversion is guessed', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '5'], 'approved', $this->approver);

    expect(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->box, '2026-10-12')))->toBe(RateFailure::NoRate);

    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '480', 'unit_id' => (string) $this->box], 'approved', $this->approver);

    expect(($this->quote)($this->item, $this->ahd, $this->box, '2026-10-12')->rate)->toBe('480.0000')
        ->and(($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12')->rate)->toBe('5.0000');
});

it('refuses a unit the item does not have, or one that is switched off', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);

    expect(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, unitId($this->acme, 'KG'), '2026-10-12')))->toBe(RateFailure::UnitNotAllowed)
        ->and(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, unitId($this->globex, 'NOS'), '2026-10-12')))->toBe(RateFailure::UnitNotAllowed)
        ->and(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, 999999, '2026-10-12')))->toBe(RateFailure::UnitNotAllowed);

    Unit::withoutGlobalScopes()->whereKey($this->nos)->update(['status' => 'inactive']);
    expect(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12')))->toBe(RateFailure::UnitNotAllowed);
});

it('uses a price list’s own rate for it, the standard rate for everyone else, and says which', function () {
    $dealer = makePriceList($this->acme);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '100'], 'approved', $this->approver);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '90', 'price_list_id' => (string) $dealer->id], 'approved', $this->approver);

    $forDealer = ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12', $dealer);
    $standard = ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12');

    expect($forDealer->rate)->toBe('90.0000')->and($forDealer->source)->toBe('price_list')->and($forDealer->priceListId)->toBe($dealer->id)
        ->and($standard->rate)->toBe('100.0000')->and($standard->source)->toBe('standard')->and($standard->priceListId)->toBeNull();
});

it('falls back to the standard rate when the price list has none for that day — only if allowed', function () {
    $dealer = makePriceList($this->acme);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '100'], 'approved', $this->approver);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '90', 'price_list_id' => (string) $dealer->id, 'effective_from' => '2027-01-01'], 'approved', $this->approver);

    $quote = ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12', $dealer);
    expect($quote->rate)->toBe('100.0000')->and($quote->source)->toBe('standard')->and($quote->priceListId)->toBeNull();

    expect(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12', $dealer, false)))->toBe(RateFailure::NotYetEffective);
});

it('never invents a standard rate out of a price list’s rate', function () {
    $dealer = makePriceList($this->acme);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '90', 'price_list_id' => (string) $dealer->id], 'approved', $this->approver);

    expect(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12')))->toBe(RateFailure::NoRate);
});

it('refuses a price list that is switched off, or does not exist', function () {
    $dealer = makePriceList($this->acme);
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['price_list_id' => (string) $dealer->id], 'approved', $this->approver);
    $dealer->forceFill(['status' => 'inactive'])->save();

    expect(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12', $dealer)))->toBe(RateFailure::PriceListNotAvailable)
        ->and(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12', 999999)))->toBe(RateFailure::PriceListNotAvailable)
        ->and(($this->failure)(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12', makePriceList($this->globex, 'DEALER', 'Theirs'))))->toBe(RateFailure::PriceListNotAvailable);
});

// ======================================================================================================
// Company isolation
// ======================================================================================================

it('never returns another company’s rate, item or branch', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    $globexUser = makeUser(['Sales Manager'], [$this->globex]);
    $globexItem = makeItem($this->globex, makeUser(['Purchase User'], [$this->globex]), [], 'approved');   // fixtures first, then sign in
    $this->actingAs($globexUser);

    expect(($this->failure)(fn () => $this->service->getRate($this->item, $this->ahd, $this->nos, '2026-10-12')))->toBe(RateFailure::ItemNotAvailable);

    // even with the id of an item of their own and a branch of the other company

    expect(($this->failure)(fn () => $this->service->getRate($globexItem, $this->ahd, $globexItem->base_unit_id, '2026-10-12')))->toBe(RateFailure::LocationNotAvailable)
        ->and(($this->failure)(fn () => $this->service->getRate($globexItem, $this->globexBranch, $globexItem->base_unit_id, '2026-10-12')))->toBe(RateFailure::NoRate);
});

// ======================================================================================================
// Ties, dates and messages
// ======================================================================================================

it('breaks a tie the same way every time: the later start, then the later approval, then the higher id', function () {
    $make = fn (string $from, string $approvedAt, string $rate) => inCompany($this->acme, function () use ($from, $approvedAt, $rate) {
        // written straight to the table, as a data fix or an old import might: the service would never allow an overlap
        $row = new Rate(['rate' => $rate, 'effective_from' => $from]);
        $row->forceFill(['company_id' => $this->acme->id, 'item_id' => $this->item->id, 'location_id' => $this->ahd->id, 'unit_id' => $this->nos, 'approval_status' => 'approved', 'approved_at' => $approvedAt]);
        $row->save();

        return $row;
    });

    $make('2026-04-01', '2026-04-01 09:00:00', '100');
    $later = $make('2026-06-01', '2026-03-01 09:00:00', '110');
    expect(($this->quote)($this->item, $this->ahd, $this->nos, '2026-07-01')->rateId)->toBe($later->id);          // later start wins, whoever was approved last

    Rate::query()->delete();
    $make('2026-04-01', '2026-04-01 09:00:00', '100');
    $newer = $make('2026-04-01', '2026-05-01 09:00:00', '120');
    expect(($this->quote)($this->item, $this->ahd, $this->nos, '2026-07-01')->rateId)->toBe($newer->id);          // same start: approved more recently

    Rate::query()->delete();
    $make('2026-04-01', '2026-04-01 09:00:00', '100');
    $higher = $make('2026-04-01', '2026-04-01 09:00:00', '130');
    expect(($this->quote)($this->item, $this->ahd, $this->nos, '2026-07-01')->rateId)->toBe($higher->id);         // same start and moment: higher id
});

it('refuses a date it cannot read instead of looking up the wrong day', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);

    foreach (['12/10/2026', '2026-10-12 10:00', '2026-13-01', '2026-02-30', 'tomorrow', ''] as $bad) {
        expect(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, $bad))->toThrow(InvalidArgumentException::class);
    }
});

it('explains why there is no rate in words a person can act on, without ever naming a price', function () {
    $message = function (callable $call): string {
        try {
            $call();
        } catch (RateNotFoundException $e) {
            return $e->getMessage();
        }

        return '';
    };

    makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['rate' => '777.7777']);                 // draft
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '888.8888', 'effective_from' => '2027-01-01'], 'approved', $this->approver);

    $none = $message(fn () => ($this->quote)($this->item, $this->ahd, $this->box, '2026-10-12'));
    $draft = $message(fn () => ($this->quote)($this->item, $this->mum, $this->nos, '2026-10-12'));
    $future = $message(fn () => ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12'));

    expect($none)->toContain('There is no rate for')->toContain('per Box')->toContain('at AHD Branch')->toContain('12 Oct 2026')->toContain('Ask Accounts to add one')
        ->and($draft)->toContain('not approved yet')
        ->and($future)->toContain('starts on 1 Jan 2027')
        ->and($none.$draft.$future)->not->toContain('777')->not->toContain('888');
});

// ======================================================================================================
// Overriding a rate on a document
// ======================================================================================================

it('records an override with the original and the new rate, the reason and the document, and changes nothing else', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '125.5'], 'approved', $this->approver);
    $manager = makeUser(['Sales Manager'], [$this->acme]);
    $quote = ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12');
    $this->actingAs($manager);

    $applied = $this->service->recordOverride($quote, '110', 'Bulk order for a long-standing customer', $manager, $this->item);

    $entry = ActivityLog::query()->where('log_name', 'rate')->where('event', 'overridden')->latest('id')->firstOrFail();

    expect($applied)->toBe('110.0000')
        ->and($entry->subject_type)->toBe('rate')->and((int) $entry->subject_id)->toBe($rate->id)->and((int) $entry->causer_id)->toBe($manager->id)
        ->and($entry->properties['old'])->toBe(['rate' => '125.5000'])->and($entry->properties['attributes'])->toBe(['rate' => '110.0000'])
        ->and($entry->properties['reason'])->toBe('Bulk order for a long-standing customer')
        ->and($entry->properties['document'])->toBe(['type' => 'item', 'id' => $this->item->id])
        ->and($entry->properties['as_of'])->toBe('2026-10-12')->and($entry->properties['item_id'])->toBe($this->item->id)
        ->and($rate->fresh()->rate)->toBe('125.5000');   // the master is untouched
});

it('lets only someone holding the override permission change a rate on a document', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    $quote = ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12');

    foreach ([$this->accounts, $this->purchase, $this->approver, makeUser(['BDE / Field Salesperson'], [$this->acme])] as $user) {
        $this->actingAs($user);
        expect(fn () => $this->service->recordOverride($quote, '100', 'Because', $user))->toThrow(AuthorizationException::class);
    }

    expect(ActivityLog::query()->where('event', 'overridden')->count())->toBe(0);
});

it('asks for a reason and a sensible rate when overriding', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['rate' => '125.5'], 'approved', $this->approver);
    $manager = makeUser(['Sales Manager'], [$this->acme]);
    $quote = ($this->quote)($this->item, $this->ahd, $this->nos, '2026-10-12');
    $this->actingAs($manager);

    $errorsFor = function (string $rate, string $reason) use ($quote, $manager): array {
        try {
            $this->service->recordOverride($quote, $rate, $reason, $manager);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    expect($errorsFor('100', ''))->toBe(['reason'])->and($errorsFor('100', 'ab'))->toBe(['reason'])
        ->and($errorsFor('0', 'Free sample'))->toBe(['rate'])->and($errorsFor('-5', 'Reason'))->toBe(['rate'])
        ->and($errorsFor('abc', 'Reason'))->toBe(['rate'])->and($errorsFor('1.23456', 'Reason'))->toBe(['rate'])
        ->and($errorsFor('125.50', 'Reason'))->toBe(['rate']);   // the same as the master: nothing to override

    expect(ActivityLog::query()->where('event', 'overridden')->count())->toBe(0);
});

it('will not record an override against a rate the person cannot see', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->mum, [], 'approved', $this->approver);
    $quote = ($this->quote)($this->item, $this->mum, $this->nos, '2026-10-12');

    $ahdManager = makeUser(['Sales Manager'], [$this->acme], [$this->ahd]);
    $globexManager = makeUser(['Sales Manager'], [$this->globex]);

    foreach ([$ahdManager, $globexManager] as $user) {
        $this->actingAs($user);
        expect(fn () => $this->service->recordOverride($quote, '100', 'Reason', $user))->toThrow(AuthorizationException::class);
    }
});
