<?php

use App\Modules\Masters\Exceptions\RateNotFoundException;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Services\RateMaintenance;
use App\Modules\Masters\Services\RateService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
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
    $this->box = unitId($this->acme, 'BOX');

    $this->maintenance = app(RateMaintenance::class);
    $this->approvals = app(ApprovalService::class);

    // save a rate the way the form does, inside the company
    $this->save = fn (array $rate = [], ?Rate $editing = null, $by = null, $item = null, $location = null) => inCompany(
        $this->acme,
        fn () => $this->maintenance->save($editing, rateInput($item ?? $this->item, $location ?? $this->ahd, $rate), $by ?? $this->accounts),
    );

    // which fields the validator complained about (empty = it saved)
    $this->complaints = function (array $rate, ?Rate $editing = null) {
        try {
            ($this->save)($rate, $editing);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    $this->submit = fn (Rate $rate, $by = null) => inCompany($this->acme, fn () => $this->approvals->submit($rate->fresh(), $by ?? $this->accounts));
    $this->approve = fn (Rate $rate, $by = null) => inCompany($this->acme, fn () => $this->approvals->approve($rate->fresh(), $by ?? $this->approver));
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Saving
// ======================================================================================================

it('saves a draft rate with exact precision, for the right company, branch and person', function () {
    $rate = ($this->save)(['rate' => '125.5', 'effective_from' => '2026-04-01', 'remarks' => '  Revised   price list  ']);

    expect($rate->rate)->toBe('125.5000')->and($rate->approval_status)->toBe(ApprovalStatus::Draft)->and($rate->status)->toBe('active')
        ->and($rate->company_id)->toBe($this->acme->id)->and($rate->location_id)->toBe($this->ahd->id)
        ->and($rate->item_id)->toBe($this->item->id)->and($rate->unit_id)->toBe($this->item->base_unit_id)->and($rate->price_list_id)->toBeNull()
        ->and($rate->effective_from->toDateString())->toBe('2026-04-01')->and($rate->effective_to)->toBeNull()
        ->and($rate->created_by)->toBe($this->accounts->id)->and($rate->remarks)->toBe('Revised price list');
});

it('accepts a thousands separator and up to four decimals, and refuses everything else as a rate', function () {
    expect(($this->save)(['rate' => '1,250.5', 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31'])->rate)->toBe('1250.5000')
        ->and(($this->save)(['rate' => '0.0001', 'effective_from' => '2026-02-01', 'effective_to' => '2026-02-28'])->rate)->toBe('0.0001')
        ->and(($this->save)(['rate' => '99999999999.9999', 'effective_from' => '2026-03-01', 'effective_to' => '2026-03-31'])->rate)->toBe('99999999999.9999');

    foreach (['', '0', '0.0000', '-1', '-0.5', 'abc', '12abc', '1.23456', '123456789012', '1e5', '1.2.3', ' '] as $bad) {
        expect(($this->complaints)(['rate' => $bad, 'effective_from' => '2030-01-01']))->toBe(['rate.rate'], "rate \"{$bad}\" should be refused");
    }
});

it('checks the dates', function () {
    foreach ([
        'start missing' => [['effective_from' => ''], 'rate.effective_from'],
        'start not a date' => [['effective_from' => 'next week'], 'rate.effective_from'],
        'start 31 Feb' => [['effective_from' => '2026-02-31'], 'rate.effective_from'],
        'start too early' => [['effective_from' => '1999-12-31'], 'rate.effective_from'],
        'start too far' => [['effective_from' => '2101-01-01'], 'rate.effective_from'],
        'end not a date' => [['effective_to' => 'soon'], 'rate.effective_to'],
        'end before start' => [['effective_from' => '2026-05-01', 'effective_to' => '2026-04-30'], 'rate.effective_to'],
        'end too far' => [['effective_to' => '2101-01-01'], 'rate.effective_to'],
    ] as $label => [$rate, $field]) {
        expect(in_array($field, ($this->complaints)($rate), true))->toBeTrue("{$label} should be refused");
    }

    // a one-day rate is fine
    expect(($this->complaints)(['effective_from' => '2026-05-01', 'effective_to' => '2026-05-01']))->toBe([]);
});

it('only lets an approved, active item be given a rate, in a unit it has', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Still in Purchase']]);
    $submitted = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Waiting']], 'submitted');
    $foreign = makeItem($this->globex, makeUser(['Purchase User'], [$this->globex]), [], 'approved');
    $this->item->forceFill(['status' => 'inactive'])->save();
    $inactive = $this->item;

    foreach ([$draft, $submitted, $foreign, $inactive] as $item) {
        expect(inCompany($this->acme, fn () => ($this->complaints)(rateInput($item, $this->ahd)['rate'])))->toContain('rate.item_id');
    }

    $this->item->forceFill(['status' => 'active'])->save();
    $kg = unitId($this->acme, 'KG');
    $otherCompanyUnit = unitId($this->globex, 'NOS');

    expect(($this->complaints)(['unit_id' => (string) $this->box]))->toBe([])                 // a conversion of the item
        ->and(($this->complaints)(['unit_id' => (string) $kg, 'effective_from' => '2030-01-01']))->toBe(['rate.unit_id'])
        ->and(($this->complaints)(['unit_id' => (string) $otherCompanyUnit, 'effective_from' => '2030-01-01']))->toBe(['rate.unit_id'])
        ->and(($this->complaints)(['unit_id' => '', 'effective_from' => '2030-01-01']))->toBe(['rate.unit_id']);
});

it('only offers the branches, price lists and units that are the company’s own and switched on', function () {
    $dealer = makePriceList($this->acme);
    $off = makePriceList($this->acme, 'OLD', 'Old list', 'inactive');
    $theirs = makePriceList($this->globex, 'THEIRS', 'Their list');
    $this->mum->forceFill(['status' => 'inactive'])->save();

    expect(($this->complaints)(['price_list_id' => (string) $dealer->id]))->toBe([])
        ->and(($this->complaints)(['price_list_id' => (string) $off->id, 'effective_from' => '2030-01-01']))->toBe(['rate.price_list_id'])
        ->and(($this->complaints)(['price_list_id' => (string) $theirs->id, 'effective_from' => '2030-01-01']))->toBe(['rate.price_list_id'])
        ->and(($this->complaints)(['price_list_id' => '99999', 'effective_from' => '2030-01-01']))->toBe(['rate.price_list_id']);

    $inCompany = fn (array $rate, $branch) => inCompany($this->acme, function () use ($rate, $branch) {
        try {
            $this->maintenance->save(null, rateInput($this->item, $branch, $rate), $this->accounts);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    });

    expect($inCompany(['effective_from' => '2031-01-01'], $this->mum))->toBe(['rate.location_id'])            // switched off
        ->and($inCompany(['effective_from' => '2031-01-01'], $this->globexBranch))->toBe(['rate.location_id']); // another company's
});

it('does not let someone price a branch they do not work in', function () {
    $ahdOnly = makeUser(['Accounts User'], [$this->acme], [$this->ahd]);
    $this->actingAs($ahdOnly);

    $try = function ($branch) use ($ahdOnly) {
        try {
            $this->maintenance->save(null, rateInput($this->item, $branch), $ahdOnly);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    expect($try($this->mum))->toBe(['rate.location_id'])->and($try($this->ahd))->toBe([]);
});

// ======================================================================================================
// Overlaps
// ======================================================================================================

it('refuses a period that overlaps a live rate for the same item, branch, unit and price list', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01', 'effective_to' => '2026-09-30'], 'approved', $this->approver);

    $overlapping = [
        'shares the last day' => ['2026-09-30', '2026-12-31'],
        'shares the first day' => ['2026-01-01', '2026-04-01'],
        'sits inside' => ['2026-06-01', '2026-06-30'],
        'covers it' => ['2026-01-01', '2026-12-31'],
        'open-ended from before it' => ['2025-01-01', ''],
        'open-ended from inside it' => ['2026-08-01', ''],
    ];
    foreach ($overlapping as $label => [$from, $to]) {
        expect(($this->complaints)(['effective_from' => $from, 'effective_to' => $to]))->toBe(['rate.effective_from'], "{$label} should be refused");
    }

    // touching is not overlapping
    expect(($this->complaints)(['effective_from' => '2026-10-01', 'effective_to' => '2026-12-31']))->toBe([])
        ->and(($this->complaints)(['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']))->toBe([]);
});

it('treats an open-ended rate as running for ever', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01'], 'approved', $this->approver);

    expect(($this->complaints)(['effective_from' => '2031-01-01', 'effective_to' => '2031-01-31']))->toBe(['rate.effective_from'])
        ->and(($this->complaints)(['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']))->toBe([]);
});

it('lets the same period stand for a different branch, unit or price list', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    $dealer = makePriceList($this->acme);

    expect(inCompany($this->acme, fn () => $this->maintenance->save(null, rateInput($this->item, $this->mum), $this->accounts)))->toBeInstanceOf(Rate::class)
        ->and(($this->complaints)(['unit_id' => (string) $this->box]))->toBe([])
        ->and(($this->complaints)(['price_list_id' => (string) $dealer->id]))->toBe([]);

    // …but the same price list twice is the same slot
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['price_list_id' => (string) $dealer->id, 'effective_from' => '2027-01-01', 'effective_to' => '2027-03-31'], 'approved', $this->approver);
    expect(($this->complaints)(['price_list_id' => (string) $dealer->id, 'effective_from' => '2027-02-01', 'effective_to' => '2027-02-28']))->toBe(['rate.effective_from']);
});

it('counts waiting and approved rates (even switched off), but not drafts or rejected ones', function () {
    $pending = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-01-01', 'effective_to' => '2026-01-31'], 'submitted');
    $approved = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-02-01', 'effective_to' => '2026-02-28'], 'approved', $this->approver);
    $approved->forceFill(['status' => 'inactive'])->save();
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-03-01', 'effective_to' => '2026-03-31'], 'draft');
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01', 'effective_to' => '2026-04-30'], 'rejected', $this->approver);

    expect(($this->complaints)(['effective_from' => '2026-01-15', 'effective_to' => '2026-01-20']))->toBe(['rate.effective_from'])     // waiting
        ->and(($this->complaints)(['effective_from' => '2026-02-15', 'effective_to' => '2026-02-20']))->toBe(['rate.effective_from'])  // approved, switched off
        ->and(($this->complaints)(['effective_from' => '2026-03-15', 'effective_to' => '2026-03-20']))->toBe([])                       // only a draft there
        ->and(($this->complaints)(['effective_from' => '2026-04-15', 'effective_to' => '2026-04-20']))->toBe([]);                      // only a rejected one
});

it('names the rate it clashes with so the person can fix it', function () {
    makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01', 'effective_to' => '2026-09-30'], 'approved', $this->approver);

    try {
        ($this->save)(['effective_from' => '2026-08-01']);
    } catch (ValidationException $e) {
        expect($e->errors()['rate.effective_from'][0])->toContain('1 Apr 2026 – 30 Sep 2026')->toContain('approved')->toContain('end-date the other one');

        return;
    }

    $this->fail('the overlap was not refused');
});

it('does not clash with itself when a draft is saved again', function () {
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01', 'effective_to' => '2026-09-30']);

    expect(($this->complaints)(['effective_from' => '2026-04-01', 'effective_to' => '2026-10-31', 'rate' => '130'], $draft))->toBe([])
        ->and($draft->fresh()->rate)->toBe('130.0000')->and($draft->fresh()->effective_to->toDateString())->toBe('2026-10-31');
});

// ======================================================================================================
// Submitting and approving
// ======================================================================================================

it('lets only one of two overlapping drafts be submitted', function () {
    $first = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01']);
    $second = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-06-01']);   // a draft may overlap another draft

    ($this->submit)($first);

    expect(fn () => ($this->submit)($second))->toThrow(ApprovalStateException::class, 'does not overlap another rate')
        ->and($second->fresh()->approval_status)->toBe(ApprovalStatus::Draft);

    // once the first is rejected the second may go
    inCompany($this->acme, fn () => $this->approvals->reject($first->fresh(), $this->approver, 'Not this one'));
    ($this->submit)($second);

    expect($second->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('will not send a rate for approval whose item, branch, unit or price list has gone off since it was drafted', function () {
    $dealer = makePriceList($this->acme);
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['price_list_id' => (string) $dealer->id]);

    $dealer->forceFill(['status' => 'inactive'])->save();
    expect(fn () => ($this->submit)($draft))->toThrow(ApprovalStateException::class, 'an active price list');
    $dealer->forceFill(['status' => 'active'])->save();

    $this->ahd->forceFill(['status' => 'inactive'])->save();
    expect(fn () => ($this->submit)($draft))->toThrow(ApprovalStateException::class, 'an active branch');
    $this->ahd->forceFill(['status' => 'active'])->save();

    inCompany($this->acme, fn () => $this->approvals->reopen($this->item->fresh(), $this->accounts, 'Needs a correction'));
    expect(fn () => ($this->submit)($draft))->toThrow(ApprovalStateException::class, 'an item that is approved and active');
});

it('approves through the normal engine: someone else decides, and the rate becomes available', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'submitted');

    expect(fn () => ($this->approve)($rate, $this->accounts))->toThrow(AuthorizationException::class);   // maker–checker

    ($this->approve)($rate);

    $rate->refresh();
    expect($rate->approval_status)->toBe(ApprovalStatus::Approved)->and($rate->approved_by)->toBe($this->approver->id)->and($rate->isUsable())->toBeTrue();
});

it('cannot be approved once the price or dates were changed after submission', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'submitted');

    $rate->forceFill(['rate' => '1.0000'])->save();       // tampering around the form
    expect(fn () => ($this->approve)($rate))->toThrow(ApprovalStateException::class);

    $rate->forceFill(['rate' => '125.5000'])->save();     // put back exactly: the same thing was signed off
    ($this->approve)($rate);
    expect($rate->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});

it('does not treat remarks or switching on and off as a change to what was signed off', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'submitted');
    $rate->forceFill(['remarks' => 'a note added later', 'status' => 'inactive'])->save();

    ($this->approve)($rate);

    expect($rate->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});

it('locks a rate while it waits and once it is approved', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'submitted');

    expect(fn () => ($this->save)(['rate' => '1'], $rate))->toThrow(ApprovalStateException::class);

    ($this->approve)($rate);
    expect(fn () => ($this->save)(['rate' => '1'], $rate->fresh()))->toThrow(ApprovalStateException::class)
        ->and($rate->fresh()->rate)->toBe('125.5000');
});

it('can be re-opened, corrected and approved again, with the first approval kept on record', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);

    inCompany($this->acme, fn () => $this->approvals->reopen($rate->fresh(), $this->accounts, 'Typed the wrong price'));
    $corrected = ($this->save)(['rate' => '135'], $rate->fresh());
    ($this->submit)($corrected);
    ($this->approve)($corrected);

    expect($rate->fresh()->rate)->toBe('135.0000')->and($rate->fresh()->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($rate->approvals()->count())->toBe(2)->and($rate->approvals()->max('cycle'))->toBe(2);
});

it('fixes who and where a rate is for once it has been approved', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, [], 'approved', $this->approver);
    inCompany($this->acme, fn () => $this->approvals->reopen($rate->fresh(), $this->accounts, 'Correction'));
    $dealer = makePriceList($this->acme);

    expect(($this->complaints)(['location_id' => (string) $this->mum->id], $rate->fresh()))->toBe(['rate.location_id'])
        ->and(($this->complaints)(['unit_id' => (string) $this->box], $rate->fresh()))->toBe(['rate.unit_id'])
        ->and(($this->complaints)(['price_list_id' => (string) $dealer->id], $rate->fresh()))->toBe(['rate.price_list_id'])
        ->and(($this->complaints)(['rate' => '140', 'effective_to' => '2026-12-31'], $rate->fresh()))->toBe([]);    // price and dates may be corrected
});

// ======================================================================================================
// Ending a rate early
// ======================================================================================================

it('ends an open-ended approved rate on a date, keeps it approved, and records who, why and what changed', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01'], 'approved', $this->approver);

    inCompany($this->acme, fn () => $this->maintenance->endDate($rate->fresh(), '2026-11-30', 'New price list from December', $this->accounts));

    $entries = ActivityLog::query()->where('subject_type', 'rate')->where('subject_id', $rate->id)->where('event', 'ended_early')->get();

    expect($rate->fresh()->effective_to->toDateString())->toBe('2026-11-30')->and($rate->fresh()->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($entries)->toHaveCount(1)
        ->and($entries->first()->properties['old'])->toBe(['effective_to' => null])->and($entries->first()->properties['attributes'])->toBe(['effective_to' => '2026-11-30'])
        ->and($entries->first()->properties['comment'])->toBe('New price list from December')->and((int) $entries->first()->causer_id)->toBe($this->accounts->id)
        // one entry carries the reason: not that and a second, bare "updated" row for the same change
        ->and(ActivityLog::query()->where('subject_type', 'rate')->where('subject_id', $rate->id)->where('event', 'updated')->get()
            ->filter(fn ($e) => array_key_exists('effective_to', (array) ($e->properties['attributes'] ?? [])))->count())->toBe(0);
});

it('lets the next rate start the day after the old one ends', function () {
    $old = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01'], 'approved', $this->approver);
    expect(($this->complaints)(['effective_from' => '2026-12-01', 'rate' => '140']))->toBe(['rate.effective_from']);          // the old one still runs for ever

    inCompany($this->acme, fn () => $this->maintenance->endDate($old->fresh(), '2026-11-30', 'Price change', $this->accounts));

    expect(($this->complaints)(['effective_from' => '2026-12-01', 'rate' => '140']))->toBe([]);
});

it('only ever shortens a rate, and never rewrites the past', function () {
    $bounded = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01', 'effective_to' => '2026-12-31'], 'approved', $this->approver);
    $future = makeRate($this->acme, $this->accounts, $this->item, $this->mum, ['effective_from' => '2027-01-01'], 'approved', $this->approver);

    $end = function ($rate, string $on, string $reason = 'A reason') {
        try {
            inCompany($this->acme, fn () => $this->maintenance->endDate($rate->fresh(), $on, $reason, $this->accounts));
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    expect($end($bounded, '2026-12-31'))->toBe(['on'])         // already ends then
        ->and($end($bounded, '2027-03-31'))->toBe(['on'])      // would lengthen it
        ->and($end($bounded, '2026-10-11'))->toBe(['on'])      // yesterday
        ->and($end($bounded, 'someday'))->toBe(['on'])
        ->and($end($bounded, ''))->toBe(['on'])
        ->and($end($bounded, '2026-11-30', ''))->toBe(['reason'])
        ->and($end($bounded, '2026-11-30', 'ab'))->toBe(['reason'])
        ->and($end($future, '2026-12-31'))->toBe(['on'])       // before it even starts
        ->and($bounded->fresh()->effective_to->toDateString())->toBe('2026-12-31');

    // today itself is allowed (it applies today, then stops), and so is the day it starts
    expect($end($bounded, '2026-10-12'))->toBe([])->and($bounded->fresh()->effective_to->toDateString())->toBe('2026-10-12')
        ->and($end($future, '2027-01-01'))->toBe([]);
});

it('ends only approved rates', function () {
    $draft = makeRate($this->acme, $this->accounts, $this->item, $this->ahd);
    $waiting = makeRate($this->acme, $this->accounts, $this->item, $this->mum, [], 'submitted');

    foreach ([$draft, $waiting] as $rate) {
        expect(fn () => inCompany($this->acme, fn () => $this->maintenance->endDate($rate->fresh(), '2026-12-31', 'Reason', $this->accounts)))->toThrow(ApprovalStateException::class);
    }
});

it('stops offering the rate after its new last day', function () {
    $rate = makeRate($this->acme, $this->accounts, $this->item, $this->ahd, ['effective_from' => '2026-04-01'], 'approved', $this->approver);
    inCompany($this->acme, fn () => $this->maintenance->endDate($rate->fresh(), '2026-10-31', 'Contract over', $this->accounts));

    $lookup = fn (string $day) => inCompany($this->acme, fn () => app(RateService::class)->getRate($this->item, $this->ahd, $this->item->base_unit_id, $day));

    expect($lookup('2026-10-31')->rateId)->toBe($rate->id)
        ->and(fn () => $lookup('2026-11-01'))->toThrow(RateNotFoundException::class, 'ended on 31 Oct 2026');
});
