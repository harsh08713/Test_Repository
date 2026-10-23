<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Models\Brand;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\CostResolver;
use App\Modules\Sales\Http\Livewire\Invoices\SalesInvoiceShow;
use App\Modules\Sales\Http\Livewire\Returns\SalesReturnForm;
use App\Modules\Sales\Http\Livewire\Returns\SalesReturnList;
use App\Modules\Sales\Http\Livewire\Returns\SalesReturnShow;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Sales\Services\NetRevenueCalculator;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Brand and standard cost on items, the cost copied onto an invoice line, returns and credit notes against an invoice, and the net revenue
 * they feed (FORMULAS.md §2). Hand-calculated: 10 bolts at ₹125.50 (cost ₹100) and 2.5 kg of wire at ₹80 (cost ₹60) — taxable ₹1,455.00,
 * cost ₹1,150.00. Returning 4 bolts is ₹502.00 taxable (CGST and SGST ₹45.18 each, ₹592.36 in all) and ₹400.00 of cost.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24', 'gstin' => gstinFor('24', 'ABCDE1234F')]);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $this->bolt = makeUsableItem($this->acme, 'MS Hex Bolt M10', ['standard_cost' => '100']);
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG'), 'gst_rate' => '12', 'standard_cost' => '60']);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');
    makeStandardRate($this->acme, $this->wire, $this->ahd, '80');

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->orderFor = function ($by = null) {
        $by ??= $this->bde;
        $deal = approveDocument($this->acme, makeDeal($this->acme, $by, makeLead($this->acme, $by, $this->ahd, [], [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]), ['customer_ledger_id' => (string) $this->customer->id]), $by, $this->approver);

        return makeSalesOrder($this->acme, $by, $deal, $this->customer, [], null, 'approved', $this->approver);
    };
    $this->order = ($this->orderFor)();
    $this->invoice = $this->order->invoice;
    $this->boltLine = SalesInvoiceItem::query()->where('sales_invoice_id', $this->invoice->id)->where('item_name', 'MS Hex Bolt M10')->firstOrFail();
    $this->wireLine = SalesInvoiceItem::query()->where('sales_invoice_id', $this->invoice->id)->where('item_name', 'Steel Wire 2mm')->firstOrFail();

    // a return, made through the service as Accounts
    $this->makeReturn = fn (array $lines, string $type = 'return', array $over = []) => ($this->do)(
        fn () => app(SalesReturnService::class)->create($this->invoice->fresh(), ['return_type' => $type, 'reason' => 'Damaged in transit', 'lines' => $lines, ...$over], $this->accounts)
    );
    $this->approve = fn (SalesReturn $r) => approveDocument($this->acme, $r, $this->accounts, $this->approver);
    $this->submit = fn (SalesReturn $r) => ($this->do)(fn () => app(ApprovalService::class)->submit($r->fresh(), $this->accounts));
});

afterEach(fn () => Carbon::setTestNow());

// ---- brand and standard cost on an item -------------------------------------------------------------------------------------------

it('keeps a brand and a standard cost on an item, and only a usable brand of the company', function () {
    $purchase = makeUser(['Purchase User'], [$this->acme]);
    $brand = ($this->do)(fn () => Brand::query()->create(['code' => 'ULTRA', 'name' => 'UltraBolt']));
    $inactive = ($this->do)(fn () => Brand::query()->create(['code' => 'OLD', 'name' => 'Old brand', 'status' => 'inactive']));
    $foreign = inCompany($this->globex, fn () => Brand::query()->create(['code' => 'THEIRS', 'name' => 'Their brand']));

    $item = makeItem($this->acme, $purchase, ['item' => ['name' => 'Branded bolt', 'brand_id' => (string) $brand->id, 'standard_cost' => '4.5']]);
    expect($item->brand_id)->toBe($brand->id)->and((string) $item->standard_cost)->toBe('4.5000');

    foreach ([[(string) $inactive->id, '1'], [(string) $foreign->id, '1'], ['', '-1'], ['', '1.23456'], ['', 'abc']] as [$brandId, $cost]) {
        expect(fn () => makeItem($this->acme, $purchase, ['item' => ['name' => 'Bad '.$cost.$brandId, 'brand_id' => $brandId, 'standard_cost' => $cost]]))->toThrow(ValidationException::class);
    }

    // no brand and no cost are allowed: both are optional
    $plain = makeItem($this->acme, $purchase, ['item' => ['name' => 'Plain bolt']]);
    expect($plain->brand_id)->toBeNull()->and($plain->standard_cost)->toBeNull();
});

// ---- the cost copied onto an invoice line ----------------------------------------------------------------------------------------

it('copies the item’s cost onto each invoice line when the invoice is made, and never changes it afterwards', function () {
    expect((string) $this->boltLine->unit_cost)->toBe('100.0000')->and((string) $this->boltLine->cost_amount)->toBe('1000.00')->and($this->boltLine->cost_source)->toBe('standard')
        ->and((string) $this->wireLine->unit_cost)->toBe('60.0000')->and((string) $this->wireLine->cost_amount)->toBe('150.00');

    DB::table('items')->where('id', $this->bolt->id)->update(['standard_cost' => '999']);   // the cost moves on; the invoice does not

    expect((string) SalesInvoiceItem::query()->find($this->boltLine->id)->cost_amount)->toBe('1000.00');
    expect(fn () => SalesInvoiceItem::query()->find($this->boltLine->id)->forceFill(['cost_amount' => '1.00'])->save())->toThrow(LogicException::class);
});

it('leaves the cost blank, not zero, for an item that has none', function () {
    $nocost = makeUsableItem($this->acme, 'Uncosted fitting');
    makeStandardRate($this->acme, $nocost, $this->ahd, '10');
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($nocost, '3', '')]), ['customer_ledger_id' => (string) $this->customer->id]), $this->bde, $this->approver);
    $invoice = makeSalesOrder($this->acme, $this->bde, $deal, $this->customer, [], null, 'approved', $this->approver)->invoice;

    $line = $invoice->items()->first();
    expect($line->unit_cost)->toBeNull()->and($line->cost_amount)->toBeNull()->and($line->cost_source)->toBeNull()
        ->and((string) $invoice->grand_total)->not->toBe('0.00');   // the invoice itself is unaffected
});

it('works out the cost of a unit other than the base unit', function () {
    $item = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'Boxed bolt', 'standard_cost' => '4.50']]);   // a box is 100 Nos
    $item->load('uoms');
    $resolver = app(CostResolver::class);

    expect($resolver->unitCost($item, $item->baseUnit))->toBe(['unit_cost' => '4.5000', 'source' => 'standard'])
        ->and($resolver->unitCost($item, Unit::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail(unitId($this->acme, 'BOX'))))->toBe(['unit_cost' => '450.0000', 'source' => 'standard']);
});

// ---- making a return --------------------------------------------------------------------------------------------------------------

it('makes a return at the invoice’s price and tax, numbered, linked and in draft, with the invoice line’s cost', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '4']);

    expect($r->return_number)->toBe('SR/AHD/26-27/00001')->and($r->approval_status->value)->toBe('draft')->and($r->sales_invoice_id)->toBe($this->invoice->id)
        ->and($r->assigned_to)->toBe($this->invoice->assigned_to)->and($r->location_id)->toBe($this->invoice->location_id)->and($r->return_type)->toBe('return')
        ->and($r->return_date->toDateString())->toBe('2026-10-12')->and($r->supply_type)->toBe('intra')
        ->and((string) $r->taxable_total)->toBe('502.00')->and((string) $r->cgst_total)->toBe('45.18')->and((string) $r->sgst_total)->toBe('45.18')
        ->and((string) $r->grand_total)->toBe('592.00')->and((string) $r->round_off)->toBe('-0.36')->and((string) $r->cost_total)->toBe('400.00');

    $line = $r->items()->first();
    expect((string) $line->quantity)->toBe('4.000')->and((string) $line->rate)->toBe('125.5000')->and((string) $line->line_total)->toBe('592.36')
        ->and((string) $line->unit_cost)->toBe('100.0000')->and((string) $line->cost_amount)->toBe('400.00')->and($line->sales_invoice_item_id)->toBe($this->boltLine->id);
});

it('reproduces the invoice line exactly when a whole line is returned', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '10', $this->wireLine->id => '2.5']);

    expect((string) $r->taxable_total)->toBe((string) $this->invoice->taxable_total)->and((string) $r->cgst_total)->toBe((string) $this->invoice->cgst_total)
        ->and((string) $r->grand_total)->toBe((string) $this->invoice->grand_total)->and((string) $r->cost_total)->toBe('1150.00');

    foreach ($r->items as $line) {
        expect((string) $line->line_total)->toBe((string) $line->invoiceLine->line_total);
    }
});

it('makes a credit note the same way, and takes the next number', function () {
    $a = ($this->makeReturn)([$this->boltLine->id => '1']);
    $b = ($this->makeReturn)([$this->boltLine->id => '1'], 'credit_note');

    expect($b->return_type)->toBe('credit_note')->and($b->typeLabel())->toBe('Credit note')->and($b->return_number)->toBe('SR/AHD/26-27/00002')->and($a->typeLabel())->toBe('Sales return');
});

it('refuses a return that is not sensible', function () {
    $try = fn (array $lines, array $over = []) => fn () => ($this->makeReturn)($lines, 'return', $over);

    expect($try([$this->boltLine->id => '11']))->toThrow(ValidationException::class)                       // more than was invoiced
        ->and($try([$this->boltLine->id => '0']))->toThrow(ValidationException::class)                      // nothing
        ->and($try([]))->toThrow(ValidationException::class)
        ->and($try([$this->boltLine->id => '1.2345']))->toThrow(ValidationException::class)                 // too many decimals
        ->and($try([$this->boltLine->id => '2.5']))->toThrow(ValidationException::class)                    // Nos cannot be split
        ->and($try([99999 => '1']))->toThrow(ValidationException::class)                                    // not a line of this invoice
        ->and($try([$this->boltLine->id => '1'], ['reason' => 'x']))->toThrow(ValidationException::class)
        ->and($try([$this->boltLine->id => '1'], ['return_type' => 'refund']))->toThrow(ValidationException::class)
        ->and($try([$this->boltLine->id => '1'], ['return_date' => '2026-10-13']))->toThrow(ValidationException::class)   // tomorrow
        ->and($try([$this->boltLine->id => '1'], ['return_date' => '2026-10-11']))->toThrow(ValidationException::class)   // before the invoice
        ->and(SalesReturn::query()->count())->toBe(0);
});

it('lets only approved or waiting returns reserve quantity, and checks again when one is sent for approval', function () {
    $first = ($this->makeReturn)([$this->boltLine->id => '6']);
    $second = ($this->makeReturn)([$this->boltLine->id => '6']);      // two drafts of 6 each fit: a draft reserves nothing

    expect(app(SalesReturnService::class)->returnable($this->invoice)[$this->boltLine->id])->toBe('10.000');

    ($this->submit)($first);                                            // 6 are now reserved
    expect(($this->do)(fn () => app(SalesReturnService::class)->returnable($this->invoice)[$this->boltLine->id]))->toBe('4.000');

    expect(fn () => ($this->submit)($second))->toThrow(ApprovalStateException::class, 'more than the invoice still allows')
        ->and($second->fresh()->approval_status->value)->toBe('draft');
    expect(fn () => ($this->makeReturn)([$this->boltLine->id => '5']))->toThrow(ValidationException::class);   // a new one is checked at once
    expect(($this->makeReturn)([$this->boltLine->id => '4']))->toBeInstanceOf(SalesReturn::class);

    // a rejected return gives its quantity back
    ($this->do)(fn () => app(ApprovalService::class)->reject($first->fresh(), $this->approver, 'Not agreed with the customer'));
    expect(($this->do)(fn () => app(SalesReturnService::class)->returnable($this->invoice)[$this->boltLine->id]))->toBe('10.000');
});

it('locks a return that is waiting or approved, and never deletes one', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '2']);
    ($this->submit)($r);

    expect(fn () => ($this->do)(fn () => app(SalesReturnService::class)->update($r->fresh(), ['return_type' => 'return', 'reason' => 'Changed my mind', 'lines' => [$this->boltLine->id => '1']], $this->accounts)))
        ->toThrow(FieldRuleException::class)
        ->and(fn () => SalesReturn::query()->find($r->id)->forceFill(['reason' => 'edited'])->save())->toThrow(LogicException::class)
        ->and(fn () => SalesReturnItem::query()->where('sales_return_id', $r->id)->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => SalesReturn::query()->find($r->id)->delete())->toThrow(LogicException::class);
});

it('changes a draft: its lines are replaced and its figures worked out again', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '4']);
    $changed = ($this->do)(fn () => app(SalesReturnService::class)->update($r, ['return_type' => 'credit_note', 'reason' => 'Wrong price', 'lines' => [$this->wireLine->id => '1']], $this->accounts));

    expect($changed->return_type)->toBe('credit_note')->and($changed->items()->count())->toBe(1)->and((string) $changed->taxable_total)->toBe('80.00')->and((string) $changed->cost_total)->toBe('60.00')
        ->and($changed->return_number)->toBe($r->return_number);

    // and again, keeping the same invoice line: the replaced lines must not hold on to their keys
    $again = ($this->do)(fn () => app(SalesReturnService::class)->update($changed, ['return_type' => 'credit_note', 'reason' => 'Wrong price', 'lines' => [$this->wireLine->id => '2']], $this->accounts));
    expect($again->items()->count())->toBe(1)->and((string) $again->taxable_total)->toBe('160.00')
        ->and(SalesReturnItem::withTrashed()->where('sales_return_id', $r->id)->count())->toBe(1);
});

it('makes the maker wait for someone else to approve, and counts it only once approved', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '4']);
    ($this->submit)($r);

    expect(fn () => ($this->do)(fn () => app(ApprovalService::class)->approve($r->fresh(), $this->accounts)))->toThrow(Exception::class);   // not the maker

    ($this->do)(fn () => app(ApprovalService::class)->approve($r->fresh(), $this->approver));
    expect(SalesReturn::query()->find($r->id)->approval_status->value)->toBe('approved');
});

it('refuses a return against a cancelled invoice, and an order cancel while a return stands', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '2']);
    ($this->submit)($r);

    expect(fn () => ($this->do)(fn () => app(SalesOrderService::class)->cancel($this->order->fresh(), 'Customer withdrew', $this->manager)))
        ->toThrow(FieldRuleException::class, 'cannot be cancelled');

    // once the only return is rejected the order may be cancelled — and then nothing more can be returned
    ($this->do)(fn () => app(ApprovalService::class)->reject($r->fresh(), $this->approver, 'Not valid'));
    ($this->do)(fn () => app(SalesOrderService::class)->cancel($this->order->fresh(), 'Customer withdrew', $this->manager));

    expect($this->invoice->fresh()->status)->toBe(SalesInvoice::CANCELLED)
        ->and(fn () => ($this->makeReturn)([$this->boltLine->id => '1']))->toThrow(FieldRuleException::class, 'cancelled');
});

// ---- net revenue -------------------------------------------------------------------------------------------------------------------

it('works out net revenue, cost, profit and margin from invoices less approved returns', function () {
    $calc = fn () => ($this->do)(fn () => app(NetRevenueCalculator::class)->forPeriod(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));

    $before = $calc();
    expect($before)->toMatchArray(['gross_sales' => '1455.00', 'discounts' => '0.00', 'returns' => '0.00', 'net_revenue' => '1455.00', 'cogs' => '1150.00', 'gross_profit' => '305.00',
        'margin_pct' => '20.96', 'lines_without_cost' => 0, 'cost_complete' => true]);

    $r = ($this->makeReturn)([$this->boltLine->id => '4']);
    expect($calc()['returns'])->toBe('0.00');                       // a draft counts for nothing
    ($this->submit)($r);
    expect($calc()['returns'])->toBe('0.00');                       // nor does one waiting for approval

    ($this->do)(fn () => app(ApprovalService::class)->approve($r->fresh(), $this->approver));
    expect($calc())->toMatchArray(['returns' => '502.00', 'net_revenue' => '953.00', 'cogs' => '750.00', 'gross_profit' => '203.00', 'margin_pct' => '21.30']);
});

it('says nothing about margin when nothing is left, keeps the periods apart and counts lines whose cost is unknown', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '10', $this->wireLine->id => '2.5']);
    ($this->approve)($r);

    $inPeriod = fn () => ($this->do)(fn () => app(NetRevenueCalculator::class)->forPeriod(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));
    $other = ($this->do)(fn () => app(NetRevenueCalculator::class)->forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')));

    expect($inPeriod())->toMatchArray(['net_revenue' => '0.00', 'gross_profit' => '0.00', 'margin_pct' => null, 'cogs' => '0.00'])
        ->and($other)->toMatchArray(['gross_sales' => '0.00', 'net_revenue' => '0.00', 'margin_pct' => null]);

    DB::table('sales_invoice_items')->where('id', $this->wireLine->id)->update(['cost_amount' => null, 'unit_cost' => null]);
    expect($inPeriod())->toMatchArray(['lines_without_cost' => 1, 'cost_complete' => false]);
});

it('keeps another company’s invoices out of the figures', function () {
    $theirs = inCompany($this->globex, fn () => app(NetRevenueCalculator::class)->forPeriod(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));

    expect($theirs['gross_sales'])->toBe('0.00');
});

// ---- the screens ------------------------------------------------------------------------------------------------------------------

it('offers the return from the invoice to those who may make one', function () {
    Livewire::actingAs($this->accounts)->test(SalesInvoiceShow::class, ['invoice' => $this->invoice])->assertOk()->assertSee('Return or credit');
    Livewire::actingAs($this->operations)->test(SalesInvoiceShow::class, ['invoice' => $this->invoice])->assertOk()->assertDontSee('Return or credit');
});

it('shows a live preview and saves a draft from the form', function () {
    Livewire::actingAs($this->accounts)->test(SalesReturnForm::class, ['invoice' => $this->invoice->id])
        ->assertOk()->assertSee('MS Hex Bolt M10')
        ->set('quantities.'.$this->boltLine->id, '4')->assertSee('592.00')
        ->set('quantities.'.$this->boltLine->id, '11')->assertSee('Only 10 of this line can still be returned')
        ->set('quantities.'.$this->boltLine->id, '4')->set('reason', 'Damaged in transit')->call('save')->assertHasNoErrors()->assertRedirect();

    expect(SalesReturn::withoutGlobalScopes()->count())->toBe(1);
});

it('closes the form to those who may not make a return, and to invoices they cannot see', function () {
    Livewire::actingAs($this->operations)->test(SalesReturnForm::class, ['invoice' => $this->invoice->id])->assertForbidden();
    Livewire::actingAs($this->bde2)->test(SalesReturnForm::class, ['invoice' => $this->invoice->id])->assertForbidden();
    Livewire::actingAs($this->bde)->test(SalesReturnForm::class, ['invoice' => $this->invoice->id])->assertForbidden();   // sales may view returns, not make them
});

it('lists returns to the people who may see them and shows a return with its approval panel', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '4']);

    Livewire::actingAs($this->accounts)->test(SalesReturnList::class)->assertOk()->assertSee($r->return_number);
    Livewire::actingAs($this->operations)->test(SalesReturnList::class)->assertOk()->assertSee($r->return_number);
    Livewire::actingAs($this->bde)->test(SalesReturnList::class)->assertOk()->assertSee($r->return_number);       // their own customer’s invoice
    Livewire::actingAs($this->bde2)->test(SalesReturnList::class)->assertOk()->assertDontSee($r->return_number);   // someone else’s
    Livewire::actingAs($this->bde2)->test(SalesReturnShow::class, ['salesReturn' => $r])->assertForbidden();

    Livewire::actingAs($this->accounts)->test(SalesReturnShow::class, ['salesReturn' => $r])->assertOk()->assertSee('592.00')->assertSee('Damaged in transit')->assertSee('400.00');
});

it('opens the pages over HTTP and puts the list in the menu', function () {
    $r = ($this->makeReturn)([$this->boltLine->id => '4']);

    $this->actingAs($this->accounts)->get('/sales/returns')->assertOk()->assertSee('Returns &amp; credit notes', false);
    $this->actingAs($this->accounts)->get('/sales/returns/'.$r->id)->assertOk();
    $this->actingAs($this->accounts)->get('/sales/returns/make/'.$this->invoice->id)->assertOk();
});
