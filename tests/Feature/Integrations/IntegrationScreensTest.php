<?php

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Integrations\Http\Livewire\IntegrationList;
use App\Modules\Integrations\Http\Livewire\LeadLogList;
use App\Modules\Integrations\Jobs\PullProviderLeads;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Services\LeadApiLogStore;
use App\Modules\Shared\Http\Livewire\Settings\General;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->nyc = makeLocation($this->globex, 'NYC');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->settings = app(SettingsService::class);

    // who can look, who can set up, who can run
    Role::create(['name' => 'Integration viewer'])->givePermissionTo(['integration.view']);
    Role::create(['name' => 'Integration setup only'])->givePermissionTo(['integration.view', 'integration.edit', 'lead.create']);   // may not hand leads out
    $this->viewer = makeUser(['Integration viewer'], [$this->acme]);
    $this->setupOnly = makeUser(['Integration setup only'], [$this->acme]);

    $this->hub = fn ($as = null) => Livewire::actingAs($as ?? $this->admin)->test(IntegrationList::class);
    $this->setUp = fn (string $provider, array $form, $as = null) => ($this->hub)($as)->call('edit', $provider)
        ->set('form.default_location_id', (string) $this->ahd->id)->tap(function ($c) use ($form) {
            foreach ($form as $k => $v) {
                $c->set("form.{$k}", $v);
            }
        });
});

afterEach(fn () => Carbon::setTestNow());

// ---- who sees what -------------------------------------------------------------------------------------------

it('is for administrators: people who sell have no business here', function () {
    foreach ([$this->bde, $this->manager] as $person) {
        Livewire::actingAs($person)->test(IntegrationList::class)->assertForbidden();
        Livewire::actingAs($person)->test(LeadLogList::class)->assertForbidden();
        $this->actingAs($person)->get(route('admin.integrations'))->assertForbidden();
    }

    $this->actingAs($this->admin)->get(route('admin.integrations'))->assertOk()->assertSee('IndiaMART')->assertSee('Just Dial');
});

it('makes the two connections, switched off, the first time an administrator looks — each with its own unguessable address', function () {
    ($this->hub)()->assertSeeHtml('wire:key="conn-indiamart"')->assertSeeHtml('wire:key="conn-justdial"')->assertSee('Disabled');

    $rows = IntegrationConnection::query()->orderBy('provider')->get();

    expect($rows->pluck('provider')->all())->toBe(['indiamart', 'justdial'])->and($rows->pluck('enabled')->unique()->all())->toBe([false])
        ->and($rows->pluck('mode')->all())->toBe(['pull', 'webhook'])
        ->and($rows->pluck('public_key')->unique())->toHaveCount(2)->and(strlen($rows[0]->public_key))->toBe(40);

    // looking again does not make more
    ($this->hub)();
    expect(IntegrationConnection::query()->count())->toBe(2);
});

it('lets someone who may only look see the state of things — but not the address, and not the buttons', function () {
    ($this->hub)();   // an administrator has looked: the rows exist
    $key = IntegrationConnection::query()->where('provider', 'justdial')->value('public_key');

    ($this->hub)($this->viewer)->assertOk()->assertSee('Just Dial')->assertDontSee($key)->assertDontSee('Webhook address')
        ->assertDontSee('Set up')->assertDontSee('Test connection')->assertDontSee('New address…');

    ($this->hub)($this->viewer)->call('edit', 'justdial')->assertForbidden();
    ($this->hub)($this->viewer)->call('test', 'indiamart')->assertForbidden();
    ($this->hub)($this->viewer)->call('pullNow', 'indiamart')->assertForbidden();
    ($this->hub)($this->viewer)->call('askForNewAddress', 'justdial')->assertForbidden();
});

// ---- setting a connection up -------------------------------------------------------------------------------------

it('switches IndiaMART on once its key is saved, entering leads as the person who saved it', function () {
    $this->settings->set('integrations.indiamart_api_key', 'imkey-saved-in-settings', $this->acme->id);

    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'both', 'assigned_to' => (string) $this->bde->id, 'pull_interval_minutes' => '20', 'initial_lookback_hours' => '48'])
        ->call('save')->assertHasNoErrors()->assertSet('editing', '')->assertSee('This connection is switched on');

    $c = IntegrationConnection::query()->where('provider', 'indiamart')->sole();

    expect($c->enabled)->toBeTrue()->and($c->mode)->toBe('both')->and($c->default_location_id)->toBe($this->ahd->id)->and($c->assigned_to)->toBe($this->bde->id)
        ->and($c->actor_user_id)->toBe($this->admin->id)->and($c->pull_interval_minutes)->toBe(20)->and($c->initial_lookback_hours)->toBe(48)
        ->and($c->status()->value)->toBe('configured');
});

it('will not switch a connection on half-built', function () {
    ($this->hub)()->call('edit', 'indiamart')->set('form.enabled', true)->set('form.mode', 'pull')->call('save')
        ->assertHasErrors(['form.default_location_id'])->assertSee('Choose the branch new leads go to');

    // pulling needs the key saved first
    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'pull'])->call('save')->assertHasErrors(['form.mode'])->assertSee('Save the IndiaMART API key under Company settings');
    expect(IntegrationConnection::query()->where('provider', 'indiamart')->value('enabled'))->toBeFalse();

    // …but receiving pushes does not
    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'webhook'])->call('save')->assertHasNoErrors();
    expect(IntegrationConnection::query()->where('provider', 'indiamart')->value('enabled'))->toBeTrue();
});

it('lets a connection be saved switched off with nothing chosen yet', function () {
    ($this->hub)()->call('edit', 'indiamart')->set('form.default_location_id', '')->call('save')->assertHasNoErrors();

    expect(IntegrationConnection::query()->where('provider', 'indiamart')->value('enabled'))->toBeFalse();
});

it('checks every choice on the server', function () {
    $this->settings->set('integrations.indiamart_api_key', 'imkey-saved-in-settings', $this->acme->id);
    $outsider = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $foreign = makeUser(['Company Admin'], [$this->globex]);

    ($this->setUp)('justdial', ['enabled' => true, 'mode' => 'pull'])->call('save')->assertHasErrors(['form.mode']);                            // Just Dial only pushes
    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'whatever'])->call('save')->assertHasErrors(['form.mode']);
    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'both', 'assigned_to' => (string) $outsider->id])->call('save')->assertHasErrors(['form.assigned_to']);   // does not work in AHD
    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'both', 'assigned_to' => (string) $foreign->id])->call('save')->assertHasErrors(['form.assigned_to']);    // another company
    ($this->hub)()->call('edit', 'indiamart')->set('form.default_location_id', (string) $this->nyc->id)->call('save')->assertHasErrors(['form.default_location_id']);       // another company's branch
    ($this->setUp)('indiamart', ['pull_interval_minutes' => '2'])->call('save')->assertHasErrors(['form.pull_interval_minutes']);                                          // below the provider's gap
    ($this->setUp)('indiamart', ['pull_interval_minutes' => '999'])->call('save')->assertHasErrors(['form.pull_interval_minutes']);
    ($this->setUp)('indiamart', ['initial_lookback_hours' => '500'])->call('save')->assertHasErrors(['form.initial_lookback_hours']);

    expect(IntegrationConnection::query()->where('enabled', true)->count())->toBe(0);
});

it('needs the person who saves to be one who may create and hand out leads — they are who the leads are entered as', function () {
    ($this->setUp)('justdial', ['enabled' => true, 'mode' => 'webhook'], $this->setupOnly)->call('save')
        ->assertHasErrors(['form.mode'])->assertSee('must be someone who may create and hand out leads');

    expect(IntegrationConnection::query()->where('provider', 'justdial')->value('enabled'))->toBeFalse();
});

it('audits what was changed — and never the secret address or the sync bookkeeping', function () {
    ($this->setUp)('justdial', ['enabled' => true, 'mode' => 'webhook'])->call('save')->assertHasNoErrors();

    $c = IntegrationConnection::query()->where('provider', 'justdial')->sole();
    $entry = ActivityLog::query()->where('log_name', 'integrations')->where('subject_id', $c->id)->latest('id')->first();
    $json = json_encode($entry->properties);

    expect($entry->event)->toBe('updated')->and($json)->toContain('default_location_id')->and($json)->toContain('enabled')
        ->and($json)->not->toContain($c->public_key)->not->toContain('public_key')->not->toContain('last_error');
});

// ---- secrets ---------------------------------------------------------------------------------------------------

it('shows that a key is saved, but never the key', function () {
    $this->settings->set('integrations.indiamart_api_key', 'imkey-NEVER-SHOWN-123456', $this->acme->id);
    $this->settings->set('integrations.indiamart_webhook_secret', 'whsec-NEVER-SHOWN-654321', $this->acme->id);
    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'both'])->call('save');

    ($this->hub)()->assertSee('Saved')->assertDontSee('imkey-NEVER-SHOWN-123456')->assertDontSee('whsec-NEVER-SHOWN-654321')->assertSee('Signed');
    expect(IntegrationConnection::query()->first()->toJson())->not->toContain('NEVER-SHOWN');
});

it('says pushes are unsigned until a signing secret is saved, and shows the Company settings page the way to set one', function () {
    ($this->setUp)('justdial', ['enabled' => true, 'mode' => 'webhook'])->call('save');

    ($this->hub)()->assertSee('Unsigned (the address is the only secret)');
    Livewire::actingAs($this->admin)->test(General::class)
        ->assertSee('Just Dial webhook signing secret')->assertSee('IndiaMART webhook signing secret')->assertSee('Keep the inbound-lead log for this many days');
});

// ---- the actions ----------------------------------------------------------------------------------------------

it('shows the webhook address to those who set it up, and a new address stops the old one', function () {
    ($this->setUp)('justdial', ['enabled' => true, 'mode' => 'webhook'])->call('save');
    $c = IntegrationConnection::query()->where('provider', 'justdial')->sole();
    $old = $c->public_key;

    $hub = ($this->hub)()->assertSee(url("/api/webhooks/justdial/{$old}"));

    $hub->call('askForNewAddress', 'justdial')->assertSee('A new address stops the old one at once')->call('cancelNewAddress')->assertDontSee('A new address stops the old one at once');
    expect($c->fresh()->public_key)->toBe($old);

    $hub->call('askForNewAddress', 'justdial')->call('newAddress')->assertSee('There is a new webhook address');

    $new = $c->fresh()->public_key;
    expect($new)->not->toBe($old)->and(strlen($new))->toBe(40)
        ->and(ActivityLog::query()->where('event', 'address_regenerated')->where('subject_id', $c->id)->count())->toBe(1)
        ->and(json_encode(ActivityLog::query()->where('event', 'address_regenerated')->first()->properties))->not->toContain($new);

    $this->call('POST', "/api/webhooks/justdial/{$old}", [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(justDialRecord()))->assertNotFound();
    $this->call('POST', "/api/webhooks/justdial/{$new}", [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(justDialRecord()))->assertStatus(202);
});

it('tests an IndiaMART connection and says what came back — or why not', function () {
    $this->settings->set('integrations.indiamart_api_key', 'imkey-saved-in-settings', $this->acme->id);
    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'pull'])->call('save');

    // one fake whose answer the test changes (a second Http::fake() would not replace the first)
    $answer = new ArrayObject(indiaMartAnswer([indiaMartRecord()]));
    Http::fake(fn () => Http::response($answer->getArrayCopy()));

    ($this->hub)()->call('test', 'indiamart')->assertSee('Connected. 1 lead came back');
    expect(LeadApiLog::query()->count())->toBe(0);

    ($this->hub)()->call('test', 'indiamart')->assertSee('allows one call every 5 minutes');       // the provider's gap

    Carbon::setTestNow('2026-10-12 10:10:00');
    $answer->exchangeArray(['CODE' => 401, 'STATUS' => 'FAILURE', 'MESSAGE' => 'x']);
    ($this->hub)()->call('test', 'indiamart')->assertSee('did not accept the key')->assertDontSee('imkey-saved-in-settings');
});

it('explains that Just Dial has nothing to test by calling it', function () {
    ($this->setUp)('justdial', ['enabled' => true, 'mode' => 'webhook'])->call('save');

    ($this->hub)()->call('test', 'justdial')->assertSee('sends its leads to us');
});

it('queues a pull when asked, only for a connection that pulls', function () {
    Queue::fake();
    $this->settings->set('integrations.indiamart_api_key', 'imkey-saved-in-settings', $this->acme->id);
    ($this->setUp)('indiamart', ['enabled' => true, 'mode' => 'pull'])->call('save');

    ($this->hub)()->call('pullNow', 'indiamart')->assertSee('A pull has been queued');
    Queue::assertPushed(PullProviderLeads::class, fn ($job) => $job->companyId === $this->acme->id);

    Queue::fake();
    ($this->hub)()->call('pullNow', 'justdial')->assertSee('switched off or does not pull');
    Queue::assertNothingPushed();
});

it('reports each connection’s counts and its last error', function () {
    ($this->setUp)('justdial', ['enabled' => true, 'mode' => 'webhook'])->call('save');
    $c = IntegrationConnection::query()->where('provider', 'justdial')->sole();
    $c->forceFill(['consecutive_failures' => 3, 'last_error' => 'Something the provider said', 'last_received_at' => now()->subHour()])->saveQuietly();

    foreach (['J1', 'J2'] as $id) {
        inCompany($this->acme, fn () => app(LeadApiLogStore::class)->record($c, app(ProviderRegistry::class)->get('justdial'), justDialRecord(['leadid' => $id]), 'webhook'));
    }
    LeadApiLog::query()->where('provider_lead_id', 'J2')->update(['status' => 'failed']);

    ($this->hub)()->assertSee('3 failed attempts in a row')->assertSee('Something the provider said')->assertSee('Degraded');
});

// ---- the log -----------------------------------------------------------------------------------------------------

it('lists what was delivered, with its filters, and nothing of another company', function () {
    $im = makeConnection($this->acme, $this->admin, $this->ahd);
    $jd = makeConnection($this->acme, $this->admin, $this->ahd, 'justdial');
    $foreign = makeConnection($this->globex, makeUser(['Company Admin'], [$this->globex]), $this->nyc);
    $store = fn ($company, $connection, $provider, $record) => inCompany($company, fn () => app(LeadApiLogStore::class)->record($connection, app(ProviderRegistry::class)->get($provider), $record, 'pull')[0]);

    $a = $store($this->acme, $im, 'indiamart', indiaMartRecord(['UNIQUE_QUERY_ID' => 'IM-A']));
    $b = $store($this->acme, $im, 'indiamart', indiaMartRecord(['UNIQUE_QUERY_ID' => 'IM-B']));
    $c = $store($this->acme, $jd, 'justdial', justDialRecord(['leadid' => 'JD-C']));
    $theirs = $store($this->globex, $foreign, 'indiamart', indiaMartRecord(['UNIQUE_QUERY_ID' => 'IM-THEIRS']));
    LeadApiLog::query()->whereKey($b->id)->update(['status' => 'failed', 'error' => 'The standard source is switched off']);

    $page = Livewire::actingAs($this->admin)->test(LeadLogList::class);

    $page->assertSeeHtml('wire:key="log-'.$a->id.'"')->assertSeeHtml('wire:key="log-'.$b->id.'"')->assertSeeHtml('wire:key="log-'.$c->id.'"')->assertDontSeeHtml('wire:key="log-'.$theirs->id.'"');

    $page->set('provider', 'justdial')->assertSeeHtml('wire:key="log-'.$c->id.'"')->assertDontSeeHtml('wire:key="log-'.$a->id.'"')
        ->set('provider', '')->set('status', 'failed')->assertSeeHtml('wire:key="log-'.$b->id.'"')->assertDontSeeHtml('wire:key="log-'.$a->id.'"')->assertSee('The standard source is switched off')
        ->set('status', '')->set('search', 'IM-A')->assertSeeHtml('wire:key="log-'.$a->id.'"')->assertDontSeeHtml('wire:key="log-'.$b->id.'"');
});

it('shows a delivery as it arrived — without its secrets', function () {
    $im = makeConnection($this->acme, $this->admin, $this->ahd);
    $log = inCompany($this->acme, fn () => app(LeadApiLogStore::class)->record($im, app(ProviderRegistry::class)->get('indiamart'), indiaMartRecord(['glusr_crm_key' => 'imkey-LEAK-CHECK-1']), 'webhook')[0]);

    Livewire::actingAs($this->admin)->test(LeadLogList::class)->call('inspect', $log->id)
        ->assertSee('Shree Ganesh Traders')->assertSee('Hex Bolt M10')->assertSee('[removed]')->assertDontSee('imkey-LEAK-CHECK-1')
        ->call('closeInspector')->assertDontSee('As received, with anything secret removed');
});

it('retries a failed delivery from the log once the cause is fixed — and only a failed one, and only by someone who may', function () {
    $im = makeConnection($this->acme, $this->admin, $this->ahd);
    LeadSource::query()->where('code', 'INDIAMART')->update(['status' => 'inactive']);
    $log = inCompany($this->acme, fn () => app(LeadApiLogStore::class)->record($im, app(ProviderRegistry::class)->get('indiamart'), indiaMartRecord(), 'webhook')[0]);
    app(LeadApiLogStore::class)->dispatch($log);
    expect($log->fresh()->status->value)->toBe('failed');

    Livewire::actingAs($this->viewer)->test(LeadLogList::class)->call('retry', $log->id)->assertForbidden();
    Livewire::actingAs($this->admin)->test(LeadLogList::class)->call('retry', $log->id)->assertSee('It failed again');

    LeadSource::query()->where('code', 'INDIAMART')->update(['status' => 'active']);
    Livewire::actingAs($this->admin)->test(LeadLogList::class)->call('retry', $log->id)->assertSee('Processed: Lead created');

    expect($log->fresh()->status->value)->toBe('created')->and(Lead::query()->count())->toBe(1);

    Livewire::actingAs($this->admin)->test(LeadLogList::class)->call('retry', $log->id)->assertForbidden();   // it is no longer failed
});

it('can be opened and retried only inside the company', function () {
    $foreign = makeConnection($this->globex, makeUser(['Company Admin'], [$this->globex]), $this->nyc);
    $log = inCompany($this->globex, fn () => app(LeadApiLogStore::class)->record($foreign, app(ProviderRegistry::class)->get('indiamart'), indiaMartRecord(), 'webhook')[0]);
    LeadApiLog::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($log->id)->update(['status' => 'failed']);

    Livewire::actingAs($this->admin)->test(LeadLogList::class)->call('inspect', $log->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test(LeadLogList::class)->call('retry', $log->id)->assertNotFound();
});

it('links a delivery to its lead for the people who may open that lead', function () {
    $im = makeConnection($this->acme, $this->admin, $this->ahd);
    $log = inCompany($this->acme, fn () => app(LeadApiLogStore::class)->record($im, app(ProviderRegistry::class)->get('indiamart'), indiaMartRecord(), 'webhook')[0]);
    app(LeadApiLogStore::class)->dispatch($log);
    $lead = Lead::query()->sole();

    Livewire::actingAs($this->admin)->test(LeadLogList::class)->assertSeeHtml('href="'.route('crm.leads.show', $lead).'"')->assertSee($lead->lead_number);
});
