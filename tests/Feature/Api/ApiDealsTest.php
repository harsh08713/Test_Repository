<?php

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Deals and inquiries over the API: the same rules as the screens (everything goes through DealService and LeadService), found
 * only through the caller's own reach, with a refusal that the state explains answered as a 409 and not a bare 403.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->nyc = makeLocation($this->globex, 'NYC');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->loner = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);   // on nobody's team
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->bolt = makeUsableItem($this->acme);
    $this->wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG')]);
    $this->customer = makeCustomer($this->acme);
    $this->reason = lostReasonId($this->acme);

    // a deal of the other company, whose administrator owns the lead (an administrator's lead is otherwise left in the pool)
    $this->foreignDeal = function () {
        $admin = makeUser(['Company Admin'], [$this->globex]);

        return makeDeal($this->globex, $admin, makeLead($this->globex, $admin, $this->nyc, ['assigned_to' => (string) $admin->id], [lineRow(makeUsableItem($this->globex), '1', '')]));
    };
    $this->deal = fn ($by = null, array $lead = [], array $deal = []) => makeDeal($this->acme, $by ?? $this->bde,
        makeLead($this->acme, $by ?? $this->bde, $this->ahd, $lead, [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]), $deal);
    $this->ids = fn ($response) => collect($response->json('data'))->pluck('id')->sort()->values()->all();
    $this->get = fn ($deal, $as) => apiCall('GET', "/api/v1/deals/{$deal->id}", as: $as);

    // the body that converts a lead: each of its lines, priced
    $this->body = function (Lead $lead, array $deal = [], array $price = ['125.50', '40']): array {
        $rows = app(DealService::class)->linesFromLead($lead);

        return [
            'expected_close_date' => '2026-11-30',
            ...$deal,
            'items' => array_map(fn (array $row, string $rate) => [
                'lead_interest_id' => $row['lead_interest_id'], 'item_id' => $row['item_id'], 'unit_id' => $row['unit_id'], 'quantity' => (float) $row['quantity'], 'expected_rate' => (float) $rate,
            ], $rows, array_slice($price, 0, count($rows))),
        ];
    };
    $this->qualified = fn ($by = null) => qualify($this->acme, makeLead($this->acme, $by ?? $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', ''), lineRow($this->wire, '2.5', '')]), $by ?? $this->bde);
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Whose deals a person sees
// ======================================================================================================

it('shows a salesperson their own deals, a manager their team’s, and an administrator everyone’s — never another company’s', function () {
    $mine = ($this->deal)();
    $theirs = ($this->deal)($this->bde2);
    $loners = ($this->deal)($this->loner);
    $foreign = ($this->foreignDeal)();

    $list = fn ($user) => ($this->ids)(apiCall('GET', '/api/v1/deals', as: $user)->assertOk());

    expect($list($this->bde))->toBe([$mine->id])
        ->and($list($this->manager))->toBe([$mine->id, $theirs->id])
        ->and($list($this->admin))->toBe([$mine->id, $theirs->id, $loners->id])
        ->and($list($this->admin))->not->toContain($foreign->id);

    // a tele-caller holds no right to deals at all
    apiCall('GET', '/api/v1/deals', as: $this->telecaller)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});

it('answers 404 — not 403 — for a deal the person may not see, in this company or another', function () {
    $mine = ($this->deal)();
    $theirs = ($this->deal)($this->bde2);
    $foreign = ($this->foreignDeal)();

    ($this->get)($mine, $this->bde)->assertOk()->assertJsonPath('data.id', $mine->id);

    foreach ([$theirs, $foreign] as $deal) {
        ($this->get)($deal, $this->bde)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    // and no write reaches them either: the same 404, and nothing changed
    apiCall('PATCH', "/api/v1/deals/{$theirs->id}", ['remarks' => 'Taken over'], $this->bde)->assertNotFound();
    apiCall('POST', "/api/v1/deals/{$foreign->id}/stage", ['stage' => 'negotiation'], $this->admin)->assertNotFound();
    apiCall('POST', "/api/v1/deals/{$theirs->id}/submit", [], $this->bde)->assertNotFound();
    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($theirs->id)->value('remarks'))->toBeNull()
        ->and(Deal::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($theirs->id)->value('stage'))->toBe(DealStage::Discovery);
});

it('filters by search, owner, stage, approval state and close date', function () {
    $a = ($this->deal)($this->bde, ['name' => 'Alpha Steel', 'mobile' => '9811111111'], ['expected_close_date' => '2026-10-20']);
    $b = ($this->deal)($this->bde2, ['name' => 'Bravo Pipes', 'mobile' => '9822222222'], ['expected_close_date' => '2026-12-05']);
    $c = ($this->deal)($this->bde, ['name' => 'Charlie Tubes', 'mobile' => '9833333333'], ['expected_close_date' => '2026-12-10']);
    inCompany($this->acme, function () use ($b, $c) {
        app(ApprovalService::class)->submit($b, $this->bde2);
        app(DealService::class)->changeStage($c, DealStage::Lost, $this->bde, null, $this->reason);
    });
    DB::table('deals')->where('id', $a->id)->update(['expected_close_date' => '2026-09-30']);

    $ids = fn (array $query) => ($this->ids)(apiCall('GET', '/api/v1/deals', $query, $this->admin)->assertOk());

    expect($ids([]))->toBe([$a->id, $b->id])                                                       // open by default: Charlie is lost
        ->and($ids(['stage' => 'all']))->toBe([$a->id, $b->id, $c->id])
        ->and($ids(['stage' => 'lost']))->toBe([$c->id])
        ->and($ids(['q' => 'bravo']))->toBe([$b->id])
        ->and($ids(['q' => '+91 98111 11111']))->toBe([$a->id])
        ->and($ids(['owner' => (string) $this->bde2->id]))->toBe([$b->id])
        ->and($ids(['approval' => 'pending']))->toBe([$b->id])
        ->and($ids(['closing' => 'late']))->toBe([$a->id])
        ->and(apiCall('GET', '/api/v1/deals', ['owner' => 'me'], $this->bde)->assertOk()->json('data.*.id'))->toBe([$a->id]);

    apiCall('GET', '/api/v1/deals', ['stage' => 'nonsense'], $this->admin)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('gives what changed since a moment, oldest change first, and tells the server time to ask from next', function () {
    $first = ($this->deal)();
    Carbon::setTestNow('2026-10-12 10:30:00');
    $second = ($this->deal)();
    Carbon::setTestNow('2026-10-12 11:30:00');
    inCompany($this->acme, fn () => app(DealService::class)->changeStage($first, DealStage::Negotiation, $this->bde));

    $sync = apiCall('GET', '/api/v1/deals', ['updated_since' => '2026-10-12T10:00:00+05:30'], $this->bde)->assertOk();
    // 10:00 +05:30 is 04:30 UTC: both were changed after it; the first changed last
    expect($sync->json('data.*.id'))->toBe([$second->id, $first->id])->and($sync->json('meta.server_time'))->not->toBeNull();

    $later = apiCall('GET', '/api/v1/deals', ['updated_since' => '2026-10-12T11:00:00+05:30'], $this->bde)->assertOk();
    expect($later->json('data.*.id'))->toBe([$first->id]);
});

it('shows a deal with its lines, its history and what the caller may do with it', function () {
    $deal = ($this->deal)();

    $shown = ($this->get)($deal, $this->bde)->assertOk();

    expect($shown->json('data.deal_number'))->toBe($deal->deal_number)
        ->and($shown->json('data.expected_value'))->toBe('1568.75')
        ->and($shown->json('data.weighted_value'))->toBe('156.88')
        ->and($shown->json('data.stage.value'))->toBe('discovery')
        ->and($shown->json('data.approval_status.value'))->toBe('draft')
        ->and($shown->json('data.items'))->toHaveCount(2)
        ->and($shown->json('data.items.0.amount'))->toBe('1255.00')
        ->and($shown->json('data.items.0.item.name'))->toBe($this->bolt->name)
        ->and($shown->json('data.stage_history'))->toHaveCount(1)
        ->and($shown->json('data.stage_history.0.from'))->toBeNull()
        ->and($shown->json('data.permissions'))->toMatchArray(['update' => true, 'submit' => true, 'reopen_lost' => false, 'reassign' => false]);

    expect(($this->get)($deal, $this->manager)->json('data.permissions.reassign'))->toBeTrue();
});

// ======================================================================================================
// Converting a lead
// ======================================================================================================

it('converts a qualified lead into a deal: 201, a Location, the server’s own numbers, and the lead moves to Deal', function () {
    $lead = ($this->qualified)();

    $response = apiCall('POST', "/api/v1/leads/{$lead->id}/convert", ($this->body)($lead, ['probability' => 35, 'remarks' => 'Hot']), $this->bde, idem())->assertCreated();

    $deal = Deal::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->firstOrFail();
    $response->assertHeader('Location', route('api.v1.deals.show', ['deal' => $deal->id], false));

    expect($response->json('data.id'))->toBe($deal->id)
        ->and($response->json('data.deal_number'))->toStartWith('DL/')
        ->and($response->json('data.expected_value'))->toBe('1355.00')        // 10 × 125.50 + 2.5 × 40
        ->and($response->json('data.probability'))->toBe(35)
        ->and($response->json('data.owner.id'))->toBe($this->bde->id)
        ->and($response->json('data.lead.lead_number'))->toBe($lead->lead_number)
        ->and($response->json('data.items'))->toHaveCount(2)
        ->and($lead->fresh()->stage)->toBe(LeadStage::Deal);
});

it('refuses a conversion without an Idempotency-Key, and answers a repeat with the first answer, not a second deal', function () {
    $lead = ($this->qualified)();
    $body = ($this->body)($lead);

    apiCall('POST', "/api/v1/leads/{$lead->id}/convert", $body, $this->bde)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);

    $key = idem();
    $first = apiCall('POST', "/api/v1/leads/{$lead->id}/convert", $body, $this->bde, $key)->assertCreated();
    $again = apiCall('POST', "/api/v1/leads/{$lead->id}/convert", $body, $this->bde, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.id'))->toBe($first->json('data.id'))->and(Deal::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);

    // another request for a lead that is already a deal is a conflict, and names the deal
    $second = apiCall('POST', "/api/v1/leads/{$lead->id}/convert", $body, $this->bde, idem())->assertStatus(409)->assertJsonPath('error.code', 'state_conflict');
    expect($second->json('error.message'))->toContain($first->json('data.deal_number'));
});

it('says plainly why a lead cannot be converted', function () {
    $new = makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]);
    $mine = ($this->qualified)();
    $theirs = ($this->qualified)($this->bde2);
    $pool = qualify($this->acme, makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => ''], [lineRow($this->bolt, '5', '')]), $this->manager);

    $convert = fn (Lead $lead, $as) => apiCall('POST', "/api/v1/leads/{$lead->id}/convert", ($this->body)($lead, [], ['10', '10']), $as, idem());

    expect($convert($new, $this->bde)->assertStatus(409)->json('error.message'))->toContain('Only a qualified lead');
    expect($convert($pool, $this->manager)->assertStatus(409)->json('error.message'))->toContain('owner');
    expect($convert($theirs, $this->loner)->assertNotFound()->json('error.code'))->toBe('not_found');          // not even visible
    expect($convert($mine, $this->bde2)->assertNotFound()->json('error.code'))->toBe('not_found');
    expect($convert($mine, $this->telecaller)->assertForbidden()->json('error.code'))->toBe('forbidden');      // no right to deals

    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('needs every line of the lead, and says what is wrong with the rest, by field', function () {
    $lead = ($this->qualified)();
    $body = ($this->body)($lead);
    $call = fn (array $body) => apiCall('POST', "/api/v1/leads/{$lead->id}/convert", $body, $this->bde, idem());

    $short = $body;
    array_pop($short['items']);
    expect($call($short)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->json('error.fields.items.0'))->toContain('Every item the lead listed');

    // what is wrong with the shape of a line is said first…
    $bad = $body;
    $bad['items'][1]['expected_rate'] = -4;
    expect($call($bad)->assertStatus(422)->json('error.fields'))->toHaveKey('items.1.expected_rate');

    // …then what the rules say, named as the app names its fields (no "deal." in front)
    $late = $body;
    $late['expected_close_date'] = '2026-10-01';
    expect($call($late)->assertStatus(422)->json('error.fields'))->toHaveKey('expected_close_date')->not->toHaveKey('deal.expected_close_date');

    $split = $body;
    $split['items'][0]['quantity'] = 2.5;               // Nos cannot be split
    expect($call($split)->assertStatus(422)->json('error.fields'))->toHaveKey('items.0.quantity');

    $swapped = $body;
    $swapped['items'][0]['item_id'] = $this->wire->id;
    $swapped['items'][0]['unit_id'] = $this->wire->base_unit_id;
    expect($call($swapped)->assertStatus(422)->json('error.fields'))->toHaveKey('items.0.item_id');

    $none = $body;
    unset($none['items']);
    $call($none)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect(Deal::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('ignores the number, the value, the stage and the owner a device sends', function () {
    $lead = ($this->qualified)();
    $body = [...($this->body)($lead), 'deal_number' => 'DL/X/1', 'expected_value' => '1.00', 'stage' => 'won', 'assigned_to' => $this->bde2->id, 'company_id' => $this->globex->id, 'approval_status' => 'approved'];
    $body['items'][0]['amount'] = '1.00';

    $response = apiCall('POST', "/api/v1/leads/{$lead->id}/convert", $body, $this->bde, idem())->assertCreated();

    expect($response->json('data.deal_number'))->not->toBe('DL/X/1')
        ->and($response->json('data.expected_value'))->toBe('1355.00')
        ->and($response->json('data.stage.value'))->toBe('discovery')
        ->and($response->json('data.owner.id'))->toBe($this->bde->id)
        ->and($response->json('data.approval_status.value'))->toBe('draft')
        ->and($response->json('data.items.0.amount'))->toBe('1255.00');
});

it('turns a read-only device away from every write', function () {
    $lead = ($this->qualified)();
    $deal = ($this->deal)();
    $readOnly = apiToken($this->bde, ['read']);

    apiCall('POST', "/api/v1/leads/{$lead->id}/convert", ($this->body)($lead), $readOnly, idem())->assertForbidden()->assertJsonPath('error.code', 'scope_missing');
    apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['remarks' => 'x'], $readOnly)->assertForbidden()->assertJsonPath('error.code', 'scope_missing');
    apiCall('POST', "/api/v1/deals/{$deal->id}/stage", ['stage' => 'negotiation'], $readOnly)->assertForbidden();
    apiCall('GET', "/api/v1/deals/{$deal->id}", as: $readOnly)->assertOk();
});

// ======================================================================================================
// Changing a deal
// ======================================================================================================

it('changes where an open deal stands, and what it is only while it is a draft', function () {
    $deal = ($this->deal)();

    $moved = apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['probability' => 60, 'expected_close_date' => '2026-12-01', 'remarks' => 'Site visit done'], $this->bde)->assertOk();
    expect($moved->json('data.probability'))->toBe(60)->and($moved->json('data.remarks'))->toBe('Site visit done')->and($moved->json('data.items'))->toHaveCount(2);

    // the lines: a row with an id is updated, one without is added, one left out is removed
    $line = $moved->json('data.items.0');
    $changed = apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['items' => [
        ['id' => $line['id'], 'item_id' => $this->bolt->id, 'unit_id' => $this->bolt->base_unit_id, 'quantity' => 20, 'expected_rate' => 100],
        ['item_id' => $this->wire->id, 'quantity' => 1, 'expected_rate' => 10.5],
    ]], $this->bde)->assertOk();

    expect($changed->json('data.items'))->toHaveCount(2)->and($changed->json('data.expected_value'))->toBe('2010.50');

    // once it is in approval, what it is is locked — with the reason — but where it stands is not
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($deal->fresh(), $this->bde));
    $locked = apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['items' => [['item_id' => $this->bolt->id, 'quantity' => 1, 'expected_rate' => 1]]], $this->bde)->assertStatus(409);
    expect($locked->json('error.code'))->toBe('state_conflict')->and($locked->json('error.message'))->toContain('waiting for approval');
    apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['customer_ledger_id' => $this->customer->id], $this->bde)->assertStatus(409);
    apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['probability' => 70], $this->bde)->assertOk()->assertJsonPath('data.probability', 70);
});

it('refuses a probability outside 1 to 99 and a close date before the deal was made', function () {
    $deal = ($this->deal)();

    foreach ([['probability' => 0], ['probability' => 100], ['probability' => 'high'], ['expected_close_date' => '2026-10-01'], ['expected_close_date' => 'soon']] as $bad) {
        apiCall('PATCH', "/api/v1/deals/{$deal->id}", $bad, $this->bde)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    expect($deal->fresh()->probability)->toBe(10);
});

it('makes a stale edit a version conflict that carries the current deal', function () {
    $deal = ($this->deal)();
    $loaded = ($this->get)($deal, $this->bde)->json('data.updated_at');

    Carbon::setTestNow('2026-10-12 12:00:00');
    apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['remarks' => 'Changed on the web'], $this->bde)->assertOk();

    $stale = apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['remarks' => 'Changed on the phone', 'expected_updated_at' => $loaded], $this->bde)->assertStatus(409);

    expect($stale->json('error.code'))->toBe('version_conflict')->and($stale->json('error.details.current.remarks'))->toBe('Changed on the web');
    apiCall('PATCH', "/api/v1/deals/{$deal->id}", ['remarks' => 'Now', 'expected_updated_at' => ($this->get)($deal, $this->bde)->json('data.updated_at')], $this->bde)->assertOk();
});

// ======================================================================================================
// Stages, approval and hand-over
// ======================================================================================================

it('moves a deal between stages, marks it lost with a reason, and brings it back only for someone who may', function () {
    $deal = ($this->deal)();
    $stage = fn (array $body, $as) => apiCall('POST', "/api/v1/deals/{$deal->id}/stage", $body, $as);

    expect($stage(['stage' => 'negotiation'], $this->bde)->assertOk()->json('data.probability'))->toBe(50);
    expect($stage(['stage' => 'won'], $this->bde)->assertStatus(409)->json('error.code'))->toBe('state_conflict');
    expect($stage(['stage' => 'quotation'], $this->bde)->assertStatus(409)->json('error.message'))->toContain('cannot be moved to quotation');
    expect($stage(['stage' => 'lost'], $this->bde)->assertStatus(422)->json('error.fields'))->toHaveKey('lost_reason_id');

    $lost = $stage(['stage' => 'lost', 'lost_reason_id' => $this->reason, 'comment' => 'Went elsewhere'], $this->bde)->assertOk();
    expect($lost->json('data.stage.value'))->toBe('lost')->and($lost->json('data.lost.remarks'))->toBe('Went elsewhere')->and($lost->json('data.probability'))->toBe(0)
        ->and($deal->lead()->first()->stage)->toBe(LeadStage::Lost);

    // already lost: a plain move is a conflict with a reason, and the salesperson cannot undo the decision
    expect($stage(['stage' => 'lost', 'lost_reason_id' => $this->reason], $this->bde)->assertStatus(409)->json('error.code'))->toBe('state_conflict');
    $stage(['stage' => 'negotiation', 'comment' => 'They are back'], $this->bde)->assertForbidden();

    expect($stage(['stage' => 'negotiation'], $this->manager)->assertStatus(422)->json('error.fields'))->toHaveKey('comment');
    $back = $stage(['stage' => 'negotiation', 'comment' => 'They are back'], $this->manager)->assertOk();
    expect($back->json('data.stage.value'))->toBe('negotiation')->and($deal->lead()->first()->stage)->toBe(LeadStage::Deal);
});

it('sends a deal for approval and says so plainly when it cannot be sent again', function () {
    $deal = ($this->deal)();

    $sent = apiCall('POST', "/api/v1/deals/{$deal->id}/submit", [], $this->bde)->assertOk();
    expect($sent->json('data.approval_status.value'))->toBe('pending')->and($sent->json('data.permissions.submit'))->toBeFalse();

    $again = apiCall('POST', "/api/v1/deals/{$deal->id}/submit", [], $this->bde)->assertStatus(409);
    expect($again->json('error.code'))->toBe('state_conflict');

    // a deal without lines has nothing to approve
    $empty = ($this->deal)();
    DB::table('deal_items')->where('deal_id', $empty->id)->delete();
    expect(apiCall('POST', "/api/v1/deals/{$empty->id}/submit", [], $this->bde)->assertStatus(409)->json('error.message'))->toContain('at least one item line');

    expect($deal->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('hands a deal and its lead to another person for a manager, and not for the salesperson who holds it', function () {
    $deal = ($this->deal)();
    $lead = $deal->lead()->first();

    apiCall('POST', "/api/v1/deals/{$deal->id}/reassign", ['to_user_id' => $this->bde2->id, 'reason' => 'Territory re-allocated'], $this->bde)->assertForbidden();
    apiCall('POST', "/api/v1/deals/{$deal->id}/reassign", ['to_user_id' => $this->bde2->id, 'reason' => ''], $this->manager)->assertStatus(422);
    apiCall('POST', "/api/v1/deals/{$deal->id}/reassign", ['to_user_id' => $this->telecaller->id, 'reason' => 'Not on the team'], $this->manager)->assertStatus(422);

    $moved = apiCall('POST', "/api/v1/deals/{$deal->id}/reassign", ['to_user_id' => $this->bde2->id, 'reason' => 'Territory re-allocated'], $this->manager)->assertOk();

    expect($moved->json('data.owner.id'))->toBe($this->bde2->id)->and($lead->fresh()->assigned_to)->toBe($this->bde2->id);

    // the previous owner has lost sight of it
    ($this->get)($deal, $this->bde)->assertNotFound();
    ($this->get)($deal, $this->bde2)->assertOk();

    // a closed deal no longer changes hands
    inCompany($this->acme, fn () => app(DealService::class)->changeStage($deal->fresh(), DealStage::Lost, $this->bde2, null, $this->reason));
    expect(apiCall('POST', "/api/v1/deals/{$deal->id}/reassign", ['to_user_id' => $this->bde->id, 'reason' => 'Back again'], $this->manager)->assertStatus(409)->json('error.message'))->toContain('no longer changes hands');
});

// ======================================================================================================
// Inquiries from existing customers
// ======================================================================================================

it('finds an approved customer to name on an inquiry — a few letters, nothing more than picking needs', function () {
    makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', ['ledger' => ['name' => 'Ganesh Draft Customer']]);       // not approved
    makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'supplier', ['ledger' => ['name' => 'Ganesh Supplier']], true);        // not a customer

    $found = apiCall('GET', '/api/v1/lookups/customers', ['q' => 'ganesh'], $this->bde)->assertOk();

    expect($found->json('data'))->toHaveCount(1)
        ->and($found->json('data.0'))->toBe(['id' => $this->customer->id, 'code' => $this->customer->code, 'name' => $this->customer->name, 'gstin' => null]);

    apiCall('GET', '/api/v1/lookups/customers', ['q' => 'g'], $this->bde)->assertStatus(422);
    apiCall('GET', '/api/v1/lookups/customers', [], $this->bde)->assertStatus(422);
    apiCall('GET', '/api/v1/lookups/customers', ['q' => 'ganesh'], $this->telecaller)->assertForbidden();
});

it('enters an inquiry from an existing customer, taking the customer’s details from the ledger', function () {
    $response = apiCall('POST', '/api/v1/leads', [
        'customer_ledger_id' => $this->customer->id, 'location_id' => $this->ahd->id, 'lead_source_id' => leadSourceId($this->acme), 'name' => 'Somebody Else',
        'interests' => [['item_id' => $this->bolt->id, 'quantity' => 10, 'expected_rate' => 125.5], ['item_id' => $this->wire->id, 'quantity' => 2.5, 'expected_rate' => 33.33]],
    ], $this->manager, idem())->assertCreated();

    expect($response->json('data.type'))->toBe('existing')
        ->and($response->json('data.customer.id'))->toBe($this->customer->id)
        ->and($response->json('data.name'))->toBe($this->customer->name)
        ->and($response->json('data.mobile'))->toBe('9876543210')
        ->and($response->json('data.expected_value'))->toBe('1338.33')
        ->and($response->json('data.approval_status.value'))->toBe('draft')
        ->and($response->json('data.interests.1.expected_rate'))->toBe('33.33')
        ->and($response->json('data.deal'))->toBeNull()
        ->and($response->json('data.permissions.submit'))->toBeTrue();

    // it is listed among the customers' inquiries, and not among the new leads
    $mine = fn (string $type) => apiCall('GET', '/api/v1/leads', ['type' => $type], $this->manager)->assertOk()->json('data.*.id');
    expect($mine('existing'))->toBe([$response->json('data.id')])->and($mine('new'))->toBe([]);

    // whoever may not read the customer ledger cannot enter one; a customer that is not usable is refused
    apiCall('POST', '/api/v1/leads', ['customer_ledger_id' => $this->customer->id, 'location_id' => $this->ahd->id, 'lead_source_id' => leadSourceId($this->acme)], $this->telecaller, idem())->assertForbidden();
    $draft = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', ['ledger' => ['name' => 'Not yet approved']]);
    expect(apiCall('POST', '/api/v1/leads', ['customer_ledger_id' => $draft->id, 'location_id' => $this->ahd->id, 'lead_source_id' => leadSourceId($this->acme)], $this->manager, idem())
        ->assertStatus(422)->json('error.fields'))->toHaveKey('customer_ledger_id');
});

it('turns an inquiry into a deal for its customer, carrying its rates, and the lead shows its deal', function () {
    $inquiry = qualify($this->acme, makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '10', '100'), lineRow($this->wire, '4', '55.55')]), $this->bde);

    $made = apiCall('POST', "/api/v1/leads/{$inquiry->id}/convert", ($this->body)($inquiry, [], ['100', '55.55']), $this->bde, idem())->assertCreated();

    expect($made->json('data.customer.id'))->toBe($this->customer->id)->and($made->json('data.expected_value'))->toBe('1222.20');

    $lead = apiCall('GET', "/api/v1/leads/{$inquiry->id}", as: $this->bde)->assertOk();
    expect($lead->json('data.deal.id'))->toBe($made->json('data.id'))->and($lead->json('data.deal.deal_number'))->toBe($made->json('data.deal_number'))
        ->and($lead->json('data.stage.value'))->toBe('deal')->and($lead->json('data.permissions.convert'))->toBeFalse();

    // an inquiry that is locked by its approval cannot be edited over the API either, and says why
    $other = makeInquiry($this->acme, $this->bde, $this->ahd, $this->customer, [lineRow($this->bolt, '5', '10')]);
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($other, $this->bde));
    expect(apiCall('PATCH', "/api/v1/leads/{$other->id}", ['remarks' => 'x'], $this->bde)->assertStatus(409)->json('error.message'))->toContain('waiting for approval');
});

it('enters an inquiry from the field under the same geofence as any lead: inside it is saved, outside it is refused and logged', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    $inquiry = fn (float $latitude) => [
        'customer_ledger_id' => $this->customer->id, 'position' => ['latitude' => $latitude, 'longitude' => HQ_LNG, 'accuracy_m' => 12],
        'interests' => [['item_id' => $this->bolt->id, 'quantity' => 10, 'expected_rate' => 125.5]],
    ];

    // 900 m away from the planned stop: nothing is saved, the attempt is on record as a lead saved in the field
    apiCall('POST', '/api/v1/leads', $inquiry(HQ_LAT + 0.0081), $this->bde, idem())->assertStatus(422)->assertJsonPath('error.code', 'outside_geofence');
    expect(Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('customer_ledger_id', $this->customer->id)->count())->toBe(0)
        ->and(DB::table('geofence_violations')->where('attempt_type', 'lead_save')->count())->toBe(1);

    // at the stop it is saved: from the customer's ledger, in the plan's branch, in the field channel, owned by the person
    $saved = apiCall('POST', '/api/v1/leads', $inquiry(HQ_LAT + 0.0010), $this->bde, idem())->assertCreated();

    expect($saved->json('data.type'))->toBe('existing')
        ->and($saved->json('data.channel'))->toBe('field')
        ->and($saved->json('data.name'))->toBe($this->customer->name)
        ->and($saved->json('data.branch.id'))->toBe($this->ahd->id)
        ->and($saved->json('data.expected_value'))->toBe('1255.00')
        ->and($saved->json('data.owner.id'))->toBe($this->bde->id);
});
