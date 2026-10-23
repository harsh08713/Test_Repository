<?php

namespace App\Modules\Marketing\Channels;

use App\Modules\Marketing\Models\Communication;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SMS through Twilio's Programmable Messaging API: `POST /2010-04-01/Accounts/{sid}/Messages.json`, basic auth, form fields To / From / Body / StatusCallback.
 * Built against the documented shape; **live verification is pending**. In India, commercial SMS also needs DLT registration (sender and template IDs) with
 * the operator — that is the company's to arrange and is not modelled here (docs/channels.md).
 */
final class TwilioSmsProvider implements ChannelProvider
{
    public function __construct(private readonly SettingsService $settings) {}

    public function send(Communication $message, int $companyId): string
    {
        $sid = trim((string) $this->settings->get('channels.sms_account_sid', $companyId));
        $token = (string) $this->settings->get('channels.sms_auth_token', $companyId);
        $from = trim((string) $this->settings->get('channels.sms_from', $companyId));

        if ($sid === '' || $token === '' || $from === '') {
            throw new ChannelException('SMS is not fully set up: the account SID, auth token or sender is missing.');
        }

        try {
            $response = Http::withBasicAuth($sid, $token)->acceptJson()->asForm()->timeout(20)->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json', [
                'To' => '+'.ChannelNumbers::international($message->to_address, $companyId, $this->settings), 'From' => $from, 'Body' => $message->body,
                'StatusCallback' => ChannelWebhooks::url('sms', $companyId, $this->settings),
            ]);
        } catch (ConnectionException) {
            throw new ChannelException('The SMS provider could not be reached. It will be tried again.', true);
        }

        if ($response->failed()) {
            $why = (string) ($response->json('message') ?? '');
            $retry = $response->serverError() || $response->status() === 429;

            throw new ChannelException('The SMS provider refused the message'.($why !== '' ? ': '.PlainText::safe($why) : '.'), $retry);
        }

        $id = $response->json('sid');

        if (! is_string($id) || $id === '') {
            throw new ChannelException('The SMS provider accepted the call but gave no message reference, so its report cannot be matched.');
        }

        return $id;
    }
}
