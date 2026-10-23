<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Procurement\Http\Livewire\GrnForm;
use App\Modules\Procurement\Http\Livewire\GrnShow;
use App\Modules\Procurement\Http\Livewire\PurchaseOrderForm;
use App\Modules\Procurement\Http\Livewire\PurchaseOrderList;
use App\Modules\Procurement\Http\Livewire\PurchaseOrderShow;
use App\Modules\Procurement\Http\Livewire\ReorderList;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Procurement\Services\GrnPoster;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\ReorderSuggestions;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Purchase orders and goods receipts: who may do what, the PO tolerance (FORMULAS.md `grn.max_qty`), stock posted once when a receipt is approved,
 * batches with their expiry, cancelling, the retry, and reorder suggestions. Hand-calculated: 2 Boxes (200 Nos) at ₹450 a box is ₹900 and ₹4.50 a Nos;
 * with a 5% tolerance 210 Nos may be received.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->store = makeUser(['Inventory User'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme]);

    $this->supplier = makeLedger($this->acme, $this->purchase, 'supplier', ['ledger' => ['name' => 'Steel Traders', 'lead_time_days' => '7']], true, $this->accounts);
    $this->customer = makeLedger($this->acme, $this->accounts, 'customer', [], true);
    $this->bolt = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'MS Hex Bolt M10', 'standard_cost' => '4']], 'approved');   // 1 Box = 100 Nos, tolerance 5%
    $this->drug = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Paracetamol 500'], 'uoms' => [], 'inventory' => ['tracking' => 'batch', 'has_expiry' => true]], 'approved');

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->line = fn ($item, string $qty, string $rate, string $unit = 'NOS') => ['item_id' => (string) $item->id, 'unit_id' => (string) unitId($this->acme, $unit), 'quantity' => $qty, 'rate' => $rate];
    $this->makePo = fn (array $items, array $over = [], $by = null) => ($this->do)(fn () => app(PurchaseOrderService::class)->create(
        ['location_id' => (string) $this->ahd->id, 'supplier_ledger_id' => (string) $this->supplier->id, 'items' => $items, ...$over], $by ?? $this->purchase,
    ));
    $this->approvedPo = fn (array $items) => approveDocument($this->acme, ($this->makePo)($items), $this->purchase, $this->accounts);
    $this->makeGrn = fn (PurchaseOrder $po, array $lines, array $over = []) => ($this->do)(fn () => app(GrnService::class)->create($po->fresh(), ['lines' => $lines, ...$over], $this->store));
    $this->submit = fn ($doc, $by) => ($this->do)(fn () => app(ApprovalService::class)->submit($doc->fresh(), $by));
    $this->approveGrn = fn (Grn $grn) => approveDocument($this->acme, $grn, $this->store, $this->accounts);
    $this->lineOf = fn (PurchaseOrder $po, int $n = 1) => PurchaseOrderItem::withoutGlobalScopes(Tenancy::SCOPES)->where('purchase_order_id', $po->id)->where('line_no', $n)->firstOrFail();
    $this->onHand = fn ($item, $location = null) => (string) DB::table('stock_balances')->where('item_id', $item->id)->where('location_id', ($location ?? $this->ahd)->id)->sum('quantity');
});

afterEach(fn () => Carbon::setTestNow());

// ---- purchase orders ---------------------------------------------------------------------------------------------------------------

it('makes a numbered purchase order in base units, with the item’s tolerance and the supplier’s lead time', function () {
    $po = ($this->makePo)([($this->line)($this->bolt, '2', '450', 'BOX')]);

    expect($po->po_number)->toBe('PO/AHD/26-27/00001')->and($po->approval_status->value)->toBe('draft')->and($po->state)->toBe('open')
        ->and($po->supplier_name)->toBe('Steel Traders')->and((string) $po->total_amount)->toBe('900.00')
        ->and($po->po_date->toDateString())->toBe('2026-10-12')->and($po->expected_date->toDateString())->toBe('2026-10-19');   // 7 days' lead time

    $line = ($this->lineOf)($po);
    expect((string) $line->quantity)->toBe('2.000')->and((string) $line->base_quantity)->toBe('200.000')->and((string) $line->rate)->toBe('450.0000')
        ->and((string) $line->amount)->toBe('900.00')->and((string) $line->tolerance_percent)->toBe('5.00');
});

it('refuses an order that is not sensible', function () {
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Not approved yet']]);
    $try = fn (array $items, array $over = []) => fn () => ($this->makePo)($items, $over);
    $ok = [($this->line)($this->bolt, '10', '4.5')];

    expect($try($ok, ['supplier_ledger_id' => (string) $this->customer->id]))->toThrow(ValidationException::class)   // a customer is not a supplier
        ->and($try([($this->line)($draft, '1', '1')]))->toThrow(ValidationException::class)                          // an unapproved item
        ->and($try([($this->line)($this->bolt, '1', '1', 'KG')]))->toThrow(ValidationException::class)               // a unit the item does not have
        ->and($try([($this->line)($this->bolt, '1.5', '1')]))->toThrow(ValidationException::class)                   // Nos cannot be split
        ->and($try([($this->line)($this->bolt, '1', '-1')]))->toThrow(ValidationException::class)
        ->and($try([]))->toThrow(ValidationException::class)
        ->and($try($ok, ['po_date' => '2026-10-13']))->toThrow(ValidationException::class)                          // tomorrow
        ->and($try($ok, ['expected_date' => '2026-10-01']))->toThrow(ValidationException::class)                    // before the order
        ->and($try($ok, ['location_id' => '999999']))->toThrow(ValidationException::class)
        ->and(PurchaseOrder::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('goes through approval with maker–checker, and locks once it is waiting', function () {
    $po = ($this->makePo)([($this->line)($this->bolt, '10', '4.5')]);
    ($this->submit)($po, $this->purchase);

    expect(fn () => ($this->do)(fn () => app(ApprovalService::class)->approve($po->fresh(), $this->purchase)))->toThrow(Exception::class)   // not the maker (and Purchase cannot approve)
        ->and(fn () => ($this->do)(fn () => app(PurchaseOrderService::class)->update($po->fresh(), ['location_id' => (string) $this->ahd->id, 'supplier_ledger_id' => (string) $this->supplier->id, 'items' => [($this->line)($this->bolt, '1', '1')]], $this->purchase)))
        ->toThrow(FieldRuleException::class)
        ->and(fn () => PurchaseOrder::withoutGlobalScopes(Tenancy::SCOPES)->find($po->id)->forceFill(['total_amount' => '1'])->save())->toThrow(LogicException::class)
        ->and(fn () => ($this->lineOf)($po)->delete())->toThrow(LogicException::class);

    ($this->do)(fn () => app(ApprovalService::class)->approve($po->fresh(), $this->accounts));
    expect($po->fresh()->isReceivable())->toBeTrue();
});

it('changes a draft: lines replaced, total worked out again, same number', function () {
    $po = ($this->makePo)([($this->line)($this->bolt, '10', '4.5')]);
    $changed = ($this->do)(fn () => app(PurchaseOrderService::class)->update($po, ['location_id' => (string) $this->mum->id, 'supplier_ledger_id' => (string) $this->supplier->id, 'items' => [($this->line)($this->bolt, '1', '450', 'BOX'), ($this->line)($this->bolt, '20', '4')]], $this->purchase));

    expect($changed->po_number)->toBe($po->po_number)->and($changed->items()->count())->toBe(2)->and((string) $changed->total_amount)->toBe('530.00')->and($changed->location_id)->toBe($this->mum->id);

    // a receipt changed while keeping the same order line: the replaced lines must not hold on to their keys
    $approved = ($this->approvedPo)([($this->line)($this->bolt, '100', '4.5')]);
    $grn = ($this->makeGrn)($approved, [($this->lineOf)($approved)->id => ['accepted' => '10']]);
    $grn = ($this->do)(fn () => app(GrnService::class)->update($grn, ['lines' => [($this->lineOf)($approved)->id => ['accepted' => '20']]], $this->store));

    expect((string) $grn->items()->sole()->accepted_quantity)->toBe('20.000');
});

// ---- receiving and the tolerance -------------------------------------------------------------------------------------------------

it('receives only against an approved, open order', function () {
    $draft = ($this->makePo)([($this->line)($this->bolt, '100', '4.5')]);
    $line = ($this->lineOf)($draft);

    expect(fn () => ($this->makeGrn)($draft, [$line->id => ['accepted' => '10']]))->toThrow(FieldRuleException::class, 'not been approved');
});

it('allows the ordered quantity plus the tolerance, rejected goods included, and no more', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '100', '4.5')]);   // 105 may arrive
    $line = ($this->lineOf)($po);

    expect(fn () => ($this->makeGrn)($po, [$line->id => ['accepted' => '106']]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->makeGrn)($po, [$line->id => ['accepted' => '100', 'rejected' => '6', 'reject_reason' => 'Rusted']]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->makeGrn)($po, [$line->id => ['accepted' => '90', 'rejected' => '5']]))->toThrow(ValidationException::class);   // a rejection needs a reason

    $grn = ($this->makeGrn)($po, [$line->id => ['accepted' => '100', 'rejected' => '5', 'reject_reason' => 'Rusted threads']], ['supplier_invoice_no' => 'ST/889']);
    $gl = $grn->items()->first();

    expect($grn->grn_number)->toBe('GRN/AHD/26-27/00001')->and($grn->supplier_invoice_no)->toBe('ST/889')->and((string) $gl->base_accepted)->toBe('100.000')
        ->and((string) $gl->base_received)->toBe('105.000')->and((string) $gl->unit_cost)->toBe('4.5000')->and($gl->reject_reason)->toBe('Rusted threads');
});

it('lets only waiting or approved receipts take quantity, and checks again when one is sent for approval', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '100', '4.5')]);
    $line = ($this->lineOf)($po);

    $first = ($this->makeGrn)($po, [$line->id => ['accepted' => '60']]);
    $second = ($this->makeGrn)($po, [$line->id => ['accepted' => '60']]);   // two drafts of 60 fit: a draft reserves nothing

    ($this->submit)($first, $this->store);
    expect(fn () => ($this->submit)($second, $this->store))->toThrow(ApprovalStateException::class, 'more than the order allows')
        ->and(fn () => ($this->makeGrn)($po, [$line->id => ['accepted' => '46']]))->toThrow(ValidationException::class);   // 105 − 60 = 45 left

    ($this->do)(fn () => app(ApprovalService::class)->reject($first->fresh(), $this->accounts, 'Wrong supplier challan'));
    expect(($this->do)(fn () => app(PurchaseOrderService::class)->received($po->fresh()))[$line->id])->toBe('0.000');
});

// ---- posting to stock --------------------------------------------------------------------------------------------------------------

it('puts the accepted goods into stock when the receipt is approved — once, at the order’s rate per base unit', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '2', '450', 'BOX')]);
    $line = ($this->lineOf)($po);
    $grn = ($this->makeGrn)($po, [$line->id => ['accepted' => '2', 'rejected' => '0']]);

    expect(($this->onHand)($this->bolt))->toBe('0');   // nothing until it is approved

    $grn = ($this->approveGrn)($grn);
    expect($grn->posted_at)->not->toBeNull()->and($grn->post_error)->toBeNull()->and(($this->onHand)($this->bolt))->toBe('200.000');

    $m = StockMovement::withoutGlobalScopes(Tenancy::SCOPES)->where('item_id', $this->bolt->id)->sole();
    expect($m->movement_type)->toBe('receipt')->and((string) $m->quantity)->toBe('200.000')->and((string) $m->unit_cost)->toBe('4.5000')->and((string) $m->value)->toBe('900.00')
        ->and($m->source_type)->toBe('grn')->and($m->source_id)->toBe($grn->id);

    // asking again, from anywhere, changes nothing
    expect(app(GrnPoster::class)->post($grn))->toBeFalse();
    $this->artisan('procurement:post-grns')->assertSuccessful();
    expect(StockMovement::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1)->and(($this->onHand)($this->bolt))->toBe('200.000');
});

it('makes the batch with its dates, and refuses batches that are missing, out of date or contradict an existing one', function () {
    $po = ($this->approvedPo)([($this->line)($this->drug, '500', '1.2')]);
    $line = ($this->lineOf)($po);
    $try = fn (array $row) => fn () => ($this->makeGrn)($po, [$line->id => ['accepted' => '100', ...$row]]);

    expect($try([]))->toThrow(ValidationException::class)                                                          // no batch
        ->and($try(['batch_no' => 'P-1']))->toThrow(ValidationException::class)                                    // an expiring item needs the expiry
        ->and($try(['batch_no' => 'P-1', 'expiry_date' => '2026-10-01']))->toThrow(ValidationException::class)     // already expired: reject it instead
        ->and($try(['batch_no' => 'P-1', 'mfg_date' => '2027-01-01', 'expiry_date' => '2026-12-01']))->toThrow(ValidationException::class);

    $grn = ($this->makeGrn)($po, [$line->id => ['accepted' => '100', 'batch_no' => 'P-1', 'lot_no' => 'L7', 'mfg_date' => '2026-09-01', 'expiry_date' => '2027-08-31']]);
    ($this->approveGrn)($grn);

    $batch = Batch::withoutGlobalScopes(Tenancy::SCOPES)->where('batch_no', 'P-1')->sole();
    expect($batch->expiry_date->toDateString())->toBe('2027-08-31')->and($batch->lot_no)->toBe('L7')->and($batch->status)->toBe('active')
        ->and((string) DB::table('stock_balances')->where('batch_id', $batch->id)->value('quantity'))->toBe('100.000');

    // the same batch number again must carry the same expiry
    expect($try(['batch_no' => 'P-1', 'expiry_date' => '2027-09-30']))->toThrow(ValidationException::class);
});

it('records a failed posting on the receipt and the retry puts it right', function () {
    $po = ($this->approvedPo)([($this->line)($this->drug, '100', '1.2')]);
    $line = ($this->lineOf)($po);
    $grn = ($this->makeGrn)($po, [$line->id => ['accepted' => '10', 'batch_no' => 'X-1', 'expiry_date' => '2027-01-31']]);

    // somebody makes batch X-1 with another expiry between the receipt being saved and approved: the posting must stop, whole
    $clash = new Batch;
    $clash->forceFill(['company_id' => $this->acme->id, 'item_id' => $this->drug->id, 'batch_no' => 'X-1', 'expiry_date' => '2027-06-30', 'status' => 'active']);
    inCompany($this->acme, fn () => $clash->save());

    $grn = ($this->approveGrn)($grn);   // the approval stands
    expect($grn->approval_status->value)->toBe('approved')->and($grn->posted_at)->toBeNull()->and($grn->post_error)->toContain('different expiry')
        ->and(StockMovement::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);

    $this->artisan('procurement:post-grns')->assertFailed();

    DB::table('batches')->where('id', $clash->id)->update(['expiry_date' => '2027-01-31']);
    $this->artisan('procurement:post-grns')->assertSuccessful();
    expect($grn->fresh()->posted_at)->not->toBeNull()->and($grn->fresh()->post_error)->toBeNull()->and(($this->onHand)($this->drug))->toBe('10.000');
});

// ---- cancelling ---------------------------------------------------------------------------------------------------------------------

it('cancels an order only while nothing stands against it, and then nothing can be received', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '100', '4.5')]);
    $line = ($this->lineOf)($po);
    $grn = ($this->makeGrn)($po, [$line->id => ['accepted' => '10']]);
    ($this->submit)($grn, $this->store);

    $cancel = fn () => ($this->do)(fn () => app(PurchaseOrderService::class)->cancel($po->fresh(), 'Supplier cannot deliver', $this->purchase));
    expect($cancel)->toThrow(FieldRuleException::class, 'received');

    ($this->do)(fn () => app(ApprovalService::class)->reject($grn->fresh(), $this->accounts, 'Wrong quantity'));
    $cancel();

    expect($po->fresh()->state)->toBe('cancelled')->and($po->fresh()->cancel_reason)->toBe('Supplier cannot deliver')
        ->and(fn () => ($this->makeGrn)($po, [$line->id => ['accepted' => '1']]))->toThrow(FieldRuleException::class, 'cancelled');
});

// ---- reorder suggestions -------------------------------------------------------------------------------------------------------------

it('suggests what to reorder, counting what is still due, with the last supplier and its lead time', function () {
    DB::table('item_location_parameters')->insert(['company_id' => $this->acme->id, 'item_id' => $this->bolt->id, 'location_id' => $this->ahd->id,
        'minimum_stock' => 20, 'reorder_level' => 50, 'maximum_stock' => 200, 'created_at' => now(), 'updated_at' => now()]);
    $suggest = fn () => app(ReorderSuggestions::class)->forCompany($this->acme->id);

    expect($suggest())->toHaveCount(1)->and($suggest()[0])->toMatchArray(['on_hand' => '0.000', 'on_order' => '0.000', 'suggested' => '200.000', 'supplier_name' => null]);

    $po = ($this->approvedPo)([($this->line)($this->bolt, '100', '4.5')]);
    expect($suggest())->toBe([]);   // 100 due is above the reorder level

    ($this->approveGrn)(($this->makeGrn)($po, [($this->lineOf)($po)->id => ['accepted' => '100']]));
    ($this->do)(fn () => app(StockService::class)->scrap($this->bolt->fresh(), $this->ahd->id, '70', null, 'Damaged in the flood', $this->store));

    expect($suggest()[0])->toMatchArray(['on_hand' => '30.000', 'on_order' => '0.000', 'suggested' => '170.000', 'supplier_name' => 'Steel Traders', 'lead_time_days' => 7, 'arrives_by' => '2026-10-19']);
});

// ---- the screens ----------------------------------------------------------------------------------------------------------------------

it('makes a purchase order from the form', function () {
    Livewire::actingAs($this->purchase)->test(PurchaseOrderForm::class)
        ->assertOk()
        ->call('pickSupplier', $this->supplier->id)->assertSet('supplierId', (string) $this->supplier->id)
        ->set('locationId', (string) $this->ahd->id)
        ->call('pickItem', 0, $this->bolt->id)->set('rows.0.quantity', '20')->set('rows.0.rate', '4.5')->assertSee('90.00')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    expect(PurchaseOrder::withoutGlobalScopes(Tenancy::SCOPES)->sole()->total_amount)->toEqual('90.00');
});

it('shows an order with what is received and allowed, and offers to receive only once it is approved', function () {
    $po = ($this->makePo)([($this->line)($this->bolt, '100', '4.5')]);

    Livewire::actingAs($this->store)->test(PurchaseOrderShow::class, ['purchaseOrder' => $po])->assertOk()->assertDontSee('Receive goods');

    $po = approveDocument($this->acme, $po, $this->purchase, $this->accounts);
    Livewire::actingAs($this->store)->test(PurchaseOrderShow::class, ['purchaseOrder' => $po])->assertOk()->assertSee('Receive goods')->assertSee('105');
    Livewire::actingAs($this->operations)->test(PurchaseOrderShow::class, ['purchaseOrder' => $po])->assertOk()->assertDontSee('Receive goods')->assertDontSee('Cancel the order');
});

it('receives goods from the form and shows the receipt', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '100', '4.5')]);
    $line = ($this->lineOf)($po);

    Livewire::actingAs($this->store)->test(GrnForm::class, ['order' => $po->id])
        ->assertOk()->assertSee('MS Hex Bolt M10')
        ->set("lines.{$line->id}.accepted", '200')->call('save')->assertHasErrors(["lines.{$line->id}"])
        ->set("lines.{$line->id}.accepted", '100')->call('save')->assertHasNoErrors()->assertRedirect();

    $grn = Grn::withoutGlobalScopes(Tenancy::SCOPES)->sole();
    Livewire::actingAs($this->accounts)->test(GrnShow::class, ['grn' => $grn])->assertOk()->assertSee($grn->grn_number)->assertSee('100');
});

it('keeps procurement to the people it belongs to', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '100', '4.5')]);

    Livewire::actingAs($this->operations)->test(PurchaseOrderList::class)->assertOk()->assertSee($po->po_number);
    Livewire::actingAs($this->operations)->test(PurchaseOrderForm::class)->assertForbidden();
    Livewire::actingAs($this->bde)->test(PurchaseOrderList::class)->assertForbidden();
    Livewire::actingAs($this->purchase)->test(GrnForm::class, ['order' => $po->id])->assertForbidden();   // Purchase orders; Inventory receives
    Livewire::actingAs($this->store)->test(ReorderList::class)->assertOk();
});

it('opens the pages over HTTP and puts them in the menu', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '100', '4.5')]);

    foreach (['/procurement/orders', '/procurement/orders/create', '/procurement/orders/'.$po->id, '/procurement/receipts', '/procurement/reorder', '/inventory/stock', '/inventory/movements', '/inventory/batches'] as $url) {
        $this->actingAs($this->purchase)->get($url)->assertOk();
    }

    $this->actingAs($this->store)->get('/inventory/entry')->assertOk()->assertSee('Stock entry');
    $this->actingAs($this->store)->get('/procurement/receipts/receive/'.$po->id)->assertOk();
    $this->actingAs($this->bde)->get('/inventory/stock')->assertForbidden();
});
