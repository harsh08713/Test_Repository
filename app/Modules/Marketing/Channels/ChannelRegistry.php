<?php

namespace App\Modules\Marketing\Channels;

use App\Modules\Marketing\Enums\Channel;
use App\Modules\Shared\Services\SettingsService;
use InvalidArgumentException;

/**
 * Which channels a company can send on, and the provider that sends. E-mail goes through the installation's mail transport and is always connected. WhatsApp and SMS are
 * `off` until a company chooses `sandbox` (rehearsal: nothing leaves) or `live` (the provider, which needs its credentials saved in Company settings).
 */
final class ChannelRegistry
{
    public const MODES = ['off' => 'Off', 'sandbox' => 'Sandbox (nothing is sent)', 'live' => 'Live'];

    public function __construct(private readonly SettingsService $settings) {}

    public function mode(Channel $channel, int $companyId): string
    {
        return match ($channel) {
            Channel::Email => 'live',
            Channel::WhatsApp => (string) $this->settings->get('channels.whatsapp_mode', $companyId),
            Channel::Sms => (string) $this->settings->get('channels.sms_mode', $companyId),
        };
    }

    /** Can a message on this channel be handed over for this company? */
    public function isConnected(Channel $channel, int $companyId): bool
    {
        $mode = $this->mode($channel, $companyId);

        return $mode === 'sandbox' || ($mode === 'live' && $this->missing($channel, $companyId) === []);
    }

    /** @return list<string> what a live connection still lacks, in words */
    public function missing(Channel $channel, int $companyId): array
    {
        $need = match ($channel) {
            Channel::Email => [],
            Channel::WhatsApp => ['channels.whatsapp_phone_number_id' => 'phone number ID', 'channels.whatsapp_access_token' => 'access token', 'channels.whatsapp_app_secret' => 'app secret', 'channels.webhook_token' => 'webhook token'],
            Channel::Sms => ['channels.sms_account_sid' => 'account SID', 'channels.sms_auth_token' => 'auth token', 'channels.sms_from' => 'sender', 'channels.webhook_token' => 'webhook token'],
        };

        return array_values(array_map(fn ($label) => $label, array_filter($need, fn ($label, $key) => trim((string) $this->settings->get($key, $companyId)) === '', ARRAY_FILTER_USE_BOTH)));
    }

    public function provider(Channel $channel, int $companyId): ChannelProvider
    {
        if ($this->mode($channel, $companyId) === 'sandbox') {
            return new SandboxProvider;
        }

        return match ($channel) {
            Channel::WhatsApp => new WhatsAppCloudProvider($this->settings),
            Channel::Sms => new TwilioSmsProvider($this->settings),
            Channel::Email => throw new InvalidArgumentException('E-mail is not sent through a channel provider.'),
        };
    }
}
