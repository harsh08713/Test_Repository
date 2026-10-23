<?php

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\CRM\Services\DealService;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/*
 * Who may see and do what with a deal: the permission matrix says what a person may do with deals at all, DealAccess says
 * which deals, and the state of the deal says what may still be changed.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->otherManager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->loner = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);     // on nobody's team
    $this->mumBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);
    $this->westTeam = makeTeam($this->acme, $this->otherManager, [$this->mumBde], 'WEST', 'West team');

    $this->bolt = makeUsableItem($this->acme);
    $this->customer = makeCustomer($this->acme);

    $this->deal = fn (?Lead $lead = null) => makeDeal($this->acme, $this->bde, $lead ?? makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]));

    // does this person's right to do $ability on $deal come out as expected? (asked inside the company, as a request would)
    $this->can = fn ($user, string $ability, $deal) => inCompany($this->acme, fn () => Gate::forUser($user)->allows($ability, $deal->fresh()));
    $this->who = fn (string $ability, $deal, array $people) => collect($people)->filter(fn ($user) => ($this->can)($user, $ability, $deal))->keys()->all();
    $this->people = [
        'owner' => $this->bde, 'colleague' => $this->bde2, 'loner' => $this->loner, 'manager' => $this->manager, 'otherManager' => $this->otherManager,
        'admin' => $this->admin, 'approver' => $this->approver, 'telecaller' => $this->telecaller, 'accounts' => $this->accounts,
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('lets the owner, the owner’s manager and an administrator open a deal, and nobody else', function () {
    $deal = ($this->deal)();

    expect(($this->who)('view', $deal, $this->people))->toBe(['owner', 'manager', 'admin']);
    expect(($this->can)($this->globexAdmin, 'view', $deal))->toBeFalse()   // another company
        ->and(($this->can)($this->mumBde, 'view', $deal))->toBeFalse();     // a branch they do not work in
});

it('lets those who work a draft deal change it, move it and mark it lost — and nobody else', function () {
    $deal = ($this->deal)();

    foreach (['update', 'submit', 'updateProgress', 'changeStage', 'markLost'] as $ability) {
        expect(($this->who)($ability, $deal, $this->people))->toBe(['owner', 'manager', 'admin'], $ability);
    }

    // handing it over is for those who may hand things out
    expect(($this->who)('reassign', $deal, $this->people))->toBe(['manager', 'admin']);
});

it('never deletes a deal, whoever asks', function () {
    $deal = ($this->deal)();

    expect(($this->who)('delete', $deal, [...$this->people, 'globex' => $this->globexAdmin]))->toBe([]);
});

it('gives the audit trail to those who hold the right to read it', function () {
    $deal = ($this->deal)();

    expect(($this->who)('viewAudit', $deal, $this->people))->toBe(['admin']);
});

it('shows an approver a deal only while it waits for them, and afterwards the ones they decided', function () {
    $deal = ($this->deal)();

    expect(($this->can)($this->approver, 'view', $deal))->toBeFalse()
        ->and(($this->can)($this->approver, 'approve', $deal))->toBeFalse();

    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($deal, $this->bde));

    // with no approval levels configured, anyone holding `deal.approve` is asked: so every manager may open it, as well as the approver
    expect(($this->who)('view', $deal, $this->people))->toBe(['owner', 'manager', 'otherManager', 'admin', 'approver'])
        ->and(($this->who)('approve', $deal, $this->people))->toBe(['manager', 'otherManager', 'admin', 'approver'])    // those who hold the permission; self-approval is the service’s to stop
        ->and(($this->who)('update', $deal, $this->people))->toBe([])                                       // locked while it waits
        ->and(($this->who)('updateProgress', $deal, $this->people))->toBe(['owner', 'manager', 'admin']);  // where it stands is not locked

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($deal->fresh(), $this->approver));

    expect(($this->can)($this->approver, 'view', $deal))->toBeTrue()                                        // the one they decided
        ->and(($this->can)($this->accounts, 'view', $deal))->toBeFalse()
        ->and(($this->who)('update', $deal, $this->people))->toBe([])
        ->and(($this->who)('reopenApproval', $deal, $this->people))->toBe(['owner', 'manager', 'admin'])
        ->and(($this->who)('submit', $deal, $this->people))->toBe([]);

    // a second approver who never had it in front of them cannot open it
    $later = makeUser(['Approver'], [$this->acme]);
    expect(($this->can)($later, 'view', $deal))->toBeFalse();
});

it('lists the deals an approver is asked about alongside none of the others', function () {
    $waiting = ($this->deal)();
    $draft = ($this->deal)();
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($waiting, $this->bde));

    $access = app(DealAccess::class);
    $listed = fn ($user) => inCompany($this->acme, fn () => $access->restrict(Deal::query(), $user)->pluck('id')->all());
    $openable = fn ($user, $deals) => inCompany($this->acme, fn () => array_keys($access->openable($user, $deals)));

    // a manager of another team, who holds the approval right too, is asked about the one that waits — and about no other
    expect($listed($this->approver))->toBe([$waiting->id])
        ->and($openable($this->approver, [$waiting->fresh(), $draft->fresh()]))->toBe([$waiting->id])
        ->and($listed($this->bde))->toEqualCanonicalizing([$waiting->id, $draft->id])
        ->and($listed($this->bde2))->toBe([])
        ->and($listed($this->manager))->toEqualCanonicalizing([$waiting->id, $draft->id])
        ->and($listed($this->admin))->toEqualCanonicalizing([$waiting->id, $draft->id])
        ->and($listed($this->otherManager))->toBe([$waiting->id])
        ->and($listed($this->loner))->toBe([])
        ->and($openable($this->bde2, [$waiting->fresh()]))->toBe([]);
});

it('lets only those who may bring a lost deal back do so, and only when it is lost', function () {
    $deal = ($this->deal)();

    expect(($this->who)('reopenLost', $deal, $this->people))->toBe([]);   // not lost

    inCompany($this->acme, fn () => app(DealService::class)->changeStage($deal, DealStage::Lost, $this->bde, null, lostReasonId($this->acme)));

    // a salesperson can mark a deal lost but cannot undo that decision; a manager and an administrator can
    expect(($this->who)('reopenLost', $deal, $this->people))->toBe(['manager', 'admin'])
        ->and(($this->who)('markLost', $deal, $this->people))->toBe([])           // already lost
        ->and(($this->who)('changeStage', $deal, $this->people))->toBe([])
        ->and(($this->who)('updateProgress', $deal, $this->people))->toBe([])
        ->and(($this->who)('reassign', $deal, $this->people))->toBe([]);
});

it('lets only the owner’s reach convert a qualified lead — and only once', function () {
    $lead = qualify($this->acme, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]), $this->bde);
    $new = makeLead($this->acme, $this->bde, $this->ahd);

    expect(($this->who)('convert', $lead, $this->people))->toBe(['owner', 'manager', 'admin'])
        ->and(($this->who)('convert', $new, $this->people))->toBe([]);           // not qualified

    $deal = ($this->deal)($lead);
    expect(($this->who)('convert', $lead, $this->people))->toBe([])              // already a deal
        ->and(($this->who)('transfer', $lead, $this->people))->toBe([])          // it moves with the deal
        ->and(($this->who)('delete', $lead, $this->people))->toBe([]);
});

it('opens the deal screens to those who may use them, and to nobody else', function () {
    $deal = ($this->deal)();
    $converted = inCompany($this->acme, fn () => $deal->lead);   // looked up before anyone is signed in
    $lead = qualify($this->acme, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]), $this->bde);
    $status = fn ($user, string $route, $model = null) => $this->actingAs($user)->get($model ? route($route, $model) : route($route))->status();

    // a guest is sent to sign in (before anyone is signed in for the rest of the test)
    $this->get(route('crm.deals.index'))->assertRedirect(route('login'));
    $this->get(route('crm.deals.show', $deal))->assertRedirect(route('login'));

    // the list: whoever may view deals at all
    expect($status($this->bde, 'crm.deals.index'))->toBe(200)->and($status($this->manager, 'crm.deals.index'))->toBe(200)->and($status($this->approver, 'crm.deals.index'))->toBe(200)
        ->and($status($this->telecaller, 'crm.deals.index'))->toBe(403)->and($status($this->accounts, 'crm.deals.index'))->toBe(403);

    // one deal
    expect($status($this->bde, 'crm.deals.show', $deal))->toBe(200)->and($status($this->manager, 'crm.deals.show', $deal))->toBe(200)->and($status($this->admin, 'crm.deals.show', $deal))->toBe(200)
        ->and($status($this->bde2, 'crm.deals.show', $deal))->toBe(403)->and($status($this->approver, 'crm.deals.show', $deal))->toBe(403)
        ->and($status($this->mumBde, 'crm.deals.show', $deal))->toBe(404)->and($status($this->globexAdmin, 'crm.deals.show', $deal))->toBe(404);

    // editing it, and converting a lead
    expect($status($this->bde, 'crm.deals.edit', $deal))->toBe(200)->and($status($this->bde2, 'crm.deals.edit', $deal))->toBe(403)->and($status($this->approver, 'crm.deals.edit', $deal))->toBe(403)
        ->and($status($this->bde, 'crm.deals.convert', $lead))->toBe(200)->and($status($this->bde2, 'crm.deals.convert', $lead))->toBe(403)
        ->and($status($this->globexAdmin, 'crm.deals.convert', $lead))->toBe(404)
        ->and($status($this->bde, 'crm.deals.convert', $converted))->toBe(302);   // already converted: taken to its deal
});

it('links the Deals menu only for those who may use it', function () {
    $href = 'href="'.route('crm.deals.index').'"';

    $this->actingAs($this->bde)->get(route('dashboard'))->assertOk()->assertSee($href, false);
    $this->actingAs($this->approver)->get(route('dashboard'))->assertOk()->assertSee($href, false);
    $this->actingAs($this->accounts)->get(route('dashboard'))->assertOk()->assertDontSee($href, false);
    $this->actingAs($this->telecaller)->get(route('dashboard'))->assertOk()->assertDontSee($href, false);
});
