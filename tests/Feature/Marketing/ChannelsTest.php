<?php

use App\Modules\Marketing\Channels\ChannelDelivery;
use App\Modules\Marketing\Channels\ChannelRegistry;
use App\Modules\Marketing\Channels\ChannelWebhooks;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Http\Livewire\ChannelStatus;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Services\CommunicationService;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Phase 16 (first slice): WhatsApp and SMS as channels. The providers are faked (`Http::fake`): this is built against their documented shapes and **not verified on a live account**.
 * Hand-checked: 9825012345 with the default country code 91 is 919825012345 (WhatsApp) and +919825012345 (SMS).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme]);
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);

    $this->set = fn (array $values, $company = null) => inCompany($company ?? $this->acme, function () use ($values, $company) {
        foreach ($values as $key => $value) {
            app(SettingsService::class)->set($key, $value, ($company ?? $this->acme)->id);
        }
    });
    $this->live = fn () => ($this->set)([
        'channels.whatsapp_mode' => 'live', 'channels.whatsapp_phone_number_id' => '10555', 'channels.whatsapp_access_token' => 'wa-token', 'channels.whatsapp_app_secret' => 'wa-secret',
        'channels.sms_mode' => 'live', 'channels.sms_account_sid' => 'ACtest', 'channels.sms_auth_token' => 'sms-token', 'channels.sms_from' => '+15005550006',
        'channels.webhook_token' => str_repeat('a', 40),
    ]);
    $this->optIn = fn (Channel $c, string $address = '9825012345') => inCompany($this->acme, fn () => app(ConsentService::class)->record($c, $address, 'opted_in', 'form'));
    $this->send = function (Channel $c, string $key, string $address = '9825012345') {
        ($this->optIn)($c, $address);
        $template = makeTemplate($this->acme, $this->manager, ['name' => 'Msg '.$key, 'channel' => $c->value, 'subject' => null, 'body' => 'Hello {{first_name}}, a new offer.']);

        return inCompany($this->acme, fn () => app(CommunicationService::class)->queue($template, ['name' => 'Rakesh Patel', 'address' => $address], $key));
    };
    $this->row = fn (Communication $m) => Communication::withoutGlobalScopes(Tenancy::SCOPES)->find($m->id);
    $this->hook = fn (string $channel, ?string $token = null) => '/api/webhooks/channels/'.$this->acme->id.'/'.$channel.'/'.($token ?? str_repeat('a', 40));
});

afterEach(fn () => Carbon::setTestNow());

// ---- connection ----------------------------------------------------------------------------------------------------------------

it('keeps a message as not connected while a channel is off, and says what a live channel still lacks', function () {
    $m = ($this->send)(Channel::WhatsApp, 'k1');
    expect($m->status)->toBe('not_connected')->and($m->failure)->toContain('provider')->and($m->statusLabel())->toContain('not connected');

    ($this->set)(['channels.whatsapp_mode' => 'live']);
    $registry = app(ChannelRegistry::class);

    expect($registry->isConnected(Channel::WhatsApp, $this->acme->id))->toBeFalse()
        ->and($registry->missing(Channel::WhatsApp, $this->acme->id))->toBe(['phone number ID', 'access token', 'app secret', 'webhook token'])
        ->and(($this->send)(Channel::WhatsApp, 'k2')->status)->toBe('not_connected');

    ($this->live)();
    expect($registry->isConnected(Channel::WhatsApp, $this->acme->id))->toBeTrue()->and($registry->missing(Channel::Sms, $this->acme->id))->toBe([])
        ->and($registry->isConnected(Channel::Email, $this->acme->id))->toBeTrue()
        ->and($registry->isConnected(Channel::WhatsApp, $this->globex->id))->toBeFalse();   // another company's settings are its own
});

it('rehearses in sandbox without sending anything', function () {
    Http::fake();
    ($this->set)(['channels.whatsapp_mode' => 'sandbox']);

    $m = ($this->row)(($this->send)(Channel::WhatsApp, 's1'));

    expect($m->status)->toBe('sent')->and($m->provider_ref)->toStartWith('sandbox-')->and($m->sent_at)->not->toBeNull();
    Http::assertNothingSent();
});

// ---- sending -------------------------------------------------------------------------------------------------------------------

it('sends WhatsApp through the Cloud API with the token, and keeps the provider’s reference', function () {
    ($this->live)();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);

    $m = ($this->row)(($this->send)(Channel::WhatsApp, 'w1'));

    expect($m->status)->toBe('sent')->and($m->provider_ref)->toBe('wamid.ABC')->and($m->statusLabel())->toBe('Handed to WhatsApp (not yet reported delivered)');
    Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v21.0/10555/messages' && $r->hasHeader('Authorization', 'Bearer wa-token')
        && $r['to'] === '919825012345' && $r['type'] === 'text' && $r['text']['body'] === 'Hello Rakesh, a new offer.' && $r['messaging_product'] === 'whatsapp');
});

it('sends SMS through Twilio with basic auth, the number in international form and a status callback', function () {
    ($this->live)();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

    $m = ($this->row)(($this->send)(Channel::Sms, 'm1'));

    expect($m->status)->toBe('sent')->and($m->provider_ref)->toBe('SM123');
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.twilio.com/2010-04-01/Accounts/ACtest/Messages.json') && $r['To'] === '+919825012345' && $r['From'] === '+15005550006'
        && $r['Body'] === 'Hello Rakesh, a new offer.' && str_contains($r['StatusCallback'], '/api/webhooks/channels/'.$this->acme->id.'/sms/'.str_repeat('a', 40))
        && str_starts_with((string) $r->header('Authorization')[0], 'Basic '));
});

it('uses the company’s own country code', function () {
    ($this->live)();
    ($this->set)(['channels.country_code' => '971']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

    ($this->send)(Channel::WhatsApp, 'cc1');

    Http::assertSent(fn ($r) => $r['to'] === '9719825012345');
});

it('keeps a provider’s refusal in words with no secret in them, and does not retry what will be refused again', function () {
    ($this->live)();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Re-engagement message: the 24 hour window is closed']], 400)]);

    $m = ($this->row)(($this->send)(Channel::WhatsApp, 'r1'));

    expect($m->status)->toBe('failed')->and($m->failure)->toContain('WhatsApp refused')->and($m->failure)->toContain('24 hour window')->and($m->failure)->not->toContain('wa-token')
        ->and($m->attempts)->toBe(CommunicationService::MAX_ATTEMPTS)
        ->and(inCompany($this->acme, fn () => app(CommunicationService::class)->retryFailed()))->toBe(0);
});

it('retries a server error or an unreachable provider, up to the limit', function () {
    ($this->live)();
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Service unavailable'], 503)]);

    $m = ($this->row)(($this->send)(Channel::Sms, 't1'));
    expect($m->status)->toBe('failed')->and($m->attempts)->toBe(1)
        ->and(inCompany($this->acme, fn () => app(CommunicationService::class)->retryFailed()))->toBe(1);

    Http::fake(['api.twilio.com/*' => fn () => throw new ConnectionException('cURL error: could not resolve host api.twilio.com?AuthToken=sms-token')]);
    $m2 = ($this->row)(($this->send)(Channel::Sms, 't2', '9825012346'));
    expect($m2->status)->toBe('failed')->and($m2->failure)->toContain('could not be reached')->and($m2->failure)->not->toContain('sms-token');
});

it('does not send to someone who has not agreed, or has opted out, on a live channel', function () {
    ($this->live)();
    Http::fake();
    $template = makeTemplate($this->acme, $this->manager, ['name' => 'WA', 'channel' => 'whatsapp', 'subject' => null, 'body' => 'Hello']);

    $none = inCompany($this->acme, fn () => app(CommunicationService::class)->queue($template, ['name' => 'N', 'address' => '9825099999'], 'n1'));
    expect($none->status)->toBe('suppressed');

    ($this->optIn)(Channel::WhatsApp, '9825088888');
    inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::WhatsApp, '9825088888', 'opted_out', 'reply'));
    expect(inCompany($this->acme, fn () => app(CommunicationService::class)->queue($template, ['name' => 'N', 'address' => '9825088888'], 'n2'))->status)->toBe('suppressed');
    Http::assertNothingSent();
});

// ---- reports back --------------------------------------------------------------------------------------------------------------

it('applies delivered, read and failed reports once and never backwards', function () {
    ($this->live)();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ONE']]], 200)]);
    $m = ($this->send)(Channel::WhatsApp, 'd1');
    $apply = fn (string $event, ?string $why = null) => inCompany($this->acme, fn () => app(ChannelDelivery::class)->apply($this->acme->id, Channel::WhatsApp, 'wamid.ONE', $event, $why));

    expect($apply('sent'))->toBeTrue()->and(($this->row)($m)->delivered_at)->toBeNull();

    Carbon::setTestNow('2026-10-12 09:31:00');
    $apply('delivered');
    $first = ($this->row)($m)->delivered_at->toDateTimeString();
    Carbon::setTestNow('2026-10-12 09:40:00');
    $apply('delivered');                                   // repeated: nothing moves
    expect(($this->row)($m)->delivered_at->toDateTimeString())->toBe($first)->and(($this->row)($m)->statusLabel())->toBe('Delivered');

    $apply('read');
    expect(($this->row)($m)->read_at)->not->toBeNull()->and(($this->row)($m)->statusLabel())->toBe('Read');

    $apply('failed', 'late nonsense');                     // after delivery a failure report changes nothing
    expect(($this->row)($m)->status)->toBe('sent')->and($apply('delivered'))->toBeTrue();
    expect(inCompany($this->acme, fn () => app(ChannelDelivery::class)->apply($this->acme->id, Channel::WhatsApp, 'wamid.NOPE', 'delivered')))->toBeFalse()
        ->and(inCompany($this->globex, fn () => app(ChannelDelivery::class)->apply($this->globex->id, Channel::WhatsApp, 'wamid.ONE', 'delivered')))->toBeFalse();   // not another company's
});

it('marks a message failed when the provider says it could not deliver it, and keeps it out of the retry', function () {
    ($this->live)();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TWO']]], 200)]);
    $m = ($this->send)(Channel::WhatsApp, 'f1');

    inCompany($this->acme, fn () => app(ChannelDelivery::class)->apply($this->acme->id, Channel::WhatsApp, 'wamid.TWO', 'failed', 'Message undeliverable for 9825012345'));
    $row = ($this->row)($m);

    expect($row->status)->toBe('failed')->and($row->failure)->toContain('could not deliver')->and($row->failure)->toContain('[number]')->and($row->failure)->not->toContain('9825012345')
        ->and(inCompany($this->acme, fn () => app(CommunicationService::class)->retryFailed()))->toBe(0);
});

// ---- the webhook ---------------------------------------------------------------------------------------------------------------

it('answers a wrong or switched-off address with 404 and nothing else', function () {
    ($this->live)();

    $this->postJson(($this->hook)('whatsapp', str_repeat('b', 40)), [])->assertNotFound();
    $this->postJson(($this->hook)('email'), [])->assertNotFound();
    $this->postJson('/api/webhooks/channels/'.$this->globex->id.'/whatsapp/'.str_repeat('a', 40), [])->assertNotFound();   // that company has none

    ($this->set)(['channels.whatsapp_mode' => 'off']);
    $this->postJson(($this->hook)('whatsapp'), [])->assertNotFound();
});

it('checks Meta’s signature in live mode and applies the statuses and a STOP reply', function () {
    ($this->live)();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.THREE']]], 200)]);
    $m = ($this->send)(Channel::WhatsApp, 'h1');

    $body = json_encode(['entry' => [['changes' => [['value' => [
        'statuses' => [['id' => 'wamid.THREE', 'status' => 'delivered']],
        'messages' => [['from' => '919825012345', 'text' => ['body' => 'Stop please']]],
    ]]]]]]);
    $sign = fn (string $secret) => 'sha256='.hash_hmac('sha256', $body, $secret);
    $post = fn (string $sig) => $this->call('POST', ($this->hook)('whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], $body);

    $post($sign('wrong-secret'))->assertUnauthorized();
    expect(($this->row)($m)->delivered_at)->toBeNull();

    $post($sign('wa-secret'))->assertOk();
    expect(($this->row)($m)->delivered_at)->not->toBeNull()
        ->and(inCompany($this->acme, fn () => app(ConsentService::class)->check(Channel::WhatsApp, '9825012345', 'marketing', $this->acme->id)['ok']))->toBeFalse();

    // unsigned at all
    $this->call('POST', ($this->hook)('whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertUnauthorized();
});

it('answers Meta’s verification check only with the right verify token', function () {
    ($this->live)();
    ($this->set)(['channels.whatsapp_verify_token' => 'my-verify']);

    $this->get(($this->hook)('whatsapp').'?hub_mode=subscribe&hub_verify_token=my-verify&hub_challenge=12345')->assertOk()->assertSee('12345');
    $this->get(($this->hook)('whatsapp').'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();
    $this->get(($this->hook)('sms').'?hub_mode=subscribe&hub_verify_token=my-verify&hub_challenge=1')->assertNotFound();
});

it('checks Twilio’s signature in live mode and applies its status callback and a STOP reply', function () {
    ($this->live)();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM999'], 201)]);
    $m = ($this->send)(Channel::Sms, 'x1');
    $url = url(($this->hook)('sms'));

    $sig = function (array $params, string $token = 'sms-token') use ($url) {
        ksort($params);
        $signed = $url;
        foreach ($params as $k => $v) {
            $signed .= $k.$v;
        }

        return base64_encode(hash_hmac('sha1', $signed, $token, true));
    };

    $status = ['MessageSid' => 'SM999', 'MessageStatus' => 'delivered'];
    $this->post(($this->hook)('sms'), $status, ['X-Twilio-Signature' => $sig($status, 'wrong')])->assertUnauthorized();
    $this->post(($this->hook)('sms'), $status)->assertUnauthorized();
    expect(($this->row)($m)->delivered_at)->toBeNull();

    $this->post(($this->hook)('sms'), $status, ['X-Twilio-Signature' => $sig($status)])->assertOk();
    expect(($this->row)($m)->delivered_at)->not->toBeNull();

    $reply = ['From' => '+919825012345', 'Body' => 'STOP', 'MessageSid' => 'SMin1'];
    $this->post(($this->hook)('sms'), $reply, ['X-Twilio-Signature' => $sig($reply)])->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
    expect(inCompany($this->acme, fn () => app(ConsentService::class)->check(Channel::Sms, '9825012345', 'marketing', $this->acme->id)['ok']))->toBeFalse();

    $undelivered = ['MessageSid' => 'SM999', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003'];
    $this->post(($this->hook)('sms'), $undelivered, ['X-Twilio-Signature' => $sig($undelivered)])->assertOk();
    expect(($this->row)($m)->status)->toBe('sent');   // already delivered: unchanged
});

it('takes an unsigned call in sandbox, so a rehearsal can be driven by hand', function () {
    ($this->set)(['channels.sms_mode' => 'sandbox', 'channels.webhook_token' => str_repeat('a', 40)]);
    $m = ($this->send)(Channel::Sms, 'sb1');

    $this->post(($this->hook)('sms'), ['MessageSid' => $m->provider_ref ?? ($this->row)($m)->provider_ref, 'MessageStatus' => 'delivered'])->assertOk();
    expect(($this->row)($m)->delivered_at)->not->toBeNull();
});

it('recognises the words that mean stop, and only those', function () {
    $delivery = app(ChannelDelivery::class);
    $reply = fn (string $text) => inCompany($this->acme, fn () => $delivery->reply($this->acme->id, Channel::Sms, '9825012345', $text));

    expect($reply('STOP'))->toBeTrue()->and($reply(' unsubscribe me'))->toBeTrue()->and($reply('Stop.'))->toBeTrue()
        ->and($reply('Please do not stop sending offers'))->toBeFalse()->and($reply('Yes, interested'))->toBeFalse()->and($reply(''))->toBeFalse();
});

// ---- the status page -----------------------------------------------------------------------------------------------------------

it('shows the status page to those who may see settings, makes the webhook address only for those who may change them, and keeps the token out of the page for the others', function () {
    ($this->live)();
    ($this->set)(['channels.webhook_token' => null]);

    $this->actingAs($this->admin);
    Livewire::actingAs($this->admin)->test(ChannelStatus::class)->assertSee('WhatsApp')->assertSee('No webhook address yet')->call('makeToken')->assertHasNoErrors();

    $token = ChannelWebhooks::token($this->acme->id, app(SettingsService::class));
    expect($token)->not->toBeNull()->and(strlen($token))->toBe(40);
    Livewire::actingAs($this->admin)->test(ChannelStatus::class)->assertSee('/api/webhooks/channels/'.$this->acme->id.'/whatsapp/'.$token);

    $this->actingAs($this->bde)->get(route('marketing.channels'))->assertForbidden();
});
