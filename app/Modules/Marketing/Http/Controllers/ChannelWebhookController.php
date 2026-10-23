<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Modules\Marketing\Channels\ChannelDelivery;
use App\Modules\Marketing\Channels\ChannelRegistry;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where WhatsApp (Meta) and SMS (Twilio) report back: `GET|POST /api/webhooks/channels/{company}/{channel}/{token}`.
 *
 *  - The address holds the company and an unguessable token (a company setting); a wrong token, an unknown channel, or a channel switched `off` is **404** — the same for all, so nothing is confirmed to a guesser.
 *  - In **live** mode the provider's signature is required and checked against the raw body (Meta: `X-Hub-Signature-256` with the app secret; Twilio: `X-Twilio-Signature` over the full URL and the form
 *    fields, with the auth token) — **401** otherwise. In **sandbox** an unsigned call is accepted so a rehearsal can be driven by hand.
 *  - Reports are applied by `ChannelDelivery` (once, never backwards); a reply of STOP is an opt-out. The answer is 200 straight away, whatever was inside: a provider that is told off retries for days.
 */
class ChannelWebhookController
{
    public function verify(Request $request, int $company, string $channel, string $token, ChannelRegistry $registry, SettingsService $settings): Response
    {
        $kind = $this->resolve($company, $channel, $token, $registry, $settings);

        // Meta's one-off check when the webhook is saved: echo the challenge if the verify token matches
        abort_unless($kind === Channel::WhatsApp, 404);
        $expected = (string) $settings->get('channels.whatsapp_verify_token', $company);

        if ($request->query('hub_mode') === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        abort(403);
    }

    public function receive(Request $request, int $company, string $channel, string $token, ChannelRegistry $registry, SettingsService $settings, ChannelDelivery $delivery): Response
    {
        $kind = $this->resolve($company, $channel, $token, $registry, $settings);
        $live = $registry->mode($kind, $company) === 'live';

        return $kind === Channel::WhatsApp
            ? $this->whatsapp($request, $company, $live, $settings, $delivery)
            : $this->sms($request, $company, $live, $settings, $delivery);
    }

    private function resolve(int $company, string $channel, string $token, ChannelRegistry $registry, SettingsService $settings): Channel
    {
        $kind = Channel::tryFrom($channel);
        abort_unless($kind === Channel::WhatsApp || $kind === Channel::Sms, 404);

        $expected = (string) $settings->get('channels.webhook_token', $company);
        abort_unless($expected !== '' && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 && hash_equals($expected, $token) && $registry->mode($kind, $company) !== 'off', 404);

        return $kind;
    }

    private function whatsapp(Request $request, int $company, bool $live, SettingsService $settings, ChannelDelivery $delivery): Response
    {
        $raw = $request->getContent();
        $secret = (string) $settings->get('channels.whatsapp_app_secret', $company);

        if ($live || $secret !== '') {
            $given = (string) $request->header('X-Hub-Signature-256');
            abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $given), 401);
        }

        $data = json_decode($raw, true);

        foreach ((array) ($data['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    if (is_array($status) && is_string($status['id'] ?? null) && is_string($status['status'] ?? null)) {
                        $delivery->apply($company, Channel::WhatsApp, $status['id'], $status['status'], is_string($status['errors'][0]['title'] ?? null) ? $status['errors'][0]['title'] : null);
                    }
                }

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    if (is_array($message) && is_string($message['from'] ?? null) && is_string($message['text']['body'] ?? null)) {
                        $delivery->reply($company, Channel::WhatsApp, $message['from'], $message['text']['body']);
                    }
                }
            }
        }

        return response('ok', 200);
    }

    private function sms(Request $request, int $company, bool $live, SettingsService $settings, ChannelDelivery $delivery): Response
    {
        $params = $request->post();
        $token = (string) $settings->get('channels.sms_auth_token', $company);

        if ($live || $request->hasHeader('X-Twilio-Signature')) {
            ksort($params);
            $signed = $request->fullUrl();

            foreach ($params as $key => $value) {
                $signed .= $key.(is_scalar($value) ? $value : '');
            }

            $expected = base64_encode(hash_hmac('sha1', $signed, $token, true));
            abort_unless($token !== '' && hash_equals($expected, (string) $request->header('X-Twilio-Signature')), 401);
        }

        $sid = $params['MessageSid'] ?? $params['SmsSid'] ?? null;
        $status = $params['MessageStatus'] ?? $params['SmsStatus'] ?? null;

        if (is_string($sid) && is_string($status)) {
            $event = match ($status) {
                'delivered' => 'delivered', 'read' => 'read', 'failed', 'undelivered' => 'failed', default => null,
            };

            $event === null || $delivery->apply($company, Channel::Sms, $sid, $event, isset($params['ErrorCode']) && is_scalar($params['ErrorCode']) ? 'error '.$params['ErrorCode'] : null);
        } elseif (is_string($params['From'] ?? null) && is_string($params['Body'] ?? null)) {
            $delivery->reply($company, Channel::Sms, $params['From'], $params['Body']);
        }

        // an empty reply document: Twilio sends nothing back to the person
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)->header('Content-Type', 'text/xml');
    }
}
