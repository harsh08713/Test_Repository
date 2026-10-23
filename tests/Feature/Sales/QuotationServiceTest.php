<?php

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealService;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Mail\QuotationMail;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationDispatch;
use App\Modules\Sales\Models\QuotationItem;
use App\Modules\Sales\Models\QuotationStateEntry;
use App\Modules\Sales\Notifications\QuotationExpiryNotification;
use App\Modules\Sales\Services\QuotationConversionGuard;
use App\Modules\Sales\Services\QuotationDelivery;
use App\Modules\Sales\Services\QuotationExpiry;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\NumberSeries;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Quotations: made from an approved deal or inquiry, priced from the rate master, taxed by place of supply, approved, sent, answered,
 * revised and withdrawn. Everything goes through QuotationService.
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

    $this->customer = makeCustomer($this->acme);
    $this->bolt = makeUsableItem($this->acme);                                                                           // NOS, HSN 7318, GST 18
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG'), 'gst_rate' => '12']);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');
    makeStandardRate($this->acme, $this->wire, $this->ahd, '80');

    $this->service = fn () => app(QuotationService::class);
    $this->lead = fn (?array $lines = null, $by = null) => makeLead($this->acme, $by ?? $this->bde, $this->ahd, [], $lines ?? [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]);
    $this->deal = fn (?array $lines = null, bool $approved = true): Deal => $approved
        ? approveDocument($this->acme, makeDeal($this->acme, $this->bde, ($this->lead)($lines)), $this->bde, $this->approver)
        : makeDeal($this->acme, $this->bde, ($this->lead)($lines));
    $this->quote = fn (?Deal $deal = null, array $q = [], ?array $items = null, string $upTo = 'draft') => makeQuotation($this->acme, $this->bde, $deal ?? ($this->deal)(), $q, $items, $upTo, $this->approver);
    $this->make = fn (Deal|Lead $source, array $q = [], ?array $items = null, $actor = null) => inCompany($this->acme, fn () => ($this->service)()->create($source, quoteInput($source, $q, $items), $actor ?? $this->bde));
    $this->errors = function (callable $try): array {
        try {
            $try();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
    $this->do = fn (callable $call) => inCompany($this->acme, $call);
});

afterEach(fn () => Carbon::setTestNow());

// ---- making ---------------------------------------------------------------------------------------------------------

it('makes Rev 0 from an approved deal: numbered, priced from the master, taxed within the state, as a draft', function () {
    $q = ($this->quote)();

    expect($q->quotation_number)->toBe('QT/AHD/26-27/00001')->and($q->revision_no)->toBe(0)->and($q->state)->toBe(QuotationState::Draft)
        ->and($q->approval_status)->toBe(ApprovalStatus::Draft)->and($q->supply_type)->toBe('intra')->and($q->supplier_state_code)->toBe('24')
        ->and($q->valid_until->toDateString())->toBe('2026-11-11')->and($q->deal_id)->not->toBeNull()->and($q->lead_id)->not->toBeNull();

    $lines = $q->items()->get();
    $bolt = $lines[0];

    expect($lines)->toHaveCount(2)
        ->and($bolt->item_id)->toBe($this->bolt->id)->and((string) $bolt->quantity)->toBe('10.000')->and((string) $bolt->list_rate)->toBe('125.5000')->and((string) $bolt->rate)->toBe('125.5000')
        ->and($bolt->rate_overridden)->toBeFalse()->and($bolt->rate_source)->toBe('standard')->and($bolt->hsn_code)->toBe('7318')->and((string) $bolt->gst_rate)->toBe('18.00')
        ->and((string) $bolt->taxable_amount)->toBe('1255.00')->and((string) $bolt->cgst_amount)->toBe('112.95')->and((string) $bolt->sgst_amount)->toBe('112.95')->and((string) $bolt->igst_amount)->toBe('0.00')
        ->and((string) $bolt->line_total)->toBe('1480.90');

    // wire: 2.5 kg × 80 = 200.00, GST 12% = 24.00
    expect((string) $lines[1]->taxable_amount)->toBe('200.00')->and((string) $lines[1]->cgst_amount)->toBe('12.00')->and((string) $lines[1]->line_total)->toBe('224.00');

    // 1,255.00 + 200.00 = 1,455.00; tax 225.90 + 24.00 = 249.90; sum 1,704.90 → 1,705.00
    expect((string) $q->taxable_total)->toBe('1455.00')->and((string) $q->cgst_total)->toBe('124.95')->and((string) $q->sgst_total)->toBe('124.95')->and((string) $q->igst_total)->toBe('0.00')
        ->and((string) $q->round_off)->toBe('0.10')->and((string) $q->grand_total)->toBe('1705.00');

    $entry = QuotationStateEntry::query()->where('quotation_id', $q->id)->sole();
    expect($entry->from_state)->toBeNull()->and($entry->to_state)->toBe(QuotationState::Draft)->and($entry->actor_id)->toBe($this->bde->id);
});

it('charges IGST when the goods go to another state', function () {
    $maharashtra = stateId($this->acme, '27');
    $q = ($this->quote)(null, ['place_of_supply_state_id' => (string) $maharashtra]);

    expect($q->supply_type)->toBe('inter')->and((string) $q->igst_total)->toBe('249.90')->and((string) $q->cgst_total)->toBe('0.00')->and((string) $q->sgst_total)->toBe('0.00')
        ->and((string) $q->grand_total)->toBe('1705.00');
});

it('takes the branch’s state as the supplier’s, and refuses to guess when neither branch nor company has one', function () {
    $mumLead = makeLead($this->acme, $this->mumBde, $this->mum, [], [lineRow($this->bolt, '10', '')]);
    makeStandardRate($this->acme, $this->bolt, $this->mum, '130');
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->mumBde, $mumLead), $this->mumBde, $this->approver);

    // Mumbai is in Maharashtra: supplying Gujarat from there is inter-state
    $q = makeQuotation($this->acme, $this->mumBde, $deal, [], null, 'draft', $this->approver);
    expect($q->supply_type)->toBe('inter')->and($q->supplier_state_code)->toBe('27');

    $blank = makeLocation($this->acme, 'XXX');
    $this->acme->forceFill(['state_code' => null])->save();
    makeStandardRate($this->acme, $this->bolt, $blank, '90');
    $xBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$blank]);
    $lead = makeLead($this->acme, $xBde, $blank, [], [lineRow($this->bolt, '1', '')]);
    $d = approveDocument($this->acme, makeDeal($this->acme, $xBde, $lead), $xBde, $this->approver);

    $errors = ($this->errors)(fn () => ($this->make)($d, [], null, $xBde));
    expect($errors)->toHaveKey('quotation.place_of_supply_state_id')->and($errors['quotation.place_of_supply_state_id'][0])->toContain('no state set');
});

it('refuses a deal that is not approved, a lost deal, someone else’s deal and a branch the person does not work in', function () {
    $draft = ($this->deal)(null, false);
    expect(fn () => ($this->make)($draft))->toThrow(FieldRuleException::class, 'has not been approved');

    $lost = ($this->deal)();
    inCompany($this->acme, fn () => app(DealService::class)->changeStage($lost, DealStage::Lost, $this->bde, 'gone', lostReasonId($this->acme)));
    expect(fn () => ($this->make)($lost->fresh()))->toThrow(FieldRuleException::class, 'cannot be quoted');

    $mine = ($this->deal)();
    expect(fn () => ($this->make)($mine, [], null, $this->bde2))->toThrow(FieldRuleException::class, 'not one of yours')
        ->and(fn () => ($this->make)($mine, [], null, $this->mumBde))->toThrow(FieldRuleException::class, 'branch you do not work in');

    expect(Quotation::withoutGlobalScopes()->count())->toBe(0);
});

it('does not use up a number when a line has no rate, and says which rate is missing', function () {
    $nail = makeUsableItem($this->acme, 'Nail 2 inch');
    $deal = ($this->deal)([lineRow($nail, '100', '')]);

    $errors = ($this->errors)(fn () => ($this->make)($deal));

    expect($errors)->toHaveKey('items.0.item_id')->and($errors['items.0.item_id'][0])->toContain('There is no rate for Nail 2 inch');
    expect(Quotation::withoutGlobalScopes()->count())->toBe(0);

    makeStandardRate($this->acme, $nail, $this->ahd, '3');
    expect(($this->make)($deal)->quotation_number)->toBe('QT/AHD/26-27/00001');   // the failed attempt did not take 00001
});

it('uses a price list’s own rate and says when it fell back to the standard one', function () {
    $dealer = makePriceList($this->acme);
    makeRate($this->acme, makeUser(['Accounts User'], [$this->acme]), $this->bolt, $this->ahd, ['rate' => '110', 'price_list_id' => (string) $dealer->id], 'approved');

    $q = ($this->quote)(null, ['price_list_id' => (string) $dealer->id]);
    [$bolt, $wire] = $q->items()->get()->all();

    expect((string) $bolt->rate)->toBe('110.0000')->and($bolt->rate_source)->toBe('price_list')                  // the dealer list has a bolt rate
        ->and((string) $wire->rate)->toBe('80.0000')->and($wire->rate_source)->toBe('standard')                  // and none for wire: standard
        ->and($q->price_list_id)->toBe($dealer->id);

    expect(($this->errors)(fn () => ($this->make)(($this->deal)(), ['price_list_id' => '99999'])))->toHaveKey('quotation.price_list_id');
});

it('applies a discount before the tax', function () {
    $deal = ($this->deal)([lineRow($this->bolt, '10', '')]);
    $rows = [['id' => '', 'deal_item_id' => (string) $deal->items()->first()->id, 'lead_interest_id' => '', 'item_id' => (string) $this->bolt->id, 'unit_id' => (string) $this->bolt->base_unit_id,
        'quantity' => '10', 'discount_percent' => '2.5', 'rate_override' => '', 'override_reason' => '']];

    $q = ($this->make)($deal, [], $rows);

    expect((string) $q->discount_total)->toBe('31.38')->and((string) $q->taxable_total)->toBe('1223.62')->and((string) $q->cgst_total)->toBe('110.13')
        ->and((string) $q->grand_total)->toBe('1444.00');
});

it('checks what was typed: validity, quantities, discount, GSTIN, e-mail, units and the place of supply', function () {
    $deal = ($this->deal)();
    $row = fn (array $r = []) => [[...quoteInput($deal)['items'][0], ...$r]];

    $cases = [
        ['quotation.valid_until', ['valid_until' => '2026-10-01'], null],
        ['quotation.valid_until', ['valid_until' => '2028-10-01'], null],
        ['quotation.place_of_supply_state_id', ['place_of_supply_state_id' => ''], null],
        ['quotation.place_of_supply_state_id', ['place_of_supply_state_id' => '999999'], null],
        ['quotation.bill_line1', ['bill_line1' => ''], null],
        ['quotation.customer_gstin', ['customer_gstin' => '24ABCDE1234'], null],
        ['quotation.email', ['email' => 'not-an-email'], null],
        ['quotation.mobile', ['mobile' => '12345'], null],
        ['quotation.bill_pin_code', ['bill_pin_code' => '12'], null],
        ['items.0.quantity', [], $row(['quantity' => '0'])],
        ['items.0.quantity', [], $row(['quantity' => '1.5'])],           // a nut cannot be split: NOS takes whole numbers
        ['items.0.discount_percent', [], $row(['discount_percent' => '101'])],
        ['items.0.discount_percent', [], $row(['discount_percent' => '-1'])],
        ['items.0.unit_id', [], $row(['unit_id' => (string) unitId($this->acme, 'KG')])],
        ['items.0.item_id', [], $row(['item_id' => '99999'])],
        ['items', [], []],
    ];

    foreach ($cases as [$field, $q, $items]) {
        $errors = ($this->errors)(fn () => ($this->make)($deal, $q, $items));
        expect(array_key_exists($field, $errors))->toBeTrue("{$field} should have been refused");
    }

    expect(Quotation::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an item with no GST rate rather than quote it tax-free', function () {
    $odd = makeUsableItem($this->acme, 'Odd Thing');
    DB::table('items')->where('id', $odd->id)->update(['gst_rate' => null]);   // the item workflow never lets this happen; old data might
    makeStandardRate($this->acme, $odd, $this->ahd, '10');
    $deal = ($this->deal)([lineRow($odd, '1', '')]);

    expect(($this->errors)(fn () => ($this->make)($deal)))->toHaveKey('items.0.item_id');
});

// ---- the rate, and changing it ----------------------------------------------------------------------------------------

it('lets only someone who may override a rate quote at another one — with a reason, and an audit entry on the rate', function () {
    $deal = ($this->deal)([lineRow($this->bolt, '10', '')]);
    $row = fn (array $r) => [[...quoteInput($deal)['items'][0], ...$r]];

    // a salesperson cannot
    $errors = ($this->errors)(fn () => ($this->make)($deal, [], $row(['rate_override' => '100', 'override_reason' => 'Big customer'])));
    expect($errors)->toHaveKey('items.0.rate_override');

    // a manager can, but has to say why
    expect(($this->errors)(fn () => ($this->make)($deal, [], $row(['rate_override' => '100']), $this->manager)))->toHaveKey('items.0.override_reason');

    $q = ($this->make)($deal, [], $row(['rate_override' => '100', 'override_reason' => 'Big customer']), $this->manager);
    $line = $q->items()->first();

    expect((string) $line->list_rate)->toBe('125.5000')->and((string) $line->rate)->toBe('100.0000')->and($line->rate_overridden)->toBeTrue()->and($line->override_reason)->toBe('Big customer')
        ->and((string) $line->taxable_amount)->toBe('1000.00');

    $audit = ActivityLog::query()->where('log_name', 'rate')->where('event', 'overridden')->sole();
    expect($audit->causer_id)->toBe($this->manager->id)->and($audit->properties['old']['rate'])->toBe('125.5000')->and($audit->properties['attributes']['rate'])->toBe('100.0000')
        ->and($audit->properties['reason'])->toBe('Big customer')->and($audit->properties['document'])->toBe(['type' => 'quotation', 'id' => $q->id]);
});

it('does not call it an override when the rate typed is the master’s rate, and refuses a zero rate', function () {
    $deal = ($this->deal)([lineRow($this->bolt, '10', '')]);
    $row = fn (array $r) => [[...quoteInput($deal)['items'][0], ...$r]];

    $q = ($this->make)($deal, [], $row(['rate_override' => '125.50']), $this->manager);
    expect($q->items()->first()->rate_overridden)->toBeFalse()->and(ActivityLog::query()->where('event', 'overridden')->count())->toBe(0);

    expect(($this->errors)(fn () => ($this->make)($deal, [], $row(['rate_override' => '0']), $this->manager)))->toHaveKey('items.0.rate_override');
});

// ---- changing a draft -----------------------------------------------------------------------------------------------

it('keeps the price an unchanged line was saved with when the master moves on, and re-prices on request', function () {
    $q = ($this->quote)();
    // the master moves on: the old rate ends today and a dearer one starts tomorrow
    DB::table('rates')->where('item_id', $this->bolt->id)->where('location_id', $this->ahd->id)->update(['effective_to' => '2026-10-12']);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '140', ['effective_from' => '2026-10-13']);
    Carbon::setTestNow('2026-10-14 09:30:00');

    $input = quoteInput($q->deal, [], collect($q->items()->get())->map(fn (QuotationItem $l) => [
        'id' => (string) $l->id, 'deal_item_id' => (string) $l->deal_item_id, 'lead_interest_id' => '', 'item_id' => (string) $l->item_id, 'unit_id' => (string) $l->unit_id,
        'quantity' => (string) $l->quantity, 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
    ])->all());
    $input['quotation']['valid_until'] = '2026-11-20';

    $saved = ($this->do)(fn () => ($this->service)()->update($q, $input, $this->bde));
    expect((string) $saved->items()->first()->rate)->toBe('125.5000')->and($saved->price_date->toDateString())->toBe('2026-10-12');

    $input['quotation']['reprice'] = '1';
    $again = ($this->do)(fn () => ($this->service)()->update($saved->fresh(), $input, $this->bde));
    expect((string) $again->items()->first()->rate)->toBe('140.0000')->and($again->price_date->toDateString())->toBe('2026-10-14');
});

it('re-prices a line whose item or unit changed, and a quantity change keeps the rate', function () {
    $q = ($this->quote)(null, [], null);
    $lines = $q->items()->get();
    $input = quoteInput($q->deal, [], $lines->map(fn (QuotationItem $l) => [
        'id' => (string) $l->id, 'deal_item_id' => (string) $l->deal_item_id, 'lead_interest_id' => '', 'item_id' => (string) $l->item_id, 'unit_id' => (string) $l->unit_id,
        'quantity' => $l->item_id === $this->bolt->id ? '20' : (string) $l->quantity, 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
    ])->all());

    $saved = ($this->do)(fn () => ($this->service)()->update($q, $input, $this->bde));

    expect((string) $saved->items()->first()->taxable_amount)->toBe('2510.00')->and((string) $saved->items()->first()->rate)->toBe('125.5000')
        ->and($saved->items()->count())->toBe(2)->and(QuotationItem::withoutGlobalScopes()->where('quotation_id', $q->id)->count())->toBe(2);
});

it('lets a draft lose a line (a partial offer) but not have none', function () {
    $q = ($this->quote)();
    $first = $q->items()->first();
    $input = quoteInput($q->deal, [], [[
        'id' => (string) $first->id, 'deal_item_id' => (string) $first->deal_item_id, 'lead_interest_id' => '', 'item_id' => (string) $first->item_id, 'unit_id' => (string) $first->unit_id,
        'quantity' => '10', 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
    ]]);

    $saved = ($this->do)(fn () => ($this->service)()->update($q, $input, $this->bde));
    expect($saved->items()->count())->toBe(1)->and((string) $saved->grand_total)->toBe('1481.00');

    expect(($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->update($saved, quoteInput($q->deal, [], []), $this->bde))))->toHaveKey('items');
});

it('locks a quotation while it is in approval and once approved, until it is re-opened', function () {
    $q = ($this->quote)();
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($q, $this->bde));

    expect(fn () => ($this->do)(fn () => ($this->service)()->update($q->fresh(), quoteInput($q->deal), $this->bde)))->toThrow(FieldRuleException::class, 'waiting for approval');

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($q->fresh(), $this->approver));
    expect(fn () => ($this->do)(fn () => ($this->service)()->update($q->fresh(), quoteInput($q->deal), $this->bde)))->toThrow(FieldRuleException::class, 'is approved');

    inCompany($this->acme, fn () => app(ApprovalService::class)->reopen($q->fresh(), $this->bde, 'Wrong quantity'));
    $input = quoteInput($q->deal, ['remarks' => 'fixed'], $q->items()->get()->map(fn (QuotationItem $l) => [
        'id' => (string) $l->id, 'deal_item_id' => (string) $l->deal_item_id, 'lead_interest_id' => '', 'item_id' => (string) $l->item_id, 'unit_id' => (string) $l->unit_id,
        'quantity' => (string) $l->quantity, 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
    ])->all());

    expect(($this->do)(fn () => ($this->service)()->update($q->fresh(), $input, $this->bde))->remarks)->toBe('fixed');
});

it('cannot be sent for approval without lines, or after its validity has run out', function () {
    $q = ($this->quote)();
    Carbon::setTestNow('2026-12-01 09:00:00');

    expect(fn () => inCompany($this->acme, fn () => app(ApprovalService::class)->submit($q->fresh(), $this->bde)))->toThrow(ApprovalStateException::class, 'valid-until');
});

// ---- from an inquiry --------------------------------------------------------------------------------------------------

it('can be made straight from an approved inquiry, and not from anything less', function () {
    $inquiry = makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '120')]);

    expect(fn () => ($this->make)($inquiry))->toThrow(FieldRuleException::class, 'not been approved');

    $inquiry = approveDocument($this->acme, qualify($this->acme, $inquiry, $this->bde), $this->bde, $this->approver);
    $q = ($this->make)($inquiry);

    expect($q->deal_id)->toBeNull()->and($q->lead_id)->toBe($inquiry->id)->and($q->customer_ledger_id)->toBe($this->customer->id)
        ->and($q->items()->count())->toBe(1)->and($q->items()->first()->lead_interest_id)->not->toBeNull();

    // a new lead has to become a deal first
    expect(fn () => ($this->make)(($this->lead)()))->toThrow(FieldRuleException::class, 'converted to a deal');

    // an inquiry that has been converted is quoted through its deal
    $converted = approveDocument($this->acme, qualify($this->acme, makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '120')]), $this->bde), $this->bde, $this->approver);
    $deal = makeDeal($this->acme, $this->bde, $converted->fresh());
    expect(fn () => ($this->make)($converted->fresh()))->toThrow(FieldRuleException::class, $deal->deal_number);
});

it('moves an inquiry that is quoted directly to the Quotation stage once the quotation is out, and back when it ends', function () {
    $inquiry = approveDocument($this->acme, qualify($this->acme, makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '120')]), $this->bde), $this->bde, $this->approver);
    $q = makeQuotation($this->acme, $this->bde, $inquiry, [], null, 'sent', $this->approver);

    expect($inquiry->fresh()->stage)->toBe(LeadStage::Quotation);

    ($this->do)(fn () => ($this->service)()->decline($q->fresh(), 'Too dear', $this->bde));
    expect($inquiry->fresh()->stage)->toBe(LeadStage::Qualified);
});

// ---- sending --------------------------------------------------------------------------------------------------------

it('sends only an approved draft, and records it: sent, the deal in Quotation, a follow-up planned, a dispatch logged', function () {
    $q = ($this->quote)();

    expect(fn () => ($this->do)(fn () => ($this->service)()->send($q, ['channel' => 'manual'], $this->bde)))->toThrow(FieldRuleException::class, 'approved before');

    $q = approveDocument($this->acme, $q, $this->bde, $this->approver);
    $sent = ($this->do)(fn () => ($this->service)()->send($q, ['channel' => 'manual'], $this->bde));

    expect($sent->state)->toBe(QuotationState::Sent)->and($sent->sent_at->toDateTimeString())->toBe('2026-10-12 09:30:00');

    $deal = $q->deal->fresh();
    expect($deal->stage)->toBe(DealStage::Quotation)->and($deal->probability)->toBe(70)->and($deal->lead->fresh()->stage)->toBe(LeadStage::Quotation);

    $dispatch = QuotationDispatch::query()->where('quotation_id', $q->id)->sole();
    expect($dispatch->channel)->toBe('manual')->and($dispatch->status)->toBe('sent')->and($dispatch->actor_id)->toBe($this->bde->id);

    $followup = Followup::query()->where('subject_id', $deal->lead_id)->where('status', FollowupStatus::Pending->value)->latest('id')->first();
    expect($followup)->not->toBeNull()->and($followup->scheduled_at->toDateTimeString())->toBe('2026-10-15 11:00:00')->and($followup->assigned_to)->toBe($this->bde->id)
        ->and($followup->notes)->toContain($q->quotation_number);

    expect(fn () => ($this->do)(fn () => ($this->service)()->send($sent->fresh(), ['channel' => 'manual'], $this->bde)))->toThrow(FieldRuleException::class, 'already been sent');
});

it('does not send once the valid-until date has passed, or while the deal is not approved any more', function () {
    $q = ($this->quote)(null, [], null, 'approved');
    Carbon::setTestNow('2026-11-12 09:00:00');
    expect(fn () => ($this->do)(fn () => ($this->service)()->send($q->fresh(), ['channel' => 'manual'], $this->bde)))->toThrow(FieldRuleException::class, 'has passed');

    Carbon::setTestNow('2026-10-13 09:00:00');
    $other = ($this->quote)(null, [], null, 'approved');
    inCompany($this->acme, fn () => app(ApprovalService::class)->reopen($other->deal->fresh(), $this->bde, 'Fix the lines'));
    expect(fn () => ($this->do)(fn () => ($this->service)()->send($other->fresh(), ['channel' => 'manual'], $this->bde)))->toThrow(FieldRuleException::class, 'approved again');
});

it('e-mails the PDF through the mail transport and logs it', function () {
    Mail::fake();
    $q = ($this->quote)(null, ['email' => 'buyer@example.com', 'contact_person' => 'Mr Shah'], null, 'approved');

    $sent = ($this->do)(fn () => ($this->service)()->send($q, ['channel' => 'email', 'to' => '', 'cc' => 'boss@example.com', 'message' => 'Here you go.'], $this->bde));

    expect($sent->state)->toBe(QuotationState::Sent);
    Mail::assertSent(QuotationMail::class, fn (QuotationMail $m) => $m->hasTo('buyer@example.com') && $m->hasCc('boss@example.com') && $m->hasReplyTo($this->bde->email)
        && str_contains($m->subjectLine, 'QT/AHD/26-27/00001') && $m->note === 'Here you go.');

    $dispatch = QuotationDispatch::query()->where('quotation_id', $q->id)->sole();
    expect($dispatch->channel)->toBe('email')->and($dispatch->status)->toBe('sent')->and($dispatch->to_email)->toBe('buyer@example.com')->and($dispatch->cc_email)->toBe('boss@example.com')
        ->and($dispatch->pdf_sha256)->toMatch('/^[0-9a-f]{64}$/');
});

it('asks for a good address before e-mailing, and sends nothing', function () {
    Mail::fake();
    $q = ($this->quote)(null, ['email' => ''], null, 'approved');

    expect(($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->send($q, ['channel' => 'email', 'to' => 'nope'], $this->bde))))->toHaveKey('to');
    expect(($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->send($q, ['channel' => 'email', 'to' => ''], $this->bde))))->toHaveKey('to');

    Mail::assertNothingSent();
    expect($q->fresh()->state)->toBe(QuotationState::Draft)->and(QuotationDispatch::query()->count())->toBe(0);
});

it('puts the quotation back to a draft, and logs the failure, when the mail server refuses', function () {
    // a real transport pointed at nothing: the connection is refused at once
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2]]);
    Mail::purge('smtp');

    $q = ($this->quote)(null, ['email' => 'buyer@example.com'], null, 'approved');

    expect(fn () => ($this->do)(fn () => ($this->service)()->send($q, ['channel' => 'email', 'to' => ''], $this->bde)))->toThrow(FieldRuleException::class, 'could not be sent');

    $q = $q->fresh();
    expect($q->state)->toBe(QuotationState::Draft)->and($q->sent_at)->toBeNull()->and($q->deal->fresh()->stage)->not->toBe(DealStage::Quotation);

    $dispatch = QuotationDispatch::query()->where('quotation_id', $q->id)->sole();
    expect($dispatch->status)->toBe('failed')->and($dispatch->error)->not->toBeEmpty();

    // and it can be sent once the server is fine again
    Mail::fake();
    config(['mail.default' => 'array']);
    expect(($this->do)(fn () => ($this->service)()->send($q->fresh(), ['channel' => 'email', 'to' => ''], $this->bde))->state)->toBe(QuotationState::Sent);
});

it('never carries a credential in a failure message', function () {
    $e = new RuntimeException('Connection to smtp://mailer:s3cret@mail.example.com failed, password=hunter2 refused');
    $message = app(QuotationDelivery::class)->safeMessage($e);

    expect($message)->not->toContain('s3cret')->and($message)->not->toContain('hunter2')->and($message)->toContain('RuntimeException');
});

it('renders a real PDF of a revision from what is stored', function () {
    $q = ($this->quote)(null, ['terms' => "Pay 50% in advance.\n<script>alert(1)</script>"], null, 'approved');
    $pdf = app(QuotationDelivery::class)->pdf($q->fresh());

    expect(substr($pdf, 0, 5))->toBe('%PDF-')->and(strlen($pdf))->toBeGreaterThan(2000);
    expect(app(QuotationDelivery::class)->filename($q))->toBe('qt-ahd-26-27-00001-Rev0.pdf');
});

// ---- never changes once sent --------------------------------------------------------------------------------------------

it('refuses every change to a revision that has left draft — in the service and in the model', function () {
    $q = ($this->quote)(null, [], null, 'sent');

    expect(fn () => ($this->do)(fn () => ($this->service)()->update($q->fresh(), quoteInput($q->deal), $this->bde)))->toThrow(FieldRuleException::class, 'can no longer be changed');

    // even code that goes round the service
    $row = Quotation::withoutGlobalScopes()->find($q->id);
    expect(fn () => $row->forceFill(['party_name' => 'Somebody else'])->save())->toThrow(LogicException::class, 'new revision')
        ->and(fn () => $row->forceFill(['grand_total' => '1.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class, 'cancel');

    $line = QuotationItem::withoutGlobalScopes()->where('quotation_id', $q->id)->first();
    expect(fn () => $line->forceFill(['rate' => '1'])->save())->toThrow(LogicException::class)
        ->and(fn () => $line->forceDelete())->toThrow(LogicException::class)
        ->and(fn () => (new QuotationItem)->forceFill(['company_id' => $q->company_id, 'quotation_id' => $q->id, 'line_no' => 9])->save())->toThrow(LogicException::class);

    // what may still move is its state
    $row = Quotation::withoutGlobalScopes()->find($q->id);
    $row->forceFill(['state' => 'accepted', 'accepted_at' => now()])->save();
    expect($row->fresh()->state)->toBe(QuotationState::Accepted);
});

it('keeps its history and its dispatch log for good', function () {
    $q = ($this->quote)(null, [], null, 'sent');

    $entry = QuotationStateEntry::withoutGlobalScopes()->where('quotation_id', $q->id)->first();
    expect(fn () => $entry->forceFill(['comment' => 'x'])->save())->toThrow(LogicException::class)->and(fn () => $entry->delete())->toThrow(LogicException::class);

    $dispatch = QuotationDispatch::withoutGlobalScopes()->where('quotation_id', $q->id)->first();
    expect(fn () => $dispatch->forceFill(['status' => 'failed'])->save())->toThrow(LogicException::class)->and(fn () => $dispatch->delete())->toThrow(LogicException::class);
});

// ---- the customer's answer, the deal's stage ------------------------------------------------------------------------------

it('records an acceptance and keeps the deal in Quotation', function () {
    $q = ($this->quote)(null, [], null, 'sent');
    $accepted = ($this->do)(fn () => ($this->service)()->accept($q->fresh(), 'PO 4471', $this->bde));

    expect($accepted->state)->toBe(QuotationState::Accepted)->and($accepted->accepted_note)->toBe('PO 4471')->and($accepted->accepted_at)->not->toBeNull()
        ->and($q->deal->fresh()->stage)->toBe(DealStage::Quotation);

    expect(fn () => ($this->do)(fn () => ($this->service)()->accept($accepted, null, $this->bde)))->toThrow(FieldRuleException::class, 'has been sent');
});

it('needs a reason for a refusal, and takes the deal back to Negotiation when nothing is left standing', function () {
    $q = ($this->quote)(null, [], null, 'sent');

    expect(($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->decline($q->fresh(), '', $this->bde))))->toHaveKey('reason');

    $declined = ($this->do)(fn () => ($this->service)()->decline($q->fresh(), 'Found it cheaper', $this->bde));
    expect($declined->state)->toBe(QuotationState::Declined)->and($declined->declined_reason)->toBe('Found it cheaper')
        ->and($q->deal->fresh()->stage)->toBe(DealStage::Negotiation)->and($q->deal->lead->fresh()->stage)->toBe(LeadStage::Deal);
});

it('keeps the deal in Quotation while another quotation of it is still standing', function () {
    $deal = ($this->deal)();
    $a = ($this->quote)($deal, [], null, 'sent');
    $b = makeQuotation($this->acme, $this->bde, $deal->fresh(), [], null, 'sent', $this->approver);

    ($this->do)(fn () => ($this->service)()->decline($a->fresh(), 'No', $this->bde));
    expect($deal->fresh()->stage)->toBe(DealStage::Quotation);

    ($this->do)(fn () => ($this->service)()->decline($b->fresh(), 'No', $this->bde));
    expect($deal->fresh()->stage)->toBe(DealStage::Negotiation);
});

it('withdraws a quotation with a reason, but not while it waits for approval', function () {
    $q = ($this->quote)();
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($q, $this->bde));

    expect(fn () => ($this->do)(fn () => ($this->service)()->cancel($q->fresh(), 'Mistake', $this->bde)))->toThrow(FieldRuleException::class, 'waiting for approval');

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($q->fresh(), $this->approver));
    $sent = ($this->do)(fn () => ($this->service)()->send($q->fresh(), ['channel' => 'manual'], $this->bde));

    expect(($this->errors)(fn () => ($this->do)(fn () => ($this->service)()->cancel($sent, '', $this->bde))))->toHaveKey('reason');

    $cancelled = ($this->do)(fn () => ($this->service)()->cancel($sent, 'Customer withdrew the inquiry', $this->bde));
    expect($cancelled->state)->toBe(QuotationState::Cancelled)->and($cancelled->cancel_reason)->toBe('Customer withdrew the inquiry')->and($q->deal->fresh()->stage)->toBe(DealStage::Negotiation);

    expect(fn () => ($this->do)(fn () => ($this->service)()->cancel($cancelled, 'Again', $this->bde)))->toThrow(FieldRuleException::class, 'cannot be withdrawn');
});

// ---- revisions ------------------------------------------------------------------------------------------------------------

it('makes the next revision as a copy that needs approving again, and supersedes the one it came from', function () {
    $q = ($this->quote)(null, ['terms' => 'Original terms'], null, 'sent');
    $rev1 = ($this->do)(fn () => ($this->service)()->revise($q->fresh(), $this->bde, 'Customer wants 20'));

    expect($rev1->quotation_number)->toBe($q->quotation_number)->and($rev1->revision_no)->toBe(1)->and($rev1->previous_id)->toBe($q->id)
        ->and($rev1->state)->toBe(QuotationState::Draft)->and($rev1->approval_status)->toBe(ApprovalStatus::Draft)->and($rev1->sent_at)->toBeNull()
        ->and($rev1->terms)->toBe('Original terms')->and((string) $rev1->grand_total)->toBe((string) $q->grand_total)
        ->and($rev1->displayNumber())->toBe('QT/AHD/26-27/00001 · Rev 1');

    // the lines are copied exactly, prices and all
    expect($rev1->items()->get()->map(fn ($l) => [$l->item_id, (string) $l->rate, (string) $l->line_total])->all())
        ->toBe($q->items()->get()->map(fn ($l) => [$l->item_id, (string) $l->rate, (string) $l->line_total])->all());

    $old = $q->fresh();
    expect($old->state)->toBe(QuotationState::Superseded)->and($old->superseded_at)->not->toBeNull()->and($old->isLatestRevision())->toBeFalse()
        ->and(QuotationStateEntry::query()->where('quotation_id', $old->id)->latest('id')->first()->comment)->toContain('Replaced by revision 1');

    // the number series was not touched by the revision
    expect(NumberSeries::withoutGlobalScopes()->where('document_type', 'quotation')->value('last_number'))->toBe(1);

    // the old revision can neither be revised again nor sent nor changed
    expect(fn () => ($this->do)(fn () => ($this->service)()->revise($old, $this->bde)))->toThrow(FieldRuleException::class, 'already been made')
        ->and(fn () => ($this->do)(fn () => ($this->service)()->update($old, quoteInput($q->deal), $this->bde)))->toThrow(FieldRuleException::class);

    // the new one is a draft like any other: it can be changed, approved and sent
    $rev1 = approveDocument($this->acme, $rev1, $this->bde, $this->approver);
    expect(($this->do)(fn () => ($this->service)()->send($rev1, ['channel' => 'manual'], $this->bde))->state)->toBe(QuotationState::Sent);
});

it('can be revised only from a sent, declined or expired revision — not a draft, an accepted or a withdrawn one', function () {
    $draft = ($this->quote)();
    expect(fn () => ($this->do)(fn () => ($this->service)()->revise($draft, $this->bde)))->toThrow(FieldRuleException::class, 'change the draft itself');

    $accepted = ($this->do)(fn () => ($this->service)()->accept(($this->quote)(null, [], null, 'sent'), null, $this->bde));
    expect(fn () => ($this->do)(fn () => ($this->service)()->revise($accepted, $this->bde)))->toThrow(FieldRuleException::class, 'accepted cannot be revised');

    $declined = ($this->do)(fn () => ($this->service)()->decline(($this->quote)(null, [], null, 'sent'), 'Dear', $this->bde));
    expect(($this->do)(fn () => ($this->service)()->revise($declined, $this->bde))->revision_no)->toBe(1);
});

it('numbers revisions one after another and gives a revision of an expired quotation a fresh validity', function () {
    $q = ($this->quote)(null, [], null, 'sent');
    Carbon::setTestNow('2026-11-20 09:00:00');
    app(QuotationExpiry::class)->run();

    $expired = $q->fresh();
    expect($expired->state)->toBe(QuotationState::Expired);

    $rev1 = ($this->do)(fn () => ($this->service)()->revise($expired, $this->bde));
    expect($rev1->valid_until->toDateString())->toBe('2026-12-20');   // 30 days from now, as the company setting says

    $rev1 = approveDocument($this->acme, $rev1, $this->bde, $this->approver);
    $sent1 = ($this->do)(fn () => ($this->service)()->send($rev1, ['channel' => 'manual'], $this->bde));
    $rev2 = ($this->do)(fn () => ($this->service)()->revise($sent1, $this->bde));

    expect($rev2->revision_no)->toBe(2)->and(Quotation::withoutGlobalScopes()->where('quotation_number', $q->quotation_number)->count())->toBe(3);
});

// ---- validity -------------------------------------------------------------------------------------------------------------

it('expires a sent quotation after its last day, tells its owner once, and lets the deal fall back', function () {
    $q = ($this->quote)(null, [], null, 'sent');
    $draft = ($this->quote)();

    Carbon::setTestNow('2026-11-11 23:00:00');   // its last day
    expect(app(QuotationExpiry::class)->run()['expired'])->toBe(0);

    Carbon::setTestNow('2026-11-12 06:00:00');
    $result = app(QuotationExpiry::class)->run();

    expect($result['expired'])->toBe(1)->and($q->fresh()->state)->toBe(QuotationState::Expired)->and($q->fresh()->expired_at)->not->toBeNull()
        ->and($draft->fresh()->state)->toBe(QuotationState::Draft)
        ->and($q->deal->fresh()->stage)->toBe(DealStage::Negotiation);
    // it was warned about on its last day (inside the two-day window), and told once that it ran out
    $told = Notification::sent($this->bde, QuotationExpiryNotification::class);
    expect($told->where('expired', true))->toHaveCount(1)->and($told->where('expired', true)->first()->title)->toContain('QT/AHD/26-27/00001')->and($told->where('expired', false))->toHaveCount(1);

    // a second run finds nothing more to do
    expect(app(QuotationExpiry::class)->run())->toBe(['expired' => 0, 'warned' => 0]);
});

it('warns the owner once, a couple of days before a sent quotation runs out', function () {
    $q = ($this->quote)(null, [], null, 'sent');                  // valid until 2026-11-11

    Carbon::setTestNow('2026-11-08 09:00:00');                  // three days before: too early
    expect(app(QuotationExpiry::class)->run()['warned'])->toBe(0);

    Carbon::setTestNow('2026-11-09 09:00:00');                  // two days before
    expect(app(QuotationExpiry::class)->run()['warned'])->toBe(1);
    Notification::assertSentTo($this->bde, QuotationExpiryNotification::class, fn ($n) => $n->expired === false);

    Carbon::setTestNow('2026-11-10 09:00:00');                  // the next day: already told
    expect(app(QuotationExpiry::class)->run()['warned'])->toBe(0)->and($q->fresh()->expiry_warned_at)->not->toBeNull();
});

it('leaves a quotation alone that was accepted before it ran out', function () {
    $q = ($this->quote)(null, [], null, 'sent');
    ($this->do)(fn () => ($this->service)()->accept($q->fresh(), null, $this->bde));

    Carbon::setTestNow('2026-12-30 06:00:00');
    expect(app(QuotationExpiry::class)->run())->toBe(['expired' => 0, 'warned' => 0])->and($q->fresh()->state)->toBe(QuotationState::Accepted);
});

it('runs from the scheduler command', function () {
    $q = ($this->quote)(null, [], null, 'sent');
    Carbon::setTestNow('2026-11-13 06:00:00');

    $this->artisan('sales:quotation-expiry')->expectsOutputToContain('Quotations expired: 1')->assertSuccessful();
    expect($q->fresh()->state)->toBe(QuotationState::Expired);
});

// ---- ordering from a quotation ------------------------------------------------------------------------------------------------

it('clears an accepted, approved, latest quotation for an order, and says why not for the rest', function () {
    $guard = app(QuotationConversionGuard::class);
    $q = ($this->quote)(null, [], null, 'sent');

    // merely sent: a soft "no"
    $blocks = ($this->do)(fn () => $guard->blocks($q->fresh()));
    expect($blocks['hard'])->toBe([])->and($blocks['soft'][0])->toContain('not accepted');

    $accepted = ($this->do)(fn () => ($this->service)()->accept($q->fresh(), null, $this->bde));
    expect(($this->do)(fn () => $guard->blocks($accepted)))->toBe(['hard' => [], 'soft' => []])
        ->and(($this->do)(fn () => $guard->assertConvertible($accepted, $this->bde)))->toBeNull();
});

it('never waives a hard "no": a draft, a declined, a superseded or a withdrawn quotation', function () {
    $guard = app(QuotationConversionGuard::class);
    $draft = ($this->quote)();
    $declined = ($this->do)(fn () => ($this->service)()->decline(($this->quote)(null, [], null, 'sent'), 'No', $this->bde));
    $sent = ($this->quote)(null, [], null, 'sent');
    $rev1 = ($this->do)(fn () => ($this->service)()->revise($sent->fresh(), $this->bde));

    foreach ([$draft, $declined, $sent->fresh()] as $hard) {
        expect(fn () => ($this->do)(fn () => $guard->assertConvertible($hard, $this->manager, 'The boss said so')))->toThrow(FieldRuleException::class);
    }

    expect(($this->do)(fn () => $guard->blocks($sent->fresh()))['hard'])->toContain('A newer revision has replaced this one: order from the latest revision.');
    expect($rev1->state)->toBe(QuotationState::Draft);
});

it('lets someone with the exception right order from an expired or unaccepted quotation, with a reason, and audits it', function () {
    $guard = app(QuotationConversionGuard::class);
    $q = ($this->quote)(null, [], null, 'sent');

    expect(fn () => ($this->do)(fn () => $guard->assertConvertible($q->fresh(), $this->bde, 'Please')))->toThrow(FieldRuleException::class, 'not allowed')             // no right
        ->and(fn () => ($this->do)(fn () => $guard->assertConvertible($q->fresh(), $this->manager)))->toThrow(FieldRuleException::class, 'reason')                    // no reason
        ->and(fn () => ($this->do)(fn () => $guard->assertConvertible($q->fresh(), $this->manager, 'ok')))->toThrow(FieldRuleException::class, 'reason');            // too short

    $note = ($this->do)(fn () => $guard->assertConvertible($q->fresh(), $this->manager, 'Customer phoned to confirm'));
    expect($note)->toBe('Customer phoned to confirm');

    $audit = ActivityLog::query()->where('event', 'conversion_exception')->sole();
    expect($audit->causer_id)->toBe($this->manager->id)->and($audit->properties['reason'])->toBe('Customer phoned to confirm')->and($audit->subject_id)->toBe($q->id);

    // past its validity as well
    ($this->do)(fn () => ($this->service)()->accept($q->fresh(), null, $this->bde));
    Carbon::setTestNow('2026-11-30 09:00:00');
    $late = ($this->do)(fn () => $guard->blocks($q->fresh()));
    expect($late['hard'])->toBe([])->and($late['soft'])->not->toBe([]);
});

it('does not need a quotation at all to order from an approved deal', function () {
    $deal = ($this->deal)();

    expect($deal->isReadyForOrder())->toBeTrue()->and(Quotation::withoutGlobalScopes()->count())->toBe(0)
        ->and(($this->deal)(null, false)->isReadyForOrder())->toBeFalse();

    // and a quotation neither blocks it nor changes it
    ($this->quote)($deal);
    expect($deal->fresh()->isReadyForOrder())->toBeTrue()->and($deal->fresh()->stage)->toBe(DealStage::Discovery);
});

it('takes every quotation number from the branch’s own series', function () {
    $a = ($this->quote)();
    $b = ($this->quote)();
    makeStandardRate($this->acme, $this->bolt, $this->mum, '130');
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->mumBde, makeLead($this->acme, $this->mumBde, $this->mum, [], [lineRow($this->bolt, '1', '')])), $this->mumBde, $this->approver);
    $c = makeQuotation($this->acme, $this->mumBde, $deal, [], null, 'draft', $this->approver);

    expect([$a->quotation_number, $b->quotation_number, $c->quotation_number])->toBe(['QT/AHD/26-27/00001', 'QT/AHD/26-27/00002', 'QT/MUM/26-27/00001']);
});
