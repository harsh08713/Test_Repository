<?php

use App\Modules\CRM\Contracts\AchievementSource;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Services\TargetAchievement;
use App\Modules\CRM\Services\TargetService;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesOrderStateEntry;
use App\Modules\Sales\Services\InvoiceGenerator;
use App\Modules\Sales\Services\SalesOrderAchievement;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Shared\Events\DocumentApproved;
use App\Modules\Shared\Models\NumberSeries;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/*
 * The invoice made when a sales order is approved: exactly one, however many times it is asked for; a copy of the order as it was approved;
 * never changed; retried when it could not be made. And what approved orders count for towards a salesperson's targets.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24', 'gstin' => gstinFor('24', 'ABCDE1234F')]);
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);
    $this->mum = makeLocation($this->acme, 'MUM', ['state_code' => '27']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $this->bolt = makeUsableItem($this->acme);
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG'), 'gst_rate' => '12']);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');
    makeStandardRate($this->acme, $this->wire, $this->ahd, '80');

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->deal = function (?array $lines = null, $by = null, ?Ledger $customer = null) {
        $by ??= $this->bde;
        $deal = makeDeal($this->acme, $by, makeLead($this->acme, $by, $this->ahd, [], $lines ?? [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]), ['customer_ledger_id' => (string) ($customer ?? $this->customer)->id]);

        return approveDocument($this->acme, $deal, $by, $this->approver);
    };
    // an order from an approved deal, taken as far as asked
    $this->order = fn (string $upTo = 'draft', ?Deal $deal = null, array $o = [], $by = null) => makeSalesOrder($this->acme, $by ?? $this->bde, $deal ?? ($this->deal)(null, $by), $this->customer, $o, null, $upTo, $this->approver);
    $this->lastInvoiceNumber = fn () => (int) DB::table('number_series')->where('company_id', $this->acme->id)->where('document_type', 'sales_invoice')->value('last_number');
});

afterEach(fn () => Carbon::setTestNow());

// ---- one invoice per approved order ----------------------------------------------------------------------------------------------

it('makes the invoice when an order is approved: numbered, linked and a copy of the order', function () {
    $o = ($this->order)('approved');
    $invoice = $o->invoice;

    expect($invoice)->not->toBeNull()->and($invoice->invoice_number)->toBe('SI/AHD/26-27/00001')->and($invoice->sales_order_id)->toBe($o->id)->and($invoice->source_key)->toBe('full')
        ->and($invoice->status)->toBe(SalesInvoice::ISSUED)->and($invoice->invoice_date->toDateString())->toBe('2026-10-12')->and($invoice->due_date->toDateString())->toBe('2026-11-11')   // 30 credit days
        ->and($invoice->customer_ledger_id)->toBe($this->customer->id)->and($invoice->assigned_to)->toBe($o->assigned_to)->and($invoice->created_by)->toBe($this->approver->id)
        ->and($invoice->party_name)->toBe($o->party_name)->and($invoice->bill_line1)->toBe($o->bill_line1)->and($invoice->ship_line1)->toBe($o->ship_line1)
        ->and($invoice->supply_type)->toBe('intra')->and($invoice->supplier_gstin)->toBe($o->supplier_gstin)->and($invoice->terms)->toBe($o->terms)
        ->and((string) $invoice->grand_total)->toBe('1705.00')->and((string) $invoice->taxable_total)->toBe('1455.00')->and((string) $invoice->cgst_total)->toBe((string) $o->cgst_total)
        ->and((string) $invoice->round_off)->toBe((string) $o->round_off);

    $lines = $invoice->items()->get();
    expect($lines)->toHaveCount(2)->and($lines[0]->sales_order_item_id)->toBe($o->items()->first()->id)->and((string) $lines[0]->line_total)->toBe('1480.90')->and($lines[0]->hsn_code)->toBe('7318')
        ->and((string) $lines[0]->quantity)->toBe('10.000')->and((string) $lines[0]->rate)->toBe('125.5000');

    $o = $o->fresh();
    expect($o->state)->toBe(SalesOrderState::Invoiced)->and($o->invoiced_at)->not->toBeNull()->and($o->invoice_error)->toBeNull()
        ->and($o->items()->get()->every(fn (SalesOrderItem $l) => (string) $l->invoiced_quantity === (string) $l->quantity))->toBeTrue();

    $entry = SalesOrderStateEntry::query()->where('sales_order_id', $o->id)->latest('id')->first();
    expect($entry->to_state)->toBe(SalesOrderState::Invoiced)->and($entry->comment)->toContain('SI/AHD/26-27/00001')->and($entry->actor_id)->toBeNull();
});

it('makes no invoice for an order that is merely sent, rejected or a draft', function () {
    $draft = ($this->order)();
    $pending = ($this->order)('pending');
    ($this->do)(fn () => app(ApprovalService::class)->reject($pending->fresh(), $this->approver, 'Check the rate'));

    expect(SalesInvoice::withoutGlobalScopes()->count())->toBe(0)->and(($this->do)(fn () => app(InvoiceGenerator::class)->generateFor($draft)))->toBeNull()
        ->and(($this->do)(fn () => app(InvoiceGenerator::class)->generateFor($pending->fresh())))->toBeNull()->and(($this->lastInvoiceNumber)())->toBe(0);
});

it('makes exactly one invoice however many times the approval is replayed or the generator is asked', function () {
    $o = ($this->order)('approved');
    $first = $o->invoice;
    $document = $o->fresh();

    // the approval event, delivered again and again (a retried queue, a replayed event)
    foreach (range(1, 3) as $n) {
        ($this->do)(fn () => DocumentApproved::dispatch($document, $this->approver, 'again'));
    }

    // and the generator, asked directly by several callers
    $again = collect(range(1, 4))->map(fn () => ($this->do)(fn () => app(InvoiceGenerator::class)->generateFor($o->id)));

    expect(SalesInvoice::withoutGlobalScopes()->where('sales_order_id', $o->id)->count())->toBe(1)
        ->and($again->pluck('id')->unique()->all())->toBe([$first->id])
        ->and(SalesInvoiceItem::withoutGlobalScopes()->where('sales_invoice_id', $first->id)->count())->toBe(2)
        ->and(($this->lastInvoiceNumber)())->toBe(1)                                       // no number was burned by the repeats
        ->and(SalesOrderStateEntry::query()->where('sales_order_id', $o->id)->where('to_state', 'invoiced')->count())->toBe(1);
});

it('is stopped by the database itself from holding a second full invoice for an order', function () {
    $o = ($this->order)('approved');
    $first = SalesInvoice::withoutGlobalScopes()->where('sales_order_id', $o->id)->first();

    $second = new SalesInvoice;
    $second->forceFill([...array_diff_key($first->getAttributes(), array_flip(['id', 'created_at', 'updated_at'])), 'invoice_number' => 'SI/AHD/26-27/99999']);

    expect(fn () => $second->save())->toThrow(UniqueConstraintViolationException::class);
    expect(SalesInvoice::withoutGlobalScopes()->where('sales_order_id', $o->id)->count())->toBe(1);

    // …while an invoice for another part of the order would be allowed by the table (invoicing in parts, later)
    $part = new SalesInvoice;
    $part->forceFill([...array_diff_key($first->getAttributes(), array_flip(['id', 'created_at', 'updated_at'])), 'invoice_number' => 'SI/AHD/26-27/99998', 'source_key' => 'part:2']);
    $part->save();
    expect(SalesInvoice::withoutGlobalScopes()->where('sales_order_id', $o->id)->count())->toBe(2);
});

it('takes the next invoice number for the next order, in the branch’s own series', function () {
    $a = ($this->order)('approved');
    $b = ($this->order)('approved');

    expect([$a->invoice->invoice_number, $b->invoice->invoice_number])->toBe(['SI/AHD/26-27/00001', 'SI/AHD/26-27/00002'])
        ->and(NumberSeries::withoutGlobalScopes()->where('document_type', 'sales_invoice')->value('last_number'))->toBe(2);
});

// ---- a copy that does not move ------------------------------------------------------------------------------------------------------

it('keeps saying what the order said when the masters change afterwards', function () {
    $o = ($this->order)('approved');
    $invoice = $o->invoice;
    $before = $invoice->items()->get()->map(fn ($l) => [(string) $l->rate, (string) $l->gst_rate, $l->item_name, (string) $l->line_total])->all();

    // the rate, the item's name and GST, the customer's name and address all change
    DB::table('rates')->where('item_id', $this->bolt->id)->update(['rate' => '999']);
    DB::table('items')->where('id', $this->bolt->id)->update(['name' => 'Renamed Bolt', 'gst_rate' => '28']);
    DB::table('ledgers')->where('id', $this->customer->id)->update(['name' => 'Renamed Customer Ltd']);
    DB::table('ledger_addresses')->where('ledger_id', $this->customer->id)->update(['line1' => 'Somewhere else']);

    $fresh = SalesInvoice::query()->find($invoice->id);

    expect($fresh->party_name)->toBe($o->party_name)->and($fresh->bill_line1)->toBe($o->bill_line1)->and((string) $fresh->grand_total)->toBe('1705.00')
        ->and($fresh->items()->get()->map(fn ($l) => [(string) $l->rate, (string) $l->gst_rate, $l->item_name, (string) $l->line_total])->all())->toBe($before);
});

it('never changes an issued invoice, its lines, or deletes it', function () {
    $o = ($this->order)('approved');
    $invoice = SalesInvoice::withoutGlobalScopes()->find($o->invoice->id);

    expect(fn () => $invoice->forceFill(['party_name' => 'Somebody else'])->save())->toThrow(LogicException::class, 'cannot be changed')
        ->and(fn () => $invoice->forceFill(['grand_total' => '1.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $invoice->delete())->toThrow(LogicException::class, 'cancelled');

    $line = SalesInvoiceItem::withoutGlobalScopes()->where('sales_invoice_id', $invoice->id)->first();
    expect(fn () => $line->forceFill(['rate' => '1'])->save())->toThrow(LogicException::class)->and(fn () => $line->forceDelete())->toThrow(LogicException::class);

    // the only thing that moves is its status
    $invoice = SalesInvoice::withoutGlobalScopes()->find($invoice->id);
    $invoice->forceFill(['status' => SalesInvoice::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'Order cancelled'])->save();
    expect($invoice->fresh()->isCancelled())->toBeTrue();
});

// ---- when it cannot be made ---------------------------------------------------------------------------------------------------------

it('leaves an approved order without an invoice, says why, and is retried by the command', function () {
    $fail = true;
    SalesInvoiceItem::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('The disk is full');
        }
    });
    Log::spy();

    $o = ($this->order)('approved');

    $stuck = $o->fresh();
    expect($stuck->approval_status->value)->toBe('approved')->and($stuck->state)->toBe(SalesOrderState::Open)->and($stuck->invoice)->toBeNull()
        ->and($stuck->invoice_error)->toContain('RuntimeException')->and($stuck->invoice_error)->toContain('The disk is full')->and($stuck->invoice_attempted_at)->not->toBeNull()
        ->and(SalesInvoice::withoutGlobalScopes()->count())->toBe(0)
        ->and(($this->lastInvoiceNumber)())->toBe(0);                                      // the failed attempt gave its number back

    $fail = false;
    $this->artisan('sales:generate-invoices')->expectsOutputToContain('Invoices made: 1')->assertSuccessful();

    $done = $o->fresh();
    expect($done->state)->toBe(SalesOrderState::Invoiced)->and($done->invoice->invoice_number)->toBe('SI/AHD/26-27/00001')->and($done->invoice_error)->toBeNull();

    // running it again finds nothing to do
    $this->artisan('sales:generate-invoices')->expectsOutputToContain('Invoices made: 0')->assertSuccessful();
    expect(SalesInvoice::withoutGlobalScopes()->count())->toBe(1);
});

it('lets the salesperson make the invoice of an approved order that has none', function () {
    $fail = true;
    SalesInvoiceItem::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('Try again later');
        }
    });

    $o = ($this->order)('approved');
    $fail = false;

    $made = ($this->do)(fn () => app(InvoiceGenerator::class)->generateFor($o));
    $again = ($this->do)(fn () => app(InvoiceGenerator::class)->generateFor($o));

    expect($made->id)->toBe($again->id)->and($o->fresh()->state)->toBe(SalesOrderState::Invoiced);
});

// ---- what counts towards a target ---------------------------------------------------------------------------------------------------

it('counts approved orders towards the salesperson’s target, net of tax, and nothing else', function () {
    $source = fn () => app(SalesOrderAchievement::class);
    $achieved = fn ($user = null, string $from = '2026-10-01', string $to = '2026-10-31', ?int $group = null, ?int $location = null) => ($this->do)(fn () => $source()->achieved(($user ?? $this->bde)->id, $from, $to, $group, $location));

    expect(app(AchievementSource::class))->toBeInstanceOf(SalesOrderAchievement::class)->and($source()->isLive())->toBeTrue()
        ->and($achieved())->toBe(['value' => '0.00', 'quantity' => '0.000']);

    ($this->order)();                                 // a draft: not counted
    ($this->order)('pending');                        // waiting: not counted
    $approved = ($this->order)('approved');            // 1,455.00 net of GST

    expect($achieved())->toBe(['value' => '1455.00', 'quantity' => '12.500'])               // 10 NOS + 2.5 KG
        ->and($achieved($this->bde2))->toBe(['value' => '0.00', 'quantity' => '0.000'])    // credited to the owner only
        ->and($achieved(null, '2026-11-01', '2026-11-30'))->toBe(['value' => '0.00', 'quantity' => '0.000'])   // on its order date
        ->and($achieved(null, '2026-10-12', '2026-10-12')['value'])->toBe('1455.00');          // both ends inclusive

    // a cancelled order stops counting at once
    ($this->do)(fn () => app(SalesOrderService::class)->cancel($approved->fresh(), 'Customer withdrew', $this->manager));
    expect($achieved())->toBe(['value' => '0.00', 'quantity' => '0.000']);
});

it('filters achievement by the branch and the item group, and counts quantities in base units', function () {
    $o = ($this->order)('approved', ($this->deal)([lineRow($this->bolt, '10', '')]));

    // bolts are in the item group of the fixtures; wire is in the same group here, so use a unit the item is sold in: boxes of 100
    $box = unitId($this->acme, 'BOX');
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '12000', ['unit_id' => (string) $box]);
    $boxed = ($this->deal)([lineRow($this->bolt, '2', '', ['unit_id' => (string) $box])]);
    $order = ($this->do)(fn () => app(SalesOrderService::class)->createFromDeal($boxed, orderInput($this->customer, $boxed), $this->bde));
    approveDocument($this->acme, $order, $this->bde, $this->approver);

    $group = (int) $this->bolt->item_group_id;
    $achieved = fn (?int $g = null, ?int $l = null) => ($this->do)(fn () => app(SalesOrderAchievement::class)->achieved($this->bde->id, '2026-10-01', '2026-10-31', $g, $l));

    // 10 NOS + 2 boxes (200 NOS) = 210 base units
    expect($achieved()['quantity'])->toBe('210.000')->and($achieved($group)['quantity'])->toBe('210.000')->and($achieved($group + 999)['quantity'])->toBe('0.000')
        ->and($achieved(null, $this->ahd->id)['quantity'])->toBe('210.000')->and($achieved(null, $this->mum->id)['quantity'])->toBe('0.000');
    expect($o->fresh()->state)->toBe(SalesOrderState::Invoiced);
});

it('reads a target’s progress from the orders', function () {
    ($this->order)('approved');

    $target = ($this->do)(fn () => app(TargetService::class)->save(null, ['target' => [
        'subject' => 'user', 'user_id' => (string) $this->bde->id, 'period_type' => 'monthly', 'period_start' => '2026-10-01', 'target_value' => '14550',
        'target_quantity' => '', 'item_group_id' => '', 'location_id' => '', 'allow_overlap' => false, 'status' => 'active', 'remarks' => '',
    ]], $this->manager));

    $progress = ($this->do)(fn () => app(TargetAchievement::class)->progress($target));

    expect($progress->live)->toBeTrue()->and($progress->achievedValue)->toBe('1455.00')->and($progress->valuePercent)->toBe('10.0');
});
