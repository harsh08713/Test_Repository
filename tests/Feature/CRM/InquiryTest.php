<?php

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Enums\LeadType;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * An inquiry from an existing customer: a lead that names an approved customer ledger, lists what the customer asked for with
 * the rate they expect, and goes through the approval workflow. Everything goes through LeadService, like any lead.
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
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->customer = makeCustomer($this->acme);
    $this->bolt = makeUsableItem($this->acme);
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG')]);

    // what the service says is wrong with an input, by field
    $this->errors = function (array $input, $actor = null, ?Lead $editing = null): array {
        try {
            inCompany($this->acme, fn () => $editing === null
                ? app(LeadService::class)->create($input, $actor ?? $this->bde)
                : app(LeadService::class)->update($editing, $input, $actor ?? $this->bde));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };

    $this->inquiry = fn (?array $lines = null, array $lead = [], $by = null) => makeInquiry($this->acme, $by ?? $this->bde, $this->ahd, $this->customer, $lines ?? [lineRow($this->bolt)], $lead);
    $this->approvals = fn () => app(ApprovalService::class);
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Entering an inquiry
// ======================================================================================================

it('enters an inquiry for an approved customer, taking the customer’s name, contact and address from the ledger', function () {
    $lead = ($this->inquiry)([lineRow($this->bolt, '10', '125.50'), lineRow($this->wire, '2.5', '33.33')]);

    expect($lead->lead_type)->toBe(LeadType::Existing)
        ->and($lead->isExisting())->toBeTrue()
        ->and($lead->customer_ledger_id)->toBe($this->customer->id)
        ->and($lead->name)->toBe($this->customer->name)
        ->and($lead->contact_person)->toBe('Meera Shah')
        ->and($lead->mobile)->toBe('9876543210')
        ->and($lead->email)->toBe('meera@example.test')
        ->and($lead->address_line1)->toBe('12 Ashram Road')
        ->and($lead->city)->toBe('Ahmedabad')
        ->and($lead->pin_code)->toBe('380009')
        ->and($lead->state_id)->not->toBeNull()
        ->and($lead->lead_number)->toStartWith('LD/')->toContain('AHD')
        ->and($lead->stage)->toBe(LeadStage::New)
        ->and($lead->is_complete)->toBeTrue()
        ->and($lead->approval_status)->toBe(ApprovalStatus::Draft)
        ->and($lead->assigned_to)->toBe($this->bde->id);

    // the value is what the lines come to — 10 × 125.50 + 2.5 × 33.33 (= 83.325, rounded half up to 83.33) — and the rate is kept per line
    expect($lead->expected_value)->toBe('1338.33')
        ->and($lead->interests)->toHaveCount(2)
        ->and($lead->interests[0]->expected_rate)->toBe('125.50')
        ->and($lead->interests[1]->quantity)->toBe('2.500')
        ->and($lead->interests[1]->expected_rate)->toBe('33.33');
});

it('never takes the value, the name or the address from what was typed for an inquiry', function () {
    $lead = ($this->inquiry)([lineRow($this->bolt, '4', '10')], [
        'name' => 'Somebody Else Ltd', 'mobile' => '9123456789', 'address_line1' => 'Nowhere', 'city' => 'Elsewhere', 'expected_value' => '999999', 'pin_code' => '110001',
    ]);

    expect($lead->name)->toBe($this->customer->name)
        ->and($lead->mobile)->toBe('9876543210')
        ->and($lead->address_line1)->toBe('12 Ashram Road')
        ->and($lead->city)->toBe('Ahmedabad')
        ->and($lead->pin_code)->toBe('380009')
        ->and($lead->expected_value)->toBe('40.00');
});

it('lets a customer ask again, with no duplicate warning and no mobile number needed', function () {
    $first = ($this->inquiry)();
    $second = ($this->inquiry)();

    expect($first->id)->not->toBe($second->id);

    // a customer whose ledger has no mobile number (only a desk phone) can still ask
    $desk = ['id' => '', 'name' => 'Front desk', 'designation' => '', 'phone' => '0792345678', 'mobile' => '', 'email' => '', 'is_primary' => true];
    $bare = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', ['ledger' => ['name' => 'Contactless Co'], 'contacts' => [$desk]], true);
    $lead = makeInquiry($this->acme, $this->bde, $this->ahd, $bare, [lineRow($this->bolt)]);

    expect($lead->mobile)->toBeNull()->and($lead->is_complete)->toBeTrue()->and($lead->customer_ledger_id)->toBe($bare->id);
});

it('refuses a customer a transaction may not use', function () {
    $draft = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', ['ledger' => ['name' => 'Draft Customer']]);
    $inactive = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', ['ledger' => ['name' => 'Dormant Customer']], true);
    DB::table('ledgers')->where('id', $inactive->id)->update(['status' => 'inactive']);
    $supplier = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'supplier', ['ledger' => ['name' => 'A Supplier']], true);
    $foreign = makeCustomer($this->globex, 'Foreign Customer');

    foreach ([$draft, $inactive, $supplier, $foreign] as $ledger) {
        $input = leadInput($this->acme, $this->ahd, ['customer_ledger_id' => (string) $ledger->id, 'mobile' => ''], [lineRow($this->bolt)]);

        expect(($this->errors)($input))->toHaveKey('lead.customer_ledger_id');
    }

    expect(($this->errors)(leadInput($this->acme, $this->ahd, ['customer_ledger_id' => '99999', 'mobile' => ''], [lineRow($this->bolt)])))->toHaveKey('lead.customer_ledger_id')
        ->and(Lead::query()->count())->toBe(0);
});

it('needs every line to name an approved item, a unit it is sold in, a quantity and a sensible rate', function () {
    $draftItem = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'Unapproved Gadget']], 'draft');
    $input = fn (array $line) => leadInput($this->acme, $this->ahd, ['customer_ledger_id' => (string) $this->customer->id, 'mobile' => ''], [$line]);

    // an item typed in words has nothing to approve or price
    expect(($this->errors)($input(['description' => 'Some sort of bolts', 'quantity' => '5', 'expected_rate' => '10'])))->toHaveKey('interests.0.item_id')
        // an item that is not approved
        ->and(($this->errors)($input(lineRow($draftItem))))->toHaveKey('interests.0.item_id')
        // a unit the item is not sold in (Kg for a bolt sold in Nos and Boxes)
        ->and(($this->errors)($input(lineRow($this->bolt, '5', '10', ['unit_id' => (string) unitId($this->acme, 'KG')]))))->toHaveKey('interests.0.unit_id')
        // no quantity, a zero quantity, a quantity with too many decimals
        ->and(($this->errors)($input(lineRow($this->bolt, '', '10'))))->toHaveKey('interests.0.quantity')
        ->and(($this->errors)($input(lineRow($this->bolt, '0', '10'))))->toHaveKey('interests.0.quantity')
        ->and(($this->errors)($input(lineRow($this->bolt, '1.2345', '10'))))->toHaveKey('interests.0.quantity')
        // a unit that cannot be split is asked for in whole numbers
        ->and(($this->errors)($input(lineRow($this->bolt, '2.5', '10'))))->toHaveKey('interests.0.quantity')
        // a rate that is not money
        ->and(($this->errors)($input(lineRow($this->bolt, '5', 'cheap'))))->toHaveKey('interests.0.expected_rate')
        ->and(($this->errors)($input(lineRow($this->bolt, '5', '10.999'))))->toHaveKey('interests.0.expected_rate')
        ->and(($this->errors)($input(lineRow($this->bolt, '5', '-5'))))->toHaveKey('interests.0.expected_rate');

    // a unit that can be split takes a fraction
    expect(($this->errors)($input(lineRow($this->wire, '2.5', '10'))))->toBe([]);
});

it('keeps a new lead’s items of interest unpriced, whatever rate was sent with them', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, [], [['id' => '', 'item_id' => (string) $this->bolt->id, 'description' => '', 'quantity' => '5', 'unit_id' => '', 'expected_rate' => '99']]);

    expect($lead->lead_type)->toBe(LeadType::New)
        ->and($lead->customer_ledger_id)->toBeNull()
        ->and($lead->interests[0]->expected_rate)->toBeNull();
});

it('fixes the customer when the inquiry is entered: editing changes the date, the source and the lines, not who it is from', function () {
    $other = makeCustomer($this->acme, 'Another Customer');
    $lead = ($this->inquiry)([lineRow($this->bolt, '10', '100')]);
    $line = $lead->interests[0];

    $input = leadInput($this->acme, $this->ahd, [
        'customer_ledger_id' => (string) $other->id, 'name' => 'Renamed', 'mobile' => '9000000000', 'remarks' => 'Wants delivery before Diwali',
        'lead_source_id' => (string) leadSourceId($this->acme, 'WEBSITE'),
    ], [lineRow($this->bolt, '12', '95', ['id' => (string) $line->id]), lineRow($this->wire, '3', '40')]);

    $saved = inCompany($this->acme, fn () => app(LeadService::class)->update($lead, $input, $this->bde));

    expect($saved->customer_ledger_id)->toBe($this->customer->id)
        ->and($saved->name)->toBe($this->customer->name)
        ->and($saved->mobile)->toBe('9876543210')
        ->and($saved->remarks)->toBe('Wants delivery before Diwali')
        ->and($saved->lead_source_id)->toBe(leadSourceId($this->acme, 'WEBSITE'))
        ->and($saved->interests)->toHaveCount(2)
        ->and($saved->interests[0]->id)->toBe($line->id)
        ->and($saved->expected_value)->toBe('1260.00');   // 12 × 95 + 3 × 40
});

it('keeps what a customer’s name and address were when the inquiry came in, even if the ledger is edited afterwards', function () {
    $lead = ($this->inquiry)();

    DB::table('ledgers')->where('id', $this->customer->id)->update(['name' => 'Shree Ganesh Industries (renamed)']);

    $saved = inCompany($this->acme, fn () => app(LeadService::class)->update($lead->fresh(), leadInput($this->acme, $this->ahd, [], [lineRow($this->bolt)]), $this->bde));

    expect($saved->name)->toBe($this->customer->name)->and($saved->name)->not->toContain('renamed');
});

it('lets a person who may not hand leads out own only what they enter, and one who may give it to a person they manage', function () {
    $mine = ($this->inquiry)([lineRow($this->bolt)], ['assigned_to' => (string) $this->bde2->id]);
    expect($mine->assigned_to)->toBe($this->bde->id);   // a salesperson owns what they enter, whatever was sent

    $given = ($this->inquiry)([lineRow($this->bolt)], ['assigned_to' => (string) $this->bde->id], $this->manager);
    expect($given->assigned_to)->toBe($this->bde->id);
});

// ======================================================================================================
// Approval
// ======================================================================================================

it('can be sent for approval only when every line is complete and the customer is still usable', function () {
    $unpriced = ($this->inquiry)([lineRow($this->bolt, '10', '')]);
    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->submit($unpriced, $this->bde)))
        ->toThrow(ApprovalStateException::class, 'Line 1 needs');

    $empty = ($this->inquiry)([]);
    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->submit($empty, $this->bde)))
        ->toThrow(ApprovalStateException::class, 'List at least one item');

    $other = makeCustomer($this->acme, 'Soon Dormant');
    $dormant = makeInquiry($this->acme, $this->bde, $this->ahd, $other, [lineRow($this->bolt)]);
    DB::table('ledgers')->where('id', $other->id)->update(['status' => 'inactive']);
    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->submit($dormant->fresh(), $this->bde)))
        ->toThrow(ApprovalStateException::class, 'no longer approved and active');
});

it('goes through approval, locks while it waits and once it is approved, and can be re-opened with a reason', function () {
    $lead = ($this->inquiry)();

    inCompany($this->acme, function () use ($lead) {
        ($this->approvals)()->submit($lead, $this->bde);
    });
    $lead = $lead->fresh();
    expect($lead->approval_status)->toBe(ApprovalStatus::Pending);

    // locked while it waits
    expect(fn () => inCompany($this->acme, fn () => app(LeadService::class)->update($lead, leadInput($this->acme, $this->ahd, ['remarks' => 'x'], [lineRow($this->bolt)]), $this->bde)))
        ->toThrow(FieldRuleException::class, 'waiting for approval');
    expect(fn () => inCompany($this->acme, fn () => app(LeadService::class)->delete($lead, $this->manager)))
        ->toThrow(FieldRuleException::class, 'waiting for approval or approved');

    inCompany($this->acme, fn () => ($this->approvals)()->approve($lead, $this->approver));
    $lead = $lead->fresh();

    expect($lead->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($lead->isReadyForQuotation())->toBeTrue()
        ->and($lead->approved_by)->toBe($this->approver->id);

    // locked once approved — but its stage still moves, because working it is not part of what was approved
    expect(fn () => inCompany($this->acme, fn () => app(LeadService::class)->update($lead, leadInput($this->acme, $this->ahd, ['remarks' => 'x'], [lineRow($this->bolt)]), $this->bde)))
        ->toThrow(FieldRuleException::class, 'is approved');
    $moved = inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lead, LeadStage::Qualified, $this->bde));
    expect($moved->stage)->toBe(LeadStage::Qualified)->and($moved->approval_status)->toBe(ApprovalStatus::Approved);

    // re-opening needs a reason, goes back to draft, and the lead is unusable for a quotation until it is approved again
    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->reopen($moved, $this->bde, ' ')))->toThrow(ApprovalStateException::class);
    inCompany($this->acme, fn () => ($this->approvals)()->reopen($moved, $this->bde, 'Quantity was wrong'));
    $moved = $moved->fresh();

    expect($moved->approval_status)->toBe(ApprovalStatus::Draft)->and($moved->isReadyForQuotation())->toBeFalse();
    $edited = inCompany($this->acme, fn () => app(LeadService::class)->update($moved, leadInput($this->acme, $this->ahd, [], [lineRow($this->bolt, '20', '120')]), $this->bde));
    expect($edited->expected_value)->toBe('2400.00');
});

it('is returned by a rejection and can be corrected and sent again', function () {
    $lead = ($this->inquiry)();

    inCompany($this->acme, function () use ($lead) {
        ($this->approvals)()->submit($lead, $this->bde);
        ($this->approvals)()->reject($lead->fresh(), $this->approver, 'The rate is too low');
    });
    $lead = $lead->fresh();
    expect($lead->approval_status)->toBe(ApprovalStatus::Rejected);

    $edited = inCompany($this->acme, fn () => app(LeadService::class)->update($lead, leadInput($this->acme, $this->ahd, [], [lineRow($this->bolt, '10', '140')]), $this->bde));
    inCompany($this->acme, function () use ($edited) {
        ($this->approvals)()->submit($edited, $this->bde);
        ($this->approvals)()->approve($edited->fresh(), $this->approver);
    });

    expect($edited->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});

it('cannot be approved after what was submitted was changed behind the approver’s back', function () {
    $lead = ($this->inquiry)();

    inCompany($this->acme, fn () => ($this->approvals)()->submit($lead, $this->bde));
    DB::table('lead_interests')->where('lead_id', $lead->id)->update(['expected_rate' => '1.00']);

    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->approve($lead->fresh(), $this->approver)))
        ->toThrow(ApprovalStateException::class, 'changed after it was submitted');
});

it('does not let the person who sent it for approval decide it', function () {
    $lead = ($this->inquiry)([lineRow($this->bolt)], [], $this->manager);

    inCompany($this->acme, fn () => ($this->approvals)()->submit($lead, $this->manager));

    // a manager holds lead.approve, but not for what they submitted themselves
    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->approve($lead->fresh(), $this->manager)))->toThrow(AuthorizationException::class);
});

it('never sends a new lead for approval', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    expect(fn () => inCompany($this->acme, fn () => ($this->approvals)()->submit($lead, $this->bde)))
        ->toThrow(ApprovalStateException::class, 'Only an inquiry from an existing customer');
    expect($lead->fresh()->approval_status)->toBe(ApprovalStatus::Draft);
});

// ======================================================================================================
// Who may open it
// ======================================================================================================

it('lets an approver open an inquiry that waits for their decision, and afterwards the ones they decided — and nobody else’s', function () {
    $lead = ($this->inquiry)();
    $access = app(LeadAccess::class);
    $seen = fn ($user) => inCompany($this->acme, fn () => $access->sees($user, $lead->fresh()));

    // before it is sent in, an approver has no business with it
    expect($seen($this->approver))->toBeFalse();

    inCompany($this->acme, fn () => ($this->approvals)()->submit($lead, $this->bde));
    expect($seen($this->approver))->toBeTrue()
        ->and($seen($this->bde2))->toBeFalse()          // a colleague with no part in it
        ->and($seen($this->telecaller))->toBeFalse();

    // …and the list and a page of rows agree with the single check
    $listed = fn ($user) => inCompany($this->acme, fn () => $access->restrict(Lead::query(), $user)->pluck('id')->all());
    $openable = fn ($user) => inCompany($this->acme, fn () => array_keys($access->openable($user, [$lead->fresh()])));

    expect($listed($this->approver))->toContain($lead->id)
        ->and($openable($this->approver))->toBe([$lead->id])
        ->and($listed($this->bde2))->not->toContain($lead->id)
        ->and($openable($this->bde2))->toBe([]);

    // once decided, it stays one the approver can open — to see what they decided
    inCompany($this->acme, fn () => ($this->approvals)()->approve($lead->fresh(), $this->approver));
    expect($seen($this->approver))->toBeTrue()
        ->and($listed($this->approver))->toContain($lead->id);

    // …but another approver who never decided it, with nothing waiting, has no way in
    $later = makeUser(['Approver'], [$this->acme]);
    expect($seen($later))->toBeFalse();
});

it('does not let an approver open a new lead, and keeps the policy to inquiries', function () {
    $new = makeLead($this->acme, $this->bde, $this->ahd);
    $inquiry = ($this->inquiry)();
    inCompany($this->acme, fn () => ($this->approvals)()->submit($inquiry, $this->bde));

    inCompany($this->acme, function () use ($new, $inquiry) {
        expect(Gate::forUser($this->approver)->allows('view', $new->fresh()))->toBeFalse()
            ->and(Gate::forUser($this->approver)->allows('view', $inquiry->fresh()))->toBeTrue()
            ->and(Gate::forUser($this->approver)->allows('approve', $inquiry->fresh()))->toBeTrue()
            ->and(Gate::forUser($this->approver)->allows('approve', $new->fresh()))->toBeFalse()   // a new lead is never decided
            ->and(Gate::forUser($this->bde)->allows('approve', $inquiry->fresh()))->toBeFalse()    // the permission is the approver’s
            ->and(Gate::forUser($this->bde)->allows('submit', $inquiry->fresh()))->toBeFalse()     // already sent in
            ->and(Gate::forUser($this->bde)->allows('submit', $new->fresh()))->toBeFalse();        // a new lead is not sent in
    });
});

it('needs the permission to read customers as well as to enter leads, to enter an inquiry', function () {
    inCompany($this->acme, function () {
        expect(Gate::forUser($this->bde)->allows('createInquiry', Lead::class))->toBeTrue()
            ->and(Gate::forUser($this->manager)->allows('createInquiry', Lead::class))->toBeTrue()
            ->and(Gate::forUser($this->telecaller)->allows('create', Lead::class))->toBeTrue()
            ->and(Gate::forUser($this->telecaller)->allows('createInquiry', Lead::class))->toBeFalse()   // a tele-caller cannot read the customer ledger
            ->and(Gate::forUser($this->approver)->allows('createInquiry', Lead::class))->toBeFalse();
    });
});

it('lets the owner’s manager delete a draft inquiry, the owner re-open an approved one, and nobody else', function () {
    $lead = ($this->inquiry)();

    inCompany($this->acme, function () use ($lead) {
        expect(Gate::forUser($this->manager)->allows('delete', $lead->fresh()))->toBeTrue()
            ->and(Gate::forUser($this->bde)->allows('delete', $lead->fresh()))->toBeFalse();    // a salesperson holds no delete right
        expect(Gate::forUser($this->bde)->allows('reopenApproval', $lead->fresh()))->toBeFalse();   // not approved yet

        ($this->approvals)()->submit($lead, $this->bde);
        ($this->approvals)()->approve($lead->fresh(), $this->approver);

        expect(Gate::forUser($this->manager)->allows('delete', $lead->fresh()))->toBeFalse()   // approved: locked
            ->and(Gate::forUser($this->manager)->allows('reopenApproval', $lead->fresh()))->toBeTrue()
            ->and(Gate::forUser($this->bde)->allows('reopenApproval', $lead->fresh()))->toBeTrue()
            ->and(Gate::forUser($this->bde2)->allows('reopenApproval', $lead->fresh()))->toBeFalse()
            ->and(Gate::forUser($this->approver)->allows('reopenApproval', $lead->fresh()))->toBeFalse();
    });
});
