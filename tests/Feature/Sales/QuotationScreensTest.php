<?php

use App\Modules\CRM\Http\Livewire\Deals\DealShow;
use App\Modules\CRM\Http\Livewire\Leads\LeadShow;
use App\Modules\CRM\Models\Deal;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Http\Livewire\Quotations\QuotationForm;
use App\Modules\Sales\Http\Livewire\Quotations\QuotationList;
use App\Modules\Sales\Http\Livewire\Quotations\QuotationShow;
use App\Modules\Sales\Mail\QuotationMail;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');   // a Monday
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24', 'gstin' => gstinFor('24', 'ABCDE1234F')]);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->customer = makeCustomer($this->acme);
    $this->bolt = makeUsableItem($this->acme);
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG'), 'gst_rate' => '12']);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');
    makeStandardRate($this->acme, $this->wire, $this->ahd, '80');

    $this->deal = fn ($by = null, bool $approved = true): Deal => $approved
        ? approveDocument($this->acme, makeDeal($this->acme, $by ?? $this->bde, makeLead($this->acme, $by ?? $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')])), $by ?? $this->bde, $this->approver)
        : makeDeal($this->acme, $by ?? $this->bde, makeLead($this->acme, $by ?? $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]));
    $this->quote = fn (string $upTo = 'draft', $by = null, ?Deal $deal = null) => makeQuotation($this->acme, $by ?? $this->bde, $deal ?? ($this->deal)($by), [], null, $upTo, $this->approver);
    $this->row = fn (Quotation $q) => 'wire:key="quo-'.$q->id.'"';
    $this->show = fn ($user, Quotation $q) => Livewire::actingAs($user)->test(QuotationShow::class, ['quotation' => $q->fresh()]);
});

afterEach(fn () => Carbon::setTestNow());

// ---- the list --------------------------------------------------------------------------------------------------------------

it('lists exactly the quotations a person may see, with what is out with customers', function () {
    $mine = ($this->quote)('sent');
    $draft = ($this->quote)('draft');
    $colleague = ($this->quote)('sent', $this->bde2);

    Livewire::actingAs($this->bde)->test(QuotationList::class)
        ->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($draft))->assertDontSeeHtml(($this->row)($colleague))
        ->assertViewHas('outCount', 1)->assertViewHas('outValue', '1705.00');

    Livewire::actingAs($this->manager)->test(QuotationList::class)
        ->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($colleague))->assertViewHas('outCount', 2);

    Livewire::actingAs($this->globexAdmin)->test(QuotationList::class)->assertDontSeeHtml(($this->row)($mine));
});

it('counts the states over what the person may see, and narrows by state, text and approval', function () {
    $sent = ($this->quote)('sent');
    $approved = ($this->quote)('approved');
    $draft = ($this->quote)('draft');
    $declined = inCompany($this->acme, fn () => app(QuotationService::class)->decline(($this->quote)('sent'), 'Dear', $this->bde));

    $list = Livewire::actingAs($this->bde)->test(QuotationList::class);

    expect($list->viewData('tabs'))->toBe([['live', 'Live', 3], ['draft', 'Draft', 2], ['sent', 'Sent', 1], ['accepted', 'Accepted', 0], ['declined', 'Declined', 1], ['expired', 'Expired', 0], ['cancelled', 'Cancelled', 0], ['', 'All', 4]]);

    $list->assertSet('state', 'live')->assertSeeHtml(($this->row)($sent))->assertDontSeeHtml(($this->row)($declined));
    $list->call('setState', 'declined')->assertSeeHtml(($this->row)($declined))->assertDontSeeHtml(($this->row)($sent));
    $list->call('setState', '')->assertSeeHtml(($this->row)($declined))->assertSeeHtml(($this->row)($sent));
    $list->call('setState', 'nonsense')->assertSet('state', 'live');

    $list->set('approval', 'draft')->assertSeeHtml(($this->row)($draft))->assertDontSeeHtml(($this->row)($approved))->set('approval', '');
    $list->set('search', $approved->quotation_number)->assertSeeHtml(($this->row)($approved))->assertDontSeeHtml(($this->row)($draft));
});

it('shows quotations that are about to run out', function () {
    $soon = ($this->quote)('sent');
    $later = makeQuotation($this->acme, $this->bde, ($this->deal)(), ['valid_until' => '2026-12-01'], null, 'sent', $this->approver);
    Carbon::setTestNow('2026-11-06 09:00:00');

    Livewire::actingAs($this->bde)->test(QuotationList::class)->set('validity', 'expiring')
        ->assertSeeHtml(($this->row)($soon))->assertDontSeeHtml(($this->row)($later));
});

it('shows a sales menu entry to those who may view quotations, and a 403 to those who may not', function () {
    $this->actingAs($this->bde)->get(route('sales.quotations.index'))->assertOk()->assertSee('Quotations');

    $telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->actingAs($telecaller)->get(route('sales.quotations.index'))->assertForbidden();
    $this->get(route('sales.quotations.index'))->assertForbidden();
});

// ---- making one -------------------------------------------------------------------------------------------------------------

it('starts a new quotation with the deal’s lines priced, and saves it', function () {
    $deal = ($this->deal)();

    $form = Livewire::actingAs($this->bde)->test(QuotationForm::class, ['deal' => $deal->id])
        ->assertSee('Make a quotation')->assertSee('Rate from the master')->assertSee('125.50')
        ->assertSet('q.valid_until', '2026-11-11')->assertSet('q.party_name', $deal->party_name);

    // the lines are priced as soon as they are there; the totals wait for the rest (a place of supply and an address)
    expect($form->get('items'))->toHaveCount(2)->and($form->viewData('preview')['totals'])->toBeNull();

    $form->set('q.place_of_supply_state_id', (string) stateId($this->acme, '24'))->set('q.bill_line1', '12 Industrial Estate')->set('q.email', 'buyer@example.com');
    expect($form->viewData('preview')['totals']['grand_total'])->toBe('1705.00');

    $form->call('save')->assertHasNoErrors();

    $q = Quotation::query()->sole();
    $form->assertRedirect(route('sales.quotations.show', $q));
    expect($q->quotation_number)->toBe('QT/AHD/26-27/00001')->and((string) $q->grand_total)->toBe('1705.00')->and($q->created_by)->toBe($this->bde->id);
});

it('shows what is wrong beside the field, and saves nothing', function () {
    $deal = ($this->deal)();

    Livewire::actingAs($this->bde)->test(QuotationForm::class, ['deal' => $deal->id])
        ->set('q.bill_line1', '')->set('q.place_of_supply_state_id', '')->set('q.valid_until', '2026-10-01')->set('items.0.discount_percent', '150')
        ->call('save')
        ->assertHasErrors(['quotation.bill_line1', 'quotation.place_of_supply_state_id', 'quotation.valid_until'])
        ->assertSee('Enter the billing address.')->assertSee('Choose the state the goods are supplied to');

    expect(Quotation::query()->count())->toBe(0);
});

it('tells the person when an item has no rate, instead of showing a price', function () {
    $nail = makeUsableItem($this->acme, 'Nail 2 inch');
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($nail, '100', '')])), $this->bde, $this->approver);

    Livewire::actingAs($this->bde)->test(QuotationForm::class, ['deal' => $deal->id])->assertSee('There is no rate for Nail 2 inch')
        ->set('q.place_of_supply_state_id', (string) stateId($this->acme, '24'))->set('q.bill_line1', 'x')->call('save')->assertSee('There is no rate for Nail 2 inch');

    expect(Quotation::query()->count())->toBe(0);
});

it('sends a person away from a deal that cannot be quoted yet, saying why', function () {
    $draft = ($this->deal)(null, false);

    Livewire::actingAs($this->bde)->test(QuotationForm::class, ['deal' => $draft->id])->assertRedirect(route('crm.deals.show', $draft));
    expect(session('status'))->toContain('approved');

    Livewire::actingAs($this->bde2)->test(QuotationForm::class, ['deal' => ($this->deal)()->id])->assertForbidden();
});

it('lets only someone who may override a rate see the fields for it', function () {
    $deal = ($this->deal)();

    Livewire::actingAs($this->bde)->test(QuotationForm::class, ['deal' => $deal->id])->assertDontSee('Quote at a different rate');
    Livewire::actingAs($this->manager)->test(QuotationForm::class, ['deal' => $deal->id])->assertSee('Quote at a different rate');
});

it('changes a draft, but refuses to open one that has been approved or sent', function () {
    $q = ($this->quote)();

    Livewire::actingAs($this->bde)->test(QuotationForm::class, ['editing' => $q->fresh()])->assertSee('Edit quotation')->assertSet('q.party_name', $q->party_name)
        ->set('q.remarks', 'Call before dispatch')->call('save')->assertHasNoErrors();
    expect($q->fresh()->remarks)->toBe('Call before dispatch');

    foreach (['approved', 'sent'] as $upTo) {
        $locked = ($this->quote)($upTo);
        Livewire::actingAs($this->bde)->test(QuotationForm::class, ['editing' => $locked->fresh()])->assertForbidden();
    }
});

it('makes a quotation straight from an approved inquiry', function () {
    $inquiry = approveDocument($this->acme, qualify($this->acme, makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '120')]), $this->bde), $this->bde, $this->approver);

    $show = Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $inquiry->fresh()])->assertSee('Quote directly');
    expect($show->viewData('canQuote'))->toBeTrue();

    $form = Livewire::actingAs($this->bde)->test(QuotationForm::class, ['inquiry' => $inquiry->id])->assertSee('Inquiry');
    expect($form->get('items'))->toHaveCount(1);

    $form->set('q.place_of_supply_state_id', (string) stateId($this->acme, '24'))->set('q.bill_line1', '7 Mill Road')->call('save')->assertHasNoErrors();
    expect(Quotation::query()->sole()->deal_id)->toBeNull();
});

// ---- the page of one quotation ------------------------------------------------------------------------------------------------

it('shows a quotation with its figures, tax and state, to those who may open it', function () {
    $q = ($this->quote)('approved');

    ($this->show)($this->bde, $q)->assertOk()->assertSee($q->quotation_number)->assertSee('Rev 0')->assertSee('Approved')->assertSee('CGST')->assertSee('SGST')->assertDontSee('IGST')
        ->assertSee('1,705.00')->assertSee('Send to customer')->assertSee('Download PDF')->assertSee('7318')->assertDontSee('Customer accepted');

    ($this->show)($this->manager, $q)->assertOk();
    Livewire::actingAs($this->bde2)->test(QuotationShow::class, ['quotation' => $q->fresh()])->assertForbidden();
    Livewire::actingAs($this->globexAdmin)->test(QuotationShow::class, ['quotation' => $q->fresh()])->assertForbidden();
});

it('shows IGST for a supply to another state', function () {
    $q = makeQuotation($this->acme, $this->bde, ($this->deal)(), ['place_of_supply_state_id' => (string) stateId($this->acme, '27')], null, 'draft', $this->approver);

    ($this->show)($this->bde, $q)->assertSee('IGST')->assertDontSee('SGST')->assertSee('Inter-state');
});

it('flags a line whose rate was changed, with the master’s rate and the reason', function () {
    $deal = ($this->deal)();
    $rows = quoteInput($deal)['items'];
    $rows[0] = [...$rows[0], 'rate_override' => '100', 'override_reason' => 'Volume order'];
    $q = inCompany($this->acme, fn () => app(QuotationService::class)->create($deal, quoteInput($deal, [], $rows), $this->manager));

    ($this->show)($this->manager, $q)->assertSee('Rate changed')->assertSeeHtml('Master rate ₹ 125.50 — Volume order');
});

it('sends an approved quotation from its page, recording who and how', function () {
    $q = ($this->quote)('approved');

    $page = ($this->show)($this->bde, $q)->call('openDialog', 'send')->assertSee('Send this quotation to the customer')
        ->set('channel', 'manual')->call('sendQuotation')->assertHasNoErrors();

    $page->assertRedirect(route('sales.quotations.show', $q));
    expect($q->fresh()->state)->toBe(QuotationState::Sent);
    ($this->show)($this->bde, $q)->assertSee('Sent to the customer')->assertSee('Recorded as handed over')->assertSee('Customer accepted');
});

it('e-mails it from its page, and shows a failure beside the button without losing the quotation', function () {
    Mail::fake();
    $q = ($this->quote)('approved');

    ($this->show)($this->bde, $q)->call('openDialog', 'send')->assertSet('to', 'buyer@example.com')->set('to', 'not-an-address')->call('sendQuotation')->assertHasErrors('to');
    expect($q->fresh()->state)->toBe(QuotationState::Draft);

    ($this->show)($this->bde, $q)->call('openDialog', 'send')->call('sendQuotation')->assertHasNoErrors();
    expect($q->fresh()->state)->toBe(QuotationState::Sent);
    Mail::assertSent(QuotationMail::class, 1);
});

it('records the customer’s answer from the page, and the refusal needs a reason', function () {
    $q = ($this->quote)('sent');

    ($this->show)($this->bde, $q)->call('openDialog', 'decline')->set('reason', '')->call('recordDeclined')->assertHasErrors('reason');
    expect($q->fresh()->state)->toBe(QuotationState::Sent);

    ($this->show)($this->bde, $q)->call('openDialog', 'accept')->set('note', 'PO 4471')->call('recordAccepted')->assertHasNoErrors();
    expect($q->fresh()->state)->toBe(QuotationState::Accepted);
    ($this->show)($this->bde, $q)->assertSee('PO 4471')->assertSee('Accepted');
});

it('makes a revision from the page and opens it for editing', function () {
    $q = ($this->quote)('sent');

    $page = ($this->show)($this->bde, $q)->call('openDialog', 'revise')->assertSee('Make revision 1')->set('reason', 'Customer wants 20')->call('reviseQuotation');
    $rev1 = Quotation::query()->where('revision_no', 1)->sole();

    $page->assertRedirect(route('sales.quotations.edit', $rev1));
    ($this->show)($this->bde, $q)->assertSee('replaced by a newer one')->assertDontSee('Make a new revision');
    ($this->show)($this->bde, $rev1)->assertSee('Revisions')->assertSee('Revision 0');
});

it('withdraws a quotation from the page, with a reason', function () {
    $q = ($this->quote)('sent');

    ($this->show)($this->bde, $q)->call('openDialog', 'cancel')->call('cancelQuotation')->assertHasErrors('reason');
    ($this->show)($this->bde, $q)->call('openDialog', 'cancel')->set('reason', 'Customer withdrew')->call('cancelQuotation')->assertHasNoErrors();

    expect($q->fresh()->state)->toBe(QuotationState::Cancelled);
    ($this->show)($this->bde, $q)->assertSee('Withdrawn')->assertSee('Customer withdrew');
});

it('re-opens an approved draft for editing, with a reason', function () {
    $q = ($this->quote)('approved');

    ($this->show)($this->bde, $q)->call('openDialog', 'reopen-approval')->call('reopenApproval')->assertHasErrors('reason');
    ($this->show)($this->bde, $q)->call('openDialog', 'reopen-approval')->set('reason', 'Wrong address')->call('reopenApproval')->assertRedirect(route('sales.quotations.edit', $q));

    expect($q->fresh()->isEditableByWorkflow())->toBeTrue();
});

it('refuses a dialog the person has no right to, and one that does not exist', function () {
    $q = ($this->quote)('draft');

    ($this->show)($this->bde, $q)->call('openDialog', 'send')->assertForbidden();
    ($this->show)($this->bde, $q)->call('openDialog', 'format-the-disk')->assertNotFound();
});

it('puts a quotation waiting for approval in front of the approver, with the approval panel', function () {
    $q = ($this->quote)();
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($q->fresh(), $this->bde));

    ($this->show)($this->approver, $q)->assertOk()->assertSee('waiting for approval')->assertSee($q->quotation_number);
});

// ---- the PDF -------------------------------------------------------------------------------------------------------------------------

it('downloads the PDF for whoever may open the quotation, and for nobody else', function () {
    $q = ($this->quote)('approved');

    $response = $this->actingAs($this->bde)->get(route('sales.quotations.pdf', $q))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')->and($response->headers->get('Content-Disposition'))->toContain('qt-ahd-26-27-00001-Rev0.pdf')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');

    $this->actingAs($this->manager)->get(route('sales.quotations.pdf', $q))->assertOk();
    $this->actingAs($this->bde2)->get(route('sales.quotations.pdf', $q))->assertForbidden();
    $this->actingAs($this->globexAdmin)->get(route('sales.quotations.pdf', $q))->assertNotFound();
    auth()->logout();
    $this->get(route('sales.quotations.pdf', $q))->assertRedirect();
});

// ---- from the deal --------------------------------------------------------------------------------------------------------------------

it('offers a quotation on an approved deal, lists the quotations made from it, and says a quotation is optional', function () {
    $deal = ($this->deal)();

    Livewire::actingAs($this->bde)->test(DealShow::class, ['deal' => $deal->fresh()])->assertSee('Make a quotation')->assertSee('No quotation has been made')->assertSee('optional');

    $q = ($this->quote)('sent', null, $deal);

    Livewire::actingAs($this->bde)->test(DealShow::class, ['deal' => $deal->fresh()])->assertSee($q->quotation_number)->assertSee('Sent')->assertSee('Quotation');
    $this->actingAs($this->bde)->get(route('crm.deals.show', $deal))->assertOk();
});

it('offers no quotation on a deal that is not approved, or to someone who may not work it', function () {
    $draft = ($this->deal)(null, false);
    expect(Livewire::actingAs($this->bde)->test(DealShow::class, ['deal' => $draft->fresh()])->viewData('canQuote'))->toBeFalse();

    $approved = ($this->deal)();
    expect(Livewire::actingAs($this->approver)->test(DealShow::class, ['deal' => $approved->fresh()])->viewData('canQuote'))->toBeFalse();
});
