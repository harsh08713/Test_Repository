<?php

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealStageEntry;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadStageEntry;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Notifications\LeadsAssignedNotification;
use App\Modules\CRM\Services\DealService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\NumberSeries;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Notifications\ApprovalRequestedNotification;
use App\Modules\Shared\Notifications\DocumentDecisionNotification;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Converting a qualified lead into a deal, and everything a deal does afterwards: its lines and totals, approval, stages and
 * hand-over. Everything goes through DealService.
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
    $this->mumBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->customer = makeCustomer($this->acme);
    $this->bolt = makeUsableItem($this->acme);
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG')]);
    $this->lostReason = lostReasonId($this->acme);

    // a new lead with two items of interest (10 bolts and 2.5 kg of wire), owned by $bde unless said otherwise
    $this->lead = fn (?array $lines = null, $by = null, array $lead = []) => makeLead($this->acme, $by ?? $this->bde, $this->ahd, $lead,
        $lines ?? [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]);
    $this->qualified = fn (?array $lines = null, $by = null, array $lead = []) => qualify($this->acme, ($this->lead)($lines, $by, $lead), $by ?? $this->bde);

    $this->deals = fn () => app(DealService::class);
    $this->approvals = fn () => app(ApprovalService::class);
    $this->convert = fn (Lead $lead, ?array $input = null, $actor = null) => inCompany($this->acme, fn () => app(DealService::class)->convert($lead, $input ?? dealInput($lead), $actor ?? $this->bde));
    $this->make = fn (?array $lines = null, array $deal = []) => makeDeal($this->acme, $this->bde, ($this->qualified)($lines), $deal);

    // what the service says is wrong with an input, by field
    $this->errors = function (Lead $lead, array $input, $actor = null): array {
        try {
            ($this->convert)($lead, $input, $actor);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Converting a lead
// ======================================================================================================

it('turns a qualified lead into a deal with its lines, its owner and a link back to the lead', function () {
    $lead = ($this->qualified)();
    $interests = $lead->interests()->get();

    $deal = makeDeal($this->acme, $this->bde, $lead);

    expect($deal->deal_number)->toStartWith('DL/')->toContain('AHD')->toContain('26-27')
        ->and($deal->company_id)->toBe($this->acme->id)
        ->and($deal->location_id)->toBe($this->ahd->id)
        ->and($deal->lead_id)->toBe($lead->id)
        ->and($deal->stage)->toBe(DealStage::Discovery)
        ->and($deal->probability)->toBe(10)
        ->and($deal->approval_status)->toBe(ApprovalStatus::Draft)
        ->and($deal->assigned_to)->toBe($this->bde->id)
        ->and($deal->sales_team_id)->toBe($this->team->id)
        ->and($deal->party_name)->toBe($lead->name)
        ->and($deal->contact_person)->toBe($lead->contact_person)
        ->and($deal->mobile)->toBe($lead->mobile)
        ->and($deal->customer_ledger_id)->toBeNull()
        ->and($deal->deal_date->toDateString())->toBe('2026-10-12')
        ->and($deal->expected_close_date->toDateString())->toBe('2026-11-11')
        ->and($deal->created_by)->toBe($this->bde->id);

    // 10 × 125.50 and 2.5 × 125.50 = 313.75: the lines, worked out here
    expect($deal->items)->toHaveCount(2)
        ->and($deal->items[0]->line_no)->toBe(1)
        ->and($deal->items[0]->item_id)->toBe($this->bolt->id)
        ->and($deal->items[0]->unit_id)->toBe($this->bolt->base_unit_id)
        ->and($deal->items[0]->quantity)->toBe('10.000')
        ->and($deal->items[0]->expected_rate)->toBe('125.50')
        ->and($deal->items[0]->amount)->toBe('1255.00')
        ->and($deal->items[0]->lead_interest_id)->toBe($interests[0]->id)       // every line keeps the link to the line of the lead it came from
        ->and($deal->items[1]->amount)->toBe('313.75')
        ->and($deal->items[1]->lead_interest_id)->toBe($interests[1]->id)
        ->and($deal->expected_value)->toBe('1568.75');

    // the first entry of the deal's history is its conversion; the lead has moved to Deal, with its own history
    $history = DealStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('deal_id', $deal->id)->get();
    expect($history)->toHaveCount(1)
        ->and($history[0]->from_stage)->toBeNull()
        ->and($history[0]->to_stage)->toBe(DealStage::Discovery)
        ->and($history[0]->comment)->toContain($lead->lead_number)
        ->and($history[0]->actor_id)->toBe($this->bde->id);

    $lead = $lead->fresh();
    expect($lead->stage)->toBe(LeadStage::Deal)->and($lead->deal->id)->toBe($deal->id);

    $entry = LeadStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->latest('id')->first();
    expect($entry->from_stage)->toBe(LeadStage::Qualified)->and($entry->to_stage)->toBe(LeadStage::Deal)->and($entry->comment)->toContain($deal->deal_number);

    // and it is on record in the audit trail of the lead and of the deal
    expect(ActivityLog::query()->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->where('event', 'converted')->count())->toBe(1)
        ->and(ActivityLog::query()->where('subject_type', $deal->getMorphClass())->where('subject_id', $deal->id)->where('event', 'created')->count())->toBe(1);
});

it('turns an inquiry into a deal for the same customer, keeping the rates it already had', function () {
    $inquiry = makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '10', '100'), lineRow($this->wire, '4', '55.55')]);
    $deal = makeDeal($this->acme, $this->bde, $inquiry);

    expect($deal->customer_ledger_id)->toBe($this->customer->id)
        ->and($deal->party_name)->toBe($this->customer->name)
        ->and($deal->items[0]->expected_rate)->toBe('100.00')
        ->and($deal->items[1]->amount)->toBe('222.20')
        ->and($deal->expected_value)->toBe('1222.20')
        ->and($inquiry->fresh()->stage)->toBe(LeadStage::Deal);
});

it('works out the amounts, the value and every other fact about the deal itself, whatever the client sent', function () {
    $lead = ($this->qualified)();
    $input = dealInput($lead, ['expected_value' => '1', 'stage' => 'won', 'company_id' => '999', 'assigned_to' => (string) $this->bde2->id, 'deal_number' => 'DL/X/1', 'approval_status' => 'approved']);
    $input['items'][0] += ['amount' => '1.00', 'line_no' => '99', 'deal_id' => '5'];

    $deal = ($this->convert)($lead, $input);

    expect($deal->expected_value)->toBe('1568.75')
        ->and($deal->stage)->toBe(DealStage::Discovery)
        ->and($deal->company_id)->toBe($this->acme->id)
        ->and($deal->assigned_to)->toBe($this->bde->id)
        ->and($deal->deal_number)->not->toBe('DL/X/1')
        ->and($deal->approval_status)->toBe(ApprovalStatus::Draft)
        ->and($deal->items[0]->amount)->toBe('1255.00')
        ->and($deal->items[0]->line_no)->toBe(1);
});

it('rounds a line half up to the paisa', function () {
    $lead = ($this->qualified)([lineRow($this->wire, '2.5', '')]);

    expect(($this->convert)($lead, dealInput($lead, [], [['id' => '', 'lead_interest_id' => (string) $lead->interests[0]->id, 'item_id' => (string) $this->wire->id, 'unit_id' => (string) $this->wire->base_unit_id, 'quantity' => '2.5', 'expected_rate' => '33.33']]))
        ->items[0]->amount)->toBe('83.33');   // 83.325

    $other = ($this->qualified)([lineRow($this->wire, '0.001', '')]);
    $deal = ($this->convert)($other, dealInput($other, [], [['id' => '', 'lead_interest_id' => (string) $other->interests[0]->id, 'item_id' => (string) $this->wire->id, 'unit_id' => (string) $this->wire->base_unit_id, 'quantity' => '0.001', 'expected_rate' => '4.99']]));
    expect($deal->items[0]->amount)->toBe('0.00');   // 0.00499 is less than half a paisa
});

it('makes a deal once: a second conversion is refused, names the deal and uses up no number', function () {
    $lead = ($this->qualified)();
    $deal = ($this->convert)($lead);

    $series = fn () => (int) NumberSeries::withoutGlobalScopes()->where('company_id', $this->acme->id)->where('document_type', 'deal')->value('last_number');
    expect($series())->toBe(1);

    expect(fn () => ($this->convert)($lead->fresh()))->toThrow(FieldRuleException::class, "already been converted to deal {$deal->deal_number}");
    expect(fn () => ($this->convert)($lead))->toThrow(FieldRuleException::class, 'already been converted');   // even from a stale copy of the lead

    expect($series())->toBe(1)->and(Deal::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->count())->toBe(1);
});

it('numbers deals per branch, one after another', function () {
    $first = ($this->make)();
    $second = ($this->make)();
    $mumbai = makeDeal($this->acme, $this->mumBde, qualify($this->acme, makeLead($this->acme, $this->mumBde, $this->mum, [], [lineRow($this->bolt, '1', '')]), $this->mumBde));

    $serial = fn (Deal $deal) => (int) substr($deal->deal_number, strrpos($deal->deal_number, '/') + 1);

    expect($serial($second))->toBe($serial($first) + 1)->and($serial($mumbai))->toBe(1)->and($mumbai->deal_number)->toContain('MUM');
});

it('converts only a qualified lead', function () {
    $new = ($this->lead)();
    expect(fn () => ($this->convert)($new, dealInput($new)))->toThrow(FieldRuleException::class, 'Only a qualified lead');

    $contacted = inCompany($this->acme, fn () => app(LeadService::class)->changeStage(($this->lead)(), LeadStage::Contacted, $this->bde));
    expect(fn () => ($this->convert)($contacted, dealInput($contacted)))->toThrow(FieldRuleException::class, 'this one is contacted');

    $lost = inCompany($this->acme, fn () => app(LeadService::class)->changeStage(($this->lead)(), LeadStage::Lost, $this->bde, null, $this->lostReason));
    expect(fn () => ($this->convert)($lost, dealInput($lost)))->toThrow(FieldRuleException::class, 'this one is lost');

    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('needs the lead to have an owner', function () {
    $pool = qualify($this->acme, makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => ''], [lineRow($this->bolt, '5', '')]), $this->manager);
    expect($pool->assigned_to)->toBeNull();

    expect(fn () => ($this->convert)($pool, dealInput($pool), $this->manager))->toThrow(FieldRuleException::class, 'Give the lead an owner');
});

it('lets only someone who works the lead, in a branch they work in, convert it', function () {
    $lead = ($this->qualified)();

    // a colleague with no part in it, a salesperson of another branch, a manager of another team
    expect(fn () => ($this->convert)($lead, dealInput($lead), $this->bde2))->toThrow(FieldRuleException::class, 'not one of yours')
        ->and(fn () => ($this->convert)($lead, dealInput($lead), $this->mumBde))->toThrow(FieldRuleException::class, 'branch you do not work in')
        ->and(fn () => ($this->convert)($lead, dealInput($lead), $this->otherManager))->toThrow(FieldRuleException::class, 'not one of yours');

    // the owner’s manager and an administrator may
    expect(($this->convert)($lead, dealInput($lead), $this->manager)->assigned_to)->toBe($this->bde->id);

    $another = ($this->qualified)();
    expect(($this->convert)($another, dealInput($another), $this->admin)->lead_id)->toBe($another->id);
});

// ======================================================================================================
// The lines
// ======================================================================================================

it('brings every line of the lead across, and says which is missing', function () {
    $lead = ($this->qualified)();
    $input = dealInput($lead);
    array_pop($input['items']);

    $errors = ($this->errors)($lead, $input);

    expect($errors)->toHaveKey('items')->and($errors['items'][0])->toContain('Every item the lead listed has to come across')->toContain($this->wire->name);
    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('lets a line be adjusted and more be added, but not a line of the lead be swapped for another item', function () {
    $lead = ($this->qualified)([lineRow($this->bolt, '10', '')]);
    $row = dealInput($lead)['items'][0];

    $deal = ($this->convert)($lead, dealInput($lead, [], [
        [...$row, 'quantity' => '12', 'expected_rate' => '110'],
        ['id' => '', 'lead_interest_id' => '', 'item_id' => (string) $this->wire->id, 'unit_id' => '', 'quantity' => '1', 'expected_rate' => '500'],
    ]));

    expect($deal->items)->toHaveCount(2)
        ->and($deal->items[0]->quantity)->toBe('12.000')
        ->and($deal->items[0]->lead_interest_id)->not->toBeNull()
        ->and($deal->items[1]->lead_interest_id)->toBeNull()              // added to the deal; it came from no line of the lead
        ->and($deal->items[1]->unit_id)->toBe($this->wire->base_unit_id)   // no unit given: the item’s own
        ->and($deal->expected_value)->toBe('1820.00');

    $other = ($this->qualified)([lineRow($this->bolt, '10', '')]);
    $swapped = dealInput($other)['items'][0];
    $swapped['item_id'] = (string) $this->wire->id;
    $swapped['unit_id'] = (string) $this->wire->base_unit_id;

    expect(($this->errors)($other, dealInput($other, [], [$swapped])))->toHaveKey('items.0.item_id');
});

it('needs an item chosen for a line the lead only described in words', function () {
    $lead = ($this->qualified)([['id' => '', 'item_id' => '', 'description' => 'Some stainless fasteners', 'quantity' => '40', 'unit_id' => '']]);
    $row = dealInput($lead)['items'][0];

    expect($row['item_id'])->toBe('')
        ->and(($this->errors)($lead, dealInput($lead)))->toHaveKey('items.0.item_id');

    $deal = ($this->convert)($lead, dealInput($lead, [], [[...$row, 'item_id' => (string) $this->bolt->id, 'unit_id' => (string) $this->bolt->base_unit_id, 'expected_rate' => '3.5']]));

    expect($deal->items[0]->item_id)->toBe($this->bolt->id)->and($deal->items[0]->lead_interest_id)->toBe($lead->interests[0]->id)->and($deal->expected_value)->toBe('140.00');
});

it('refuses a line that is not a real, approved item in a unit it is sold in, in a quantity the unit allows', function () {
    $lead = ($this->qualified)([lineRow($this->bolt, '10', '')]);
    $row = dealInput($lead)['items'][0];
    $draft = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'Unapproved Gadget']], 'draft');
    $errorsFor = fn (array $over) => ($this->errors)($lead, dealInput($lead, [], [[...$row, ...$over]]));

    expect($errorsFor(['item_id' => (string) $draft->id]))->toHaveKey('items.0.item_id')
        ->and($errorsFor(['item_id' => '99999']))->toHaveKey('items.0.item_id')
        ->and($errorsFor(['unit_id' => (string) unitId($this->acme, 'KG')]))->toHaveKey('items.0.unit_id')
        ->and($errorsFor(['quantity' => '']))->toHaveKey('items.0.quantity')
        ->and($errorsFor(['quantity' => '0']))->toHaveKey('items.0.quantity')
        ->and($errorsFor(['quantity' => '-3']))->toHaveKey('items.0.quantity')
        ->and($errorsFor(['quantity' => '2.5']))->toHaveKey('items.0.quantity')               // Nos cannot be split
        ->and($errorsFor(['expected_rate' => '']))->toHaveKey('items.0.expected_rate')
        ->and($errorsFor(['expected_rate' => '12.345']))->toHaveKey('items.0.expected_rate')
        ->and($errorsFor(['expected_rate' => 'free']))->toHaveKey('items.0.expected_rate')
        ->and($errorsFor(['quantity' => '999999999999', 'expected_rate' => '9999999999999.99']))->toHaveKey('items.0.quantity');   // more than the system can hold

    // the unit of another item in the same family is fine: a Box of bolts
    $box = ($this->convert)($lead, dealInput($lead, [], [[...$row, 'unit_id' => (string) unitId($this->acme, 'BOX'), 'quantity' => '3', 'expected_rate' => '12000']]));
    expect($box->items[0]->unit_id)->toBe(unitId($this->acme, 'BOX'))->and($box->items[0]->amount)->toBe('36000.00');
});

it('refuses a deal with no lines, too many lines, or a line of somebody else’s lead', function () {
    $lead = ($this->qualified)([lineRow($this->bolt, '10', '')]);
    $row = dealInput($lead)['items'][0];

    expect(($this->errors)($lead, dealInput($lead, [], [])))->toHaveKey('items');

    $many = array_fill(0, DealService::MAX_LINES + 1, ['id' => '', 'lead_interest_id' => '', 'item_id' => (string) $this->wire->id, 'unit_id' => '', 'quantity' => '1', 'expected_rate' => '1']);
    expect(($this->errors)($lead, dealInput($lead, [], [$row, ...$many])))->toHaveKey('items');

    $foreign = ($this->lead)([lineRow($this->bolt, '1', '')]);
    $borrowed = [...$row, 'lead_interest_id' => (string) $foreign->interests[0]->id];
    expect(($this->errors)($lead, dealInput($lead, [], [$borrowed])))->toHaveKey('items.0.lead_interest_id');
});

it('refuses a close date in the past or years away, a probability that is not between 1 and 99, and a long remark', function () {
    $lead = ($this->qualified)();
    $errorsFor = fn (array $deal) => ($this->errors)($lead, dealInput($lead, $deal));

    expect($errorsFor(['expected_close_date' => '2026-10-11']))->toHaveKey('deal.expected_close_date')
        ->and($errorsFor(['expected_close_date' => '']))->toHaveKey('deal.expected_close_date')
        ->and($errorsFor(['expected_close_date' => '2040-01-01']))->toHaveKey('deal.expected_close_date')
        ->and($errorsFor(['expected_close_date' => 'soon']))->toHaveKey('deal.expected_close_date')
        ->and($errorsFor(['probability' => '0']))->toHaveKey('deal.probability')
        ->and($errorsFor(['probability' => '100']))->toHaveKey('deal.probability')
        ->and($errorsFor(['probability' => 'likely']))->toHaveKey('deal.probability')
        ->and($errorsFor(['remarks' => str_repeat('x', 2001)]))->toHaveKey('deal.remarks');

    $today = ($this->convert)($lead, dealInput($lead, ['expected_close_date' => '2026-10-12', 'probability' => '35', 'remarks' => 'Hot']));
    expect($today->expected_close_date->toDateString())->toBe('2026-10-12')->and($today->probability)->toBe(35)->and($today->remarks)->toBe('Hot');
});

it('links a deal for a prospect to a customer ledger that is approved and active, and keeps an inquiry’s deal for the inquiry’s customer', function () {
    $lead = ($this->qualified)();
    $draft = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', ['ledger' => ['name' => 'Not yet approved']]);
    $supplier = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'supplier', ['ledger' => ['name' => 'A supplier']], true);

    expect(($this->errors)($lead, dealInput($lead, ['customer_ledger_id' => (string) $draft->id])))->toHaveKey('deal.customer_ledger_id')
        ->and(($this->errors)($lead, dealInput($lead, ['customer_ledger_id' => (string) $supplier->id])))->toHaveKey('deal.customer_ledger_id')
        ->and(($this->errors)($lead, dealInput($lead, ['customer_ledger_id' => (string) makeCustomer($this->globex, 'Foreign')->id])))->toHaveKey('deal.customer_ledger_id');

    expect(($this->convert)($lead, dealInput($lead, ['customer_ledger_id' => (string) $this->customer->id]))->customer_ledger_id)->toBe($this->customer->id);

    $other = makeCustomer($this->acme, 'Another Customer');
    $inquiry = makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '10')]);
    $deal = makeDeal($this->acme, $this->bde, $inquiry, ['customer_ledger_id' => (string) $other->id]);
    expect($deal->customer_ledger_id)->toBe($this->customer->id);   // whatever was sent
});

it('refuses to convert an inquiry whose customer is no longer approved and active', function () {
    $inquiry = qualify($this->acme, makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '10')]), $this->bde);
    DB::table('ledgers')->where('id', $this->customer->id)->update(['status' => 'inactive']);

    expect(fn () => ($this->convert)($inquiry->fresh(), dealInput($inquiry)))->toThrow(FieldRuleException::class, 'no longer approved and active');
});

// ======================================================================================================
// Changing a deal
// ======================================================================================================

it('changes the customer and the lines of a draft deal, keeping the rows it names and renumbering the rest', function () {
    $deal = ($this->make)();
    [$first, $second] = [$deal->items[0], $deal->items[1]];

    $input = ['deal' => ['customer_ledger_id' => (string) $this->customer->id, 'expected_close_date' => '2026-12-31', 'probability' => '40', 'remarks' => 'Revised'], 'items' => [
        // the second row moves up and changes; the first is dropped; a new one comes in
        ['id' => (string) $second->id, 'item_id' => (string) $this->wire->id, 'unit_id' => (string) $this->wire->base_unit_id, 'quantity' => '4', 'expected_rate' => '50'],
        ['id' => '', 'item_id' => (string) $this->bolt->id, 'unit_id' => '', 'quantity' => '20', 'expected_rate' => '5'],
    ]];

    $saved = inCompany($this->acme, fn () => ($this->deals)()->update($deal, $input, $this->bde));

    expect($saved->customer_ledger_id)->toBe($this->customer->id)
        ->and($saved->expected_close_date->toDateString())->toBe('2026-12-31')
        ->and($saved->probability)->toBe(40)
        ->and($saved->items)->toHaveCount(2)
        ->and($saved->items[0]->id)->toBe($second->id)
        ->and($saved->items[0]->line_no)->toBe(1)
        ->and($saved->items[0]->lead_interest_id)->toBe($second->lead_interest_id)   // the link to the lead is kept
        ->and($saved->items[1]->line_no)->toBe(2)
        ->and($saved->expected_value)->toBe('300.00')                                // 4 × 50 + 20 × 5
        ->and($saved->items()->where('id', $first->id)->exists())->toBeFalse();
});

it('does not let a line of another deal be named, or a customer that cannot be used be linked', function () {
    $deal = ($this->make)();
    $other = ($this->make)();

    $input = fn (array $row, array $deal = []) => ['deal' => ['customer_ledger_id' => '', 'expected_close_date' => '2026-12-31', 'probability' => '20', 'remarks' => '', ...$deal], 'items' => [$row]];
    $try = function (array $input) use ($deal) {
        try {
            inCompany($this->acme, fn () => ($this->deals)()->update($deal, $input, $this->bde));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };

    $stolen = ['id' => (string) $other->items[0]->id, 'item_id' => (string) $this->bolt->id, 'unit_id' => '', 'quantity' => '1', 'expected_rate' => '1'];
    $draft = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', ['ledger' => ['name' => 'Not yet approved']]);
    $fine = ['id' => (string) $deal->items[0]->id, 'item_id' => (string) $this->bolt->id, 'unit_id' => '', 'quantity' => '1', 'expected_rate' => '1'];

    expect($try($input($stolen)))->toHaveKey('items.0.id')
        ->and($try($input($fine, ['customer_ledger_id' => (string) $draft->id])))->toHaveKey('deal.customer_ledger_id')
        ->and($other->items()->count())->toBe(2);   // nothing of the other deal was touched
});

it('locks the customer and the lines while the deal waits for approval and once it is approved, but not where it stands', function () {
    $deal = ($this->make)();
    $edit = fn (Deal $d) => inCompany($this->acme, fn () => ($this->deals)()->update($d, dealInput($d->lead, [], [['id' => (string) $d->items[0]->id, 'item_id' => (string) $this->bolt->id, 'unit_id' => '', 'quantity' => '1', 'expected_rate' => '1']]), $this->bde));
    $progress = fn (Deal $d, array $deal) => inCompany($this->acme, fn () => ($this->deals)()->updateProgress($d, ['deal' => $deal], $this->bde));

    inCompany($this->acme, fn () => ($this->approvals)()->submit($deal, $this->bde));
    $deal = $deal->fresh();

    expect(fn () => $edit($deal))->toThrow(FieldRuleException::class, 'waiting for approval');
    // …where it stands can still be brought up to date while it waits, without that needing a new approval
    $moved = $progress($deal, ['expected_close_date' => '2026-12-15', 'probability' => '60', 'remarks' => 'Customer confirmed budget']);
    expect($moved->probability)->toBe(60)->and($moved->approval_status)->toBe(ApprovalStatus::Pending);

    inCompany($this->acme, fn () => ($this->approvals)()->approve($deal->fresh(), $this->approver));
    $deal = $deal->fresh();

    expect($deal->approval_status)->toBe(ApprovalStatus::Approved)
        ->and(fn () => $edit($deal))->toThrow(FieldRuleException::class, 'is approved')
        ->and($progress($deal, ['expected_close_date' => '2026-12-20', 'probability' => '70', 'remarks' => ''])->expected_close_date->toDateString())->toBe('2026-12-20');

    // re-opening it, with a reason, lets the lines be corrected — and it then has to be approved again
    inCompany($this->acme, fn () => ($this->approvals)()->reopen($deal, $this->bde, 'The rate was wrong'));
    $edited = $edit($deal->fresh());
    expect($edited->approval_status)->toBe(ApprovalStatus::Draft)->and($edited->expected_value)->toBe('1.00');
});

it('is signed off with its lines: a change behind the approver’s back stops the approval, a change of stage does not', function () {
    $deal = ($this->make)();
    inCompany($this->acme, fn () => ($this->approvals)()->submit($deal, $this->bde));

    // where it stands is not what is approved
    inCompany($this->acme, fn () => ($this->deals)()->changeStage($deal->fresh(), DealStage::Negotiation, $this->bde));
    DB::table('deals')->where('id', $deal->id)->update(['remarks' => 'Typed afterwards', 'probability' => 80]);

    expect(inCompany($this->acme, fn () => ($this->approvals)()->changedSinceSubmission($deal->fresh())))->toBeFalse();

    // what is approved is
    DB::table('deal_items')->where('deal_id', $deal->id)->where('line_no', 1)->update(['expected_rate' => '1.00', 'amount' => '10.00']);

    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->approve($deal->fresh(), $this->approver)))
        ->toThrow(ApprovalStateException::class, 'changed after it was submitted');
});

it('cannot be sent for approval without lines, or once it is lost', function () {
    $deal = ($this->make)();
    DB::table('deal_items')->where('deal_id', $deal->id)->delete();

    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->submit($deal->fresh(), $this->bde)))->toThrow(ApprovalStateException::class, 'at least one item line');

    $lost = ($this->make)();
    inCompany($this->acme, fn () => ($this->deals)()->changeStage($lost, DealStage::Lost, $this->bde, null, $this->lostReason));
    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->submit($lost->fresh(), $this->bde)))->toThrow(ApprovalStateException::class, 'cannot be sent for approval');
});

it('updates where an open deal stands, within bounds, and refuses it for a deal that is closed', function () {
    $deal = ($this->make)();
    $progress = fn (array $deal2, ?Deal $d = null) => inCompany($this->acme, fn () => ($this->deals)()->updateProgress($d ?? $deal, ['deal' => $deal2], $this->bde));
    $bad = function (array $deal2) use ($progress) {
        try {
            $progress($deal2);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };

    expect($bad(['expected_close_date' => '2026-10-11', 'probability' => '50', 'remarks' => '']))->toHaveKey('deal.expected_close_date')   // before the deal was made
        ->and($bad(['expected_close_date' => '2026-12-01', 'probability' => '0', 'remarks' => '']))->toHaveKey('deal.probability')
        ->and($bad(['expected_close_date' => '2026-12-01', 'probability' => '100', 'remarks' => '']))->toHaveKey('deal.probability')
        ->and($bad(['expected_close_date' => '2026-12-01', 'probability' => '', 'remarks' => '']))->toHaveKey('deal.probability');

    $saved = $progress(['expected_close_date' => '2026-12-01', 'probability' => '65', 'remarks' => 'Site visit done']);
    expect($saved->probability)->toBe(65)->and($saved->remarks)->toBe('Site visit done')->and($saved->expected_value)->toBe($deal->expected_value);

    inCompany($this->acme, fn () => ($this->deals)()->changeStage($deal->fresh(), DealStage::Lost, $this->bde, null, $this->lostReason));
    expect(fn () => $progress(['expected_close_date' => '2026-12-01', 'probability' => '65', 'remarks' => ''], $deal->fresh()))->toThrow(FieldRuleException::class, 'can no longer be changed');
});

// ======================================================================================================
// Stages
// ======================================================================================================

it('moves a deal between discovery and negotiation, its probability following the stage until it has been adjusted', function () {
    $deal = ($this->make)();
    $move = fn (Deal $d, DealStage $to) => inCompany($this->acme, fn () => ($this->deals)()->changeStage($d, $to, $this->bde));

    $deal = $move($deal, DealStage::Negotiation);
    expect($deal->stage)->toBe(DealStage::Negotiation)->and($deal->probability)->toBe(50);

    $deal = $move($deal, DealStage::Discovery);
    expect($deal->stage)->toBe(DealStage::Discovery)->and($deal->probability)->toBe(10);

    // once the salesperson has set their own figure, the stage leaves it alone
    $deal = inCompany($this->acme, fn () => ($this->deals)()->updateProgress($deal, ['deal' => ['expected_close_date' => '2026-12-01', 'probability' => '33', 'remarks' => '']], $this->bde));
    $deal = $move($deal, DealStage::Negotiation);
    expect($deal->probability)->toBe(33);

    $history = DealStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('deal_id', $deal->id)->orderBy('id')->get();
    expect($history->map(fn ($e) => ($e->from_stage?->value ?? '-').'>'.$e->to_stage->value)->all())->toBe(['->discovery', 'discovery>negotiation', 'negotiation>discovery', 'discovery>negotiation']);

    // the lead stays at Deal throughout
    expect($deal->lead->fresh()->stage)->toBe(LeadStage::Deal);
});

it('refuses the moves nobody may make by hand', function () {
    $deal = ($this->make)();
    $try = fn (DealStage $to) => inCompany($this->acme, fn () => ($this->deals)()->changeStage($deal->fresh(), $to, $this->bde));

    expect(fn () => $try(DealStage::Discovery))->toThrow(FieldRuleException::class, 'already discovery')
        ->and(fn () => $try(DealStage::Quotation))->toThrow(FieldRuleException::class, 'cannot be moved to quotation')
        ->and(fn () => $try(DealStage::Won))->toThrow(FieldRuleException::class, 'cannot be moved to won');
});

it('marks a deal lost with a reason, and the lead is lost with it, its follow-ups called off', function () {
    $lead = ($this->qualified)();
    $followup = makeFollowup($this->acme, $this->bde, $lead);
    $deal = makeDeal($this->acme, $this->bde, $lead);
    $lose = fn (?int $reason, ?string $comment = null) => inCompany($this->acme, fn () => ($this->deals)()->changeStage($deal->fresh(), DealStage::Lost, $this->bde, $comment, $reason));

    // a reason is needed, and it has to be one of the company’s
    expect(fn () => $lose(null))->toThrow(ValidationException::class)
        ->and(fn () => $lose(99999))->toThrow(ValidationException::class);

    $lost = $lose($this->lostReason, 'Went with a competitor on price');

    expect($lost->stage)->toBe(DealStage::Lost)
        ->and($lost->probability)->toBe(0)
        ->and($lost->lost_reason_id)->toBe($this->lostReason)
        ->and($lost->lost_remarks)->toBe('Went with a competitor on price')
        ->and($lost->lost_at)->not->toBeNull();

    $lead = $lead->fresh();
    expect($lead->stage)->toBe(LeadStage::Lost)
        ->and($lead->lost_reason_id)->toBe($this->lostReason)
        ->and($lead->next_followup_at)->toBeNull()
        ->and($followup->fresh()->status)->toBe(FollowupStatus::Cancelled);

    $entry = LeadStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->latest('id')->first();
    expect($entry->to_stage)->toBe(LeadStage::Lost)->and($entry->comment)->toContain($deal->deal_number)->and($entry->lost_reason_id)->toBe($this->lostReason);
});

it('brings a lost deal back with a comment, and its lead with it — and the lead cannot be brought back on its own', function () {
    $lead = ($this->qualified)();
    $deal = makeDeal($this->acme, $this->bde, $lead);
    $deal = inCompany($this->acme, fn () => ($this->deals)()->changeStage($deal, DealStage::Lost, $this->bde, null, $this->lostReason));

    // the lead follows its deal: re-opening it by hand is refused, so the two cannot disagree
    expect(fn () => inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lead->fresh(), LeadStage::Qualified, $this->bde, 'Trying again')))
        ->toThrow(FieldRuleException::class, 'Re-open the deal');

    expect(fn () => inCompany($this->acme, fn () => ($this->deals)()->changeStage($deal->fresh(), DealStage::Negotiation, $this->bde)))->toThrow(ValidationException::class);

    $back = inCompany($this->acme, fn () => ($this->deals)()->changeStage($deal->fresh(), DealStage::Negotiation, $this->bde, 'Customer came back with a bigger order'));

    expect($back->stage)->toBe(DealStage::Negotiation)
        ->and($back->probability)->toBe(50)
        ->and($back->lost_reason_id)->toBeNull()->and($back->lost_at)->toBeNull();

    $lead = $lead->fresh();
    expect($lead->stage)->toBe(LeadStage::Deal)->and($lead->lost_reason_id)->toBeNull()->and($lead->lost_at)->toBeNull();

    $kinds = DealStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('deal_id', $deal->id)->orderBy('id')->get()->map(fn ($e) => $e->label())->all();
    expect($kinds)->toBe(['Deal created from the lead', 'Marked as lost', 'Re-opened as Negotiation']);
});

it('lets the documents that follow set the quotation and won stages, but only for an approved deal', function () {
    $deal = ($this->make)();
    $set = fn (DealStage $to, ?Deal $d = null) => inCompany($this->acme, fn () => ($this->deals)()->setStageBySystem($d ?? $deal->fresh(), $to, null, 'By a document'));

    // nothing is quoted or ordered from a deal nobody signed off
    expect(fn () => $set(DealStage::Quotation))->toThrow(FieldRuleException::class, 'has not been approved');

    inCompany($this->acme, function () use ($deal) {
        ($this->approvals)()->submit($deal, $this->bde);
        ($this->approvals)()->approve($deal->fresh(), $this->approver);
    });

    $quoted = $set(DealStage::Quotation);
    expect($quoted->stage)->toBe(DealStage::Quotation)->and($quoted->probability)->toBe(70)->and($deal->lead->fresh()->stage)->toBe(LeadStage::Quotation);

    // its customer and lines stay as they were quoted, and a salesperson cannot take it back by hand
    expect($quoted->isContentEditable())->toBeFalse()
        ->and(fn () => inCompany($this->acme, fn () => ($this->deals)()->changeStage($quoted, DealStage::Negotiation, $this->bde)))->toThrow(FieldRuleException::class);

    // the quotation is withdrawn: back to negotiation
    $again = $set(DealStage::Negotiation, $quoted);
    expect($again->stage)->toBe(DealStage::Negotiation)->and($deal->lead->fresh()->stage)->toBe(LeadStage::Deal);

    $won = $set(DealStage::Won, $again);
    expect($won->stage)->toBe(DealStage::Won)->and($won->probability)->toBe(100)->and($deal->lead->fresh()->stage)->toBe(LeadStage::Won);

    // and nothing leaves Won
    expect(fn () => $set(DealStage::Quotation, $won))->toThrow(FieldRuleException::class)
        ->and(fn () => inCompany($this->acme, fn () => ($this->deals)()->changeStage($won, DealStage::Lost, $this->bde, null, $this->lostReason)))->toThrow(FieldRuleException::class);

    $stages = DealStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('deal_id', $deal->id)->orderBy('id')->get();
    expect($stages->last()->actor_id)->toBeNull();   // written by a document, not a person
});

it('keeps the stage history append-only', function () {
    $deal = ($this->make)();
    $entry = DealStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('deal_id', $deal->id)->first();

    expect(fn () => $entry->forceFill(['comment' => 'edited'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

// ======================================================================================================
// Handing a deal over
// ======================================================================================================

it('hands a deal and its lead to another person together, keeping the history and moving what is planned', function () {
    $lead = ($this->qualified)();
    $followup = makeFollowup($this->acme, $this->bde, $lead);
    $deal = makeDeal($this->acme, $this->bde, $lead);

    $moved = inCompany($this->acme, fn () => ($this->deals)()->reassign($deal, $this->bde2->id, 'Territory re-allocated', $this->manager));

    expect($moved->assigned_to)->toBe($this->bde2->id)
        ->and($moved->sales_team_id)->toBe($this->team->id)
        ->and($lead->fresh()->assigned_to)->toBe($this->bde2->id)
        ->and($followup->fresh()->assigned_to)->toBe($this->bde2->id);

    $transfer = LeadTransfer::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->latest('id')->first();
    expect($transfer->from_user_id)->toBe($this->bde->id)->and($transfer->to_user_id)->toBe($this->bde2->id)->and($transfer->reason)->toBe('Territory re-allocated')->and($transfer->transferred_by)->toBe($this->manager->id);

    Notification::assertSentTo($this->bde2, LeadsAssignedNotification::class);
    expect(ActivityLog::query()->where('subject_type', $deal->getMorphClass())->where('subject_id', $deal->id)->where('event', 'reassigned')->count())->toBe(1);
});

it('hands a deal over only to people and for reasons the transfer rules allow, and only while it is open', function () {
    $deal = ($this->make)();
    $reassign = fn (int $to, string $reason, $by, ?Deal $d = null) => inCompany($this->acme, fn () => ($this->deals)()->reassign($d ?? $deal->fresh(), $to, $reason, $by));

    // the salesperson holding it may hand it only to themselves or someone they manage — which is nobody
    expect(fn () => $reassign($this->bde2->id, 'Because', $this->bde))->toThrow(ValidationException::class)
        ->and(fn () => $reassign($this->bde2->id, '', $this->manager))->toThrow(ValidationException::class)
        ->and(fn () => $reassign($this->bde->id, 'Same person', $this->manager))->toThrow(ValidationException::class)
        ->and(fn () => $reassign($this->mumBde->id, 'Wrong branch', $this->manager))->toThrow(ValidationException::class)
        ->and(fn () => $reassign($this->otherManager->id, 'Not on the team', $this->manager))->toThrow(ValidationException::class);

    expect($deal->fresh()->assigned_to)->toBe($this->bde->id);

    inCompany($this->acme, fn () => ($this->deals)()->changeStage($deal->fresh(), DealStage::Lost, $this->bde, null, $this->lostReason));
    expect(fn () => $reassign($this->bde2->id, 'It is lost', $this->manager))->toThrow(FieldRuleException::class, 'no longer changes hands');
});

it('does not let the lead of a deal be handed over on its own', function () {
    $deal = ($this->make)();

    expect(fn () => inCompany($this->acme, fn () => app(LeadTransferService::class)->transfer([$deal->lead_id], $this->bde2->id, 'Moving it', $this->manager)))
        ->toThrow(ValidationException::class, 'hand the deal over instead');

    expect($deal->lead->fresh()->assigned_to)->toBe($this->bde->id);
});

it('never deletes a lead that has a deal', function () {
    $deal = ($this->make)();

    expect(fn () => inCompany($this->acme, fn () => app(LeadService::class)->delete($deal->lead, $this->manager)))->toThrow(FieldRuleException::class, 'converted to a deal');
});

// ======================================================================================================
// Telling people
// ======================================================================================================

it('tells the approvers when a deal is sent in, and the person who sent it how it was decided', function () {
    $deal = ($this->make)();

    inCompany($this->acme, fn () => ($this->approvals)()->submit($deal, $this->bde));
    Notification::assertSentTo($this->approver, ApprovalRequestedNotification::class);
    Notification::assertNotSentTo($this->bde, ApprovalRequestedNotification::class);

    inCompany($this->acme, fn () => ($this->approvals)()->approve($deal->fresh(), $this->approver, 'Fine'));
    Notification::assertSentTo($this->bde, DocumentDecisionNotification::class);

    expect($deal->approvalUrl())->toBe(route('crm.deals.show', $deal))
        ->and($deal->approvalTitle())->toBe("Deal {$deal->deal_number} — {$deal->party_name}");
});
