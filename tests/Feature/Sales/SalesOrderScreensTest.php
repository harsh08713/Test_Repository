<?php

use App\Modules\CRM\Http\Livewire\Deals\DealShow;
use App\Modules\CRM\Models\Deal;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Sales\Http\Livewire\Invoices\SalesInvoiceList;
use App\Modules\Sales\Http\Livewire\Invoices\SalesInvoiceShow;
use App\Modules\Sales\Http\Livewire\Orders\SalesOrderForm;
use App\Modules\Sales\Http\Livewire\Orders\SalesOrderList;
use App\Modules\Sales\Http\Livewire\Orders\SalesOrderShow;
use App\Modules\Sales\Http\Livewire\Quotations\QuotationList;
use App\Modules\Sales\Http\Livewire\Quotations\QuotationShow;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPdf;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
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
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $this->bolt = makeUsableItem($this->acme);
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG'), 'gst_rate' => '12']);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');
    makeStandardRate($this->acme, $this->wire, $this->ahd, '80');

    $this->deal = function ($by = null): Deal {
        $by ??= $this->bde;

        return approveDocument($this->acme, makeDeal($this->acme, $by, makeLead($this->acme, $by, $this->ahd, [], [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]), ['customer_ledger_id' => (string) $this->customer->id]), $by, $this->approver);
    };
    $this->quotation = function (?Deal $deal = null) {
        $q = makeQuotation($this->acme, $this->bde, $deal ?? ($this->deal)(), [], null, 'sent', $this->approver);

        return inCompany($this->acme, fn () => app(QuotationService::class)->accept($q->fresh(), 'PO received', $this->bde));
    };
    $this->order = fn (string $upTo = 'draft', $by = null) => makeSalesOrder($this->acme, $by ?? $this->bde, ($this->deal)($by), $this->customer, [], null, $upTo, $this->approver);
    $this->row = fn (SalesOrder $o) => 'wire:key="so-'.$o->id.'"';
    $this->show = fn ($user, SalesOrder $o) => Livewire::actingAs($user)->test(SalesOrderShow::class, ['order' => $o->fresh()]);
});

afterEach(fn () => Carbon::setTestNow());

// ---- the list -----------------------------------------------------------------------------------------------------------------

it('lists exactly the orders a person may see, with what is on the books', function () {
    $mine = ($this->order)('approved');
    $draft = ($this->order)();
    $colleague = ($this->order)('approved', $this->bde2);

    Livewire::actingAs($this->bde)->test(SalesOrderList::class)
        ->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($draft))->assertDontSeeHtml(($this->row)($colleague))
        ->assertViewHas('standingCount', 1)->assertViewHas('standingValue', '1705.00');

    Livewire::actingAs($this->manager)->test(SalesOrderList::class)->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($colleague))->assertViewHas('standingCount', 2);
    Livewire::actingAs($this->accounts)->test(SalesOrderList::class)->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($colleague))->assertSeeHtml(($this->row)($draft));
    Livewire::actingAs($this->globexAdmin)->test(SalesOrderList::class)->assertDontSeeHtml(($this->row)($mine));
});

it('counts the states over what the person may see, and narrows by state, text and approval', function () {
    $invoiced = ($this->order)('approved');
    $draft = ($this->order)();
    $cancelled = inCompany($this->acme, fn () => app(SalesOrderService::class)->cancel(($this->order)(), 'Mistake', $this->manager));

    $list = Livewire::actingAs($this->bde)->test(SalesOrderList::class);

    expect($list->viewData('tabs'))->toBe([['live', 'Live', 2], ['open', 'Open', 1], ['invoiced', 'Invoiced', 1], ['cancelled', 'Cancelled', 1], ['', 'All', 3]]);

    $list->assertSeeHtml(($this->row)($invoiced))->assertSeeHtml(($this->row)($draft))->assertDontSeeHtml(($this->row)($cancelled));
    $list->call('setState', 'cancelled')->assertSeeHtml(($this->row)($cancelled))->assertDontSeeHtml(($this->row)($draft));
    $list->call('setState', '')->assertSeeHtml(($this->row)($cancelled))->assertSeeHtml(($this->row)($invoiced));
    $list->call('setState', 'nonsense')->assertSet('state', 'live');
    $list->set('search', $invoiced->so_number)->assertSeeHtml(($this->row)($invoiced))->assertDontSeeHtml(($this->row)($draft));
});

it('shows the sales menu entries to those who may view orders and invoices, and a 403 to those who may not', function () {
    $this->actingAs($this->bde)->get(route('sales.orders.index'))->assertOk()->assertSee('Sales orders');
    $this->actingAs($this->accounts)->get(route('sales.invoices.index'))->assertOk()->assertSee('Sales invoices');

    $telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->actingAs($telecaller)->get(route('sales.orders.index'))->assertForbidden();
    $this->actingAs($telecaller)->get(route('sales.invoices.index'))->assertForbidden();
});

// ---- making one -----------------------------------------------------------------------------------------------------------------

it('starts an order from an accepted quotation with its lines as quoted, and saves it', function () {
    $q = ($this->quotation)();

    $form = Livewire::actingAs($this->bde)->test(SalesOrderForm::class, ['quotation' => $q->id])
        ->assertSee('Make a sales order')->assertSee('keeps the price it was quoted at')->assertSet('o.party_name', $q->party_name)
        ->assertSet('o.delivery_date', '2026-10-26')->assertSet('o.bill_line1', '12 Industrial Estate')->assertSet('customerId', $this->customer->id);

    expect($form->get('items'))->toHaveCount(2)->and($form->viewData('preview')['totals']['grand_total'])->toBe('1705.00');

    $form->set('o.customer_po_number', 'PO-4471')->set('o.customer_po_date', '2026-10-10')->call('save')->assertHasNoErrors();

    $o = SalesOrder::query()->sole();
    $form->assertRedirect(route('sales.orders.show', $o));
    expect($o->so_number)->toBe('SO/AHD/26-27/00001')->and($o->customer_po_number)->toBe('PO-4471')->and((string) $o->grand_total)->toBe('1705.00')->and($o->quotation_id)->toBe($q->id);
});

it('shows what is wrong beside the field, and saves nothing', function () {
    $q = ($this->quotation)();

    Livewire::actingAs($this->bde)->test(SalesOrderForm::class, ['quotation' => $q->id])
        ->set('o.bill_line1', '')->set('o.delivery_date', '2026-10-01')->set('items.0.discount_percent', '150')
        ->call('save')
        ->assertHasErrors(['order.bill_line1', 'order.delivery_date'])
        ->assertSee('Enter the billing address.');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('starts an order from an approved deal, priced from the rate master', function () {
    $deal = ($this->deal)();

    $form = Livewire::actingAs($this->bde)->test(SalesOrderForm::class, ['deal' => $deal->id])->assertSee('priced from the rate master')->assertSet('dealId', $deal->id);
    expect($form->get('items'))->toHaveCount(2);

    $form->call('save')->assertHasNoErrors();
    expect(SalesOrder::query()->sole()->source_type)->toBe('deal');
});

it('sends a person away from a source that cannot be ordered from, saying why', function () {
    $q = ($this->quotation)();
    $first = ($this->order)('draft', null);   // another deal's order, unrelated

    ($this->order)('draft');
    $deal = ($this->deal)();
    makeSalesOrder($this->acme, $this->bde, $deal, $this->customer);

    Livewire::actingAs($this->bde)->test(SalesOrderForm::class, ['deal' => $deal->id])->assertRedirect(route('sales.orders.show', SalesOrder::query()->where('deal_id', $deal->id)->first()));
    expect(session('status'))->toContain('already been ordered');

    $draftQuotation = makeQuotation($this->acme, $this->bde, ($this->deal)(), [], null, 'draft', $this->approver);
    Livewire::actingAs($this->bde)->test(SalesOrderForm::class, ['quotation' => $draftQuotation->id])->assertRedirect(route('sales.quotations.show', $draftQuotation));
    expect(session('status'))->toContain('not been sent');

    Livewire::actingAs($this->bde2)->test(SalesOrderForm::class, ['deal' => ($this->deal)()->id])->assertForbidden();
    expect($first->id)->not->toBe($q->id);
});

it('asks for a reason before ordering from a quotation that has not been accepted — from someone allowed to', function () {
    $q = makeQuotation($this->acme, $this->bde, ($this->deal)(), [], null, 'sent', $this->approver);   // sent, not accepted

    // a salesperson is sent back to the quotation
    Livewire::actingAs($this->bde)->test(SalesOrderForm::class, ['quotation' => $q->id])->assertRedirect(route('sales.quotations.show', $q));

    // a manager sees the exception box, and needs to give a reason
    $form = Livewire::actingAs($this->manager)->test(SalesOrderForm::class, ['quotation' => $q->id])->assertSee('not in the usual state to order from');
    $form->call('save')->assertHasErrors('form');
    expect(SalesOrder::query()->count())->toBe(0);

    $form->set('exceptionReason', 'Customer phoned to confirm')->call('save')->assertHasNoErrors();
    expect(SalesOrder::query()->sole()->source_type)->toBe('quotation');
});

it('makes a direct order for a regular customer: the customer and branch are found first', function () {
    ($this->order)('approved');   // the customer's first approved order makes them regular

    $form = Livewire::actingAs($this->bde)->test(SalesOrderForm::class)->assertSee('Direct order')
        ->set('customerSearch', 'Ganesh')->assertSee('Shree Ganesh Industries')->call('pickCustomer', $this->customer->id)
        ->assertSet('customerId', $this->customer->id)->set('locationId', (string) $this->ahd->id)->assertSet('o.bill_line1', fn ($v) => $v !== null);

    $form->set('o.bill_line1', '7 Mill Road')->set('o.place_of_supply_state_id', (string) stateId($this->acme, '24'))
        ->set('items.0.item_id', (string) $this->bolt->id)->set('items.0.unit_id', (string) $this->bolt->base_unit_id)->set('items.0.quantity', '4')
        ->call('save')->assertHasNoErrors();

    expect(SalesOrder::query()->where('source_type', 'direct')->sole()->assigned_to)->toBe($this->bde->id);
});

it('refuses a direct order for a customer who is not regular yet, in words', function () {
    $form = Livewire::actingAs($this->bde)->test(SalesOrderForm::class)->call('pickCustomer', $this->customer->id)->set('locationId', (string) $this->ahd->id)
        ->set('o.bill_line1', '7 Mill Road')->set('o.place_of_supply_state_id', (string) stateId($this->acme, '24'))
        ->set('items.0.item_id', (string) $this->bolt->id)->set('items.0.unit_id', (string) $this->bolt->base_unit_id)->set('items.0.quantity', '4')
        ->call('save');

    $form->assertHasErrors('form')->assertSee('not a regular customer yet');
    expect(SalesOrder::query()->count())->toBe(0);
});

it('changes a draft, but refuses to open one that has been sent for approval', function () {
    $o = ($this->order)();

    Livewire::actingAs($this->bde)->test(SalesOrderForm::class, ['editing' => $o->fresh()])->assertSee('Edit sales order')->assertSet('o.party_name', $o->party_name)
        ->set('o.customer_po_number', 'PO-9')->call('save')->assertHasNoErrors();
    expect($o->fresh()->customer_po_number)->toBe('PO-9');

    $pending = ($this->order)('pending');
    Livewire::actingAs($this->bde)->test(SalesOrderForm::class, ['editing' => $pending->fresh()])->assertForbidden();
});

// ---- the page of one order ------------------------------------------------------------------------------------------------------

it('shows an order with its figures, tax, credit check and state, to those who may open it', function () {
    $o = ($this->order)('approved');

    ($this->show)($this->bde, $o)->assertOk()->assertSee($o->so_number)->assertSee('Invoiced')->assertSee('CGST')->assertSee('1,705.00')->assertSee('Credit check')
        ->assertSee('Within the credit limit')->assertSee('View the invoice')->assertSee('Order acknowledgment (PDF)');

    ($this->show)($this->accounts, $o)->assertOk();
    Livewire::actingAs($this->bde2)->test(SalesOrderShow::class, ['order' => $o->fresh()])->assertForbidden();
    Livewire::actingAs($this->globexAdmin)->test(SalesOrderShow::class, ['order' => $o->fresh()])->assertForbidden();
});

it('says when an approved order has no invoice yet and offers to make it', function () {
    $fail = true;
    SalesInvoiceItem::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('Try again later');
        }
    });
    $o = ($this->order)('approved');

    ($this->show)($this->bde, $o)->assertSee('approved but its invoice has not been made yet')->assertSee('Try again later')->assertSee('Make the invoice now');

    $fail = false;
    ($this->show)($this->bde, $o)->call('makeInvoiceNow')->assertHasNoErrors();
    expect($o->fresh()->state)->toBe(SalesOrderState::Invoiced);
});

it('allows an order over a blocking credit limit from the page, with a reason', function () {
    app(SettingsService::class)->set('credit_limit.behaviour', 'block', $this->acme->id);
    $this->customer->forceFill(['credit_limit' => '1000'])->save();
    $o = ($this->order)();

    ($this->show)($this->bde, $o)->assertSee('Over the credit limit (blocked)')->assertDontSee('Allow over the credit limit');
    ($this->show)($this->manager, $o)->call('openDialog', 'credit')->call('allowOverCredit')->assertHasErrors('reason');
    ($this->show)($this->manager, $o)->call('openDialog', 'credit')->set('reason', 'Advance paid')->call('allowOverCredit')->assertHasNoErrors();

    expect($o->fresh()->credit_override_reason)->toBe('Advance paid');
    ($this->show)($this->bde, $o)->assertSee('Allowed over the limit')->assertSee('Advance paid');
});

it('cancels an order from the page, with a reason, and its invoice with it', function () {
    $o = ($this->order)('approved');

    ($this->show)($this->manager, $o)->call('openDialog', 'cancel')->call('cancelOrder')->assertHasErrors('reason');
    ($this->show)($this->manager, $o)->call('openDialog', 'cancel')->set('reason', 'Customer withdrew')->call('cancelOrder')->assertHasNoErrors();

    expect($o->fresh()->state)->toBe(SalesOrderState::Cancelled)->and($o->invoice->fresh()->isCancelled())->toBeTrue();
    ($this->show)($this->manager, $o)->assertSee('Cancelled')->assertSee('Customer withdrew');
});

it('refuses a dialog the person has no right to, and one that does not exist', function () {
    $o = ($this->order)('approved');

    ($this->show)($this->bde, $o)->call('openDialog', 'cancel')->assertForbidden();
    ($this->show)($this->bde, $o)->call('openDialog', 'format-the-disk')->assertNotFound();
});

it('puts an order waiting for approval in front of the approver, and approving it from the inbox makes the invoice', function () {
    $o = ($this->order)('pending');

    ($this->show)($this->approver, $o)->assertOk()->assertSee('waiting for approval');

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($o->fresh(), $this->approver));
    expect($o->fresh()->state)->toBe(SalesOrderState::Invoiced)->and($o->fresh()->invoice)->not->toBeNull();
});

// ---- the PDFs -------------------------------------------------------------------------------------------------------------------------

it('downloads the order acknowledgment and the invoice for whoever may open them, and for nobody else', function () {
    $o = ($this->order)('approved');

    $ack = $this->actingAs($this->bde)->get(route('sales.orders.pdf', $o))->assertOk();
    expect($ack->headers->get('Content-Type'))->toBe('application/pdf')->and($ack->headers->get('Content-Disposition'))->toContain('so-ahd-26-27-00001-acknowledgment.pdf')
        ->and(substr($ack->getContent(), 0, 5))->toBe('%PDF-');

    $inv = $this->actingAs($this->accounts)->get(route('sales.invoices.pdf', $o->invoice))->assertOk();
    expect($inv->headers->get('Content-Disposition'))->toContain('si-ahd-26-27-00001.pdf')->and(substr($inv->getContent(), 0, 5))->toBe('%PDF-');

    $this->actingAs($this->bde2)->get(route('sales.orders.pdf', $o))->assertForbidden();
    $this->actingAs($this->bde2)->get(route('sales.invoices.pdf', $o->invoice))->assertForbidden();
    $this->actingAs($this->globexAdmin)->get(route('sales.orders.pdf', $o))->assertNotFound();
    $this->actingAs($this->globexAdmin)->get(route('sales.invoices.pdf', $o->invoice))->assertNotFound();
    auth()->logout();
    $this->get(route('sales.orders.pdf', $o))->assertRedirect();
});

it('renders the PDFs from what is stored, escaping everything typed', function () {
    $o = ($this->order)('draft');
    $o->forceFill(['terms' => 'Pay in 30 days <script>alert(1)</script>'])->save();
    $pdf = app(SalesPdf::class);

    expect(substr(inCompany($this->acme, fn () => $pdf->order($o->fresh())), 0, 5))->toBe('%PDF-');
});

// ---- the invoice pages --------------------------------------------------------------------------------------------------------------

it('lists and shows invoices to those who may see them', function () {
    $o = ($this->order)('approved');
    $other = ($this->order)('approved', $this->bde2);

    Livewire::actingAs($this->bde)->test(SalesInvoiceList::class)->assertSee($o->invoice->invoice_number)->assertDontSee($other->invoice->invoice_number)->assertViewHas('issuedValue', '1705.00');
    Livewire::actingAs($this->accounts)->test(SalesInvoiceList::class)->assertSee($o->invoice->invoice_number)->assertSee($other->invoice->invoice_number);
    Livewire::actingAs($this->globexAdmin)->test(SalesInvoiceList::class)->assertDontSee($o->invoice->invoice_number);

    Livewire::actingAs($this->bde)->test(SalesInvoiceShow::class, ['invoice' => $o->invoice->fresh()])->assertOk()->assertSee($o->invoice->invoice_number)->assertSee('1,705.00')->assertSee('Download PDF')->assertSee($o->so_number);
    Livewire::actingAs($this->bde2)->test(SalesInvoiceShow::class, ['invoice' => $o->invoice->fresh()])->assertForbidden();

    inCompany($this->acme, fn () => app(SalesOrderService::class)->cancel($o->fresh(), 'Mistake', $this->manager));
    Livewire::actingAs($this->bde)->test(SalesInvoiceList::class)->assertDontSee($o->invoice->invoice_number)->call('setStatus', 'cancelled')->assertSee($o->invoice->invoice_number);
    Livewire::actingAs($this->bde)->test(SalesInvoiceShow::class, ['invoice' => $o->invoice->fresh()])->assertSee('Cancelled');
});

// ---- from the deal and the quotation -------------------------------------------------------------------------------------------------

it('offers a sales order on an approved deal and on an accepted quotation, and lists the orders made', function () {
    $q = ($this->quotation)();
    $deal = $q->deal;

    Livewire::actingAs($this->bde)->test(DealShow::class, ['deal' => $deal->fresh()])->assertSee('Make a sales order');
    Livewire::actingAs($this->bde)->test(QuotationShow::class, ['quotation' => $q->fresh()])->assertSee('Make a sales order');

    $o = makeSalesOrder($this->acme, $this->bde, $q->fresh(), $this->customer);

    Livewire::actingAs($this->bde)->test(DealShow::class, ['deal' => $deal->fresh()])->assertDontSee('Make a sales order')->assertSee($o->so_number);
    Livewire::actingAs($this->bde)->test(QuotationShow::class, ['quotation' => $q->fresh()])->assertDontSee('Make a sales order')->assertSee('Sales orders made from this quotation')->assertSee($o->so_number);
});

it('offers no order on a deal that is not approved, or on a quotation that is only a draft', function () {
    $draftDeal = makeDeal($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]));
    expect(Livewire::actingAs($this->bde)->test(DealShow::class, ['deal' => $draftDeal->fresh()])->viewData('canOrder'))->toBeFalse();

    $draft = makeQuotation($this->acme, $this->bde, ($this->deal)(), [], null, 'draft', $this->approver);
    expect(Livewire::actingAs($this->bde)->test(QuotationShow::class, ['quotation' => $draft->fresh()])->viewData('canOrder'))->toBeFalse();
});

it('has a list of quotations that still works with the new ordering links', function () {
    expect(Quotation::query()->count())->toBe(0);
    Livewire::actingAs($this->bde)->test(QuotationList::class)->assertOk();
});
