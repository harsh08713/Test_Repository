<?php

namespace App\Modules\Marketing\Channels;

use App\Modules\Marketing\Models\Communication;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through Meta's WhatsApp Business Cloud API: `POST /{phone-number-id}/messages` with a bearer token, a plain text message.
 * Built against the documented shape; **live verification is pending** (no account yet). Note: outside a customer's 24-hour reply window WhatsApp accepts only
 * Meta-approved *template* messages; this adapter sends text, so a business-initiated message to a customer who has not written first can be refused by Meta
 * (it then arrives as a "failed" report). Mapping our templates to Meta-approved ones is an owner decision (docs/channels.md).
 */
final class WhatsAppCloudProvider implements ChannelProvider
{
    public const VERSION = 'v21.0';

    public function __construct(private readonly SettingsService $settings) {}

    public function send(Communication $message, int $companyId): string
    {
        $phoneId = trim((string) $this->settings->get('channels.whatsapp_phone_number_id', $companyId));
        $token = (string) $this->settings->get('channels.whatsapp_access_token', $companyId);

        if ($phoneId === '' || $token === '') {
            throw new ChannelException('WhatsApp is not fully set up: the phone number ID or access token is missing.');
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->post('https://graph.facebook.com/'.self::VERSION.'/'.rawurlencode($phoneId).'/messages', [
                'messaging_product' => 'whatsapp', 'to' => ChannelNumbers::international($message->to_address, $companyId, $this->settings), 'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $message->body],
            ]);
        } catch (ConnectionException) {   // its text can carry the address of the call: say only what a person needs
            throw new ChannelException('WhatsApp could not be reached. It will be tried again.', true);
        }

        if ($response->failed()) {
            $why = (string) ($response->json('error.message') ?? '');
            $retry = $response->serverError() || $response->status() === 429;

            throw new ChannelException('WhatsApp refused the message'.($why !== '' ? ': '.PlainText::safe($why) : '.'), $retry);
        }

        $id = $response->json('messages.0.id');

        if (! is_string($id) || $id === '') {
            throw new ChannelException('WhatsApp accepted the call but gave no message reference, so its report cannot be matched.');
        }

        return $id;
    }
}
