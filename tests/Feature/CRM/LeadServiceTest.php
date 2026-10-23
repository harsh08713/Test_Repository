<?php

use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadInterest;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStageEntry;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Notifications\LeadsAssignedNotification;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->globexBranch = makeLocation($this->globex, 'NYC');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->otherManager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->mumBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->service = fn () => app(LeadService::class);

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
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Entering a lead
// ======================================================================================================

it('enters a lead: numbered from its branch, owned by the person who entered it, new, with its history started', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '+91 98765 43210', 'email' => 'Meera@Example.TEST', 'expected_value' => '2,50,000']);

    expect($lead->lead_number)->toStartWith('LD/')->toContain('AHD')
        ->and($lead->company_id)->toBe($this->acme->id)
        ->and($lead->location_id)->toBe($this->ahd->id)
        ->and($lead->mobile)->toBe('9876543210')                      // stored as its ten digits
        ->and($lead->email)->toBe('meera@example.test')
        ->and($lead->expected_value)->toBe('250000.00')
        ->and($lead->stage)->toBe(LeadStage::New)
        ->and($lead->entry_channel)->toBe(LeadChannel::Manual)
        ->and($lead->is_complete)->toBeTrue()
        ->and($lead->assigned_to)->toBe($this->bde->id)
        ->and($lead->sales_team_id)->toBe($this->team->id)             // the team the owner is on today
        ->and($lead->created_by)->toBe($this->bde->id);

    $stages = LeadStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->get();
    expect($stages)->toHaveCount(1)
        ->and($stages[0]->from_stage)->toBeNull()
        ->and($stages[0]->to_stage)->toBe(LeadStage::New)
        ->and($stages[0]->actor_id)->toBe($this->bde->id);

    $transfers = LeadTransfer::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->get();
    expect($transfers)->toHaveCount(1)
        ->and($transfers[0]->from_user_id)->toBeNull()
        ->and($transfers[0]->to_user_id)->toBe($this->bde->id)
        ->and($transfers[0]->transferred_by)->toBe($this->bde->id);

    // the person who entered it is not told about their own lead
    Notification::assertNothingSent();
});

it('numbers leads per branch, one after another', function () {
    $first = makeLead($this->acme, $this->bde, $this->ahd);
    $second = makeLead($this->acme, $this->bde, $this->ahd);
    $mumbai = makeLead($this->acme, $this->mumBde, $this->mum);

    $serial = fn (Lead $lead) => (int) substr($lead->lead_number, strrpos($lead->lead_number, '/') + 1);

    expect($serial($second))->toBe($serial($first) + 1)
        ->and($serial($mumbai))->toBe(1)
        ->and($mumbai->lead_number)->toContain('MUM')
        ->and($first->lead_number)->not->toBe($second->lead_number);
});

it('refuses a lead without the essentials, and says what is wrong in plain words', function () {
    $input = leadInput($this->acme, $this->ahd, ['name' => '  ', 'mobile' => '', 'lead_source_id' => '', 'email' => 'not-an-address', 'expected_value' => '12.345', 'pin_code' => '12', 'lead_date' => '2026-10-13']);

    $errors = ($this->errors)($input);

    expect($errors)->toHaveKeys(['lead.name', 'lead.mobile', 'lead.lead_source_id', 'lead.email', 'lead.expected_value', 'lead.pin_code', 'lead.lead_date'])
        ->and($errors['lead.mobile'][0])->toContain('mobile number is needed')
        ->and($errors['lead.lead_date'][0])->toBe('The lead date cannot be in the future.')
        ->and(Lead::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('accepts only a real Indian mobile number', function (string $mobile, bool $ok) {
    $errors = ($this->errors)(leadInput($this->acme, $this->ahd, ['mobile' => $mobile]));

    expect(isset($errors['lead.mobile']))->toBe(! $ok);
})->with([
    'ten digits' => ['9876543210', true],
    'with +91 and spaces' => ['+91 98765 43210', true],
    'with a leading zero' => ['09876543210', true],
    'nine digits' => ['987654321', false],
    'starts with 5' => ['5876543210', false],
    'letters' => ['98765abcde', false],
]);

it('lets only the branches the person works in be chosen', function () {
    // the Ahmedabad salesperson cannot put a lead in Mumbai, nor in another company's branch
    expect(($this->errors)(leadInput($this->acme, $this->mum)))->toHaveKey('lead.location_id')
        ->and(($this->errors)(leadInput($this->acme, $this->globexBranch)))->toHaveKey('lead.location_id');

    // …even when nobody is signed in around the call (a background import): it is asked about the actor
    $this->actingAs($this->bde);
    expect(($this->errors)(leadInput($this->acme, $this->mum)))->toHaveKey('lead.location_id');

    // a switched-off branch is not offered either
    $this->ahd->forceFill(['status' => 'inactive'])->save();
    expect(($this->errors)(leadInput($this->acme, $this->ahd)))->toHaveKey('lead.location_id');
});

it('takes the source only from the company’s own active sources', function () {
    $foreign = leadSourceId($this->globex);
    expect(($this->errors)(leadInput($this->acme, $this->ahd, ['lead_source_id' => (string) $foreign])))->toHaveKey('lead.lead_source_id');

    LeadSource::withoutGlobalScopes(Tenancy::SCOPES)->whereKey(leadSourceId($this->acme, 'WEBSITE'))->update(['status' => 'inactive']);
    expect(($this->errors)(leadInput($this->acme, $this->ahd, ['lead_source_id' => (string) leadSourceId($this->acme, 'WEBSITE')])))->toHaveKey('lead.lead_source_id');
});

// ======================================================================================================
// Duplicates: warned about, not blocked
// ======================================================================================================

it('warns when an open lead already has the mobile number, and saves once the person says it is a separate inquiry', function () {
    $first = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '9876543210']);

    // somebody else, in another branch, types the same number in another shape
    $input = leadInput($this->acme, $this->mum, ['mobile' => '+91-98765-43210', 'name' => 'Ganesh Traders (Mumbai)']);
    $errors = ($this->errors)($input, $this->mumBde);

    expect($errors)->toHaveKey('lead.mobile')
        ->and($errors['lead.mobile'][0])->toContain($first->lead_number)->toContain('New')->toContain($this->bde->name)->toContain('separate lead');

    $input['lead']['acknowledge_duplicate'] = true;
    $second = inCompany($this->acme, fn () => app(LeadService::class)->create($input, $this->mumBde));

    expect($second->exists)->toBeTrue()->and($second->mobile)->toBe('9876543210')->and($second->id)->not->toBe($first->id);
});

it('does not count a lead that is lost or won as a duplicate, nor the lead being edited', function () {
    $lost = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '9876543210']);
    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lost, LeadStage::Lost, $this->bde, null, lostReasonId($this->acme)));

    // the number is free again
    $again = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '9876543210']);
    expect($again->exists)->toBeTrue();

    // editing a lead does not trip over itself
    $input = leadInput($this->acme, $this->ahd, ['mobile' => '9876543210', 'name' => 'Renamed Traders']);
    expect(($this->errors)($input, $this->bde, $again))->toBe([])
        ->and($again->fresh()->name)->toBe('Renamed Traders');

    // the duplicate list still shows the closed one, so the person knows the history
    $found = inCompany($this->acme, fn () => app(LeadService::class)->duplicates('98765 43210', $again->id));
    expect($found->pluck('id')->all())->toBe([$lost->id]);
});

it('looks for duplicates in the whole company but never in another one', function () {
    makeLead($this->acme, $this->mumBde, $this->mum, ['mobile' => '9876543210']);
    $globexUser = makeUser(['BDE / Field Salesperson'], [$this->globex]);

    // signed in as the Ahmedabad salesperson, who cannot open the Mumbai lead: the warning still sees it
    $this->actingAs($this->bde);
    $found = app(LeadService::class)->duplicates('9876543210');
    expect($found)->toHaveCount(1)->and($found[0]->location->name)->toBe('MUM Branch');

    // another company has its own leads
    $this->actingAs($globexUser);
    app(TenantContext::class)->flush();
    expect(app(LeadService::class)->duplicates('9876543210'))->toHaveCount(0);
});

// ======================================================================================================
// Address: the PIN code fills what it can
// ======================================================================================================

it('fills the state, district and block from a PIN code the master knows', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['pin_code' => '380001']);

    expect($lead->state_id)->toBe($this->geo['gj']->id)
        ->and($lead->district_id)->toBe($this->geo['ahmedabad']->id)
        ->and($lead->block_id)->toBe($this->geo['daskroi']->id);
});

it('fills only what is certain when a PIN code covers two blocks, and nothing for a PIN it does not know', function () {
    $ambiguous = makeLead($this->acme, $this->bde, $this->ahd, ['pin_code' => '382110']);
    $unknown = makeLead($this->acme, $this->bde, $this->ahd, ['pin_code' => '999999']);

    expect($ambiguous->district_id)->toBe($this->geo['ahmedabad']->id)->and($ambiguous->block_id)->toBeNull()
        ->and($unknown->state_id)->toBeNull()->and($unknown->district_id)->toBeNull()->and($unknown->pin_code)->toBe('999999');
});

it('refuses an address that contradicts itself', function () {
    // a Surat PIN with Maharashtra chosen
    $errors = ($this->errors)(leadInput($this->acme, $this->ahd, ['pin_code' => '395003', 'state_id' => (string) $this->geo['mh']->id]));
    expect($errors)->toHaveKey('lead.pin_code')->and($errors['lead.pin_code'][0])->toContain('Gujarat');

    // a block that is not in the chosen district
    $errors = ($this->errors)(leadInput($this->acme, $this->ahd, ['district_id' => (string) $this->geo['surat']->id, 'block_id' => (string) $this->geo['haveli']->id]));
    expect($errors)->toHaveKey('lead.block_id');

    // a district of another state
    $errors = ($this->errors)(leadInput($this->acme, $this->ahd, ['state_id' => (string) $this->geo['mh']->id, 'district_id' => (string) $this->geo['surat']->id]));
    expect($errors)->toHaveKey('lead.district_id');
});

it('works the wider place out from the narrower one', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['block_id' => (string) $this->geo['choryasi']->id]);

    expect($lead->district_id)->toBe($this->geo['surat']->id)->and($lead->state_id)->toBe($this->geo['gj']->id);
});

it('never accepts another company’s geography', function () {
    $foreignState = stateId($this->globex);

    expect(($this->errors)(leadInput($this->acme, $this->ahd, ['state_id' => (string) $foreignState])))->toHaveKey('lead.state_id');
});

// ======================================================================================================
// Ownership when the lead is entered
// ======================================================================================================

it('makes whoever cannot hand leads out the owner of what they enter, whatever was sent', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['assigned_to' => (string) $this->bde2->id]);

    expect($lead->assigned_to)->toBe($this->bde->id);
});

it('lets a manager give a new lead to themselves, to their team, or to nobody yet', function () {
    $own = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => (string) $this->manager->id]);
    $team = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => (string) $this->bde->id]);
    $pool = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => '']);

    expect($own->assigned_to)->toBe($this->manager->id)
        ->and($team->assigned_to)->toBe($this->bde->id)->and($team->sales_team_id)->toBe($this->team->id)
        ->and($pool->assigned_to)->toBeNull()->and($pool->sales_team_id)->toBeNull()
        ->and(LeadTransfer::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $pool->id)->count())->toBe(0);

    // the team member is told; the manager is not told about their own
    Notification::assertSentTo($this->bde, LeadsAssignedNotification::class, fn ($n) => $n->count === 1 && str_contains($n->firstTitle, $team->lead_number));
    Notification::assertNotSentTo($this->manager, LeadsAssignedNotification::class);
});

it('stops a manager giving a lead to someone they do not manage, or who does not work in the branch', function () {
    // bde2 is on nobody's team
    $errors = ($this->errors)(leadInput($this->acme, $this->ahd, ['assigned_to' => (string) $this->bde2->id]), $this->manager);
    expect($errors)->toHaveKey('lead.assigned_to')->and($errors['lead.assigned_to'][0])->toContain('teams you manage');

    // the team's own salesperson works in Ahmedabad only
    $errors = ($this->errors)(leadInput($this->acme, $this->mum, ['assigned_to' => (string) $this->bde->id]), $this->manager);
    expect($errors)->toHaveKey('lead.assigned_to')->and($errors['lead.assigned_to'][0])->toContain('branch');

    // an administrator may give it to anyone who works there
    $lead = makeLead($this->acme, $this->admin, $this->ahd, ['assigned_to' => (string) $this->bde2->id]);
    expect($lead->assigned_to)->toBe($this->bde2->id);

    // …but not to a stranger to the company
    $outsider = makeUser(['BDE / Field Salesperson'], [$this->globex]);
    expect(($this->errors)(leadInput($this->acme, $this->ahd, ['assigned_to' => (string) $outsider->id]), $this->admin))->toHaveKey('lead.assigned_to');
});

// ======================================================================================================
// Items of interest
// ======================================================================================================

it('lists what the lead is interested in: items from the master, or a description in words', function () {
    $purchase = makeUser(['Purchase User'], [$this->acme]);
    $item = makeItem($this->acme, $purchase, [], 'approved');

    $lead = makeLead($this->acme, $this->bde, $this->ahd, [], [
        ['id' => '', 'item_id' => (string) $item->id, 'description' => '', 'quantity' => '5,000', 'unit_id' => ''],
        ['id' => '', 'item_id' => '', 'description' => 'Stainless fasteners, mixed sizes', 'quantity' => '', 'unit_id' => ''],
        ['id' => '', 'item_id' => '', 'description' => '  ', 'quantity' => '', 'unit_id' => ''],   // an untouched row of the form
    ]);

    $rows = LeadInterest::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->item_id)->toBe($item->id)->and($rows[0]->quantity)->toBe('5000.000')->and($rows[0]->unit_id)->toBe($item->base_unit_id)   // the item's own unit
        ->and($rows[1]->item_id)->toBeNull()->and($rows[1]->description)->toBe('Stainless fasteners, mixed sizes');
});

it('offers only approved, active items, and only the units an item is sold in', function () {
    $purchase = makeUser(['Purchase User'], [$this->acme]);
    $draft = makeItem($this->acme, $purchase, ['item' => ['name' => 'Draft Washer']], 'draft');
    $approved = makeItem($this->acme, $purchase, [], 'approved');
    $row = fn (array $over) => [['id' => '', 'item_id' => '', 'description' => '', 'quantity' => '', 'unit_id' => '', ...$over]];

    expect(($this->errors)(leadInput($this->acme, $this->ahd, [], $row(['item_id' => (string) $draft->id]))))->toHaveKey('interests.0.item_id')
        ->and(($this->errors)(leadInput($this->acme, $this->ahd, [], $row(['item_id' => (string) $approved->id, 'unit_id' => (string) unitId($this->acme, 'KG')]))))->toHaveKey('interests.0.unit_id')
        ->and(($this->errors)(leadInput($this->acme, $this->ahd, [], $row(['item_id' => (string) $approved->id, 'quantity' => '0']))))->toHaveKey('interests.0.quantity')
        ->and(($this->errors)(leadInput($this->acme, $this->ahd, [], $row(['quantity' => '5']))))->toHaveKey('interests.0.description');

    // the box the item is also sold in is fine
    $lead = makeLead($this->acme, $this->bde, $this->ahd, [], $row(['item_id' => (string) $approved->id, 'unit_id' => (string) unitId($this->acme, 'BOX'), 'quantity' => '12.5']));
    expect($lead->interests()->first()->unit_id)->toBe(unitId($this->acme, 'BOX'));
});

it('edits, adds and removes interests by their row, and ignores a row id that is not the lead’s', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, [], [
        ['id' => '', 'item_id' => '', 'description' => 'Bolts', 'quantity' => '10', 'unit_id' => ''],
        ['id' => '', 'item_id' => '', 'description' => 'Nuts', 'quantity' => '', 'unit_id' => ''],
    ]);
    $other = makeLead($this->acme, $this->bde, $this->ahd, [], [['id' => '', 'item_id' => '', 'description' => 'Somebody else’s', 'quantity' => '', 'unit_id' => '']]);
    [$bolts, $nuts] = $lead->interests()->get()->all();
    $foreignRow = $other->interests()->first();

    $input = leadInput($this->acme, $this->ahd, ['mobile' => $lead->mobile], [
        ['id' => (string) $bolts->id, 'item_id' => '', 'description' => 'Bolts M10', 'quantity' => '25', 'unit_id' => ''],
        ['id' => '', 'item_id' => '', 'description' => 'Washers', 'quantity' => '', 'unit_id' => ''],
    ]);
    inCompany($this->acme, fn () => app(LeadService::class)->update($lead, $input, $this->bde));

    $rows = $lead->interests()->get();
    expect($rows->pluck('description')->all())->toBe(['Bolts M10', 'Washers'])
        ->and($rows[0]->id)->toBe($bolts->id)->and($rows[0]->quantity)->toBe('25.000')
        ->and(LeadInterest::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($nuts->id)->exists())->toBeFalse();

    // a row id borrowed from another lead is refused, and that lead's row is untouched
    $input['interests'] = [['id' => (string) $foreignRow->id, 'item_id' => '', 'description' => 'Hijacked', 'quantity' => '', 'unit_id' => '']];
    expect(($this->errors)($input, $this->bde, $lead))->toHaveKey('interests.0.id')
        ->and($foreignRow->fresh()->description)->toBe('Somebody else’s');
});

// ======================================================================================================
// Editing
// ======================================================================================================

it('keeps the branch, the number and the owner when a lead is edited', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    $input = leadInput($this->acme, $this->mum, ['mobile' => $lead->mobile, 'name' => 'New name', 'assigned_to' => (string) $this->bde2->id, 'location_id' => (string) $this->mum->id]);
    $saved = inCompany($this->acme, fn () => app(LeadService::class)->update($lead, $input, $this->bde));

    expect($saved->name)->toBe('New name')
        ->and($saved->location_id)->toBe($this->ahd->id)
        ->and($saved->lead_number)->toBe($lead->lead_number)
        ->and($saved->assigned_to)->toBe($this->bde->id)
        ->and($saved->updated_by)->toBe($this->bde->id);
});

it('records who changed what in the audit log', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $this->actingAs($this->bde);

    app(LeadService::class)->update($lead, leadInput($this->acme, $this->ahd, ['mobile' => $lead->mobile, 'name' => 'Audited Traders']), $this->bde);

    $entry = ActivityLog::withoutGlobalScopes(Tenancy::SCOPES)->where('subject_type', 'lead')->where('subject_id', $lead->id)->where('event', 'updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->bde->id)
        ->and($entry->properties['attributes']['name'])->toBe('Audited Traders')
        ->and((int) $entry->getAttribute('company_id'))->toBe($this->acme->id);
});

// ======================================================================================================
// Stages
// ======================================================================================================

it('moves a lead between new, contacted and qualified, and writes every move down', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $move = fn (LeadStage $to, ?string $comment = null) => inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lead, $to, $this->bde, $comment));

    expect($move(LeadStage::Contacted)->stage)->toBe(LeadStage::Contacted)
        ->and($move(LeadStage::Qualified, 'Budget confirmed')->stage)->toBe(LeadStage::Qualified)
        ->and($move(LeadStage::Contacted)->stage)->toBe(LeadStage::Contacted);

    $history = LeadStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->orderBy('id')->get();

    expect($history->map(fn ($e) => $e->label())->all())->toBe(['Lead created', 'New → Contacted', 'Contacted → Qualified', 'Qualified → Contacted'])
        ->and($history[2]->comment)->toBe('Budget confirmed')
        ->and($history[2]->actor_id)->toBe($this->bde->id);
});

it('never lets a person set a stage that only a conversion sets, or repeat the stage it is in', function (LeadStage $to) {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    expect(fn () => inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lead, $to, $this->bde)))
        ->toThrow(FieldRuleException::class);

    expect($lead->fresh()->stage)->toBe(LeadStage::New);
})->with([LeadStage::Deal, LeadStage::Quotation, LeadStage::Won, LeadStage::New]);

it('needs a reason to mark a lead lost, calls off what was planned, and needs a comment to re-open it', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $followup = makeFollowup($this->acme, $this->bde, $lead);
    $service = fn () => app(LeadService::class);

    // no reason, another company's reason, a switched-off reason
    foreach ([null, lostReasonId($this->globex)] as $reason) {
        expect(fn () => inCompany($this->acme, fn () => $service()->changeStage($lead, LeadStage::Lost, $this->bde, 'Too expensive', $reason)))
            ->toThrow(ValidationException::class);
    }
    expect($lead->fresh()->stage)->toBe(LeadStage::New)->and($followup->fresh()->status)->toBe(FollowupStatus::Pending);

    $lost = inCompany($this->acme, fn () => $service()->changeStage($lead, LeadStage::Lost, $this->bde, 'Too expensive', lostReasonId($this->acme, 'PRICE')));

    expect($lost->stage)->toBe(LeadStage::Lost)
        ->and($lost->lost_reason_id)->toBe(lostReasonId($this->acme, 'PRICE'))
        ->and($lost->lost_remarks)->toBe('Too expensive')
        ->and($lost->lost_at)->not->toBeNull()
        ->and($lost->next_followup_at)->toBeNull()
        ->and($followup->fresh()->status)->toBe(FollowupStatus::Cancelled);

    // re-opening: a comment, please
    expect(fn () => inCompany($this->acme, fn () => $service()->changeStage($lost, LeadStage::Contacted, $this->bde, ' ')))->toThrow(ValidationException::class);

    $open = inCompany($this->acme, fn () => $service()->changeStage($lost, LeadStage::Contacted, $this->bde, 'They called back'));

    expect($open->stage)->toBe(LeadStage::Contacted)->and($open->lost_reason_id)->toBeNull()->and($open->lost_at)->toBeNull()->and($open->lost_remarks)->toBeNull();

    $labels = LeadStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->orderBy('id')->get()->map(fn ($e) => $e->label())->all();
    expect($labels)->toBe(['Lead created', 'Marked as lost', 'Re-opened as Contacted']);
});

it('will not qualify a lead that is not complete, nor let a converted or lost lead be edited', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($lead->id)->update(['is_complete' => false]);

    expect(fn () => inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lead, LeadStage::Qualified, $this->bde)))
        ->toThrow(FieldRuleException::class, 'Complete the lead');

    Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($lead->id)->update(['stage' => LeadStage::Deal->value]);

    expect(fn () => inCompany($this->acme, fn () => app(LeadService::class)->update($lead->fresh(), leadInput($this->acme, $this->ahd, ['mobile' => $lead->mobile]), $this->bde)))
        ->toThrow(FieldRuleException::class, 'can no longer be edited');
});

it('keeps the stage and transfer history append-only', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $stage = LeadStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->firstOrFail();
    $transfer = LeadTransfer::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->firstOrFail();

    expect(fn () => $stage->forceFill(['comment' => 'rewritten'])->save())->toThrow(LogicException::class)
        ->and(fn () => $stage->delete())->toThrow(LogicException::class)
        ->and(fn () => $transfer->forceFill(['reason' => 'rewritten'])->save())->toThrow(LogicException::class)
        ->and(fn () => $transfer->delete())->toThrow(LogicException::class);
});

// ======================================================================================================
// Deleting
// ======================================================================================================

it('deletes a lead softly: its number is not reused and what was planned is called off', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $followup = makeFollowup($this->acme, $this->bde, $lead);

    inCompany($this->acme, fn () => app(LeadService::class)->delete($lead, $this->manager));

    expect(Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($lead->id)->exists())->toBeFalse()             // gone from every normal read
        ->and(Lead::withTrashed()->withoutGlobalScopes(Tenancy::SCOPES)->whereKey($lead->id)->exists())->toBeTrue()   // kept in the database
        ->and($followup->fresh()->status)->toBe(FollowupStatus::Cancelled);

    $next = makeLead($this->acme, $this->bde, $this->ahd);
    expect($next->lead_number)->not->toBe($lead->lead_number);

    // the deleted lead's mobile number is free again
    expect(makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => $lead->mobile])->exists)->toBeTrue();
});

// ======================================================================================================
// Who sees which lead
// ======================================================================================================

it('shows a lead to its owner, to the owner’s manager and to administrators — and to nobody else', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $access = app(LeadAccess::class);

    expect($access->sees($this->bde, $lead))->toBeTrue()
        ->and($access->sees($this->manager, $lead))->toBeTrue()
        ->and($access->sees($this->admin, $lead))->toBeTrue()
        ->and($access->sees($this->bde2, $lead))->toBeFalse()
        ->and($access->sees($this->otherManager, $lead))->toBeFalse()
        ->and($access->sees($this->telecaller, $lead))->toBeFalse();

    // the policy says the same, and adds the branch: the Mumbai salesperson cannot reach an Ahmedabad lead at all
    expect(Gate::forUser($this->bde)->allows('view', $lead))->toBeTrue()
        ->and(Gate::forUser($this->bde)->allows('update', $lead))->toBeTrue()
        ->and(Gate::forUser($this->bde2)->allows('view', $lead))->toBeFalse()
        ->and(Gate::forUser($this->mumBde)->allows('view', $lead))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('transfer', $lead))->toBeTrue()
        ->and(Gate::forUser($this->bde)->allows('transfer', $lead))->toBeFalse()      // no lead.assign
        ->and(Gate::forUser($this->bde)->allows('delete', $lead))->toBeFalse()        // no lead.delete
        ->and(Gate::forUser($this->manager)->allows('delete', $lead))->toBeTrue();
});

it('keeps the unassigned pool to those who hand leads out, and to whoever entered the lead', function () {
    $pool = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => '']);
    $access = app(LeadAccess::class);

    expect($access->sees($this->manager, $pool))->toBeTrue()          // entered it
        ->and($access->sees($this->otherManager, $pool))->toBeTrue()  // may hand leads out
        ->and($access->sees($this->admin, $pool))->toBeTrue()
        ->and($access->sees($this->bde, $pool))->toBeFalse();
});

it('lets someone with a planned follow-up on a lead open it — for as long as it is planned, and no further', function () {
    $lead = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => (string) $this->manager->id]);
    $access = app(LeadAccess::class);

    expect($access->sees($this->bde, $lead))->toBeFalse();

    // the manager asks their salesperson to make a call on the manager's own lead
    $followup = makeFollowup($this->acme, $this->manager, $lead, ['assigned_to' => (string) $this->bde->id]);

    expect($access->sees($this->bde, $lead))->toBeTrue()
        ->and($access->works($this->bde, $lead))->toBeFalse()
        ->and(Gate::forUser($this->bde)->allows('view', $lead))->toBeTrue()
        ->and(Gate::forUser($this->bde)->allows('followUp', $lead))->toBeTrue()
        ->and(Gate::forUser($this->bde)->allows('update', $lead))->toBeFalse()          // seeing is not working
        ->and(Gate::forUser($this->bde)->allows('changeStage', $lead))->toBeFalse()
        ->and($access->openable($this->bde, [$lead]))->toBe([$lead->id => true]);

    inCompany($this->acme, fn () => app(FollowupService::class)->cancel($followup, 'No longer needed', $this->manager));

    expect($access->sees($this->bde, $lead))->toBeFalse()->and($access->openable($this->bde, [$lead]))->toBe([]);
});

it('restricts a lead query to exactly what the person may see', function () {
    $mine = makeLead($this->acme, $this->bde, $this->ahd);
    $colleague = makeLead($this->acme, $this->bde2, $this->ahd);
    $managers = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => (string) $this->manager->id]);
    $pool = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => '']);
    $mumbai = makeLead($this->acme, $this->mumBde, $this->mum);
    $asked = makeLead($this->acme, $this->admin, $this->ahd, ['assigned_to' => (string) $this->bde2->id]);
    makeFollowup($this->acme, $this->admin, $asked, ['assigned_to' => (string) $this->bde->id]);

    $visible = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();

        return app(LeadAccess::class)->restrict(Lead::query(), $user)->orderBy('id')->pluck('id')->all();
    };

    expect($visible($this->bde))->toBe([$mine->id, $asked->id])                                          // own + asked to follow up
        ->and($visible($this->bde2))->toBe([$colleague->id, $asked->id])
        ->and($visible($this->manager))->toBe([$mine->id, $managers->id, $pool->id])                    // team + own + the pool
        ->and($visible($this->otherManager))->toBe([$pool->id])                                         // only the pool
        ->and($visible($this->mumBde))->toBe([$mumbai->id])
        ->and($visible($this->admin))->toBe([$mine->id, $colleague->id, $managers->id, $pool->id, $mumbai->id, $asked->id]);

    // and what the query returns is what the policy lets each of them open
    foreach ([$this->bde, $this->bde2, $this->manager, $this->otherManager, $this->mumBde, $this->admin] as $user) {
        $ids = $visible($user);
        foreach (Lead::query()->get() as $lead) {
            expect(Gate::forUser($user)->allows('view', $lead))->toBe(in_array($lead->id, $ids, true), "user {$user->id} lead {$lead->id}");
        }
    }
});

it('never shows a lead of another company, whoever asks', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $globexAdmin = makeUser(['Company Admin'], [$this->globex]);

    $this->actingAs($globexAdmin);

    expect(Lead::query()->count())->toBe(0)
        ->and(Lead::query()->find($lead->id))->toBeNull()
        ->and(Gate::forUser($globexAdmin)->allows('view', $lead))->toBeFalse();
});
