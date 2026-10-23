<?php

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Http\Livewire\Deals\DealForm;
use App\Modules\CRM\Http\Livewire\Deals\DealList;
use App\Modules\CRM\Http\Livewire\Deals\DealShow;
use App\Modules\CRM\Http\Livewire\Leads\InquiryForm;
use App\Modules\CRM\Http\Livewire\Leads\LeadForm;
use App\Modules\CRM\Http\Livewire\Leads\LeadList;
use App\Modules\CRM\Http\Livewire\Leads\LeadShow;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Http\Livewire\ApprovalInbox;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');   // a Monday
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->bolt = makeUsableItem($this->acme);
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG')]);
    $this->customer = makeCustomer($this->acme);
    $this->reason = lostReasonId($this->acme);

    $this->deal = fn (array $deal = [], $by = null, array $lead = []) => makeDeal($this->acme, $by ?? $this->bde,
        makeLead($this->acme, $by ?? $this->bde, $this->ahd, $lead, [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]), $deal);
    $this->row = fn (Deal $deal) => 'wire:key="deal-'.$deal->id.'"';
    $this->show = fn ($user, Deal $deal) => Livewire::actingAs($user)->test(DealShow::class, ['deal' => $deal->fresh()]);
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// The deal list
// ======================================================================================================

it('lists exactly the deals a person may see, with what the open ones add up to', function () {
    $mine = ($this->deal)(['probability' => '50']);                       // 1568.75 at 50%
    $colleague = ($this->deal)([], $this->bde2);
    $waiting = ($this->deal)([], $this->bde2);
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($waiting, $this->bde2));

    Livewire::actingAs($this->bde)->test(DealList::class)
        ->assertSeeHtml(($this->row)($mine))->assertSee($mine->deal_number)->assertSee('Discovery')
        ->assertDontSeeHtml(($this->row)($colleague))->assertDontSeeHtml(($this->row)($waiting))
        ->assertViewHas('pipelineCount', 1)->assertViewHas('pipelineValue', '1568.75');

    $manager = Livewire::actingAs($this->manager)->test(DealList::class)
        ->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($colleague))->assertSeeHtml(($this->row)($waiting))
        ->assertViewHas('pipelineCount', 3);
    expect((float) $manager->viewData('pipelineWeighted'))->toEqualWithDelta(1568.75 * 0.5 + 1568.75 * 0.1 + 1568.75 * 0.1, 0.001);

    // an approver is shown only what waits for a decision
    Livewire::actingAs($this->approver)->test(DealList::class)
        ->assertSeeHtml(($this->row)($waiting))->assertDontSeeHtml(($this->row)($mine))->assertDontSeeHtml(($this->row)($colleague));

    Livewire::actingAs($this->admin)->test(DealList::class)->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($colleague))->assertSeeHtml(($this->row)($waiting));
});

it('counts the stages over what the person may see, and shows the stage that was asked for', function () {
    $open = ($this->deal)();
    $negotiating = ($this->deal)();
    $lost = ($this->deal)();
    ($this->deal)([], $this->bde2);                                         // not theirs: not counted for them
    inCompany($this->acme, function () use ($negotiating, $lost) {
        app(DealService::class)->changeStage($negotiating, DealStage::Negotiation, $this->bde);
        app(DealService::class)->changeStage($lost, DealStage::Lost, $this->bde, null, $this->reason);
    });

    $list = Livewire::actingAs($this->bde)->test(DealList::class);

    expect($list->viewData('tabs'))->toBe([['open', 'Open', 2], ['discovery', 'Discovery', 1], ['negotiation', 'Negotiation', 1], ['lost', 'Lost', 1], ['', 'All', 3]]);

    $list->assertSet('stage', 'open')->assertSeeHtml(($this->row)($open))->assertSeeHtml(($this->row)($negotiating))->assertDontSeeHtml(($this->row)($lost));
    $list->call('setStage', 'lost')->assertSeeHtml(($this->row)($lost))->assertDontSeeHtml(($this->row)($open));
    $list->call('setStage', '')->assertSeeHtml(($this->row)($lost))->assertSeeHtml(($this->row)($open));
    $list->call('setStage', 'nonsense')->assertSet('stage', 'open');
});

it('filters the list by what was typed, the owner, the approval state and the close date, never beyond what the person may see', function () {
    $ganesh = ($this->deal)(['expected_close_date' => '2026-10-20'], null, ['name' => 'Shree Ganesh Traders', 'mobile' => '9876543210']);
    $patel = ($this->deal)(['expected_close_date' => '2026-12-05'], $this->bde2, ['name' => 'Patel Polymers']);
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($patel, $this->bde2));
    $late = ($this->deal)(['expected_close_date' => '2026-10-12'], null, ['name' => 'Dormant Dealers']);
    DB::table('deals')->where('id', $late->id)->update(['expected_close_date' => '2026-09-30']);

    $shown = function ($user, array $filters) use ($ganesh, $patel, $late) {
        $list = Livewire::actingAs($user)->test(DealList::class);
        foreach ($filters as $property => $value) {
            $list->set($property, $value);
        }

        return collect(['ganesh' => $ganesh, 'patel' => $patel, 'late' => $late])->filter(fn (Deal $d) => str_contains($list->html(), ($this->row)($d)))->keys()->all();
    };

    expect($shown($this->admin, []))->toBe(['ganesh', 'patel', 'late'])
        ->and($shown($this->admin, ['search' => 'ganesh']))->toBe(['ganesh'])
        ->and($shown($this->admin, ['search' => $patel->deal_number]))->toBe(['patel'])
        ->and($shown($this->admin, ['search' => '98765 432']))->toBe(['ganesh'])                      // the lead’s mobile number, as people type it
        ->and($shown($this->admin, ['search' => '100%']))->toBe([])
        ->and($shown($this->admin, ['owner' => (string) $this->bde2->id]))->toBe(['patel'])
        ->and($shown($this->admin, ['approval' => 'pending']))->toBe(['patel'])
        ->and($shown($this->admin, ['approval' => 'draft']))->toBe(['ganesh', 'late'])
        ->and($shown($this->admin, ['closing' => 'late']))->toBe(['late'])
        ->and($shown($this->admin, ['closing' => 'month']))->toBe(['ganesh'])
        // asking for somebody else’s deals gives a salesperson nothing
        ->and($shown($this->bde, ['owner' => (string) $this->bde2->id]))->toBe([])
        ->and($shown($this->bde, []))->toBe(['ganesh', 'late']);
});

// ======================================================================================================
// One deal
// ======================================================================================================

it('shows a deal with its lines, its value, where it came from and how it got where it is', function () {
    $deal = ($this->deal)(['remarks' => 'Wants delivery before Diwali']);

    ($this->show)($this->bde, $deal)
        ->assertSee($deal->deal_number)->assertSee($deal->party_name)->assertSee('Discovery')->assertSee('Draft')
        ->assertSee('1,568.75')->assertSee('Wants delivery before Diwali')
        ->assertSee($this->bolt->name)->assertSee($this->wire->name)->assertSee('1,255.00')->assertSee('313.75')
        ->assertSee($deal->lead->lead_number)->assertSee(route('crm.leads.show', $deal->lead_id), false)->assertSee('Deal created from the lead')
        ->assertSee('Move to Negotiation')->assertSee('Edit lines')->assertSee('Submit for approval')
        ->assertDontSee('Hand over…')                                     // handing things out is the manager’s
        ->assertDontSee('Audit trail');

    ($this->show)($this->manager, $deal)->assertSee('Hand over…')->assertDontSee('Audit trail');
    ($this->show)($this->admin, $deal)->assertSee('Hand over…')->assertSee('Audit trail');
});

it('turns away a person who may not open the deal', function () {
    $deal = ($this->deal)();

    foreach ([$this->bde2, $this->telecaller, $this->approver] as $stranger) {
        Livewire::actingAs($stranger)->test(DealShow::class, ['deal' => $deal->fresh()])->assertForbidden();
    }
});

it('moves a deal between stages from its page', function () {
    $deal = ($this->deal)();

    ($this->show)($this->bde, $deal)->call('moveToStage', 'negotiation')->assertHasNoErrors()->assertRedirect(route('crm.deals.show', $deal));
    expect($deal->fresh()->stage)->toBe(DealStage::Negotiation)->and($deal->fresh()->probability)->toBe(50);

    // never to a stage only a document sets (said in plain words), and never to Lost this way (that needs a reason, so it has its own dialog)
    ($this->show)($this->bde, $deal)->call('moveToStage', 'won')->assertHasErrors(['workflow']);
    ($this->show)($this->bde, $deal)->call('moveToStage', 'quotation')->assertHasErrors(['workflow']);
    ($this->show)($this->bde, $deal)->call('moveToStage', 'lost')->assertNotFound();
    ($this->show)($this->bde, $deal)->call('moveToStage', 'nonsense')->assertNotFound();
    expect($deal->fresh()->stage)->toBe(DealStage::Negotiation);

    ($this->show)($this->bde2, $deal)->assertForbidden();
});

it('marks a deal lost through a dialog that insists on a reason, and brings it back only for someone who may', function () {
    $deal = ($this->deal)();

    $page = ($this->show)($this->bde, $deal)->call('openDialog', 'lost')->assertSet('dialog', 'lost')->assertSee('Why was it lost?')
        ->call('markLost')->assertHasErrors(['lost_reason_id'])->assertSet('dialog', 'lost');
    $page->set('lostReasonId', (string) $this->reason)->set('comment', 'Went elsewhere')->call('markLost')->assertHasNoErrors()->assertRedirect(route('crm.deals.show', $deal));

    $lost = $deal->fresh();
    expect($lost->stage)->toBe(DealStage::Lost)->and($lost->lost_remarks)->toBe('Went elsewhere')->and($lost->lead->stage)->toBe(LeadStage::Lost);

    // the salesperson made the call but cannot undo it; the page does not even offer it
    ($this->show)($this->bde, $lost)->assertSee('Lost')->assertDontSee('Re-open…')->assertDontSee('Mark as lost')->assertDontSee('Move to ')
        ->call('openDialog', 'reopen-lost')->assertForbidden();

    $page = ($this->show)($this->manager, $lost)->assertSee('Re-open…')->call('openDialog', 'reopen-lost')->assertSet('dialog', 'reopen-lost');
    $page->call('reopenLost')->assertHasErrors(['comment']);
    $page->set('comment', 'They came back')->set('reopenTo', 'negotiation')->call('reopenLost')->assertHasNoErrors()->assertRedirect(route('crm.deals.show', $deal));

    expect($deal->fresh()->stage)->toBe(DealStage::Negotiation)->and($deal->fresh()->lead->stage)->toBe(LeadStage::Deal);
});

it('updates where an open deal stands from a dialog, also once it is approved', function () {
    $deal = ($this->deal)();
    inCompany($this->acme, function () use ($deal) {
        app(ApprovalService::class)->submit($deal, $this->bde);
        app(ApprovalService::class)->approve($deal->fresh(), $this->approver);
    });

    $page = ($this->show)($this->bde, $deal)->assertDontSee('Edit lines')->call('openDialog', 'progress')
        ->assertSet('progress.probability', '10')->assertSet('progress.expected_close_date', '2026-11-11');

    $page->set('progress.probability', '100')->call('saveProgress')->assertHasErrors(['deal.probability']);
    $page->set('progress.probability', '75')->set('progress.expected_close_date', '2026-12-01')->set('progress.remarks', 'Order expected')->call('saveProgress')
        ->assertHasNoErrors()->assertRedirect(route('crm.deals.show', $deal));

    $saved = $deal->fresh();
    expect($saved->probability)->toBe(75)->and($saved->expected_close_date->toDateString())->toBe('2026-12-01')->and($saved->approval_status)->toBe(ApprovalStatus::Approved);
});

it('re-opens an approved deal for editing with a reason, and sends the person to correct it', function () {
    $deal = ($this->deal)();
    inCompany($this->acme, function () use ($deal) {
        app(ApprovalService::class)->submit($deal, $this->bde);
        app(ApprovalService::class)->approve($deal->fresh(), $this->approver);
    });

    ($this->show)($this->bde, $deal)->assertSee('Re-open for editing…')
        ->call('openDialog', 'reopen-approval')->call('reopenApproval')->assertHasErrors(['reason'])
        ->set('reason', 'The rate was wrong')->call('reopenApproval')->assertHasNoErrors()->assertRedirect(route('crm.deals.edit', $deal));

    expect($deal->fresh()->approval_status)->toBe(ApprovalStatus::Draft);
    ($this->show)($this->bde2, $deal)->assertForbidden();
});

it('hands a deal to somebody else from its page, the manager’s right alone', function () {
    $deal = ($this->deal)();

    ($this->show)($this->bde, $deal)->call('openDialog', 'reassign')->assertForbidden();

    $page = ($this->show)($this->manager, $deal)->call('openDialog', 'reassign')->assertSee($this->bde2->name);
    $page->call('reassign')->assertHasErrors(['to_user_id']);
    $page->set('reassignTo', (string) $this->bde2->id)->call('reassign')->assertHasErrors(['reason']);
    $page->set('reason', 'Territory re-allocated')->call('reassign')->assertHasNoErrors()->assertRedirect(route('crm.deals.show', $deal));

    expect($deal->fresh()->assigned_to)->toBe($this->bde2->id)->and($deal->fresh()->lead->assigned_to)->toBe($this->bde2->id);

    // the manager still reaches the deal (their team); the previous owner no longer does
    ($this->show)($this->manager, $deal)->assertSee($this->bde2->name);
    Livewire::actingAs($this->bde)->test(DealShow::class, ['deal' => $deal->fresh()])->assertForbidden();
});

it('shows an approver the deal that waits for them, with the decision to make, and nothing to change', function () {
    $deal = ($this->deal)();
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($deal, $this->bde));

    $page = ($this->show)($this->approver, $deal)->assertSee('Your decision is needed')->assertSee('Approve')
        ->assertDontSee('Edit lines')->assertDontSee('Mark as lost')->assertDontSee('Update progress');

    $page->assertSee($this->bolt->name);
});

// ======================================================================================================
// Converting a lead
// ======================================================================================================

it('offers to convert a qualified lead, and only to those who may', function () {
    $lead = qualify($this->acme, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]), $this->bde);
    $new = makeLead($this->acme, $this->bde, $this->ahd);

    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertSee('Convert to a deal')->assertSee(route('crm.deals.convert', $lead), false);
    Livewire::actingAs($this->manager)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertSee('Convert to a deal');
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $new->fresh()])->assertDontSee('Convert to a deal');

    // a tele-caller with a planned follow-up on it may open the lead but not convert it
    makeFollowup($this->acme, $this->admin, $lead, ['assigned_to' => (string) $this->telecaller->id]);
    Livewire::actingAs($this->telecaller)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertDontSee('Convert to a deal');
});

it('shows the conversion form with every line of the lead, and makes the deal on saving', function () {
    $lead = qualify($this->acme, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]), $this->bde);

    $form = Livewire::actingAs($this->bde)->test(DealForm::class, ['source' => $lead->id])
        ->assertSee($lead->lead_number)->assertSee($this->bolt->name)->assertSee($this->wire->name)->assertSee('From lead')
        ->assertSet('deal.probability', '10')->assertSet('deal.expected_close_date', '2026-11-11');

    expect($form->get('items'))->toHaveCount(2)->and($form->get('items.0.quantity'))->toBe('10')->and($form->get('items.1.quantity'))->toBe('2.5');

    // nothing is priced yet: the deal cannot be made until it is
    $form->call('save')->assertHasErrors(['items.0.expected_rate', 'items.1.expected_rate'])->assertNoRedirect();
    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);

    $form->set('items.0.expected_rate', '125.50')->set('items.1.expected_rate', '40')->set('items.0.quantity', '12')->set('deal.probability', '35')->call('save')->assertHasNoErrors();

    $deal = Deal::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->firstOrFail();
    $form->assertRedirect(route('crm.deals.show', $deal));
    expect($deal->expected_value)->toBe('1606.00')       // 12 × 125.50 + 2.5 × 40
        ->and($deal->probability)->toBe(35)
        ->and($deal->assigned_to)->toBe($this->bde->id);
});

it('does not let a lead’s own line be removed or its item swapped on the conversion form, and drops a row that was never filled in', function () {
    $lead = qualify($this->acme, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]), $this->bde);

    $form = Livewire::actingAs($this->bde)->test(DealForm::class, ['source' => $lead->id]);

    $form->call('removeRow', 0)->call('clearItem', 0)->call('pickItem', 0, $this->wire->id);
    expect($form->get('items'))->toHaveCount(1)->and($form->get('items.0.item_id'))->toBe((string) $this->bolt->id);

    // a row of their own can be added and taken away again; a blank one is not sent
    $form->call('addRow')->call('addRow');
    expect($form->get('items'))->toHaveCount(3);
    $form->call('removeRow', 2);
    expect($form->get('items'))->toHaveCount(2);

    $form->set('items.0.expected_rate', '100')->call('save')->assertHasNoErrors();
    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->firstOrFail()->items()->count())->toBe(1);
});

it('finds an item for an added line and refuses one that is not approved', function () {
    $lead = qualify($this->acme, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]), $this->bde);
    $draft = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'Unapproved Gadget']], 'draft');

    $form = Livewire::actingAs($this->bde)->test(DealForm::class, ['source' => $lead->id])->call('addRow')
        ->set('items.1.search', 'Steel')->assertSee($this->wire->name)
        ->set('items.1.search', 'Unapproved')->assertDontSee('Unapproved Gadget')->assertSee('Nothing found');

    $form->call('pickItem', 1, $draft->id);
    expect($form->get('items.1.item_id'))->toBe('');

    $form->call('pickItem', 1, $this->wire->id);
    expect($form->get('items.1.item_id'))->toBe((string) $this->wire->id)->and($form->get('items.1.unit_id'))->toBe((string) $this->wire->base_unit_id);
});

it('turns away a conversion of a lead that is not qualified, is not theirs, or has already been converted', function () {
    $new = makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]);
    $qualified = qualify($this->acme, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]), $this->bde);
    $converted = ($this->deal)();

    Livewire::actingAs($this->bde)->test(DealForm::class, ['source' => $new->id])->assertForbidden();
    Livewire::actingAs($this->bde2)->test(DealForm::class, ['source' => $qualified->id])->assertForbidden();
    Livewire::actingAs($this->bde)->test(DealForm::class, ['source' => $converted->lead_id])->assertRedirect(route('crm.deals.show', $converted));

    // two people with the form open: the second to save is told, and no second deal appears
    $first = Livewire::actingAs($this->bde)->test(DealForm::class, ['source' => $qualified->id])->set('items.0.expected_rate', '100');
    $second = Livewire::actingAs($this->manager)->test(DealForm::class, ['source' => $qualified->id])->set('items.0.expected_rate', '90');

    $first->call('save')->assertHasNoErrors();
    $second->call('save')->assertHasErrors(['form'])->assertSee('already been converted');
    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $qualified->id)->count())->toBe(1);
});

it('corrects a draft deal on the form, and turns the form away once the deal is locked', function () {
    $deal = ($this->deal)();

    $form = Livewire::actingAs($this->bde)->test(DealForm::class, ['editing' => $deal->fresh()])->assertSee($deal->deal_number)->assertSet('deal.probability', '10');
    expect($form->get('items'))->toHaveCount(2);

    $form->set('items.0.quantity', '20')->set('items.0.expected_rate', '10')->call('removeRow', 1)->set('deal.remarks', 'Revised')->call('save')->assertHasNoErrors()
        ->assertRedirect(route('crm.deals.show', $deal));

    $saved = $deal->fresh();
    expect($saved->expected_value)->toBe('200.00')->and($saved->items()->count())->toBe(1)->and($saved->remarks)->toBe('Revised');

    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($saved, $this->bde));
    Livewire::actingAs($this->bde)->test(DealForm::class, ['editing' => $saved->fresh()])->assertForbidden();
    Livewire::actingAs($this->bde2)->test(DealForm::class, ['editing' => $deal->fresh()])->assertForbidden();
});

it('links a deal for a prospect to a customer from the form, and keeps an inquiry’s deal for its own customer', function () {
    $lead = qualify($this->acme, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]), $this->bde);

    $form = Livewire::actingAs($this->bde)->test(DealForm::class, ['source' => $lead->id])->set('customerSearch', 'Ganesh')->assertSee($this->customer->name)
        ->call('pickCustomer', $this->customer->id)->assertSet('deal.customer_ledger_id', (string) $this->customer->id)
        ->set('items.0.expected_rate', '100')->call('save')->assertHasNoErrors();

    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->value('customer_ledger_id'))->toBe($this->customer->id);

    $inquiry = qualify($this->acme, makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '10')]), $this->bde);
    $other = makeCustomer($this->acme, 'Another Customer');

    $page = Livewire::actingAs($this->bde)->test(DealForm::class, ['source' => $inquiry->id])->assertSet('deal.customer_ledger_id', (string) $this->customer->id)->assertSee('inquiry’s customer');
    $page->call('pickCustomer', $other->id)->call('clearCustomer')->assertSet('deal.customer_ledger_id', (string) $this->customer->id);
});

// ======================================================================================================
// The lead pages that lead to and from deals
// ======================================================================================================

it('links an inquiry’s deal and its lead to the customer’s ledger, for those who may read it', function () {
    $inquiry = qualify($this->acme, makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '10')]), $this->bde);
    $deal = makeDeal($this->acme, $this->bde, $inquiry);
    $ledger = route('masters.ledgers.show', $this->customer);

    // a salesperson may read the customer ledger
    ($this->show)($this->bde, $deal)->assertSee($ledger, false)->assertSee($this->customer->name);
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $inquiry->fresh()])->assertSee($this->customer->name);

    // …and so may an approver, deciding on a deal that waits for them (the link is not a stranger's way into the ledger)
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($deal->fresh(), $this->bde));
    ($this->show)($this->approver, $deal)->assertSee($this->customer->name)->assertSee($ledger, false);

    // a deal for a prospect has no customer to link
    ($this->show)($this->bde, ($this->deal)())->assertSee('Not a customer yet')->assertDontSee($ledger, false);
});

it('tells the lead page about its deal, and about the deal that was lost', function () {
    $deal = ($this->deal)();
    $lead = $deal->lead;

    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertSee('has been converted')->assertSee($deal->deal_number)->assertSee(route('crm.deals.show', $deal), false)
        ->assertDontSee('Convert to a deal')->assertDontSee('Transfer…')->assertDontSee('Edit');

    inCompany($this->acme, fn () => app(DealService::class)->changeStage($deal, DealStage::Lost, $this->bde, null, $this->reason));

    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertSee('deal')->assertSee('was lost, and the lead with it')->assertSee($deal->deal_number);
});

it('lists inquiries from customers among the leads, and filters them', function () {
    $new = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Prospect Traders']);
    $inquiry = makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '10')]);
    $row = fn (Lead $lead) => 'wire:key="lead-'.$lead->id.'"';

    Livewire::actingAs($this->bde)->test(LeadList::class)->assertSeeHtml($row($new))->assertSeeHtml($row($inquiry))->assertSee('Customer')
        ->assertSee('Add an inquiry from a customer')
        ->set('type', 'existing')->assertSeeHtml($row($inquiry))->assertDontSeeHtml($row($new))
        ->set('type', 'new')->assertSeeHtml($row($new))->assertDontSeeHtml($row($inquiry))
        ->set('type', 'junk')->assertSeeHtml($row($new))->assertSeeHtml($row($inquiry));

    // a tele-caller cannot read the customer ledger, so is not offered the inquiry
    Livewire::actingAs($this->telecaller)->test(LeadList::class)->assertSee('Add a lead')->assertDontSee('Add an inquiry from a customer');
});

it('enters an inquiry from an existing customer on its own form', function () {
    $form = Livewire::actingAs($this->bde)->test(InquiryForm::class)->assertSee('Inquiry from an existing customer')
        ->set('customerSearch', 'Ganesh')->assertSee($this->customer->name)
        ->call('pickCustomer', $this->customer->id)->assertSet('lead.customer_ledger_id', (string) $this->customer->id);

    $form->set('lead.location_id', (string) $this->ahd->id)->set('lead.lead_source_id', (string) leadSourceId($this->acme))
        ->set('interests.0.search', 'Hex')->call('pickItem', 0, $this->bolt->id)
        ->set('interests.0.quantity', '10')->set('interests.0.expected_rate', '125.50')
        ->assertSee('1,255.00');

    $form->call('save')->assertHasNoErrors();

    $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('customer_ledger_id', $this->customer->id)->firstOrFail();
    $form->assertRedirect(route('crm.leads.show', $lead));
    expect($lead->isExisting())->toBeTrue()->and($lead->expected_value)->toBe('1255.00')->and($lead->assigned_to)->toBe($this->bde->id);

    // the lead page shows the customer, the rate and the approval panel
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead->fresh()])
        ->assertSee('Existing customer')->assertSee($this->customer->name)->assertSee('Rate expected')->assertSee('125.50')->assertSee('Submit for approval');
});

it('says what is wrong on the inquiry form, row by row, and refuses a person who may not read customers', function () {
    $form = Livewire::actingAs($this->bde)->test(InquiryForm::class)->set('lead.location_id', (string) $this->ahd->id)->set('lead.lead_source_id', (string) leadSourceId($this->acme));

    $form->call('save')->assertHasErrors(['lead.customer_ledger_id']);

    $form->call('pickCustomer', $this->customer->id)->call('pickItem', 0, $this->bolt->id)->set('interests.0.quantity', '2.5')->set('interests.0.expected_rate', 'cheap')->call('save')
        ->assertHasErrors(['interests.0.expected_rate']);

    // and once the rate is a number, that Nos cannot be split
    $form->set('interests.0.expected_rate', '10')->call('save')->assertHasErrors(['interests.0.quantity']);

    Livewire::actingAs($this->telecaller)->test(InquiryForm::class)->assertForbidden();
});

it('corrects an inquiry on its own form, with the customer fixed, and sends a lead edit for an inquiry there', function () {
    $inquiry = makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '10', '100')]);

    $form = Livewire::actingAs($this->bde)->test(InquiryForm::class, ['editing' => $inquiry->fresh()])->assertSee($inquiry->lead_number)->assertSee($this->customer->name)
        ->assertSee('The customer is fixed once the inquiry is entered');

    $form->call('pickCustomer', makeCustomer($this->acme, 'Someone Else')->id)->assertSet('lead.customer_ledger_id', (string) $this->customer->id);
    $form->set('interests.0.quantity', '15')->set('lead.remarks', 'Urgent')->call('save')->assertHasNoErrors()->assertRedirect(route('crm.leads.show', $inquiry));

    expect($inquiry->fresh()->expected_value)->toBe('1500.00')->and($inquiry->fresh()->remarks)->toBe('Urgent');

    // the general lead form hands an inquiry over to the inquiry form, and the inquiry form refuses a new lead
    Livewire::actingAs($this->bde)->test(LeadForm::class, ['editing' => $inquiry->fresh()])->assertRedirect(route('crm.leads.edit-inquiry', $inquiry));
    $new = makeLead($this->acme, $this->bde, $this->ahd);
    Livewire::actingAs($this->bde)->test(InquiryForm::class, ['editing' => $new->fresh()])->assertNotFound();
});

it('takes an inquiry through approval from its page, and lets the person re-open it with a reason', function () {
    $inquiry = makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '10', '100')]);

    $page = fn ($user) => Livewire::actingAs($user)->test(LeadShow::class, ['lead' => $inquiry->fresh()]);

    $page($this->bde)->assertSee('Submit for approval')->assertDontSee('Re-open for editing');
    inCompany($this->acme, function () use ($inquiry) {
        app(ApprovalService::class)->submit($inquiry, $this->bde);
    });

    // the approver can open it, and decide; the salesperson no longer edits it
    $page($this->approver)->assertSee('Your decision is needed')->assertDontSee('Convert to a deal');
    $page($this->bde)->assertDontSee('>Edit<', false);

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($inquiry->fresh(), $this->approver));

    $page($this->bde)->assertSee('Re-open for editing…')->call('openDialog', 'reopen-approval')->call('reopenApproval')->assertHasErrors(['reason'])
        ->set('reason', 'Quantity changed')->call('reopenApproval')->assertHasNoErrors()->assertRedirect(route('crm.leads.edit-inquiry', $inquiry));
    expect($inquiry->fresh()->approval_status)->toBe(ApprovalStatus::Draft);
    $page($this->bde2)->assertForbidden();
});

it('puts deals and inquiries in the approval inbox, and a decision made there takes effect', function () {
    $deal = ($this->deal)();
    $inquiry = makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '10')]);
    inCompany($this->acme, function () use ($deal, $inquiry) {
        app(ApprovalService::class)->submit($deal, $this->bde);
        app(ApprovalService::class)->submit($inquiry, $this->bde);
    });

    $inbox = Livewire::actingAs($this->approver)->test(ApprovalInbox::class)
        ->assertSee($deal->approvalTitle())->assertSee($inquiry->approvalTitle())->assertSee('Deal')->assertSee('Lead')
        ->assertSee(route('crm.deals.show', $deal), false)->assertSee(route('crm.leads.show', $inquiry), false);

    $approvalId = fn ($model) => DB::table('approvals')->where('approvable_type', $model->getMorphClass())->where('approvable_id', $model->getKey())->value('id');

    $inbox->call('approve', $approvalId($deal))->assertHasNoErrors()->call('approve', $approvalId($inquiry))->assertHasNoErrors();

    expect($deal->fresh()->approval_status)->toBe(ApprovalStatus::Approved)->and($inquiry->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});
