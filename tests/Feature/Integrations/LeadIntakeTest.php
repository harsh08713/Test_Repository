<?php

use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Notifications\LeadsAssignedNotification;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Integrations\Enums\InboundStatus;
use App\Modules\Integrations\Exceptions\IntakeProblem;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Notifications\MarketplaceEnquiryNotification;
use App\Modules\Integrations\Notifications\PoolLeadNotification;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Services\LeadApiLogStore;
use App\Modules\Integrations\Services\LeadIntake;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);
    $this->connection = makeConnection($this->acme, $this->admin, $this->ahd);
    $this->providers = app(ProviderRegistry::class);

    // a delivery arrives, is written down, and is processed — as a worker would
    $this->deliver = function (array $record, string $provider = 'indiamart', ?object $connection = null): LeadApiLog {
        $connection ??= $this->connection;

        return inCompany($this->acme, function () use ($record, $provider, $connection) {
            [$log] = app(LeadApiLogStore::class)->record($connection, $this->providers->get($provider), $record, 'pull');

            return $log;
        });
    };
    $this->process = fn (LeadApiLog $log) => inCompany($this->acme, fn () => app(LeadIntake::class)->process($log));
    $this->leadOf = fn (LeadApiLog $log) => Lead::query()->findOrFail($log->fresh()->lead_id);
});

afterEach(fn () => Carbon::setTestNow());

// ---- a new lead -----------------------------------------------------------------------------------------

it('turns an IndiaMART enquiry into a lead in the connection’s branch, under IndiaMART, waiting in the pool', function () {
    $log = ($this->deliver)(indiaMartRecord());

    expect(($this->process)($log))->toBe(InboundStatus::Created);

    $lead = ($this->leadOf)($log);
    $log->refresh();

    expect($lead->entry_channel)->toBe(LeadChannel::IndiaMart)->and($lead->source->code)->toBe('INDIAMART')
        ->and($lead->location_id)->toBe($this->ahd->id)->and($lead->assigned_to)->toBeNull()->and($lead->stage)->toBe(LeadStage::New)
        ->and($lead->created_by)->toBe($this->admin->id)->and($lead->lead_number)->toStartWith('LD/AHD/26-27/')
        ->and($lead->name)->toBe('Shree Ganesh Traders')->and($lead->contact_person)->toBe('Meera Shah')
        ->and($lead->mobile)->toBe('9876543210')->and($lead->email)->toBe('meera@example.test')
        ->and($lead->city)->toBe('Ahmedabad')->and($lead->address_line1)->toBe('12 Ashram Road')->and($lead->pin_code)->toBe('380001')
        ->and($lead->state_id)->toBe($this->geo['gj']->id)->and($lead->district_id)->toBe($this->geo['ahmedabad']->id)->and($lead->block_id)->toBe($this->geo['daskroi']->id)
        ->and($lead->requirement)->toBe("Need 5000 pcs.\nPlease quote.")
        ->and($lead->lead_date->toDateString())->toBe('2026-10-12')
        ->and($lead->is_complete)->toBeTrue()
        ->and($lead->interests()->pluck('description')->all())->toBe(['Hex Bolt M10'])
        ->and($lead->remarks)->toContain('IndiaMART direct enquiry (id 2026101200001)')->toContain('received 12 Oct 2026, 08:45')
        ->and($log->status)->toBe(InboundStatus::Created)->and($log->lead_id)->toBe($lead->id)->and($log->attempts)->toBe(1)
        ->and($log->processed_at)->not->toBeNull()->and($log->error)->toBeNull();
});

it('gives the lead to the person the connection names, and tells them', function () {
    $connection = makeConnection($this->acme, $this->admin, $this->ahd, 'justdial', ['assigned_to' => $this->bde->id]);

    $log = ($this->deliver)(justDialRecord(), 'justdial', $connection);
    ($this->process)($log);

    $lead = ($this->leadOf)($log);

    expect($lead->assigned_to)->toBe($this->bde->id)->and($lead->entry_channel)->toBe(LeadChannel::JustDial)->and($lead->source->code)->toBe('JUSTDIAL');
    Notification::assertSentTo($this->bde, LeadsAssignedNotification::class);
    Notification::assertNotSentTo($this->admin, LeadsAssignedNotification::class);
});

it('tells the people who hand leads out when one lands in the pool — in the app only, and not people who cannot', function () {
    $log = ($this->deliver)(indiaMartRecord());
    ($this->process)($log);

    Notification::assertSentTo($this->manager, PoolLeadNotification::class, fn ($n) => $n->incomplete === false && $n->provider === 'IndiaMART' && $n->via($this->manager) === ['database']);
    Notification::assertSentTo($this->admin, PoolLeadNotification::class);
    Notification::assertNotSentTo($this->bde, PoolLeadNotification::class);
});

it('keeps a lead that arrives without a mobile number — incomplete, so it cannot be qualified yet', function () {
    $log = ($this->deliver)(['UNIQUE_QUERY_ID' => 'Q-NOMOB', 'SENDER_NAME' => 'Meera Shah', 'SENDER_EMAIL' => 'meera@example.test', 'QUERY_MESSAGE' => 'Price list please']);
    ($this->process)($log);

    $lead = ($this->leadOf)($log);

    expect($lead->mobile)->toBeNull()->and($lead->is_complete)->toBeFalse()->and($lead->name)->toBe('Meera Shah')
        ->and($lead->remarks)->toContain('No mobile number was given')
        ->and(fn () => inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lead, LeadStage::Qualified, $this->admin)))
        ->toThrow(FieldRuleException::class, 'Complete the lead');
    Notification::assertSentTo($this->manager, PoolLeadNotification::class, fn ($n) => $n->incomplete === true);
});

it('keeps a lead whose buyer gave no name — the placeholder is not a name — incomplete', function () {
    $log = ($this->deliver)(['UNIQUE_QUERY_ID' => 'Q-ANON', 'SENDER_NAME' => 'IndiaMART Buyer', 'SENDER_MOBILE' => '9811122233']);
    ($this->process)($log);

    $lead = ($this->leadOf)($log);

    expect($lead->name)->toBe('IndiaMART enquiry 9811122233')->and($lead->contact_person)->toBeNull()->and($lead->mobile)->toBe('9811122233')
        ->and($lead->is_complete)->toBeFalse()->and($lead->remarks)->toContain('The buyer gave no name');
});

it('leaves off — and notes — what is not a valid mobile number, e-mail address or PIN code, rather than refusing the lead', function () {
    $log = ($this->deliver)(indiaMartRecord([
        'UNIQUE_QUERY_ID' => 'Q-ODD', 'SENDER_MOBILE' => '+1 415 555 0100', 'SENDER_COUNTRY_ISO' => 'US', 'SENDER_EMAIL' => 'not an address', 'SENDER_PINCODE' => '12',
    ]));
    ($this->process)($log);

    $lead = ($this->leadOf)($log);

    expect($lead->mobile)->toBeNull()->and($lead->email)->toBeNull()->and($lead->pin_code)->toBeNull()->and($lead->is_complete)->toBeFalse()
        ->and($lead->remarks)->toContain('Phone as given: +1 415 555 0100 (US) — not an Indian mobile number')
        ->and($lead->remarks)->toContain('E-mail as given: not an address')->toContain('PIN as given: 12')->toContain('State as given: Gujarat');
});

it('never dates a lead in the future, whatever the provider’s clock says', function () {
    $future = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-F', 'QUERY_TIME' => '2026-10-20 10:00:00']));
    $junk = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-J', 'SENDER_MOBILE' => '9811100001', 'QUERY_TIME' => 'sometime']));
    $past = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-P', 'SENDER_MOBILE' => '9811100002', 'QUERY_TIME' => '2026-10-03 11:00:00']));

    foreach ([$future, $junk, $past] as $log) {
        ($this->process)($log);
    }

    expect(($this->leadOf)($future)->lead_date->toDateString())->toBe('2026-10-12')->and(($this->leadOf)($junk)->lead_date->toDateString())->toBe('2026-10-12')
        ->and(($this->leadOf)($past)->lead_date->toDateString())->toBe('2026-10-03');
});

it('flags a number on the do-not-call register in the lead’s remarks', function () {
    $connection = makeConnection($this->acme, $this->admin, $this->ahd, 'justdial');
    $log = ($this->deliver)(justDialRecord(['dncmobile' => '1']), 'justdial', $connection);
    ($this->process)($log);

    expect(($this->leadOf)($log)->remarks)->toContain('On the do-not-call register: do not phone this number.');
});

it('limits long text to what a lead can hold, and records it', function () {
    $log = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-LONG', 'SENDER_COMPANY' => str_repeat('A', 400), 'QUERY_MESSAGE' => str_repeat('word ', 900), 'QUERY_PRODUCT_NAME' => str_repeat('P', 500)]));
    ($this->process)($log);

    $lead = ($this->leadOf)($log);

    expect(mb_strlen($lead->name))->toBe(200)->and(mb_strlen($lead->requirement))->toBeLessThanOrEqual(2000)->and(mb_strlen($lead->interests()->value('description')))->toBe(200)
        ->and($log->fresh()->status)->toBe(InboundStatus::Created);
});

// ---- the same buyer, the same enquiry -------------------------------------------------------------------

it('does not store the same enquiry twice, however often it is delivered, and counts the deliveries', function () {
    $first = ($this->deliver)(indiaMartRecord());
    $again = ($this->deliver)(indiaMartRecord());
    $third = ($this->deliver)(indiaMartRecord());

    expect($again->id)->toBe($first->id)->and($third->id)->toBe($first->id)
        ->and(LeadApiLog::query()->count())->toBe(1)->and($first->fresh()->received_count)->toBe(3);
});

it('processes a delivery once even when asked twice', function () {
    $log = ($this->deliver)(indiaMartRecord());

    ($this->process)($log);
    $second = ($this->process)($log);

    expect($second)->toBe(InboundStatus::Created)->and(Lead::query()->count())->toBe(1)->and($log->fresh()->attempts)->toBe(1);
});

it('adds a second enquiry from a number that is already on an open lead to that lead, instead of making another', function () {
    $mine = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '9876543210', 'name' => 'Existing Traders']);
    $log = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-AGAIN']));

    expect(($this->process)($log))->toBe(InboundStatus::Linked);

    $log->refresh();
    $note = Comment::query()->where('commentable_id', $mine->id)->where('commentable_type', 'lead')->sole();

    expect(Lead::query()->count())->toBe(1)->and($log->status)->toBe(InboundStatus::Linked)->and($log->lead_id)->toBe($mine->id)
        ->and($note->body)->toContain('Another IndiaMART enquiry from this number (id Q-AGAIN)')->toContain('Direct enquiry')->toContain('Need 5000 pcs.')
        ->and($note->user_id)->toBe($this->admin->id)->and($note->visibility)->toBe('internal')->and((int) $note->company_id)->toBe($this->acme->id)
        ->and($mine->fresh()->updated_at->toDateTimeString())->toBe('2026-10-12 10:00:00');
    Notification::assertSentTo($this->bde, MarketplaceEnquiryNotification::class, fn ($n) => $n->provider === 'IndiaMART' && str_contains($n->summary, 'Need 5000 pcs.'));
});

it('does the same when the open lead is in another branch, or in the pool', function () {
    $other = makeLead($this->acme, $this->manager, $this->mum, ['mobile' => '9876543210', 'assigned_to' => '']);
    $log = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-X']));

    expect(($this->process)($log))->toBe(InboundStatus::Linked)->and($log->fresh()->lead_id)->toBe($other->id);
    Notification::assertNothingSentTo($this->bde);   // nobody owns it: there is nobody to tell
});

it('makes a new lead when the earlier one is closed — a lost enquiry that comes back is a new chance', function () {
    $old = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '9876543210']);
    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($old, LeadStage::Lost, $this->bde, null, lostReasonId($this->acme)));

    $log = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-BACK']));

    expect(($this->process)($log))->toBe(InboundStatus::Created)->and(Lead::query()->count())->toBe(2)->and($log->fresh()->lead_id)->not->toBe($old->id);
});

it('makes one lead when two enquiries from one buyer are processed one after the other', function () {
    $a = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-A']));
    $b = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-B', 'QUERY_MESSAGE' => 'Also need washers']));

    expect(($this->process)($a))->toBe(InboundStatus::Created)->and(($this->process)($b))->toBe(InboundStatus::Linked)
        ->and(Lead::query()->count())->toBe(1)->and($b->fresh()->lead_id)->toBe($a->fresh()->lead_id);
});

// ---- things that go wrong -------------------------------------------------------------------------------

it('keeps a delivery that cannot become a lead as failed, with its payload and a reason a person can act on — and retries once that is fixed', function () {
    LeadSource::query()->where('code', 'INDIAMART')->update(['status' => 'inactive']);
    $log = ($this->deliver)(indiaMartRecord());

    expect(fn () => ($this->process)($log))->toThrow(IntakeProblem::class, 'IndiaMART” lead source is missing or switched off');

    $log->refresh();
    expect($log->status)->toBe(InboundStatus::Failed)->and($log->error)->toContain('Lead masters')->and($log->attempts)->toBe(1)
        ->and($log->payload['UNIQUE_QUERY_ID'])->toBe('2026101200001')->and(Lead::query()->count())->toBe(0);

    LeadSource::query()->where('code', 'INDIAMART')->update(['status' => 'active']);

    expect(($this->process)($log))->toBe(InboundStatus::Created)->and($log->fresh()->attempts)->toBe(2)->and($log->fresh()->error)->toBeNull()->and(Lead::query()->count())->toBe(1);
});

it('says what is wrong with a connection that was never finished, or whose person has left', function () {
    $noBranch = makeConnection($this->acme, $this->admin, $this->ahd, 'justdial', ['default_location_id' => null]);
    $log = ($this->deliver)(justDialRecord(['leadid' => 'J1']), 'justdial', $noBranch);
    expect(fn () => ($this->process)($log))->toThrow(IntakeProblem::class, 'no branch or no person');

    DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);
    $gone = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-G']));
    expect(fn () => ($this->process)($gone))->toThrow(IntakeProblem::class, 'no longer an active user');
    DB::table('users')->where('id', $this->admin->id)->update(['is_active' => true]);

    $operations = makeUser(['Operations User'], [$this->acme]);
    DB::table('integration_connections')->where('id', $this->connection->id)->update(['actor_user_id' => $operations->id]);
    $log = ($this->deliver)(indiaMartRecord(['UNIQUE_QUERY_ID' => 'Q-C']));
    expect(fn () => ($this->process)($log))->toThrow(IntakeProblem::class, 'may no longer create leads');

    expect(LeadApiLog::query()->where('status', 'failed')->count())->toBe(3)->and(Lead::query()->count())->toBe(0);
});

it('refuses, with the reason, a lead whose branch the connection’s person may not use', function () {
    DB::table('locations')->where('id', $this->ahd->id)->update(['status' => 'inactive']);
    $log = ($this->deliver)(indiaMartRecord());

    expect(fn () => ($this->process)($log))->toThrow(IntakeProblem::class, 'The lead was refused (location_id: Choose one of your active branches.)');
});

it('claims a delivery that a dead worker left half-way, but not one that is genuinely being worked on', function () {
    $log = ($this->deliver)(indiaMartRecord());
    DB::table('lead_api_logs')->where('id', $log->id)->update(['status' => 'processing', 'updated_at' => now()->subMinutes(2)]);

    expect(($this->process)($log->fresh()))->toBe(InboundStatus::Processing)->and(Lead::query()->count())->toBe(0);   // someone else has it

    DB::table('lead_api_logs')->where('id', $log->id)->update(['updated_at' => now()->subMinutes(11)]);

    expect(($this->process)($log->fresh()))->toBe(InboundStatus::Created)->and(Lead::query()->count())->toBe(1);
});

it('keeps keys and secrets out of everything it stores, including a failure message', function () {
    app(SettingsService::class)->set('integrations.indiamart_api_key', 'im-secret-KEY-9999', $this->acme->id);
    $record = indiaMartRecord(['glusr_crm_key' => 'im-secret-KEY-9999', 'QUERY_MESSAGE' => 'ref https://x.test/?glusr_crm_key=im-secret-KEY-9999']);
    $log = ($this->deliver)($record);

    expect(json_encode($log->payload))->not->toContain('im-secret-KEY-9999')->and($log->payload['glusr_crm_key'])->toBe('[removed]');

    LeadSource::query()->where('code', 'INDIAMART')->update(['status' => 'inactive']);
    try {
        ($this->process)($log);
    } catch (IntakeProblem) {
    }

    expect($log->fresh()->error)->not->toContain('im-secret-KEY-9999');
});

it('records the lead’s arrival in the audit trail without naming a person as its author', function () {
    $log = ($this->deliver)(indiaMartRecord());
    ($this->process)($log);

    $lead = ($this->leadOf)($log);
    $entry = ActivityLog::query()->where('log_name', 'integrations')->where('event', 'received')->sole();

    expect($entry->subject_id)->toBe($lead->id)->and($entry->properties['provider'])->toBe('indiamart')->and($entry->properties['provider_lead_id'])->toBe('2026101200001')
        ->and($entry->properties['incomplete'])->toBeFalse()->and($entry->causer_id)->toBeNull();
});

it('does not reach into another company', function () {
    $foreign = makeUser(['Company Admin'], [$this->globex]);
    $branch = makeLocation($this->globex, 'NYC');
    $theirs = makeConnection($this->globex, $foreign, $branch);

    $log = inCompany($this->globex, function () use ($theirs) {
        [$row] = app(LeadApiLogStore::class)->record($theirs, $this->providers->get('indiamart'), indiaMartRecord(), 'pull');

        return $row;
    });
    inCompany($this->globex, fn () => app(LeadIntake::class)->process($log));

    // the same provider id in another company is another delivery, and nothing of it is ours
    $mine = ($this->deliver)(indiaMartRecord());
    ($this->process)($mine);

    expect(LeadApiLog::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(2)
        ->and(Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->count())->toBe(1)
        ->and(Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->count())->toBe(1)
        ->and(Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->value('location_id'))->toBe($branch->id);
});
