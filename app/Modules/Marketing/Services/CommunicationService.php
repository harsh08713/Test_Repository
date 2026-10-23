<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Marketing\Channels\ChannelException;
use App\Modules\Marketing\Channels\ChannelRegistry;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Jobs\SendCommunication;
use App\Modules\Marketing\Mail\MarketingMail;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The one door through which a message to a person is made — by a campaign, a workflow or a person. Making a message does not send it: it decides
 * what is to happen to it and writes that down.
 *
 *  - Only an **approved, active** template is used, and what is sent is the template filled in, kept exactly as it went out.
 *  - The **idempotency key** (`c{campaign}:m{member}:s{step}`, `w{run}:a{action}`…) is unique, so asking twice for the same message gives the same message.
 *  - **Consent** is checked (`ConsentService`) and a **frequency cap** applied to marketing; a message that fails either is *kept* as `suppressed`, with the reason.
 *  - A channel with **no provider connected** keeps the message as `not_connected` — never "sent".
 *  - Otherwise it is `queued` and `SendCommunication` hands it to the mail server. `sent` means the mail server accepted it; nothing claims it was delivered.
 */
final class CommunicationService
{
    /** An address's marketing messages are counted over this many days. */
    public const CAP_DAYS = 7;

    /** Failed messages are tried again this many times in all. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ConsentService $consent,
        private readonly TemplateRenderer $renderer,
        private readonly SettingsService $settings,
        private readonly ChannelRegistry $channels,
    ) {}

    /**
     * @param  array{name?: string|null, address: string|null, subject_type?: string|null, subject_id?: int|null, values?: array<string, string>}  $to
     *
     * @throws FieldRuleException when the template cannot be used
     */
    public function queue(MessageTemplate $template, array $to, string $key, ?Campaign $campaign = null, ?CampaignMember $member = null, ?int $workflowRunId = null, ?User $by = null): Communication
    {
        if (! $template->isApproved() || $template->status !== 'active') {
            throw new FieldRuleException('The template “'.$template->name.'” is not approved and active, so nothing can be sent with it.');
        }

        $companyId = (int) $template->company_id;
        $existing = $this->find($companyId, $key);

        if ($existing !== null) {
            return $existing;
        }

        $channel = $template->channel;
        $address = ContactNormalizer::address($channel, $to['address'] ?? null);
        $company = (string) Company::withoutGlobalScopes()->whereKey($companyId)->value('name');

        $values = [
            'name' => (string) ($to['name'] ?? ''), 'first_name' => (string) (preg_split('/\s+/', trim((string) ($to['name'] ?? '')))[0] ?? ''), 'company' => $company,
            'campaign_name' => (string) $campaign?->name, 'owner_name' => (string) $campaign?->owner?->name,
            'unsubscribe_url' => $address === null ? '' : $this->consent->unsubscribeUrl($channel, $address, $companyId),
            ...($to['values'] ?? []),
        ];
        $text = $this->renderer->render($template->subject, $template->body, $values);

        [$status, $failure] = $this->decide($template, $address, $companyId);

        $row = new Communication;
        $row->forceFill([
            'company_id' => $companyId, 'campaign_id' => $campaign?->getKey(), 'campaign_member_id' => $member?->getKey(), 'message_template_id' => $template->getKey(),
            'workflow_run_id' => $workflowRunId, 'channel' => $channel->value, 'purpose' => $template->purpose, 'subject_type' => $to['subject_type'] ?? null,
            'subject_id' => $to['subject_id'] ?? null, 'to_name' => $to['name'] ?? null, 'to_address' => $address ?? mb_substr((string) ($to['address'] ?? ''), 0, 150),
            'subject' => $text['subject'], 'body' => $text['body'], 'status' => $status, 'failure' => $failure, 'idempotency_key' => $key,
            'created_by' => $by?->getKey(), 'queued_at' => now(),
        ]);

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            return $this->find($companyId, $key) ?? throw new FieldRuleException('The message could not be recorded.');
        }

        $status === Communication::QUEUED && SendCommunication::dispatch((int) $row->getKey(), $companyId);

        return $row->refresh();   // with a synchronous queue the message has already been handed over
    }

    /**
     * Send a queued message now (the job calls this). Safe to deliver twice: only a queued message is claimed. Consent is looked at again here,
     * because the person may have opted out since the message was made.
     */
    public function deliver(int $id, int $companyId): void
    {
        $claimed = Communication::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($id)->where('company_id', $companyId)->where('status', Communication::QUEUED)
            ->update(['status' => Communication::SENDING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $message = Communication::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($id)->firstOrFail();
        $check = $this->consent->check($message->channel, $message->to_address, $message->purpose, $companyId);

        if (! $check['ok']) {
            $this->finish($message, Communication::SUPPRESSED, 'Stopped before sending: '.$check['reason']);

            return;
        }

        if ($message->channel !== Channel::Email) {
            try {
                $ref = $this->channels->provider($message->channel, $companyId)->send($message, $companyId);
            } catch (ChannelException $e) {
                // a refusal the provider will repeat is not tried again by the retry sweep
                $message->forceFill(['attempts' => $e->retryable ? $message->attempts : self::MAX_ATTEMPTS]);
                $this->finish($message, Communication::FAILED, $e->getMessage());

                return;
            } catch (Throwable $e) {
                report($e);
                $this->finish($message, Communication::FAILED, 'The message could not be handed to the provider.');

                return;
            }

            $message->forceFill(['provider_ref' => $ref]);
            $this->finish($message, Communication::SENT, null, now());

            return;
        }

        try {
            $sender = trim((string) $this->settings->get('marketing.sender_name', $companyId)) ?: (string) Company::withoutGlobalScopes()->whereKey($companyId)->value('name');
            $unsubscribe = $message->purpose === 'marketing' ? $this->consent->unsubscribeUrl($message->channel, $message->to_address, $companyId) : null;

            Mail::to($message->to_address, $message->to_name)->send(new MarketingMail((string) $message->subject, $message->body, $sender, $unsubscribe));
        } catch (Throwable $e) {
            report($e);
            $this->finish($message, Communication::FAILED, $this->redact($e->getMessage()));

            return;
        }

        $this->finish($message, Communication::SENT, null, now());
    }

    /** Put failed messages back in the queue for another go (up to MAX_ATTEMPTS, within a day). Returns how many. */
    public function retryFailed(): int
    {
        $count = 0;

        Communication::withoutGlobalScopes(Tenancy::SCOPES)->where('status', Communication::FAILED)->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('queued_at', '>=', now()->subDay())->orderBy('id')->limit(500)->get()->each(function (Communication $c) use (&$count) {
                $back = Communication::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($c->getKey())->where('status', Communication::FAILED)->update(['status' => Communication::QUEUED]);

                if ($back === 1) {
                    SendCommunication::dispatch((int) $c->getKey(), (int) $c->company_id);
                    $count++;
                }
            });

        return $count;
    }

    /** Has this address had as many marketing messages in the last week as the company allows? (Only marketing is limited.) */
    public function capReached(MessageTemplate $template, ?string $address): bool
    {
        $companyId = (int) $template->company_id;
        $cap = (int) $this->settings->get('marketing.max_per_contact_per_week', $companyId);
        $normal = ContactNormalizer::address($template->channel, $address);

        if ($template->purpose !== 'marketing' || $cap <= 0 || $normal === null) {
            return false;
        }

        return Communication::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('channel', $template->channel->value)->where('to_address', $normal)
            ->where('purpose', 'marketing')->whereIn('status', [Communication::QUEUED, Communication::SENDING, Communication::SENT])
            ->where('queued_at', '>=', now()->subDays(self::CAP_DAYS))->count() >= $cap;
    }

    /** @return array{0: string, 1: string|null} */
    private function decide(MessageTemplate $template, ?string $address, int $companyId): array
    {
        $check = $this->consent->check($template->channel, $address, $template->purpose, $companyId);

        if (! $check['ok']) {
            return [Communication::SUPPRESSED, $check['reason']];
        }

        if ($this->capReached($template, $address)) {
            return [Communication::SUPPRESSED, 'Held back: this address has already had its limit of marketing messages in the last '.self::CAP_DAYS.' days.'];
        }

        if (! $this->channels->isConnected($template->channel, $companyId)) {
            return [Communication::NOT_CONNECTED, 'No '.$template->channel->label().' provider is connected for this company, so the message was kept and not sent.'];
        }

        return [Communication::QUEUED, null];
    }

    private function find(int $companyId, string $key): ?Communication
    {
        return Communication::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('idempotency_key', $key)->first();
    }

    private function finish(Communication $message, string $status, ?string $failure, ?Carbon $sentAt = null): void
    {
        $message->forceFill(['status' => $status, 'failure' => $failure, 'sent_at' => $sentAt])->save();
    }

    /** A line a person can read: no addresses, no paths, no long dumps. */
    private function redact(string $text): string
    {
        $text = (string) preg_replace('/\S+@\S+/', '[address]', $text);
        $text = (string) preg_replace('~[A-Za-z]:\\\\\S+|/\S+/\S+~', '[path]', $text);

        return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 240) ?: 'The mail server refused the message.';
    }
}
