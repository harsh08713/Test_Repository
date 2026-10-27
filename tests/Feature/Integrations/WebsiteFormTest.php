<?php

use App\Modules\CRM\Models\Lead;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Providers\WebsiteProvider;
use App\Modules\Integrations\Services\WebsiteOrigins;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\ContactConsent;
use App\Modules\Marketing\Services\CampaignAttribution;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

const SITE = 'https://www.acme.test';

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->nyc = makeLocation($this->globex, 'NYC');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->other = makeUser(['Company Admin'], [$this->globex]);

    $this->form = makeConnection($this->acme, $this->admin, $this->ahd, 'website');
    $this->theirs = makeConnection($this->globex, $this->other, $this->nyc, 'website');
    $this->settings = app(SettingsService::class);
    $this->settings->set(WebsiteOrigins::SETTING, SITE.', http://localhost:3000', $this->acme->id);

    $this->adapter = new WebsiteProvider;
    $this->url = fn ($connection = null) => '/api/webhooks/website/'.($connection ?? $this->form)->public_key;
    // a visitor's browser: JSON from a page on `$origin` (none = a server posting the form)
    $this->visit = fn (array|string $body, ?string $origin = SITE, $connection = null, array $server = []) => $this->call(
        'POST', ($this->url)($connection), [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...($origin === null ? [] : ['HTTP_ORIGIN' => $origin]), ...$server],
        is_string($body) ? $body : json_encode($body),
    );
});

afterEach(fn () => Carbon::setTestNow());

// ---- the adapter: only its wire format ---------------------------------------------------------------------

it('is a push-only provider registered beside the marketplaces, filed under the Website source', function () {
    $provider = app(ProviderRegistry::class)->get('website');

    expect($provider->canPush())->toBeTrue()->and($provider->canPull())->toBeFalse()->and($provider->sourceCode())->toBe('WEBSITE')
        ->and($provider->channel()->value)->toBe('website')->and(fn () => $provider->fetch('k', now()->subHour(), now()))->toThrow(Exception::class, 'nothing to pull');
});

it('reads one flat submission, or one inside a "lead" wrapper, and nothing that is not a way to reach a person', function () {
    $flat = $this->adapter->extractRecords(['Name' => 'A', 'MOBILE' => '9811100001', 'thanks_url' => 'https://www.acme.test/thanks']);

    expect($flat)->toBe([['name' => 'A', 'mobile' => '9811100001']])                                  // keys read in lower case; thanks_url is not part of the lead
        ->and($this->adapter->extractRecords(['lead' => ['name' => 'B', 'email' => 'b@example.test']]))->toBe([['name' => 'B', 'email' => 'b@example.test']])
        ->and($this->adapter->extractRecords([['mobile' => '9811100001'], ['mobile' => '9811100002']]))->toBe([])   // a list is not a form
        ->and($this->adapter->extractRecords(['name' => 'No way to reach me', 'message' => 'hello']))->toBe([])
        ->and($this->adapter->extractRecords([]))->toBe([]);
});

it('names a submission by the page’s own id, or by its content and the day', function () {
    $named = $this->adapter->providerLeadId(['submission_id' => 'abc-12345678', 'mobile' => '9811100001']);
    $content = fn (array $over = []) => $this->adapter->providerLeadId(['mobile' => '98111 00001', 'message' => 'hi', 'name' => 'A', ...$over]);

    expect($named)->toBe('s-abc-12345678')
        ->and($this->adapter->providerLeadId(['submission_id' => 'x y;DROP', 'mobile' => '9811100001']))->toStartWith('w-')   // an id with odd characters is not trusted as an id
        ->and($content(['mobile' => '98111-00001']))->toBe($content())                                                          // spacing in the number is irrelevant
        ->and($content(['message' => 'another']))->not->toBe($content());

    $today = $content();
    Carbon::setTestNow('2026-10-13 10:00:00');

    expect($content())->not->toBe($today);   // the same words tomorrow are a new enquiry
});

it('turns a submission into a lead: company over person, the page without its query string, only a web address', function () {
    $lead = $this->adapter->normalise(websiteRecord());

    expect($lead->name)->toBe('Shree Ganesh Traders')->and($lead->contactPerson)->toBe('Meera Shah')->and($lead->mobile)->toBe('98765 43210')
        ->and($lead->email)->toBe('Meera@Example.test')->and($lead->pinCode)->toBe('380001')->and($lead->product)->toBe('Hex Bolt M10')
        ->and($lead->kind)->toBe('Contact form')->and($lead->pageUrl)->toBe('https://www.acme.test/contact')   // no ?token=…, no #fragment
        ->and($lead->namePlaceholder)->toBeFalse();

    expect($this->adapter->normalise(['mobile' => '9811100001', 'page_url' => 'javascript:alert(1)'])->pageUrl)->toBeNull()
        ->and($this->adapter->normalise(['mobile' => '9811100001'])->namePlaceholder)->toBeTrue()
        ->and($this->adapter->normalise(['first_name' => 'Asha', 'last_name' => 'Rao', 'mobile' => '9811100001'])->name)->toBe('Asha Rao');
});

it('takes a tick as consent only with the wording and the channels it covered', function () {
    $tick = fn (array $over) => $this->adapter->normalise(['mobile' => '9811100001', 'email' => 'a@example.test', ...$over]);

    $full = $tick(['consent' => 'on', 'consent_text' => 'I agree to be contacted about my enquiry.', 'consent_channels' => 'email, WhatsApp , fax']);

    expect($full->consentChannels)->toBe(['email', 'whatsapp'])->and($full->consentText)->toBe('I agree to be contacted about my enquiry.')
        ->and($tick(['consent' => 'on', 'consent_channels' => ['email']])->consentChannels)->toBe([])                              // no wording, no consent
        ->and($tick(['consent' => 'no', 'consent_text' => 'I agree', 'consent_channels' => ['email']])->consentChannels)->toBe([])
        ->and($tick(['consent' => 'true', 'consent_text' => 'I agree'])->consentChannels)->toBe([])                                  // no channel named
        ->and($tick(['consent' => '1', 'consent_text' => 'I agree', 'consent_channels' => ['sms', 'sms']])->consentChannels)->toBe(['sms']);
});

it('recognises a robot by the trap field and hands out a form that has the trap and the address', function () {
    expect($this->adapter->isBot(['mobile' => '9811100001', '_gotcha' => 'http://spam.example']))->toBeTrue()
        ->and($this->adapter->isBot(['mobile' => '9811100001', '_gotcha' => '']))->toBeFalse()
        ->and($this->adapter->isBot(['mobile' => '9811100001']))->toBeFalse();

    $html = $this->adapter->snippet('https://crm.example.test/api/webhooks/website/KEY');

    expect($html)->toContain('name="_gotcha"')->toContain('"https://crm.example.test/api/webhooks/website/KEY"')->toContain('crypto.randomUUID()');
});

it('compares websites as scheme, host and port — exactly', function () {
    expect(WebsiteOrigins::normalise('HTTPS://Www.Acme.test:443/contact?x=1'))->toBe('https://www.acme.test')
        ->and(WebsiteOrigins::normalise('http://localhost:3000/'))->toBe('http://localhost:3000')
        ->and(WebsiteOrigins::normalise('https://acme.test@evil.example/'))->toBe('https://evil.example')   // the host is what comes after the @
        ->and(WebsiteOrigins::normalise('javascript:alert(1)'))->toBeNull()->and(WebsiteOrigins::normalise('null'))->toBeNull()->and(WebsiteOrigins::normalise(''))->toBeNull();

    $origins = app(WebsiteOrigins::class);

    expect($origins->allows($this->acme->id, SITE))->toBeTrue()->and($origins->allows($this->acme->id, 'http://localhost:3000'))->toBeTrue()
        ->and($origins->allows($this->acme->id, 'http://www.acme.test'))->toBeFalse()          // http is not https
        ->and($origins->allows($this->acme->id, 'https://acme.test'))->toBeFalse()             // no wildcards, no "www" guessing
        ->and($origins->allows($this->acme->id, 'https://www.acme.test.evil.example'))->toBeFalse()
        ->and($origins->allows($this->globex->id, SITE))->toBeFalse();                          // another company listed nothing
});

// ---- a visitor's browser ---------------------------------------------------------------------------------------

it('takes a post from a listed website: writes it down, makes the lead under the Website source and lets the page read the answer', function () {
    $response = ($this->visit)(websiteRecord())->assertStatus(202);

    $lead = Lead::query()->sole();

    expect($response->json('data'))->toBe(['status' => 'accepted', 'received' => 1, 'new' => 1, 'already_known' => 0])
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe(SITE)->and($response->headers->get('Vary'))->toContain('Origin')
        ->and($lead->entry_channel->value)->toBe('website')->and($lead->source->code)->toBe('WEBSITE')->and($lead->name)->toBe('Shree Ganesh Traders')
        ->and($lead->mobile)->toBe('9876543210')->and($lead->email)->toBe('meera@example.test')
        ->and($lead->remarks)->toContain('Website form contact form')->toContain('Sent from the page https://www.acme.test/contact.')->not->toContain('SECRET-IN-LINK')
        ->and(LeadApiLog::query()->sole()->via)->toBe('webhook')
        ->and($this->form->fresh()->last_received_at)->not->toBeNull();
});

it('answers the browser’s preflight for a listed site, and refuses every other site', function () {
    $preflight = fn (?string $origin, $connection = null) => $this->call('OPTIONS', ($this->url)($connection), [], [], [], [
        'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ]);

    $ok = $preflight(SITE)->assertStatus(204);

    expect($ok->headers->get('Access-Control-Allow-Origin'))->toBe(SITE)->and($ok->headers->get('Access-Control-Allow-Methods'))->toContain('POST')
        ->and($ok->headers->get('Access-Control-Allow-Headers'))->toContain('Content-Type')->and($ok->headers->get('Access-Control-Allow-Credentials'))->toBeNull();

    $refused = $preflight('https://evil.example')->assertStatus(403);

    expect($refused->headers->get('Access-Control-Allow-Origin'))->toBeNull()->and($refused->json('error.code'))->toBe('origin_not_allowed');

    $preflight(SITE, $this->theirs)->assertStatus(403);   // Globex has not listed Acme's site
});

it('refuses a post from a website that is not listed, and writes nothing down', function () {
    foreach (['https://evil.example', 'http://www.acme.test', 'null'] as $origin) {
        $response = ($this->visit)(websiteRecord(), $origin)->assertStatus(403);

        expect($response->json('error.code'))->toBe('origin_not_allowed')->and($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
    }

    expect(LeadApiLog::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0);
});

it('allows no browser at all until the company lists a website — a server posting the form needs none', function () {
    $this->settings->set(WebsiteOrigins::SETTING, '', $this->acme->id);

    ($this->visit)(websiteRecord())->assertStatus(403);
    ($this->visit)(websiteRecord(), null)->assertStatus(202);   // no Origin header: not a browser

    expect(Lead::query()->count())->toBe(1);
});

it('answers a wrong, switched-off or other-provider address 404 with no hint, preflight included', function () {
    $this->form->forceFill(['enabled' => false])->saveQuietly();

    ($this->visit)(websiteRecord())->assertNotFound();
    $this->call('OPTIONS', ($this->url)(), [], [], [], ['HTTP_ORIGIN' => SITE, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])->assertNotFound();

    $this->form->forceFill(['enabled' => true])->saveQuietly();
    $bad = $this->call('POST', '/api/webhooks/website/'.str_repeat('a', 40), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => SITE], json_encode(websiteRecord()));

    expect($bad->status())->toBe(404)->and($bad->headers->get('Access-Control-Allow-Origin'))->toBeNull();

    // the marketplaces' addresses are not website forms: no CORS for a browser, even from a listed site
    $im = makeConnection($this->acme, $this->admin, $this->ahd, 'indiamart', ['mode' => 'webhook']);
    $this->call('OPTIONS', '/api/webhooks/indiamart/'.$im->public_key, [], [], [], ['HTTP_ORIGIN' => SITE, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])->assertNotFound();
    $post = $this->call('POST', '/api/webhooks/indiamart/'.$im->public_key, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => SITE], json_encode(indiaMartRecord()));

    expect($post->status())->toBe(202)->and($post->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('lets the page read why a post failed — a bad body and a throttle carry the site’s headers too', function () {
    $bad = ($this->visit)(['name' => 'No way to reach me'])->assertStatus(400);

    expect($bad->headers->get('Access-Control-Allow-Origin'))->toBe(SITE);

    foreach (range(1, 9) as $i) {
        ($this->visit)(websiteRecord(['submission_id' => "sub-{$i}0000000", 'mobile' => '98111000'.sprintf('%02d', $i)]))->assertStatus(202);
    }

    // ten a minute from one visitor (the bad one counted as the first)
    $throttled = ($this->visit)(websiteRecord(['submission_id' => 'sub-too-many']))->assertStatus(429);

    expect($throttled->headers->get('Access-Control-Allow-Origin'))->toBe(SITE)->and($throttled->headers->get('Retry-After'))->not->toBeNull();

    // another visitor (another address) is not held up by it
    ($this->visit)(websiteRecord(['submission_id' => 'sub-other-visitor']), SITE, null, ['REMOTE_ADDR' => '203.0.113.9'])->assertStatus(202);
});

it('tells a robot it worked and writes nothing down', function () {
    $response = ($this->visit)(websiteRecord(['_gotcha' => 'buy cheap watches']))->assertStatus(202);

    expect($response->json('data.new'))->toBe(0)->and(LeadApiLog::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0)
        ->and($this->form->fresh()->last_received_at)->toBeNull();
});

it('is one lead when the visitor double-clicks, and the second is only counted', function () {
    ($this->visit)(websiteRecord())->assertStatus(202)->assertJsonPath('data.new', 1);
    $again = ($this->visit)(websiteRecord())->assertStatus(202);

    expect($again->json('data'))->toBe(['status' => 'accepted', 'received' => 1, 'new' => 0, 'already_known' => 1])
        ->and(Lead::query()->count())->toBe(1)->and(LeadApiLog::query()->sole()->received_count)->toBe(2);
});

it('adds a second enquiry from a number that is on an open lead as a note, like the marketplaces', function () {
    ($this->visit)(websiteRecord())->assertStatus(202);
    ($this->visit)(websiteRecord(['submission_id' => 'sub-second-1', 'message' => 'Also need nuts.']))->assertStatus(202)->assertJsonPath('data.new', 1);

    expect(Lead::query()->count())->toBe(1)->and(Comment::query()->where('commentable_id', Lead::query()->sole()->id)->value('body'))->toContain('Another Website form enquiry')->toContain('Also need nuts.');
});

it('sends a plain HTML form on a listed site to its thank-you page, and never to a page that is not listed', function () {
    $form = fn (array $fields) => $this->call('POST', ($this->url)(), $fields, [], [], ['HTTP_ORIGIN' => SITE, 'HTTP_ACCEPT' => 'text/html']);

    $thanks = $form([...websiteRecord(), 'thanks_url' => SITE.'/thank-you'])->assertStatus(303);

    expect($thanks->headers->get('Location'))->toBe(SITE.'/thank-you')->and(Lead::query()->count())->toBe(1);

    // a thank-you page on another site is not followed (an open redirect): the JSON answer is shown instead
    $other = $form([...websiteRecord(['submission_id' => 'sub-plain-2', 'mobile' => '9811100002']), 'thanks_url' => 'https://evil.example/phish'])->assertStatus(202);

    expect($other->headers->get('Location'))->toBeNull()->and(Lead::query()->count())->toBe(2);

    // and a script that sends JSON is never redirected
    ($this->visit)([...websiteRecord(['submission_id' => 'sub-json-1', 'mobile' => '9811100003']), 'thanks_url' => SITE.'/thank-you'])->assertStatus(202);
});

it('keeps another company’s leads apart: a key belongs to one company, whatever the site says', function () {
    $this->settings->set(WebsiteOrigins::SETTING, SITE, $this->globex->id);

    ($this->visit)(websiteRecord(), SITE, $this->theirs)->assertStatus(202);

    $log = LeadApiLog::withoutGlobalScopes()->sole();

    expect((int) $log->company_id)->toBe($this->globex->id)->and(Lead::withoutGlobalScopes()->sole()->company_id)->toBe($this->globex->id)
        ->and(LeadApiLog::withoutGlobalScopes()->where('company_id', $this->acme->id)->count())->toBe(0);
});

it('refuses a browser while a signing secret is set — a page cannot sign — and accepts a signed relay', function () {
    $this->settings->set('integrations.website_webhook_secret', 'whsec-RELAY-secret-2718', $this->acme->id);

    ($this->visit)(websiteRecord())->assertStatus(401);

    $raw = json_encode(websiteRecord());
    $timestamp = time();
    $signature = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$raw}", 'whsec-RELAY-secret-2718');

    ($this->visit)($raw, null, null, ['HTTP_X_FORMEZY_TIMESTAMP' => (string) $timestamp, 'HTTP_X_FORMEZY_SIGNATURE' => $signature])->assertStatus(202);

    expect(Lead::query()->count())->toBe(1);
});

it('never keeps the signing secret in the stored submission', function () {
    $this->settings->set('integrations.website_webhook_secret', 'whsec-RELAY-secret-2718', $this->acme->id);

    $raw = json_encode(websiteRecord(['message' => 'The secret is whsec-RELAY-secret-2718, ignore it', 'api_key' => 'k-123456']));
    $timestamp = time();

    ($this->visit)($raw, null, null, ['HTTP_X_FORMEZY_TIMESTAMP' => (string) $timestamp, 'HTTP_X_FORMEZY_SIGNATURE' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$raw}", 'whsec-RELAY-secret-2718')])->assertStatus(202);

    $stored = json_encode(LeadApiLog::query()->sole()->payload);

    expect($stored)->not->toContain('whsec-RELAY-secret-2718')->not->toContain('k-123456')->and(json_encode(Lead::query()->sole()->getAttributes()))->not->toContain('whsec-RELAY');
});

// ---- where the visitor came from ---------------------------------------------------------------------------

it('credits the lead to the campaign and keeps the UTM values its page carried', function () {
    $template = makeTemplate($this->acme, $this->admin);
    $campaign = makeCampaign($this->acme, $this->admin, $this->ahd, $template);

    ($this->visit)(websiteRecord(['campaign' => $campaign->code, 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'Autumn <b>sale</b>']))->assertStatus(202);

    $lead = Lead::query()->sole();

    expect($lead->campaign_id)->toBe($campaign->id)->and($lead->attribution)->toBe('link')
        ->and($lead->utm)->toBe(['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'Autumn bsaleb']);   // only plain text is kept
});

it('keeps the UTM values of a page with no (or an unknown) campaign, and does not make one up', function () {
    ($this->visit)(websiteRecord(['campaign' => 'CP/NO-SUCH', 'utm_source' => 'google']))->assertStatus(202);

    $lead = Lead::query()->sole();

    expect($lead->campaign_id)->toBeNull()->and($lead->attribution)->toBeNull()->and($lead->utm)->toBe(['utm_source' => 'google']);
});

it('does not wipe a campaign a lead was already credited to when only UTM values arrive', function () {
    $template = makeTemplate($this->acme, $this->admin);
    $campaign = makeCampaign($this->acme, $this->admin, $this->ahd, $template);

    ($this->visit)(websiteRecord())->assertStatus(202);

    $lead = Lead::query()->sole();
    $lead->forceFill(['campaign_id' => $campaign->id, 'attribution' => 'inferred'])->saveQuietly();

    inCompany($this->acme, fn () => app(CampaignAttribution::class)->tag($lead->fresh(), null, ['utm_source' => 'google'], 'link'));

    $lead = $lead->fresh();

    expect($lead->campaign_id)->toBe($campaign->id)->and($lead->attribution)->toBe('inferred')->and($lead->utm)->toBe(['utm_source' => 'google']);
});

// ---- what the visitor agreed to ----------------------------------------------------------------------------

it('records the opt-in the visitor ticked, with the wording, and says so on the lead', function () {
    ($this->visit)(websiteRecord(['consent' => 'on', 'consent_text' => 'I agree to be contacted about my enquiry.', 'consent_channels' => ['email', 'whatsapp']]))->assertStatus(202);

    $rows = ContactConsent::withoutGlobalScopes()->orderBy('channel')->get();

    expect($rows->map(fn ($row) => $row->channel->value)->all())->toBe(['email', 'whatsapp'])->and($rows->pluck('status')->unique()->all())->toBe(['opted_in'])
        ->and($rows->pluck('source')->unique()->all())->toBe(['website'])->and($rows->pluck('address')->all())->toBe(['meera@example.test', '9876543210'])
        ->and($rows[0]->note)->toContain('s-sub-8d3f1a2b')->toContain('I agree to be contacted about my enquiry.')
        ->and((int) $rows[0]->company_id)->toBe($this->acme->id)
        ->and(Lead::query()->sole()->remarks)->toContain('Agreed on the form to be contacted by E-mail')->toContain('Agreed on the form to be contacted by WhatsApp');
});

it('records no consent for a tick with no wording or no channel, and none for a marketplace lead', function () {
    ($this->visit)(websiteRecord(['consent' => 'on', 'consent_channels' => ['email']]))->assertStatus(202);                                                       // no wording
    ($this->visit)(websiteRecord(['submission_id' => 'sub-no-channel', 'mobile' => '9811100002', 'consent' => 'on', 'consent_text' => 'I agree']))->assertStatus(202);   // no channel

    expect(ContactConsent::withoutGlobalScopes()->count())->toBe(0)->and(Lead::query()->count())->toBe(2);
});

it('does not take a form’s tick as undoing an opt-out, and tells whoever works the lead', function () {
    inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::Email, 'meera@example.test', 'opted_out', 'unsubscribe'));

    ($this->visit)(websiteRecord(['consent' => 'on', 'consent_text' => 'I agree', 'consent_channels' => ['email', 'sms']]))->assertStatus(202);

    $rows = ContactConsent::withoutGlobalScopes()->orderBy('id')->get();

    expect($rows->mapWithKeys(fn ($row) => [$row->channel->value => $row->status])->all())->toBe(['email' => 'opted_out', 'sms' => 'opted_in'])   // the opt-out stands; the other channel was ticked and is recorded
        ->and(Lead::query()->sole()->remarks)->toContain('had opted out and the form cannot prove who filled it in');
});

it('says on the lead when a ticked channel has no usable address', function () {
    ($this->visit)(websiteRecord(['email' => 'not-an-email', 'consent' => 'on', 'consent_text' => 'I agree', 'consent_channels' => ['email']]))->assertStatus(202);

    expect(ContactConsent::withoutGlobalScopes()->count())->toBe(0)->and(Lead::query()->sole()->remarks)->toContain('gave no usable e-mail address');
});

it('records the same opt-in once when the visitor sends the form twice, and leaves a note with what was agreed on an existing lead', function () {
    $consent = ['consent' => 'on', 'consent_text' => 'I agree', 'consent_channels' => ['sms']];

    ($this->visit)(websiteRecord($consent))->assertStatus(202);
    ($this->visit)(websiteRecord(['submission_id' => 'sub-second-2', 'message' => 'Again', ...$consent]))->assertStatus(202);

    expect(Lead::query()->count())->toBe(1)->and(ContactConsent::withoutGlobalScopes()->count())->toBe(1)
        ->and(Comment::query()->where('commentable_id', Lead::query()->sole()->id)->value('body'))->toContain('Agreed on the form to be contacted by SMS');
});

it('records no consent when the lead itself could not be made', function () {
    $this->form->forceFill(['actor_user_id' => null])->saveQuietly();

    ($this->visit)(websiteRecord(['consent' => 'on', 'consent_text' => 'I agree', 'consent_channels' => ['email']]))->assertStatus(202);

    expect(LeadApiLog::query()->sole()->status->value)->toBe('failed')->and(Lead::query()->count())->toBe(0)->and(ContactConsent::withoutGlobalScopes()->count())->toBe(0);
});
