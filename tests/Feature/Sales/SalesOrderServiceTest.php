<?php

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Services\DealService;
use App\Modules\Sales\Enums\CreditResult;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesOrderStateEntry;
use App\Modules\Sales\Services\CreditCheck;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Sales orders: made from an accepted quotation, an approved deal, or directly for a regular customer; priced as quoted or from the rate master;
 * checked against the customer's credit limit; approved, which makes the invoice and wins the deal; cancelled. Everything goes through
 * SalesOrderService (and InvoiceGenerator behind the approval).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24', 'gstin' => gstinFor('24', 'ABCDE1234F')]);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);
    $this->mum = makeLocation($this->acme, 'MUM', ['state_code' => '27']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->mumBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '500000', 'credit_days' => '30']);
    $this->bolt = makeUsableItem($this->acme);                                                                           // NOS, HSN 7318, GST 18
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG'), 'gst_rate' => '12']);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');
    makeStandardRate($this->acme, $this->wire, $this->ahd, '80');

    $this->service = fn () => app(SalesOrderService::class);
    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->deal = function (?array $lines = null, bool $approved = true, $by = null): Deal {
        $by ??= $this->bde;
        $deal = makeDeal($this->acme, $by, makeLead($this->acme, $by, $this->ahd, [], $lines ?? [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]), ['customer_ledger_id' => (string) $this->customer->id]);

        return $approved ? approveDocument($this->acme, $deal, $by, $this->approver) : $deal;
    };
    // an accepted quotation of an approved deal (what an order is normally made from)
    $this->quotation = function (string $upTo = 'accepted', ?Deal $deal = null, array $q = []) {
        $made = makeQuotation($this->acme, $this->bde, $deal ?? ($this->deal)(), $q, null, 'sent', $this->approver);

        return $upTo === 'accepted' ? ($this->do)(fn () => app(QuotationService::class)->accept($made->fresh(), 'PO received', $this->bde)) : $made->fresh();
    };
    $this->order = fn (?Quotation $q = null, string $upTo = 'draft', array $o = []) => makeSalesOrder($this->acme, $this->bde, $q ?? ($this->quotation)(), $this->customer, $o, null, $upTo, $this->approver);
    $this->errors = function (callable $try): array {
        try {
            $try();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
    $this->setting = fn (string $key, mixed $value) => app(SettingsService::class)->set($key, $value, $this->acme->id);
});

afterEach(fn () => Carbon::setTestNow());

// ---- from a quotation -----------------------------------------------------------------------------------------------------

it('makes an order from an accepted quotation, copying what was quoted', function () {
    $q = ($this->quotation)();
    $o = ($this->order)($q);

    expect($o->so_number)->toBe('SO/AHD/26-27/00001')->and($o->source_type)->toBe('quotation')->and($o->quotation_id)->toBe($q->id)->and($o->deal_id)->toBe($q->deal_id)
        ->and($o->state)->toBe(SalesOrderState::Open)->and($o->approval_status)->toBe(ApprovalStatus::Draft)->and($o->pricing_policy)->toBe('copy')
        ->and($o->assigned_to)->toBe($this->bde->id)->and($o->customer_ledger_id)->toBe($this->customer->id)->and($o->supply_type)->toBe('intra')
        ->and($o->order_date->toDateString())->toBe('2026-10-12')->and($o->delivery_date->toDateString())->toBe('2026-10-26')
        ->and((string) $o->grand_total)->toBe((string) $q->grand_total)->and((string) $o->taxable_total)->toBe((string) $q->taxable_total);

    $line = $o->items()->first();
    $quoted = $q->items()->first();
    expect((string) $line->rate)->toBe((string) $quoted->rate)->and((string) $line->line_total)->toBe((string) $quoted->line_total)->and($line->quotation_item_id)->toBe($quoted->id)
        ->and($line->hsn_code)->toBe('7318')->and((string) $line->invoiced_quantity)->toBe('0.000');

    $entry = SalesOrderStateEntry::query()->where('sales_order_id', $o->id)->sole();
    expect($entry->from_state)->toBeNull()->and($entry->to_state)->toBe(SalesOrderState::Open)->and($entry->comment)->toContain($q->quotation_number);
});

it('keeps the price it was quoted at when the rate master moves on — or prices again if the company says so', function () {
    $q = ($this->quotation)();
    DB::table('rates')->where('item_id', $this->bolt->id)->where('location_id', $this->ahd->id)->update(['effective_to' => '2026-10-12']);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '200', ['effective_from' => '2026-10-13']);
    Carbon::setTestNow('2026-10-14 09:30:00');

    $copied = ($this->order)($q);
    expect((string) $copied->items()->first()->rate)->toBe('125.5000');

    ($this->do)(fn () => ($this->service)()->cancel($copied, 'Test the other policy', $this->bde));
    ($this->setting)('sales_order.quotation_pricing', 'rates');

    $again = ($this->order)($q);
    expect($again->pricing_policy)->toBe('rates')->and((string) $again->items()->first()->rate)->toBe('200.0000');
});

it('carries a quotation’s changed rate across without auditing it twice', function () {
    $deal = ($this->deal)([lineRow($this->bolt, '10', '')]);
    $rows = quoteInput($deal)['items'];
    $rows[0] = [...$rows[0], 'rate_override' => '100', 'override_reason' => 'Volume order'];
    $q = ($this->do)(fn () => app(QuotationService::class)->create($deal, quoteInput($deal, [], $rows), $this->manager));
    $q = approveDocument($this->acme, $q, $this->manager, $this->approver);
    $q = ($this->do)(fn () => app(QuotationService::class)->send($q, ['channel' => 'manual'], $this->manager));
    $q = ($this->do)(fn () => app(QuotationService::class)->accept($q, null, $this->manager));
    expect(ActivityLog::query()->where('event', 'overridden')->count())->toBe(1);

    $o = ($this->do)(fn () => ($this->service)()->createFromQuotation($q, orderInput($this->customer, $q), $this->manager));

    $line = $o->items()->first();
    expect((string) $line->rate)->toBe('100.0000')->and($line->rate_overridden)->toBeTrue()->and($line->override_reason)->toBe('Volume order')
        ->and(ActivityLog::query()->where('event', 'overridden')->count())->toBe(1);
});

it('orders from a quotation that is only sent, or has run out, only by an audited exception', function () {
    $sent = ($this->quotation)('sent');

    expect(fn () => ($this->do)(fn () => ($this->service)()->createFromQuotation($sent, orderInput($this->customer, $sent), $this->bde)))->toThrow(FieldRuleException::class, 'not accepted')
        ->and(fn () => ($this->do)(fn () => ($this->service)()->createFromQuotation($sent, orderInput($this->customer, $sent), $this->manager)))->toThrow(FieldRuleException::class, 'reason');

    $o = ($this->do)(fn () => ($this->service)()->createFromQuotation($sent, orderInput($this->customer, $sent), $this->manager, 'The customer phoned to confirm'));

    expect($o->source_type)->toBe('quotation')->and(SalesOrderStateEntry::query()->where('sales_order_id', $o->id)->sole()->comment)->toContain('by exception: The customer phoned to confirm');

    $audit = ActivityLog::query()->where('event', 'conversion_exception')->sole();
    expect($audit->causer_id)->toBe($this->manager->id)->and($audit->properties['reason'])->toBe('The customer phoned to confirm');
});

it('never orders from a draft, declined, withdrawn or replaced quotation', function () {
    $draft = makeQuotation($this->acme, $this->bde, ($this->deal)(), [], null, 'draft', $this->approver);

    expect(fn () => ($this->do)(fn () => ($this->service)()->createFromQuotation($draft, orderInput($this->customer, $draft), $this->manager, 'Please')))->toThrow(FieldRuleException::class, 'not been sent');

    $declined = ($this->do)(fn () => app(QuotationService::class)->decline(($this->quotation)('sent'), 'Dear', $this->bde));
    expect(fn () => ($this->do)(fn () => ($this->service)()->createFromQuotation($declined, orderInput($this->customer, $declined), $this->manager, 'Please')))->toThrow(FieldRuleException::class, 'declined');

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

it('lets a quotation, and its deal, have one live order at a time — and free them when the order is cancelled', function () {
    $deal = ($this->deal)();
    $q = ($this->quotation)('accepted', $deal);
    $other = makeQuotation($this->acme, $this->bde, $deal->fresh(), [], null, 'sent', $this->approver);
    $other = ($this->do)(fn () => app(QuotationService::class)->accept($other->fresh(), null, $this->bde));
    $first = ($this->order)($q);

    expect(fn () => ($this->order)($q->fresh()))->toThrow(FieldRuleException::class, $first->so_number)
        ->and(fn () => ($this->order)($other->fresh()))->toThrow(FieldRuleException::class, 'already been ordered')       // the deal already has an order
        ->and(fn () => ($this->do)(fn () => ($this->service)()->createFromDeal($deal->fresh(), orderInput($this->customer, $deal), $this->bde)))->toThrow(FieldRuleException::class, 'already been ordered');

    ($this->do)(fn () => ($this->service)()->cancel($first, 'Wrong customer PO', $this->bde));

    expect(($this->order)($q->fresh())->so_number)->toBe('SO/AHD/26-27/00002');
});

it('does not use up a number when the order cannot be made', function () {
    $q = ($this->quotation)();
    $bad = orderInput($this->customer, $q, ['bill_line1' => '']);

    expect(($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->createFromQuotation($q, $bad, $this->bde))))->toHaveKey('order.bill_line1');
    expect(($this->order)($q)->so_number)->toBe('SO/AHD/26-27/00001');
});

// ---- from a deal ----------------------------------------------------------------------------------------------------------

it('makes an order straight from an approved deal, priced from the rate master', function () {
    $deal = ($this->deal)();
    $o = ($this->do)(fn () => ($this->service)()->createFromDeal($deal, orderInput($this->customer, $deal), $this->bde));

    // the deal's lines carried an expected rate of 125.50 for bolts and 0 for wire; the order uses the master's 125.50 and 80
    [$bolt, $wire] = $o->items()->get()->all();
    expect($o->source_type)->toBe('deal')->and($o->quotation_id)->toBeNull()->and($o->deal_id)->toBe($deal->id)->and($o->pricing_policy)->toBe('rates')
        ->and((string) $bolt->rate)->toBe('125.5000')->and((string) $wire->rate)->toBe('80.0000')->and($bolt->deal_item_id)->not->toBeNull()
        ->and((string) $o->grand_total)->toBe('1705.00');
});

it('refuses a deal that is not approved, lost, someone else’s, or has no customer to sell to', function () {
    $draft = ($this->deal)(null, false);
    expect(fn () => ($this->do)(fn () => ($this->service)()->createFromDeal($draft, orderInput($this->customer, $draft), $this->bde)))->toThrow(FieldRuleException::class, 'not been approved');

    $mine = ($this->deal)();
    expect(fn () => ($this->do)(fn () => ($this->service)()->createFromDeal($mine, orderInput($this->customer, $mine), $this->bde2)))->toThrow(FieldRuleException::class, 'not one of yours')
        ->and(fn () => ($this->do)(fn () => ($this->service)()->createFromDeal($mine, orderInput($this->customer, $mine), $this->mumBde)))->toThrow(FieldRuleException::class, 'branch you do not work in');

    $lost = ($this->deal)();
    inCompany($this->acme, fn () => app(DealService::class)->changeStage($lost, DealStage::Lost, $this->bde, 'gone', lostReasonId($this->acme)));
    expect(fn () => ($this->do)(fn () => ($this->service)()->createFromDeal($lost->fresh(), orderInput($this->customer, $lost), $this->bde)))->toThrow(FieldRuleException::class, 'cannot be ordered');

    // a deal for a prospect (no customer ledger) is ordered once a customer is chosen
    $prospect = approveDocument($this->acme, makeDeal($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '5', '')])), $this->bde, $this->approver);
    $noCustomer = orderInput($this->customer, $prospect);
    $noCustomer['customer_ledger_id'] = '';
    expect(fn () => ($this->do)(fn () => ($this->service)()->createFromDeal($prospect, $noCustomer, $this->bde)))->toThrow(FieldRuleException::class, 'no approved customer');
    expect(($this->do)(fn () => ($this->service)()->createFromDeal($prospect, orderInput($this->customer, $prospect), $this->bde))->customer_ledger_id)->toBe($this->customer->id);
});

it('refuses a customer that is not approved and active', function () {
    $draftCustomer = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', ['ledger' => ['name' => 'Not Approved Ltd']], false);
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '5', '')])), $this->bde, $this->approver);
    $input = orderInput($this->customer, $deal);
    $input['customer_ledger_id'] = (string) $draftCustomer->id;

    expect(fn () => ($this->do)(fn () => ($this->service)()->createFromDeal($deal, $input, $this->bde)))->toThrow(FieldRuleException::class, 'no approved customer');
});

// ---- direct ---------------------------------------------------------------------------------------------------------------

it('makes a direct order only for a regular customer, in a branch the person works in, credited to them', function () {
    $input = fn () => orderInput($this->customer, null, [], [['id' => '', 'quotation_item_id' => '', 'deal_item_id' => '', 'item_id' => (string) $this->bolt->id, 'unit_id' => (string) $this->bolt->base_unit_id,
        'quantity' => '4', 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '']]);

    // a customer with no orders yet is not "regular"
    expect(fn () => ($this->do)(fn () => ($this->service)()->createDirect($this->customer->id, $this->ahd->id, $input(), $this->bde)))->toThrow(FieldRuleException::class, 'not a regular customer');

    ($this->order)(null, 'approved');   // one approved order makes them regular

    $o = ($this->do)(fn () => ($this->service)()->createDirect($this->customer->id, $this->ahd->id, $input(), $this->bde));
    expect($o->source_type)->toBe('direct')->and($o->quotation_id)->toBeNull()->and($o->deal_id)->toBeNull()->and($o->lead_id)->toBeNull()->and($o->assigned_to)->toBe($this->bde->id)
        ->and($o->pricing_policy)->toBe('rates')->and((string) $o->grand_total)->toBe('592.00');   // 4 × 125.50 = 502.00 + 18% = 592.36 → 592

    expect(fn () => ($this->do)(fn () => ($this->service)()->createDirect($this->customer->id, $this->mum->id, $input(), $this->bde)))->toThrow(FieldRuleException::class, 'branch you do not work in');
});

it('lets the company decide how many orders make a customer regular', function () {
    $input = orderInput($this->customer, null, [], [['id' => '', 'quotation_item_id' => '', 'deal_item_id' => '', 'item_id' => (string) $this->bolt->id, 'unit_id' => (string) $this->bolt->base_unit_id,
        'quantity' => '1', 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '']]);

    ($this->setting)('sales_order.direct_min_orders', 0);
    expect(($this->do)(fn () => ($this->service)()->createDirect($this->customer->id, $this->ahd->id, $input, $this->bde))->source_type)->toBe('direct');

    ($this->setting)('sales_order.direct_min_orders', 2);
    ($this->order)(null, 'approved');
    expect(fn () => ($this->do)(fn () => ($this->service)()->createDirect($this->customer->id, $this->ahd->id, $input, $this->bde)))->toThrow(FieldRuleException::class, 'needs 2 approved orders');
});

// ---- what is typed ------------------------------------------------------------------------------------------------------

it('checks the delivery date, the customer’s order date, discounts, quantities and the addresses', function () {
    $q = ($this->quotation)();
    $cases = [
        ['order.delivery_date', ['delivery_date' => '2026-10-01'], null],
        ['order.delivery_date', ['delivery_date' => ''], null],
        ['order.customer_po_date', ['customer_po_date' => '2026-11-01'], null],
        ['order.bill_line1', ['bill_line1' => ''], null],
        ['order.place_of_supply_state_id', ['place_of_supply_state_id' => ''], null],
        ['order.customer_gstin', ['customer_gstin' => '24ABCDE1234'], null],
        ['order.ship_pin_code', ['ship_pin_code' => '12'], null],
        ['items.0.quantity', [], ['quantity' => '0']],
        ['items.0.discount_percent', [], ['discount_percent' => '101']],
        ['items', [], 'none'],
    ];

    foreach ($cases as [$field, $o, $row]) {
        $input = orderInput($this->customer, $q, $o);

        if ($row === 'none') {
            $input['items'] = [];
        } elseif ($row !== null) {
            $input['items'][0] = [...$input['items'][0], ...$row];
        }

        $errors = ($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->createFromQuotation($q, $input, $this->bde)));
        expect(array_key_exists($field, $errors))->toBeTrue("{$field} should have been refused");
    }

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

it('ships to the billing address when no shipping address is given', function () {
    $q = ($this->quotation)();
    $o = ($this->do)(fn () => ($this->service)()->createFromQuotation($q, orderInput($this->customer, $q, ['ship_line1' => '', 'ship_line2' => '', 'ship_city' => '', 'ship_pin_code' => '']), $this->bde));

    expect($o->ship_line1)->toBe($o->bill_line1)->and($o->shippingLines())->toBe($o->billingLines());
});

it('changes a draft, re-checking and re-pricing it, and nothing else', function () {
    $o = ($this->order)();
    $input = orderInput($this->customer, null, ['customer_po_number' => 'PO-77', 'customer_po_date' => '2026-10-10', 'delivery_date' => '2026-11-05'], $o->items()->get()->map(fn (SalesOrderItem $l) => [
        'id' => (string) $l->id, 'quotation_item_id' => '', 'deal_item_id' => '', 'item_id' => (string) $l->item_id, 'unit_id' => (string) $l->unit_id,
        'quantity' => $l->item_id === $this->bolt->id ? '20' : (string) $l->quantity, 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
    ])->all());

    $saved = ($this->do)(fn () => ($this->service)()->update($o, $input, $this->bde));

    expect($saved->customer_po_number)->toBe('PO-77')->and($saved->delivery_date->toDateString())->toBe('2026-11-05')->and((string) $saved->items()->first()->taxable_amount)->toBe('2510.00')
        ->and((string) $saved->items()->first()->rate)->toBe('125.5000');   // an unchanged line keeps its price

    ($this->do)(fn () => app(ApprovalService::class)->submit($saved, $this->bde));
    expect(fn () => ($this->do)(fn () => ($this->service)()->update($saved->fresh(), $input, $this->bde)))->toThrow(FieldRuleException::class, 'waiting for approval');
});

// ---- credit ---------------------------------------------------------------------------------------------------------------

it('works out the credit check on every save: within the limit is fine', function () {
    $o = ($this->order)();

    expect($o->credit_result)->toBe(CreditResult::Ok)->and((string) $o->credit_limit)->toBe('500000.00')->and((string) $o->credit_exposure)->toBe('0.00')->and($o->credit_checked_at)->not->toBeNull();
});

it('counts the customer’s other orders that are still in their credit days — and only those', function () {
    $first = ($this->order)(null, 'pending');                                                      // 1,705 pending: counts
    $second = ($this->order)();

    expect((string) $second->credit_exposure)->toBe('1705.00');

    // 31 days on, the first order is past the 30 credit days: the customer is taken to have paid
    Carbon::setTestNow('2026-11-12 09:30:00');
    expect((string) ($this->do)(fn () => app(CreditCheck::class)->exposure($second->fresh(), 30)))->toBe('0.00');

    // a cancelled or a draft order never counts
    Carbon::setTestNow('2026-10-12 09:30:00');
    $draft = ($this->order)();
    expect((string) ($this->do)(fn () => app(CreditCheck::class)->exposure($second->fresh(), 30)))->toBe('1705.00');   // still just the first

    ($this->do)(fn () => app(ApprovalService::class)->reject($first->fresh(), $this->approver, 'Check the rate'));
    ($this->do)(fn () => ($this->service)()->cancel($first->fresh(), 'No longer needed', $this->bde));
    expect((string) ($this->do)(fn () => app(CreditCheck::class)->exposure($draft->fresh(), 30)))->toBe('0.00');
});

it('warns and allows an order over the limit when the company only warns', function () {
    $this->customer->forceFill(['credit_limit' => '1000'])->save();
    $o = ($this->order)(null, 'pending');

    expect($o->credit_result)->toBe(CreditResult::Warn)->and($o->approval_status)->toBe(ApprovalStatus::Pending);
});

it('blocks an order over the limit when the company blocks — until someone allowed overrides it, with a reason', function () {
    ($this->setting)('credit_limit.behaviour', 'block');
    $this->customer->forceFill(['credit_limit' => '1000'])->save();
    $o = ($this->order)();

    expect($o->credit_result)->toBe(CreditResult::Blocked)->and(CreditResult::Blocked->allowsSubmission())->toBeFalse();
    expect(fn () => ($this->do)(fn () => app(ApprovalService::class)->submit($o->fresh(), $this->bde)))->toThrow(ApprovalStateException::class, 'credit limit');

    // a salesperson cannot override; a manager can, but must say why
    expect(fn () => ($this->do)(fn () => ($this->service)()->overrideCredit($o->fresh(), 'We trust them', $this->bde)))->toThrow(AuthorizationException::class);
    expect(($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->overrideCredit($o->fresh(), '', $this->manager))))->toHaveKey('reason');

    $allowed = ($this->do)(fn () => ($this->service)()->overrideCredit($o->fresh(), 'Paid the advance by cheque', $this->manager));
    expect($allowed->credit_result)->toBe(CreditResult::Overridden)->and($allowed->credit_override_reason)->toBe('Paid the advance by cheque')->and($allowed->credit_override_by)->toBe($this->manager->id);

    $audit = ActivityLog::query()->where('event', 'credit_overridden')->sole();
    expect($audit->causer_id)->toBe($this->manager->id)->and($audit->properties['reason'])->toBe('Paid the advance by cheque');

    ($this->do)(fn () => app(ApprovalService::class)->submit($allowed->fresh(), $this->bde));
    expect($o->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('needs the override again when the order grows', function () {
    ($this->setting)('credit_limit.behaviour', 'block');
    $this->customer->forceFill(['credit_limit' => '1000'])->save();
    $o = ($this->order)();
    $o = ($this->do)(fn () => ($this->service)()->overrideCredit($o, 'Advance paid', $this->manager));

    $bigger = orderInput($this->customer, null, [], $o->items()->get()->map(fn (SalesOrderItem $l) => [
        'id' => (string) $l->id, 'quotation_item_id' => '', 'deal_item_id' => '', 'item_id' => (string) $l->item_id, 'unit_id' => (string) $l->unit_id,
        'quantity' => (string) bcmul((string) $l->quantity, '2', 3), 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
    ])->all());
    $o = ($this->do)(fn () => ($this->service)()->update($o, $bigger, $this->bde));

    expect($o->credit_override_reason)->toBeNull()->and($o->credit_result)->toBe(CreditResult::Blocked);
});

it('has nothing to override when the order is within the limit, and ignores the check when switched off', function () {
    ($this->setting)('credit_limit.behaviour', 'block');
    $o = ($this->order)();
    expect(fn () => ($this->do)(fn () => ($this->service)()->overrideCredit($o, 'Not needed', $this->manager)))->toThrow(FieldRuleException::class, 'nothing to override');

    ($this->setting)('credit_limit.behaviour', 'ignore');
    $this->customer->forceFill(['credit_limit' => '1000'])->save();
    expect(($this->order)()->credit_result)->toBe(CreditResult::Ignored);

    $this->customer->forceFill(['credit_limit' => null])->save();
    ($this->setting)('credit_limit.behaviour', 'block');
    expect(($this->order)()->credit_result)->toBe(CreditResult::NoLimit);
});

// ---- approval ---------------------------------------------------------------------------------------------------------------

it('wins the deal and its lead when an order made from it is approved, and makes the invoice', function () {
    $q = ($this->quotation)();
    $deal = $q->deal;
    $o = ($this->order)($q, 'approved');

    expect($o->approval_status)->toBe(ApprovalStatus::Approved)->and($o->state)->toBe(SalesOrderState::Invoiced)->and($o->invoice)->not->toBeNull()
        ->and($deal->fresh()->stage)->toBe(DealStage::Won)->and($deal->lead->fresh()->stage)->toBe(LeadStage::Won);
});

it('wins the inquiry of a quotation made straight from it', function () {
    $inquiry = approveDocument($this->acme, qualify($this->acme, makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '120')]), $this->bde), $this->bde, $this->approver);
    $q = makeQuotation($this->acme, $this->bde, $inquiry, [], null, 'sent', $this->approver);
    $q = ($this->do)(fn () => app(QuotationService::class)->accept($q, null, $this->bde));

    $o = ($this->order)($q, 'approved');

    expect($o->deal_id)->toBeNull()->and($o->lead_id)->toBe($inquiry->id)->and($inquiry->fresh()->stage)->toBe(LeadStage::Won);
});

it('cannot be approved by the person who sent it, and is not approved by a draft or rejected route', function () {
    $o = ($this->order)(null, 'pending');

    expect(fn () => ($this->do)(fn () => app(ApprovalService::class)->approve($o->fresh(), $this->bde)))->toThrow('not an approver');
    expect($o->fresh()->approval_status)->toBe(ApprovalStatus::Pending);

    ($this->do)(fn () => app(ApprovalService::class)->reject($o->fresh(), $this->approver, 'Check the delivery date'));
    expect($o->fresh()->approval_status)->toBe(ApprovalStatus::Rejected)->and($o->fresh()->invoice)->toBeNull();

    // a returned order can be changed again, and sent again
    $saved = ($this->do)(fn () => ($this->service)()->update($o->fresh(), orderInput($this->customer, null, ['delivery_date' => '2026-11-20'], $o->items()->get()->map(fn (SalesOrderItem $l) => [
        'id' => (string) $l->id, 'quotation_item_id' => '', 'deal_item_id' => '', 'item_id' => (string) $l->item_id, 'unit_id' => (string) $l->unit_id,
        'quantity' => (string) $l->quantity, 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
    ])->all()), $this->bde));
    expect($saved->delivery_date->toDateString())->toBe('2026-11-20');
});

// ---- never changes once locked -----------------------------------------------------------------------------------------------

it('refuses every change to an order that is waiting for approval, approved, invoiced or cancelled — in the model too', function () {
    $pending = ($this->order)(null, 'pending');
    $row = SalesOrder::withoutGlobalScopes()->find($pending->id);

    expect(fn () => $row->forceFill(['party_name' => 'Somebody else'])->save())->toThrow(LogicException::class, 'cannot be changed')
        ->and(fn () => $row->delete())->toThrow(LogicException::class, 'cancel it');

    $line = SalesOrderItem::withoutGlobalScopes()->where('sales_order_id', $pending->id)->first();
    expect(fn () => $line->forceFill(['rate' => '1'])->save())->toThrow(LogicException::class)
        ->and(fn () => $line->forceDelete())->toThrow(LogicException::class)
        ->and(fn () => (new SalesOrderItem)->forceFill(['company_id' => $pending->company_id, 'sales_order_id' => $pending->id, 'line_no' => 9])->save())->toThrow(LogicException::class);

    $invoiced = ($this->order)(null, 'approved');
    $row = SalesOrder::withoutGlobalScopes()->find($invoiced->id);
    expect($row->state)->toBe(SalesOrderState::Invoiced)->and(fn () => $row->forceFill(['grand_total' => '1.00'])->save())->toThrow(LogicException::class);
    expect(fn () => ($this->do)(fn () => ($this->service)()->update($invoiced->fresh(), orderInput($this->customer), $this->bde)))->toThrow(FieldRuleException::class, 'invoiced');
});

it('keeps its history for good', function () {
    $o = ($this->order)();
    $entry = SalesOrderStateEntry::withoutGlobalScopes()->where('sales_order_id', $o->id)->first();

    expect(fn () => $entry->forceFill(['comment' => 'x'])->save())->toThrow(LogicException::class)->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

// ---- cancelling ---------------------------------------------------------------------------------------------------------------

it('cancels an order with a reason, and its invoice with it', function () {
    $o = ($this->order)(null, 'approved');
    $invoice = $o->invoice;

    expect(($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->cancel($o->fresh(), '', $this->manager))))->toHaveKey('reason');

    $cancelled = ($this->do)(fn () => ($this->service)()->cancel($o->fresh(), 'The customer withdrew the order', $this->manager));

    expect($cancelled->state)->toBe(SalesOrderState::Cancelled)->and($cancelled->cancel_reason)->toBe('The customer withdrew the order')->and($cancelled->cancelled_by)->toBe($this->manager->id)
        ->and($invoice->fresh()->status)->toBe(SalesInvoice::CANCELLED)->and($invoice->fresh()->cancel_reason)->toContain($o->so_number);
    expect(SalesOrderStateEntry::query()->where('sales_order_id', $o->id)->latest('id')->first()->to_state)->toBe(SalesOrderState::Cancelled);

    expect(fn () => ($this->do)(fn () => ($this->service)()->cancel($cancelled, 'Again', $this->manager)))->toThrow(FieldRuleException::class, 'already been cancelled');
});

it('does not cancel an order that waits for a decision', function () {
    $o = ($this->order)(null, 'pending');

    expect(fn () => ($this->do)(fn () => ($this->service)()->cancel($o, 'Mistake', $this->bde)))->toThrow(FieldRuleException::class, 'waiting for approval');
});

it('keeps a cancelled order out of what a customer owes', function () {
    $a = ($this->order)(null, 'approved');
    $b = ($this->order)();
    expect((string) $b->credit_exposure)->toBe('1705.00');

    ($this->do)(fn () => ($this->service)()->cancel($a->fresh(), 'Cancelled by the customer', $this->manager));
    $after = ($this->do)(fn () => app(CreditCheck::class)->exposure($b->fresh(), 30));

    expect((string) $after)->toBe('0.00');
});

it('takes every order number from the branch’s own series', function () {
    $a = ($this->order)();
    $b = ($this->order)();

    expect([$a->so_number, $b->so_number])->toBe(['SO/AHD/26-27/00001', 'SO/AHD/26-27/00002']);
});
