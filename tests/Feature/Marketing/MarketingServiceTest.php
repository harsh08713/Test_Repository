<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Mail\MarketingMail;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\CampaignStep;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Models\ContactConsent;
use App\Modules\Marketing\Services\CampaignAttribution;
use App\Modules\Marketing\Services\CampaignRunner;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Marketing\Services\CommunicationService;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Marketing\Services\ContactNormalizer;
use App\Modules\Marketing\Services\TemplateRenderer;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * Consent, templates, the outbox, campaigns (approval, launch, the sequence, exits, caps) and attribution — through the services the screens use.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    Mail::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->consent = fn () => inCompany($this->acme, fn () => app(ConsentService::class));
    $this->optIn = fn (string $email) => inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::Email, $email, 'opted_in', 'form'));
    $this->lead = fn (string $name, string $email, $by = null, array $over = []) => makeLead($this->acme, $by ?? $this->bde, $this->ahd, ['name' => $name, 'email' => $email, ...$over]);
    $this->runner = fn (?Carbon $at = null) => inCompany($this->acme, fn () => app(CampaignRunner::class)->run($at));
    $this->members = fn (Campaign $c) => CampaignMember::query()->where('campaign_id', $c->id)->orderBy('id')->get();
});

// ---- addresses and consent --------------------------------------------------------------------------------------------------------

it('spells an address one way', function () {
    expect(ContactNormalizer::email('  Anil@Example.COM '))->toBe('anil@example.com')->and(ContactNormalizer::email('not an email'))->toBeNull()
        ->and(ContactNormalizer::mobile('+91 98250-12345'))->toBe('9825012345')->and(ContactNormalizer::mobile('12345'))->toBeNull()
        ->and(ContactNormalizer::address(Channel::Sms, '098250 12345'))->toBe('9825012345')
        ->and(ContactNormalizer::mask(Channel::Email, 'anil@example.com'))->toBe('an•••@example.com')->and(ContactNormalizer::mask(Channel::Sms, '9825012345'))->toBe('••••••2345');
});

it('answers whether a message may be sent from the latest thing the person said', function () {
    $svc = app(ConsentService::class);
    $ok = fn (Channel $c, ?string $a, string $p) => inCompany($this->acme, fn () => $svc->check($c, $a, $p, $this->acme->id)['ok']);

    // nothing recorded: marketing needs an opt-in, a transactional message does not
    expect($ok(Channel::Email, 'a@example.com', 'marketing'))->toBeFalse()->and($ok(Channel::Email, 'a@example.com', 'transactional'))->toBeTrue()
        ->and($ok(Channel::Email, null, 'transactional'))->toBeFalse();

    ($this->optIn)('A@Example.com');
    expect($ok(Channel::Email, 'a@example.com', 'marketing'))->toBeTrue();

    // an opt-out wins, for every purpose, until they opt in again
    inCompany($this->acme, fn () => $svc->record(Channel::Email, 'a@example.com', 'opted_out', 'unsubscribe'));
    expect($ok(Channel::Email, 'a@example.com', 'marketing'))->toBeFalse()->and($ok(Channel::Email, 'a@example.com', 'transactional'))->toBeFalse();
    ($this->optIn)('a@example.com');
    expect($ok(Channel::Email, 'a@example.com', 'marketing'))->toBeTrue();

    // e-mail marketing can be opened to addresses with no answer on record; WhatsApp and SMS never can
    app(SettingsService::class)->set('marketing.email_requires_optin', false, $this->acme->id);
    expect($ok(Channel::Email, 'new@example.com', 'marketing'))->toBeTrue()->and($ok(Channel::WhatsApp, '9825012345', 'marketing'))->toBeFalse()->and($ok(Channel::Sms, '9825012345', 'marketing'))->toBeFalse();
    inCompany($this->acme, fn () => $svc->record(Channel::Email, 'new@example.com', 'opted_out', 'manual'));
    expect($ok(Channel::Email, 'new@example.com', 'marketing'))->toBeFalse();

    // each company keeps its own answers
    expect(inCompany($this->globex, fn () => $svc->check(Channel::Email, 'a@example.com', 'marketing', $this->globex->id)['ok']))->toBeFalse();
});

it('keeps the consent history, adds nothing for the same answer twice, and refuses a bad address', function () {
    $svc = app(ConsentService::class);

    inCompany($this->acme, function () use ($svc) {
        $svc->record(Channel::Email, 'a@example.com', 'opted_in', 'form');
        $svc->record(Channel::Email, 'A@example.com', 'opted_in', 'form');
        $svc->record(Channel::Email, 'a@example.com', 'opted_out', 'unsubscribe');
    });

    expect(ContactConsent::query()->count())->toBe(2)->and(fn () => ContactConsent::query()->first()->forceFill(['status' => 'opted_out'])->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => ContactConsent::query()->first()->delete())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => inCompany($this->acme, fn () => $svc->record(Channel::Email, 'nonsense', 'opted_in', 'form')))->toThrow(ValidationException::class)
        ->and(fn () => inCompany($this->acme, fn () => $svc->record(Channel::Sms, '12', 'opted_in', 'form')))->toThrow(ValidationException::class);
});

// ---- templates ----------------------------------------------------------------------------------------------------------------

it('accepts only the listed variables and makes a marketing e-mail carry the unsubscribe link', function () {
    $r = app(TemplateRenderer::class);

    expect($r->problems(Channel::Email, 'marketing', 'Hello {{first_name}}', 'Hi {{name}} {{unsubscribe_url}}'))->toBe([])
        ->and($r->problems(Channel::Email, 'marketing', 'Hello', 'Hi {{nmae}}'))->toHaveKey('body')
        ->and($r->problems(Channel::Email, 'marketing', 'Hello', 'Hi there'))->toHaveKey('body')   // no unsubscribe link
        ->and($r->problems(Channel::Email, 'transactional', 'Your order', 'Thank you {{name}}'))->toBe([])
        ->and($r->problems(Channel::Email, 'transactional', '', 'Thank you'))->toHaveKey('subject')
        ->and($r->problems(Channel::Sms, 'transactional', 'A subject', 'Hello'))->toHaveKey('subject')
        ->and($r->problems(Channel::Sms, 'transactional', null, str_repeat('x', 481)))->toHaveKey('body')
        ->and($r->problems(Channel::Email, 'transactional', 'S', '   '))->toHaveKey('body');

    $out = $r->render('Offer for {{ first_name }}', 'Dear {{name}} from {{company}} {{missing}}', ['name' => 'Rakesh Patel', 'first_name' => 'Rakesh', 'company' => 'ACME']);
    expect($out)->toBe(['subject' => 'Offer for Rakesh', 'body' => 'Dear Rakesh Patel from ACME']);
});

// ---- the outbox ---------------------------------------------------------------------------------------------------------------

it('sends only with an approved template, once per key, and says only that the mail server took it', function () {
    ($this->optIn)('a@example.com');
    $draft = makeTemplate($this->acme, $this->manager, [], false);
    $template = makeTemplate($this->acme, $this->manager);
    $svc = fn () => inCompany($this->acme, fn () => app(CommunicationService::class));

    expect(fn () => $svc()->queue($draft, ['name' => 'A', 'address' => 'a@example.com'], 'k1'))->toThrow(FieldRuleException::class, 'not approved');

    $one = $svc()->queue($template, ['name' => 'Anil Shah', 'address' => 'A@example.com', 'subject_type' => 'lead', 'subject_id' => 1], 'k1');
    $again = $svc()->queue($template, ['name' => 'Anil Shah', 'address' => 'a@example.com'], 'k1');

    expect($one->id)->toBe($again->id)->and(Communication::query()->count())->toBe(1)
        ->and($one->fresh()->status)->toBe('sent')->and($one->fresh()->statusLabel())->toBe('Handed to the mail server')->and($one->fresh()->sent_at)->not->toBeNull()
        ->and($one->subject)->toBe('An offer for Anil')->and($one->body)->toContain('Dear Anil Shah')->and($one->body)->toContain('/unsubscribe/'.$this->acme->id.'/email');

    Mail::assertSent(MarketingMail::class, 1);
    Mail::assertSent(MarketingMail::class, fn ($m) => $m->hasTo('a@example.com'));

    // the words and the address are a record
    expect(fn () => $one->fresh()->forceFill(['body' => 'changed'])->save())->toThrow(LogicException::class, 'only how it went')
        ->and(fn () => $one->fresh()->delete())->toThrow(LogicException::class, 'cannot be deleted');
});

it('keeps a message it will not send, with the reason: no consent, opted out, held back by the weekly limit, or no provider', function () {
    $template = makeTemplate($this->acme, $this->manager);
    $svc = fn () => inCompany($this->acme, fn () => app(CommunicationService::class));

    $noConsent = $svc()->queue($template, ['name' => 'N', 'address' => 'n@example.com'], 'a');
    expect($noConsent->status)->toBe('suppressed')->and($noConsent->failure)->toContain('No consent');

    ($this->optIn)('o@example.com');
    inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::Email, 'o@example.com', 'opted_out', 'unsubscribe'));
    expect($svc()->queue($template, ['name' => 'O', 'address' => 'o@example.com'], 'b')->failure)->toBe('Opted out.');

    // the weekly limit (3 by default) counts marketing messages to one address
    ($this->optIn)('c@example.com');
    foreach (['c1', 'c2', 'c3'] as $key) {
        expect($svc()->queue($template, ['name' => 'C', 'address' => 'c@example.com'], $key)->status)->toBe('sent');
    }
    $held = $svc()->queue($template, ['name' => 'C', 'address' => 'c@example.com'], 'c4');
    expect($held->status)->toBe('suppressed')->and($held->failure)->toContain('Held back');

    // a transactional message is not limited
    $order = makeTemplate($this->acme, $this->manager, ['name' => 'Order', 'purpose' => 'transactional', 'subject' => 'Your order', 'body' => 'Thank you {{name}}']);
    expect($svc()->queue($order, ['name' => 'C', 'address' => 'c@example.com'], 'c5')->status)->toBe('sent');

    // a channel with no provider connected is kept, not pretended
    inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::WhatsApp, '9825012345', 'opted_in', 'form'));
    $wa = makeTemplate($this->acme, $this->manager, ['name' => 'WA', 'channel' => 'whatsapp', 'subject' => null, 'body' => 'Hello {{first_name}}']);
    $kept = $svc()->queue($wa, ['name' => 'Rakesh', 'address' => '+91 98250 12345'], 'w1');
    expect($kept->status)->toBe('not_connected')->and($kept->failure)->toContain('provider')->and($kept->statusLabel())->toContain('not connected');
});

it('stops a message just before it is handed over if the person has opted out in between', function () {
    ($this->optIn)('a@example.com');
    $template = makeTemplate($this->acme, $this->manager);
    $row = inCompany($this->acme, fn () => app(CommunicationService::class)->queue($template, ['name' => 'A', 'address' => 'a@example.com'], 'k'));

    // put it back as waiting (as if the worker had not got to it yet), then they opt out
    Communication::query()->whereKey($row->id)->update(['status' => 'queued', 'sent_at' => null]);
    Mail::fake();
    inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::Email, 'a@example.com', 'opted_out', 'unsubscribe'));
    inCompany($this->acme, fn () => app(CommunicationService::class)->deliver($row->id, $this->acme->id));

    expect($row->fresh()->status)->toBe('suppressed')->and($row->fresh()->failure)->toContain('Stopped before sending');
    Mail::assertNothingSent();
});

it('records a failure in words without an address in it, and tries it again a few times', function () {
    ($this->optIn)('a@example.com');
    $template = makeTemplate($this->acme, $this->manager);
    // a real mail manager with a transport that refuses everything (and puts the address in its complaint)
    Mail::clearResolvedInstance('mail.manager');
    app()->forgetInstance('mail.manager');
    app('mail.manager')->extend('broken', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('550 mailbox a@example.com refused by /var/mail/server');
        }

        public function __toString(): string
        {
            return 'broken://';
        }
    });
    config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'broken']]);

    $row = inCompany($this->acme, fn () => app(CommunicationService::class)->queue($template, ['name' => 'A', 'address' => 'a@example.com'], 'k'));

    expect($row->fresh()->status)->toBe('failed')->and($row->fresh()->failure)->not->toContain('a@example.com')->and($row->fresh()->attempts)->toBe(1);

    inCompany($this->acme, fn () => app(CommunicationService::class)->retryFailed());
    expect($row->fresh()->attempts)->toBe(2);
});

// ---- campaigns ----------------------------------------------------------------------------------------------------------------

it('makes a campaign with a CP number and steps, and checks what was typed', function () {
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, ['budget' => '25000.50', 'utm_campaign' => 'autumn']);

    expect($campaign->code)->toBe('CP/AHD/26-27/00001')->and($campaign->state)->toBe('draft')->and($campaign->approval_status->value)->toBe('draft')->and((string) $campaign->budget)->toBe('25000.50')
        ->and($campaign->steps)->toHaveCount(1)->and($campaign->audience)->toBe(['stages' => ['new']]);

    $bad = fn (array $over) => collect([$over])->map(function ($o) use ($template) {
        try {
            makeCampaign($this->acme, $this->manager, $this->ahd, $template, $o);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    })->first();

    expect($bad(['name' => '']))->toBe(['name'])
        ->and($bad(['type' => 'carrier-pigeon']))->toBe(['type'])
        ->and($bad(['start_date' => '2026-10-01']))->toBe(['start_date'])
        ->and($bad(['end_date' => '2026-10-11']))->toBe(['end_date'])
        ->and($bad(['budget' => '-5']))->toBe(['budget'])
        ->and($bad(['audience_kind' => 'everyone']))->toBe(['audience_kind'])
        ->and($bad(['utm_source' => '<script>']))->toBe(['utm_source'])
        ->and($bad(['owner_id' => (string) $this->bde2->id]))->toBe(['owner_id'])
        ->and($bad(['lead_source_id' => '99999']))->toBe(['lead_source_id'])
        ->and($bad(['steps' => [['delay_hours' => '-1', 'message_template_id' => (string) $template->id]]]))->toBe(['steps.0.delay_hours'])
        ->and($bad(['steps' => [['delay_hours' => '0', 'message_template_id' => '99999']]]))->toBe(['steps.0.message_template_id']);
});

it('goes for approval only when complete, is then fixed in the model, and is launched only once it is approved', function () {
    ($this->lead)('Alpha Works', 'alpha@example.com');
    $draftTemplate = makeTemplate($this->acme, $this->manager, ['name' => 'Not yet'], false);
    $template = makeTemplate($this->acme, $this->manager);

    $noSteps = makeCampaign($this->acme, $this->manager, $this->ahd, $template, ['steps' => []]);
    expect(fn () => inCompany($this->acme, fn () => app(ApprovalService::class)->submit($noSteps, $this->manager)))->toThrow(ApprovalStateException::class, 'at least one message step');

    $unapproved = makeCampaign($this->acme, $this->manager, $this->ahd, $draftTemplate);
    expect(fn () => inCompany($this->acme, fn () => app(ApprovalService::class)->submit($unapproved, $this->manager)))->toThrow(ApprovalStateException::class, 'not approved and active');

    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template);
    expect(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->launch($campaign, $this->manager)))->toThrow(FieldRuleException::class, 'approved');

    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($campaign, $this->manager));

    // sent for approval: the words, the audience and the steps are fixed — in the model and in the service
    expect(fn () => Campaign::query()->find($campaign->id)->forceFill(['name' => 'Changed'])->save())->toThrow(LogicException::class, 're-open')
        ->and(fn () => CampaignStep::query()->where('campaign_id', $campaign->id)->first()->forceFill(['delay_hours' => 5])->save())->toThrow(LogicException::class, 'do not change')
        ->and(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->update($campaign->fresh(), ['name' => 'x', 'type' => 'email', 'start_date' => '2026-10-12', 'audience_kind' => 'leads', 'steps' => []], $this->manager)))
        ->toThrow(FieldRuleException::class, 'no longer changes');

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($campaign->fresh(), $this->approver));
    $launched = inCompany($this->acme, fn () => app(CampaignService::class)->launch($campaign->fresh(), $this->manager));
    expect($launched->state)->toBe('running')->and($launched->launched_at)->not->toBeNull();

    // once only, and a launched campaign cannot be re-opened
    expect(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->launch($launched, $this->manager)))->toThrow(FieldRuleException::class, 'already been launched')
        ->and(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->reopen($launched->fresh(), 'change of mind', $this->manager)))->toThrow(FieldRuleException::class);
});

it('re-opens an approved campaign that has not been launched, with a reason, and it has to be approved again', function () {
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, [], 'approved');

    expect(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->reopen($campaign, '  ', $this->manager)))->toThrow(ApprovalStateException::class);

    $draft = inCompany($this->acme, fn () => app(CampaignService::class)->reopen($campaign, 'The offer changed', $this->manager));
    expect($draft->approval_status->value)->toBe('draft');

    $changed = inCompany($this->acme, fn () => app(CampaignService::class)->update($draft, [
        'name' => 'Changed offer', 'type' => 'email', 'start_date' => '2026-10-12', 'audience_kind' => 'leads', 'audience' => ['stages' => ['new']],
        'steps' => [['delay_hours' => '0', 'message_template_id' => (string) $template->id]],
    ], $this->manager));
    expect($changed->name)->toBe('Changed offer')->and(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->launch($changed, $this->manager)))->toThrow(FieldRuleException::class, 'approved');
});

it('chooses its members with the owner’s reach and the contact details it was approved with, and refuses an empty audience', function () {
    ($this->lead)('Alpha Works', 'alpha@example.com', $this->bde);
    ($this->lead)('Beta Works', 'beta@example.com', $this->bde2);
    ($this->lead)('No Mail Works', '', $this->bde);
    $template = makeTemplate($this->acme, $this->manager);

    // the owner is the BDE: the campaign can write only to the BDE’s own leads
    $campaign = makeCampaign($this->acme, $this->bde, $this->ahd, $template, ['owner_id' => (string) $this->bde->id], 'running');
    $members = ($this->members)($campaign);

    expect($members->pluck('name')->all())->toBe(['Alpha Works', 'No Mail Works'])
        ->and($members->firstWhere('name', 'Alpha Works')->status)->toBe('active')->and($members->firstWhere('name', 'Alpha Works')->email)->toBe('alpha@example.com')
        ->and($members->firstWhere('name', 'No Mail Works')->status)->toBe('suppressed')->and($members->firstWhere('name', 'No Mail Works')->exit_reason)->toBe('no_address');

    $empty = makeCampaign($this->acme, $this->manager, $this->ahd, $template, ['audience' => ['stages' => ['won']]], 'approved');
    expect(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->launch($empty, $this->manager)))->toThrow(FieldRuleException::class, 'matches nobody');
});

it('sends each step once to each member, waits between steps, and finishes them after the last', function () {
    ($this->lead)('Alpha Works', 'alpha@example.com');
    ($this->optIn)('alpha@example.com');
    $first = makeTemplate($this->acme, $this->manager, ['name' => 'One']);
    $second = makeTemplate($this->acme, $this->manager, ['name' => 'Two', 'subject' => 'Second note, {{first_name}}']);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $first, ['steps' => [
        ['delay_hours' => '0', 'message_template_id' => (string) $first->id], ['delay_hours' => '48', 'message_template_id' => (string) $second->id],
    ]], 'running');

    expect(($this->runner)()['sent'])->toBe(1)->and(($this->runner)()['sent'])->toBe(0);   // a second run sends nothing more

    $member = ($this->members)($campaign)->sole();
    expect($member->next_step)->toBe(2)->and($member->status)->toBe('active')->and($member->next_due_at->toDateTimeString())->toBe('2026-10-14 09:30:00')->and($member->last_sent_at)->not->toBeNull();

    // not yet due
    Carbon::setTestNow('2026-10-13 09:30:00');
    expect(($this->runner)()['sent'])->toBe(0);

    Carbon::setTestNow('2026-10-14 09:31:00');
    expect(($this->runner)()['sent'])->toBe(1);

    $member->refresh();
    expect($member->status)->toBe('completed')->and($member->next_due_at)->toBeNull()
        ->and(Communication::query()->where('campaign_id', $campaign->id)->orderBy('id')->pluck('idempotency_key')->all())->toBe(["c{$campaign->id}:m{$member->id}:s1", "c{$campaign->id}:m{$member->id}:s2"])
        ->and(Communication::query()->where('campaign_id', $campaign->id)->pluck('subject')->all())->toBe(['An offer for Alpha', 'Second note, Alpha']);

    // everyone is done: the campaign finishes
    expect($campaign->fresh()->state)->toBe('completed')->and($campaign->fresh()->completed_at)->not->toBeNull();
});

it('does not start before its start date, does not run while paused, and stops when it is called off', function () {
    ($this->lead)('Alpha Works', 'alpha@example.com');
    ($this->lead)('Beta Works', 'beta@example.com');
    ($this->optIn)('alpha@example.com');
    ($this->optIn)('beta@example.com');
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, ['start_date' => '2026-10-15'], 'running');

    expect(($this->runner)()['sent'])->toBe(0);   // 12 October: the campaign starts on the 15th

    Carbon::setTestNow('2026-10-15 10:00:00');
    inCompany($this->acme, fn () => app(CampaignService::class)->pause($campaign, $this->manager));
    expect(($this->runner)()['sent'])->toBe(0);

    inCompany($this->acme, fn () => app(CampaignService::class)->resume($campaign->fresh(), $this->manager));
    expect(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->resume($campaign->fresh(), $this->manager)))->toThrow(FieldRuleException::class, 'paused');

    expect(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->cancel($campaign->fresh(), ' ', $this->manager)))->toThrow(ValidationException::class);
    inCompany($this->acme, fn () => app(CampaignService::class)->cancel($campaign->fresh(), 'The offer is withdrawn', $this->manager));

    expect(($this->runner)()['sent'])->toBe(0)->and($campaign->fresh()->state)->toBe('cancelled')->and(($this->members)($campaign)->pluck('exit_reason')->unique()->all())->toBe(['campaign_cancelled']);
});

it('ends a person’s sequence when they respond, reach a stage the campaign lists, opt out, or never agreed', function () {
    $responded = ($this->lead)('Responded Works', 'r@example.com');
    $moved = ($this->lead)('Moved Works', 'm@example.com');
    $optedOut = ($this->lead)('Opted Out Works', 'o@example.com');
    $never = ($this->lead)('Never Agreed Works', 'n@example.com');
    $stays = ($this->lead)('Stays Works', 's@example.com');
    foreach (['r', 'm', 'o', 's'] as $who) {
        ($this->optIn)("{$who}@example.com");
    }

    $first = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $first, ['exit_stages' => ['qualified'], 'steps' => [
        ['delay_hours' => '0', 'message_template_id' => (string) $first->id], ['delay_hours' => '24', 'message_template_id' => (string) $first->id],
    ]], 'running');

    ($this->runner)();   // everyone who may be written to gets step 1
    $byName = fn (string $n) => ($this->members)($campaign)->firstWhere('name', $n);

    expect($byName('Never Agreed Works')->status)->toBe('exited')->and($byName('Never Agreed Works')->exit_reason)->toBe('no_consent');

    inCompany($this->acme, fn () => app(CampaignService::class)->markResponded($byName('Responded Works'), 'Rang back', $this->manager));
    Lead::query()->whereKey($moved->id)->update(['stage' => 'qualified']);
    inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::Email, 'o@example.com', 'opted_out', 'unsubscribe'));
    expect(fn () => inCompany($this->acme, fn () => app(CampaignService::class)->markResponded($byName('Responded Works'), null, $this->manager)))->toThrow(FieldRuleException::class, 'already');

    Carbon::setTestNow('2026-10-13 10:00:00');
    ($this->runner)();

    expect($byName('Responded Works')->fresh())->status->toBe('exited')->exit_reason->toBe('responded')
        ->and($byName('Moved Works')->fresh()->exit_reason)->toBe('stage_reached')
        ->and($byName('Opted Out Works')->fresh()->exit_reason)->toBe('opted_out')
        ->and($byName('Stays Works')->fresh()->status)->toBe('completed');

    // 4 people were written to once (everyone but the one who never agreed); only the one who stayed got the second message
    expect(Communication::query()->where('campaign_id', $campaign->id)->count())->toBe(5)
        ->and($responded->exists && $moved->exists && $optedOut->exists && $never->exists && $stays->exists)->toBeTrue();
});

it('holds a member back a day when the address has had its weekly limit, without making a message', function () {
    ($this->lead)('Alpha Works', 'alpha@example.com');
    ($this->optIn)('alpha@example.com');
    $template = makeTemplate($this->acme, $this->manager);

    // three marketing messages to this address already this week (by something else)
    foreach (['x1', 'x2', 'x3'] as $key) {
        inCompany($this->acme, fn () => app(CommunicationService::class)->queue($template, ['name' => 'Alpha', 'address' => 'alpha@example.com'], $key));
    }

    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, [], 'running');
    $result = ($this->runner)();

    expect($result['held'])->toBe(1)->and($result['sent'])->toBe(0)->and(Communication::query()->where('campaign_id', $campaign->id)->count())->toBe(0)
        ->and(($this->members)($campaign)->sole()->next_due_at->toDateTimeString())->toBe('2026-10-13 09:30:00');

    // a week on, the limit has room again
    Carbon::setTestNow('2026-10-20 10:00:00');
    expect(($this->runner)()['sent'])->toBe(1);
});

it('finishes a campaign whose end date has passed, and lets the people in it go', function () {
    ($this->lead)('Alpha Works', 'alpha@example.com');
    ($this->optIn)('alpha@example.com');
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, ['end_date' => '2026-10-13', 'steps' => [
        ['delay_hours' => '0', 'message_template_id' => (string) $template->id], ['delay_hours' => '240', 'message_template_id' => (string) $template->id],
    ]], 'running');
    ($this->runner)();

    Carbon::setTestNow('2026-10-14 10:00:00');
    ($this->runner)();

    expect($campaign->fresh()->state)->toBe('completed')->and(($this->members)($campaign)->sole()->exit_reason)->toBe('campaign_ended');
});

// ---- attribution --------------------------------------------------------------------------------------------------------------

it('credits a new lead to the campaign that wrote to the same person lately, and marks that person as having responded', function () {
    ($this->lead)('Alpha Works', 'alpha@example.com');
    ($this->optIn)('alpha@example.com');
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, [], 'running');
    ($this->runner)();

    // someone else’s lead with the same e-mail address arrives (a web form, say)
    $new = ($this->lead)('Alpha Works (web enquiry)', 'ALPHA@example.com', $this->bde2);

    expect($new->fresh()->campaign_id)->toBe($campaign->id)->and($new->fresh()->attribution)->toBe('inferred');

    $member = ($this->members)($campaign)->sole();
    expect($member->responded_at)->not->toBeNull()->and($member->lead_id)->not->toBeNull()->and($member->status)->toBe('completed');   // one step, already sent: the sequence was over; the response is still credited

    // a stranger is not credited
    expect(($this->lead)('Stranger', 'stranger@example.com')->fresh()->campaign_id)->toBeNull();

    // and not after the window
    Carbon::setTestNow('2026-12-01 10:00:00');
    expect(($this->lead)('Late Works', 'alpha@example.com', null, ['mobile' => '9825099999'])->fresh()->campaign_id)->toBeNull();
});

it('keeps the campaign and the UTM values that came with a link, and only the five standard ones', function () {
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template);
    $lead = ($this->lead)('Linked Works', 'l@example.com');

    inCompany($this->acme, fn () => app(CampaignAttribution::class)->tag($lead, $campaign->id, [
        'utm_source' => 'facebook', 'utm_medium' => 'cpc<script>', 'utm_campaign' => str_repeat('x', 150), 'utm_unknown' => 'nope', 'utm_term' => ['array'],
    ], 'link'));

    $fresh = $lead->fresh();
    expect($fresh->campaign_id)->toBe($campaign->id)->and($fresh->attribution)->toBe('link')
        ->and($fresh->utm['utm_source'])->toBe('facebook')->and($fresh->utm['utm_medium'])->toBe('cpcscript')->and(strlen($fresh->utm['utm_campaign']))->toBe(100)
        ->and($fresh->utm)->not->toHaveKey('utm_unknown')->not->toHaveKey('utm_term');

    // another company’s campaign cannot be named
    $foreign = makeCampaign($this->globex, makeUser(['Sales Manager'], [$this->globex]), makeLocation($this->globex, 'GX'), makeTemplate($this->globex, makeUser(['Sales Manager'], [$this->globex])), ['owner_id' => null]);
    expect(inCompany($this->acme, fn () => app(CampaignAttribution::class)->tag(($this->lead)('Other', 'o2@example.com'), $foreign->id, [], 'manual'))->campaign_id)->toBeNull();
});
