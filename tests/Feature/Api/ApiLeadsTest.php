<?php

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->nyc = makeLocation($this->globex, 'NYC');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);    // not on the manager's team
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->lead = fn ($by, array $over = [], $branch = null) => makeLead($this->acme, $by, $branch ?? $this->ahd, $over);
    $this->ids = fn ($response) => collect($response->json('data'))->pluck('id')->sort()->values()->all();
    $this->get = fn ($lead, $as) => apiCall('GET', "/api/v1/leads/{$lead->id}", as: $as);
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Whose leads a person sees
// ======================================================================================================

it('shows a salesperson their own leads, a manager their team’s, and an administrator everyone’s — never another company’s', function () {
    $mine = ($this->lead)($this->bde);
    $theirs = ($this->lead)($this->bde2);
    $pool = ($this->lead)($this->manager, ['assigned_to' => '']);
    $foreign = makeLead($this->globex, makeUser(['Company Admin'], [$this->globex]), $this->nyc);

    $list = fn ($user) => ($this->ids)(apiCall('GET', '/api/v1/leads', as: $user)->assertOk());

    expect($list($this->bde))->toBe([$mine->id])
        ->and($list($this->manager))->toBe([$mine->id, $pool->id])         // their team, and the pool they keep
        ->and($list($this->admin))->toBe([$mine->id, $theirs->id, $pool->id])
        ->and($list($this->admin))->not->toContain($foreign->id);
});

it('answers 404 — not 403 — for a lead the person may not see, in this company or another', function () {
    $mine = ($this->lead)($this->bde);
    $theirs = ($this->lead)($this->bde2);
    $foreign = makeLead($this->globex, makeUser(['Company Admin'], [$this->globex]), $this->nyc);
    $mumbai = ($this->lead)($this->admin, ['assigned_to' => $this->admin->id], $this->mum);   // a branch the BDE does not work in

    ($this->get)($mine, $this->bde)->assertOk()->assertJsonPath('data.id', $mine->id);

    foreach ([$theirs, $foreign, $mumbai] as $lead) {
        ($this->get)($lead, $this->bde)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    // and no write reaches them either: the same 404, and nothing changed
    apiCall('PATCH', "/api/v1/leads/{$theirs->id}", ['name' => 'Taken over'], $this->bde)->assertNotFound();
    apiCall('POST', "/api/v1/leads/{$foreign->id}/stage", ['stage' => 'lost', 'lost_reason_id' => lostReasonId($this->acme)], $this->admin)->assertNotFound();
    apiCall('DELETE', "/api/v1/leads/{$theirs->id}", as: $this->bde)->assertNotFound();
    expect(Lead::query()->whereKey($theirs->id)->value('name'))->toBe('Shree Ganesh Traders');
});

it('lets a manager see and open a team member’s lead, and a tele-caller open one they are asked to ring', function () {
    $lead = ($this->lead)($this->bde);

    ($this->get)($lead, $this->manager)->assertOk();
    ($this->get)($lead, $this->telecaller)->assertNotFound();

    inCompany($this->acme, fn () => app(SalesTeamService::class)->addMember($this->team, $this->telecaller->id, '2026-01-01', $this->manager));   // someone the manager may give calls to
    $call = makeFollowup($this->acme, $this->manager, $lead, ['assigned_to' => (string) $this->telecaller->id]);
    ($this->get)($lead, $this->telecaller)->assertOk();

    // …for as long as the call is planned
    inCompany($this->acme, fn () => app(FollowupService::class)->cancel($call, 'No longer needed', $this->manager));
    ($this->get)($lead, $this->telecaller)->assertNotFound();
});

it('works in the company named by X-Company-Id, and finds nothing of the other one', function () {
    $second = makeCompany('SECOND');
    seedDefaults($second);
    $branch = makeLocation($second, 'BLR');
    $both = makeUser(['Sales Manager'], [$this->acme, $second], null, ['default_company_id' => $this->acme->id]);
    $inAcme = makeLead($this->acme, $both, $this->ahd, ['assigned_to' => (string) $both->id]);
    $inSecond = makeLead($second, $both, $branch, ['assigned_to' => (string) $both->id]);

    expect(($this->ids)(apiCall('GET', '/api/v1/leads', as: $both)))->toBe([$inAcme->id])
        ->and(($this->ids)(apiCall('GET', '/api/v1/leads', as: $both, headers: ['X-Company-Id' => (string) $second->id])))->toBe([$inSecond->id]);

    apiCall('GET', "/api/v1/leads/{$inSecond->id}", as: $both)->assertNotFound();
    apiCall('GET', "/api/v1/leads/{$inSecond->id}", as: $both, headers: ['X-Company-Id' => (string) $second->id])->assertOk();
});

// ======================================================================================================
// Filters, paging and sync
// ======================================================================================================

it('filters by search, owner, branch, source, due date and lead date, and by stage', function () {
    $a = ($this->lead)($this->admin, ['assigned_to' => (string) $this->bde->id, 'name' => 'Alpha Steel', 'mobile' => '9811111111', 'lead_date' => '2026-10-01']);
    $b = ($this->lead)($this->admin, ['assigned_to' => (string) $this->bde2->id, 'name' => 'Bravo Pipes', 'mobile' => '9822222222', 'lead_date' => '2026-10-05', 'lead_source_id' => (string) leadSourceId($this->acme, 'WEBSITE')]);
    $c = ($this->lead)($this->admin, ['assigned_to' => '', 'name' => 'Charlie Tubes', 'mobile' => '9833333333', 'lead_date' => '2026-10-10'], $this->mum);
    $d = ($this->lead)($this->admin, ['assigned_to' => (string) $this->bde->id, 'name' => 'Delta Fasteners', 'mobile' => '9844444444']);
    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($d, LeadStage::Lost, $this->admin, 'gone', lostReasonId($this->acme)));
    DB::table('leads')->where('id', $a->id)->update(['next_followup_at' => now()->subDay()]);
    DB::table('leads')->where('id', $b->id)->update(['next_followup_at' => now()->addHours(2)]);

    $ids = fn (array $query) => ($this->ids)(apiCall('GET', '/api/v1/leads', $query, $this->admin)->assertOk());

    expect($ids([]))->toBe([$a->id, $b->id, $c->id])                                     // open by default: Delta is lost
        ->and($ids(['stage' => 'all']))->toBe([$a->id, $b->id, $c->id, $d->id])
        ->and($ids(['stage' => 'lost']))->toBe([$d->id])
        ->and($ids(['q' => 'bravo']))->toBe([$b->id])
        ->and($ids(['q' => '9833333333']))->toBe([$c->id])
        ->and($ids(['q' => '+91 98222 22222']))->toBe([$b->id])
        ->and($ids(['owner' => (string) $this->bde->id]))->toBe([$a->id])
        ->and($ids(['owner' => 'none']))->toBe([$c->id])
        ->and($ids(['branch' => (string) $this->mum->id]))->toBe([$c->id])
        ->and($ids(['source' => (string) leadSourceId($this->acme, 'WEBSITE')]))->toBe([$b->id])
        ->and($ids(['due' => 'overdue']))->toBe([$a->id])
        ->and($ids(['due' => 'today']))->toBe([$b->id])
        ->and($ids(['from' => '2026-10-04', 'to' => '2026-10-06']))->toBe([$b->id]);

    // "my leads" means the signed-in person's
    expect(($this->ids)(apiCall('GET', '/api/v1/leads', ['owner' => 'me'], $this->bde)))->toBe([$a->id]);
});

it('never lets a filter widen what a person may see', function () {
    $mine = ($this->lead)($this->bde);
    $theirs = ($this->lead)($this->bde2);

    expect(($this->ids)(apiCall('GET', '/api/v1/leads', ['owner' => (string) $this->bde2->id], $this->bde)))->toBe([])
        ->and(($this->ids)(apiCall('GET', '/api/v1/leads', ['owner' => 'none'], $this->bde)))->toBe([])
        ->and(($this->ids)(apiCall('GET', '/api/v1/leads', ['branch' => (string) $this->mum->id], $this->bde)))->toBe([])
        ->and(($this->ids)(apiCall('GET', '/api/v1/leads', ['q' => $theirs->mobile], $this->bde)))->toBe([])
        ->and(($this->ids)(apiCall('GET', '/api/v1/leads', ['q' => $mine->mobile], $this->bde)))->toBe([$mine->id]);
});

it('bounds what a client may ask for', function () {
    foreach ([['per_page' => 101], ['per_page' => 0], ['page' => 0], ['stage' => 'everything'], ['owner' => 'someone'], ['due' => 'soon'], ['from' => '12-10-2026'], ['q' => str_repeat('x', 101)], ['updated_since' => 'yesterday']] as $query) {
        apiCall('GET', '/api/v1/leads', $query, $this->admin)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    foreach (range(1, 5) as $_) {
        ($this->lead)($this->admin, ['assigned_to' => (string) $this->bde->id]);
    }

    $page = apiCall('GET', '/api/v1/leads', ['per_page' => 2, 'page' => 3], $this->admin)->assertOk();
    expect($page->json('data'))->toHaveCount(1)
        ->and($page->json('meta.pagination'))->toBe(['page' => 3, 'per_page' => 2, 'total' => 5, 'last_page' => 3, 'has_more' => false]);
    expect(apiCall('GET', '/api/v1/leads', as: $this->admin)->json('meta.pagination.per_page'))->toBe(20);
});

it('syncs what changed since a moment, oldest change first — closed and removed leads included — and says when to ask again', function () {
    $old = ($this->lead)($this->bde);
    Carbon::setTestNow('2026-10-12 10:00:00');
    $changed = ($this->lead)($this->bde);
    Carbon::setTestNow('2026-10-12 11:00:00');
    $lost = ($this->lead)($this->bde);
    Carbon::setTestNow('2026-10-12 12:00:00');
    $gone = ($this->lead)($this->bde);

    Carbon::setTestNow('2026-10-12 13:00:00');
    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lost, LeadStage::Lost, $this->bde, null, lostReasonId($this->acme)));
    Carbon::setTestNow('2026-10-12 14:00:00');
    inCompany($this->acme, fn () => app(LeadService::class)->delete($gone, $this->manager));
    Carbon::setTestNow('2026-10-12 15:00:00');
    apiCall('PATCH', "/api/v1/leads/{$changed->id}", ['city' => 'Surat'], $this->bde)->assertOk();

    $sync = apiCall('GET', '/api/v1/leads', ['updated_since' => '2026-10-12T12:30:00+05:30'], $this->bde)->assertOk();

    expect(collect($sync->json('data'))->pluck('id')->all())->toBe([$lost->id, $gone->id, $changed->id])
        ->and(collect($sync->json('data'))->firstWhere('id', $gone->id)['deleted_at'])->toBe('2026-10-12T14:00:00+05:30')
        ->and(collect($sync->json('data'))->firstWhere('id', $lost->id)['stage']['value'])->toBe('lost')
        ->and($sync->json('meta.server_time'))->toBe('2026-10-12T15:00:00+05:30');

    // without updated_since a removed lead is simply not there
    expect(($this->ids)(apiCall('GET', '/api/v1/leads', ['stage' => 'all'], $this->bde)))->not->toContain($gone->id)->toContain($old->id);
});

// ======================================================================================================
// One lead
// ======================================================================================================

it('shows a lead with its interests, follow-ups and what the caller may do with it', function () {
    $item = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), [], 'approved');
    $lead = ($this->lead)($this->bde, [], null);
    inCompany($this->acme, fn () => app(LeadService::class)->update($lead, leadInput($this->acme, $this->ahd, ['mobile' => $lead->mobile], [
        ['id' => '', 'item_id' => (string) $item->id, 'description' => '', 'quantity' => '12.5', 'unit_id' => (string) $item->base_unit_id],
        ['id' => '', 'item_id' => '', 'description' => 'Custom brackets', 'quantity' => '', 'unit_id' => ''],
    ]), $this->bde));
    makeFollowup($this->acme, $this->bde, $lead);

    $data = ($this->get)($lead, $this->bde)->assertOk()->json('data');

    expect($data['lead_number'])->toBe($lead->lead_number)->and($data['stage'])->toBe(['value' => 'new', 'label' => 'New'])
        ->and($data['interests'])->toHaveCount(2)
        ->and($data['interests'][0]['item']['name'])->toBe('MS Hex Bolt M10')->and($data['interests'][0]['quantity'])->toBe('12.500')->and($data['interests'][0]['unit']['code'])->toBe('NOS')
        ->and($data['interests'][1])->toMatchArray(['item' => null, 'description' => 'Custom brackets'])
        ->and($data['followups'])->toHaveCount(1)->and($data['followups'][0]['type']['value'])->toBe('call')
        ->and($data['permissions'])->toBe(['update' => true, 'change_stage' => true, 'transfer' => false, 'delete' => false, 'follow_up' => true, 'convert' => false, 'submit' => false]);

    // a manager can hand it over and delete it
    $seen = ($this->get)($lead, $this->manager)->json('data.permissions');
    expect($seen['transfer'])->toBeTrue()->and($seen['delete'])->toBeTrue();
});

it('does not let a client set what is the server’s to decide', function () {
    $response = apiCall('POST', '/api/v1/leads', [
        'name' => 'Sneaky Traders', 'mobile' => nextMobile(), 'location_id' => $this->ahd->id, 'lead_source_id' => leadSourceId($this->acme, 'REFERENCE'),
        'company_id' => $this->globex->id, 'stage' => 'won', 'entry_channel' => 'indiamart', 'lead_number' => 'LD/HACK/1', 'is_complete' => false,
        'assigned_to' => $this->bde2->id, 'created_by' => $this->admin->id, 'approval_status' => 'approved', 'latitude' => 1, 'longitude' => 1,
    ], $this->telecaller, idem())->assertCreated();

    $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($response->json('data.id'));

    expect($lead->company_id)->toBe($this->acme->id)->and($lead->stage)->toBe(LeadStage::New)->and($lead->entry_channel->value)->toBe('manual')
        ->and($lead->lead_number)->toStartWith('LD/AHD/')->and($lead->assigned_to)->toBe($this->telecaller->id)->and($lead->created_by)->toBe($this->telecaller->id)
        ->and($lead->is_complete)->toBeTrue()->and($lead->latitude)->toBeNull();
});

// ======================================================================================================
// Changing a lead
// ======================================================================================================

it('changes only the fields that are sent and leaves the rest alone', function () {
    $lead = ($this->lead)($this->bde, ['email' => 'meera@example.test', 'city' => 'Ahmedabad', 'expected_value' => '250000']);

    $response = apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['requirement' => 'Hex bolts, 5,000 pcs'], $this->bde)->assertOk();

    $fresh = $lead->fresh();
    expect($response->json('data.requirement'))->toBe('Hex bolts, 5,000 pcs')
        ->and($fresh->name)->toBe('Shree Ganesh Traders')->and($fresh->email)->toBe('meera@example.test')->and($fresh->city)->toBe('Ahmedabad')
        ->and($fresh->expected_value)->toBe('250000.00')->and($fresh->mobile)->toBe($lead->mobile)->and($fresh->assigned_to)->toBe($this->bde->id);
});

it('replaces the interests only when they are sent, updating rows by id', function () {
    $lead = ($this->lead)($this->bde, [], null);
    inCompany($this->acme, fn () => app(LeadService::class)->update($lead, leadInput($this->acme, $this->ahd, ['mobile' => $lead->mobile], [
        ['id' => '', 'item_id' => '', 'description' => 'Brackets', 'quantity' => '4', 'unit_id' => ''],
        ['id' => '', 'item_id' => '', 'description' => 'Washers', 'quantity' => '', 'unit_id' => ''],
    ]), $this->bde));
    [$brackets, $washers] = $lead->interests()->get()->all();

    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Surat'], $this->bde)->assertOk()->assertJsonCount(2, 'data.interests');

    $response = apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['interests' => [
        ['id' => $brackets->id, 'description' => 'Steel brackets', 'quantity' => 6],
        ['description' => 'Nuts'],
    ]], $this->bde)->assertOk();

    expect(collect($response->json('data.interests'))->pluck('description')->all())->toBe(['Steel brackets', 'Nuts'])
        ->and($response->json('data.interests.0.id'))->toBe($brackets->id)->and($response->json('data.interests.0.quantity'))->toBe('6.000')
        ->and(DB::table('lead_interests')->where('id', $washers->id)->exists())->toBeFalse();

    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['interests' => []], $this->bde)->assertOk()->assertJsonCount(0, 'data.interests');
});

it('re-fills state, district and block from a PIN code that is changed on its own', function () {
    $lead = ($this->lead)($this->bde, ['pin_code' => '411001']);   // Pune
    expect($lead->fresh()->district_id)->toBe($this->geo['pune']->id);

    $response = apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['pin_code' => '380001'], $this->bde)->assertOk();

    expect($response->json('data.address.district.name'))->toBe('Ahmedabad')->and($response->json('data.address.state.name'))->toBe('Gujarat')
        ->and($response->json('data.address.block.name'))->toBe('Daskroi')->and($response->json('data.address.pin_code'))->toBe('380001');
});

it('checks a changed mobile number for duplicates, but not one that is left as it was', function () {
    $other = ($this->lead)($this->bde2);
    $lead = ($this->lead)($this->bde);
    // the lead already shares its number with another open lead, acknowledged when it was entered
    DB::table('leads')->where('id', $lead->id)->update(['mobile' => $other->mobile]);

    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Surat'], $this->bde)->assertOk();
    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['mobile' => $other->mobile, 'city' => 'Vadodara'], $this->bde)->assertOk();

    $third = ($this->lead)($this->bde2);
    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['mobile' => $third->mobile], $this->bde)->assertStatus(422);
    expect(apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['mobile' => $third->mobile], $this->bde)->json('error.fields.mobile.0'))->toContain('An open lead already has this mobile number');

    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['mobile' => $third->mobile, 'acknowledge_duplicate' => true], $this->bde)->assertOk();
});

it('refuses a change made on an out-of-date copy, and shows the current one', function () {
    $lead = ($this->lead)($this->bde);
    $loaded = ($this->get)($lead, $this->bde)->json('data.updated_at');

    Carbon::setTestNow('2026-10-12 10:15:00');
    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Surat'], $this->bde)->assertOk();

    $stale = apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Rajkot', 'expected_updated_at' => $loaded], $this->bde)->assertStatus(409);

    expect($stale->json('error.code'))->toBe('version_conflict')->and($stale->json('error.details.current.address.city'))->toBe('Surat')
        ->and($lead->fresh()->city)->toBe('Surat');

    $current = $stale->json('error.details.current.updated_at');
    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Rajkot', 'expected_updated_at' => $current], $this->bde)->assertOk();
    expect($lead->fresh()->city)->toBe('Rajkot');
});

it('refuses to edit by hand a lead that has been lost, and to set ownership or branch through an edit', function () {
    $lead = ($this->lead)($this->bde);

    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['assigned_to' => $this->bde2->id], $this->bde)->assertStatus(422);
    expect(array_keys(apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['assigned_to' => $this->bde2->id, 'location_id' => $this->mum->id], $this->bde)->json('error.fields')))->toBe(['location_id', 'assigned_to']);

    apiCall('POST', "/api/v1/leads/{$lead->id}/stage", ['stage' => 'lost', 'lost_reason_id' => lostReasonId($this->acme)], $this->bde)->assertOk();
    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Surat'], $this->bde)->assertStatus(409)->assertJsonPath('error.code', 'state_conflict');   // a lost lead is re-opened first
});

// ======================================================================================================
// Stages, hand-over, removal
// ======================================================================================================

it('moves a lead through the stages a person may choose, and says what each move needs', function () {
    $lead = ($this->lead)($this->bde);
    $stage = fn (array $body) => apiCall('POST', "/api/v1/leads/{$lead->id}/stage", $body, $this->bde);

    $stage(['stage' => 'contacted'])->assertOk()->assertJsonPath('data.stage.value', 'contacted');
    $stage(['stage' => 'qualified'])->assertOk()->assertJsonPath('data.stage.value', 'qualified');

    // lost needs a reason from the master
    expect($stage(['stage' => 'lost'])->assertStatus(422)->json('error.fields'))->toHaveKey('lost_reason_id');
    $stage(['stage' => 'lost', 'lost_reason_id' => lostReasonId($this->acme), 'comment' => 'Went elsewhere'])->assertOk()
        ->assertJsonPath('data.lost.reason.name', 'Price too high')->assertJsonPath('data.lost.remarks', 'Went elsewhere')->assertJsonPath('data.is_open', false);

    // re-opening needs a comment
    expect($stage(['stage' => 'contacted'])->assertStatus(422)->json('error.fields'))->toHaveKey('comment');
    $stage(['stage' => 'contacted', 'comment' => 'They called back'])->assertOk()->assertJsonPath('data.is_open', true);

    // conversion stages are the system's: a person cannot claim them, and an unknown one is not a stage at all
    $stage(['stage' => 'won'])->assertStatus(409)->assertJsonPath('error.code', 'state_conflict');
    $stage(['stage' => 'archived'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect(DB::table('lead_stage_history')->where('lead_id', $lead->id)->count())->toBe(5);
});

it('does not let a salesperson move someone else’s lead', function () {
    $theirs = ($this->lead)($this->bde2);

    apiCall('POST', "/api/v1/leads/{$theirs->id}/stage", ['stage' => 'contacted'], $this->bde)->assertNotFound();
    // a manager outside the owner's line sees it as 404 as well
    $other = makeUser(['Sales Manager'], [$this->acme]);
    apiCall('POST', "/api/v1/leads/{$theirs->id}/stage", ['stage' => 'contacted'], $other)->assertNotFound();
    expect($theirs->fresh()->stage)->toBe(LeadStage::New);
});

it('hands leads over in bulk, all or nothing, with the reason kept', function () {
    inCompany($this->acme, fn () => app(SalesTeamService::class)->addMember($this->team, $this->bde2->id, '2026-01-01', $this->manager));
    $a = ($this->lead)($this->bde);
    $b = ($this->lead)($this->bde);
    $mine = ($this->lead)($this->manager, ['assigned_to' => (string) $this->manager->id]);

    $response = apiCall('POST', '/api/v1/leads/transfer', ['lead_ids' => [$a->id, $b->id], 'to_user_id' => $this->bde2->id, 'reason' => 'Territory change'], $this->manager, idem())->assertOk();

    expect(collect($response->json('data'))->pluck('owner.id')->unique()->all())->toBe([$this->bde2->id])
        ->and(LeadTransfer::query()->where('reason', 'Territory change')->count())->toBe(2)
        ->and($a->fresh()->assigned_to)->toBe($this->bde2->id);

    // one lead that cannot move stops the lot
    $theirs = ($this->lead)($this->admin, ['assigned_to' => (string) $this->admin->id]);
    $refused = apiCall('POST', '/api/v1/leads/transfer', ['lead_ids' => [$mine->id, $theirs->id], 'to_user_id' => $this->bde->id, 'reason' => 'Rebalancing'], $this->manager, idem())->assertStatus(422);

    expect($refused->json('error.fields.leads.0'))->toContain('Nothing was transferred')->and($mine->fresh()->assigned_to)->toBe($this->manager->id);
});

it('keeps hand-over to the people who may hand leads out, and to people the caller manages', function () {
    $lead = ($this->lead)($this->bde);
    $body = ['lead_ids' => [$lead->id], 'to_user_id' => $this->bde2->id, 'reason' => 'Because'];

    apiCall('POST', '/api/v1/leads/transfer', $body, $this->bde, idem())->assertForbidden();
    apiCall('POST', '/api/v1/leads/transfer', $body, $this->manager, idem())->assertStatus(422)->assertJsonPath('error.fields.to_user_id.0', 'You can hand leads only to yourself or to people on the teams you manage.');
    apiCall('POST', '/api/v1/leads/transfer', [...$body, 'lead_ids' => []], $this->manager, idem())->assertStatus(422);
    apiCall('POST', '/api/v1/leads/transfer', [...$body, 'reason' => 'x'], $this->manager, idem())->assertStatus(422);
    expect($lead->fresh()->assigned_to)->toBe($this->bde->id);
});

it('removes a lead entered by mistake for those who may, and tells a syncing app', function () {
    $lead = ($this->lead)($this->bde);

    apiCall('DELETE', "/api/v1/leads/{$lead->id}", as: $this->bde)->assertForbidden();                 // a salesperson may not delete
    apiCall('DELETE', "/api/v1/leads/{$lead->id}", as: $this->manager)->assertOk()->assertJsonPath('data.deleted', true);

    ($this->get)($lead, $this->bde)->assertNotFound();
    $sync = apiCall('GET', '/api/v1/leads', ['updated_since' => '2026-10-12T09:00:00+05:30'], $this->bde)->assertOk();
    expect(collect($sync->json('data'))->firstWhere('id', $lead->id)['deleted_at'])->not->toBeNull()
        ->and(Lead::withTrashed()->whereKey($lead->id)->exists())->toBeTrue();
});

it('refuses to remove a lead that has been converted', function () {
    $lead = ($this->lead)($this->bde);
    DB::table('leads')->where('id', $lead->id)->update(['stage' => 'won']);

    apiCall('DELETE', "/api/v1/leads/{$lead->id}", as: $this->manager)->assertStatus(409)->assertJsonPath('error.code', 'state_conflict');
    apiCall('POST', "/api/v1/leads/{$lead->id}/stage", ['stage' => 'contacted'], $this->bde)->assertStatus(409);
    expect(Lead::query()->whereKey($lead->id)->exists())->toBeTrue();
});
