<?php

use App\Modules\CRM\Services\DealService;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationAccess;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/*
 * Who may see and do what with a quotation: the permission matrix says what a person may do with quotations at all, QuotationAccess
 * says which — those of their own leads, their team's, everyone's — and the state of the revision says what is still possible.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);
    $this->mum = makeLocation($this->acme, 'MUM', ['state_code' => '27']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->otherManager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->loner = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->mumBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);
    $this->westTeam = makeTeam($this->acme, $this->otherManager, [$this->mumBde], 'WEST', 'West team');

    $this->bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');

    $this->quote = fn (string $upTo = 'draft') => makeQuotation(
        $this->acme, $this->bde,
        approveDocument($this->acme, makeDeal($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')])), $this->bde, $this->approver),
        [], null, $upTo, $this->approver,
    );

    $this->can = fn ($user, string $ability, $quotation) => inCompany($this->acme, fn () => Gate::forUser($user)->allows($ability, $quotation->fresh()));
    $this->who = fn (string $ability, $quotation, array $people) => collect($people)->filter(fn ($user) => ($this->can)($user, $ability, $quotation))->keys()->all();
    $this->people = [
        'owner' => $this->bde, 'colleague' => $this->bde2, 'loner' => $this->loner, 'manager' => $this->manager, 'otherManager' => $this->otherManager,
        'admin' => $this->admin, 'approver' => $this->approver, 'telecaller' => $this->telecaller, 'accounts' => $this->accounts,
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('lets the owner, the owner’s manager and an administrator open a quotation, and nobody else', function () {
    $q = ($this->quote)();

    expect(($this->who)('view', $q, $this->people))->toBe(['owner', 'manager', 'admin'])
        ->and(($this->can)($this->globexAdmin, 'view', $q))->toBeFalse()      // another company
        ->and(($this->can)($this->mumBde, 'view', $q))->toBeFalse();           // a branch they do not work in
});

it('lets those who work a draft change it and send it for approval, and nobody else', function () {
    $q = ($this->quote)();

    foreach (['update', 'submit'] as $ability) {
        expect(($this->who)($ability, $q, $this->people))->toBe(['owner', 'manager', 'admin'], $ability);
    }

    expect(($this->who)('send', $q, $this->people))->toBe([])      // not approved yet
        ->and(($this->who)('respond', $q, $this->people))->toBe([])
        ->and(($this->who)('revise', $q, $this->people))->toBe([]);
});

it('lets an approver open a quotation only while it waits for them, or after they decided it', function () {
    $q = ($this->quote)();

    expect(($this->can)($this->approver, 'view', $q))->toBeFalse();

    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($q->fresh(), $this->bde));

    expect(($this->can)($this->approver, 'view', $q))->toBeTrue()
        ->and(($this->can)($this->approver, 'approve', $q))->toBeTrue()
        ->and(($this->can)($this->approver, 'update', $q))->toBeFalse()        // reading, never editing
        ->and(($this->can)($this->bde, 'approve', $q))->toBeFalse()            // a salesperson holds no approve right
        ->and(($this->can)($this->loner, 'view', $q))->toBeFalse();

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($q->fresh(), $this->approver));
    expect(($this->can)($this->approver, 'view', $q))->toBeTrue();             // decided by them

    $other = makeUser(['Approver'], [$this->acme]);
    expect(($this->can)($other, 'view', $q))->toBeFalse();                     // no longer waiting for anyone
});

it('lets the owner send an approved draft, and nothing else changes until it is sent', function () {
    $q = ($this->quote)('approved');

    expect(($this->who)('send', $q, $this->people))->toBe(['owner', 'manager', 'admin'])
        ->and(($this->who)('update', $q, $this->people))->toBe([])             // approved: locked
        ->and(($this->who)('reopenApproval', $q, $this->people))->toBe(['owner', 'manager', 'admin'])
        ->and(($this->who)('cancel', $q, $this->people))->toBe(['owner', 'manager', 'admin'])
        ->and(($this->who)('respond', $q, $this->people))->toBe([]);
});

it('lets the owner record the answer, revise or withdraw a sent quotation, and never change it', function () {
    $q = ($this->quote)('sent');

    foreach (['respond', 'revise', 'cancel'] as $ability) {
        expect(($this->who)($ability, $q, $this->people))->toBe(['owner', 'manager', 'admin'], $ability);
    }

    foreach (['update', 'submit', 'send', 'reopenApproval'] as $ability) {
        expect(($this->who)($ability, $q, $this->people))->toBe([], $ability);
    }
});

it('gives a superseded revision no actions at all', function () {
    $q = ($this->quote)('sent');
    inCompany($this->acme, fn () => app(QuotationService::class)->revise($q->fresh(), $this->bde));

    foreach (['update', 'send', 'respond', 'revise', 'cancel'] as $ability) {
        expect(($this->who)($ability, $q, $this->people))->toBe([], $ability);
    }

    // the approver who decided it can still read what they decided
    expect(($this->who)('view', $q, $this->people))->toBe(['owner', 'manager', 'admin', 'approver']);
});

it('lets a person without the quotation permissions do nothing with one', function () {
    $q = ($this->quote)();

    // a tele-caller holds no quotation permission; Accounts and the Approver hold only what their roles give
    foreach (['view', 'update', 'submit', 'send', 'download'] as $ability) {
        expect(($this->can)($this->telecaller, $ability, $q))->toBeFalse($ability);
    }

    expect(($this->can)($this->accounts, 'view', $q))->toBeFalse();
});

it('never deletes a quotation, whoever asks', function () {
    $q = ($this->quote)();

    expect(($this->who)('delete', $q, [...$this->people, 'globex' => $this->globexAdmin]))->toBe([]);
});

it('allows the PDF to whoever may open the quotation', function () {
    $q = ($this->quote)('approved');

    expect(($this->who)('download', $q, $this->people))->toBe(['owner', 'manager', 'admin', 'approver']);   // the approver decided it
});

it('works out which of a page of quotations a person may open, in agreement with the policy', function () {
    $mine = ($this->quote)();
    $theirs = makeQuotation(
        $this->acme, $this->bde2,
        approveDocument($this->acme, makeDeal($this->acme, $this->bde2, makeLead($this->acme, $this->bde2, $this->ahd, [], [lineRow($this->bolt, '5', '')])), $this->bde2, $this->approver),
        [], null, 'draft', $this->approver,
    );
    $rows = Quotation::withoutGlobalScopes()->whereIn('id', [$mine->id, $theirs->id])->get();

    $openable = fn ($user) => array_keys(inCompany($this->acme, fn () => app(QuotationAccess::class)->openable($user, $rows)));

    expect($openable($this->bde))->toBe([$mine->id])
        ->and($openable($this->bde2))->toBe([$theirs->id])
        ->and($openable($this->manager))->toEqualCanonicalizing([$mine->id, $theirs->id])
        ->and($openable($this->loner))->toBe([]);

    // a list query agrees
    $listed = fn ($user) => inCompany($this->acme, fn () => app(QuotationAccess::class)->restrict(Quotation::query(), $user)->orderBy('id')->pluck('id')->all());

    expect($listed($this->bde))->toBe([$mine->id])->and($listed($this->manager))->toBe([$mine->id, $theirs->id])->and($listed($this->loner))->toBe([]);
});

it('follows the lead when it is handed over', function () {
    $q = ($this->quote)();
    $lead = $q->lead;

    expect(($this->can)($this->bde2, 'view', $q))->toBeFalse();

    inCompany($this->acme, fn () => app(DealService::class)->reassign($q->deal->fresh(), $this->bde2->id, 'Moving areas', $this->manager));

    expect(($this->can)($this->bde2, 'view', $q))->toBeTrue()->and(($this->can)($this->bde, 'view', $q))->toBeFalse()->and(($this->can)($this->bde2, 'update', $q))->toBeTrue();
    expect($q->fresh()->state)->toBe(QuotationState::Draft)->and($lead->fresh()->assigned_to)->toBe($this->bde2->id);
});
