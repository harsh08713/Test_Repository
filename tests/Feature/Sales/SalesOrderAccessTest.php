<?php

use App\Modules\CRM\Services\DealService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/*
 * Who may see and do what with a sales order and its invoice: the permission matrix says what a person may do with orders at all,
 * SalesOrderAccess says which — the ones credited to them, their team's, everyone's (Accounts and Operations see all) — and the state of the
 * order says what is still possible.
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
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);
    $this->westTeam = makeTeam($this->acme, $this->otherManager, [$this->mumBde], 'WEST', 'West team');

    $this->customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $this->bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');

    $this->order = function (string $upTo = 'draft', $by = null) {
        $by ??= $this->bde;
        $deal = approveDocument($this->acme, makeDeal($this->acme, $by, makeLead($this->acme, $by, $this->ahd, [], [lineRow($this->bolt, '10', '')]), ['customer_ledger_id' => (string) $this->customer->id]), $by, $this->approver);

        return makeSalesOrder($this->acme, $by, $deal, $this->customer, [], null, $upTo, $this->approver);
    };
    $this->can = fn ($user, string $ability, $model) => inCompany($this->acme, fn () => Gate::forUser($user)->allows($ability, $model->fresh()));
    $this->who = fn (string $ability, $model, array $people) => collect($people)->filter(fn ($user) => ($this->can)($user, $ability, $model))->keys()->all();
    $this->people = [
        'owner' => $this->bde, 'colleague' => $this->bde2, 'loner' => $this->loner, 'manager' => $this->manager, 'otherManager' => $this->otherManager,
        'admin' => $this->admin, 'approver' => $this->approver, 'telecaller' => $this->telecaller, 'accounts' => $this->accounts, 'operations' => $this->operations,
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('lets the salesperson, their manager, an administrator and Accounts and Operations open an order — and nobody else', function () {
    $o = ($this->order)();

    expect(($this->who)('view', $o, $this->people))->toBe(['owner', 'manager', 'admin', 'accounts', 'operations'])
        ->and(($this->can)($this->globexAdmin, 'view', $o))->toBeFalse()       // another company
        ->and(($this->can)($this->mumBde, 'view', $o))->toBeFalse();            // a branch they do not work in
});

it('lets those who work a draft change it and send it for approval, and nobody else', function () {
    $o = ($this->order)();

    foreach (['update', 'submit'] as $ability) {
        expect(($this->who)($ability, $o, $this->people))->toBe(['owner', 'manager', 'admin'], $ability);
    }

    // Accounts and Operations see every order but sell none
    expect(($this->can)($this->accounts, 'update', $o))->toBeFalse()->and(($this->can)($this->operations, 'cancel', $o))->toBeFalse();
});

it('locks an order for editing while it waits and once it is approved', function () {
    $pending = ($this->order)('pending');
    $approved = ($this->order)('approved');

    foreach ([$pending, $approved] as $o) {
        expect(($this->who)('update', $o, $this->people))->toBe([])->and(($this->who)('submit', $o, $this->people))->toBe([]);
    }
});

it('lets an approver open an order only while it waits for them, or after they decided it', function () {
    $o = ($this->order)();
    $other = makeUser(['Approver'], [$this->acme]);

    expect(($this->can)($this->approver, 'view', $o))->toBeFalse();

    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($o->fresh(), $this->bde));

    expect(($this->can)($this->approver, 'view', $o))->toBeTrue()->and(($this->can)($this->approver, 'approve', $o))->toBeTrue()
        ->and(($this->can)($this->approver, 'update', $o))->toBeFalse()             // reading, never editing
        ->and(($this->can)($this->bde, 'approve', $o))->toBeFalse()
        ->and(($this->can)($this->loner, 'view', $o))->toBeFalse();

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($o->fresh(), $this->approver));
    expect(($this->can)($this->approver, 'view', $o))->toBeTrue()->and(($this->can)($other, 'view', $o))->toBeFalse();
});

it('lets a manager cancel an order, but not a salesperson (who may not delete orders)', function () {
    $o = ($this->order)('approved');

    expect(($this->who)('cancel', $o, $this->people))->toBe(['manager', 'admin']);

    $pending = ($this->order)('pending');
    expect(($this->who)('cancel', $pending, $this->people))->toBe([]);           // wait for the decision
});

it('offers the credit override only to someone who holds that right, and only for an order that is blocked', function () {
    app(SettingsService::class)->set('credit_limit.behaviour', 'block', $this->acme->id);
    $this->customer->forceFill(['credit_limit' => '1000'])->save();
    $blocked = ($this->order)();

    expect(($this->who)('overrideCredit', $blocked, $this->people))->toBe(['manager', 'admin']);

    $this->customer->forceFill(['credit_limit' => '5000000'])->save();
    expect(($this->who)('overrideCredit', ($this->order)(), $this->people))->toBe([]);
});

it('offers re-opening only before the invoice has been made', function () {
    $stuck = ($this->order)('pending');
    inCompany($this->acme, fn () => app(ApprovalService::class)->reject($stuck->fresh(), $this->approver, 'No'));
    $invoiced = ($this->order)('approved');

    expect(($this->who)('reopenApproval', $invoiced, $this->people))->toBe([]);   // approved orders are invoiced at once
});

it('never deletes an order, whoever asks', function () {
    $o = ($this->order)();

    expect(($this->who)('delete', $o, [...$this->people, 'globex' => $this->globexAdmin]))->toBe([]);
});

it('allows the acknowledgment PDF to whoever may open the order', function () {
    $o = ($this->order)('approved');

    expect(($this->who)('download', $o, $this->people))->toBe(['owner', 'manager', 'admin', 'approver', 'accounts', 'operations']);   // the approver decided it
});

it('lets the same people read the invoice, and nobody change, delete or make one by hand', function () {
    $o = ($this->order)('approved');
    $invoice = $o->invoice;

    expect(($this->who)('view', $invoice, $this->people))->toBe(['owner', 'manager', 'admin', 'accounts', 'operations'])
        ->and(($this->who)('download', $invoice, $this->people))->toBe(['owner', 'manager', 'admin', 'accounts', 'operations'])
        ->and(($this->can)($this->globexAdmin, 'view', $invoice))->toBeFalse()->and(($this->can)($this->mumBde, 'view', $invoice))->toBeFalse();

    foreach (['update', 'delete'] as $ability) {
        expect(($this->who)($ability, $invoice, [...$this->people, 'globex' => $this->globexAdmin]))->toBe([], $ability);
    }

    expect(inCompany($this->acme, fn () => Gate::forUser($this->admin)->allows('create', SalesInvoice::class)))->toBeFalse();
});

it('works out which orders and invoices a person sees in a list, in agreement with the policy', function () {
    $mine = ($this->order)('approved');
    $theirs = ($this->order)('approved', $this->bde2);
    $draft = ($this->order)();

    $orders = fn ($user) => inCompany($this->acme, fn () => app(SalesOrderAccess::class)->restrict(SalesOrder::query(), $user)->orderBy('id')->pluck('id')->all());
    $invoices = fn ($user) => inCompany($this->acme, fn () => app(SalesOrderAccess::class)->restrictInvoices(SalesInvoice::query(), $user)->orderBy('id')->pluck('id')->all());

    expect($orders($this->bde))->toBe([$mine->id, $draft->id])
        ->and($orders($this->bde2))->toBe([$theirs->id])
        ->and($orders($this->manager))->toBe([$mine->id, $theirs->id, $draft->id])
        ->and($orders($this->accounts))->toBe([$mine->id, $theirs->id, $draft->id])
        ->and($orders($this->loner))->toBe([]);

    expect($invoices($this->bde))->toBe([$mine->invoice->id])->and($invoices($this->accounts))->toBe([$mine->invoice->id, $theirs->invoice->id])->and($invoices($this->loner))->toBe([]);
});

it('credits an order to its salesperson for good, whatever happens to the lead afterwards', function () {
    $o = ($this->order)();   // still open: an approved order wins its deal, and a won deal no longer changes hands

    inCompany($this->acme, fn () => app(DealService::class)->reassign($o->deal->fresh(), $this->bde2->id, 'Moving areas', $this->manager));

    expect(($this->can)($this->bde, 'view', $o))->toBeTrue()->and(($this->can)($this->bde2, 'view', $o))->toBeFalse()->and($o->fresh()->assigned_to)->toBe($this->bde->id);
});
