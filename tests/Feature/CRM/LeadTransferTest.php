<?php

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Notifications\LeadsAssignedNotification;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

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
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);
    $this->otherTeam = makeTeam($this->acme, $this->otherManager, [$this->mumBde], 'WEST', 'West team');

    $this->transfer = fn (array $leads, $to, string $reason = 'Territory re-allocated', $actor = null) => inCompany($this->acme, fn () => app(LeadTransferService::class)
        ->transfer(array_map(fn ($lead) => $lead instanceof Lead ? $lead->id : $lead, $leads), $to->id, $reason, $actor ?? $this->manager));

    // what the service refuses, by field
    $this->refusal = function (array $leads, $to, string $reason = 'Territory re-allocated', $actor = null): array {
        try {
            ($this->transfer)($leads, $to, $reason, $actor);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };

    $this->history = fn (Lead $lead) => LeadTransfer::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->orderBy('id')->get();
});

afterEach(fn () => Carbon::setTestNow());

it('hands a lead to someone else and writes down from whom, to whom, by whom, when and why', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    $moved = ($this->transfer)([$lead], $this->bde2, 'Bhavin is on leave');

    expect($moved)->toHaveCount(1)
        ->and($moved[0]->assigned_to)->toBe($this->bde2->id)
        ->and($lead->fresh()->assigned_to)->toBe($this->bde2->id)
        ->and($lead->fresh()->sales_team_id)->toBe($this->team->id)
        ->and($lead->fresh()->updated_by)->toBe($this->manager->id);

    $history = ($this->history)($lead);

    expect($history)->toHaveCount(2)                                   // entered for bde, then handed to bde2
        ->and($history[1]->from_user_id)->toBe($this->bde->id)
        ->and($history[1]->to_user_id)->toBe($this->bde2->id)
        ->and($history[1]->from_team_id)->toBe($this->team->id)
        ->and($history[1]->to_team_id)->toBe($this->team->id)
        ->and($history[1]->transferred_by)->toBe($this->manager->id)
        ->and($history[1]->reason)->toBe('Bhavin is on leave')
        ->and($history[1]->created_at->toDateTimeString())->toBe('2026-10-12 09:30:00')
        ->and($history[1]->company_id)->toBe($this->acme->id);

    Notification::assertSentTo($this->bde2, LeadsAssignedNotification::class, fn ($n) => $n->count === 1 && $n->by === $this->manager->name && str_contains($n->url, '/crm/leads/'.$lead->id));
});

it('keeps the whole chain when a lead changes hands again and again', function () {
    $lead = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => '']);   // starts in the pool

    ($this->transfer)([$lead], $this->bde, 'First allocation');
    ($this->transfer)([$lead], $this->bde2, 'Rebalancing');
    ($this->transfer)([$lead], $this->manager, 'Key account: taking it myself');

    $history = ($this->history)($lead);

    expect($history->map(fn ($t) => [$t->from_user_id, $t->to_user_id, $t->reason])->all())->toBe([
        [null, $this->bde->id, 'First allocation'],
        [$this->bde->id, $this->bde2->id, 'Rebalancing'],
        [$this->bde2->id, $this->manager->id, 'Key account: taking it myself'],
    ])->and($lead->fresh()->assigned_to)->toBe($this->manager->id)
        ->and($lead->fresh()->sales_team_id)->toBeNull();               // a manager is not a member of the team they run

    // the manager is not told about a lead they took themselves
    Notification::assertNotSentTo($this->manager, LeadsAssignedNotification::class);
});

it('moves what was planned for the lead to the new owner, and re-arms the reminders', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $planned = makeFollowup($this->acme, $this->bde, $lead);
    $managers = makeFollowup($this->acme, $this->manager, $lead, ['assigned_to' => (string) $this->manager->id, 'scheduled_at' => '2026-10-14T15:00']);
    $planned->forceFill(['reminder_sent_at' => now()])->save();

    ($this->transfer)([$lead], $this->bde2);

    expect($planned->fresh()->assigned_to)->toBe($this->bde2->id)
        ->and($planned->fresh()->reminder_sent_at)->toBeNull()
        ->and($managers->fresh()->assigned_to)->toBe($this->manager->id);   // somebody else's follow-up on the lead stays theirs
});

it('transfers many leads at once, and tells the new owner once', function () {
    $leads = [makeLead($this->acme, $this->bde, $this->ahd), makeLead($this->acme, $this->bde, $this->ahd), makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => ''])];

    $moved = ($this->transfer)([...$leads, $leads[0]], $this->bde2);     // a repeated id is one lead

    expect($moved)->toHaveCount(3);
    foreach ($leads as $lead) {
        expect($lead->fresh()->assigned_to)->toBe($this->bde2->id);
    }

    Notification::assertSentToTimes($this->bde2, LeadsAssignedNotification::class, 1);
    Notification::assertSentTo($this->bde2, LeadsAssignedNotification::class, fn ($n) => $n->count === 3 && str_ends_with($n->url, '/crm/leads'));
});

it('moves nothing at all when one of the leads cannot be moved, and says which', function () {
    $fine = makeLead($this->acme, $this->bde, $this->ahd);
    $already = makeLead($this->acme, $this->bde2, $this->ahd);
    $lost = makeLead($this->acme, $this->bde, $this->ahd);
    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lost, LeadStage::Lost, $this->bde, null, lostReasonId($this->acme)));

    $errors = ($this->refusal)([$fine, $already, $lost], $this->bde2);

    expect($errors)->toHaveKey('leads')
        ->and($errors['leads'][0])->toStartWith('Nothing was transferred')
        ->toContain("{$already->lead_number} is already with {$this->bde2->name}")
        ->toContain("{$lost->lead_number} is lost")
        ->and($fine->fresh()->assigned_to)->toBe($this->bde->id)           // the one that could have moved did not
        ->and(($this->history)($fine))->toHaveCount(1);

    Notification::assertNothingSent();
});

it('needs a reason, a real person and at least one lead', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $outsider = makeUser(['BDE / Field Salesperson'], [$this->globex]);
    $inactive = makeUser(['BDE / Field Salesperson'], [$this->acme], null, ['is_active' => false]);

    expect(($this->refusal)([$lead], $this->bde2, ' '))->toHaveKey('reason')
        ->and(($this->refusal)([$lead], $this->bde2, 'ok'))->toHaveKey('reason')
        ->and(($this->refusal)([], $this->bde2))->toHaveKey('leads')
        ->and(($this->refusal)(['abc', null], $this->bde2))->toHaveKey('leads')
        ->and(($this->refusal)([$lead], $outsider))->toHaveKey('to_user_id')
        ->and(($this->refusal)([$lead], $inactive))->toHaveKey('to_user_id')
        ->and($lead->fresh()->assigned_to)->toBe($this->bde->id);
});

it('lets a manager hand leads only to themselves or to people on their own teams', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    // someone on another manager's team
    $errors = ($this->refusal)([$lead], $this->mumBde);
    expect($errors)->toHaveKey('to_user_id')->and($errors['to_user_id'][0])->toContain('teams you manage');

    // another manager
    expect(($this->refusal)([$lead], $this->otherManager))->toHaveKey('to_user_id');

    // an administrator may hand it to anyone — who works in the lead's branch
    $errors = ($this->refusal)([$lead], $this->mumBde, 'Moving to the west', $this->admin);
    expect($errors)->toHaveKey('leads')->and($errors['leads'][0])->toContain("is in a branch {$this->mumBde->name} does not work in");

    ($this->transfer)([$lead], $this->otherManager, 'Escalated', $this->admin);
    expect($lead->fresh()->assigned_to)->toBe($this->otherManager->id);
});

it('lets a manager hand over only leads that are theirs to hand over', function () {
    $theirs = makeLead($this->acme, $this->mumBde, $this->mum);                 // another manager's team
    $own = makeLead($this->acme, $this->bde, $this->ahd);

    $errors = ($this->refusal)([$own, $theirs], $this->bde2);

    expect($errors)->toHaveKey('leads')
        ->and($errors['leads'][0])->toContain("{$theirs->lead_number} is not one of yours to hand over")
        ->and($own->fresh()->assigned_to)->toBe($this->bde->id)
        ->and($theirs->fresh()->assigned_to)->toBe($this->mumBde->id);
});

it('does not let a follow-up on somebody else’s lead be used to take the lead', function () {
    // the manager has been asked to call the administrator's own lead: they can open it, but it is not theirs to hand over
    $lead = makeLead($this->acme, $this->admin, $this->ahd, ['assigned_to' => (string) $this->admin->id]);
    makeFollowup($this->acme, $this->admin, $lead, ['assigned_to' => (string) $this->manager->id]);

    expect(app(LeadAccess::class)->sees($this->manager, $lead))->toBeTrue();

    $errors = ($this->refusal)([$lead], $this->manager, 'Mine now');

    expect($errors)->toHaveKey('leads')
        ->and($errors['leads'][0])->toContain('is not one of yours to hand over')
        ->and($lead->fresh()->assigned_to)->toBe($this->admin->id);
});

it('cannot reach a lead of another company or of a branch the person does not work in', function () {
    $globexBranch = makeLocation($this->globex, 'NYC');
    $globexUser = makeUser(['Sales Manager'], [$this->globex]);
    $foreign = inCompany($this->globex, fn () => app(LeadService::class)
        ->create(leadInput($this->globex, $globexBranch, ['mobile' => nextMobile(), 'assigned_to' => (string) $globexUser->id]), $globexUser));

    // another company's lead is simply not there
    $errors = ($this->refusal)([$foreign], $this->bde2);
    expect($errors)->toHaveKey('leads')
        ->and($errors['leads'][0])->toContain('1 of the selected leads are not available to you')
        ->and($foreign->fresh()->assigned_to)->toBe($globexUser->id);

    // a manager who works only in Ahmedabad cannot hand over a Mumbai lead, even one of their own team's
    $localManager = makeUser(['Sales Manager'], [$this->acme], [$this->ahd]);
    $both = makeUser(['BDE / Field Salesperson'], [$this->acme]);
    makeTeam($this->acme, $localManager, [$both], 'LOCAL', 'Local team');
    $mumbaiLead = makeLead($this->acme, $both, $this->mum);

    $errors = ($this->refusal)([$mumbaiLead], $localManager, 'Taking it over', $localManager);
    expect($errors)->toHaveKey('leads')
        ->and($errors['leads'][0])->toContain('is in a branch you do not work in')
        ->and($mumbaiLead->fresh()->assigned_to)->toBe($both->id);
});

it('refuses more leads in one go than the limit', function () {
    $ids = range(900001, 900000 + LeadTransferService::MAX_PER_TRANSFER + 1);

    $errors = ($this->refusal)($ids, $this->bde2);

    expect($errors)->toHaveKey('leads')->and($errors['leads'][0])->toContain((string) LeadTransferService::MAX_PER_TRANSFER);
});

it('leaves an audit entry on the lead for the change of owner', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $this->actingAs($this->manager);

    app(LeadTransferService::class)->transfer([$lead->id], $this->bde2->id, 'Territory re-allocated', $this->manager);

    $entry = ActivityLog::withoutGlobalScopes(Tenancy::SCOPES)->where('subject_type', 'lead')->where('subject_id', $lead->id)->where('event', 'updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->manager->id)
        ->and($entry->properties['attributes']['assigned_to'])->toBe($this->bde2->id)
        ->and($entry->properties['old']['assigned_to'])->toBe($this->bde->id);
});
